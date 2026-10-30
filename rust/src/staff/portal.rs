//! portal.php: the Customer Portal Manager. Portals with a headline per language (`content` rows of type
//! `portal`) and the catalog subcategories each one shows (`subcategory.portal`, a `^id^id^` list).
//!
//! As in the PHP, the page prints no message line, delete does nothing, and the list's row ends come out before
//! the table.

use std::sync::Arc;

use axum::{extract::State, response::Response, routing::get, Router};
use base64::{engine::general_purpose::STANDARD_NO_PAD, Engine};
use sqlx::SqlitePool;

use super::{
    check::posted_text,
    csrf::{php_intval, Fields, Posted},
    html::{button, close, hidden, href, js, open, text, Form, Notes, Opts, Tabs},
    inventory::languages,
    shell, Staff,
};
use crate::api::{Administrator, Auth};

const PATH: &str = "/portal.php";
const TITLE: &str = "Customer Portal Manager";

/// fn_base64(): the field name of tab-joined parts.
fn field(parts: &[&str]) -> String {
    STANDARD_NO_PAD.encode(parts.join("\t"))
}

/// A posted field name read back into its parts, when it is one.
fn parts(name: &str) -> Option<(String, String)> {
    let bytes = STANDARD_NO_PAD.decode(name.trim_end_matches('=')).ok()?;
    let text = String::from_utf8(bytes).ok()?;
    text.split_once('\t').map(|(a, b)| (a.to_string(), b.to_string()))
}

/// The portal being edited: its name and active flag, its headline per language and its subcategories.
#[derive(Default)]
struct Edit {
    id: i64,
    name: String,
    active: bool,
    descriptions: Vec<(String, String)>,
    subcategories: Vec<i64>,
}

impl Edit {
    fn description(&self, code: &str) -> &str {
        self.descriptions.iter().find(|(c, _)| c == code).map_or("", |(_, d)| d.as_str())
    }
}

