//! portalcontent.php: the Portal Content Manager. Each portal's images, headline HTML, links and hyperlinks, one
//! file (or URL) per language, the English one standing in for a language that has none.
//!
//! The PHP kept the record's per-language content in `$_SESSION['maintain_portalcontent']` between the form and
//! the save ("Refresh not allowed" without it). Here it lives in `staff_draft` with the staff session.

use std::{collections::BTreeMap, sync::Arc};

use axum::{
    extract::State,
    response::{IntoResponse, Response},
    routing::get,
    Router,
};
use base64::{
    engine::general_purpose::{STANDARD, STANDARD_NO_PAD},
    Engine,
};
use serde::{Deserialize, Serialize};
use serde_json::Value;
use sqlx::SqlitePool;

use super::{
    choices,
    csrf::{Posted, Unchecked},
    html::{button, close, hidden, href, js, list_options, open, text, Blank, Form, Notes, Opts, Tabs},
    inventory::{file_kind, languages},
    map, shell, time, Staff,
};
use crate::{
    api::{Administrator, Auth},
    db::unix_now,
};

const PATH: &str = "/portalcontent.php";
const TITLE: &str = "Portal Content Manager";
const VERSION: &str = "10/10/2025";
const FORMS: [&str; 6] = ["Portal Image", "Portal HTML", "Category Image", "Category HTML", "Link", "Hyperlink"];

fn field(parts: &[&str]) -> String {
    STANDARD_NO_PAD.encode(parts.join("\t"))
}

/// The record (portalcontent_data_class).
#[derive(Debug, Clone, Default)]
struct Record {
    id: i64,
    portal_id: i64,
    name: String,
    form: String,
    active: bool,
}

