//! `hxweb job <name>`: the work cron.php used to start over HTTP with an API key (search_utility, image_filmstrip,
//! cad_toolbox), run as a command by a systemd timer instead, and the price increase inventory_increase.php ran.
//! Nothing here is reachable from the web.

use std::{
    fs::{self, OpenOptions},
    io::Write,
    os::unix::fs::OpenOptionsExt,
    path::Path,
    str::FromStr,
};

use rust_decimal::{Decimal, RoundingStrategy};
use sqlx::SqlitePool;

use super::{
    data::{pricing, pricing_text},
    html::number,
    time,
};

/// One TSV cell: tabs and line breaks become spaces, NULL is empty.
fn cell(v: impl Into<Option<String>>) -> String {
    v.into().unwrap_or_default().replace(['\t', '\r', '\n'], " ")
}

fn num(v: Option<i64>) -> String {
    v.map(|n| n.to_string()).unwrap_or_default()
}

fn line(cells: &[String]) -> String {
    cells.join("\t") + "\n"
}

/// search_export.php: the files hxweb's catalog search loads (rows, families, categories, parts). Each is written
/// whole under a temporary name and all are renamed into place at the end, so the reader never sees half a file.
pub async fn search_export(pool: &SqlitePool, dir: &Path) -> Result<String, String> {
    fs::create_dir_all(dir).map_err(|e| format!("{}: {e}", dir.display()))?;
    let rows: Vec<String> = sqlx::query!(
        r#"SELECT id AS "id!: i64", table_name, ifnull(language_code, '') AS "language_code!: String", active, code,
                  category_id, subcategory_id, inventory_id, ranking FROM search ORDER BY id"#
    )
    .fetch_all(pool)
    .await
    .map_err(|e| format!("rows.tsv: {e}"))?
    .into_iter()
    .map(|r| {
        line(&[
            r.id.to_string(),
            cell(r.table_name),
            cell(r.language_code),
            num(r.active),
            cell(r.code),
            num(r.category_id),
            num(r.subcategory_id),
            num(r.inventory_id),
            num(r.ranking),
        ])
    })
    .collect();
    let families: Vec<String> = sqlx::query!(
        r#"SELECT s.id AS "id!: i64", s.category_id, s.status,
             ifnull((SELECT name FROM content c WHERE c.record_type = 'subcategory' AND c.record_item = '' AND c.record_id = s.id AND c.language_code = 'EN' LIMIT 1), '') AS "en!: String",
             ifnull((SELECT name FROM content c WHERE c.record_type = 'subcategory' AND c.record_item = '' AND c.record_id = s.id AND c.language_code = 'FR' LIMIT 1), '') AS "fr!: String"
           FROM subcategory s ORDER BY s.id"#
    )
    .fetch_all(pool)
    .await
    .map_err(|e| format!("families.tsv: {e}"))?
    .into_iter()
    .map(|r| line(&[r.id.to_string(), num(r.category_id), cell(r.status), cell(r.en), cell(r.fr)]))
    .collect();
    let categories: Vec<String> = sqlx::query!(
        r#"SELECT k.id AS "id!: i64",
             ifnull((SELECT name FROM content c WHERE c.record_type = 'category' AND c.record_item = '' AND c.record_id = k.id AND c.language_code = 'EN' LIMIT 1), '') AS "en!: String",
             ifnull((SELECT name FROM content c WHERE c.record_type = 'category' AND c.record_item = '' AND c.record_id = k.id AND c.language_code = 'FR' LIMIT 1), '') AS "fr!: String"
           FROM category k ORDER BY k.id"#
    )
    .fetch_all(pool)
    .await
    .map_err(|e| format!("categories.tsv: {e}"))?
    .into_iter()
    .map(|r| line(&[r.id.to_string(), cell(r.en), cell(r.fr)]))
    .collect();
    let parts: Vec<String> = sqlx::query!(r#"SELECT id AS "id!: i64", sku, subcategory_id FROM inventory ORDER BY id"#)
        .fetch_all(pool)
        .await
        .map_err(|e| format!("parts.tsv: {e}"))?
        .into_iter()
        .map(|r| line(&[r.id.to_string(), cell(r.sku), num(r.subcategory_id)]))
        .collect();
    let files = [("rows.tsv", rows), ("families.tsv", families), ("categories.tsv", categories), ("parts.tsv", parts)];
    let mut summary = String::new();
    for (name, lines) in &files {
        let tmp = dir.join(format!("{name}.tmp"));
        fs::write(&tmp, lines.concat()).map_err(|e| format!("{}: {e}", tmp.display()))?;
        summary.push_str(&format!("{name}: {} rows\n", lines.len()));
    }
    for (name, _) in &files {
        let tmp = dir.join(format!("{name}.tmp"));
        fs::rename(&tmp, dir.join(name)).map_err(|e| format!("{}: {e}", tmp.display()))?;
    }
    Ok(summary)
}

/// `search_export.php --log`: two years of catalog searches, the input of search_learn.sh. It holds visitor
/// addresses, so the file is readable by its owner only. Searches by bench-test accounts are left out.
pub async fn search_log(pool: &SqlitePool, file: &Path, now: i64) -> Result<String, String> {
    let today = time::detroit(now);
    let since = time::from_local(today.year - 2, today.month, today.day.min(28), today.hour, today.minute, today.second);
    let rows = sqlx::query!(
        r#"SELECT id AS "id!: i64", date_time AS "date_time!: i64", user_id AS "user_id!: i64", ip AS "ip!", subtype, sku AS "sku!",
                  subcategory_id AS "subcategory_id!: i64", comment
           FROM log WHERE type = 'Search' AND bot_id = 0 AND date_time > ?1
             AND user_id NOT IN (SELECT id FROM "user" WHERE comment = 'bench-test')
           ORDER BY id"#,
        since
    )
    .fetch_all(pool)
    .await
    .map_err(|e| format!("log: {e}"))?;
    let mut out = OpenOptions::new()
        .write(true)
        .create(true)
        .truncate(true)
        .mode(0o600)
        .open(file)
        .map_err(|e| format!("{}: {e}", file.display()))?;
    let mut body = String::from("id\tdate_time\tuser_id\tip\tsubtype\tsku\tsubcategory_id\tc\n");
    for r in &rows {
        let comment = r.comment.clone().map(|c| c.replace('\n', " | "));
        body.push_str(&line(&[
            r.id.to_string(),
            r.date_time.to_string(),
            r.user_id.to_string(),
            cell(r.ip.clone()),
            cell(r.subtype.clone()),
            cell(r.sku.clone()),
            r.subcategory_id.to_string(),
            cell(comment),
        ]));
    }
    out.write_all(body.as_bytes()).map_err(|e| format!("{}: {e}", file.display()))?;
    Ok(format!("log: {} rows\n", rows.len()))
}

/// One row of the `search` table the catalog search reads.
struct Term {
    code: String,
    category_id: i64,
    subcategory_id: i64,
    inventory_id: i64,
    ranking: i64,
}

/// `regexp_replace(name, ' *&[a-zA-Z0-9#]+;', '')`: category names lose their HTML entities and the spaces before.
fn strip_entities(name: &str) -> String {
    let mut out = String::with_capacity(name.len());
    let mut rest = name;
    while let Some(at) = rest.find('&') {
        let tail = &rest[at + 1..];
        let len = tail.bytes().take_while(|b| b.is_ascii_alphanumeric() || *b == b'#').count();
        if len > 0 && tail.as_bytes().get(len) == Some(&b';') {
            out.push_str(rest[..at].trim_end_matches(' '));
            rest = &tail[len + 1..];
        } else {
            out.push_str(&rest[..=at]);
            rest = tail;
        }
    }
    out.push_str(rest);
    out
}

/// The terms of a list field, in the order and with the filtering search_utility.php applied.
fn search_words(stored: &str) -> Vec<String> {
    let mut out: Vec<String> = Vec::new();
    for w in stored.split('^').map(str::trim) {
        if w.len() > 2 && !out.iter().any(|o| o == w) {
            out.push(w.to_string());
        }
    }
    out
}

/// MySQL's latin1_swedish_ci order for the codes: case-insensitive, trailing spaces ignored.
fn ci_key(code: &str) -> String {
    code.trim_end_matches(' ').to_lowercase()
}

async fn insert_terms(tx: &mut sqlx::SqliteConnection, language: &str, table: &str, mut terms: Vec<Term>) -> sqlx::Result<usize> {
    terms.sort_by(|a, b| ci_key(&a.code).cmp(&ci_key(&b.code)).then(a.ranking.cmp(&b.ranking)));
    for t in &terms {
        sqlx::query!(
            "INSERT INTO search (language_code, active, table_name, code, code_normalized, category_id, subcategory_id, inventory_id, ranking)
             VALUES (?1, 1, ?2, ?3, replace(replace(replace(replace(replace(?3, '-', ''), ' ', ''), '_', ''), '.', ''), '/', ''), ?4, ?5, ?6, ?7)",
            language,
            table,
            t.code,
            t.category_id,
            t.subcategory_id,
            t.inventory_id,
            t.ranking
        )
        .execute(&mut *tx)
        .await?;
    }
    Ok(terms.len())
}

/// search_utility.php: rebuild the `search` table from the active families, their names in every language, their
/// part numbers, search words and competitor part numbers, then drop duplicates. One transaction: a failed run
/// leaves yesterday's table in place, and a rerun converges on the same rows.
pub async fn search_utility(pool: &SqlitePool, now: i64) -> Result<String, String> {
    let fail = |e: sqlx::Error| format!("search: {e}");
    let mut tx = pool.begin().await.map_err(fail)?;
    sqlx::query!("DELETE FROM search").execute(&mut *tx).await.map_err(fail)?;
    let families = sqlx::query_scalar!(r#"SELECT count(*) AS "n!: i64" FROM subcategory WHERE lower(status) = 'active'"#)
        .fetch_one(&mut *tx)
        .await
        .map_err(fail)?;

    let names = sqlx::query!(
        r#"SELECT c.language_code AS "language!: String", c.name AS "name!: String", k.id AS "id!: i64", k.sort_order AS "sort_order!: i64"
           FROM content c JOIN category k ON k.id = c.record_id
           WHERE c.record_type = 'category' AND c.record_item = '' AND k.active = 1 ORDER BY c.id"#
    )
    .fetch_all(&mut *tx)
    .await
    .map_err(fail)?;
    for n in &names {
        let code = strip_entities(&n.name);
        let ranking = n.sort_order - 1_000_000;
        sqlx::query!(
            "INSERT INTO search (language_code, active, table_name, code, code_normalized, category_id, subcategory_id, inventory_id, ranking)
             VALUES (?1, 1, 'category', ?2, replace(replace(replace(replace(replace(?2, '-', ''), ' ', ''), '_', ''), '.', ''), '/', ''), ?3, 0, 0, ?4)",
            n.language,
            code,
            n.id,
            ranking
        )
        .execute(&mut *tx)
        .await
        .map_err(fail)?;
    }

    let languages = sqlx::query!(
        "INSERT INTO search (language_code, active, table_name, code, code_normalized, category_id, subcategory_id, inventory_id, ranking)
         SELECT c.language_code, 1, 'subcategory', c.name,
                replace(replace(replace(replace(replace(c.name, '-', ''), ' ', ''), '_', ''), '.', ''), '/', ''),
                s.category_id, s.id, 0, s.sort_order
         FROM content c JOIN subcategory s ON s.id = c.record_id
         WHERE c.record_type = 'subcategory' AND c.record_item = '' AND lower(s.status) = 'active' ORDER BY c.id"
    )
    .execute(&mut *tx)
    .await
    .map_err(fail)?
    .rows_affected();

    let parts = sqlx::query!(
        "INSERT INTO search (language_code, active, table_name, code, code_normalized, category_id, subcategory_id, inventory_id, ranking)
         SELECT '', 1, 'inventory', i.sku,
                replace(replace(replace(replace(replace(i.sku, '-', ''), ' ', ''), '_', ''), '.', ''), '/', ''),
                s.category_id, s.id, i.id, i.sort_order + 2000000
         FROM inventory i JOIN subcategory s ON s.id = i.subcategory_id
         WHERE i.active = 1 AND lower(s.status) = 'active' ORDER BY i.id"
    )
    .execute(&mut *tx)
    .await
    .map_err(fail)?
    .rows_affected();

    let subs = sqlx::query!(
        r#"SELECT id AS "id!: i64", category_id AS "category_id!: i64", sort_order AS "sort_order!: i64", search_words
           FROM subcategory WHERE lower(status) = 'active' ORDER BY id"#
    )
    .fetch_all(&mut *tx)
    .await
    .map_err(fail)?;
    let words = subs
        .iter()
        .flat_map(|s| {
            search_words(s.search_words.as_deref().unwrap_or("")).into_iter().map(move |code| Term {
                code,
                category_id: s.category_id,
                subcategory_id: s.id,
                inventory_id: 0,
                ranking: s.sort_order + 1_000_000,
            })
        })
        .collect();
    let words = insert_terms(&mut tx, "EN", "search_words", words).await.map_err(fail)?;

    let rivals = sqlx::query!(
        r#"SELECT i.id AS "id!: i64", i.category_id AS "category_id!: i64", i.subcategory_id AS "subcategory_id!: i64",
                  i.sort_order AS "sort_order!: i64", i.competitor_sku AS "competitor_sku!: String"
           FROM inventory i JOIN subcategory s ON s.id = i.subcategory_id
           WHERE i.active = 1 AND lower(s.status) = 'active' AND i.competitor_sku <> '' ORDER BY i.id"#
    )
    .fetch_all(&mut *tx)
    .await
    .map_err(fail)?;
    let rivals = rivals
        .iter()
        .flat_map(|i| {
            i.competitor_sku.split(',').map(move |code| Term {
                code: code.to_string(),
                category_id: i.category_id,
                subcategory_id: i.subcategory_id,
                inventory_id: i.id,
                ranking: i.sort_order + 3_000_000,
            })
        })
        .collect();
    let rivals = insert_terms(&mut tx, "", "competitor_sku", rivals).await.map_err(fail)?;

    let duplicates = sqlx::query_scalar!(
        r#"SELECT count(*) AS "n!: i64" FROM (SELECT 1 FROM search
             GROUP BY language_code, lower(rtrim(code)), subcategory_id, inventory_id HAVING count(*) > 1)"#
    )
    .fetch_one(&mut *tx)
    .await
    .map_err(fail)?;
    sqlx::query!(
        "DELETE FROM search WHERE id NOT IN (SELECT min(id) FROM search GROUP BY language_code, lower(rtrim(code)), subcategory_id, inventory_id)"
    )
    .execute(&mut *tx)
    .await
    .map_err(fail)?;
    let unique = sqlx::query_scalar!(r#"SELECT count(*) AS "n!: i64" FROM search"#).fetch_one(&mut *tx).await.map_err(fail)?;

    let n = number;
    let lines = [
        format!("Subcategory: {}", n(families)),
        format!("Category Languages: {}", n(names.len() as i64)),
        format!("Subcategory Languages: {}", n(languages as i64)),
        format!("Search Words: {}", n(words as i64)),
        format!("Inventory: {}", n(parts as i64)),
        format!("Competitor SKU: {}", n(rivals as i64)),
        format!("Purge Duplicates: {}", n(duplicates)),
        format!("Unique search terms: {}", n(unique)),
    ];
    let comment = lines.join("\n");
    sqlx::query!("INSERT INTO log (date_time, type, subtype, comment) VALUES (?1, 'CRON', 'Search Utility', ?2)", now, comment)
        .execute(&mut *tx)
        .await
        .map_err(fail)?;
    let stamp = now.to_string();
    let updated = sqlx::query!("UPDATE registry SET data = ?1 WHERE domain = '' AND module = 'local' AND field = 'search_timestamp' AND item = ''", stamp)
        .execute(&mut *tx)
        .await
        .map_err(fail)?
        .rows_affected();
    if updated == 0 {
        sqlx::query!("INSERT INTO registry (domain, module, field, item, data) VALUES ('', 'local', 'search_timestamp', '', ?1)", stamp)
            .execute(&mut *tx)
            .await
            .map_err(fail)?;
    }
    tx.commit().await.map_err(fail)?;
    Ok(comment + "\n")
}

/// One CAD format PARTcommunity offers, as the `cad` table keeps it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct CadFormat {
    pub code: String,
    pub name: String,
    pub qualifier: String,
    pub version: String,
    pub kind: String,
    pub native: bool,
    pub has_macro: bool,
    pub direct: bool,
}

fn xml_text(s: &str) -> String {
    s.trim().replace("&lt;", "<").replace("&gt;", ">").replace("&quot;", "\"").replace("&apos;", "'").replace("&amp;", "&")
}

/// The `<format>` elements of cadqualifier.asp's answer: attributes `type` and `cad`, children `qualifier` (the
/// code), `name`, `version`, `macro` and `directintocad`. None when it is not that list.
pub fn cad_formats(xml: &str) -> Option<Vec<CadFormat>> {
    static RE: std::sync::OnceLock<(regex::Regex, regex::Regex, regex::Regex)> = std::sync::OnceLock::new();
    let (format, attr, child) = RE.get_or_init(|| {
        (
            regex::Regex::new(r"(?s)<format\b([^>]*?)(?:/>|>(.*?)</format>)").expect("format"),
            regex::Regex::new(r#"([A-Za-z_]+)\s*=\s*"([^"]*)""#).expect("attribute"),
            regex::Regex::new(r"(?s)<([A-Za-z_]+)\s*(?:/>|>([^<]*)</[A-Za-z_]+>)").expect("child"),
        )
    });
    if !xml.contains("<format") {
        return None;
    }
    let mut out: Vec<CadFormat> = Vec::new();
    for f in format.captures_iter(xml) {
        let attrs: Vec<(String, String)> = attr.captures_iter(&f[1]).map(|a| (a[1].to_string(), xml_text(&a[2]))).collect();
        let children: Vec<(String, String)> = f.get(2).map_or(Vec::new(), |b| child.captures_iter(b.as_str()).map(|c| (c[1].to_string(), c.get(2).map_or(String::new(), |v| xml_text(v.as_str())))).collect());
        let attr = |k: &str| attrs.iter().find(|(n, _)| n == k).map_or("", |(_, v)| v.as_str());
        let get = |k: &str| children.iter().find(|(n, _)| n == k).map_or("", |(_, v)| v.as_str());
        let direct = get("directintocad").eq_ignore_ascii_case("true") || get("directintocad") == "1";
        let format = CadFormat {
            code: get("qualifier").to_string(),
            name: format!("{} {}", get("name"), get("version")).trim().to_string(),
            qualifier: get("name").to_string(),
            version: get("version").to_string(),
            kind: if direct { "PART2CAD".into() } else { attr("cad").to_uppercase() },
            native: attr("type") == "native",
            has_macro: get("macro") == "true",
            direct,
        };
        match out.iter_mut().find(|o| o.code == format.code) {
            Some(o) => *o = format,
            None => out.push(format),
        }
    }
    Some(out)
}

/// cad_toolbox.php's cron job: the CAD formats PARTcommunity offers for the site's portal (or catalog), which
/// replace the `cad` table. A failed or empty answer leaves the table as it was.
pub async fn cad_refresh(pool: &SqlitePool, now: i64) -> Result<String, String> {
    let fail = |e: sqlx::Error| format!("cad: {e}");
    let portal = crate::accounts::registry::get(pool, "cad", "portal").await.map_err(fail)?;
    let catalog = crate::accounts::registry::get(pool, "cad", "catalog").await.map_err(fail)?;
    let query = match (portal, catalog) {
        (Some(p), _) => [("portal", p)],
        (None, Some(c)) => [("firm", c)],
        (None, None) => return Err("registry cad.portal or cad.catalog is not set".into()),
    };
    let url = "https://www.partcommunity.com/cadqualifier.asp";
    let client = reqwest::Client::builder().timeout(std::time::Duration::from_secs(120)).build().map_err(|e| e.to_string())?;
    let answer = client.get(url).query(&query).send().await.and_then(reqwest::Response::error_for_status);
    let message = match answer {
        Err(e) => format!("Import Error: {}", e.status().map_or("no answer".to_string(), |s| format!("HTTP Code: {}", s.as_u16()))),
        Ok(r) => match cad_formats(&r.text().await.unwrap_or_default()) {
            None => "Malformed XML response".into(),
            Some(f) if f.is_empty() => "No records found".into(),
            Some(f) => {
                replace_cad(pool, &f).await.map_err(fail)?;
                format!("CAD formats: {}", f.len())
            }
        },
    };
    let comment = format!("{message}\nURL: {url}?{}={}", query[0].0, query[0].1);
    sqlx::query!("INSERT INTO log (ip, date_time, user_id, email, type, subtype, comment) VALUES ('', ?1, 0, '', 'CRON', 'CAD', ?2)", now, comment)
        .execute(pool)
        .await
        .map_err(fail)?;
    Ok(comment + "\n")
}

pub async fn replace_cad(pool: &SqlitePool, formats: &[CadFormat]) -> sqlx::Result<()> {
    let mut tx = pool.begin().await?;
    sqlx::query!("DELETE FROM cad").execute(&mut *tx).await?;
    for f in formats {
        sqlx::query!(
            r#"INSERT INTO cad (code, name, qualifier, version, "type", instructions, "macro", native, direct, active) VALUES (?1, ?2, ?3, ?4, ?5, '', ?6, ?7, ?8, 1)"#,
            f.code,
            f.name,
            f.qualifier,
            f.version,
            f.kind,
            f.has_macro,
            f.native,
            f.direct
        )
        .execute(&mut *tx)
        .await?;
    }
    tx.commit().await
}

/// How inventory_increase.php raised prices: by a fixed amount or a percentage of each break, to the cent.
#[derive(Debug, Clone, Copy, PartialEq)]
pub enum Increase {
    Fixed(Decimal),
    Percent(Decimal),
}

impl Increase {
    /// A fixed amount above $0.00, or a percentage above 0 and below 100.
    pub fn parse(kind: &str, value: &str) -> Result<Increase, String> {
        let amount = Decimal::from_str(value.trim().trim_start_matches('$').trim_end_matches('%')).map_err(|_| format!("not an amount: {value}"))?;
        match kind {
            "fixed" if amount > Decimal::ZERO => Ok(Increase::Fixed(amount)),
            "percent" if amount > Decimal::ZERO && amount < Decimal::ONE_HUNDRED => Ok(Increase::Percent(amount)),
            "fixed" => Err("a fixed increase must be > $0.00".into()),
            _ => Err("a percent increase must be > 0.00% and < 100.00%".into()),
        }
    }

    fn kind(self) -> &'static str {
        match self {
            Increase::Fixed(_) => "Fixed Amount",
            Increase::Percent(_) => "Percent",
        }
    }

    fn apply(self, price: Decimal) -> Decimal {
        match self {
            Increase::Fixed(add) => price + add,
            Increase::Percent(pct) => price + (price * pct / Decimal::ONE_HUNDRED).round_dp_with_strategy(2, RoundingStrategy::MidpointAwayFromZero),
        }
    }

    fn describe(self) -> String {
        match self {
            Increase::Fixed(d) => format!("${:.2}", d),
            Increase::Percent(d) => format!("{:.2}%", d),
        }
    }
}

/// The subcategories a price increase may touch: active, not smart, in a category.
async fn raisable(pool: &SqlitePool) -> sqlx::Result<Vec<i64>> {
    sqlx::query_scalar!(
        r#"SELECT DISTINCT s.id AS "id!: i64" FROM inventory i JOIN category c ON c.id = i.category_id JOIN subcategory s ON s.id = i.subcategory_id
           WHERE s.output <> 'smart' AND s.status = 'Active' COLLATE NOCASE AND c.id <> 0 AND s.id <> 0 ORDER BY s.id"#
    )
    .fetch_all(pool)
    .await
}

/// inventory_increase.php: raise every price break of the parts in these subcategories (all that may be raised when
/// none are named). It cannot be rolled back. Each subcategory is raised in one transaction and stamped with the
/// time; a part with no base price is skipped.
pub async fn price_increase(pool: &SqlitePool, increase: Increase, subcategories: &[i64], now: i64) -> Result<String, String> {
    let fail = |e: sqlx::Error| format!("price increase: {e}");
    let allowed = raisable(pool).await.map_err(fail)?;
    if let Some(id) = subcategories.iter().find(|id| !allowed.contains(id)) {
        return Err(format!("subcategory {id} is not an active, priced subcategory"));
    }
    let chosen = if subcategories.is_empty() { allowed } else { subcategories.to_vec() };
    let (mut updated, mut skipped) = (0i64, 0i64);
    for &id in &chosen {
        let mut tx = pool.begin().await.map_err(fail)?;
        let parts = sqlx::query!(r#"SELECT id AS "id!: i64", pricing FROM inventory WHERE subcategory_id = ?1"#, id).fetch_all(&mut *tx).await.map_err(fail)?;
        for part in parts {
            let mut breaks = part.pricing.as_deref().map(pricing).unwrap_or_default();
            if breaks.first().is_none_or(|b| b.price.is_zero()) {
                skipped += 1;
                continue;
            }
            for b in &mut breaks {
                b.price = increase.apply(b.price);
            }
            let stored = pricing_text(&breaks);
            sqlx::query!("UPDATE inventory SET pricing = ?2 WHERE id = ?1", part.id, stored).execute(&mut *tx).await.map_err(fail)?;
            updated += 1;
        }
        sqlx::query!("UPDATE subcategory SET price_increase = ?2 WHERE id = ?1", id, now).execute(&mut *tx).await.map_err(fail)?;
        tx.commit().await.map_err(fail)?;
    }
    let comment = [
        format!("Subcategories: {}", number(chosen.len() as i64)),
        format!("Increase: {}", increase.describe()),
        format!("SKUs updated: {} SKUs skipped: {}", number(updated), number(skipped)),
    ]
    .join("\n");
    let subtype = format!("Price Increase: {}", increase.kind());
    sqlx::query!("INSERT INTO log (ip, date_time, user_id, email, type, subtype, comment) VALUES ('', ?1, 0, '', 'Import Table', ?2, ?3)", now, subtype, comment)
        .execute(pool)
        .await
        .map_err(fail)?;
    Ok(comment + "\n")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn increases_round_each_break_to_the_cent() {
        let d = |s: &str| Decimal::from_str(s).unwrap();
        assert_eq!(Increase::Percent(d("2.5")).apply(d("12.30")), d("12.61"));
        assert_eq!(Increase::Percent(d("10")).apply(d("0.05")), d("0.06"));
        assert_eq!(Increase::Fixed(d("1.25")).apply(d("12.30")), d("13.55"));
        assert_eq!(Increase::Percent(d("12.5")).describe(), "12.50%");
        assert_eq!(Increase::parse("fixed", "$1.50"), Ok(Increase::Fixed(d("1.50"))));
        assert!(Increase::parse("percent", "100").is_err());
        assert!(Increase::parse("fixed", "0").is_err());
    }

    #[test]
    fn cad_formats_read_from_the_qualifier_list() {
        let xml = r#"<?xml version="1.0"?><formats>
            <format type="native" cad="solidworks"><qualifier>SWX</qualifier><name>SolidWorks</name><version>2024</version><macro>true</macro><directintocad>true</directintocad></format>
            <format type="neutral" cad="step"><qualifier>STEP-AP214</qualifier><name>STEP &amp; more</name><version/><macro>false</macro><directintocad>false</directintocad></format>
            <format type="neutral" cad="step"><qualifier>STEP-AP214</qualifier><name>STEP AP214</name><version>2</version></format>
        </formats>"#;
        let f = cad_formats(xml).unwrap();
        assert_eq!(f.len(), 2, "one row per code, the last kept");
        assert_eq!((f[0].code.as_str(), f[0].name.as_str(), f[0].kind.as_str(), f[0].native, f[0].has_macro, f[0].direct), ("SWX", "SolidWorks 2024", "PART2CAD", true, true, true));
        assert_eq!((f[1].name.as_str(), f[1].kind.as_str(), f[1].native), ("STEP AP214 2", "STEP", false));
        assert_eq!(cad_formats("<html>maintenance</html>"), None);
        assert_eq!(cad_formats("<formats><format/></formats>").map(|f| f.len()), Some(1));
    }

    #[test]
    fn category_names_lose_entities_and_words_are_filtered() {
        assert_eq!(strip_entities("Clamps &amp; Pins"), "Clamps Pins");
        assert_eq!(strip_entities("Tools&reg;"), "Tools");
        assert_eq!(strip_entities("A & B &#8482;"), "A & B");
        assert_eq!(search_words(" hbox ^ab^riser^hbox^"), ["hbox", "riser"]);
        assert_eq!(ci_key("Abc  "), "abc");
    }

    #[test]
    fn cells_are_one_line() {
        assert_eq!(cell(Some("a\tb\r\nc".to_string())), "a b  c");
        assert_eq!(cell(None::<String>), "");
        assert_eq!(num(None), "");
    }
}
