//! The staff work that is not a web page, as commands of the one binary. Each opens the database the server uses
//! (HXWEB_DB) and is run by someone with a shell on the server, or by a systemd timer for the jobs.

use std::{collections::HashMap, io::Write, path::Path};

use sqlx::SqlitePool;

use super::{
    data::{self, EmailSource, Options, Table},
    jobs,
    map::{self, Field, FileType},
    time,
};
use crate::{
    audit::{self, Entry},
    config::Config,
    db,
    identity::{self, UserId},
    remote_grant,
};

const USAGE: &str = "usage:
  hxweb job search_utility | search_export <dir> | search_export --log <file> | image_filmstrip <docroot>
  hxweb job price_increase (--percent <p> | --fixed <amount>) (--all | <subcategory id>...)
  hxweb export <table> [--out <file.xlsx|.txt|.csv>] [--subcategory <id>] [--no-price] [--category <id>]
               [--status <s,..>] [--role <r,..>] [--industry <id>] [--from <date>] [--thru <date>]
               [--source all|first|last] [--record-type <code>]
  hxweb import <table> <file> [--update] [--subcategory <id>] [--category <id>] [--record-type <code>]
               [--worksheet <name>] [--replace] [--xr]
  hxweb import prj <PARTsolutions export> [--update]
  hxweb remote-grants list | mint <user id> --by <staff id> | revoke <grant id> --by <staff id>
tables: inventory, erp-price (import), subcategory, xref, user, email (export), pricing, prj-missing (export)";

const SWITCHES: [&str; 6] = ["--update", "--all", "--no-price", "--replace", "--xr", "--log"];

/// Arguments after the command: words, `--name value` options and switches.
#[derive(Debug, Default)]
struct Args {
    words: Vec<String>,
    options: HashMap<String, String>,
}

impl Args {
    fn parse(args: &[String]) -> Result<Args, String> {
        let mut out = Args::default();
        let mut it = args.iter();
        while let Some(a) = it.next() {
            if SWITCHES.contains(&a.as_str()) {
                out.options.insert(a.clone(), String::new());
            } else if a.starts_with("--") {
                let value = it.next().ok_or_else(|| format!("{a} needs a value"))?;
                out.options.insert(a.clone(), value.clone());
            } else {
                out.words.push(a.clone());
            }
        }
        Ok(out)
    }

    fn has(&self, name: &str) -> bool {
        self.options.contains_key(name)
    }

    fn get(&self, name: &str) -> &str {
        self.options.get(name).map_or("", String::as_str)
    }

    fn id(&self, name: &str) -> Result<i64, String> {
        match self.get(name) {
            "" => Ok(0),
            v => v.parse().map_err(|_| format!("{name}: not a number: {v}")),
        }
    }

    fn list(&self, name: &str) -> Vec<String> {
        self.get(name).split(',').map(str::trim).filter(|s| !s.is_empty()).map(String::from).collect()
    }

    fn only(&self, allowed: &[&str]) -> Result<(), String> {
        match self.options.keys().find(|k| !allowed.contains(&k.as_str())) {
            Some(k) => Err(format!("{k} does not apply here")),
            None => Ok(()),
        }
    }
}

/// A date as typed (`2025-03-05`, `2025/03/05` or `3/5/2025`), as `Y/m/d`.
fn day(s: &str) -> Result<String, String> {
    if s.is_empty() {
        return Ok(String::new());
    }
    ["%Y-%m-%d", "%Y/%m/%d", "%m/%d/%Y"]
        .iter()
        .find_map(|f| chrono::NaiveDate::parse_from_str(s, f).ok())
        .map(|d| d.format("%Y/%m/%d").to_string())
        .ok_or_else(|| format!("not a date: {s}"))
}

fn options(a: &Args) -> Result<Options, String> {
    let source = match a.get("--source") {
        "" | "all" => EmailSource::All,
        "first" => EmailSource::First,
        "last" => EmailSource::Last,
        s => return Err(format!("--source: all, first or last, not {s}")),
    };
    let o = Options {
        subcategory_id: a.id("--subcategory")?,
        category_id: a.id("--category")?,
        no_price: a.has("--no-price"),
        statuses: a.list("--status"),
        roles: a.list("--role"),
        industry_id: a.id("--industry")?,
        from: day(a.get("--from"))?,
        thru: day(a.get("--thru"))?,
        source,
        record_type: a.get("--record-type").to_string(),
        replace: a.has("--replace"),
    };
    if !o.thru.is_empty() && o.from > o.thru {
        return Err("--from is after --thru".into());
    }
    Ok(o)
}