async fn read(pool: &SqlitePool, id: i64) -> sqlx::Result<Record> {
    let r = sqlx::query!(r#"SELECT id AS "id!: i64", portal_id, name, form, active FROM portalcontent WHERE id = ?1"#, id).fetch_optional(pool).await?;
    Ok(r.map_or_else(|| Record { active: true, form: "Link".into(), ..Record::default() }, |r| Record { id: r.id, portal_id: r.portal_id, name: r.name, form: r.form, active: r.active != 0 }))
}

/// One language's content.
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
struct Item {
    id: i64,
    name: String,
    filetype: String,
    revised: i64,
    file_name: String,
    mime: String,
    hyperlink: String,
    /// An uploaded file, base64; `None` keeps what is stored.
    upload: Option<String>,
}

impl Item {
    /// portalcontent_info(): what tells a language's file apart from the English one.
    fn info(&self) -> Option<(String, String, i64)> {
        (!self.file_name.is_empty()).then(|| (self.file_name.clone(), self.mime.clone(), self.revised))
    }
}

#[derive(Debug, Clone, Default, Serialize, Deserialize)]
struct Draft {
    items: BTreeMap<String, Item>,
}

impl Draft {
    async fn load(pool: &SqlitePool, staff: &Staff<Administrator>) -> sqlx::Result<Option<Draft>> {
        let hash = staff.session.as_slice();
        let body = sqlx::query_scalar!("SELECT body FROM staff_draft WHERE token_hash = ?1 AND page = ?2", hash, PATH).fetch_optional(pool).await?;
        Ok(body.and_then(|b| serde_json::from_slice(&b).ok()))
    }

    async fn save(&self, pool: &SqlitePool, staff: &Staff<Administrator>) -> sqlx::Result<()> {
        let body = serde_json::to_vec(self).unwrap_or_default();
        let (hash, now) = (staff.session.as_slice(), unix_now());
        sqlx::query!(
            "INSERT INTO staff_draft (token_hash, page, body, updated_at) VALUES (?1, ?2, ?3, ?4)
             ON CONFLICT (token_hash, page) DO UPDATE SET body = excluded.body, updated_at = excluded.updated_at",
            hash,
            PATH,
            body,
            now
        )
        .execute(pool)
        .await?;
        Ok(())
    }

    async fn clear(pool: &SqlitePool, staff: &Staff<Administrator>) -> sqlx::Result<()> {
        let hash = staff.session.as_slice();
        sqlx::query!("DELETE FROM staff_draft WHERE token_hash = ?1 AND page = ?2", hash, PATH).execute(pool).await?;
        Ok(())
    }

    /// "maintain": each language's row. A language whose file is the English one shows none of its own.
    async fn of(pool: &SqlitePool, record: &Record) -> sqlx::Result<Draft> {
        let rows = sqlx::query!(
            r#"SELECT id AS "id!: i64", language_code, name, filetype, meta, revised, html_content FROM content
               WHERE record_type = 'portalcontent' AND record_id = ?1 ORDER BY id"#,
            record.id
        )
        .fetch_all(pool)
        .await?;
        let mut d = Draft::default();
        let mut english = None;
        for r in rows {
            let meta: Value = r.meta.as_deref().and_then(|m| serde_json::from_str(m).ok()).unwrap_or_default();
            let meta_text = |k: &str| meta.get(k).and_then(Value::as_str).unwrap_or("").to_string();
            let mut item = Item {
                id: r.id,
                name: r.name,
                filetype: r.filetype,
                revised: r.revised,
                file_name: meta_text("name"),
                mime: meta_text("mime"),
                hyperlink: if record.form == "Hyperlink" { r.html_content.unwrap_or_default() } else { String::new() },
                upload: None,
            };
            if r.language_code == "EN" {
                english = Some(item.info());
            } else if english.as_ref() == Some(&item.info()) {
                item.file_name.clear();
            }
            d.items.insert(r.language_code, item);
        }
        Ok(d)
    }
}

/// input_update(): the posted names, files or URLs checked; saved when there is no error.
async fn update(pool: &SqlitePool, posted: &Posted, record: &mut Record, draft: &mut Draft, notes: &mut Notes) -> sqlx::Result<bool> {
    let f = &posted.fields;
    record.portal_id = f.int("portal_id");
    record.form = f.get("form").to_string();
    record.active = f.checked("active");
    for (code, _) in languages(pool).await? {
        let (name_field, file_field, delete_field, link_field) = (field(&["name", &code]), field(&["file", &code]), field(&["delete", &code]), field(&["hyperlink", &code]));
        let item = draft.items.entry(code.clone()).or_default();
        item.name = f.get(&name_field).trim().to_string();
        let upload = posted.uploads.iter().find(|u| u.field == file_field && !u.file_name.is_empty());
        if record.form == "Hyperlink" {
            item.hyperlink = f.get(&link_field).trim().to_string();
            if code == "EN" || !item.name.is_empty() {
                let ok = item.hyperlink.strip_prefix("https://").is_some_and(|rest| !rest.is_empty() && !rest.starts_with('/'));
                if item.hyperlink.is_empty() {
                    notes.error(&link_field, Some("Cannot be blank"));
                } else if !ok {
                    notes.error(&link_field, Some("Malformed URL"));
                }
                item.file_name.clear();
                item.mime.clear();
                if !item.hyperlink.is_empty() {
                    item.revised = unix_now();
                    item.filetype = "hyperlink".into();
                    item.file_name = "manual.html".into();
                    item.mime = "text/html".into();
                }
            }
        } else if f.checked(&delete_field) {
            item.file_name.clear();
            item.mime.clear();
            item.filetype.clear();
            item.upload = Some(String::new());
        } else if let Some(u) = upload {
            if u.bytes.is_empty() {
                notes.error(&file_field, Some("Empty file"));
            } else if u.bytes.len() > map::MAX_UPLOAD {
                notes.error(&file_field, Some("Error: 1"));
            } else {
                let name: String = u.file_name.chars().map(|c| if matches!(c, ' ' | '?' | '/' | '"') { '_' } else { c }).collect();
                let (mime, filetype) = file_kind(&name);
                item.file_name = name;
                item.mime = mime.into();
                item.filetype = filetype.into();
                item.revised = unix_now();
                item.upload = Some(STANDARD.encode(&u.bytes));
            }
        }
        let item = item.clone();
        if code == "EN" {
            record.name = item.name.clone();
            if record.name.is_empty() {
                notes.error(&name_field, None);
            }
            if item.file_name.is_empty() && !notes.is_error(&file_field) {
                notes.error(&file_field, Some("English file mandatory"));
            }
        } else if !item.file_name.is_empty() && item.name.is_empty() {
            notes.error(&name_field, None);
        }
        if record.form != "Hyperlink" && !notes.is_error(&file_field) && !item.file_name.is_empty() {
            let wrong = match record.form.as_str() {
                _ if item.filetype.is_empty() => Some("Invalid file type"),
                "Portal Image" | "Category Image" if item.filetype != "image" => Some("Banner files must be images"),
                "Portal HTML" | "Category HTML" if item.filetype != "html" => Some("Headline files must be HTML"),
                "Link" if !matches!(item.filetype.as_str(), "pdf" | "other") => Some("Links must be PDF or Other"),
                _ => None,
            };
            if let Some(w) = wrong {
                notes.error(&file_field, Some(w));
            }
        }
    }
    if notes.has_errors() {
        return Ok(false);
    }
    let mut tx = pool.begin().await?;
    let active = i64::from(record.active);
    let written = if record.id == 0 {
        sqlx::query_scalar!(r#"INSERT INTO portalcontent (portal_id, name, form, active) VALUES (?1, ?2, ?3, ?4) RETURNING id AS "id!: i64""#, record.portal_id, record.name, record.form, active)
            .fetch_one(&mut *tx)
            .await
            .map(Some)
    } else {
        sqlx::query!("UPDATE portalcontent SET portal_id = ?2, name = ?3, form = ?4, active = ?5 WHERE id = ?1", record.id, record.portal_id, record.name, record.form, active)
            .execute(&mut *tx)
            .await
            .map(|_| None)
    };
    match written {
        Ok(Some(id)) => record.id = id,
        Ok(None) => {}
        Err(sqlx::Error::Database(e)) => {
            notes.say(e.message());
            notes.error("name", None);
            return Ok(false);
        }
        Err(e) => return Err(e),
    }
    let english = draft.items.get("EN").cloned().unwrap_or_default();
    let now = unix_now();
    for (code, item) in &draft.items {
        if item.name.is_empty() {
            if item.id != 0 {
                sqlx::query!("DELETE FROM content WHERE id = ?1", item.id).execute(&mut *tx).await?;
            }
            continue;
        }
        let source = if code == "EN" || !item.file_name.is_empty() { item } else { &english };
        let meta = serde_json::json!({"name": source.file_name, "mime": source.mime, "width": 0, "height": 0, "type": 0, "dimensions": null, "bits": 0, "channels": 0}).to_string();
        let (blob, html, changed) = if record.form == "Hyperlink" {
            (None, Some(source.hyperlink.clone()), true)
        } else {
            match source.upload.as_deref() {
                Some(b64) => {
                    let bytes = STANDARD.decode(b64).unwrap_or_default();
                    if matches!(source.filetype.as_str(), "html" | "hyperlink") {
                        (None, Some(String::from_utf8_lossy(&bytes).to_string()), true)
                    } else {
                        (Some(bytes), Some(String::new()), true)
                    }
                }
                None => (None, None, source.id != item.id),
            }
        };
        let copy_from = if changed { 0 } else { source.id };
        sqlx::query!(
            "INSERT INTO content (record_type, record_id, record_item, language_code, name, description, filetype, revised, meta, content, html_content)
             VALUES ('portalcontent', ?1, '', ?2, ?3, '', ?4, ?5, ?6, ?7, COALESCE(?8, ''))
             ON CONFLICT (record_type, record_id, record_item, language_code) DO UPDATE SET
                 name = excluded.name, filetype = excluded.filetype, revised = excluded.revised, meta = excluded.meta,
                 content = CASE WHEN ?9 THEN excluded.content ELSE content END,
                 html_content = CASE WHEN ?9 THEN excluded.html_content ELSE html_content END",
            record.id,
            code,
            item.name,
            source.filetype,
            now,
            meta,
            blob,
            html,
            changed
        )
        .execute(&mut *tx)
        .await?;
        if copy_from != 0 && copy_from != item.id {
            sqlx::query!(
                "UPDATE content SET content = (SELECT content FROM content WHERE id = ?1), html_content = (SELECT html_content FROM content WHERE id = ?1)
                 WHERE record_type = 'portalcontent' AND record_id = ?2 AND language_code = ?3",
                copy_from,
                record.id,
                code
            )
            .execute(&mut *tx)
            .await?;
        }
    }
    tx.commit().await?;
    notes.say("Record maintained");
    Ok(true)
}

const SCRIPT: &str = r##"
<script>
jQuery(document).ready(function() {
	fn_form();
});

function fn_form() {
    if (jQuery("#form").val() == "Hyperlink") {
        jQuery(".file_tr").hide();
        jQuery(".url_tr").show();
      } else {
        jQuery(".file_tr").show();
        jQuery(".url_tr").hide();
    }
}

function fn_action(action, id, name) {
	if (action == 'delete') {
        jQuery("#delete" + id).prop("checked", false);
	    if (!confirm(name)) return;
	}
	jQuery('#action').val(action);
	jQuery('#record_id').val(id);
	jQuery('#report_portal_id').val(name);
	jQuery('#scs_form').submit();
}
</script>"##;

fn language_tab(form: &Form<'_>, record: &Record, item: &Item, code: &str, portals: &[(String, String)]) -> Vec<String> {
    let (name_field, file_field, delete_field, link_field) = (field(&["name", code]), field(&["file", code]), field(&["delete", code]), field(&["hyperlink", code]));
    let mut out = vec!["<table class='standard noborder'>".to_string(), hidden(&file_field, code)];
    if code == "EN" {
        out.extend(["<tr>".to_string(), "<td>Portal name</td>".into(), format!("<td>{}</td>", form.select("portal_id", portals, &record.portal_id.to_string(), Blank::Empty, Opts::new())), "</tr>".into()]);
        out.extend(["<tr>".to_string(), "<td>Used by</td>".into(), format!("<td>{}</td>", form.select("form", &list_options(&FORMS), &record.form, Blank::None, Opts::new().onchange("fn_form();"))), "</tr>".into()]);
    }
    out.extend(["<tr>".to_string(), "<td width='20%'>Name</td>".into(), format!("<td width='80%'>{}</td>", form.text(&name_field, &item.name, 60, 60, Opts::new())), "</tr>".into()]);
    out.push("<tr class=file_tr>".into());
    out.push(format!("<td>Upload file{}</td>", if code == "EN" { "" } else { "<br>(English content will be used by default)" }));
    let mut cell = format!("<td>{}", form.file(&file_field, 40, Opts::new()));
    if let Some(e) = form.notes.error_text(&file_field) {
        cell.push_str(&format!("<br>{e}"));
    }
    out.push(cell);
    out.push("</td>".into());
    out.push("</tr>".into());
    if !item.file_name.is_empty() {
        if code != "EN" {
            out.extend(["<tr class=file_tr>".to_string(), "<td>Delete?</td>".into(), format!("<td>{}</td>", form.checkbox(&delete_field, "1", false, Opts::new())), "</tr>".into()]);
        }
        out.extend(["<tr class=file_tr>".to_string(), "<td>File name</td>".into(), format!("<td>{}</td>", text(&item.file_name)), "</tr>".into()]);
        out.extend(["<tr>".to_string(), "<td>Mime</td>".into(), format!("<td>{}</td>", text(&item.mime)), "</tr>".into()]);
        out.extend(["<tr>".to_string(), "<td>Revised</td>".into(), format!("<td>{}</td>", time::date("m/d/Y h:i A", item.revised)), "</tr>".into()]);
    }
    let mut link = Vec::new();
    if let Some(e) = form.notes.error_text(&link_field) {
        link.push(e.to_string());
    }
    link.push(form.textarea(&link_field, &item.hyperlink, 3, 100, Opts::new()));
    out.extend(["<tr class=url_tr>".to_string(), "<td>Hyperlink</td>".into(), format!("<td>{}</td>", link.join("<br>")), "</tr>".into()]);
    if code == "EN" {
        out.extend(["<tr>".to_string(), "<td>Active?</td>".into(), format!("<td>{}</td>", form.checkbox("active", "1", record.active, Opts::new())), "</tr>".into()]);
    }
    out.push("</table>".into());
    out
}

async fn list(pool: &SqlitePool, form: &Form<'_>, staff: &Staff<Administrator>) -> sqlx::Result<String> {
    let last_login = sqlx::query_scalar!(r#"SELECT last_login FROM "user" WHERE id = ?1"#, staff.who.user.id.0).fetch_optional(pool).await?.unwrap_or(0);
    let mut portals = sqlx::query!(r#"SELECT id AS "id!: i64", name FROM portal ORDER BY id"#).fetch_all(pool).await?;
    super::filesort::sort(&mut portals, |a, b| super::filesort::ci(&a.name, &b.name));
    let mut items = sqlx::query!(r#"SELECT id AS "id!: i64", portal_id, name, form, active FROM portalcontent ORDER BY id"#).fetch_all(pool).await?;
    super::filesort::sort(&mut items, |a, b| a.portal_id.cmp(&b.portal_id).then_with(|| super::filesort::ci(&a.name, &b.name)));
    let content = sqlx::query!(r#"SELECT id AS "id!: i64", record_id, language_code FROM content WHERE record_type = 'portalcontent' ORDER BY id"#).fetch_all(pool).await?;
    let mut out = String::from("<div id='jquery_accordian' class='accordion'>");
    for p in portals {
        let mine: Vec<_> = items.iter().filter(|i| i.portal_id == p.id).collect();
        out.push_str(&format!("<h3>{} ({})</h3><div><table class='large border'>", text(&p.name), mine.len()));
        out.push_str("<tr><th>Name</th><th>Form</th><th>View</th><th>Download</th><th>Del?</th></tr>");
        out.push_str(&format!("<tr><td colspan=5>{}</td></tr>", href("Add new item", &format!("javascript:fn_action('maintain','0',{});", js(&p.id.to_string())), Opts::new())));
        for item in mine {
            let bg = if item.active == 0 { "style='background-color: #ffff00;'" } else { "" };
            out.push_str(&format!("<tr {bg}>"));
            out.push_str(&format!("<td>{}</td>", href(&text(&item.name), &format!("javascript:fn_action('maintain',{},'');", js(&item.id.to_string())), Opts::new())));
            out.push_str(&format!("<td>{}</td>", text(&item.form)));
            let mut languages: Vec<(&str, i64)> = Vec::new();
            for c in content.iter().filter(|c| c.record_id == item.id) {
                match languages.iter_mut().find(|(l, _)| *l == c.language_code) {
                    Some(slot) => slot.1 = c.id,
                    None => languages.push((&c.language_code, c.id)),
                }
            }
            let link = |kind: &str, code: &str, id: i64| -> String {
                let key = super::dashboard::content_key(id, last_login);
                let opts = if kind == "viewer" { Opts::new().set("target", "document_viewer").set("title", "View ") } else { Opts::new().set("title", "Download ") };
                href(code, &format!("/content_viewer.php?{kind}={key}"), opts)
            };
            out.push_str(&format!("<td>{}</td>", languages.iter().map(|(code, id)| link("viewer", code, *id)).collect::<Vec<_>>().join(" ")));
            out.push_str(&format!("<td>{}</td>", languages.iter().map(|(code, id)| link("download", code, *id)).collect::<Vec<_>>().join(" ")));
            let del = if item.active != 0 { "&nbsp;".to_string() } else { form.checkbox_delete(item.id, &item.name, "fn_action") };
            out.push_str(&format!("<td class=center>{del}</td></tr>"));
        }
        out.push_str("</table></div>");
    }
    out.push_str("</div>");
    Ok(out)
}

async fn page(pool: &SqlitePool, staff: &Staff<Administrator>, posted: Option<&Posted>) -> sqlx::Result<Response> {
    let f = posted.map(|p| p.fields.clone()).unwrap_or_default();
    let mut notes = Notes::new(TITLE, VERSION);
    let record_id = f.int("record_id");
    let mut record = read(pool, record_id).await?;
    let mut draft = None;
    match f.get("action") {
        "cancel" => {
            notes.say("Request cancelled");
            Draft::clear(pool, staff).await?;
        }
        "delete" => {
            let mut tx = pool.begin().await?;
            sqlx::query!("DELETE FROM portalcontent WHERE id = ?1", record_id).execute(&mut *tx).await?;
            sqlx::query!("DELETE FROM content WHERE record_type = 'portalcontent' AND record_id = ?1", record_id).execute(&mut *tx).await?;
            tx.commit().await?;
            notes.say(&format!("Portal content {} deleted", record.name));
        }
        "maintain" => {
            if record_id == 0 {
                record.portal_id = f.int("report_portal_id");
            }
            let d = Draft::of(pool, &record).await?;
            d.save(pool, staff).await?;
            draft = Some(d);
        }
        "update" => match (Draft::load(pool, staff).await?, posted) {
            (Some(mut d), Some(p)) => {
                if update(pool, p, &mut record, &mut d, &mut notes).await? {
                    Draft::clear(pool, staff).await?;
                } else {
                    d.save(pool, staff).await?;
                    draft = Some(d);
                }
            }
            _ => notes.say("Refresh not allowed"),
        },
        _ => {}
    }
    let form = Form { notes: &notes };
    let mut body = notes.render();
    body.push_str(SCRIPT);
    body.push_str(&open(PATH, staff.csrf.as_str(), true));
    body.push_str(&hidden("action", ""));
    body.push_str(&hidden("record_id", ""));
    body.push_str(&hidden("report_portal_id", ""));
    match draft {
        Some(d) => {
            let portals = choices::portals(pool).await?;
            let mut tabs = Tabs::new();
            tabs.in_wordpress(staff.wp);
            for (code, name) in languages(pool).await? {
                let item = d.items.get(&code).cloned().unwrap_or_default();
                tabs.lines(&name, &language_tab(&form, &record, &item, &code, &portals));
            }
            body.push_str(&tabs.render());
            let update = button("Update", Opts::new().onclick(format!("fn_action('update',{},'');", js(f.get("record_id")))));
            let cancel = button("Cancel", Opts::new().onclick("fn_action('cancel','','');"));
            body.push_str(&format!("<p>{update} {cancel}</p>"));
        }
        None => body.push_str(&list(pool, &form, staff).await?),
    }
    body.push_str(close());
    Ok(shell::page(pool, staff.view(), TITLE, &body).await)
}

async fn show(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>) -> Response {
    page(&auth.pool, &staff, None).await.unwrap_or_else(shell::internal)
}

async fn post(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, form: Unchecked) -> Response {
    match form.accept_by_action() {
        Ok(posted) => page(&auth.pool, &staff, Some(&posted)).await.unwrap_or_else(shell::internal),
        Err(rejected) => rejected.into_response(),
    }
}

pub fn routes() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(show).post(post))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_language_without_its_own_file_shows_none() {
        let english = Item { file_name: "a.png".into(), mime: "image/png".into(), revised: 5, ..Item::default() };
        let french = english.clone();
        assert_eq!(english.info(), french.info());
        assert_eq!(Item::default().info(), None);
    }
}
