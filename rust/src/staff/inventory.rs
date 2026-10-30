//! inventory.php: the Inventory Manager. An Administrator finds a part by SKU or lists a subcategory's parts, and
//! edits one: subcategory, SKU, sort order, new date, lead time, parameters, price breaks, competitor SKUs, the
//! descriptions per language and the download files.
//!
//! The PHP kept the descriptions and downloads of the part being edited in the session between the form and the
//! save ("Refresh not allowed" otherwise). Here a save reads them from the database and applies what was posted.

use std::{str::FromStr, sync::Arc};

use axum::{
    extract::State,
    response::{IntoResponse, Response},
    routing::get,
    Router,
};
use base64::{engine::general_purpose::STANDARD, Engine};
use rust_decimal::Decimal;
use serde_json::Value;
use sqlx::SqlitePool;

use super::{
    catalog::{self, Subcategory},
    check::{posted_number, posted_text},
    choices,
    csrf::{Fields, Posted, Unchecked, Upload},
    html::{amount, button, close, hidden, href, js, list_options, open, php_float, text, Blank, Form, Notes, Opts, Tabs},
    phpser::{self, Php},
    shell, time, Staff,
};
use crate::{api::{Administrator, Auth}, db::unix_now};

const PATH: &str = "/inventory.php";
const TITLE: &str = "Inventory Manager";
const PARAMETERS: usize = 20;
const BREAKS: usize = 10;
const DOWNLOADS: usize = 4;
const DOCUMENTS: [(&str, &str); 4] = [("overview", "Overview"), ("features", "Features"), ("applications", "Applications"), ("specials", "Specials")];
const LEADTIME: [&str; 4] = ["Subcategory", "In Stock", "Weeks", "Call"];

