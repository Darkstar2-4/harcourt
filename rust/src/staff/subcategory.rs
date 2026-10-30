//! subcategory.php: the Subcategory Manager. An Administrator lists subcategories by category, status and images,
//! and edits one across ten tabs: names and descriptions per language, currency adders, industry and portal access,
//! the Specials and Customs files, up to nine overview documents (uploaded, or written as HTML in the page's own
//! editor), parameters, search order, images and downloads.
//!
//! The PHP kept the subcategory's content items in `$_SESSION['maintain_subcategory']` from "maintain" until the
//! save, and the overview editor's AJAX calls changed them there. Here they live in `staff_draft` with the staff
//! session. Every part of the page, its AJAX answers and downloads included, needs an Administrator.

use std::{collections::BTreeMap, path::PathBuf, sync::Arc};

use axum::{
    extract::{FromRequest, RawQuery, Request, State},
    http::{header, HeaderMap, HeaderValue, StatusCode},
    response::{IntoResponse, Response},
    routing::get,
    Router,
};
use base64::{
    engine::general_purpose::{STANDARD, STANDARD_NO_PAD},
    Engine,
};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use sqlx::SqlitePool;

use super::{
    catalog::php_floatval,
    choices,
    csrf::{php_intval, Fields, Posted, Upload},
    html::{button, close, esc, hidden, href, list_options, number, open, text, Blank, Form, Notes, Opts, Tabs},
    inventory::{file_kind, languages},
    map,
    phpser::{self, Php},
    shell, time, Staff,
};
use crate::{
    api::{Administrator, Auth},
    db::unix_now,
};

const PATH: &str = "/subcategory.php";
const TITLE: &str = "Subcategory Manager";
const VERSION: &str = "10/06/2025";
const PARAMETERS: usize = 20;
const OVERVIEWS: usize = 9;
const DOWNLOADS: usize = 4;
const STATUS: [&str; 3] = ["Active", "Pending", "Inactive"];
const LEADTIME: [&str; 3] = ["In Stock", "Weeks", "Call"];
const OUTPUT: [(&str, &str); 3] = [("", "Products & CAD"), ("configurator", "Only CAD (Static SKU)"), ("smart", "Only CAD (SMART SKU)")];
const OPTIONS: [(&str, &str); 2] = [("sku", "Part#"), ("price", "Price")];
const TAB_FILES: [(&str, &str); 2] = [("specials", "Specials"), ("customs", "Customs")];
const IMAGE_LIST: [&str; 3] = ["Thumbnail", "Sketch", "Both"];
const PARAMETER_TYPES: [&str; 14] =
    ["Alphanumeric", "Uppercase", "Lowercase", "Numeric", "Decimal:0", "Decimal:1", "Decimal:2", "Decimal:3", "Decimal:4", "Decimal:5", "Decimal:6", "Decimal:7", "Decimal:8", "Decimal:9"];
const OVERVIEW_TYPES: [&str; 7] = ["htm", "html", "txt", "pdf", "jpg", "png", "gif"];
const IMAGES_DIR: &str = "images_subcategory";
const MAX_IMAGE: usize = 10 * 1024 * 1024;

/// fn_base64(): a field name made of tab-joined parts.
fn field(parts: &[&str]) -> String {
    STANDARD_NO_PAD.encode(parts.join("\t"))
}

/// subcategory_parameter_class.
#[derive(Debug, Clone, PartialEq)]
struct Parameter {
    id: usize,
    name: String,
    kind: String,
    hyperlink: bool,
}

/// The subcategory being edited (subcategory_data_class), as stored.
#[derive(Debug, Clone)]
struct Sub {
    id: i64,
    category_id: i64,
    name: String,
    prj: String,
    prj_landing_page: bool,
    image_url: String,
    thumbnail_url: String,
    sketch_url: String,
    parameters: Vec<Parameter>,
    parameter_search: Vec<String>,
    search_words: Vec<String>,
    download: Vec<String>,
    sort_order: i64,
    /// `Y/m/d`, or empty.
    new_date: String,
    page_break: bool,
    industry: Vec<String>,
    portal: Vec<String>,
    options: Vec<(String, String)>,
    output: String,
    status: String,
    leadtime_option: String,
    leadtime_text: String,
    currency: Vec<(String, f64)>,
    price_increase: i64,
    custom: i64,
    lookup: bool,
}

impl Sub {
    fn new(category_id: i64) -> Sub {
        Sub {
            id: 0,
            category_id,
            name: String::new(),
            prj: String::new(),
            prj_landing_page: false,
            image_url: String::new(),
            thumbnail_url: String::new(),
            sketch_url: String::new(),
            parameters: Vec::new(),
            parameter_search: Vec::new(),
            search_words: Vec::new(),
            download: Vec::new(),
            sort_order: 0,
            new_date: String::new(),
            page_break: false,
            industry: Vec::new(),
            portal: Vec::new(),
            options: Vec::new(),
            output: String::new(),
            status: "Active".into(),
            leadtime_option: "Call".into(),
            leadtime_text: String::new(),
            currency: Vec::new(),
            price_increase: 0,
            custom: 0,
            lookup: true,
        }
    }

    fn option(&self, key: &str) -> &str {
        self.options.iter().find(|(k, _)| k == key).map_or("", |(_, v)| v.as_str())
    }
}

fn caret_list(s: Option<&str>, strip_ends: bool) -> Vec<String> {
    match s.filter(|s| !s.is_empty()) {
        None => Vec::new(),
        Some(s) => {
            let inner = if strip_ends { s.get(1..s.len().saturating_sub(1)).unwrap_or("") } else { s };
            inner.split('^').map(str::to_string).collect()
        }
    }
}

fn parameters(stored: &str) -> Vec<Parameter> {
    phpser::parse(stored)
        .map(|v| {
            v.entries()
                .iter()
                .map(|(k, p)| Parameter {
                    id: usize::try_from(k.int()).unwrap_or(0),
                    name: p.get("name").map(Php::text).unwrap_or_default(),
                    kind: p.get("type").map(Php::text).unwrap_or_else(|| "Alphanumeric".into()),
                    hyperlink: p.get("hyperlink").is_some_and(Php::truthy),
                })
                .collect()
        })
        .unwrap_or_default()
}

fn parameters_text(list: &[Parameter]) -> String {
    let entries = list
        .iter()
        .map(|p| {
            let numeric = p.kind != "Alphanumeric" && p.kind != "Uppercase" && p.kind != "Lowercase";
            let decimal = if numeric && p.kind != "Numeric" { Php::Str(p.kind.chars().last().map(String::from).unwrap_or_default()) } else { Php::Int(0) };
            let props = vec![
                (Php::Str("id".into()), Php::Int(p.id as i64)),
                (Php::Str("name".into()), Php::Str(p.name.clone())),
                (Php::Str("type".into()), Php::Str(p.kind.clone())),
                (Php::Str("numeric".into()), Php::Bool(numeric)),
                (Php::Str("decimal".into()), decimal),
                (Php::Str("hyperlink".into()), Php::Int(i64::from(p.hyperlink))),
            ];
            (Php::Int(p.id as i64), Php::Object("subcategory_parameter_class".into(), props))
        })
        .collect();
    phpser::write(&Php::Array(entries))
}

async fn read(pool: &SqlitePool, id: i64) -> sqlx::Result<Option<Sub>> {
    let Some(r) = sqlx::query!(
        r#"SELECT id AS "id!: i64", category_id, name, prj, prj_landing_page, image_url, thumbnail_url, sketch_url, parameters, parameter_search,
                  search_words, download, sort_order, new_date, page_break, industry, portal, options, output, status, leadtime_option,
                  leadtime_text, currency, price_increase, custom, lookup
           FROM subcategory WHERE id = ?1"#,
        id
    )
    .fetch_optional(pool)
    .await?
    else {
        return Ok(None);
    };
    let options = r.options.as_deref().and_then(phpser::parse).map(|o| o.entries().iter().map(|(k, v)| (k.text(), v.text())).collect()).unwrap_or_default();
    let download = r.download.as_deref().and_then(phpser::parse).map(|o| o.entries().iter().map(|(_, v)| v.text()).collect()).unwrap_or_default();
    let currency = r
        .currency
        .as_deref()
        .and_then(|c| serde_json::from_str::<serde_json::Map<String, Value>>(c).ok())
        .map(|m| m.into_iter().map(|(k, v)| (k, v.as_f64().or_else(|| v.as_str().map(php_floatval)).unwrap_or(0.0))).collect())
        .unwrap_or_default();
    Ok(Some(Sub {
        id: r.id,
        category_id: r.category_id.unwrap_or(0),
        name: r.name.unwrap_or_default(),
        prj: r.prj.unwrap_or_default(),
        prj_landing_page: r.prj_landing_page.unwrap_or(0) != 0,
        image_url: r.image_url.unwrap_or_default(),
        thumbnail_url: r.thumbnail_url.unwrap_or_default(),
        sketch_url: r.sketch_url.unwrap_or_default(),
        parameters: r.parameters.as_deref().map(parameters).unwrap_or_default(),
        parameter_search: caret_list(r.parameter_search.as_deref(), false),
        search_words: caret_list(r.search_words.as_deref(), false),
        download,
        sort_order: r.sort_order.unwrap_or(0),
        new_date: match r.new_date.as_str() {
            "" | "0" | "0000-00-00" => String::new(),
            d => d.replace('-', "/"),
        },
        page_break: r.page_break.unwrap_or(0) != 0,
        industry: caret_list(r.industry.as_deref(), true),
        portal: caret_list(r.portal.as_deref(), true),
        options,
        output: r.output,
        status: r.status,
        leadtime_option: r.leadtime_option,
        leadtime_text: r.leadtime_text.unwrap_or_default(),
        currency,
        price_increase: r.price_increase.unwrap_or(0),
        custom: r.custom.unwrap_or(0),
        lookup: r.lookup.unwrap_or(1) != 0,
    }))
}

