//! The PARTsolutions links (`prj`): inventory_missing_prj.php lists the parts without one, inventory_prj.php imports
//! them from a PARTsolutions export, and subcategory_prj.php lists subcategories whose project files share a name.
//!
//! The PHP import kept the parsed upload in `scs.tmp` in the document root between the review and the update,
//! one file for every user. Here the review form carries the parsed links back in a hidden field.

use std::sync::Arc;

use axum::{extract::State, response::Response, routing::get, Router};
use sqlx::SqlitePool;

use super::{
    csrf::{Fields, Posted},
    html::{button, close, hidden, number, open, text, Form, Notes, Opts},
    shell, Staff,
};
use crate::api::{Administrator, Auth};

const MISSING_PATH: &str = "/inventory_missing_prj.php";
const IMPORT_PATH: &str = "/inventory_prj.php";
const REVIEW_PATH: &str = "/subcategory_prj.php";

/// One subcategory's parts without a link, in category and subcategory order.
struct Missing {
    subcategory_id: i64,
    category: String,
    subcategory: String,
    skus: Vec<String>,
}

async fn missing(pool: &SqlitePool) -> sqlx::Result<Vec<Missing>> {
    let rows = sqlx::query!(
        r#"SELECT i.subcategory_id AS "subcategory_id!: i64", i.sku,
                  CASE WHEN c.id IS NULL THEN 'Missing Category #' || s.category_id ELSE c.name END AS "category?: String",
                  s.name AS "subcategory?: String"
           FROM inventory i LEFT JOIN subcategory s ON s.id = i.subcategory_id LEFT JOIN category c ON c.id = s.category_id
           WHERE i.prj = ''
           ORDER BY c.sort_order, s.sort_order, i.sku"#
    )
    .fetch_all(pool)
    .await?;
    let mut out: Vec<Missing> = Vec::new();
    for r in rows {
        match out.iter_mut().find(|m| m.subcategory_id == r.subcategory_id) {
            Some(m) => m.skus.push(r.sku),
            None => out.push(Missing {
                subcategory_id: r.subcategory_id,
                category: r.category.unwrap_or_default(),
                subcategory: r.subcategory.unwrap_or_default(),
                skus: vec![r.sku],
            }),
        }
    }
    Ok(out)
}

async fn show_missing(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>) -> Response {
    let pool = &auth.pool;
    let groups = match missing(pool).await {
        Ok(g) => g,
        Err(e) => return shell::internal(e),
    };
    let title = "Inventory Missing PRJ";
    let mut body = Notes::new(title, "").render();
    body.push_str(&open(MISSING_PATH, staff.csrf.as_str(), false));
    body.push_str("<div class='accordion'>\n");
    for g in groups {
        let skus: Vec<String> = g.skus.iter().map(|s| text(s)).collect();
        body.push_str(&format!("<h3>{}: {} ({})</h3>\n<div>\n{}</div>\n", text(&g.category), text(&g.subcategory), skus.len(), skus.join("<br>\n")));
    }
    body.push_str("</div>\n");
    body.push_str(close());
    shell::page(pool, staff.view(), title, &body).await
}

/// ksort() of PHP array keys: numeric keys compare as numbers, anything else as text.
fn php_key_order(a: &str, b: &str) -> std::cmp::Ordering {
    match (a.parse::<i64>(), b.parse::<i64>()) {
        (Ok(x), Ok(y)) => x.cmp(&y),
        _ => a.cmp(b),
    }
}

/// A subcategory with its project path: id, name, first SKU and path.
type Project = (i64, String, String, String);

/// Subcategories grouped by the file name their project path ends in; only names shared by two or more.
async fn shared_projects(pool: &SqlitePool) -> sqlx::Result<Vec<(String, Vec<Project>)>> {
    let rows = sqlx::query!(
        r#"SELECT s.id AS "id!: i64", s.name, s.prj AS "prj!: String",
                  (SELECT sku FROM inventory i WHERE i.subcategory_id = s.id ORDER BY i.id LIMIT 1) AS "sku?: String"
           FROM subcategory s WHERE s.prj IS NOT NULL ORDER BY s.name COLLATE NOCASE"#
    )
    .fetch_all(pool)
    .await?;
    let mut groups: Vec<(String, Vec<Project>)> = Vec::new();
    for r in rows {
        let prj = r.prj.replace("info=", "");
        let code = prj.rsplit('/').next().unwrap_or("").to_string();
        let item = (r.id, r.name.unwrap_or_default(), r.sku.unwrap_or_default(), prj);
        match groups.iter_mut().find(|(c, _)| *c == code) {
            Some((_, items)) => items.push(item),
            None => groups.push((code, vec![item])),
        }
    }
    groups.retain(|(_, items)| items.len() > 1);
    groups.sort_by(|a, b| php_key_order(&a.0, &b.0));
    Ok(groups)
}