/// The command for `args`, its exit code; None when the first word is not one of these commands.
pub async fn run(args: &[String]) -> Option<i32> {
    let command = args.first()?.as_str();
    if !matches!(command, "job" | "export" | "import" | "remote-grants") {
        return None;
    }
    let a = match Args::parse(&args[1..]) {
        Ok(a) => a,
        Err(e) => return Some(usage(&e)),
    };
    let config = match Config::from_env(|k| std::env::var(k).ok()) {
        Ok(c) => c,
        Err(e) => return Some(failed(&e.to_string())),
    };
    let pool = match db::open(&config.db_path).await {
        Ok(p) => p,
        Err(e) => return Some(failed(&format!("cannot open {}: {e}", config.db_path.display()))),
    };
    let result = match command {
        "job" => job(&pool, &a).await,
        "export" => export(&pool, &a).await,
        "import" => import(&pool, &a).await,
        _ => grants(&pool, &a).await,
    };
    Some(match result {
        Ok(Done::Ok(out)) => {
            print!("{out}");
            0
        }
        Ok(Done::Rejected(out)) => {
            print!("{out}");
            1
        }
        Err(Fail::Usage(e)) => usage(&e),
        Err(Fail::Run(e)) => failed(&e),
    })
}

/// What a command printed: all went through, or some lines were rejected (exit 1).
#[derive(Debug, PartialEq)]
pub enum Done {
    Ok(String),
    Rejected(String),
}

#[derive(Debug, PartialEq)]
pub enum Fail {
    Usage(String),
    Run(String),
}

impl From<String> for Fail {
    fn from(e: String) -> Fail {
        Fail::Run(e)
    }
}

fn usage(e: &str) -> i32 {
    eprintln!("{e}\n{USAGE}");
    2
}

fn failed(e: &str) -> i32 {
    eprintln!("{e}");
    1
}

fn bad(e: impl Into<String>) -> Fail {
    Fail::Usage(e.into())
}

async fn job(pool: &SqlitePool, a: &Args) -> Result<Done, Fail> {
    let words: Vec<&str> = a.words.iter().map(String::as_str).collect();
    let now = db::unix_now();
    let out = match words[..] {
        ["search_utility"] => jobs::search_utility(pool, now).await?,
        ["search_export", file] if a.has("--log") => jobs::search_log(pool, Path::new(file), now).await?,
        ["search_export", dir] => jobs::search_export(pool, Path::new(dir)).await?,
        ["image_filmstrip", docroot] => super::filmstrip::run(pool, Path::new(docroot), now).await?,
        ["price_increase", ref ids @ ..] => {
            let increase = match (a.get("--percent"), a.get("--fixed")) {
                (p, "") if !p.is_empty() => jobs::Increase::parse("percent", p).map_err(bad)?,
                ("", f) if !f.is_empty() => jobs::Increase::parse("fixed", f).map_err(bad)?,
                _ => return Err(bad("price_increase needs --percent or --fixed")),
            };
            if ids.is_empty() != a.has("--all") {
                return Err(bad("name the subcategories or give --all"));
            }
            let ids: Vec<i64> = ids.iter().map(|s| s.parse().map_err(|_| bad(format!("not a subcategory id: {s}")))).collect::<Result<_, _>>()?;
            jobs::price_increase(pool, increase, &ids, now).await?
        }
        _ => return Err(bad("unknown job")),
    };
    Ok(Done::Ok(out))
}

/// The log row export() and import() wrote (`$database->log->ipc`), with no account behind it.
async fn log(pool: &SqlitePool, kind: &str, table: Table, lines: &[String]) -> Result<(), String> {
    let (now, subtype, comment) = (db::unix_now(), table.name(), lines.join("\n"));
    sqlx::query!("INSERT INTO log (ip, date_time, user_id, email, type, subtype, comment) VALUES ('', ?1, 0, '', ?2, ?3, ?4)", now, kind, subtype, comment)
        .execute(pool)
        .await
        .map_err(|e| e.to_string())?;
    Ok(())
}

fn table(a: &Args, wants: fn(Table) -> bool) -> Result<Table, Fail> {
    let name = a.words.first().map_or("", String::as_str);
    Table::parse(name).filter(|t| wants(*t)).ok_or_else(|| bad(format!("no such table here: {name}")))
}