/// A content item the editor holds (content_data_class): stored, or changed by this edit.
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
struct Item {
    id: i64,
    name: String,
    description: String,
    filetype: String,
    revised: i64,
    file_name: String,
    mime: String,
    /// New content, base64; `None` keeps what is stored.
    content: Option<String>,
}

impl Item {
    fn meta_html(&self) -> String {
        if self.file_name.is_empty() {
            return String::new();
        }
        [format!("Name: {}", text(&self.file_name)), format!("File Type: {}", text(&self.filetype)), format!("Mime: {}", text(&self.mime)), format!("Revised: {}", time::date("m/d/Y h:iA", self.revised))]
            .join("<br>")
    }

    fn meta_json(&self) -> String {
        let name = if self.file_name.is_empty() { Value::Null } else { Value::from(self.file_name.clone()) };
        let mime = if self.mime.is_empty() { Value::Null } else { Value::from(self.mime.clone()) };
        json!({"name": name, "mime": mime, "width": 0, "height": 0, "type": 0, "dimensions": null, "bits": 0, "channels": 0}).to_string()
    }
}

/// `$_SESSION['maintain_subcategory']`: the items of each language, by record item ("name" for the names).
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
struct Draft {
    items: BTreeMap<String, BTreeMap<String, Item>>,
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

    fn get(&self, language: &str, item: &str) -> Option<&Item> {
        self.items.get(language).and_then(|m| m.get(item))
    }

    fn slot(&mut self, language: &str, item: &str) -> &mut Item {
        self.items.entry(language.to_string()).or_default().entry(item.to_string()).or_default()
    }

    /// input_maintain(): every content row of the subcategory, by language and item.
    async fn of(pool: &SqlitePool, id: i64, languages: &[(String, String)]) -> sqlx::Result<Draft> {
        let mut d = Draft::default();
        for (code, _) in languages {
            d.items.entry(code.clone()).or_default();
        }
        let rows = sqlx::query!(
            r#"SELECT id AS "id!: i64", language_code, record_item, name, description, filetype, meta, revised FROM content
               WHERE record_type = 'subcategory' AND record_id = ?1 ORDER BY id"#,
            id
        )
        .fetch_all(pool)
        .await?;
        for r in rows {
            let meta: Value = r.meta.as_deref().and_then(|m| serde_json::from_str(m).ok()).unwrap_or_default();
            let meta_text = |k: &str| meta.get(k).and_then(Value::as_str).unwrap_or("").to_string();
            let item = if r.record_item.is_empty() { "name".to_string() } else { r.record_item };
            *d.slot(&r.language_code, &item) =
                Item { id: r.id, name: r.name, description: r.description.unwrap_or_default(), filetype: r.filetype, revised: r.revised, file_name: meta_text("name"), mime: meta_text("mime"), content: None };
        }
        Ok(d)
    }
}