/// A form field the PHP named by the base64 of its tab-joined parts.
fn field(parts: &[&str]) -> String {
    STANDARD.encode(parts.join("\t"))
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Action {
    None,
    List,
    Maintain,
    Delete,
    Cancel,
    Subcategory,
    Update,
}

impl Action {
    fn parse(s: &str) -> Action {
        match s {
            "list" => Action::List,
            "maintain" => Action::Maintain,
            "delete" => Action::Delete,
            "cancel" => Action::Cancel,
            "subcategory" => Action::Subcategory,
            "update" => Action::Update,
            _ => Action::None,
        }
    }
}

/// One price break: from this quantity up, this price.
#[derive(Debug, Clone, PartialEq)]
pub(super) struct Break {
    pub(super) qty: Decimal,
    pub(super) price: Decimal,
}

/// The part being edited, as inventory_data_class held it.
struct Item {
    id: i64,
    sku: String,
    category_id: i64,
    subcategory_id: i64,
    parameter: Vec<String>,
    /// The numeric parameters as text: the stored number as PHP printed it, or what output() made of the post.
    nparameter: Vec<String>,
    documents: Vec<(String, Php)>,
    pricing: Vec<Break>,
    sort_order: i64,
    /// `Y/m/d`, or empty.
    new_date: String,
    competitor_sku: Vec<String>,
    leadtime_option: String,
    leadtime_text: String,
    active: bool,
}

impl Item {
    fn new() -> Item {
        Item {
            id: 0,
            sku: String::new(),
            category_id: 0,
            subcategory_id: 0,
            parameter: vec![String::new(); PARAMETERS],
            nparameter: vec![String::new(); PARAMETERS],
            documents: Vec::new(),
            pricing: Vec::new(),
            sort_order: 0,
            new_date: String::new(),
            competitor_sku: Vec::new(),
            leadtime_option: "Subcategory".into(),
            leadtime_text: String::new(),
            active: true,
        }
    }

    fn document(&self, key: &str) -> String {
        self.documents.iter().find(|(k, _)| k == key).map(|(_, v)| v.text()).unwrap_or_default()
    }
}

fn decimal(p: Option<&Php>) -> Decimal {
    p.map(Php::text).and_then(|s| Decimal::from_str(s.trim()).or_else(|_| Decimal::from_scientific(s.trim())).ok()).unwrap_or_default()
}

pub(super) fn pricing(stored: &str) -> Vec<Break> {
    phpser::parse(stored).map(|v| v.entries().iter().map(|(_, b)| Break { qty: decimal(b.get("qty_break")), price: decimal(b.get("price")) }).collect()).unwrap_or_default()
}

/// The breaks as the PHP serialized them: the first quantity an integer, later ones floats.
pub(super) fn pricing_text(breaks: &[Break]) -> String {
    let number = |d: &Decimal, first: bool| if first { Php::Int(0) } else { Php::Float(d.normalize().to_string()) };
    let entries = breaks
        .iter()
        .enumerate()
        .map(|(i, b)| {
            let props = vec![(Php::Str("price".into()), Php::Float(b.price.normalize().to_string())), (Php::Str("qty_break".into()), number(&b.qty, i == 0))];
            (Php::Int(i as i64), Php::Object("inventory_pricing_class".into(), props))
        })
        .collect();
    phpser::write(&Php::Array(entries))
}

/// fn_date(…, "mysql") of a stored date: `Y/m/d`, empty for the zero date.
fn stored_date(s: &str) -> String {
    match s.trim() {
        "" | "0" | "0000-00-00" => String::new(),
        d => d.replace('-', "/"),
    }
}

/// fn_date(…, "ymd"): the form shows `m/d/Y`.
fn shown_date(ymd: &str) -> String {
    let mut p = ymd.split('/');
    match (p.next(), p.next(), p.next()) {
        (Some(y), Some(m), Some(d)) => format!("{m}/{d}/{y}"),
        _ => String::new(),
    }
}

/// fn_date(…, "mdy") of the posted date: `Y/m/d`, or `None` when it is not a date.
fn posted_date(s: &str) -> Option<String> {
    let s = s.trim();
    if s.is_empty() {
        return Some(String::new());
    }
    let parts: Vec<u32> = s.split(['/', '-', '.', ' ']).map(|p| p.parse().ok()).collect::<Option<_>>()?;
    let [m, d, y] = parts[..] else { return None };
    chrono::NaiveDate::from_ymd_opt(y as i32, m, d).map(|_| format!("{y:04}/{m:02}/{d:02}"))
}

async fn read(pool: &SqlitePool, id: i64, sku: Option<&str>) -> sqlx::Result<Option<Item>> {
    let row = sqlx::query!(
        r#"SELECT id AS "id!: i64", sku, category_id, subcategory_id, documents, pricing, sort_order, new_date, competitor_sku, leadtime_option,
                  leadtime_text, active,
                  json_array(parameter0, parameter1, parameter2, parameter3, parameter4, parameter5, parameter6, parameter7, parameter8, parameter9,
                             parameter10, parameter11, parameter12, parameter13, parameter14, parameter15, parameter16, parameter17, parameter18,
                             parameter19) AS "parameters!: String",
                  json_array(nparameter0, nparameter1, nparameter2, nparameter3, nparameter4, nparameter5, nparameter6, nparameter7, nparameter8,
                             nparameter9, nparameter10, nparameter11, nparameter12, nparameter13, nparameter14, nparameter15, nparameter16,
                             nparameter17, nparameter18, nparameter19) AS "nparameters!: String"
           FROM inventory WHERE (?2 IS NULL AND id = ?1) OR sku = ?2"#,
        id,
        sku
    )
    .fetch_optional(pool)
    .await?;
    let Some(r) = row else { return Ok(None) };
    let list = |json: &str| -> Vec<Value> { serde_json::from_str(json).unwrap_or_default() };
    let parameter = list(&r.parameters).iter().map(|v| v.as_str().unwrap_or("").to_string()).collect();
    let nparameter = list(&r.nparameters).iter().map(|v| php_float(v.as_f64().unwrap_or(0.0))).collect();
    let documents = r.documents.as_deref().and_then(phpser::parse).map(|d| d.entries().iter().map(|(k, v)| (k.text(), v.clone())).collect()).unwrap_or_default();
    Ok(Some(Item {
        id: r.id,
        sku: r.sku,
        category_id: r.category_id.unwrap_or(0),
        subcategory_id: r.subcategory_id.unwrap_or(0),
        parameter,
        nparameter,
        documents,
        pricing: r.pricing.as_deref().map(pricing).unwrap_or_default(),
        sort_order: r.sort_order,
        new_date: stored_date(&r.new_date),
        competitor_sku: r.competitor_sku.filter(|c| !c.is_empty()).map(|c| c.split(',').map(str::to_string).collect()).unwrap_or_default(),
        leadtime_option: r.leadtime_option,
        leadtime_text: r.leadtime_text.unwrap_or_default(),
        active: r.active.unwrap_or(0) != 0,
    }))
}

/// A row of `content` for the part: a description of one document in one language, or a download.
#[derive(Default, Clone)]
struct Content {
    id: i64,
    language: String,
    item: String,
    name: String,
    description: String,
    filetype: String,
    file_name: String,
    mime: String,
    revised: i64,
    upload: Option<(String, Vec<u8>)>,
}

async fn contents(pool: &SqlitePool, id: i64) -> sqlx::Result<Vec<Content>> {
    let rows = sqlx::query!(
        r#"SELECT id AS "id!: i64", language_code, record_item, name, description, filetype, meta, revised FROM content
           WHERE record_type = 'inventory' AND record_id = ?1 ORDER BY id"#,
        id
    )
    .fetch_all(pool)
    .await?;
    Ok(rows
        .into_iter()
        .map(|r| {
            let meta: Value = r.meta.as_deref().and_then(|m| serde_json::from_str(m).ok()).unwrap_or_default();
            let meta_text = |k: &str| meta.get(k).and_then(Value::as_str).unwrap_or("").to_string();
            Content {
                id: r.id,
                language: r.language_code,
                item: r.record_item,
                name: r.name,
                description: r.description.unwrap_or_default(),
                filetype: r.filetype,
                file_name: meta_text("name"),
                mime: meta_text("mime"),
                revised: r.revised,
                upload: None,
            }
        })
        .collect())
}

fn find<'a>(rows: &'a [Content], language: &str, item: &str) -> Option<&'a Content> {
    rows.iter().find(|c| c.language == language && c.item == item)
}

