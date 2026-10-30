//! campaign_manager.php: the Campaign Manager. Campaigns (a code visitors arrive with, dates, type, divs, the
//! subcategories shown, extra CSS and JavaScript) and their content documents, each Public, Semi-Private or Private.
//!
//! The PHP kept the content being edited in `$_SESSION['campaign_content']` from "maintain" to the save, and the
//! page's AJAX "delete" removed rows from it. Here it lives in `staff_draft` with the staff session. The AJAX calls
//! need an Administrator like the page; the PHP answered them before checking.

use std::sync::Arc;

use axum::{
    extract::{FromRequest, RawQuery, Request, State},
    http::{HeaderMap, StatusCode},
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
    csrf::{Fields, Posted},
    html::{button, close, hidden, href, js, list_options, open, text, Blank, Form, Notes, Opts, Tabs},
    map,
    phpser::{self, Php},
    shell, time, Staff,
};
use crate::{
    api::{Administrator, Auth},
    db::unix_now,
};

const PATH: &str = "/campaign_manager.php";
const TITLE: &str = "Campaign Manager";
const ACCESS: [&str; 3] = ["Public", "Semi-Private", "Private"];
const TYPES: [&str; 3] = ["Invite", "Public", "Remote"];
const DIVS: [(&str, &str); 7] = [
    ("content", "Campaign Content"),
    ("form", "Forms"),
    ("form_content", "Forms Content"),
    ("form_title", "Forms Title"),
    ("form_disclaimer", "Forms Disclaimer"),
    ("email_welcome", "Email: Welcome"),
    ("email_disclaimer", "Email: Disclaimer"),
];

fn field(parts: &[&str]) -> String {
    STANDARD_NO_PAD.encode(parts.join("\t"))
}

/// campaign_data_class.
#[derive(Debug, Clone, Default)]
struct Campaign {
    id: i64,
    code: String,
    name: String,
    description: String,
    /// `Y/m/d`, or empty.
    from_date: String,
    thru_date: String,
    kind: String,
    sf_campaign_id: String,
    sort_order: i64,
    active: bool,
    title_omit: bool,
    divs: Vec<(String, String)>,
    subcategory: Vec<String>,
    css: Vec<String>,
    js: Vec<String>,
}

fn stored_date(s: Option<String>) -> String {
    match s.as_deref() {
        None | Some("" | "0" | "0000-00-00") => String::new(),
        Some(d) => d.replace('-', "/"),
    }
}

fn caret(s: Option<String>) -> Vec<String> {
    s.filter(|s| !s.is_empty()).map(|s| s.split('^').map(str::to_string).collect()).unwrap_or_default()
}

async fn read(pool: &SqlitePool, id: i64) -> sqlx::Result<Option<Campaign>> {
    let Some(r) = sqlx::query!(
        r#"SELECT id AS "id!: i64", code, name, description, from_date, thru_date, "type" AS kind, sf_campaign_id, sort_order, active, title_omit, divs,
                  subcategory, css, js FROM campaign WHERE id = ?1"#,
        id
    )
    .fetch_optional(pool)
    .await?
    else {
        return Ok(None);
    };
    Ok(Some(Campaign {
        id: r.id,
        code: r.code.unwrap_or_default(),
        name: r.name.unwrap_or_default(),
        description: r.description.unwrap_or_default(),
        from_date: stored_date(r.from_date),
        thru_date: stored_date(r.thru_date),
        kind: r.kind.unwrap_or_default(),
        sf_campaign_id: r.sf_campaign_id.unwrap_or_default(),
        sort_order: r.sort_order.unwrap_or(0),
        active: r.active.unwrap_or(0) != 0,
        title_omit: r.title_omit.unwrap_or(0) != 0,
        divs: r.divs.as_deref().and_then(phpser::parse).map(|d| d.entries().iter().map(|(k, v)| (k.text(), v.text())).collect()).unwrap_or_default(),
        subcategory: caret(r.subcategory),
        css: caret(r.css),
        js: caret(r.js),
    }))
}

/// campaign_content_class: one content document being edited. New ones have negative ids until saved.
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
struct Content {
    id: i64,
    name: String,
    description: String,
    access: String,
    revised: i64,
    file_name: String,
    mime: String,
    /// An uploaded replacement, base64.
    upload: Option<String>,
    #[serde(skip)]
    errors: Vec<(String, String)>,
}

#[derive(Debug, Clone, Default, Serialize, Deserialize)]
struct Draft {
    content: Vec<Content>,
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

