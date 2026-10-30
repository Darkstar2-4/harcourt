//! hx-import: copy the SCS application tables from the MySQL database into a fresh SQLite file built from
//! `migrations/`, then prove the copy: per table, the MySQL row count equals the SQLite row count, and a checksum of
//! the values as inserted equals the checksum of the values read back from the finished file.
//!
//!     HX_IMPORT_MYSQL_URL=mysql://user:pass@127.0.0.1/db hx-import --out hx.db [--only user,log]
//!
//! Exit 0 when every table verifies, 1 on a mismatch or a row SQLite refuses, 2 on usage, connection or schema errors.
//! The file is built as `<out>.partial` and renamed into place only after it verifies, so a failed run leaves the
//! previous copy untouched and a rerun starts clean. WordPress tables are not copied here.
//!
//! A generic copier cannot use `sqlx::query!`: identifiers come only from the two schemas and pass `ident`, and every
//! value is a bound parameter.

use std::{
    collections::{BTreeMap, BTreeSet},
    fmt,
    path::{Path, PathBuf},
    process::ExitCode,
    str::FromStr,
    time::Instant,
};

use futures_util::TryStreamExt;
use sha2::{Digest, Sha256};
use sqlx::{
    mysql::{MySqlConnectOptions, MySqlConnection},
    sqlite::{SqliteConnectOptions, SqliteJournalMode, SqlitePoolOptions, SqliteSynchronous},
    Connection, Row, SqlitePool, TypeInfo, ValueRef,
};

/// The legacy tables the PHP site owns. A missing one on either side is an error, so a dropped table is loud.
const LEGACY: [&str; 36] = [
    "blacklist", "bot", "cad", "campaign", "captcha_bypass", "carrier", "category", "company_category_block",
    "content", "country", "currency", "document", "hero", "industry", "inventory", "inventory_xref", "language",
    "log", "mailform", "orderhd", "orderln", "pages", "portal", "portalcategory", "portalcontent", "portalitem",
    "pricing", "registry", "remote", "scs_migration", "search", "subcategory", "user", "user_category",
    "xinventory", "xuser",
];

/// Tables only the Rust program writes; they have no MySQL source.
const RUST_OWNED: [&str; 5] = ["session", "access_code", "remote_grant", "outbox", "_sqlx_migrations"];

const MAX_BIND: usize = 30_000;
const MAX_BATCH: usize = 500;

#[derive(Debug)]
enum Failure {
    Usage(String),
    Setup(String),
    Mismatch,
}

impl From<sqlx::Error> for Failure {
    fn from(e: sqlx::Error) -> Self {
        Failure::Setup(e.to_string())
    }
}

impl From<std::io::Error> for Failure {
    fn from(e: std::io::Error) -> Self {
        Failure::Setup(e.to_string())
    }
}

#[derive(Debug, Clone, PartialEq)]
enum Value {
    Null,
    Int(i64),
    Real(f64),
    Text(String),
    Blob(Vec<u8>),
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Affinity {
    Integer,
    Real,
    Text,
    Blob,
    Numeric,
}

impl Affinity {
    /// SQLite's own rules for a declared column type (datatype3.html, 3.1).
    fn of_declared(decl: &str) -> Affinity {
        let d = decl.to_ascii_uppercase();
        if d.contains("INT") {
            Affinity::Integer
        } else if d.contains("CHAR") || d.contains("CLOB") || d.contains("TEXT") {
            Affinity::Text
        } else if d.is_empty() || d.contains("BLOB") {
            Affinity::Blob
        } else if d.contains("REAL") || d.contains("FLOA") || d.contains("DOUB") {
            Affinity::Real
        } else {
            Affinity::Numeric
        }
    }
}

/// How a MySQL column is selected and what its bytes mean. Numbers, dates and bits are selected as text so one
/// parser handles them all; text arrives in the connection charset (utf8mb4, as PHP's scs_header.php asks for).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Source {
    Text,
    Bytes,
    Number,
    Temporal,
    Bit,
}