/// file_meta_class::mime() and content_constant_class::filetype() by the file's suffix.
pub(super) fn file_kind(name: &str) -> (&'static str, &'static str) {
    let suffix = name.rsplit('.').next().unwrap_or("").to_ascii_lowercase();
    match suffix.as_str() {
        "txt" => ("text/plain", "html"),
        "htm" | "html" => ("text/html", "html"),
        "php" => ("text/html", ""),
        "css" => ("text/css", ""),
        "js" => ("application/javascript", ""),
        "json" => ("application/json", ""),
        "xml" => ("application/xml", "other"),
        "swf" => ("application/x-shockwave-flash", "other"),
        "flv" => ("video/x-flv", "other"),
        "png" => ("image/png", "image"),
        "jpe" | "jpeg" | "jpg" => ("image/jpeg", "image"),
        "gif" => ("image/gif", "image"),
        "bmp" => ("image/bmp", "image"),
        "ico" => ("image/vnd.microsoft.icon", "image"),
        "tiff" | "tif" => ("image/tiff", "image"),
        "svg" | "svgz" => ("image/svg+xml", "image"),
        "zip" => ("application/zip", "other"),
        "rar" => ("application/x-rar-compressed", "other"),
        "exe" | "msi" => ("application/x-msdownload", ""),
        "cab" => ("application/vnd.ms-cab-compressed", ""),
        "mp3" => ("audio/mpeg", "other"),
        "qt" | "mov" => ("video/quicktime", "other"),
        "pdf" => ("application/pdf", "pdf"),
        "psd" => ("image/vnd.adobe.photoshop", "other"),
        "ai" | "eps" | "ps" => ("application/postscript", "other"),
        "doc" | "docx" => ("application/msword", "other"),
        "rtf" => ("application/rtf", "other"),
        "xls" | "xlsx" => ("application/vnd.ms-excel", "other"),
        "ppt" | "pptx" => ("application/vnd.ms-powerpoint", "other"),
        "odt" => ("application/vnd.oasis.opendocument.text", "other"),
        "ods" => ("application/vnd.oasis.opendocument.spreadsheet", "other"),
        _ => ("application/octet-stream", ""),
    }
}

pub(super) async fn languages(pool: &SqlitePool) -> sqlx::Result<Vec<(String, String)>> {
    let rows = sqlx::query!("SELECT code, name FROM language WHERE active = 1 ORDER BY priority, name COLLATE NOCASE").fetch_all(pool).await?;
    Ok(rows.into_iter().map(|r| (r.code, r.name)).collect())
}

/// competitor_sku(): one SKU per line, upper-cased, at least three characters, no commas; sorted and unique.
fn competitor_skus(input: &str) -> (Vec<String>, Vec<&'static str>) {
    let mut errors = Vec::new();
    if input.contains(',') {
        errors.push("Comma not allowed in competitor SKU");
    }
    let mut skus: Vec<String> = Vec::new();
    let mut short = false;
    for item in input.split('\n') {
        let item = item.trim().to_uppercase();
        if item.is_empty() {
            continue;
        }
        short |= item.chars().count() < 3;
        if !skus.contains(&item) {
            skus.push(item);
        }
    }
    if short {
        errors.push("Must be at least 3 characters");
    }
    skus.sort();
    (skus, errors)
}