    /// "maintain": the campaign's documents, public first, then semi-private, then the rest, by item.
    async fn of(pool: &SqlitePool, id: i64) -> sqlx::Result<Draft> {
        let mut rows = sqlx::query!(
            r#"SELECT id AS "id!: i64", record_item, name, description, meta, revised FROM content WHERE record_type = 'campaign' AND record_id = ?1 ORDER BY id"#,
            id
        )
        .fetch_all(pool)
        .await?;
        let rank = |item: &str| {
            let lower = item.to_lowercase();
            if lower.starts_with("public") {
                1
            } else if lower.starts_with("semi-private") {
                2
            } else {
                3
            }
        };
        super::filesort::sort(&mut rows, |a, b| rank(&a.record_item).cmp(&rank(&b.record_item)).then_with(|| super::filesort::ci(&a.record_item, &b.record_item)));
        let mut content = Vec::new();
        for r in rows {
            let parts: Vec<&str> = r.record_item.split(':').collect();
            let ok = parts.len() == 2 && ACCESS.contains(&parts[0]) && parts[1].ends_with(|c: char| c.is_ascii_digit() || c == '(' || c == ')');
            if !ok {
                continue;
            }
            let meta: Value = r.meta.as_deref().and_then(|m| serde_json::from_str(m).ok()).unwrap_or_default();
            let meta_text = |k: &str| meta.get(k).and_then(Value::as_str).unwrap_or("").to_string();
            content.push(Content {
                id: r.id,
                name: r.name,
                description: r.description.unwrap_or_default(),
                access: parts[0].to_string(),
                revised: r.revised,
                file_name: meta_text("name"),
                mime: meta_text("mime"),
                upload: None,
                errors: Vec::new(),
            });
        }
        Ok(Draft { content })
    }
}

/// campaign_row(): one document's fold of the accordion.
fn row_html(form: &Form<'_>, row: i64, c: &Content) -> String {
    let r = row.to_string();
    let mut label = if c.name.is_empty() { vec!["Empty: New Content".to_string()] } else { vec![format!("{}:", text(&c.access)), text(&c.name)] };
    if !c.errors.is_empty() {
        label.push(format!("({} Error(s) Detected)", c.errors.len()));
    }
    let mut out = vec![format!("<div id=content_div{r}>"), format!("<h3 id=content_label{r}>{}</h3>", label.join(" ")), "<div>".into(), "<table class='standard noborder'>".into()];
    if c.id != 0 {
        out.push(format!("<tr><td>Document ID</td><td>{}</td></tr>", c.id));
    }
    let del = form.checkbox(&format!("content_delete{r}"), "1", false, Opts::new().onclick(format!("content_row_del(this,{},{});", js(&r), js(&c.id.to_string()))));
    out.push(format!("<tr><td width='30%'>Delete?</td><td width='70%'>{del}</td></tr>"));
    let title = form.text(&format!("content_name{r}"), &c.name, 20, 30, Opts::new().set("onfocusout", format!("content_label({});", js(&r))));
    out.push(format!("<tr><td width='30%'>Title</td><td width='70%'>{title}</td></tr>"));
    let mut description = vec![form.textarea(&format!("content_description{r}"), &c.description, 3, 60, Opts::new())];
    if let Some((_, e)) = c.errors.iter().find(|(k, _)| *k == format!("content_description{r}")) {
        description.push(text(e));
    }
    out.push(format!("<tr><td>Description</td><td>{}</td></tr>", description.join("<br>")));
    let access = form.select(&format!("content_access{r}"), &list_options(&ACCESS), &c.access, Blank::None, Opts::new().set("onfocusout", format!("content_label({});", js(&r))));
    out.push(format!("<tr><td>Access</td><td>{access}</td></tr>"));
    let mut file = vec![form.file(&format!("content_file{r}"), 40, Opts::new())];
    if let Some(e) = form.notes.error_text(&format!("content_file{r}")) {
        file.push(e.to_string());
    }
    if !c.file_name.is_empty() {
        file.push(format!("Name: {}", text(&c.file_name)));
    }
    if c.revised != 0 {
        file.push(format!("Revised: {}", time::date("m/d/Y h:i A", c.revised)));
    }
    out.push(format!("<tr><td>{}</td><td>{}</td></tr>", if c.file_name.is_empty() { "Upload File" } else { "Replace File" }, file.join("<br>")));
    out.push("</table>".into());
    out.push(hidden(&format!("content_content_id{r}"), &c.id.to_string()));
    out.push(format!("</div> <!-- end row {r} -->"));
    out.push(format!("</div> <!-- end group {r} -->"));
    out.concat()
}

fn php_json(pairs: &[(&str, Value)]) -> String {
    let body: Vec<String> = pairs.iter().map(|(k, v)| format!("{}:{v}", Value::from(*k))).collect();
    crate::orders::shop::cart::php_json(&format!("{{{}}}", body.join(",")))
}