async fn export(pool: &SqlitePool, a: &Args) -> Result<Done, Fail> {
    a.only(&["--out", "--subcategory", "--no-price", "--category", "--status", "--role", "--industry", "--from", "--thru", "--source", "--record-type"]).map_err(bad)?;
    let table = table(a, Table::exports)?;
    let out = a.get("--out");
    let file = if out.is_empty() { FileType::Txt } else { FileType::of_name(out).ok_or_else(|| bad("--out must end in .xlsx, .txt or .csv"))? };
    let sheets = data::export(pool, table, &options(a).map_err(bad)?, file).await?;
    let rows: usize = sheets.iter().map(|(_, _, r)| r.len()).sum();
    if rows == 0 {
        return Err(Fail::Run("No records found".into()));
    }
    let bytes = match &sheets[..] {
        [(_, map, records)] => map::export_file(file, &map.fields().iter().collect::<Vec<&Field>>(), records)?,
        _ => map::export_sheets(&sheets)?,
    };
    let summary = vec![format!("Table: {}", table.name()), format!("File: {}", if out.is_empty() { "-" } else { out }), format!("Rows: {rows}")];
    if out.is_empty() {
        std::io::stdout().write_all(&bytes).map_err(|e| e.to_string())?;
    } else {
        std::fs::write(out, &bytes).map_err(|e| format!("{out}: {e}"))?;
    }
    log(pool, "Export Table", table, &summary).await?;
    Ok(Done::Ok(if out.is_empty() { String::new() } else { summary.join("\n") + "\n" }))
}

async fn import(pool: &SqlitePool, a: &Args) -> Result<Done, Fail> {
    let name = a.words.first().map_or("", String::as_str);
    let Some(path) = a.words.get(1) else { return Err(bad("import needs a table and a file")) };
    let bytes = std::fs::read(path).map_err(|e| format!("{path}: {e}"))?;
    if bytes.len() > map::MAX_UPLOAD {
        return Err(Fail::Run(format!("{path} is larger than {} bytes", map::MAX_UPLOAD)));
    }
    let update = a.has("--update");
    if name == "prj" {
        a.only(&["--update"]).map_err(bad)?;
        return prj(pool, path, &bytes, update).await;
    }
    a.only(&["--update", "--subcategory", "--category", "--record-type", "--worksheet", "--replace", "--xr"]).map_err(bad)?;
    let table = table(a, Table::imports)?;
    let mut sheet = map::read_upload(path, &bytes, Some(a.get("--worksheet")).filter(|w| !w.is_empty())).map_err(Fail::Run)?;
    if a.has("--xr") {
        sheet = data::xr_rows(sheet);
    }
    let worksheet = sheet.worksheet.clone();
    let report = data::import(pool, table, &options(a).map_err(bad)?, sheet, update).await?;
    let mut out = vec![format!("Table: {}", table.name()), format!("File: {path}")];
    out.extend(worksheet.map(|w| format!("Worksheet: {w}")));
    out.push(format!("Lines: {} ({} empty)", report.accepted, report.empty));
    out.push(format!("{}: {}", if report.kept { "Updated records" } else { "Lines that pass" }, report.saved));
    out.push(format!("Rejected records: {}", report.rejected.len()));
    if report.kept {
        log(pool, "Import Table", table, &out).await?;
    }
    for (line, errors) in &report.rejected {
        for (field, message) in errors {
            out.push(format!("  line {line}: {field}: {message}"));
        }
    }
    out.push(match (update, report.kept) {
        (false, _) => "Review complete: nothing saved (add --update to save)".into(),
        (true, true) => "Update complete".into(),
        (true, false) => "Update rolled back: every line must pass for this table".into(),
    });
    let text = out.join("\n") + "\n";
    Ok(if report.rejected.is_empty() { Done::Ok(text) } else { Done::Rejected(text) })
}

async fn prj(pool: &SqlitePool, path: &str, bytes: &[u8], update: bool) -> Result<Done, Fail> {
    let rows = data::prj_export_rows(&String::from_utf8_lossy(bytes));
    if rows.is_empty() {
        return Err(Fail::Run(format!("{path}: empty or not a PARTsolutions export")));
    }
    let o = data::prj_import(pool, &rows, update).await.map_err(|e| e.to_string())?;
    let mut out = vec![format!("File: {path}"), format!("Unique records: {}", rows.len())];
    for (name, skus) in [(if update { "Changed" } else { "Would change" }, &o.changed), ("Not Changed", &o.not_changed), ("Missing/Invalid SKU", &o.missing)] {
        out.push(format!("{name}: {}", skus.len()));
        out.extend(skus.iter().map(|s| format!("  {s}")));
    }
    if !update {
        out.push("Review complete: nothing saved (add --update to save)".into());
    }
    Ok(Done::Ok(out.join("\n") + "\n"))
}

