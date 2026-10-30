//! `hxweb job <name>`: the work cron.php used to start over HTTP with an API key, run as a command by a systemd
//! timer instead. Nothing here is reachable from the web.

use std::{
    fs::{self, OpenOptions},
    io::Write,
    os::unix::fs::OpenOptionsExt,
    path::Path,
};

use sqlx::SqlitePool;

use super::time;
use crate::{config::Config, db};

const USAGE: &str = "usage: hxweb job search_utility | search_export <dir> | search_export --log <file>";

pub async fn cli(args: &[String]) -> i32 {
    let args: Vec<&str> = args.iter().map(String::as_str).collect();
    let config = match Config::from_env(|k| std::env::var(k).ok()) {
        Ok(c) => c,
        Err(e) => {
            eprintln!("{e}");
            return 2;
        }
    };
    let result = match args[..] {
        ["search_utility"] => match db::open(&config.db_path).await {
            Ok(pool) => search_utility(&pool, db::unix_now()).await,
            Err(e) => Err(format!("cannot open {}: {e}", config.db_path.display())),
        },
        ["search_export", "--log", file] => match db::open(&config.db_path).await {
            Ok(pool) => search_log(&pool, Path::new(file), db::unix_now()).await,
            Err(e) => Err(format!("cannot open {}: {e}", config.db_path.display())),
        },
        ["search_export", dir] => match db::open(&config.db_path).await {
            Ok(pool) => search_export(&pool, Path::new(dir)).await,
            Err(e) => Err(format!("cannot open {}: {e}", config.db_path.display())),
        },
        _ => {
            eprintln!("{USAGE}");
            return 2;
        }
    };
    match result {
        Ok(summary) => {
            print!("{summary}");
            0
        }
        Err(e) => {
            eprintln!("{e}");
            1
        }
    }
}

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

    let n = |v: i64| super::html::number(v);
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

#[cfg(test)]
mod tests {
    use super::*;

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