async fn show_review(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>) -> Response {
    let pool = &auth.pool;
    let groups = match shared_projects(pool).await {
        Ok(g) => g,
        Err(e) => return shell::internal(e),
    };
    let title = "Subcategory PRJ Review";
    let mut body = Notes::new(title, "").render();
    body.push_str(&open(REVIEW_PATH, staff.csrf.as_str(), false));
    body.push_str("<table class='border'><thead><tr><th>Suffix</th><th>Subcategory ID</th><th>Name</th><th>SKU</th><th>Project</th></tr></thead><tbody>");
    for (code, items) in groups {
        for (i, (id, name, sku, prj)) in items.iter().enumerate() {
            body.push_str("<tr>");
            if i == 0 {
                body.push_str(&format!("<td rowspan={}>{}</td>", items.len(), text(&code)));
            }
            body.push_str(&format!("<td class=right>{id}</td><td>{}</td><td>{}</td><td>{}</td></tr>", text(name), text(sku), text(prj)));
        }
        body.push_str("</tbody>");
    }
    body.push_str("</table>");
    body.push_str(close());
    shell::page(pool, staff.view(), title, &body).await
}

/// A PARTsolutions export: `;`-separated, a header line, the SKU (before any `_`) in the fourth column and the part's
/// URL in the fifth. Each SKU keeps the query of its first URL that has one.
fn parse_export(file: &str) -> Vec<(String, String)> {
    let mut out: Vec<(String, String)> = Vec::new();
    for line in file.split('\n').skip(1) {
        let cells = split_csv(line, ';');
        let sku = cells.get(3).map_or("", |c| c.trim()).split('_').next().unwrap_or("").to_string();
        let url = cells.get(4).map_or("", |c| c.trim());
        let query = url.split_once('?').map_or("", |(_, q)| q.split('#').next().unwrap_or(""));
        if !sku.is_empty() && !query.is_empty() && !out.iter().any(|(s, _)| *s == sku) {
            out.push((sku, query.to_string()));
        }
    }
    out
}

/// str_getcsv(): fields split on `delimiter`, double quotes around a field and doubled inside it.
fn split_csv(line: &str, delimiter: char) -> Vec<String> {
    let mut out = vec![String::new()];
    let mut quoted = false;
    let mut chars = line.trim_end_matches('\r').chars().peekable();
    while let Some(c) = chars.next() {
        match c {
            '"' if quoted && chars.peek() == Some(&'"') => {
                chars.next();
                if let Some(f) = out.last_mut() {
                    f.push('"');
                }
            }
            '"' => quoted = !quoted,
            c if c == delimiter && !quoted => out.push(String::new()),
            c => {
                if let Some(f) = out.last_mut() {
                    f.push(c);
                }
            }
        }
    }
    out
}

/// The link stored for a part: the four PARTsolutions keys of the query, decoded, in the query's order. `None`
/// unless all four have a value.
fn prj_link(query: &str) -> Option<String> {
    let mut kept: Vec<(String, String)> = Vec::new();
    for (k, v) in form_urlencoded::parse(query.as_bytes()) {
        match kept.iter_mut().find(|(key, _)| *key == k) {
            Some(slot) => slot.1 = v.into_owned(),
            None => kept.push((k.into_owned(), v.into_owned())),
        }
    }
    let parts: Vec<String> = kept.iter().filter(|(k, v)| ["prjCatalog", "prjNode", "prjName", "values"].contains(&k.as_str()) && !v.is_empty()).map(|(k, v)| format!("{k}={v}")).collect();
    (parts.len() == 4).then(|| parts.join("&"))
}

#[derive(Default)]
struct Outcome {
    changed: Vec<String>,
    not_changed: Vec<String>,
    missing: Vec<String>,
}