/// The content of an item: changed in this edit, or as stored.
async fn item_content(pool: &SqlitePool, item: &Item) -> sqlx::Result<Vec<u8>> {
    if let Some(c) = &item.content {
        return Ok(STANDARD.decode(c).unwrap_or_default());
    }
    if item.id == 0 {
        return Ok(Vec::new());
    }
    let row = sqlx::query!(r#"SELECT filetype, content, html_content FROM content WHERE id = ?1"#, item.id).fetch_optional(pool).await?;
    Ok(row.map(|r| if matches!(r.filetype.as_str(), "html" | "hyperlink") { r.html_content.unwrap_or_default().into_bytes() } else { r.content.unwrap_or_default() }).unwrap_or_default())
}

// -------------------------------------------------------------------------------------------------------------
// The AJAX calls of the overview editor and the download link
// -------------------------------------------------------------------------------------------------------------

/// json_encode() of an object as PHP printed it: keys in the order set, slashes escaped, non-ASCII as \u escapes.
fn php_json(pairs: &[(String, Value)]) -> String {
    let body: Vec<String> = pairs.iter().map(|(k, v)| format!("{}:{v}", Value::from(k.as_str()))).collect();
    crate::orders::shop::cart::php_json(&format!("{{{}}}", body.join(",")))
}

fn json_response(body: String) -> Response {
    shell::html_response(StatusCode::OK, body)
}

/// fn_text(): `<script…>` markup removed, then trimmed.
fn fn_text(s: &str) -> String {
    let re = regex::Regex::new(r"(?i)(<script)(.*)(>)").unwrap_or_else(|_| regex::Regex::new("$^").unwrap_or_else(|_| unreachable!()));
    re.replace_all(s, "").trim().to_string()
}

async fn ajax(pool: &SqlitePool, staff: &Staff<Administrator>, f: &Fields) -> sqlx::Result<Response> {
    let languages = languages(pool).await?;
    let language_name = |code: &str| languages.iter().find(|(c, _)| c == code).map_or(String::new(), |(_, n)| n.clone());
    let (sub_id, record_item, language) = (f.get("subcategory_id"), f.get("record_item"), f.get("language_code"));
    let mut out: Vec<(String, Value)> = Vec::new();
    let trace = |steps: &[&str]| Value::from(steps.iter().map(|s| Value::from(*s)).collect::<Vec<_>>());
    match f.get("action") {
        "sku_count" => {
            let id = php_intval(sub_id);
            let n = sqlx::query_scalar!(r#"SELECT count(*) AS "n!: i64" FROM inventory WHERE subcategory_id = ?1"#, id).fetch_one(pool).await?;
            out.push(("sku_count".into(), Value::from(number(n))));
            out.push(("trace".into(), trace(&["json", "json_sku_count"])));
        }
        "html_load" | "html_reload" => {
            let draft = Draft::load(pool, staff).await?.unwrap_or_default();
            let found = draft.get(language, record_item).is_some();
            let item = draft.get(language, record_item).cloned().unwrap_or_default();
            let content = if matches!(item.mime.as_str(), "text/plain" | "text/html") { String::from_utf8_lossy(&item_content(pool, &item).await?).to_string() } else { String::new() };
            let call = |action: &str| format!("subcategory_html('{action}', {sub_id}, {},{});", php_quote(record_item), php_quote(language));
            let mut buttons = vec![button("Update", Opts::new().onclick(call("html_update"))), button("Preview", Opts::new().onclick(call("html_preview")))];
            if item.filetype == "html" {
                buttons.push(button("Reload", Opts::new().onclick(call("html_reload"))));
            }
            buttons.push(button("Cancel", Opts::new().onclick(call("html_cancel"))));
            out.push(("overview_buttons".into(), Value::from(buttons.join(" "))));
            out.push(("overview_language_name".into(), Value::from(language_name(language))));
            out.push(("overview_remove".into(), Value::from(0)));
            let or_null = |v: String| if found { Value::from(v) } else { Value::Null };
            out.push(("overview_name".into(), or_null(item.name.clone())));
            out.push(("overview_html".into(), or_null(content.clone())));
            out.push(("overview_output".into(), or_null(content)));
            out.push(("overview_message".into(), Value::from("")));
            out.push(("visible".into(), Value::from(1)));
            out.push(("trace".into(), trace(&["json", "json_html_load", "json_load", "json_html_buttons"])));
        }
        "html_cancel" => {
            out.push(("overview_message".into(), Value::from("Request Cancelled")));
            out.push(("visible".into(), Value::from(0)));
            out.push(("trace".into(), trace(&["json", "json_html_cancel"])));
        }
        "html_preview" => {
            out.push(("overview_message".into(), Value::from("")));
            out.push(("overview_output".into(), Value::from(f.get("overview_html").trim())));
            out.push(("visible".into(), Value::from(1)));
            out.push(("trace".into(), trace(&["json", "json_html_preview"])));
        }
        "html_update" => {
            let mut draft = Draft::load(pool, staff).await?.unwrap_or_default();
            out.push(("visible".into(), Value::from(0)));
            let number_of = record_item.split_once(':').map_or(0, |(_, n)| php_intval(n));
            let mut message = Vec::new();
            let mut obj = draft.get(language, record_item).cloned().unwrap_or_default();
            let mut name = String::new();
            if !matches!(f.get("overview_remove").trim(), "" | "0" | "false" | "no") {
                message.push("Remove".to_string());
                obj = Item { id: obj.id, ..Item::default() };
            } else {
                name = fn_text(f.get("overview_name"));
                let html = fn_text(f.get("overview_html"));
                let mut errors = Vec::new();
                if name.is_empty() {
                    errors.push("&bull; Name cannot be blank");
                }
                if html.is_empty() {
                    errors.push("&bull; HTML cannot be blank");
                }
                if !errors.is_empty() {
                    out[0].1 = Value::from(1);
                    out.push(("overview_message".into(), Value::from(std::iter::once("Correct the following error(s)").chain(errors).collect::<Vec<_>>().join("<br>"))));
                    out.push(("trace".into(), trace(&["json", "json_html_update", "json_load"])));
                    return Ok(json_response(php_json(&out)));
                }
                message.push("Update".into());
                let revised = unix_now();
                obj = Item {
                    id: obj.id,
                    name: name.clone(),
                    description: String::new(),
                    filetype: "html".into(),
                    revised,
                    file_name: format!("{revised}.html"),
                    mime: "text/html".into(),
                    content: Some(STANDARD.encode(html.as_bytes())),
                };
            }
            message.push(format!("Item #{}", number_of + 1));
            message.push(format!("({})", language_name(language)));
            out.push((field(&["overview", "name", language, &number_of.to_string()]), Value::from(name)));
            out.push((field(&["overview", "meta", language, &number_of.to_string()]), Value::from(obj.meta_html())));
            out.push(("overview_message".into(), Value::from(message.join(" "))));
            out.push(("trace".into(), trace(&["json", "json_html_update", "json_load"])));
            *draft.slot(language, record_item) = obj;
            draft.save(pool, staff).await?;
        }
        _ => {
            out.push(("trace".into(), trace(&["json"])));
        }
    }
    Ok(json_response(php_json(&out)))
}

/// fn_escape() of a value put into the page's JavaScript.
fn php_quote(s: &str) -> String {
    format!("'{}'", s.replace('\\', "\\\\").replace('\'', "\\'"))
}

/// download(): one stored content item of a subcategory, as an attachment.
async fn download(pool: &SqlitePool, f: &Fields) -> sqlx::Result<Response> {
    let id = php_intval(f.get("subcategory_id"));
    let plain = |s: &'static str| (StatusCode::OK, [(header::CONTENT_TYPE, "text/html; charset=UTF-8")], s).into_response();
    if read(pool, id).await?.is_none() {
        return Ok(plain("Invalid subcategory"));
    }
    let (item, language) = (f.get("record_item"), f.get("language_code"));
    let row = sqlx::query!(
        r#"SELECT filetype, meta, content, html_content FROM content WHERE record_type = 'subcategory' AND record_id = ?1 AND record_item = ?2 AND language_code = ?3"#,
        id,
        item,
        language
    )
    .fetch_optional(pool)
    .await?;
    let Some(r) = row else { return Ok(plain("Content not found")) };
    let meta: Value = r.meta.as_deref().and_then(|m| serde_json::from_str(m).ok()).unwrap_or_default();
    let name = meta.get("name").and_then(Value::as_str).unwrap_or("").to_string();
    let mime = meta.get("mime").and_then(Value::as_str).unwrap_or("application/octet-stream").to_string();
    let body = if matches!(r.filetype.as_str(), "html" | "hyperlink") { r.html_content.unwrap_or_default().into_bytes() } else { r.content.unwrap_or_default() };
    let mut res = (StatusCode::OK, body).into_response();
    let h = res.headers_mut();
    h.insert(header::CONTENT_TYPE, HeaderValue::from_str(&mime).unwrap_or(HeaderValue::from_static("application/octet-stream")));
    h.insert(header::CONTENT_DISPOSITION, HeaderValue::from_str(&format!("attachment; filename={name}")).unwrap_or(HeaderValue::from_static("attachment")));
    h.insert(header::PRAGMA, HeaderValue::from_static("no-cache"));
    h.insert(header::EXPIRES, HeaderValue::from_static("0"));
    Ok(res)
}

// -------------------------------------------------------------------------------------------------------------
// The save
// -------------------------------------------------------------------------------------------------------------

/// The posted request, as input() read it.
struct Input {
    action: String,
    record_id: i64,
    record_id_text: String,
    report_category_id: String,
    report_status: String,
    report_images: String,
}

/// load_content() / load_tabs(): an uploaded file read into an item, or the reason it was refused.
fn take_file(item: &mut Item, upload: &Upload, types: &[&str]) -> Option<String> {
    if upload.bytes.is_empty() {
        return Some("Empty file".into());
    }
    let name: String = upload.file_name.chars().map(|c| if matches!(c, ' ' | '?' | '/' | '"') { '_' } else { c }).collect();
    let (mime, filetype) = file_kind(&name);
    item.revised = unix_now();
    item.file_name = name.clone();
    item.mime = mime.into();
    item.filetype = filetype.into();
    item.content = Some(STANDARD.encode(&upload.bytes));
    let suffix = name.rsplit('.').next().unwrap_or("").to_ascii_lowercase();
    if !types.is_empty() && !types.contains(&suffix.as_str()) {
        return Some(format!("File type must be {}", types.join(", ")));
    }
    None
}

/// input_update(): the post applied to the subcategory and its items, checked; saved when there is no error.
async fn update(pool: &SqlitePool, staff: &Staff<Administrator>, posted: &Posted, input: &Input, sub: &mut Sub, draft: &mut Draft, notes: &mut Notes) -> sqlx::Result<()> {
    let f = &posted.fields;
    let languages = languages(pool).await?;
    let file = |name: &str| posted.uploads.iter().find(|u| u.field == name && !u.file_name.is_empty());
    sub.category_id = php_intval(f.get("category_id"));
    if sub.category_id == 0 {
        notes.error("category_id", None);
    }
    for (code, _) in &languages {
        let (name_field, description_field) = (field(&["name", code]), field(&["description", code]));
        let item = draft.slot(code, "name");
        item.name = f.get(&name_field).trim().to_string();
        item.description = f.get(&description_field).trim().to_string();
        if code == "EN" {
            sub.name = item.name.clone();
            if sub.name.is_empty() {
                notes.error(&name_field, None);
            }
        }
    }
    sub.currency = Vec::new();
    for (code, _, _) in currencies(pool).await? {
        if !f.checked(&field(&["currency", &code, "override"])) {
            continue;
        }
        let adder_field = field(&["currency", &code, "adder_pct"]);
        let value = super::check::posted_number(&format!("{}%", f.get(&adder_field)), 2);
        let value: f64 = rust_decimal::prelude::ToPrimitive::to_f64(&value).unwrap_or(0.0);
        if value <= -100.0 || value >= 10.0 {
            notes.error(&adder_field, Some("Allowed range: -99% - + 1000%"));
        }
        sub.currency.push((code, value));
    }
    sub.custom = f.int("custom");
    for (key, _) in TAB_FILES {
        for (code, _) in &languages {
            let file_field = field(&[key, "file", code]);
            let item = draft.slot(code, key);
            if let Some(u) = file(&file_field) {
                if let Some(e) = take_file(item, u, &OVERVIEW_TYPES) {
                    notes.error(&file_field, Some(&e));
                }
            }
            item.name = fn_text(f.get(&field(&[key, "name", code])));
        }
    }
    sub.output = f.get("output").to_string();
    sub.options = OPTIONS.iter().map(|(k, _)| (k.to_string(), fn_text(f.get(&field(&["options", k]))))).filter(|(_, v)| !v.is_empty()).collect();
    sub.leadtime_option = f.get("leadtime_option").to_string();
    if sub.leadtime_option == "Weeks" {
        sub.leadtime_text = f.get("leadtime_text").trim().to_string();
        if sub.leadtime_text.is_empty() {
            notes.error("leadtime_text", None);
        }
    } else {
        sub.leadtime_text = String::new();
    }
    load_items(f, &file, draft, &languages, "overview", OVERVIEWS, &OVERVIEW_TYPES, notes);
    for kind in ["image", "thumbnail", "sketch"] {
        load_image(f, &file, sub, input, kind, notes);
    }
    sub.prj = percent_encoding::percent_decode_str(f.get("prj").trim()).decode_utf8_lossy().replace('+', " ");
    if !sub.prj.is_empty() && !form_urlencoded::parse(sub.prj.as_bytes()).any(|(k, _)| k == "info") {
        notes.error("prj", Some("Invalid PRJ Format"));
    }
    sub.parameters = Vec::new();
    for id in 0..PARAMETERS {
        let name = f.get(&field(&["parameter", "name", &id.to_string()])).trim().to_string();
        if name.is_empty() {
            break;
        }
        let kind = f.get(&field(&["parameter", "type", &id.to_string()])).to_string();
        let hyperlink = php_intval(f.get(&field(&["parameter", "hyperlink", &id.to_string()]))) != 0;
        sub.parameters.push(Parameter { id, name, kind, hyperlink });
    }
    sub.parameter_search = Vec::new();
    sub.industry = Vec::new();
    sub.portal = Vec::new();
    for (k, v) in f.iter() {
        if k.starts_with("parameter_search_") {
            sub.parameter_search.push(v.to_string());
        } else if k.starts_with("industry_") {
            sub.industry.push(v.to_string());
        } else if k.starts_with("portal_") {
            sub.portal.push(v.to_string());
        }
    }
    if sub.industry.is_empty() && sub.portal.is_empty() {
        notes.say("No industry/portals selected");
        notes.error("industry_portal", None);
    }
    sub.search_words = Vec::new();
    for word in f.get("search_words").split('\n') {
        let word = word.to_lowercase();
        if !word.is_empty() && word != "0" && !sub.search_words.contains(&word) {
            sub.search_words.push(word);
        }
    }
    sub.sort_order = php_floatval(f.get("sort_order")).round() as i64;
    sub.new_date = mdy(f.get("new_date"));
    if !sub.new_date.is_empty() && sub.new_date > time::date("Y/m/d", unix_now()) {
        notes.error("new_date", None);
    }
    sub.prj_landing_page = f.checked("prj_landing_page");
    load_items(f, &file, draft, &languages, "download", DOWNLOADS, &[], notes);
    sub.download = (0..DOWNLOADS).map(|i| f.get(&field(&["download", "inventory", &i.to_string()])).trim().to_string()).collect();
    sub.page_break = f.checked("page_break");
    sub.status = f.get("status").to_string();
    sub.lookup = f.int("lookup") != 0;
    if !sub.output.is_empty() && sub.prj.is_empty() {
        notes.error("prj", Some("PRJ required for Static/Smart SKU"));
    } else if sub.output == "smart" && !configure_module(sub.id) {
        notes.error("output", Some(&format!("classes/configure_{}.php not found", sub.id)));
    }
    if notes.has_errors() {
        return Ok(());
    }
    match save(pool, sub, draft).await? {
        Err(e) => {
            if e.to_lowercase().contains("prj") {
                notes.error("prj", Some("Duplicate PRJ"));
            } else {
                notes.error("name", Some(&e));
            }
            return Ok(());
        }
        Ok(id) => sub.id = id,
    }
    if input.record_id == 0 {
        rename_uploads(pool, sub).await?;
    }
    notes.say("Record maintained");
    if sub.output == "smart" && f.checked("inventory_delete") {
        let target = php_intval(f.get("subcategory_id"));
        let n = sqlx::query!("DELETE FROM inventory WHERE subcategory_id = ?1", target).execute(pool).await?.rows_affected();
        notes.say(&format!("{} SKUS deleted", number(n as i64)));
    }
    if !sub.lookup {
        sqlx::query!("UPDATE inventory SET prj = NULL WHERE subcategory_id = ?1", sub.id).execute(pool).await?;
    }
    Draft::clear(pool, staff).await?;
    Ok(())
}

/// A smart module was a PHP class file per subcategory; the port has none, so one is never found.
fn configure_module(id: i64) -> bool {
    docroot().is_some_and(|root| root.join(format!("classes/configure_{id}.php")).is_file())
}

#[allow(clippy::too_many_arguments)]
fn load_items<'a>(
    f: &Fields,
    file: &impl Fn(&str) -> Option<&'a Upload>,
    draft: &mut Draft,
    languages: &[(String, String)],
    kind: &str,
    count: usize,
    types: &[&str],
    notes: &mut Notes,
) {
    for i in 0..count {
        for (code, _) in languages {
            let (name_field, file_field) = (field(&[kind, "name", code, &i.to_string()]), field(&[kind, "file", code, &i.to_string()]));
            let record_item = format!("{kind}:{i}");
            let item = draft.slot(code, &record_item);
            item.name = f.get(&name_field).trim().to_string();
            let mut error = None;
            if !item.name.is_empty() {
                if let Some(u) = file(&file_field) {
                    error = take_file(item, u, &[]);
                }
            }
            if error.is_none() && !item.name.is_empty() {
                let suffix = item.file_name.rsplit('.').next().unwrap_or("").to_ascii_lowercase();
                if item.file_name.is_empty() {
                    error = Some("File required".into());
                } else if !types.is_empty() && !types.contains(&suffix.as_str()) {
                    error = Some(format!("File type must be {}", types.join(", ")));
                }
            }
            if let Some(e) = error {
                notes.error(&file_field, Some(&e));
            }
        }
    }
}

fn docroot() -> Option<PathBuf> {
    std::env::var_os("HXWEB_DOCROOT").map(PathBuf::from)
}

/// load_image(): an uploaded jpg, png or gif replaces the image (a thumbnail must be the registry's size); a ticked
/// box deletes it. Files are named by the page (`/images_subcategory/<type><id>.<ext>`), never by the upload.
fn load_image<'a>(f: &Fields, file: &impl Fn(&str) -> Option<&'a Upload>, sub: &mut Sub, input: &Input, kind: &str, notes: &mut Notes) {
    let upload_field = format!("{kind}_upload");
    let url = match kind {
        "image" => &mut sub.image_url,
        "thumbnail" => &mut sub.thumbnail_url,
        _ => &mut sub.sketch_url,
    };
    if let Some(u) = file(&upload_field) {
        let suffix = u.file_name.rsplit('.').next().unwrap_or("").to_ascii_lowercase();
        if u.bytes.is_empty() {
            notes.error(&upload_field, Some("Empty file"));
        } else if !matches!(suffix.as_str(), "jpg" | "png" | "gif") || u.bytes.len() > MAX_IMAGE {
            notes.error_html(&upload_field, "Invalid file type: <b></b>".into());
        } else {
            let suffix_id = if input.record_id != 0 { input.record_id.to_string() } else { "_upload".into() };
            let path = format!("/{IMAGES_DIR}/{kind}{suffix_id}.{suffix}");
            match image::load_from_memory(&u.bytes) {
                Ok(img) if kind == "thumbnail" && thumbnail_size().is_some_and(|s| img.width() != s || img.height() != s) => {
                    notes.error(&upload_field, Some("Thumbnail size error"));
                }
                Ok(_) => {
                    if let Some(root) = docroot() {
                        if let Err(e) = std::fs::write(root.join(path.trim_start_matches('/')), &u.bytes) {
                            tracing::error!("subcategory image {path}: {e}");
                        }
                    }
                    *url = path;
                }
                Err(_) => notes.error_html(&upload_field, "Invalid file type: <b></b>".into()),
            }
        }
    } else if kind == "thumbnail" && f.checked("thumbnail_create") {
    } else if f.checked(&format!("{kind}_delete")) {
        remove_image(url);
        url.clear();
    } else if !f.get(&upload_field).is_empty() {
        *url = f.get(&upload_field).to_string();
    }
}