async fn load(pool: &SqlitePool, id: i64) -> sqlx::Result<Edit> {
    let portal = sqlx::query!(r#"SELECT name, active FROM portal WHERE id = ?1"#, id).fetch_optional(pool).await?;
    let mut e = Edit { id, active: portal.as_ref().is_some_and(|p| p.active.unwrap_or(0) != 0), name: portal.map(|p| p.name).unwrap_or_default(), ..Edit::default() };
    let rows = sqlx::query!(r#"SELECT language_code, description FROM content WHERE record_type = 'portal' AND record_id = ?1 ORDER BY id"#, id).fetch_all(pool).await?;
    for r in rows {
        let d = r.description.unwrap_or_default();
        match e.descriptions.iter_mut().find(|(c, _)| *c == r.language_code) {
            Some(slot) => slot.1 = d,
            None => e.descriptions.push((r.language_code, d)),
        }
    }
    let pattern = format!("%^{id}^%");
    e.subcategories = sqlx::query_scalar!(r#"SELECT id AS "id!: i64" FROM subcategory WHERE ?1 = 0 OR portal LIKE ?2"#, id, pattern).fetch_all(pool).await?;
    Ok(e)
}

async fn save(pool: &SqlitePool, e: &Edit) -> sqlx::Result<()> {
    let mut tx = pool.begin().await?;
    let active = i64::from(e.active);
    let id = if e.id == 0 {
        sqlx::query_scalar!(r#"INSERT INTO portal (name, active) VALUES (?1, ?2) RETURNING id AS "id!: i64""#, e.name, active).fetch_one(&mut *tx).await?
    } else {
        sqlx::query!("UPDATE portal SET name = ?2, active = ?3 WHERE id = ?1", e.id, e.name, active).execute(&mut *tx).await?;
        e.id
    };
    let now = crate::db::unix_now();
    for (code, description) in &e.descriptions {
        let existing = sqlx::query_scalar!(r#"SELECT id AS "id!: i64" FROM content WHERE record_type = 'portal' AND record_id = ?1 AND language_code = ?2"#, id, code)
            .fetch_optional(&mut *tx)
            .await?;
        match (code == "EN" || !description.is_empty(), existing) {
            (true, Some(cid)) => {
                sqlx::query!("UPDATE content SET name = ?2, description = ?3, revised = ?4 WHERE id = ?1", cid, e.name, description, now).execute(&mut *tx).await?;
            }
            (true, None) => {
                sqlx::query!(
                    "INSERT INTO content (record_type, record_id, language_code, name, description, revised) VALUES ('portal', ?1, ?2, ?3, ?4, ?5)",
                    id,
                    code,
                    e.name,
                    description,
                    now
                )
                .execute(&mut *tx)
                .await?;
            }
            (false, Some(cid)) => {
                sqlx::query!("DELETE FROM content WHERE id = ?1", cid).execute(&mut *tx).await?;
            }
            (false, None) => {}
        }
    }
    let (mark, pattern, append) = (format!("^{id}^"), format!("%^{id}^%"), format!("{id}^"));
    sqlx::query!("UPDATE subcategory SET portal = replace(portal, ?1, '^') WHERE portal LIKE ?2", mark, pattern).execute(&mut *tx).await?;
    for s in &e.subcategories {
        sqlx::query!("UPDATE subcategory SET portal = portal || ?1 WHERE id = ?2", append, s).execute(&mut *tx).await?;
    }
    tx.commit().await
}

fn language_tab(form: &Form<'_>, e: &Edit, code: &str) -> Vec<String> {
    let mut out = vec!["<table class='standard noborder'>".to_string()];
    if code == "EN" {
        let mut name = form.text("name", &e.name, 60, 0, Opts::new());
        if let Some(t) = form.notes.error_text("name") {
            name.push(' ');
            name.push_str(t);
        }
        out.extend(["<tr>".to_string(), "<td width='20%'>Portal name</td>".into(), format!("<td width='80%'>{name}</td>"), "</tr>".into()]);
    }
    let area = form.textarea(&field(&["description", code]), e.description(code), 4, 60, Opts::new());
    out.extend(["<tr>".to_string(), "<td width='20%'>Headline</td>".into(), format!("<td width='80%'>{area}</td>"), "</tr>".into()]);
    if code == "EN" {
        out.extend(["<tr>".to_string(), "<tr>".into(), "<td>Active?</td>".into(), format!("<td>{}</td>", form.checkbox("active", "1", e.active, Opts::new())), "</tr>".into()]);
    }
    out.push("</table>".into());
    out
}

/// A category of the subcategory tab: id, name and its (id, name) subcategories.
type Group = (i64, String, Vec<(i64, String)>);

async fn subcategory_tab(pool: &SqlitePool, form: &Form<'_>, e: &Edit) -> sqlx::Result<Vec<String>> {
    let rows = sqlx::query!(
        r#"SELECT s.id AS "id!: i64", s.name, c.id AS "category_id!: i64", c.name AS "category!: String"
           FROM subcategory s LEFT JOIN category c ON c.id = s.category_id
           WHERE c.active = 1 AND s.status <> 'Inactive' AND c.name IS NOT NULL
           ORDER BY c.sort_order, c.name COLLATE NOCASE, s.sort_order, s.name COLLATE NOCASE"#
    )
    .fetch_all(pool)
    .await?;
    let mut groups: Vec<Group> = Vec::new();
    for r in rows {
        let item = (r.id, r.name.unwrap_or_default());
        match groups.iter_mut().find(|(id, _, _)| *id == r.category_id) {
            Some((_, _, items)) => items.push(item),
            None => groups.push((r.category_id, r.category, vec![item])),
        }
    }
    let mut out = vec![hidden("subcategory_error", ""), "<table class='standard border'>".into()];
    out.push(format!("<tr><th style='text-align: left;'>{} All subcategories</th></tr>", form.checkbox("all", "1", false, Opts::new().onclick("fn_subcategory(this);"))));
    out.push("<tr><td>&nbsp;</td></tr>".into());
    for (category_id, name, items) in groups {
        let all = items.iter().all(|(id, _)| e.subcategories.contains(id));
        let group = form.checkbox(&format!("category_{category_id}"), "1", all, Opts::new().onclick("fn_subcategory(this);").class("subcategory"));
        out.push(format!("<tr><th style='text-align: left;'>{group}{}</th></tr>", text(&name)));
        let ticks: Vec<String> = items
            .iter()
            .map(|(id, label)| {
                let opts = Opts::new().onclick("fn_subcategory(this);").class(format!("subcategory category_{category_id}"));
                format!("{} {}", form.checkbox(&field(&["subcategory", &id.to_string()]), "1", e.subcategories.contains(id), opts), text(label))
            })
            .collect();
        out.push(format!("<tr><td>{}</td></tr>", ticks.join("<br>")));
    }
    out.push("</table>".into());
    Ok(out)
}

async fn list(pool: &SqlitePool, form: &Form<'_>) -> sqlx::Result<String> {
    let rows = sqlx::query!(r#"SELECT id AS "id!: i64", name, active FROM portal ORDER BY name COLLATE NOCASE"#).fetch_all(pool).await?;
    let mut table = String::from("<table class='standard border tablesorter'><thead><tr><th width='90%'>Name</th><th width='10%'>Del?</th></tr></thead><tbody>");
    table.push_str(&format!("<tr><td colspan=2>{}</td></tr>", href("Add New", "javascript:fn_action('maintain','0','0');", Opts::new())));
    let mut ends = String::new();
    for r in &rows {
        let del = if r.active.unwrap_or(0) != 0 { "&nbsp;".to_string() } else { form.checkbox_delete(r.id, &r.name, "fn_action") };
        table.push_str(&format!("<tr><td>{}</td><td class=center>{del}</td>", href(&text(&r.name), &format!("javascript:fn_action('maintain',{},'');", js(&r.id.to_string())), Opts::new())));
        ends.push_str("</tr>");
    }
    table.push_str("</tbody></table>");
    Ok(ends + &table)
}

const SCRIPT: &str = r##"<script>
function fn_action(action, record_id, name) {
    if (action=='delete') {
        jQuery("#delete" + record_id).prop("checked", false);
        if (!confirm("Delete: #" + record_id + " " + name)) return;
    }
    jQuery("#action").val(action);
    jQuery("#record_id").val(record_id);
    jQuery("#scs_form").submit();
}
function fn_subcategory(obj) {
    field=obj.id.split("_");
    switch (field[0]) {
        case "all":
        $('input.subcategory').prop('checked',obj.checked);
        break;
        case "category":
        $('input.' + obj.id).prop('checked',obj.checked);
        break;
        case "subcategory":
// reserved
        break;
    }
}
</script>
"##;

async fn run(pool: &SqlitePool, staff: &Staff<Administrator>, f: &Fields) -> sqlx::Result<Response> {
    let mut notes = Notes::new(TITLE, "");
    let action = f.get("action");
    let record_id = f.get("record_id");
    let id = php_intval(record_id);
    let mut edit = None;
    match action {
        "maintain" => edit = Some(load(pool, id).await?),
        "update" => {
            let mut e = Edit { id, name: posted_text(f.get("name")), active: f.int("active") != 0, ..Edit::default() };
            if e.name.is_empty() {
                notes.error("name", Some("Cannot be blank"));
            }
            for (k, v) in f.iter() {
                match parts(k) {
                    Some((kind, code)) if kind == "description" => e.descriptions.push((code, posted_text(v))),
                    Some((kind, sub)) if kind == "subcategory" => e.subcategories.push(php_intval(&sub)),
                    _ => {}
                }
            }
            if !notes.has_errors() {
                let taken = sqlx::query_scalar!(r#"SELECT 1 AS "x!: i64" FROM portal WHERE lower(name) = lower(?1) AND id <> ?2"#, e.name, e.id).fetch_optional(pool).await?;
                if taken.is_some() {
                    notes.error("name", Some("Duplicate name"));
                } else {
                    save(pool, &e).await?;
                }
            }
            if notes.has_errors() {
                edit = Some(e);
            }
        }
        _ => {}
    }
    let form = Form { notes: &notes };
    let mut body = SCRIPT.to_string();
    body.push_str(&open(PATH, staff.csrf.as_str(), true));
    body.push_str(&hidden("action", ""));
    body.push_str(&hidden("record_id", ""));
    match edit {
        Some(e) => {
            let mut tabs = Tabs::named("standard");
            tabs.in_wordpress(staff.wp);
            for (code, name) in languages(pool).await? {
                tabs.lines(&name, &language_tab(&form, &e, &code));
            }
            tabs.lines("Subcategory", &subcategory_tab(pool, &form, &e).await?);
            body.push_str(&tabs.render());
            let update = button("Update", Opts::new().onclick(format!("fn_action('update',{},'');", js(record_id))));
            let cancel = button("Cancel", Opts::new().onclick("fn_action('cancel','','');"));
            body.push_str(&format!("<p>{update} {cancel}</p>"));
        }
        None => body.push_str(&list(pool, &form).await?),
    }
    body.push_str(close());
    Ok(shell::page(pool, staff.view(), TITLE, &body).await)
}

async fn show(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>) -> Response {
    run(&auth.pool, &staff, &Fields::default()).await.unwrap_or_else(shell::internal)
}

async fn post(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, posted: Posted) -> Response {
    run(&auth.pool, &staff, &posted.fields).await.unwrap_or_else(shell::internal)
}

pub fn routes() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(show).post(post))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn field_names_read_back_into_their_parts() {
        assert_eq!(field(&["description", "EN"]), "ZGVzY3JpcHRpb24JRU4");
        assert_eq!(parts("ZGVzY3JpcHRpb24JRU4"), Some(("description".into(), "EN".into())));
        assert_eq!(parts("action"), None);
    }
}