/// Remote grants (remote_grant.rs), which the /staff/remote-grants page minted, listed and revoked. `--by` names the
/// staff account the grant is made in the name of; it must be allowed to grant to the account, as on the page.
async fn grants(pool: &SqlitePool, a: &Args) -> Result<Done, Fail> {
    a.only(&["--by"]).map_err(bad)?;
    let now = db::unix_now();
    let words: Vec<&str> = a.words.iter().map(String::as_str).collect();
    let fail = |e: sqlx::Error| Fail::Run(e.to_string());
    let when = |t: i64| time::date("m/d/Y h:i:s A", t);
    match words[..] {
        ["list"] => {
            let mut out = String::from("grant\taccount\tcompany\tgranted by\tcreated\texpires\n");
            for g in remote_grant::live(pool, now).await.map_err(fail)? {
                out.push_str(&format!("{}\t{}\t{}\t{} {}\t{}\t{}\n", g.id, g.user_id.0, g.company_name, g.granted_by.0, g.granted_by_name, when(g.created_at), when(g.expires_at)));
            }
            Ok(Done::Ok(out))
        }
        ["mint", target] => {
            let actor = actor(pool, a).await?;
            let target: i64 = target.parse().map_err(|_| bad(format!("not an account id: {target}")))?;
            let user = identity::load_user(pool, UserId(target)).await.map_err(fail)?;
            let Some(user) = user.filter(|t| remote_grant::may_grant(&actor, t)) else {
                audit::record(pool, Entry { subtype: "remote_grant_denied", user_id: actor.id.0, email: None, ip: "", comment: "" }).await;
                return Err(Fail::Run(format!("account {} may not grant to account {target}", actor.id.0)));
            };
            let (token, expires_at) = remote_grant::mint(pool, user.id, actor.id, now).await.map_err(fail)?;
            let comment = format!("for user {}", user.id.0);
            audit::record(pool, Entry { subtype: "remote_granted", user_id: actor.id.0, email: None, ip: "", comment: &comment }).await;
            Ok(Done::Ok(format!("Grant created for user {}. It works once, until {}:\n{}\n", user.id.0, when(expires_at), token.as_str())))
        }
        ["revoke", grant] => {
            let actor = actor(pool, a).await?;
            let id: i64 = grant.parse().map_err(|_| bad(format!("not a grant id: {grant}")))?;
            let target = match remote_grant::live_target(pool, id, now).await.map_err(fail)? {
                Some(user) => identity::load_user(pool, user).await.map_err(fail)?,
                None => None,
            };
            let allowed = actor.is_active() && target.is_some_and(|t| t.role <= actor.role);
            if !allowed || !remote_grant::revoke(pool, id, now).await.map_err(fail)? {
                return Err(Fail::Run(format!("no live grant {id} that account {} may revoke", actor.id.0)));
            }
            let comment = format!("grant {id}");
            audit::record(pool, Entry { subtype: "remote_revoked", user_id: actor.id.0, email: None, ip: "", comment: &comment }).await;
            Ok(Done::Ok(format!("Grant {id} revoked\n")))
        }
        _ => Err(bad("remote-grants: list, mint or revoke")),
    }
}

/// The staff account `--by` names.
async fn actor(pool: &SqlitePool, a: &Args) -> Result<identity::User, Fail> {
    let id: i64 = a.get("--by").parse().map_err(|_| bad("--by <staff id> is required"))?;
    identity::load_user(pool, UserId(id)).await.map_err(|e| Fail::Run(e.to_string()))?.ok_or_else(|| Fail::Run(format!("no account {id}")))
}

/// The command a test runs: `args` without the binary's name, against `pool`.
pub async fn run_on(pool: &SqlitePool, args: &[&str]) -> Result<Done, Fail> {
    let owned: Vec<String> = args.iter().map(|s| s.to_string()).collect();
    let a = Args::parse(owned.get(1..).unwrap_or(&[])).map_err(bad)?;
    match args.first().copied() {
        Some("job") => job(pool, &a).await,
        Some("export") => export(pool, &a).await,
        Some("import") => import(pool, &a).await,
        Some("remote-grants") => grants(pool, &a).await,
        _ => Err(bad("unknown command")),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn args(s: &[&str]) -> Args {
        Args::parse(&s.iter().map(|x| x.to_string()).collect::<Vec<_>>()).unwrap()
    }

    #[test]
    fn words_options_and_switches_parse_apart() {
        let a = args(&["inventory", "parts.csv", "--update", "--subcategory", "12"]);
        assert_eq!(a.words, ["inventory", "parts.csv"]);
        assert!(a.has("--update"));
        assert_eq!(a.id("--subcategory"), Ok(12));
        assert!(a.only(&["--update"]).is_err());
        assert!(Args::parse(&["--out".to_string()]).is_err());
    }

    #[test]
    fn dates_are_read_in_the_forms_people_type() {
        assert_eq!(day("2025-03-05").as_deref(), Ok("2025/03/05"));
        assert_eq!(day("3/5/2025").as_deref(), Ok("2025/03/05"));
        assert_eq!(day("").as_deref(), Ok(""));
        assert!(day("soon").is_err());
        assert!(options(&args(&["--from", "2025-03-05", "--thru", "2025-03-01"])).is_err());
    }
}