fn thumbnail_size() -> Option<u32> {
    Some(80)
}

/// An image file of this page, if the path is one of ours.
fn remove_image(url: &str) {
    let ours = url.strip_prefix(&format!("/{IMAGES_DIR}/")).is_some_and(|n| !n.contains('/') && !n.contains(".."));
    if let (true, Some(root)) = (ours, docroot()) {
        let _ = std::fs::remove_file(root.join(url.trim_start_matches('/')));
    }
}

/// image_rename(): a new subcategory's `_upload` images take its id.
async fn rename_uploads(pool: &SqlitePool, sub: &mut Sub) -> sqlx::Result<()> {
    for url in [&mut sub.image_url, &mut sub.thumbnail_url, &mut sub.sketch_url] {
        if url.contains("_upload") {
            let renamed = url.replace("_upload", &sub.id.to_string());
            if let Some(root) = docroot() {
                let _ = std::fs::rename(root.join(url.trim_start_matches('/')), root.join(renamed.trim_start_matches('/')));
            }
            *url = renamed;
        }
    }
    let (image, thumbnail, sketch) = (&sub.image_url, &sub.thumbnail_url, &sub.sketch_url);
    sqlx::query!("UPDATE subcategory SET image_url = ?2, thumbnail_url = ?3, sketch_url = ?4 WHERE id = ?1", sub.id, image, thumbnail, sketch).execute(pool).await?;
    Ok(())
}

/// fn_date($text, "mdy").
fn mdy(s: &str) -> String {
    let parts: Vec<i64> = s.split([' ', '/', '.', '-']).map(php_intval).collect();
    if s.trim().is_empty() || parts.len() < 3 {
        return String::new();
    }
    let (m, d, mut y) = (parts[0], parts[1], parts[2]);
    y += match y {
        0..=49 => 2000,
        50..=99 => 1900,
        _ => 0,
    };
    let ok = (1800..=2200).contains(&y) && chrono::NaiveDate::from_ymd_opt(y as i32, m as u32, d as u32).is_some();
    if ok {
        format!("{y:04}/{m:02}/{d:02}")
    } else {
        String::new()
    }
}