/// The posted form into the part. Returns the posted descriptions and downloads to save with it.
fn take_posted(item: &mut Item, sub: Option<&Subcategory>, posted: &Posted, langs: &[(String, String)], stored: &[Content], parameters: bool, notes: &mut Notes) -> Vec<Content> {
    let f = &posted.fields;
    item.subcategory_id = f.int("subcategory_id");
    if item.subcategory_id == 0 {
        notes.error("subcategory_id", None);
    }
    item.category_id = sub.map_or(0, |s| s.category_id);
    item.sku = posted_text(f.get("sku")).to_uppercase();
    item.sort_order = posted_number(f.get("sort_order"), 0).trunc().try_into().unwrap_or(0);
    let today = time::date("Y/m/d", unix_now());
    match posted_date(f.get("new_date")) {
        Some(d) if d.is_empty() || d <= today => item.new_date = d,
        Some(d) => {
            item.new_date = d;
            notes.error("new_date", None);
        }
        None => notes.error("new_date", None),
    }
    item.leadtime_option = f.get("leadtime_option").to_string();
    item.leadtime_text = if item.leadtime_option == "Weeks" { f.get("leadtime_text").trim().to_string() } else { String::new() };
    if item.leadtime_option == "Weeks" && item.leadtime_text.is_empty() {
        notes.error("leadtime_text", None);
    }
    item.active = f.checked("active");
    let (skus, errors) = competitor_skus(f.get("competitor_sku"));
    item.competitor_sku = skus;
    if !errors.is_empty() {
        notes.error_html("competitor_sku", errors.join("<br>"));
    }
    item.pricing.clear();
    for i in 0..BREAKS {
        let (qty_field, price_field) = (field(&["qty_break", &i.to_string()]), field(&["price", &i.to_string()]));
        let price = posted_number(f.get(&price_field), 2);
        if price.is_zero() {
            continue;
        }
        let qty = match item.pricing.last() {
            None => Decimal::ZERO,
            Some(last) => {
                let qty = posted_number(f.get(&qty_field), 0);
                if qty <= last.qty {
                    notes.error(&qty_field, None);
                }
                if price > last.price {
                    notes.error(&price_field, None);
                }
                qty
            }
        };
        item.pricing.push(Break { qty, price });
    }
    let mut out = Vec::new();
    item.documents.clear();
    for (code, _) in langs {
        for (doc, _) in DOCUMENTS {
            let mut c = find(stored, code, doc).cloned().unwrap_or_else(|| Content { language: code.clone(), item: doc.into(), ..Content::default() });
            c.description = f.get(&field(&[doc, "description", code])).trim().to_string();
            if code == "EN" {
                let id_field = field(&[doc, "document_id"]);
                item.documents.push((doc.to_string(), if f.has(&id_field) { Php::Str(f.get(&id_field).to_string()) } else { Php::Null }));
            }
            out.push(c);
        }
    }
    item.parameter = vec![String::new(); PARAMETERS];
    item.nparameter = vec![String::new(); PARAMETERS];
    if !parameters {
        return out;
    }
    if item.sku.is_empty() {
        notes.error("sku", None);
    }
    for p in sub.map(|s| s.parameters.as_slice()).unwrap_or_default().iter().filter(|p| p.key < PARAMETERS) {
        item.parameter[p.key] = f.get(&field(&["parameter", &p.key.to_string()])).trim().to_string();
        if p.numeric {
            item.nparameter[p.key] = p.output(f.get(&field(&["nparameter", &p.key.to_string()])).trim());
        }
    }
    let labels = sub.and_then(|s| s.download.clone()).unwrap_or_default();
    for i in 0..DOWNLOADS {
        let record_item = format!("download:{i}");
        let mut c = find(stored, "EN", &record_item).cloned().unwrap_or_else(|| Content { language: "EN".into(), item: record_item.clone(), ..Content::default() });
        c.name = labels.get(i).cloned().unwrap_or_default();
        let file_field = field(&["download", "file", &i.to_string()]);
        let upload: Option<&Upload> = posted.uploads.iter().find(|u| u.field == file_field && !u.file_name.is_empty());
        if c.name.is_empty() || f.checked(&field(&["download", "delete", &i.to_string()])) {
            c.name.clear();
        } else if let Some(u) = upload {
            if u.bytes.is_empty() {
                notes.error(&file_field, Some("Empty file"));
            } else {
                let file_name: String = u.file_name.chars().map(|ch| if matches!(ch, ' ' | '?' | '/' | '"') { '_' } else { ch }).collect();
                let (mime, filetype) = file_kind(&file_name);
                c.mime = mime.into();
                c.filetype = filetype.into();
                c.file_name = file_name.clone();
                c.upload = Some((file_name, u.bytes.to_vec()));
            }
        }
        out.push(c);
    }
    out
}