impl Source {
    fn of_mysql(data_type: &str) -> Source {
        match data_type.to_ascii_lowercase().as_str() {
            "tinyint" | "smallint" | "mediumint" | "int" | "integer" | "bigint" | "decimal" | "numeric" | "float"
            | "double" | "real" | "year" => Source::Number,
            "date" | "datetime" | "timestamp" | "time" => Source::Temporal,
            "bit" => Source::Bit,
            "binary" | "varbinary" | "tinyblob" | "blob" | "mediumblob" | "longblob" => Source::Bytes,
            _ => Source::Text,
        }
    }

    fn select(self, col: &str) -> String {
        match self {
            Source::Text | Source::Bytes => col.to_string(),
            Source::Number | Source::Temporal => format!("CAST({col} AS CHAR)"),
            Source::Bit => format!("CAST(CAST({col} AS UNSIGNED) AS CHAR)"),
        }
    }
}

/// Legacy values the copy repaired, counted per table and column. Never the values themselves.
#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord)]
enum Dirt {
    NullToDefault,
    EmptyToDefault,
    EmptyToNull,
    ZeroDate,
    InvalidUtf8,
}

impl fmt::Display for Dirt {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(match self {
            Dirt::NullToDefault => "null->default",
            Dirt::EmptyToDefault => "empty->default",
            Dirt::EmptyToNull => "empty->null",
            Dirt::ZeroDate => "zero-date",
            Dirt::InvalidUtf8 => "invalid-utf8",
        })
    }
}

#[derive(Debug, Clone)]
struct Target {
    name: String,
    affinity: Affinity,
    not_null: bool,
    default: Option<Value>,
}

#[derive(Debug, Clone)]
struct Column {
    target: Target,
    source: Source,
}

struct Table {
    name: String,
    /// Columns present on both sides, in SQLite order. SQLite-only columns take their defaults.
    columns: Vec<Column>,
}

fn ident(s: &str) -> Result<String, Failure> {
    if !s.is_empty() && s.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'_') {
        Ok(format!("\"{s}\""))
    } else {
        Err(Failure::Setup(format!("unexpected identifier {s:?}")))
    }
}

fn mysql_ident(s: &str) -> Result<String, Failure> {
    ident(s).map(|_| format!("`{s}`"))
}

/// A column default as `pragma table_info` prints it.
fn parse_default(sql: Option<&str>) -> Result<Option<Value>, String> {
    let Some(s) = sql.map(str::trim) else { return Ok(None) };
    if s.eq_ignore_ascii_case("NULL") {
        return Ok(Some(Value::Null));
    }
    if let Some(inner) = s.strip_prefix('\'').and_then(|r| r.strip_suffix('\'')) {
        return Ok(Some(Value::Text(inner.replace("''", "'"))));
    }
    if let Ok(i) = s.parse::<i64>() {
        return Ok(Some(Value::Int(i)));
    }
    if let Ok(r) = s.parse::<f64>() {
        return Ok(Some(Value::Real(r)));
    }
    Err(format!("default {s:?} is not a literal"))
}

#[derive(Debug, PartialEq)]
struct ConvertError(String);

/// One MySQL cell to the value its SQLite column should hold, and the legacy dirt repaired on the way.
fn convert(raw: Option<&[u8]>, source: Source, t: &Target) -> Result<(Value, Option<Dirt>), ConvertError> {
    let missing = |dirt_default: Dirt, dirt_null: Option<Dirt>| -> Result<(Value, Option<Dirt>), ConvertError> {
        match (&t.default, t.not_null) {
            (Some(d), true) => Ok((d.clone(), Some(dirt_default))),
            (None, true) => Err(ConvertError(format!("{}: no value and no default", t.name))),
            (_, false) => Ok((Value::Null, dirt_null)),
        }
    };
    let Some(bytes) = raw else { return missing(Dirt::NullToDefault, None) };
    if matches!(source, Source::Bytes) || t.affinity == Affinity::Blob {
        return Ok((Value::Blob(bytes.to_vec()), None));
    }
    let (text, utf8_dirt) = match std::str::from_utf8(bytes) {
        Ok(s) => (s.to_string(), None),
        Err(_) => (String::from_utf8_lossy(bytes).into_owned(), Some(Dirt::InvalidUtf8)),
    };
    match t.affinity {
        Affinity::Text => {
            let zero = matches!(source, Source::Temporal) && text.starts_with("0000-00-00");
            Ok((Value::Text(text), utf8_dirt.or(zero.then_some(Dirt::ZeroDate))))
        }
        Affinity::Integer | Affinity::Real | Affinity::Numeric => {
            let s = text.trim();
            if s.is_empty() {
                return missing(Dirt::EmptyToDefault, Some(Dirt::EmptyToNull));
            }
            if let Ok(i) = s.parse::<i64>() {
                return Ok((if t.affinity == Affinity::Real { Value::Real(i as f64) } else { Value::Int(i) }, None));
            }
            match s.parse::<f64>() {
                Ok(r) if t.affinity == Affinity::Integer && r.fract() == 0.0 && r.abs() < 9.0e15 => {
                    Ok((Value::Int(r as i64), None))
                }
                Ok(r) if t.affinity != Affinity::Integer => Ok((Value::Real(r), None)),
                _ if t.affinity == Affinity::Numeric => Ok((Value::Text(text), utf8_dirt)),
                _ => Err(ConvertError(format!("{}: not a number", t.name))),
            }
        }
        Affinity::Blob => Ok((Value::Blob(bytes.to_vec()), None)),
    }
}