async fn ajax(pool: &SqlitePool, staff: &Staff<Administrator>, f: &Fields) -> sqlx::Result<Response> {
    let body = match f.get("ajax_action") {
        "add" => {
            let notes = Notes::new(TITLE, "");
            let form = Form { notes: &notes };
            let row = f.get("ajax_row");
            php_json(&[("row", Value::from(row_html(&form, super::csrf::php_intval(row), &Content::default()))), ("status", Value::from(format!("Add row {row}")))])
        }
        "delete" => match Draft::load(pool, staff).await? {
            Some(mut d) => {
                let id = super::csrf::php_intval(f.get("ajax_content_id"));
                d.content.retain(|c| c.id != id);
                d.save(pool, staff).await?;
                php_json(&[("status", Value::from(format!("Delete content id {}", f.get("ajax_content_id"))))])
            }
            None => "[]".into(),
        },
        other => php_json(&[("status", Value::from(format!("Unknown action: {other}")))]),
    };
    Ok(shell::html_response(StatusCode::OK, body))
}

/// fn_date($text, "mdy"): `Y/m/d`, "" for a blank, or `None` when it is not a date.
fn mdy(s: &str) -> Option<String> {
    let s = s.trim();
    if s.is_empty() || s == "0" {
        return Some(String::new());
    }
    let parts: Vec<i64> = s.split([' ', '/', '.', '-']).map(super::csrf::php_intval).collect();
    let (m, d, mut y) = (parts.first().copied().unwrap_or(0), parts.get(1).copied().unwrap_or(0), parts.get(2).copied().unwrap_or(0));
    y += match y {
        0..=49 => 2000,
        50..=99 => 1900,
        _ => 0,
    };
    let ok = (1800..=2200).contains(&y) && chrono::NaiveDate::from_ymd_opt(y as i32, m as u32, d as u32).is_some();
    ok.then(|| format!("{y:04}/{m:02}/{d:02}"))
}

/// verify_file($file, $type, TRUE) for one line of the CSS or JavaScript list. The PHP's type test was a character
/// class (`/.[.css]$/`): the name had to end in one of those characters.
fn site_file(path: &str, kind: &str) -> Option<String> {
    let last = path.chars().last().unwrap_or(' ').to_ascii_lowercase();
    if !format!(".{kind}").contains(last) || path.chars().count() < 2 {
        return Some(format!("{} not type {kind}", text(path)));
    }
    if !path.starts_with('/') {
        return Some(format!("{} must begin with /", text(path)));
    }
    let present = std::env::var_os("HXWEB_DOCROOT").map(std::path::PathBuf::from).is_none_or(|root| !path.contains("..") && root.join(path.trim_start_matches('/')).is_file());
    (!present).then(|| format!("{} not found", text(path)))
}