async fn save(pool: &SqlitePool, item: &Item, rows: &[Content]) -> sqlx::Result<Result<i64, String>> {
    let taken = sqlx::query_scalar!(r#"SELECT 1 AS "x!: i64" FROM inventory WHERE sku = ?1 AND id <> ?2"#, item.sku, item.id).fetch_optional(pool).await?;
    if taken.is_some() {
        return Ok(Err(format!("Duplicate entry '{}' for key 'sku'", item.sku)));
    }
    let params = serde_json::to_string(&item.parameter).unwrap_or_default();
    let nparams = serde_json::to_string(&item.nparameter.iter().map(|n| catalog::php_floatval(n)).collect::<Vec<f64>>()).unwrap_or_default();
    let documents = phpser::write(&Php::Array(item.documents.iter().map(|(k, v)| (Php::Str(k.clone()), v.clone())).collect()));
    let pricing = pricing_text(&item.pricing);
    let new_date = if item.new_date.is_empty() { "0000-00-00".to_string() } else { item.new_date.replace('/', "-") };
    let competitor = item.competitor_sku.join(",");
    let active = i64::from(item.active);
    let mut tx = pool.begin().await?;
    let id: i64 = if item.id == 0 {
        sqlx::query_scalar!(r#"INSERT INTO inventory (sku) VALUES (?1) RETURNING id AS "id!: i64""#, item.sku).fetch_one(&mut *tx).await?
    } else {
        item.id
    };
    sqlx::query!(
        r#"UPDATE inventory SET sku = ?2, category_id = ?3, subcategory_id = ?4, documents = ?5, pricing = ?6, sort_order = ?7, new_date = ?8,
                  competitor_sku = ?9, leadtime_option = ?10, leadtime_text = ?11, active = ?12,
                  parameter0 = json_extract(?13, '$[0]'), parameter1 = json_extract(?13, '$[1]'), parameter2 = json_extract(?13, '$[2]'),
                  parameter3 = json_extract(?13, '$[3]'), parameter4 = json_extract(?13, '$[4]'), parameter5 = json_extract(?13, '$[5]'),
                  parameter6 = json_extract(?13, '$[6]'), parameter7 = json_extract(?13, '$[7]'), parameter8 = json_extract(?13, '$[8]'),
                  parameter9 = json_extract(?13, '$[9]'), parameter10 = json_extract(?13, '$[10]'), parameter11 = json_extract(?13, '$[11]'),
                  parameter12 = json_extract(?13, '$[12]'), parameter13 = json_extract(?13, '$[13]'), parameter14 = json_extract(?13, '$[14]'),
                  parameter15 = json_extract(?13, '$[15]'), parameter16 = json_extract(?13, '$[16]'), parameter17 = json_extract(?13, '$[17]'),
                  parameter18 = json_extract(?13, '$[18]'), parameter19 = json_extract(?13, '$[19]'),
                  nparameter0 = json_extract(?14, '$[0]'), nparameter1 = json_extract(?14, '$[1]'), nparameter2 = json_extract(?14, '$[2]'),
                  nparameter3 = json_extract(?14, '$[3]'), nparameter4 = json_extract(?14, '$[4]'), nparameter5 = json_extract(?14, '$[5]'),
                  nparameter6 = json_extract(?14, '$[6]'), nparameter7 = json_extract(?14, '$[7]'), nparameter8 = json_extract(?14, '$[8]'),
                  nparameter9 = json_extract(?14, '$[9]'), nparameter10 = json_extract(?14, '$[10]'), nparameter11 = json_extract(?14, '$[11]'),
                  nparameter12 = json_extract(?14, '$[12]'), nparameter13 = json_extract(?14, '$[13]'), nparameter14 = json_extract(?14, '$[14]'),
                  nparameter15 = json_extract(?14, '$[15]'), nparameter16 = json_extract(?14, '$[16]'), nparameter17 = json_extract(?14, '$[17]'),
                  nparameter18 = json_extract(?14, '$[18]'), nparameter19 = json_extract(?14, '$[19]')
           WHERE id = ?1"#,
        id, item.sku, item.category_id, item.subcategory_id, documents, pricing, item.sort_order, new_date, competitor, item.leadtime_option,
        item.leadtime_text, active, params, nparams
    )
    .execute(&mut *tx)
    .await?;
    let now = unix_now();
    for c in rows {
        if c.name.is_empty() && c.description.is_empty() {
            if c.id != 0 {
                sqlx::query!("DELETE FROM content WHERE id = ?1", c.id).execute(&mut *tx).await?;
            }
            continue;
        }
        let meta = serde_json::json!({"name": (!c.file_name.is_empty()).then_some(&c.file_name), "mime": (!c.mime.is_empty()).then_some(&c.mime),
            "width": 0, "height": 0, "type": 0, "dimensions": null, "bits": 0, "channels": 0})
        .to_string();
        let content_id = if c.id == 0 {
            sqlx::query_scalar!(
                r#"INSERT INTO content (record_type, record_id, record_item, language_code) VALUES ('inventory', ?1, ?2, ?3) RETURNING id AS "id!: i64""#,
                id,
                c.item,
                c.language
            )
            .fetch_one(&mut *tx)
            .await?
        } else {
            c.id
        };
        sqlx::query!("UPDATE content SET name = ?2, description = ?3, revised = ?4 WHERE id = ?1", content_id, c.name, c.description, now).execute(&mut *tx).await?;
        if let Some((_, bytes)) = &c.upload {
            sqlx::query!("UPDATE content SET content = ?2, filetype = ?3, meta = ?4 WHERE id = ?1", content_id, bytes, c.filetype, meta).execute(&mut *tx).await?;
        }
    }
    tx.commit().await?;
    Ok(Ok(id))
}

async fn delete(pool: &SqlitePool, id: i64) -> sqlx::Result<()> {
    let mut tx = pool.begin().await?;
    sqlx::query!("DELETE FROM inventory WHERE id = ?1", id).execute(&mut *tx).await?;
    sqlx::query!("DELETE FROM content WHERE record_type = 'inventory' AND record_id = ?1", id).execute(&mut *tx).await?;
    tx.commit().await
}

async fn sku_tab(pool: &SqlitePool, form: &Form<'_>, item: &Item, sub: Option<&Subcategory>, record_id: &str) -> sqlx::Result<Vec<String>> {
    let groups = catalog::groups(pool).await?;
    let onchange = Opts::new().onchange(format!("fn_action(this,'subcategory',{},'');", js(record_id)));
    let mut out = vec!["<table class='standard noborder'>".to_string(), "<tr>".into(), "<td width=30%>Category/Subcategory</td>".into()];
    out.push(format!("<td width=70%>{}</td>", form.optgroup("subcategory_id", &groups, &item.subcategory_id.to_string(), true, onchange)));
    out.push("</tr>".into());
    let mut row = |label: &str, widget: String| out.extend(["<tr>".to_string(), format!("<td>{label}</td>"), format!("<td>{widget}</td>"), "</tr>".into()]);
    row("SKU", form.text("sku", &item.sku, 40, 40, Opts::new()));
    row("Sort Order (0-999999)", form.text("sort_order", &item.sort_order.to_string(), 6, 6, Opts::new()));
    row("New Date", form.date("new_date", &shown_date(&item.new_date), Opts::new()));
    let leadtime = form.select("leadtime_option", &list_options(&LEADTIME), &item.leadtime_option, Blank::None, Opts::new().onchange("fn_leadtime_option();"));
    let weeks = form.text("leadtime_text", &item.leadtime_text, 8, 8, Opts::new());
    row("Lead Time", format!("{leadtime} <span id='leadtime_text_span'>{}</span>\n", weeks.trim_end()));
    row("Active?", form.checkbox("active", "1", item.active, Opts::new()));
    out.push("</table>".into());
    let Some(sub) = sub.filter(|s| !s.parameters.is_empty()) else { return Ok(out) };
    out.push("<table class='standard noborder'>".into());
    out.push(format!("<caption class='left'>{}</caption>\n", text(&format!("{}: {}", sub.category_name, sub.name))));
    out.extend(["<tr>", "<td width='30%'>Parameter</td>", "<td width='40%'>Alpha value</td>", "<td width='30%'>Numeric value</td>", "</tr>"].map(String::from));
    for p in &sub.parameters {
        let value = |v: &Vec<String>| v.get(p.key).cloned().unwrap_or_default();
        out.push("<tr>".into());
        out.push(format!("<td>{}</td>", text(&p.name)));
        out.push(format!("<td>{}</td>", form.text(&field(&["parameter", &p.key.to_string()]), &value(&item.parameter), 40, 80, Opts::new())));
        let numeric = if p.numeric { form.text(&field(&["nparameter", &p.key.to_string()]), &value(&item.nparameter), 20, 20, Opts::new()) } else { "&nbsp;".into() };
        out.push(format!("<td>{numeric}</td>"));
        out.push("</tr>".into());
    }
    out.push("</table>".into());
    Ok(out)
}

fn pricing_tab(form: &Form<'_>, item: &Item) -> Vec<String> {
    let mut out: Vec<String> = ["<table class='standard border'>", "<tr>", "<th>Break Point</th>", "<th>Quantity</th>", "<th>Price</th>", "</tr>"].map(String::from).to_vec();
    for i in 0..BREAKS {
        let b = item.pricing.get(i);
        let label = if i == 0 { " Base Price".to_string() } else { format!("{i}.") };
        let qty = if i == 0 { String::new() } else { form.text(&field(&["qty_break", &i.to_string()]), &b.map(|b| amount(&b.qty, 0)).unwrap_or_default(), 10, 10, Opts::new()) };
        let price = form.text(&field(&["price", &i.to_string()]), &b.map(|b| amount(&b.price, 4)).unwrap_or_default(), 10, 10, Opts::new());
        out.extend(["<tr>".to_string(), format!("<td class=center>{label}</td>"), format!("<td class=center>{qty}</td>"), format!("<td class=center>${price}</td>"), "</tr>".into()]);
    }
    out.push("</table>".into());
    out
}

fn competitor_tab(form: &Form<'_>, item: &Item) -> Vec<String> {
    let mut out = vec!["<p>Enter each SKU on a separate line.<br>SKU must be at least 3 characters long and not contain a comma</p>".to_string()];
    if let Some(e) = form.notes.error_text("competitor_sku") {
        out.push(format!("<p>{e}</p>"));
    }
    out.push(form.textarea_lines("competitor_sku", &item.competitor_sku, 10, 40, Opts::new()));
    out
}

fn language_tab(form: &Form<'_>, item: &Item, code: &str, descriptions: &[Content], documents: &[super::html::OptGroup]) -> Vec<String> {
    let mut out = vec!["<table class='standard noborder'>".to_string()];
    for (doc, name) in DOCUMENTS {
        out.push(format!("<tr><td colspan=2><br><b>{name}</b></td></tr>"));
        if code == "EN" {
            let select = form.optgroup(&field(&[doc, "document_id"]), documents, &item.document(doc), true, Opts::new());
            out.extend(["<tr>".to_string(), "<td width='30%'>Document ID</td>".into(), format!("<td width='70%'>{select}</td>"), "</tr>".into()]);
        }
        let description = find(descriptions, code, doc).map(|c| c.description.clone()).unwrap_or_default();
        let area = form.textarea(&field(&[doc, "description", code]), &description, 6, 60, Opts::new());
        out.extend(["<tr>".to_string(), "<td width='30%'>Description</td>".into(), format!("<td width='70%'>{area}</td>"), "</tr>".into()]);
    }
    out.push("</table>".into());
    out
}

fn download_tab(form: &Form<'_>, sub: Option<&Subcategory>, rows: &[Content]) -> Vec<String> {
    let Some(labels) = sub.and_then(|s| s.download.as_ref()).filter(|d| !d.is_empty()) else { return Vec::new() };
    let mut out = vec!["<table class='standard noborder'>".to_string()];
    for (i, label) in labels.iter().enumerate().take(DOWNLOADS).filter(|(_, l)| !l.is_empty()) {
        let mut cell = vec![form.file(&field(&["download", "file", &i.to_string()]), 40, Opts::new())];
        if let Some(c) = find(rows, "EN", &format!("download:{i}")).filter(|c| !c.name.is_empty()) {
            cell.push(format!("Name: {}", text(&c.file_name)));
            cell.push(format!("File Type: {}", text(&c.filetype)));
            cell.push(format!("Mime: {}", text(&c.mime)));
            cell.push(format!("Revised: {}", time::date("m/d/Y h:iA", c.revised)));
            cell.push(format!("Delete? {}", form.checkbox(&field(&["download", "delete", &i.to_string()]), "1", false, Opts::new())));
        }
        out.extend(["<tr>".to_string(), format!("<td width='40%'>{}. {}</td>", i + 1, text(label)), format!("<td width='60%'>{}</td>", cell.join("<br>")), "</tr>".into()]);
    }
    out.push("</table>".into());
    out
}

async fn list(pool: &SqlitePool, form: &Form<'_>, subcategory_id: i64) -> sqlx::Result<String> {
    let sub = catalog::read(pool, subcategory_id).await?;
    let parameters = sub.map(|s| s.parameters).unwrap_or_default();
    let mut out = String::from("<table class='small border'><caption >&nbsp;</caption>\n<tr><th>SKU</th>");
    for p in &parameters {
        out.push_str(&format!("<th>{}</th>", text(&p.name)));
    }
    out.push_str("<th>Del?</th></tr>");
    out.push_str(&format!("<tr><td colspan={}>{}</td></tr>", 2 + parameters.len(), href("Add new", "javascript:fn_action(this,'maintain','0','');", Opts::new())));
    let rows = sqlx::query!(
        r#"SELECT id AS "id!: i64", sku, active,
                  json_array(parameter0, parameter1, parameter2, parameter3, parameter4, parameter5, parameter6, parameter7, parameter8, parameter9,
                             parameter10, parameter11, parameter12, parameter13, parameter14, parameter15, parameter16, parameter17, parameter18,
                             parameter19) AS "parameters!: String"
           FROM inventory WHERE subcategory_id = ?1 ORDER BY sort_order, sku"#,
        subcategory_id
    )
    .fetch_all(pool)
    .await?;
    for r in rows {
        let values: Vec<Value> = serde_json::from_str(&r.parameters).unwrap_or_default();
        let id = js(&r.id.to_string());
        out.push_str(&format!("<tr><td>{}</td>", href(&text(&r.sku), &format!("javascript:fn_action(this,'maintain',{id},'');"), Opts::new())));
        for p in &parameters {
            out.push_str(&format!("<td>{}</td>", text(values.get(p.key).and_then(Value::as_str).unwrap_or(""))));
        }
        let del = if r.active.unwrap_or(0) != 0 {
            String::new()
        } else {
            form.radio("delete", &r.id.to_string(), false, Opts::new().onclick(format!("fn_action(this,'delete',{id},{});", js(&r.sku))))
        };
        out.push_str(&format!("<td class=center>{del}</td></tr>"));
    }
    out.push_str("</table>");
    Ok(out)
}

const SCRIPT: &str = r##"<script>
jQuery(document).ready(function() {
    fn_leadtime_option();
});
function fn_leadtime_option() {
    if (jQuery("#leadtime_option").val() == "Weeks") {
    	jQuery("#leadtime_text_span").show();
	  } else {
    	jQuery("#leadtime_text_span").hide();
	}
}
function fn_action(obj,action,id,name) {
	if (action=='delete') {
	    if (!confirm("Delete: SKU# " + name)) {
	        obj.checked=false;
            return;
	    }
    }
	document.scs_form.action.value=action;
	document.scs_form.record_id.value=id;
	document.scs_form.submit();
}
</script>
"##;

async fn run(pool: &SqlitePool, staff: &Staff<Administrator>, posted: &Posted) -> sqlx::Result<Response> {
    let f = &posted.fields;
    let mut notes = Notes::new(TITLE, "");
    let mut action = Action::parse(f.get("action"));
    let report_subcategory = f.get("report_subcategory_id").to_string();
    let wanted = f.get("ajax_inventory_sku").trim().to_uppercase();
    let wanted = wanted.split('|').next().unwrap_or("").to_string();
    let mut record_id = f.get("record_id").to_string();
    let mut item = None;
    if !wanted.is_empty() {
        match read(pool, 0, Some(&wanted)).await? {
            Some(found) => {
                action = Action::Maintain;
                record_id = found.id.to_string();
                item = Some(found);
            }
            None => notes.error("ajax_inventory_sku", None),
        }
    }
    let id = super::csrf::php_intval(&record_id);
    let mut item = match item {
        Some(i) => i,
        None if id != 0 => read(pool, id, None).await?.unwrap_or_else(Item::new),
        None => Item::new(),
    };
    let langs = languages(pool).await?;
    let mut stored = if item.id != 0 { contents(pool, item.id).await? } else { Vec::new() };
    let mut edit = false;
    match action {
        Action::None | Action::List => {}
        Action::Maintain => {
            if item.id == 0 {
                item.subcategory_id = super::csrf::php_intval(&report_subcategory);
            }
            edit = true;
        }
        Action::Delete => {
            delete(pool, item.id).await?;
            notes.say(&format!("SKU {} deleted", item.sku));
        }
        Action::Cancel => notes.say("Request cancelled"),
        Action::Subcategory | Action::Update => {
            let sub = catalog::read(pool, f.int("subcategory_id")).await?;
            let full = action == Action::Update;
            let rows = take_posted(&mut item, sub.as_ref(), posted, &langs, &stored, full, &mut notes);
            if full && !notes.has_errors() {
                match save(pool, &item, &rows).await? {
                    Ok(_) => notes.say("Record maintained"),
                    Err(e) => {
                        notes.error("sku", None);
                        notes.say(&e);
                    }
                }
            }
            edit = !full || notes.has_errors();
            if edit {
                let kept: Vec<Content> = stored.into_iter().filter(|c| !rows.iter().any(|r| r.language == c.language && r.item == c.item)).collect();
                stored = rows.into_iter().chain(kept).collect();
            }
        }
    }
    let form = Form { notes: &notes };
    let mut body = notes.render();
    body.push_str(SCRIPT);
    body.push_str(&open(PATH, staff.csrf.as_str(), true));
    body.push_str(&hidden("action", ""));
    body.push_str(&hidden("record_id", ""));
    body.push_str(&hidden("page", ""));
    if edit {
        body.push_str(&hidden("report_subcategory_id", &report_subcategory));
        let sub = catalog::read(pool, item.subcategory_id).await?;
        let documents = choices::documents(pool).await?;
        let mut tabs = Tabs::new();
        tabs.in_wordpress(staff.wp);
        tabs.lines("SKU", &sku_tab(pool, &form, &item, sub.as_ref(), f.get("record_id")).await?);
        tabs.lines("Pricing", &pricing_tab(&form, &item));
        tabs.lines("Competitors", &competitor_tab(&form, &item));
        for (code, name) in &langs {
            tabs.lines(name, &language_tab(&form, &item, code, &stored, &documents));
        }
        tabs.lines("Download", &download_tab(&form, sub.as_ref(), &stored));
        body.push_str(&tabs.render());
        let update = button("Update", Opts::new().onclick(format!("fn_action(this,'update',{},'');", js(&item.id.to_string()))));
        let cancel = button("Cancel", Opts::new().onclick("fn_action(this,'cancel','','');"));
        body.push_str(&format!("<p class='standard'>{update} {cancel}</p>"));
    } else {
        let go = button("Go!", Opts::new().onclick("fn_action(this,'list','','');"));
        body.push_str(&format!("<table class='large noborder'><tr><td width='30%'>SKU</td><td width='70%'>{} {go}</td></tr>", form.text("ajax_inventory_sku", &wanted, 40, 40, Opts::new())));
        let groups = catalog::groups(pool).await?;
        let select = form.optgroup("report_subcategory_id", &groups, &report_subcategory, true, Opts::new().onchange("fn_action(this,'list','','');"));
        body.push_str(&format!("<tr><td>Category/Subcategory</td><td>{select}</td></tr></table>"));
        let subcategory_id = super::csrf::php_intval(&report_subcategory);
        if subcategory_id != 0 {
            body.push_str(&list(pool, &form, subcategory_id).await?);
        }
    }
    body.push_str(close());
    Ok(shell::page(pool, staff.view(), TITLE, &body).await)
}

async fn show(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>) -> Response {
    let nothing = Posted { fields: Fields::default(), uploads: Vec::new() };
    run(&auth.pool, &staff, &nothing).await.unwrap_or_else(shell::internal)
}

async fn post(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, form: Unchecked) -> Response {
    match form.accept_by_action() {
        Ok(posted) => run(&auth.pool, &staff, &posted).await.unwrap_or_else(shell::internal),
        Err(r) => r.into_response(),
    }
}

pub fn routes() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(show).post(post))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn field_names_are_base64_of_the_tab_joined_parts() {
        assert_eq!(field(&["price", "0"]), "cHJpY2UJMA==");
        assert_eq!(field(&["overview", "document_id"]), "b3ZlcnZpZXcJZG9jdW1lbnRfaWQ=");
    }

    #[test]
    fn pricing_round_trips_and_formats_like_number_format() {
        let stored = r#"a:2:{i:0;O:23:"inventory_pricing_class":2:{s:5:"price";d:1284.92;s:9:"qty_break";i:0;}i:1;O:23:"inventory_pricing_class":2:{s:5:"price";d:245.7;s:9:"qty_break";d:2;}}"#;
        let breaks = pricing(stored);
        assert_eq!(pricing_text(&breaks), stored);
        assert_eq!(amount(&breaks[0].price, 4), "1,284.9200");
        assert_eq!(amount(&breaks[1].qty, 0), "2");
        assert_eq!(amount(&Decimal::ZERO, 4), "");
        assert_eq!(posted_number("1,284.925", 2), Decimal::from_str("1284.93").unwrap());
    }

    #[test]
    fn competitor_skus_are_sorted_unique_and_checked() {
        let (skus, errors) = competitor_skus("bb-200\nAA-100\n\nbb-200\nx\n");
        assert_eq!(skus, ["AA-100", "BB-200", "X"]);
        assert_eq!(errors, ["Must be at least 3 characters"]);
        assert_eq!(competitor_skus("a,b").1, ["Comma not allowed in competitor SKU"]);
    }

    #[test]
    fn dates_move_between_the_form_and_the_table() {
        assert_eq!(posted_date("3/5/2020").as_deref(), Some("2020/03/05"));
        assert_eq!(posted_date("13/45/2020"), None);
        assert_eq!(posted_date("").as_deref(), Some(""));
        assert_eq!(shown_date(&stored_date("2020-03-25")), "03/25/2020");
        assert_eq!(stored_date("0000-00-00"), "");
    }
}