/// Order-independent: the wrapping sum of a 128-bit prefix of each row's SHA-256 over type-tagged values, so a row
/// SQLite stored as a different type (text '0123' as integer 123) does not match.
#[derive(Debug, Default, Clone, Copy, PartialEq, Eq)]
struct Checksum {
    rows: u64,
    sum: u128,
}

impl Checksum {
    fn add(&mut self, row: &[Value]) {
        let mut h = Sha256::new();
        for v in row {
            let (tag, bytes): (u8, Vec<u8>) = match v {
                Value::Null => (0, Vec::new()),
                Value::Int(i) => (1, i.to_le_bytes().to_vec()),
                Value::Real(r) => (2, r.to_bits().to_le_bytes().to_vec()),
                Value::Text(s) => (3, s.as_bytes().to_vec()),
                Value::Blob(b) => (4, b.clone()),
            };
            h.update([tag]);
            h.update((bytes.len() as u64).to_le_bytes());
            h.update(&bytes);
        }
        let digest = h.finalize();
        let mut prefix = [0u8; 16];
        prefix.copy_from_slice(&digest[..16]);
        self.rows += 1;
        self.sum = self.sum.wrapping_add(u128::from_le_bytes(prefix));
    }
}

struct Args {
    out: PathBuf,
    only: Option<BTreeSet<String>>,
    mysql_url: String,
}

fn parse_args(mut it: impl Iterator<Item = String>, url: Option<String>) -> Result<Args, Failure> {
    let usage = || Failure::Usage("usage: HX_IMPORT_MYSQL_URL=mysql://... hx-import --out PATH [--only t1,t2]".into());
    let (mut out, mut only) = (None, None);
    while let Some(a) = it.next() {
        match a.as_str() {
            "--out" => out = Some(PathBuf::from(it.next().ok_or_else(usage)?)),
            "--only" => only = Some(it.next().ok_or_else(usage)?.split(',').map(|s| s.trim().to_string()).collect()),
            _ => return Err(usage()),
        }
    }
    Ok(Args { out: out.ok_or_else(usage)?, only, mysql_url: url.ok_or_else(usage)? })
}

#[tokio::main]
async fn main() -> ExitCode {
    let started = Instant::now();
    let result = match parse_args(std::env::args().skip(1), std::env::var("HX_IMPORT_MYSQL_URL").ok()) {
        Ok(args) => run(args).await,
        Err(e) => Err(e),
    };
    match result {
        Ok(()) => {
            println!("ok in {:.1}s", started.elapsed().as_secs_f64());
            ExitCode::SUCCESS
        }
        Err(Failure::Mismatch) => {
            eprintln!("verification failed after {:.1}s; previous file left in place", started.elapsed().as_secs_f64());
            ExitCode::from(1)
        }
        Err(Failure::Usage(m) | Failure::Setup(m)) => {
            eprintln!("hx-import: {m}");
            ExitCode::from(2)
        }
    }
}

fn sidecars(p: &Path) -> [PathBuf; 3] {
    let s = p.as_os_str().to_string_lossy();
    [p.to_path_buf(), PathBuf::from(format!("{s}-wal")), PathBuf::from(format!("{s}-shm"))]
}

