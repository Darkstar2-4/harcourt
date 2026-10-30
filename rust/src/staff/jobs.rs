//! `hxweb job <name>`: the work cron.php used to start over HTTP with an API key, run as a command by a systemd
//! timer instead. Nothing here is reachable from the web.

use std::{
    fs::{self, OpenOptions},
    io::Write,
    os::unix::fs::OpenOptionsExt,
    path::Path,
    sync::Arc,
};

use axum::Router;
use sqlx::SqlitePool;

use super::time;
use crate::{api::Auth, config::Config, db};

pub fn routes() -> Router<Arc<Auth>> {
    Router::new()
}

const USAGE: &str = "usage: hxweb job search_export <dir> | search_export --log <file>";

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

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn cells_are_one_line() {
        assert_eq!(cell(Some("a\tb\r\nc".to_string())), "a b  c");
        assert_eq!(cell(None::<String>), "");
        assert_eq!(num(None), "");
    }
}