/// subcategory->update() and the content items: one transaction. The error text of a refused write (a duplicate
/// name or PRJ) comes back for the page.
async fn save(pool: &SqlitePool, sub: &Sub, draft: &Draft) -> sqlx::Result<Result<i64, String>> {
    let mut tx = pool.begin().await?;
    let options = phpser::write(&Php::Array(sub.options.iter().map(|(k, v)| (Php::Str(k.clone()), Php::Str(v.clone()))).collect()));
    let download = phpser::write(&Php::Array(sub.download.iter().enumerate().map(|(i, v)| (Php::Int(i as i64), Php::Str(v.clone()))).collect()));
    let currency = if sub.currency.is_empty() { "[]".to_string() } else { Value::Object(sub.currency.iter().map(|(k, v)| (k.clone(), Value::from(*v))).collect()).to_string() };
    let parameters = parameters_text(&sub.parameters);
    let (parameter_search, search_words) = (sub.parameter_search.join("^"), sub.search_words.join("^"));
    let (industry, portal) = (format!("^{}^", sub.industry.join("^")), format!("^{}^", sub.portal.join("^")));
    let new_date = if sub.new_date.is_empty() { "0000-00-00".to_string() } else { sub.new_date.replace('/', "-") };
    let prj = (!sub.prj.is_empty()).then_some(sub.prj.as_str());
    let (landing, page_break, lookup) = (i64::from(sub.prj_landing_page), i64::from(sub.page_break), i64::from(sub.lookup));
    let documents = "a:0:{}";
    let written = if sub.id == 0 {
        sqlx::query_scalar!(r#"INSERT INTO subcategory (name, category_id) VALUES (?1, ?2) RETURNING id AS "id!: i64""#, sub.name, sub.category_id).fetch_one(&mut *tx).await
    } else {
        Ok(sub.id)
    };
    let id = match written {
        Ok(id) => id,
        Err(sqlx::Error::Database(e)) => return Ok(Err(e.message().to_string())),
        Err(e) => return Err(e),
    };
    let result = sqlx::query!(
        "UPDATE subcategory SET category_id = ?2, name = ?3, prj = ?4, prj_landing_page = ?5, image_url = ?6, thumbnail_url = ?7, sketch_url = ?8,
                parameters = ?9, parameter_search = ?10, documents = ?11, download = ?12, sort_order = ?13, search_words = ?14, new_date = ?15,
                page_break = ?16, options = ?17, status = ?18, output = ?19, leadtime_option = ?20, leadtime_text = ?21, industry = ?22,
                portal = ?23, currency = ?24, price_increase = ?25, custom = ?26, lookup = ?27
         WHERE id = ?1",
        id,
        sub.category_id,
        sub.name,
        prj,
        landing,
        sub.image_url,
        sub.thumbnail_url,
        sub.sketch_url,
        parameters,
        parameter_search,
        documents,
        download,
        sub.sort_order,
        search_words,
        new_date,
        page_break,
        options,
        sub.status,
        sub.output,
        sub.leadtime_option,
        sub.leadtime_text,
        industry,
        portal,
        currency,
        sub.price_increase,
        sub.custom,
        lookup
    )
    .execute(&mut *tx)
    .await;
    match result {
        Ok(_) => {}
        Err(sqlx::Error::Database(e)) => return Ok(Err(e.message().to_string())),
        Err(e) => return Err(e),
    }
    let now = unix_now();
    for (language, items) in &draft.items {
        for (record_item, item) in items {
            let stored_item = if record_item == "name" { "" } else { record_item.as_str() };
            if !item.name.is_empty() || !item.description.is_empty() {
                let meta = item.meta_json();
                let html = matches!(item.filetype.as_str(), "html" | "hyperlink");
                let content = item.content.as_deref().map(|c| STANDARD.decode(c).unwrap_or_default());
                let (blob, html_content) = match (&content, html) {
                    (Some(c), true) => (None, Some(String::from_utf8_lossy(c).to_string())),
                    (Some(c), false) => (Some(c.clone()), Some(String::new())),
                    (None, _) => (None, None),
                };
                let changed = content.is_some();
                sqlx::query!(
                    "INSERT INTO content (record_type, record_id, record_item, language_code, name, description, filetype, revised, meta, content, html_content)
                     VALUES ('subcategory', ?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, COALESCE(?10, ''))
                     ON CONFLICT (record_type, record_id, record_item, language_code) DO UPDATE SET
                         name = excluded.name, description = excluded.description, filetype = excluded.filetype, revised = excluded.revised,
                         meta = excluded.meta,
                         content = CASE WHEN ?11 THEN excluded.content ELSE content END,
                         html_content = CASE WHEN ?11 THEN excluded.html_content ELSE html_content END",
                    id,
                    stored_item,
                    language,
                    item.name,
                    item.description,
                    item.filetype,
                    now,
                    meta,
                    blob,
                    html_content,
                    changed
                )
                .execute(&mut *tx)
                .await?;
            } else if item.id != 0 {
                sqlx::query!("DELETE FROM content WHERE id = ?1", item.id).execute(&mut *tx).await?;
            }
        }
    }
    tx.commit().await?;
    Ok(Ok(id))
}

async fn delete(pool: &SqlitePool, sub: &Sub) -> sqlx::Result<()> {
    let mut tx = pool.begin().await?;
    sqlx::query!("DELETE FROM content WHERE record_type = 'subcategory' AND record_id = ?1", sub.id).execute(&mut *tx).await?;
    sqlx::query!("DELETE FROM subcategory WHERE id = ?1", sub.id).execute(&mut *tx).await?;
    tx.commit().await?;
    for url in [&sub.image_url, &sub.thumbnail_url, &sub.sketch_url] {
        remove_image(url);
    }
    Ok(())
}

// -------------------------------------------------------------------------------------------------------------
// The page
// -------------------------------------------------------------------------------------------------------------

/// `$menu->currency` without the dollar: code, name and adder, by name.
async fn currencies(pool: &SqlitePool) -> sqlx::Result<Vec<(String, String, f64)>> {
    let rows = sqlx::query!(
        r#"SELECT code AS "code!: String", name AS "name!: String", adder_pct AS "adder_pct!: f64" FROM currency
           WHERE exchange_rate > 0 AND active = 1 AND code <> 'USD' ORDER BY name COLLATE NOCASE"#
    )
    .fetch_all(pool)
    .await?;
    Ok(rows.into_iter().map(|r| (r.code, r.name, r.adder_pct)).collect())
}

/// forms->img(): a picture of the site, through scs_file.php when it is resized (`extra` first in its query, then the
/// path, then `size`); nothing when the file is not in the document root.
fn img(src: &str, alt: &str, resize: Option<&[(&str, &str)]>, size: &[(&'static str, &str)]) -> Option<String> {
    crate::catalog::forms::img(src, &[])?;
    let shown = match resize {
        Some(extra) => {
            let mut q = form_urlencoded::Serializer::new(String::new());
            q.extend_pairs(extra.iter().copied());
            q.append_pair("code", src);
            q.extend_pairs(size.iter().copied());
            format!("/scs_file.php?{}", q.finish())
        }
        None => esc(src),
    };
    let size: String = size.iter().map(|(k, v)| format!(" {k}='{v}'")).collect();
    Some(format!("<img src='{shown}'{size} border='0' title='{}'>", esc(alt)))
}

const SCRIPT: &str = r##"
<script>
function fn_action(action,record_id,name) {
    if (action=="delete") {
        jQuery("#delete" + record_id).prop("checked", false);
        if (!confirm("Delete: #" + record_id + " " + name)) return;
    }
    jQuery("#action").val(action);
    jQuery("#record_id").val(record_id);
    jQuery("#scs_form").submit();
}
jQuery(document).ready(function() {
    jQuery(".subcategory_html").hide();
    fn_leadtime_option();
});
function fn_checked(obj) {
    jQuery("input." + obj.id).prop("checked",obj.checked);
}
var sku_count=0;
jQuery(document).ready(function() {
    jQuery.ajax({
        type: "post",
        dataType: 'json',
        data: { action: "sku_count", subcategory_id: jQuery("#subcategory_id").val() },
        success:function(response) {
            sku_count=response['sku_count'];
            fn_output();
        }
    });
});
function fn_output() {
    jQuery("#inventory_delete_td").html("Delete " + sku_count + " SKU'S?");
    if (jQuery("#output").val() == "smart") {
        jQuery("#inventory_delete_tr").show();
        } else {
        jQuery("#inventory_delete_tr").hide();
    }
}
function fn_leadtime_option() {
    if (jQuery("#leadtime_option").val() == "Weeks") {
        jQuery("#leadtime_text_span").show();
        } else {
        jQuery("#leadtime_text_span").hide();
    }
}
function subcategory_html(action, subcategory_id, record_item, language_code) {
    switch (true) {
      case (action == "html_cancel"):
        text="Cancel update?";
        break;
      case (action == "html_reload"):
        text="Reload HTML?";
        break;
      case ( (action == "html_update") && (jQuery("#overview_remove").prop('checked')) ):
        text="Remove HTML?";
        break;
      default:
        text="";
    }
    if ( (text) && (!confirm(text)) ) return;

    input=scscpq_class_data('overview_input');
    input['action']=action;
    input['subcategory_id']=subcategory_id;
    input['record_item']=record_item;
    input['language_code']=language_code;

    jQuery.ajax({
        type: "post",
        dataType: 'json',
        data: input,
        success:function(response) {
            scscpq_fill(response);
            if (response['visible']) {
                jQuery(".subcategory_html").show();
              } else {
                jQuery(".subcategory_html").hide();
            }
        }
    });
}
function subcategory_download(action, subcategory_id, field_name) {
    if (!confirm("Download File?")) return;
    url=new URL(window.location.href);
    url.searchParams.set("action", action);
    url.searchParams.set("subcategory_id", subcategory_id);
    url.searchParams.set("field_name", field_name);
    window.open(url, "subcategory");
}
</script>
"##;

async fn list(pool: &SqlitePool, form: &Form<'_>, input: &Input, listed: bool) -> sqlx::Result<String> {
    let mut out = vec!["<table class='large noborder'>".to_string(), "<tr>".into(), "<th>Category</th>".into(), "<th>Status</th>".into(), "<th>Images</th>".into(), "<th>&nbsp;</th>".into(), "</tr>".into(), "<tr>".into()];
    out.push(format!("<td class=center>{}</td>", form.select("report_category_id", &choices::categories(pool).await?, &input.report_category_id, Blank::Empty, Opts::new())));
    out.push(format!("<td class=center>{}</td>", form.select("report_status", &list_options(&STATUS), &input.report_status, Blank::Empty, Opts::new())));
    out.push(format!("<td class=center>{}</td>", form.select("report_images", &list_options(&IMAGE_LIST), &input.report_images, Blank::Empty, Opts::new())));
    out.push(format!("<td class=center>{}</td>", button("List", Opts::new().onclick("fn_action('list','','');"))));
    out.extend(["</tr>", "</table>"].map(String::from));
    let images = input.report_images.as_str();
    let (thumbs, sketches) = (matches!(images, "Thumbnail" | "Both"), matches!(images, "Sketch" | "Both"));
    let colspan = 6 + usize::from(thumbs) + usize::from(sketches);
    out.extend(
        ["<table class='large border tablesorter'>", "<caption>&nbsp;</caption>", "<thead>", "<tr>", "<th width='50%'>Name</th>", "<th width='20%'>Output</th>", "<th width='10%'>Reverse Part#</th>", "<th width='10%'>Status</th>", "<th width='10%'>Currency Adder</th>"]
            .map(String::from),
    );
    if thumbs {
        out.push("<th width='100'>Thumbnail</th>".into());
    }
    if sketches {
        out.push("<th width='100'>Sketch</th>".into());
    }
    out.extend(["<th width='10%'>Del?</th>", "</tr>", "</thead>", "<tr>"].map(String::from));
    out.push(format!("<td colspan={colspan}>{}</td>", href("Add new", "javascript:fn_action('maintain','0','');", Opts::new())));
    out.extend(["</tr>", "<tbody>"].map(String::from));
    if listed {
        let category = php_intval(&input.report_category_id);
        let mut rows = sqlx::query!(
            r#"SELECT id AS "id!: i64", name, output, lookup, status, currency, thumbnail_url, image_url, sketch_url, sort_order FROM subcategory
               WHERE (?1 = 0 OR category_id = ?1) AND (?2 = '' OR status = ?2) ORDER BY id"#,
            category,
            input.report_status
        )
        .fetch_all(pool)
        .await?;
        super::filesort::sort(&mut rows, |a, b| a.sort_order.cmp(&b.sort_order).then_with(|| super::filesort::ci_opt(a.name.as_deref(), b.name.as_deref())));
        for r in rows {
            let bg = match r.status.as_str() {
                "Inactive" => "style='background-color: #e9967a;'",
                "Pending" => "style='background-color: #fff8dc;'",
                _ => "",
            };
            let name = r.name.unwrap_or_default();
            out.push(format!("<tr {bg}>"));
            out.push(format!("<td>{}</td>", href(&text(&name), &format!("javascript:fn_action('maintain',{},'');", php_quote(&r.id.to_string())), Opts::new())));
            out.push(format!("<td>{}</td>", OUTPUT.iter().find(|(k, _)| *k == r.output).map_or("", |(_, v)| v)));
            out.push(format!("<td class=center>{}</td>", if r.lookup.unwrap_or(1) != 0 { "Yes" } else { "No" }));
            out.push(format!("<td>{}</td>", text(&r.status)));
            let adders: Vec<String> = r
                .currency
                .as_deref()
                .and_then(|c| serde_json::from_str::<serde_json::Map<String, Value>>(c).ok())
                .map(|m| m.into_iter().map(|(k, v)| format!("{}: {}%", text(&k), super::html::number_dp(v.as_f64().unwrap_or(0.0) * 100.0, 2))).collect())
                .unwrap_or_default();
            out.push(format!("<td>{}</td>", adders.join("<br>")));
            if thumbs {
                let thumb = r.thumbnail_url.unwrap_or_default();
                let image = r.image_url.unwrap_or_default();
                let cell = if !thumb.is_empty() {
                    img(&thumb, "Thumbnail", None, &[]).unwrap_or_default()
                } else if !image.is_empty() {
                    img(&image, "Resized image", Some(&[]), &[("width", "100"), ("height", "100")]).unwrap_or_default()
                } else {
                    "&nbsp;".into()
                };
                out.push(format!("<td>{cell}</td>"));
            }
            if sketches {
                out.push(format!("<td>{}</td>", img(&r.sketch_url.unwrap_or_default(), "Sketch", Some(&[]), &[("width", "100"), ("height", "100")]).unwrap_or_default()));
            }
            let del = if r.status == "Inactive" { form.checkbox_delete(r.id, &name, "fn_action") } else { "&nbsp;".into() };
            out.push(format!("<td class=center>{del}</td>"));
            out.push("</tr>".into());
        }
    }
    out.extend(["</tbody>", "</table>"].map(String::from));
    Ok(out.concat())
}

async fn name_tab(form: &Form<'_>, sub: &Sub, draft: &Draft, input: &Input, languages: &[(String, String)]) -> Vec<String> {
    let mut out = vec![hidden("subcategory_id", &input.record_id_text), "<table class='large noborder'>".into()];
    for (code, language) in languages {
        let item = draft.get(code, "name").cloned().unwrap_or_default();
        let (name_field, description_field) = (field(&["name", code]), field(&["description", code]));
        out.push("<tr>".into());
        out.push(format!("<td width='30%'>{} Name</td>", text(language)));
        out.push(format!("<td width='70%'>{}</td>", form.notes.with_error(&name_field, form.text(&name_field, &item.name, 60, 60, Opts::new()))));
        out.push("</tr>".into());
        out.push("<tr><td colspan=2><br></td></tr>".into());
        out.push("<tr>".into());
        out.push(format!("<td width='30%'>{} Description</td>", text(language)));
        out.push(format!("<td width='70%'>{}</td>", form.notes.with_error(&description_field, form.textarea(&description_field, &item.description, 6, 80, Opts::new()))));
        out.push("</tr>".into());
    }
    out.push("<tr>".into());
    out.push("<td>Lead Time</td>".into());
    let leadtime = form.select("leadtime_option", &list_options(&LEADTIME), &sub.leadtime_option, Blank::None, Opts::new().onchange("fn_leadtime_option();"));
    let weeks = format!("<span id='leadtime_text_span'>{}</span>", form.text("leadtime_text", &sub.leadtime_text, 8, 8, Opts::new()));
    out.push(format!("<td>{leadtime} {weeks}</td>"));
    out.push("</tr>".into());
    out.push("<tr>".into());
    out.push("<td>Output</td>".into());
    let outputs: Vec<(String, String)> = OUTPUT.iter().map(|(k, v)| (k.to_string(), v.to_string())).collect();
    let output = form.select("output", &outputs, &sub.output, Blank::None, Opts::new().onchange("fn_output();"));
    out.push(format!("<td>{}</td>", note_after(form, "output", output, " ")));
    out.push("</tr>".into());
    out.push("<tr id='inventory_delete_tr'>".into());
    out.push("<td id='inventory_delete_td'></td>".into());
    out.push(format!("<td>{}</td>", form.checkbox("inventory_delete", "1", false, Opts::new())));
    out.push("</tr>".into());
    out.push("<tr><td colspan=2>Product Range Titles</td></tr>".into());
    for (key, label) in OPTIONS {
        out.push("<tr>".into());
        out.push(format!("<td>&bull; {label}</td>"));
        out.push(format!("<td>{}</td>", form.text(&field(&["options", key]), sub.option(key), 20, 20, Opts::new())));
        out.push("</tr>".into());
    }
    if sub.price_increase != 0 {
        out.push("<tr>".into());
        out.push("<td>Last Price Increase</td>".into());
        out.push(format!("<td>{}</td>", time::date("m/d/Y h:i A", sub.price_increase)));
        out.push("</tr>".into());
    }
    out.push("</table>".into());
    out
}

/// A widget and its error text, as the page joined `$text` with `$sep`.
fn note_after(form: &Form<'_>, field: &str, widget: String, sep: &str) -> String {
    match form.notes.error_text(field) {
        Some(t) => format!("{widget}{sep}{t}"),
        None => widget,
    }
}

async fn currency_tab(pool: &SqlitePool, form: &Form<'_>, sub: &Sub) -> sqlx::Result<Vec<String>> {
    let mut out: Vec<String> = ["<table class=border>", "<tr>", "<th>Name</th>", "<th>Current Adder%</td>", "<th>Override?</th>", "<th>New Adder</th>", "</tr>"].map(String::from).to_vec();
    for (code, name, adder) in currencies(pool).await? {
        let set = sub.currency.iter().find(|(c, _)| *c == code).map(|(_, v)| *v);
        out.push("<tr>".into());
        out.push(format!("<td>{}</td>", text(&name)));
        out.push(format!("<td class=center>{}%</td>", super::html::number_dp(adder * 100.0, 2)));
        out.push(format!("<td class=center>{}</td>", form.checkbox(&field(&["currency", &code, "override"]), "1", set.is_some(), Opts::new())));
        let adder_field = field(&["currency", &code, "adder_pct"]);
        let shown = set.map(|v| v * 100.0).filter(|v| *v != 0.0).map(|v| super::html::number_dp(v, 2)).unwrap_or_default();
        out.push(format!("<td>{}</td>", note_after(form, &adder_field, format!("{}%", form.text(&adder_field, &shown, 7, 7, Opts::new())), " ")));
        out.push("</tr>".into());
    }
    out.push("</table>".into());
    Ok(out)
}

async fn access_tab(pool: &SqlitePool, form: &Form<'_>, sub: &Sub) -> sqlx::Result<Vec<String>> {
    let industries = sqlx::query!(r#"SELECT id AS "id!: i64", name FROM industry WHERE active = 1 ORDER BY id"#).fetch_all(pool).await?;
    let portals = sqlx::query!(r#"SELECT id AS "id!: i64", name FROM portal WHERE active = 1 ORDER BY id"#).fetch_all(pool).await?;
    let mut industries: Vec<(i64, String)> = industries.into_iter().map(|r| (r.id, r.name)).collect();
    let mut portals: Vec<(i64, String)> = portals.into_iter().map(|r| (r.id, r.name)).collect();
    super::filesort::sort(&mut industries, |a, b| super::filesort::ci(&a.1, &b.1));
    super::filesort::sort(&mut portals, |a, b| super::filesort::ci(&a.1, &b.1));
    let column = |rows: &[(i64, String)], chosen: &[String], key: &str, all: &str| -> Vec<String> {
        if rows.is_empty() {
            return Vec::new();
        }
        let every = rows.len() == chosen.len();
        let mut list = vec![format!("<b>{} {all}</b>", form.checkbox(key, "1", every, Opts::new().onclick("fn_checked(this);")))];
        for (id, name) in rows {
            let id = id.to_string();
            let on = chosen.contains(&id);
            list.push(format!("{} {}", form.checkbox(&format!("{key}_{id}"), &id, on, Opts::new().class(key)), text(name)));
        }
        list
    };
    let industry = column(&industries, &sub.industry, "industry", "All industries");
    let portal = column(&portals, &sub.portal, "portal", "All portals");
    if industry.is_empty() && portal.is_empty() {
        return Ok(Vec::new());
    }
    let mut out = vec!["<table class='standard border'>".to_string(), "<tr>".into()];
    if !industry.is_empty() {
        out.push("<th width='50%'>Industry</th>".into());
    }
    if !portal.is_empty() {
        out.push("<th width='50%'>Portal</th>".into());
    }
    out.push("</tr>".into());
    out.push("<tr>".into());
    if !industry.is_empty() {
        out.push(format!("<td>{}</td>", industry.join("<br>")));
    }
    if !portal.is_empty() {
        out.push(format!("<td>{}</td>", portal.join("<br>")));
    }
    out.push("</tr>".into());
    out.push("</table>".into());
    Ok(out)
}

fn language_heads(out: &mut Vec<String>, languages: &[(String, String)], first: &str, widths: (&str, &str)) {
    out.push("<tr>".into());
    out.push(first.to_string());
    for (_, name) in languages {
        out.push(format!("<th colspan=2>{}</th>", text(name)));
    }
    out.push("</tr>".into());
    out.push("<tr>".into());
    for _ in languages {
        out.push(format!("<th{}>Name</th>", widths.0));
        out.push(format!("<th{}>Content</th>", widths.1));
    }
    out.push("</tr>".into());
}

fn catalog_tab(form: &Form<'_>, draft: &Draft, languages: &[(String, String)]) -> Vec<String> {
    let mut out = vec!["<table class='large border'>".to_string()];
    language_heads(&mut out, languages, "<th rowspan=2 width='10%'>Field</th>", (" width='20%'", " width='25%'"));
    for (key, label) in TAB_FILES {
        out.push("<tr>".into());
        out.push(format!("<td>{label}</td>"));
        for (code, _) in languages {
            let item = draft.get(code, key).cloned().unwrap_or_default();
            let mut name = vec![form.text(&field(&[key, "name", code]), &item.name, 30, 30, Opts::new())];
            let mut content = vec![form.file(&field(&[key, "file", code]), 40, Opts::new())];
            if let Some(e) = form.notes.error_text(key) {
                name.push(e.to_string());
                content.push(e.to_string());
            }
            if !item.name.is_empty() {
                content.push(format!("Name: {}", text(&item.file_name)));
                content.push(format!("File type: {}", text(&item.filetype)));
                content.push(format!("Mime: {}", text(&item.mime)));
                content.push(format!("Revised: {}", time::date("m/d/Y h:i A", item.revised)));
            }
            out.push(format!("<td>{}</td>", name.join("<br>")));
            out.push(format!("<td>{}</td>", content.join("<br>")));
        }
        out.push("</tr>".into());
    }
    out.push("</table>".into());
    out
}

fn overview_tab(form: &Form<'_>, sub: &Sub, draft: &Draft, languages: &[(String, String)]) -> Vec<String> {
    let mut out = vec!["<div id=overview_message></div>".to_string(), "<table class='border subcategory_html'>".into()];
    let editor = [
        format!("Name: {}", form.text("overview_name", "", 40, 80, Opts::new().class("overview_input"))),
        format!("Remove? {}", form.checkbox("overview_remove", "1", false, Opts::new().class("overview_input"))),
        "<span id=overview_buttons></span>".into(),
        "Language: <span id=overview_language_name></span>".into(),
    ];
    out.push(format!("<tr><td colspan=2>{}</td></tr>", editor.join(" ")));
    out.extend(["<tr>", "<th width='50%'>HTML</th>", "<th width='50%'>Preview</th>", "</tr>", "<tr>"].map(String::from));
    out.push(format!("<td>{}</td>", form.textarea("overview_html", "", 20, 80, Opts::new().class("overview_input"))));
    out.extend(["<td id=overview_output></td>", "</tr>", "</table>", "<table class='standard border'>"].map(String::from));
    language_heads(&mut out, languages, "<th rowspan=2>Item</th>", ("", ""));
    for i in 0..OVERVIEWS {
        out.push("<tr>".into());
        out.push(format!("<td class=center>{}.</td>", i + 1));
        for (code, _) in languages {
            let n = i.to_string();
            let (name_field, file_field, meta_field) = (field(&["overview", "name", code, &n]), field(&["overview", "file", code, &n]), field(&["overview", "meta", code, &n]));
            let record_item = format!("overview:{i}");
            let item = draft.get(code, &record_item).cloned().unwrap_or_default();
            out.push(format!("<td>{}</td>", form.notes.with_error(&name_field, form.text(&name_field, &item.name, 40, 80, Opts::new()))));
            let html = button("HTML", Opts::new().onclick(format!("subcategory_html('html_load', {},{},{});", sub.id, php_quote(&record_item), php_quote(code))));
            let mut cell = format!("<p>{html} {}</p>", form.file(&file_field, 40, Opts::new()));
            if let Some(e) = form.notes.error_text(&file_field) {
                cell.push_str(e);
            }
            cell.push_str(&format!("<div id={meta_field}>{}</div>", item.meta_html()));
            out.push(format!("<td>{cell}</td>"));
        }
        out.push("</tr>".into());
    }
    out.push("</table>".into());
    out
}

async fn parameters_tab(pool: &SqlitePool, form: &Form<'_>, sub: &Sub) -> sqlx::Result<Vec<String>> {
    let mut out = vec!["<table class='large noborder'>".to_string(), "<tr>".into(), "<td width='30%'>Product Category</td>".into()];
    out.push(format!("<td width='70%'>{}</td>", form.select("category_id", &choices::categories(pool).await?, &sub.category_id.to_string(), Blank::Empty, Opts::new())));
    out.extend(["</tr>", "<tr>", "<td>PARTcommunity PRJ</td>"].map(String::from));
    out.push(format!("<td>{}</td>", form.notes.with_error("prj", form.textarea("prj", &sub.prj, 4, 60, Opts::new()))));
    out.extend(["</tr>", "<tr>", "<td>Reverse Part# Lookup?</td>"].map(String::from));
    out.push(format!("<td>{}</td>", form.checkbox("lookup", "1", sub.lookup, Opts::new())));
    out.extend(["</tr>", "<tr>", "<td>Catalog landing page?</td>"].map(String::from));
    out.push(format!("<td>{}</td>", form.checkbox("prj_landing_page", "1", sub.prj_landing_page, Opts::new())));
    out.extend(["</tr>", "<tr>", "<td>Output Order</td>"].map(String::from));
    out.push(format!("<td>{}</td>", form.text("sort_order", &sub.sort_order.to_string(), 6, 6, Opts::new())));
    out.extend(["</tr>", "<tr>", "<td>Search Words</td>"].map(String::from));
    out.push(format!("<td>{}</td>", form.textarea_lines("search_words", &sub.search_words, 3, 40, Opts::new())));
    out.extend(["</tr>", "<tr>", "<td>New Date</td>"].map(String::from));
    let shown = sub.new_date.split('/').collect::<Vec<_>>();
    let shown = if let [y, m, d] = shown[..] { format!("{m}/{d}/{y}") } else { String::new() };
    out.push(format!("<td>{}</td>", form.date("new_date", &shown, Opts::new())));
    out.extend(["</tr>", "<tr>"].map(String::from));
    out.push(format!("<td>Page break every {} records?</td>", number(super::html::PAGE_ROWS)));
    out.push(format!("<td>{}</td>", form.checkbox("page_break", "1", sub.page_break, Opts::new())));
    out.extend(["</tr>", "<tr>", "<td>Status</td>"].map(String::from));
    out.push(format!("<td>{}</td>", form.select("status", &list_options(&STATUS), &sub.status, Blank::None, Opts::new())));
    out.extend(["</tr>", "</table>", "<table class='large border'>", "<caption >&nbsp;</caption>\n", "<tr>", "<th width='33%'>Field</th>", "<th width='33%'>Type</th>", "<th width='33%'>Hyperlink?</th>", "</tr>"].map(String::from));
    for id in 0..PARAMETERS {
        let p = sub.parameters.iter().find(|p| p.id == id).cloned().unwrap_or(Parameter { id, name: String::new(), kind: "Alphanumeric".into(), hyperlink: false });
        let n = id.to_string();
        out.push("<tr>".into());
        out.push(format!("<td>{}</td>", form.text(&field(&["parameter", "name", &n]), &p.name, 40, 40, Opts::new())));
        out.push(format!("<td class=center>{}</td>", form.select(&field(&["parameter", "type", &n]), &list_options(&PARAMETER_TYPES), &p.kind, Blank::None, Opts::new())));
        out.push(format!("<td class=center>{}</td>", form.checkbox(&field(&["parameter", "hyperlink", &n]), "1", p.hyperlink, Opts::new())));
        out.push("</tr>".into());
    }
    out.push("</table>".into());
    Ok(out)
}

fn search_tab(form: &Form<'_>, sub: &Sub) -> Vec<String> {
    if sub.parameters.is_empty() {
        return Vec::new();
    }
    let mut out = vec!["<table class='standard noborder'>".to_string(), hidden("search_tab", "1")];
    let mut items = Vec::new();
    for id in &sub.parameter_search {
        if let Some(p) = sub.parameters.iter().find(|p| p.id.to_string() == *id) {
            items.push(format!("<li class='ui-state-default'>{}{}</li>", form.checkbox(&format!("parameter_search_{}", p.id), &p.id.to_string(), true, Opts::new()), text(&p.name)));
        }
    }
    for p in &sub.parameters {
        if sub.parameter_search.contains(&p.id.to_string()) {
            continue;
        }
        items.push(format!("<li class='ui-state-default'>{}{}</li>", form.checkbox(&format!("parameter_search_{}", p.id), &p.id.to_string(), false, Opts::new()), text(&p.name)));
    }
    out.push(format!("<tr><td><ol class='sortable standard' width='50%'>{}</ol></td></tr>", items.concat()));
    out.push("</table>".into());
    out
}

fn images_tab(form: &Form<'_>, sub: &Sub) -> Vec<String> {
    let thumb = thumbnail_size().unwrap_or(0).to_string();
    let mut out = vec![hidden("image_upload", &sub.image_url), hidden("thumbnail_upload", &sub.thumbnail_url), hidden("sketch_upload", &sub.sketch_url), "<table class='large noborder'>".into()];
    out.extend(["<tr>", "<td width='30%'>Image URL</td>"].map(String::from));
    out.push(format!("<td width='70%'>{}</td>", form.notes.with_error("image_upload", form.file("image_upload", 40, Opts::new()))));
    out.push("</tr>".into());
    out.extend(["<tr>", "<td>Create thumbnail from upload?</td>"].map(String::from));
    out.push(format!("<td>{}</td>", form.checkbox("thumbnail_create", "1", false, Opts::new())));
    out.push("</tr>".into());
    if !sub.image_url.is_empty() {
        out.push("<tr>".into());
        out.push(format!("<td>Delete? {}</td>", form.checkbox("image_delete", "1", false, Opts::new())));
        out.push(format!("<td>{}</td>", img(&sub.image_url, "Image", Some(&[("height", "200")]), &[]).unwrap_or_default()));
        out.push("</tr>".into());
    }
    out.push("<tr>".into());
    out.push(format!("<td>Thumbnail ({thumb} x {thumb})</td>"));
    out.push(format!("<td>{}</td>", form.notes.with_error("thumbnail_upload", form.file("thumbnail_upload", 40, Opts::new()))));
    out.push("</tr>".into());
    if !sub.thumbnail_url.is_empty() {
        out.push("<tr>".into());
        out.push(format!("<td>Delete? {}</td>", form.checkbox("thumbnail_delete", "1", false, Opts::new())));
        out.push(format!("<td>{}</td>", img(&sub.thumbnail_url, "Thumbnail", None, &[]).unwrap_or_default()));
        out.push("</tr>".into());
    }
    out.extend(["<tr>", "<td>Sketch URL</td>"].map(String::from));
    out.push(format!("<td>{}</td>", form.notes.with_error("sketch_upload", form.file("sketch_upload", 40, Opts::new()))));
    out.push("</tr>".into());
    if !sub.sketch_url.is_empty() {
        out.push("<tr>".into());
        out.push(format!("<td>Delete? {}</td>", form.checkbox("sketch_delete", "1", false, Opts::new())));
        out.push(format!("<td>{}</td>", img(&sub.sketch_url, "Sketch", Some(&[]), &[("height", "200")]).unwrap_or_default()));
        out.push("</tr>".into());
    }
    out.push("</table>".into());
    out
}

fn download_tab(form: &Form<'_>, draft: &Draft, languages: &[(String, String)]) -> Vec<String> {
    let mut out = vec!["<table class='standard border'>".to_string()];
    language_heads(&mut out, languages, "<th rowspan=2>Item</th>", ("", ""));
    for i in 0..OVERVIEWS {
        out.push("<tr>".into());
        out.push(format!("<td class=center>{}.</td>", i + 1));
        for (code, _) in languages {
            let n = i.to_string();
            let (name_field, file_field) = (field(&["download", "name", code, &n]), field(&["download", "file", code, &n]));
            let item = draft.get(code, &format!("download:{i}")).cloned().unwrap_or_default();
            out.push(format!("<td>{}</td>", form.text(&name_field, &item.name, 40, 80, Opts::new())));
            let mut content = vec![form.file(&file_field, 40, Opts::new())];
            if let Some(e) = form.notes.error_text(&file_field) {
                content.push(e.to_string());
            }
            if !item.file_name.is_empty() {
                content.push(item.meta_html());
            }
            out.push(format!("<td>{}</td>", content.join("<br>")));
        }
        out.push("</tr>".into());
    }
    out.push("</table>".into());
    out
}

fn inventory_download_tab(form: &Form<'_>, sub: &Sub) -> Vec<String> {
    let mut out = vec!["<table class='large noborder'>".to_string()];
    for i in 0..DOWNLOADS {
        let value = sub.download.get(i).map_or("", String::as_str);
        out.push("<tr>".into());
        out.push(format!("<td>{}. {}</td>", i + 1, form.text(&field(&["download", "inventory", &i.to_string()]), value, 40, 80, Opts::new())));
        out.push("</tr>".into());
    }
    out.push("</table>".into());
    out
}

async fn page(pool: &SqlitePool, staff: &Staff<Administrator>, posted: Option<&Posted>) -> sqlx::Result<Response> {
    let f = posted.map(|p| p.fields.clone()).unwrap_or_default();
    let input = Input {
        action: f.get("action").to_string(),
        record_id: php_intval(f.get("record_id")),
        record_id_text: f.get("record_id").to_string(),
        report_category_id: f.get("report_category_id").to_string(),
        report_status: f.get("report_status").to_string(),
        report_images: f.get("report_images").to_string(),
    };
    let mut notes = Notes::new(TITLE, VERSION);
    let languages = languages(pool).await?;
    let mut sub = if input.action.is_empty() { None } else { read(pool, input.record_id).await? };
    let mut draft = None;
    let mut edit = false;
    match input.action.as_str() {
        "" => {}
        "maintain" => {
            let s = sub.get_or_insert_with(|| Sub::new(php_intval(&input.report_category_id)));
            let d = Draft::of(pool, input.record_id, &languages).await?;
            d.save(pool, staff).await?;
            draft = Some(d);
            let _ = s;
            edit = true;
        }
        "delete" => {
            if let Some(s) = &sub {
                delete(pool, s).await?;
                notes.say(&format!("Subcategory {} deleted", s.name));
            } else {
                notes.say("Subcategory  deleted");
            }
        }
        "list" => {
            if input.report_category_id.is_empty() || input.report_category_id == "0" {
                notes.error("report_category_id", None);
            }
        }
        "cancel" => {
            Draft::clear(pool, staff).await?;
            notes.say("Request cancelled");
        }
        "update" => {
            if let (Some(mut d), Some(p)) = (Draft::load(pool, staff).await?, posted) {
                let mut s = sub.clone().unwrap_or_else(|| Sub::new(0));
                update(pool, staff, p, &input, &mut s, &mut d, &mut notes).await?;
                if notes.has_errors() {
                    d.save(pool, staff).await?;
                    edit = true;
                    draft = Some(d);
                }
                sub = Some(s);
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
    if edit {
        let sub = sub.clone().unwrap_or_else(|| Sub::new(php_intval(&input.report_category_id)));
        let draft = draft.unwrap_or_default();
        body.push_str(&hidden("report_category_id", &input.report_category_id));
        body.push_str(&hidden("report_status", &input.report_status));
        body.push_str(&hidden("report_images", &input.report_images));
        let mut tabs = Tabs::new();
        tabs.in_wordpress(staff.wp);
        tabs.lines("Name", &name_tab(&form, &sub, &draft, &input, &languages).await);
        tabs.lines("Currency", &currency_tab(pool, &form, &sub).await?);
        tabs.lines("Access", &access_tab(pool, &form, &sub).await?);
        tabs.lines("Catalog", &catalog_tab(&form, &draft, &languages));
        tabs.lines("Overview", &overview_tab(&form, &sub, &draft, &languages));
        tabs.lines("Parameters", &parameters_tab(pool, &form, &sub).await?);
        tabs.lines("Search", &search_tab(&form, &sub));
        tabs.lines("Images", &images_tab(&form, &sub));
        if !input.report_category_id.is_empty() && input.report_category_id != "0" {
            tabs.lines("Subcategory Download", &download_tab(&form, &draft, &languages));
            tabs.lines("Inventory Download", &inventory_download_tab(&form, &sub));
        }
        body.push_str(&tabs.render());
        let update = button("Update", Opts::new().onclick(format!("fn_action('update',{},'');", php_quote(&sub.id.to_string()))));
        let cancel = button("Cancel", Opts::new().onclick("fn_action('cancel','0','');"));
        body.push_str(&format!("<p>{update} {cancel}</p>"));
    } else {
        body.push_str(&list(pool, &form, &input, input.action == "list" && !notes.has_errors()).await?);
    }
    body.push_str(close());
    Ok(shell::page(pool, staff.view(), TITLE, &body).await)
}

fn is_ajax(headers: &HeaderMap) -> bool {
    headers.contains_key("x-requested-with")
}

async fn show(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, headers: HeaderMap, RawQuery(q): RawQuery) -> Response {
    let query = Fields::from_query(q.as_deref());
    let answer = if is_ajax(&headers) {
        ajax(&auth.pool, &staff, &Fields::default()).await
    } else if query.get("action") == "download" {
        download(&auth.pool, &query).await
    } else {
        page(&auth.pool, &staff, None).await
    };
    answer.unwrap_or_else(shell::internal)
}

/// The editor's AJAX posts carry no form token, as the PHP page's script sent none; `X-Requested-With`, which a
/// page on another site cannot send without the browser asking first, marks them. They change only this session's
/// draft, which a save (a form post with the token) must still apply.
async fn post(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, headers: HeaderMap, RawQuery(q): RawQuery, req: Request) -> Response {
    let query = Fields::from_query(q.as_deref());
    if is_ajax(&headers) {
        let Ok(body) = axum::body::to_bytes(req.into_body(), map::MAX_UPLOAD).await else { return StatusCode::BAD_REQUEST.into_response() };
        let fields = Fields::from_query(std::str::from_utf8(&body).ok()).with_query(&query);
        return ajax(&auth.pool, &staff, &fields).await.unwrap_or_else(shell::internal);
    }
    let posted = match Posted::from_request(req, &auth).await {
        Ok(p) => p,
        Err(rejected) => return rejected.into_response(),
    };
    let request = posted.fields.clone().with_query(&query);
    let answer = if request.get("action") == "download" { download(&auth.pool, &request).await } else { page(&auth.pool, &staff, Some(&posted)).await };
    answer.unwrap_or_else(shell::internal)
}

pub fn routes() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(show).post(post))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parameters_round_trip_through_serialize() {
        let list = vec![Parameter { id: 0, name: "Type".into(), kind: "Alphanumeric".into(), hyperlink: false }, Parameter { id: 1, name: "A Dia".into(), kind: "Decimal:3".into(), hyperlink: true }];
        assert_eq!(parameters(&parameters_text(&list)), list);
    }

    #[test]
    fn script_markup_is_dropped_from_editor_text() {
        assert_eq!(fn_text(" <p>a</p><script src=x>alert(1)</script> "), "<p>a</p>");
        assert_eq!(fn_text("<b>ok</b>"), "<b>ok</b>");
    }

    #[test]
    fn dates_read_month_first() {
        assert_eq!(mdy("3/5/24"), "2024/03/05");
        assert_eq!(mdy("13/5/2024"), "");
        assert_eq!(mdy(""), "");
    }

    #[test]
    fn overview_meta_lists_the_file() {
        let item = Item { file_name: "a.html".into(), filetype: "html".into(), mime: "text/html".into(), revised: 0, ..Item::default() };
        assert_eq!(item.meta_html(), "Name: a.html<br>File Type: html<br>Mime: text/html<br>Revised: 12/31/1969 07:00PM");
        assert_eq!(Item::default().meta_html(), "");
    }
}