async fn run(args: Args) -> Result<(), Failure> {
    let partial = PathBuf::from(format!("{}.partial", args.out.display()));
    for f in sidecars(&partial) {
        if f.exists() {
            std::fs::remove_file(&f)?;
        }
    }
    let opts = MySqlConnectOptions::from_str(&args.mysql_url)
        .map_err(|e| Failure::Usage(format!("HX_IMPORT_MYSQL_URL: {e}")))?
        .charset("utf8mb4");
    let mut my = MySqlConnection::connect_with(&opts).await?;
    sqlx::raw_sql("SET SESSION net_write_timeout = 3600, net_read_timeout = 3600").execute(&mut my).await?;
    sqlx::raw_sql("START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY").execute(&mut my).await?;

    let lite = SqlitePoolOptions::new()
        .max_connections(1)
        .connect_with(
            SqliteConnectOptions::new()
                .filename(&partial)
                .create_if_missing(true)
                .journal_mode(SqliteJournalMode::Off)
                .synchronous(SqliteSynchronous::Off)
                .foreign_keys(false),
        )
        .await?;
    hxweb::db::MIGRATOR.run(&lite).await.map_err(|e| Failure::Setup(format!("migrations: {e}")))?;

    let tables = plan(&mut my, &lite, args.only.as_ref()).await?;
    let mut ok = true;
    for t in &tables {
        ok &= copy(&mut my, &lite, t).await?;
    }
    sqlx::raw_sql("ROLLBACK").execute(&mut my).await?;
    if !ok {
        lite.close().await;
        return Err(Failure::Mismatch);
    }
    sqlx::query("PRAGMA journal_mode = WAL").execute(&lite).await?;
    sqlx::query("PRAGMA optimize").execute(&lite).await?;
    lite.close().await;
    std::fs::rename(&partial, &args.out)?;
    for f in sidecars(&args.out).into_iter().skip(1) {
        if f.exists() {
            std::fs::remove_file(&f)?;
        }
    }
    Ok(())
}

fn text(row: &sqlx::mysql::MySqlRow, i: usize) -> Result<String, Failure> {
    let b: Option<Vec<u8>> = row.try_get_unchecked(i)?;
    Ok(String::from_utf8_lossy(&b.unwrap_or_default()).into_owned())
}