/// The update: the posted campaign and documents checked, saved when there is no error.
async fn update(pool: &SqlitePool, posted: &Posted, mut c: Campaign, draft: &mut Draft, notes: &mut Notes) -> sqlx::Result<Option<Campaign>> {
    let f = &posted.fields;
    c.code = f.get("code").trim().to_uppercase();
    if c.code.is_empty() {
        notes.error("code", None);
    }
    c.name = f.get("name").trim().to_string();
    if c.name.is_empty() {
        notes.error("name", None);
    }
    c.description = f.get("description").trim().to_string();
    match mdy(f.get("from_date")) {
        Some(d) => c.from_date = d,
        None => notes.error("from_date", None),
    }
    match mdy(f.get("thru_date")) {
        Some(d) if d.is_empty() || c.from_date <= d => c.thru_date = d,
        _ => notes.error("thru_date", None),
    }
    c.kind = f.get("type").to_string();
    c.sf_campaign_id = f.get("sf_campaign_id").to_string();
    c.sort_order = f.int("sort_order");
    c.active = f.checked("active");
    c.title_omit = f.checked("title_omit");
    c.divs = DIVS.iter().filter_map(|(k, _)| Some((k.to_string(), f.get(&field(&["divs", k])).trim().to_string())).filter(|(_, v)| !v.is_empty())).collect();
    for (key, kind) in [("css", "css"), ("js", "js")] {
        let mut list: Vec<String> = Vec::new();
        let mut errors = Vec::new();
        for item in f.get(key).split('\n').map(str::trim).filter(|i| !i.is_empty()) {
            if list.iter().any(|l| l == item) {
                continue;
            }
            list.push(item.to_string());
            if !item.to_lowercase().starts_with("http") {
                errors.extend(site_file(item, kind));
            }
        }
        if !errors.is_empty() {
            notes.error_html(key, errors.join("<br>"));
        }
        if key == "css" {
            c.css = list;
        } else {
            c.js = list;
        }
    }
    c.subcategory = f.iter().filter_map(|(k, _)| k.strip_prefix("subcategory_").and_then(|rest| rest.split('_').nth(1)).map(str::to_string)).collect();
    let rows: Vec<(String, i64)> = f.iter().filter_map(|(k, _)| k.strip_prefix("content_name").map(|r| (r.to_string(), f.int(&format!("content_content_id{r}"))))).collect();
    let previous = std::mem::take(&mut draft.content);
    for (item, (row, id)) in rows.iter().enumerate() {
        let mut content = match previous.iter().find(|p| p.id == *id && *id != 0) {
            Some(p) => p.clone(),
            None => {
                let low = draft.content.iter().map(|x| x.id).chain(std::iter::once(0)).min().unwrap_or(0);
                Content { id: low - 1, ..Content::default() }
            }
        };
        content.errors = Vec::new();
        content.name = f.get(&format!("content_name{row}")).trim().to_string();
        if content.name.is_empty() {
            content.errors.push((format!("content_name{item}"), String::new()));
        }
        content.description = f.get(&format!("content_description{row}")).trim().to_string();
        content.access = f.get(&format!("content_access{row}")).to_string();
        if let Some(u) = posted.uploads.iter().find(|u| u.field == format!("content_file{row}") && !u.file_name.is_empty()) {
            content.revised = 0;
            content.file_name = u.file_name.clone();
            content.mime = "text/html".into();
            content.upload = None;
            let lower = u.file_name.to_lowercase();
            if u.bytes.is_empty() {
                content.errors.push((format!("content_file{item}"), "Empty file".into()));
            } else if !(lower.ends_with("htm") || lower.ends_with("html") || lower.ends_with("txt")) || u.bytes.len() > map::MAX_UPLOAD {
                content.errors.push((format!("content_file{item}"), format!("Invalid file type: {}", u.file_name)));
            } else {
                content.upload = Some(STANDARD.encode(&u.bytes));
            }
        }
        if content.description.is_empty() && content.file_name.is_empty() {
            content.errors.push((format!("content_description{item}"), "Record must contain either description or file".into()));
        }
        for (k, v) in &content.errors {
            if v.is_empty() {
                notes.error(k, None);
            } else {
                notes.error(k, Some(v));
            }
        }
        draft.content.push(content);
    }
    if c.kind != "Remote" && draft.content.is_empty() {
        notes.say("Content required");
        notes.error("null", None);
    }
    if notes.has_errors() {
        return Ok(None);
    }
    match save(pool, &c, draft).await? {
        Ok(id) => {
            c.id = id;
            notes.say("Record maintained");
            Ok(Some(c))
        }
        Err(e) => {
            notes.say(&e);
            notes.error("null", None);
            Ok(None)
        }
    }
}