async fn import(pool: &SqlitePool, rows: &[(String, String)]) -> sqlx::Result<Outcome> {
    let mut out = Outcome::default();
    for (sku, query) in rows {
        let Some(prj) = prj_link(query) else { continue };
        let changed = sqlx::query!("UPDATE inventory SET prj = ?1 WHERE sku = ?2 AND prj IS NOT ?1", prj, sku).execute(pool).await?.rows_affected();
        if changed > 0 {
            out.changed.push(sku.clone());
            continue;
        }
        let exists = sqlx::query_scalar!(r#"SELECT 1 AS "x!: i64" FROM inventory WHERE sku = ?1"#, sku).fetch_optional(pool).await?.is_some();
        if exists {
            out.not_changed.push(sku.clone());
        } else if !out.missing.contains(sku) {
            out.missing.push(sku.clone());
        }
    }
    out.not_changed.sort();
    out.not_changed.dedup();
    Ok(out)
}

const SCRIPT: &str = r#"<script type="text/javascript">
function fn_action(action) {
	if (action=='update') {
	    if (!confirm("Update Inventory PRJ?")) {
            return;
	    }
    }
	document.scs_form.action.value=action;
	document.scs_form.submit();
}
</script>
"#;

fn file_row(name: &str) -> String {
    format!("<tr>\n<td width='30%'>File name:</td>\n<td width='70%'>{}</td>\n</tr>\n", text(name))
}

async fn run_import(pool: &SqlitePool, staff: &Staff<Administrator>, posted: &Posted) -> sqlx::Result<Response> {
    let f = &posted.fields;
    let title = "Inventory PRJ Import";
    let mut notes = Notes::new(title, "");
    let mut filename = f.get("filename").to_string();
    let action = f.get("action");
    let mut review: Option<Vec<(String, String)>> = None;
    let mut outcome = None;
    match action {
        "cancel" => notes.say("Request Cancelled"),
        "upload" => match posted.uploads.iter().find(|u| u.field == "prj" && !u.file_name.is_empty()) {
            None => notes.error("prj", Some("File required")),
            Some(u) => {
                filename = u.file_name.clone();
                let rows = parse_export(&String::from_utf8_lossy(&u.bytes));
                if rows.is_empty() {
                    notes.error("prj", Some("Empty or corrupt file"));
                } else {
                    notes.say("Review Selection");
                    review = Some(rows);
                }
            }
        },
        "update" => match serde_json::from_str::<Vec<(String, String)>>(f.get("prj_rows")) {
            Ok(rows) => outcome = Some(import(pool, &rows).await?),
            Err(_) => notes.say("Upload file not found"),
        },
        _ => {}
    }
    let form = Form { notes: &notes };
    let mut body = notes.render();
    body.push_str(SCRIPT);
    body.push_str(&open(IMPORT_PATH, staff.csrf.as_str(), true));
    body.push_str(&hidden("action", ""));
    body.push_str(&hidden("filename", &filename));
    if let Some(o) = outcome {
        body.push_str(&format!("<table class='standard noborder'>\n{}</table>\n<div class='accordion'>\n", file_row(&filename)));
        for (name, skus) in [("Changed", &o.changed), ("Not Changed", &o.not_changed), ("Missing/Invalid SKU", &o.missing)] {
            if !skus.is_empty() {
                let list: Vec<String> = skus.iter().map(|s| text(s)).collect();
                body.push_str(&format!("<h3>{name} ({})</h3>\n<div>{}</div>\n", number(skus.len() as i64), list.join("<br>")));
            }
        }
        body.push_str(&format!("</div>\n<p>{}</p>\n", button("Return", Opts::new().onclick("fn_action('');"))));
    } else if let Some(rows) = review {
        body.push_str(&hidden("prj_rows", &serde_json::to_string(&rows).unwrap_or_default()));
        body.push_str(&format!(
            "<table class='standard noborder'>\n{}<tr>\n<td>Unique records:</td>\n<td>{}</td>\n</tr>\n</table>\n",
            file_row(&filename),
            number(rows.len() as i64)
        ));
        let update = button("Update", Opts::new().onclick("fn_action('update');"));
        let cancel = button("Cancel", Opts::new().onclick("fn_action('cancel');"));
        body.push_str(&format!("<p>{update} {cancel}</p>\n"));
    } else {
        let error = notes.error_text("prj").map(|e| format!("<br>{e}")).unwrap_or_default();
        body.push_str(&format!(
            "<table class='standard noborder'>\n<tr>\n<td width='30%'>File name</td>\n<td width='70%'>{}{error}</td>\n</table>\n",
            form.file("prj", 40, Opts::new())
        ));
        body.push_str(&format!("<p>{}</p>\n", button("Upload", Opts::new().onclick("fn_action('upload');"))));
    }
    body.push_str(close());
    Ok(shell::page(pool, staff.view(), title, &body).await)
}

async fn show_import(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>) -> Response {
    let nothing = Posted { fields: Fields::default(), uploads: Vec::new() };
    run_import(&auth.pool, &staff, &nothing).await.unwrap_or_else(shell::internal)
}

async fn post_import(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, posted: Posted) -> Response {
    run_import(&auth.pool, &staff, &posted).await.unwrap_or_else(shell::internal)
}

pub fn routes() -> Router<Arc<Auth>> {
    Router::new().route(MISSING_PATH, get(show_missing)).route(IMPORT_PATH, get(show_import).post(post_import)).route(REVIEW_PATH, get(show_review))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn exports_give_each_sku_its_first_query() {
        let file = "Name;x;y;Part;URL\r\na;b;c;\"BLP-3_1\";https://example.com/p?prjCatalog=harcourt&prjNode=n&prjName=BLP-3&values={A=1}#top\n;;;BLP-3_2;https://example.com/p?other=1\n;;;NOURL;https://example.com/p\n";
        assert_eq!(parse_export(file), [("BLP-3".to_string(), "prjCatalog=harcourt&prjNode=n&prjName=BLP-3&values={A=1}".to_string())]);
        assert_eq!(split_csv("a;\"b;c\";\"d\"\"e\"", ';'), ["a", "b;c", "d\"e"]);
    }

    #[test]
    fn links_need_all_four_keys() {
        assert_eq!(prj_link("prjCatalog=h&x=1&prjNode=n&prjName=a%20b&values=%7BA%3D1%7D").as_deref(), Some("prjCatalog=h&prjNode=n&prjName=a b&values={A=1}"));
        assert_eq!(prj_link("prjCatalog=h&prjNode=n&prjName=&values=v"), None);
    }
}