async fn plan(my: &mut MySqlConnection, lite: &SqlitePool, only: Option<&BTreeSet<String>>) -> Result<Vec<Table>, Failure> {
    let mysql_tables: BTreeSet<String> =
        sqlx::query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()")
            .fetch_all(&mut *my)
            .await?
            .iter()
            .map(|r| text(r, 0))
            .collect::<Result<_, _>>()?;
    let lite_tables: BTreeSet<String> =
        sqlx::query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
            .fetch_all(lite)
            .await?
            .iter()
            .map(|r| r.try_get::<String, _>(0))
            .collect::<Result<_, _>>()?;
    for name in LEGACY {
        for (side, set) in [("MySQL", &mysql_tables), ("SQLite", &lite_tables)] {
            if !set.contains(name) {
                return Err(Failure::Setup(format!("legacy table {name} is missing from {side}")));
            }
        }
    }
    let mut out = Vec::new();
    for name in lite_tables.intersection(&mysql_tables) {
        if RUST_OWNED.contains(&name.as_str()) || only.is_some_and(|o| !o.contains(name)) {
            continue;
        }
        let sources: BTreeMap<String, Source> = sqlx::query(
            "SELECT column_name, data_type FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position",
        )
        .bind(name)
        .fetch_all(&mut *my)
        .await?
        .iter()
        .map(|r| Ok((text(r, 0)?, Source::of_mysql(&text(r, 1)?))))
        .collect::<Result<_, Failure>>()?;
        let mut columns = Vec::new();
        let mut seen = BTreeSet::new();
        for r in sqlx::query(r#"SELECT name, type, "notnull", dflt_value FROM pragma_table_info(?1) ORDER BY cid"#)
            .bind(name)
            .fetch_all(lite)
            .await?
        {
            let col: String = r.try_get(0)?;
            let Some(&source) = sources.get(&col) else { continue };
            let default = parse_default(r.try_get_unchecked::<Option<String>, _>(3)?.as_deref())
                .map_err(|e| Failure::Setup(format!("{name}.{col}: {e}")))?;
            seen.insert(col.clone());
            columns.push(Column {
                target: Target {
                    affinity: Affinity::of_declared(&r.try_get::<String, _>(1)?),
                    not_null: r.try_get::<i64, _>(2)? != 0,
                    default,
                    name: col,
                },
                source,
            });
        }
        if let Some(lost) = sources.keys().find(|c| !seen.contains(*c)) {
            return Err(Failure::Setup(format!("{name}.{lost} exists in MySQL but not in the SQLite schema")));
        }
        out.push(Table { name: name.clone(), columns });
    }
    Ok(out)
}

fn insert_sql(table: &str, cols: &str, ncols: usize, rows: usize) -> String {
    let one = format!("({})", vec!["?"; ncols].join(","));
    format!("INSERT INTO {table} ({cols}) VALUES {}", vec![one; rows].join(","))
}

async fn flush(tx: &mut sqlx::SqliteConnection, sql: &str, rows: &[Vec<Value>]) -> Result<(), sqlx::Error> {
    let mut q = sqlx::query(sql);
    for v in rows.iter().flatten() {
        q = match v {
            Value::Null => q.bind(None::<i64>),
            Value::Int(i) => q.bind(*i),
            Value::Real(r) => q.bind(*r),
            Value::Text(s) => q.bind(s.clone()),
            Value::Blob(b) => q.bind(b.clone()),
        };
    }
    q.execute(&mut *tx).await.map(|_| ())
}

fn read_back(row: &sqlx::sqlite::SqliteRow, i: usize) -> Result<Value, sqlx::Error> {
    let raw = row.try_get_raw(i)?;
    if raw.is_null() {
        return Ok(Value::Null);
    }
    let kind = raw.type_info().name().to_string();
    Ok(match kind.as_str() {
        "INTEGER" => Value::Int(row.try_get_unchecked(i)?),
        "REAL" => Value::Real(row.try_get_unchecked(i)?),
        "TEXT" => Value::Text(row.try_get_unchecked(i)?),
        _ => Value::Blob(row.try_get_unchecked(i)?),
    })
}

/// Copies one table and verifies it. Ok(false) is a verification failure, reported and counted by the caller.
async fn copy(my: &mut MySqlConnection, lite: &SqlitePool, t: &Table) -> Result<bool, Failure> {
    let started = Instant::now();
    let table = ident(&t.name)?;
    let names = t.columns.iter().map(|c| ident(&c.target.name)).collect::<Result<Vec<_>, _>>()?.join(", ");
    let select = t
        .columns
        .iter()
        .map(|c| mysql_ident(&c.target.name).map(|q| c.source.select(&q)))
        .collect::<Result<Vec<_>, _>>()?
        .join(", ");
    let ncols = t.columns.len().max(1);
    let batch = (MAX_BIND / ncols).clamp(1, MAX_BATCH);
    let full_sql = insert_sql(&table, &names, ncols, batch);
    let mysql_count: i64 = sqlx::query(&format!("SELECT COUNT(*) FROM {}", mysql_ident(&t.name)?))
        .fetch_one(&mut *my)
        .await?
        .try_get(0)?;

    let mut inserted = Checksum::default();
    let mut dirt: BTreeMap<(String, Dirt), u64> = BTreeMap::new();
    let mut refused: Option<String> = None;
    let mut conn = lite.acquire().await?;
    sqlx::query("BEGIN").execute(&mut *conn).await?;
    {
        let sql = format!("SELECT {select} FROM {}", mysql_ident(&t.name)?);
        let mut rows = sqlx::query(&sql).fetch(&mut *my);
        let mut pending: Vec<Vec<Value>> = Vec::with_capacity(batch);
        while let Some(row) = rows.try_next().await? {
            let mut values = Vec::with_capacity(t.columns.len());
            for (i, c) in t.columns.iter().enumerate() {
                let raw: Option<Vec<u8>> = row.try_get_unchecked(i)?;
                let (v, d) = convert(raw.as_deref(), c.source, &c.target)
                    .map_err(|e| Failure::Setup(format!("{}.{}", t.name, e.0)))?;
                if let Some(d) = d {
                    *dirt.entry((c.target.name.clone(), d)).or_default() += 1;
                }
                values.push(v);
            }
            inserted.add(&values);
            pending.push(values);
            if pending.len() == batch {
                if let Err(e) = flush(&mut conn, &full_sql, &pending).await {
                    refused = Some(e.to_string());
                    break;
                }
                pending.clear();
            }
        }
        if refused.is_none() && !pending.is_empty() {
            let tail = insert_sql(&table, &names, ncols, pending.len());
            if let Err(e) = flush(&mut conn, &tail, &pending).await {
                refused = Some(e.to_string());
            }
        }
    }
    if let Some(e) = refused {
        sqlx::query("ROLLBACK").execute(&mut *conn).await?;
        println!("{:<24} REFUSED by SQLite: {e}", t.name);
        return Ok(false);
    }
    sqlx::query("COMMIT").execute(&mut *conn).await?;

    let mut stored = Checksum::default();
    {
        let sql = format!("SELECT {names} FROM {table}");
        let mut rows = sqlx::query(&sql).fetch(&mut *conn);
        while let Some(row) = rows.try_next().await? {
            let values = (0..t.columns.len()).map(|i| read_back(&row, i)).collect::<Result<Vec<_>, _>>()?;
            stored.add(&values);
        }
    }
    let lite_count: i64 = sqlx::query(&format!("SELECT COUNT(*) FROM {table}")).fetch_one(&mut *conn).await?.try_get(0)?;
    let counts_ok = mysql_count == lite_count && inserted.rows as i64 == mysql_count;
    let sum_ok = inserted == stored;
    let dirt_text = dirt.iter().map(|((c, d), n)| format!("{c}:{d}={n}")).collect::<Vec<_>>().join(" ");
    println!(
        "{:<24} mysql {:>9}  sqlite {:>9}  {:<8} checksum {:<8} {:>6.1}s  {}",
        t.name,
        mysql_count,
        lite_count,
        if counts_ok { "ok" } else { "MISMATCH" },
        if sum_ok { "ok" } else { "MISMATCH" },
        started.elapsed().as_secs_f64(),
        dirt_text
    );
    Ok(counts_ok && sum_ok)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn target(affinity: Affinity, not_null: bool, default: Option<Value>) -> Target {
        Target { name: "c".into(), affinity, not_null, default }
    }

    #[test]
    fn numbers_parse_into_their_affinity() {
        let int = target(Affinity::Integer, false, None);
        assert_eq!(convert(Some(b"42"), Source::Number, &int), Ok((Value::Int(42), None)));
        assert_eq!(convert(Some(b"4294967295"), Source::Number, &int), Ok((Value::Int(4_294_967_295), None)));
        let real = target(Affinity::Real, true, Some(Value::Real(0.0)));
        assert_eq!(convert(Some(b"0.1250"), Source::Number, &real), Ok((Value::Real(0.125), None)));
        assert_eq!(convert(Some(b"3"), Source::Number, &real), Ok((Value::Real(3.0), None)));
        assert!(convert(Some(b"12abc"), Source::Text, &int).is_err());
        assert_eq!(convert(Some(b"1"), Source::Bit, &int), Ok((Value::Int(1), None)));
    }

    #[test]
    fn missing_values_take_the_default_only_where_null_is_refused() {
        let strict = target(Affinity::Integer, true, Some(Value::Int(0)));
        assert_eq!(convert(None, Source::Number, &strict), Ok((Value::Int(0), Some(Dirt::NullToDefault))));
        assert_eq!(convert(Some(b""), Source::Text, &strict), Ok((Value::Int(0), Some(Dirt::EmptyToDefault))));
        let loose = target(Affinity::Integer, false, Some(Value::Int(0)));
        assert_eq!(convert(None, Source::Number, &loose), Ok((Value::Null, None)));
        assert_eq!(convert(Some(b" "), Source::Text, &loose), Ok((Value::Null, Some(Dirt::EmptyToNull))));
        assert!(convert(None, Source::Number, &target(Affinity::Integer, true, None)).is_err());
    }

    #[test]
    fn zero_dates_stay_text_and_are_counted() {
        let t = target(Affinity::Text, false, None);
        assert_eq!(
            convert(Some(b"0000-00-00 00:00:00"), Source::Temporal, &t),
            Ok((Value::Text("0000-00-00 00:00:00".into()), Some(Dirt::ZeroDate)))
        );
        assert_eq!(convert(Some(b"2026-10-29"), Source::Temporal, &t), Ok((Value::Text("2026-10-29".into()), None)));
    }

    #[test]
    fn text_keeps_utf8_and_repairs_invalid_bytes() {
        let t = target(Affinity::Text, false, None);
        assert_eq!(convert(Some("Montréal".as_bytes()), Source::Text, &t), Ok((Value::Text("Montréal".into()), None)));
        let (v, d) = convert(Some(b"caf\xe9"), Source::Text, &t).unwrap();
        assert_eq!(d, Some(Dirt::InvalidUtf8));
        assert_eq!(v, Value::Text("caf\u{fffd}".into()));
        assert_eq!(convert(Some(b"\x00\xff"), Source::Bytes, &t), Ok((Value::Blob(vec![0, 255]), None)));
    }

    #[test]
    fn numeric_text_in_a_text_column_stays_text() {
        let t = target(Affinity::Text, false, None);
        assert_eq!(convert(Some(b"01234"), Source::Text, &t), Ok((Value::Text("01234".into()), None)));
    }

    #[test]
    fn checksum_is_order_independent_and_type_sensitive() {
        let a = vec![Value::Int(1), Value::Text("x".into())];
        let b = vec![Value::Int(2), Value::Null];
        let (mut one, mut two) = (Checksum::default(), Checksum::default());
        one.add(&a);
        one.add(&b);
        two.add(&b);
        two.add(&a);
        assert_eq!(one, two);
        let mut coerced = Checksum::default();
        coerced.add(&[Value::Int(1), Value::Int(123)]);
        let mut original = Checksum::default();
        original.add(&[Value::Int(1), Value::Text("0123".into())]);
        assert_ne!(coerced, original);
        let mut typed = Checksum::default();
        typed.add(&[Value::Text("1".into()), Value::Text("x".into())]);
        let mut exact = Checksum::default();
        exact.add(&a);
        assert_ne!(typed, exact);
    }

    #[test]
    fn affinity_follows_sqlite_rules() {
        assert_eq!(Affinity::of_declared("INTEGER"), Affinity::Integer);
        assert_eq!(Affinity::of_declared("TEXT"), Affinity::Text);
        assert_eq!(Affinity::of_declared("REAL"), Affinity::Real);
        assert_eq!(Affinity::of_declared("BLOB"), Affinity::Blob);
        assert_eq!(Affinity::of_declared(""), Affinity::Blob);
        assert_eq!(Affinity::of_declared("DECIMAL(5,4)"), Affinity::Numeric);
    }

    #[test]
    fn defaults_parse_from_pragma_text() {
        assert_eq!(parse_default(Some("'Pending'")), Ok(Some(Value::Text("Pending".into()))));
        assert_eq!(parse_default(Some("'it''s'")), Ok(Some(Value::Text("it's".into()))));
        assert_eq!(parse_default(Some("0")), Ok(Some(Value::Int(0))));
        assert_eq!(parse_default(Some("1.0")), Ok(Some(Value::Real(1.0))));
        assert_eq!(parse_default(Some("NULL")), Ok(Some(Value::Null)));
        assert_eq!(parse_default(None), Ok(None));
        assert!(parse_default(Some("CURRENT_TIMESTAMP")).is_err());
    }

    #[test]
    fn identifiers_outside_the_schema_alphabet_are_refused() {
        assert_eq!(ident("user_category").unwrap(), "\"user_category\"");
        assert!(ident("user\"; DROP").is_err());
        assert!(ident("").is_err());
        assert_eq!(mysql_ident("log").unwrap(), "`log`");
    }

    #[test]
    fn args_need_an_output_and_a_url() {
        let a = |v: &[&str]| v.iter().map(|s| s.to_string()).collect::<Vec<_>>().into_iter();
        assert!(parse_args(a(&["--out", "x.db"]), None).is_err());
        assert!(parse_args(a(&[]), Some("mysql://h/db".into())).is_err());
        let ok = parse_args(a(&["--out", "x.db", "--only", "user, log"]), Some("mysql://h/db".into())).unwrap();
        assert_eq!(ok.only.unwrap().into_iter().collect::<Vec<_>>(), vec!["log".to_string(), "user".to_string()]);
    }
}