async fn save(pool: &SqlitePool, c: &Campaign, draft: &Draft) -> sqlx::Result<Result<i64, String>> {
    let mut tx = pool.begin().await?;
    let divs = phpser::write(&Php::Array(c.divs.iter().map(|(k, v)| (Php::Str(k.clone()), Php::Str(v.clone()))).collect()));
    let (subcategory, css, scripts) = (c.subcategory.join("^"), c.css.join("^"), c.js.join("^"));
    let date = |d: &str| if d.is_empty() { "0000-00-00".to_string() } else { d.replace('/', "-") };
    let (from, thru) = (date(&c.from_date), date(&c.thru_date));
    let (active, omit) = (i64::from(c.active), i64::from(c.title_omit));
    let sf = (!c.sf_campaign_id.is_empty()).then_some(c.sf_campaign_id.as_str());
    let written = if c.id == 0 {
        sqlx::query_scalar!(r#"INSERT INTO campaign (code, name) VALUES (?1, ?2) RETURNING id AS "id!: i64""#, c.code, c.name).fetch_one(&mut *tx).await
    } else {
        Ok(c.id)
    };
    let id = match written {
        Ok(id) => id,
        Err(sqlx::Error::Database(e)) => return Ok(Err(e.message().to_string())),
        Err(e) => return Err(e),
    };
    let result = sqlx::query!(
        r#"UPDATE campaign SET code = ?2, name = ?3, description = ?4, from_date = ?5, thru_date = ?6, "type" = ?7, sf_campaign_id = ?8, sort_order = ?9,
                  active = ?10, title_omit = ?11, divs = ?12, subcategory = ?13, css = ?14, js = ?15 WHERE id = ?1"#,
        id,
        c.code,
        c.name,
        c.description,
        from,
        thru,
        c.kind,
        sf,
        c.sort_order,
        active,
        omit,
        divs,
        subcategory,
        css,
        scripts
    )
    .execute(&mut *tx)
    .await;
    if let Err(sqlx::Error::Database(e)) = result {
        return Ok(Err(e.message().to_string()));
    }
    result?;
    let now = unix_now();
    let kept: Vec<i64> = draft.content.iter().map(|x| x.id).filter(|i| *i > 0).collect();
    let kept = serde_json::to_string(&kept).unwrap_or_default();
    sqlx::query!("DELETE FROM content WHERE record_type = 'campaign' AND record_id = ?1 AND id NOT IN (SELECT value FROM json_each(?2))", id, kept)
        .execute(&mut *tx)
        .await?;
    for (row, x) in draft.content.iter().enumerate() {
        let item = format!("{}:{:06}", x.access, row);
        let meta = serde_json::json!({"name": x.file_name, "mime": if x.mime.is_empty() { "text/html" } else { x.mime.as_str() }, "width": 0, "height": 0, "type": 0, "dimensions": null, "bits": 0, "channels": 0}).to_string();
        let upload = x.upload.as_deref().map(|b| String::from_utf8_lossy(&STANDARD.decode(b).unwrap_or_default()).to_string());
        if x.id > 0 {
            sqlx::query!(
                "UPDATE content SET record_item = ?2, name = ?3, description = ?4, filetype = 'html', revised = ?5, meta = ?6,
                        html_content = COALESCE(?7, html_content), content = NULL WHERE id = ?1",
                x.id,
                item,
                x.name,
                x.description,
                now,
                meta,
                upload
            )
            .execute(&mut *tx)
            .await?;
        } else {
            sqlx::query!(
                "INSERT INTO content (record_type, record_id, record_item, language_code, name, description, filetype, revised, meta, html_content)
                 VALUES ('campaign', ?1, ?2, '', ?3, ?4, 'html', ?5, ?6, COALESCE(?7, ''))",
                id,
                item,
                x.name,
                x.description,
                now,
                meta,
                upload
            )
            .execute(&mut *tx)
            .await?;
        }
    }
    tx.commit().await?;
    Ok(Ok(id))
}

const SCRIPT: &str = r##"<script type='text/javascript'>
function fn_action(obj,action,id,name) {
	if (action=='delete') {
	    if (!confirm("Campaign: " + name)) {
	        obj.checked=false;
            return;
	    }
    }
	document.scs_form.action.value=action;
	document.scs_form.record_id.value=id;
	document.scs_form.submit();
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
$( function() {
	$( "#sortable_accordion" )
    .accordion({
	    header: "> div > h3",
	    collapsible: true,
	    active: false
	})
  .sortable({
    axis: "y",
    handle: "h3",
    stop: function( event, ui ) {
      ui.item.children( "h3" ).triggerHandler( "focusout" );
      $( this ).accordion( "refresh" );
    }
  });
} );
function content_row_add() {
	row=$("#row").val();
    row++;
	$("#row").val(row);
	$.ajax({
	    data: {
        	ajax_action: 'add',
        	ajax_row: row
        },
	    dataType: 'json',
	    success:function(data){
			$( "#sortable_accordion" ).append(data['row']);
			$( "#sortable_accordion" ).accordion( "refresh" );
			$( "#sortable_accordion" ).accordion( "option", "active", $('#sortable_accordion h3').index("#content_label" + row) );
        }
	});
}
function content_row_del(obj, row, content_id) {
    if (!confirm('Delete row?')) {
        obj.checked=false;
        return;
    }
	$.ajax({
	    data: {
        	ajax_action: 'delete',
        	ajax_content_id: content_id
        },
	    dataType: 'json',
	    success:function(data){
	        $( "#content_div" + row ).remove();
	        $( "#sortable_accordion" ).accordion( "refresh" );
	        $( "#sortable_accordion" ).accordion( "option", "active", false );
        }
	});
}
function content_label(id) {
	data=new Array();
	title=$("#content_name" + id).val();
    if (title.length) {
        data.push($("#content_access" + id).val() + ":" );
    	data.push(title);
	  } else {
        data.push("Empty:");
		data.push("New Content");
	}
	$('#content_label' + id).html(data.join(' '));
}
</script>
"##;

fn info_tab(form: &Form<'_>, c: &Campaign) -> Vec<String> {
    let shown = |d: &str| {
        let p: Vec<&str> = d.split('/').collect();
        if let [y, m, dd] = p[..] {
            format!("{m}/{dd}/{y}")
        } else {
            String::new()
        }
    };
    let orders: Vec<(String, String)> = (0..=99).map(|n| (n.to_string(), n.to_string())).collect();
    let rows = [
        ("Code", form.text("code", &c.code, 12, 12, Opts::new()), true),
        ("Name", form.text("name", &c.name, 40, 80, Opts::new()), false),
        ("Meta Description", form.text("description", &c.description, 60, 255, Opts::new()), false),
        ("From Date", form.date("from_date", &shown(&c.from_date), Opts::new()), false),
        ("Thru Date", form.date("thru_date", &shown(&c.thru_date), Opts::new()), false),
        ("Type", form.select("type", &list_options(&TYPES), &c.kind, Blank::None, Opts::new()), false),
        ("Salesforce Campaign ID", form.text("sf_campaign_id", &c.sf_campaign_id, 30, 30, Opts::new()), false),
        ("Sort Order", form.select("sort_order", &orders, &c.sort_order.to_string(), Blank::None, Opts::new()), false),
        ("Active?", form.checkbox("active", "1", c.active, Opts::new()), false),
    ];
    let mut out = vec!["<table class='large noborder'>".to_string()];
    for (label, widget, first) in rows {
        let (l, w) = if first { (" width='30%'", " width='70%'") } else { ("", "") };
        out.extend(["<tr>".to_string(), format!("<td{l}>{label}</td>"), format!("<td{w}>{widget}</td>"), "</tr>".into()]);
    }
    out.push("</table>".into());
    out
}

fn divs_tab(form: &Form<'_>, c: &Campaign) -> Vec<String> {
    let mut out = vec![
        "<table class='large noborder'>".to_string(),
        "<tr>".into(),
        "<td width='30%'>Omit Titles?</td>".into(),
        format!("<td width='70%'>{}</td>", form.checkbox("title_omit", "1", c.title_omit, Opts::new())),
        "</tr>".into(),
        "<tr><td><br>Divs</td></tr>".into(),
    ];
    for (key, label) in DIVS {
        let value = c.divs.iter().find(|(k, _)| k == key).map_or("", |(_, v)| v.as_str());
        out.extend(["<tr>".to_string(), format!("<td>{label}</td>"), format!("<td>{} (campaign_{key})</td>", form.text(&field(&["divs", key]), value, 40, 40, Opts::new())), "</tr>".into()]);
    }
    out.push("</table>".into());
    out
}

/// A category and its subcategories (id, name), as the Subcategories tab lists them.
type CategoryGroup = (i64, String, Vec<(i64, String)>);

async fn subcategory_tab(pool: &SqlitePool, form: &Form<'_>, c: &Campaign) -> sqlx::Result<Vec<String>> {
    let mut rows = sqlx::query!(
        r#"SELECT s.id AS "id!: i64", s.category_id AS "category_id!: i64", c.name AS "category!: String", c.sort_order AS "category_order!: i64",
                  COALESCE(s.sort_order, 0) AS "sort_order!: i64", COALESCE(s.name, '') AS "name!: String"
           FROM subcategory s LEFT JOIN category c ON c.id = s.category_id
           WHERE c.active = 1 AND s.status <> 'Inactive' AND c.name IS NOT NULL ORDER BY s.id"#
    )
    .fetch_all(pool)
    .await?;
    super::filesort::sort(&mut rows, |a, b| {
        a.category_order
            .cmp(&b.category_order)
            .then_with(|| super::filesort::ci(&a.category, &b.category))
            .then(a.sort_order.cmp(&b.sort_order))
            .then_with(|| super::filesort::ci(&a.name, &b.name))
    });
    let mut categories: Vec<CategoryGroup> = Vec::new();
    for r in rows {
        match categories.iter_mut().find(|(id, _, _)| *id == r.category_id) {
            Some((_, _, subs)) => subs.push((r.id, r.name)),
            None => categories.push((r.category_id, r.category, vec![(r.id, r.name)])),
        }
    }
    let mut out = vec![
        "<table class='standard border'>".to_string(),
        format!("<tr><th style='text-align: left;'>{} All subcategories</th></tr>", form.checkbox("all", "1", false, Opts::new().onclick("fn_subcategory(this);"))),
        "<tr><td>&nbsp;</td></tr>".into(),
    ];
    for (id, name, subs) in categories {
        let found = subs.iter().filter(|(s, _)| c.subcategory.contains(&s.to_string())).count();
        let all = form.checkbox(&format!("category_{id}"), "1", found == subs.len(), Opts::new().onclick("fn_subcategory(this);").class("subcategory"));
        out.push(format!("<tr><th style='text-align: left;'>{all} {name}</th></tr>"));
        let items: Vec<String> = subs
            .iter()
            .map(|(s, n)| {
                let on = c.subcategory.contains(&s.to_string());
                format!("{} {}", form.checkbox(&format!("subcategory_{id}_{s}"), "1", on, Opts::new().onclick("fn_subcategory(this);").class(format!("subcategory category_{id}"))), n)
            })
            .collect();
        out.push(format!("<tr><td><div class=newspaper2>{}</div></td></tr>", items.join("<br>\n")));
    }
    out.push("</table>".into());
    Ok(out)
}

fn list_tab(form: &Form<'_>, key: &str, label: &str, lines: &[String]) -> Vec<String> {
    let area = form.textarea_lines(key, lines, 3, 80, Opts::new());
    let mut text = vec![area];
    if let Some(e) = form.notes.error_text(key) {
        text.push(e.to_string());
    }
    vec![
        "<table class='large noborder'>".to_string(),
        "<tr>".into(),
        format!("<td width='30%'>{label}</td>"),
        format!("<td width='70%'>{}</td>", text.join("<br>")),
        "</tr>".into(),
        "</table>".into(),
    ]
}

async fn list(pool: &SqlitePool, form: &Form<'_>) -> sqlx::Result<String> {
    let mut rows = sqlx::query!(
        r#"SELECT id AS "id!: i64", COALESCE(code, '') AS "code!: String", COALESCE(name, '') AS "name!: String", from_date, thru_date, sort_order, "type" AS kind, active
           FROM campaign ORDER BY id"#
    )
    .fetch_all(pool)
    .await?;
    super::filesort::sort(&mut rows, |a, b| {
        b.active.unwrap_or(0).cmp(&a.active.unwrap_or(0)).then(a.sort_order.unwrap_or(0).cmp(&b.sort_order.unwrap_or(0))).then_with(|| super::filesort::ci(&a.name, &b.name)).then_with(|| super::filesort::ci(&a.code, &b.code))
    });
    let today = time::date("Y/m/d", unix_now());
    let mut out = String::from("<table class='standard border tablesorter'>\n<thead><tr><th>Code</th><th>Name</th><th>From Date</th><th>Thru Date</th><th>Order</th><th>Type</th><th>Del?</th></tr></thead><tr>");
    out.push_str(&format!("<td colspan=6>{}</td></tr></tr><tbody>", href("Add new", "javascript:fn_action(this,'maintain','','');", Opts::new())));
    for r in rows {
        let (from, thru) = (stored_date(r.from_date), stored_date(r.thru_date));
        let active = r.active.unwrap_or(0) != 0;
        let live = active && from.as_str() <= today.as_str() && (thru.is_empty() || thru.as_str() >= today.as_str());
        let bg = if !active {
            "style='background-color: #ffff00;'"
        } else if !live {
            "style='background-color: #d3d3d3;'"
        } else {
            ""
        };
        let shown = |d: &str| {
            let p: Vec<&str> = d.split('/').collect();
            if let [y, m, dd] = p[..] {
                format!("{m}/{dd}/{y}")
            } else {
                String::new()
            }
        };
        let id = js(&r.id.to_string());
        out.push_str(&format!("<tr {bg}>"));
        out.push_str(&format!("<td>{}</td>", href(&text(&r.code), &format!("javascript:fn_action(this,'maintain',{id},'');"), Opts::new())));
        out.push_str(&format!("<td>{}</td>", text(&r.name)));
        out.push_str(&format!("<td class=center>{}</td><td class=center>{}</td>", shown(&from), shown(&thru)));
        out.push_str(&format!("<td class=center>{}</td><td>{}</td>", r.sort_order.unwrap_or(0), text(&r.kind.unwrap_or_default())));
        let del = if active { "&nbsp;".to_string() } else { form.radio("delete", &r.id.to_string(), false, Opts::new().onclick(format!("fn_action(this,'delete',{id},{});", js(&r.code)))) };
        out.push_str(&format!("<td class=center>{del}</td></tr>"));
    }
    out.push_str("</tbody></table>");
    Ok(out)
}

async fn page(pool: &SqlitePool, staff: &Staff<Administrator>, posted: Option<&Posted>) -> sqlx::Result<Response> {
    let f = posted.map(|p| p.fields.clone()).unwrap_or_default();
    let mut notes = Notes::new(TITLE, "");
    let record_id = f.int("record_id");
    let mut campaign = if record_id != 0 { read(pool, record_id).await?.unwrap_or_default() } else { Campaign::default() };
    if campaign.id == 0 {
        campaign.kind = "Invite".into();
        campaign.active = true;
        campaign.title_omit = true;
    }
    let mut draft = None;
    match f.get("action") {
        "cancel" => {
            Draft::clear(pool, staff).await?;
            notes.say("Request cancelled");
        }
        "maintain" => {
            let d = Draft::of(pool, record_id).await?;
            d.save(pool, staff).await?;
            draft = Some(d);
        }
        "delete" => {
            sqlx::query!("DELETE FROM campaign WHERE id = ?1", record_id).execute(pool).await?;
            notes.say(&format!("Campaign {} deleted", campaign.code));
        }
        "update" => {
            if let (Some(mut d), Some(p)) = (Draft::load(pool, staff).await?, posted) {
                match update(pool, p, campaign.clone(), &mut d, &mut notes).await? {
                    Some(_) => Draft::clear(pool, staff).await?,
                    None => {
                        d.save(pool, staff).await?;
                        campaign = Campaign { id: campaign.id, ..posted_campaign(&p.fields, &campaign) };
                        draft = Some(d);
                    }
                }
            }
        }
        _ => {}
    }
    let form = Form { notes: &notes };
    let mut body = notes.render();
    body.push_str(SCRIPT);
    body.push_str(&open(PATH, staff.csrf.as_str(), true));
    body.push_str(&hidden("action", ""));
    body.push_str(&hidden("record_id", ""));
    match draft {
        Some(d) => {
            let rows: Vec<String> = d.content.iter().enumerate().map(|(i, c)| row_html(&form, i as i64, c)).collect();
            body.push_str(&hidden("row", &(d.content.len() as i64 - 1).to_string()));
            let mut tabs = Tabs::new();
            tabs.in_wordpress(staff.wp);
            tabs.lines("Info", &info_tab(&form, &campaign));
            let mut content = vec!["<div id='sortable_accordion'>".to_string()];
            content.extend(rows);
            content.push("</div> <!-- .sortable_accordion -->".into());
            content.push(format!("<p>{}</p>", button("Add Content", Opts::new().onclick("content_row_add();"))));
            tabs.lines("Content", &content);
            tabs.lines("Divs", &divs_tab(&form, &campaign));
            tabs.lines("Subcategories", &subcategory_tab(pool, &form, &campaign).await?);
            tabs.lines("CSS", &list_tab(&form, "css", "Additional CSS", &campaign.css));
            tabs.lines("JavaScript", &list_tab(&form, "js", "Additional JavaScript", &campaign.js));
            body.push_str(&tabs.render());
            let update = button("Update", Opts::new().onclick(format!("fn_action(this,'update',{},'');", js(&campaign.id.to_string()))));
            let cancel = button("Cancel", Opts::new().onclick("fn_action(this,'cancel','','');"));
            body.push_str(&format!("<p>{update} {cancel}</p>"));
        }
        None => body.push_str(&list(pool, &form).await?),
    }
    body.push_str(close());
    Ok(shell::page(pool, staff.view(), TITLE, &body).await)
}

/// The campaign as posted, for showing the form again after an error.
fn posted_campaign(f: &Fields, stored: &Campaign) -> Campaign {
    let date = |k: &str| mdy(f.get(k)).unwrap_or_default();
    Campaign {
        id: stored.id,
        code: f.get("code").trim().to_uppercase(),
        name: f.get("name").trim().to_string(),
        description: f.get("description").trim().to_string(),
        from_date: date("from_date"),
        thru_date: date("thru_date"),
        kind: f.get("type").to_string(),
        sf_campaign_id: f.get("sf_campaign_id").to_string(),
        // A form posted without the field selects no order, as PHP compared it to null.
        sort_order: if f.has("sort_order") { f.int("sort_order") } else { -1 },
        active: f.checked("active"),
        title_omit: f.checked("title_omit"),
        divs: DIVS.iter().map(|(k, _)| (k.to_string(), f.get(&field(&["divs", k])).trim().to_string())).filter(|(_, v)| !v.is_empty()).collect(),
        subcategory: f.iter().filter_map(|(k, _)| k.strip_prefix("subcategory_").and_then(|rest| rest.split('_').nth(1)).map(str::to_string)).collect(),
        css: f.get("css").split('\n').map(str::trim).filter(|i| !i.is_empty()).map(str::to_string).collect(),
        js: f.get("js").split('\n').map(str::trim).filter(|i| !i.is_empty()).map(str::to_string).collect(),
    }
}

fn is_ajax(headers: &HeaderMap) -> bool {
    headers.contains_key("x-requested-with")
}

async fn show(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, headers: HeaderMap, RawQuery(q): RawQuery) -> Response {
    let answer = if is_ajax(&headers) { ajax(&auth.pool, &staff, &Fields::from_query(q.as_deref())).await } else { page(&auth.pool, &staff, None).await };
    answer.unwrap_or_else(shell::internal)
}

/// The page's AJAX calls carry no form token, as its script sent none; `X-Requested-With` marks them, and they
/// touch only this session's draft.
async fn post(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, headers: HeaderMap, RawQuery(q): RawQuery, req: Request) -> Response {
    if is_ajax(&headers) {
        let Ok(body) = axum::body::to_bytes(req.into_body(), 1 << 20).await else { return StatusCode::BAD_REQUEST.into_response() };
        let fields = Fields::from_query(std::str::from_utf8(&body).ok()).with_query(&Fields::from_query(q.as_deref()));
        return ajax(&auth.pool, &staff, &fields).await.unwrap_or_else(shell::internal);
    }
    match Posted::from_request(req, &auth).await {
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
    fn file_types_were_a_character_class() {
        assert_eq!(site_file("nothere.css", "css").as_deref(), Some("nothere.css must begin with /"));
        assert_eq!(site_file("/x.php", "css").as_deref(), Some("/x.php not type css"));
    }

    #[test]
    fn dates_read_month_first() {
        assert_eq!(mdy(""), Some(String::new()));
        assert_eq!(mdy("10/9/17"), Some("2017/10/09".into()));
        assert_eq!(mdy("13/45/2026"), None);
    }
}
