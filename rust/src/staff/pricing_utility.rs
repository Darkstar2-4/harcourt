//! pricing_utility.php: the price tables of the smart-SKU subcategories (smart.rs). An Administrator picks a smart
//! subcategory and either explains its record types (how many rows each holds, then one type's rows), downloads its
//! rows, or uploads a file of them to review or apply. The page's script asks for a subcategory's record types by
//! AJAX.
//!
//! An upload is reviewed and applied with the import/export pages' machinery (utility.rs); the PHP's own map never
//! saw function "update", so it said "Review complete" after writing and logged nothing.

use std::sync::Arc;

use axum::{
    extract::{RawQuery, State},
    http::{HeaderMap, StatusCode},
    response::{IntoResponse, Response},
    routing::get,
    Router,
};
use serde_json::Value;
use sqlx::SqlitePool;

use super::{
    catalog,
    csrf::{php_intval, Fields, Posted, Unchecked},
    html::{button, close, hidden, open, text, Blank, Form, Notes, Opts, Tabs},
    map::{self, Carried, Export, Field, FileType, Kind, Map, Record},
    shell,
    smart::{self, Module, RecordType},
    time,
    utility::{self, Errors, Line, Target},
    Staff,
};
use crate::{
    api::{Administrator, Auth},
    db::unix_now,
};

const PATH: &str = "/pricing_utility.php";
const TITLE: &str = "Smart SKU Pricing Utility";
const PRICES: usize = 5;
const YELLOW: &str = "style='background-color: #ffff00;'";

const SCRIPT: &str = r##"<script>
jQuery(document).ready(function() {
    utility_screen();
});
function fn_action(action) {
	if ( (action=='update') && (!confirm("Continue?")) ) return;
	document.scs_form.action.value=action;
	document.scs_form.submit();
}
function fn_record_type(record_type) {
    jQuery("#action").val("prelist");
    jQuery("#record_type").val(record_type);
	document.scs_form.submit();
}
function fn_subcategory() {
	jQuery.ajax({
	    data: {action: "ajax", subcategory_id: jQuery("#subcategory_id").val() },
	    dataType: 'json',
	    success:function(data){
			jQuery('#record_type_td').html(data['html']);
	   }
	});
	utility_screen();
}
function utility_screen() {
	var upload_class=0;
    var download_class=0;
    switch (jQuery("#function").val()) {
      case "download":
      	download_class=1;
        break;
      case "review":
      case "upload":
      	upload_class=1;
        break;
    }
    if (jQuery("#subcategory_id").val()) {
    	jQuery("#record_type_tr").show();
	  } else {
    	jQuery("#record_type_tr").hide();
    }
	if (upload_class) {
    	jQuery(".upload_class").show();
	  } else {
    	jQuery(".upload_class").hide();
	}
	if (download_class) {
    	jQuery(".download_class").show();
	  } else {
    	jQuery(".download_class").hide();
	}
}
</script>
"##;

/// What the form asks (`$forms->report`): action, function and subcategory from the query or the form, the rest
/// from the form only.
struct Report {
    action: String,
    function: String,
    subcategory_id: String,
    record_type: String,
    file_type: String,
}

/// The chosen subcategory as `$database->subcategory` and `$menu->configure` held it.
struct Chosen {
    name: String,
    module: Option<&'static Module>,
}

impl Chosen {
    fn record_type(&self, code: &str) -> Option<&'static RecordType> {
        self.module.and_then(|m| m.record_type(code))
    }
}

async fn chosen(pool: &SqlitePool, id_text: &str) -> sqlx::Result<Option<Chosen>> {
    let id = php_intval(id_text);
    let name = sqlx::query_scalar!(r#"SELECT COALESCE(name, '') AS "name!: String" FROM subcategory WHERE id = ?1"#, id).fetch_optional(pool).await?;
    Ok(name.map(|name| Chosen { name, module: smart::module(id) }))
}

/// select_record_type(): the module's record types, or nothing without one.
fn record_types(form: &Form<'_>, chosen: Option<&Chosen>, selected: &str) -> String {
    let Some(module) = chosen.and_then(|c| c.module) else { return String::new() };
    let options: Vec<(String, String)> = module.record_types.iter().map(|r| (r.code.to_string(), text(r.name))).collect();
    form.select("record_type", &options, selected, Blank::Empty, Opts::new())
}

/// pricing->map(): the columns of a pricing file. Given a record type, its options and prices name the code and
/// price columns and the ones it does not use go.
fn pricing_map(record_type: Option<&RecordType>) -> Map {
    let mut m = Map::new();
    m.add(Field::new("subcategory_id", Kind::Lowercase).required());
    m.add(Field::new("record_type", Kind::Lowercase).required());
    for i in 0..PRICES {
        let field = Field::new(format!("code{i}"), Kind::Uppercase).required();
        match record_type.map(|r| r.fields.get(i)) {
            None => m.add(field),
            Some(Some(name)) => m.add(field.name(*name)),
            Some(None) => {}
        }
    }
    for i in 0..PRICES {
        let field = Field::new(format!("price{i}"), Kind::Decimal(2)).required();
        match record_type.map(|r| r.prices.get(i)) {
            None => m.add(field),
            Some(Some(name)) if name.to_lowercase().contains("price") => m.add(field.name(*name)),
            Some(Some(name)) => m.add(field.name(format!("{name} price"))),
            Some(None) => {}
        }
    }
    m
}

struct PricingRow {
    record_type: String,
    options: String,
    price_type: String,
    prices: [f64; PRICES],
}

async fn rows(pool: &SqlitePool, subcategory_id: &str, record_type: &str) -> sqlx::Result<Vec<PricingRow>> {
    let rows = sqlx::query!(
        r#"SELECT record_type, options, price_type, price0, price1, price2, price3, price4 FROM pricing
           WHERE subcategory_id = ?1 AND (?2 = '' OR record_type = ?2) ORDER BY record_type, options"#,
        subcategory_id,
        record_type
    )
    .fetch_all(pool)
    .await?;
    Ok(rows
        .into_iter()
        .map(|r| PricingRow { record_type: r.record_type, options: r.options, price_type: r.price_type, prices: [r.price0, r.price1, r.price2, r.price3, r.price4] })
        .collect())
}

fn options(stored: &str) -> Vec<(String, String)> {
    form_urlencoded::parse(stored.as_bytes()).into_owned().collect()
}

fn option<'a>(options: &'a [(String, String)], name: &str) -> &'a str {
    options.iter().find(|(k, _)| k == name).map_or("", |(_, v)| v.as_str())
}

/// The explain tab: each record type of the module, its rows and what its codes and prices are.
async fn summary(pool: &SqlitePool, chosen: &Chosen, subcategory_id: &str) -> sqlx::Result<Vec<String>> {
    let counts = sqlx::query!(
        r#"SELECT record_type, count(*) AS "n!: i64",
                  (SELECT p2.update_timestamp FROM pricing p2 WHERE p2.subcategory_id = p.subcategory_id AND p2.record_type = p.record_type
                   ORDER BY p2.options LIMIT 1) AS "stamp!: i64"
           FROM pricing p WHERE subcategory_id = ?1 GROUP BY record_type ORDER BY record_type"#,
        subcategory_id
    )
    .fetch_all(pool)
    .await?;
    let stamp = counts.last().map_or(0, |c| c.stamp);
    let updated = if stamp != 0 { time::date("F j, Y g:i A", stamp) } else { "Never".into() };
    let mut out = vec![
        "<table class='small border'>".to_string(),
        format!("<caption class='standard left'><br>Last update: {updated}</caption>"),
        "<tr>".into(),
        "<th>Record Type</th>".into(),
        "<th>Name</th>".into(),
        "<th># Records</th>".into(),
        "<th>Codes</th>".into(),
        "<th>Price(s)</th>".into(),
        "</tr>".into(),
    ];
    for r in chosen.module.map_or(&[][..], |m| m.record_types) {
        let n = counts.iter().find(|c| c.record_type == r.code).map_or(0, |c| c.n);
        let codes: Vec<String> = r.fields.iter().enumerate().map(|(i, f)| format!("code{i}: {f}")).collect();
        let prices: Vec<String> = r.prices.iter().enumerate().map(|(i, p)| format!("price{i}: {p}")).collect();
        out.push(format!("<tr {}>", if n == 0 { YELLOW } else { "" }));
        out.push(format!("<td><a href=\"javascript:fn_record_type({});\">{}</a></td>", super::html::js(r.code), r.code));
        out.push(format!("<td>{}</td>", r.name));
        out.push(format!("<td class=center>{}</td>", super::html::number(n)));
        out.push(format!("<td>{}</td>", codes.join("<br>")));
        out.push(format!("<td>{}</td>", prices.join("<br>")));
        out.push("</tr>".into());
    }
    out.push("</table>".into());
    Ok(out)
}

/// The rows of one record type: each option set and its prices, a missing option marked.
async fn detail(pool: &SqlitePool, subcategory_id: &str, r: &RecordType) -> sqlx::Result<Vec<String>> {
    let price_count = r.prices.len();
    let mut out = vec![
        "<table class='standard border tablesorter'>".to_string(),
        format!("<caption class=left><br>Record type: <b>{}</b></caption>", r.code),
        "<thead>".into(),
        "<tr>".into(),
    ];
    if !r.fields.is_empty() {
        out.push(format!("<th colspan={}>Code(s)</th>", r.fields.len()));
    }
    out.push(format!("<th colspan={price_count}>Price(s)</th>"));
    out.push("</tr>".into());
    out.push("<tr>".into());
    out.extend(r.fields.iter().map(|f| format!("<th>{f}</th>")));
    out.extend(r.prices.iter().map(|p| format!("<th>{p}</th>")));
    out.push("</tr>".into());
    out.push("</thead>".into());
    out.push("<tbody>".into());
    for row in rows(pool, subcategory_id, r.code).await? {
        out.push("<tr>".into());
        let options = options(&row.options);
        for f in r.fields {
            match option(&options, f) {
                "" => out.push(format!("<td {YELLOW}>Missing</td>")),
                v => out.push(format!("<td>{}</td>", text(v))),
            }
        }
        if row.price_type.is_empty() {
            out.extend(row.prices.iter().take(price_count).map(|p| format!("<td class=right>${}</td>", crate::pricing::number_format(*p, 2))));
        } else {
            let label = match row.price_type.as_str() {
                "C/F" => "Consult Factory",
                "N/A" => "Not Available",
                _ => "",
            };
            out.push(format!("<td class=center rowspan={price_count}>{label}</td>"));
        }
        out.push("</tr>".into());
    }
    out.push("</tbody>".into());
    out.push("</table>".into());
    Ok(out)
}

/// export(): the rows as a file. Every record type into one Excel file gets a worksheet each.
async fn download(pool: &SqlitePool, report: &Report, chosen: Option<&Chosen>) -> sqlx::Result<Export> {
    let only = chosen.and_then(|c| c.record_type(&report.record_type));
    let file = FileType::posted(&report.file_type);
    let all = rows(pool, &report.subcategory_id, &report.record_type).await?;
    let record = |map: &Map, row: &PricingRow| -> Record {
        let r = chosen.and_then(|c| c.record_type(&row.record_type));
        let options = options(&row.options);
        map.fields()
            .iter()
            .map(|f| match f.key.as_str() {
                "subcategory_id" => report.subcategory_id.clone(),
                "record_type" => row.record_type.clone(),
                k => match (k.strip_prefix("code"), k.strip_prefix("price")) {
                    (Some(i), _) => r.and_then(|r| r.fields.get(php_intval(i) as usize)).map_or(String::new(), |name| option(&options, name).to_string()),
                    (_, Some(i)) => row.prices.get(php_intval(i) as usize).map_or(String::new(), |p| map::float_text(*p)),
                    _ => String::new(),
                },
            })
            .collect()
    };
    let group = &report.subcategory_id;
    if file == FileType::Xlsx && report.record_type.is_empty() {
        let mut sheets = Vec::new();
        for r in chosen.and_then(|c| c.module).map_or(&[][..], |m| m.record_types) {
            let map = pricing_map(Some(r));
            let records: Vec<Record> = all.iter().filter(|row| row.record_type == r.code).map(|row| record(&map, row)).collect();
            if !records.is_empty() {
                sheets.push((r.name.to_string(), map, records));
            }
        }
        return Ok(map::export_sheets(&map::file_name("pricing", group, "", file), &sheets));
    }
    let map = pricing_map(only);
    let records: Vec<Record> = all.iter().map(|row| record(&map, row)).collect();
    let fields: Vec<&Field> = map.fields().iter().collect();
    Ok(map::export_file(&map::file_name("pricing", group, &report.record_type, file), file, &fields, &records))
}

/// import_update() for one line of a pricing file: the subcategory and record type must be the ones chosen, the
/// record type's option must be there (the PHP read every option from `code0`; no record type has two). An update
/// replaces the row with the same options.
pub(super) async fn line(tx: &mut sqlx::SqliteConnection, line: &Line, update: bool, module: Option<&'static Module>, subcategory_id: &str, only: Option<&'static RecordType>) -> sqlx::Result<Errors> {
    let value = |k: &str| line.get(k).map_or("", String::as_str);
    let mut errors = Errors::new();
    let r = module.and_then(|m| m.record_type(value("record_type")));
    if value("subcategory_id") != subcategory_id {
        errors.push(("subcategory_id".into(), value("subcategory_id").into()));
    } else if r.is_none() || only.is_some_and(|o| r.is_some_and(|r| r.code != o.code)) {
        errors.push(("record_type".into(), value("record_type").into()));
    }
    let Some(r) = r.filter(|_| errors.is_empty()) else { return Ok(errors) };
    let code = value("code0");
    if !r.fields.is_empty() && code.is_empty() {
        errors.push(("code0".into(), format!("Invalid: {code}")));
    }
    if !errors.is_empty() || !update {
        return Ok(errors);
    }
    let options: String = form_urlencoded::Serializer::new(String::new()).extend_pairs(r.fields.iter().map(|f| (*f, code))).finish();
    let price = |i: usize| if i < r.prices.len() { map::floatval(value(&format!("price{i}"))) } else { 0.0 };
    let (p0, p1, p2, p3, p4) = (price(0), price(1), price(2), price(3), price(4));
    let stamp = unix_now();
    let sub = subcategory_id.to_string();
    sqlx::query!(
        "INSERT OR REPLACE INTO pricing (subcategory_id, record_type, options, price_type, price0, price1, price2, price3, price4, update_timestamp)
         VALUES (?1, ?2, ?3, '', ?4, ?5, ?6, ?7, ?8, ?9)",
        sub,
        r.code,
        options,
        p0,
        p1,
        p2,
        p3,
        p4,
        stamp
    )
    .execute(&mut *tx)
    .await?;
    Ok(errors)
}

/// The last subcategory subcategory->select() read: the PHP named the explain tab after whatever the select left in
/// `$database->subcategory`, which is that one, not the subcategory chosen.
async fn last_listed(pool: &SqlitePool, groups: &[super::html::OptGroup]) -> sqlx::Result<String> {
    let Some(id) = groups.last().and_then(|(_, options)| options.last()).map(|(id, _)| php_intval(id)) else { return Ok(String::new()) };
    Ok(sqlx::query_scalar!(r#"SELECT COALESCE(name, '') AS "name!: String" FROM subcategory WHERE id = ?1"#, id).fetch_optional(pool).await?.unwrap_or_default())
}

async fn page(pool: &SqlitePool, staff: &Staff<Administrator>, request: &Fields, posted: Option<&Posted>) -> sqlx::Result<Response> {
    let f = posted.map(|p| p.fields.clone()).unwrap_or_default();
    let report = Report {
        action: request.get("action").to_string(),
        function: request.get("function").to_string(),
        subcategory_id: request.get("subcategory_id").to_string(),
        record_type: f.get("record_type").to_string(),
        file_type: f.get("file_type").to_string(),
    };
    let chosen = if map::truthy(&report.subcategory_id) { chosen(pool, &report.subcategory_id).await? } else { None };
    let mut notes = Notes::new(TITLE, "");
    if map::truthy(&report.subcategory_id) {
        let name = chosen.as_ref().map_or("", |c| c.name.as_str());
        notes.say_html(format!("{} ({})", text(name), super::html::esc(&report.subcategory_id)));
    }
    let importing = matches!(report.function.as_str(), "review" | "upload");
    let update = report.function == "upload";
    let only = chosen.as_ref().and_then(|c| c.record_type(&report.record_type));
    let worksheet = only.map_or(report.record_type.as_str(), |r| r.name);
    let map = pricing_map(only);
    let carried = vec![
        ("function", Carried::One(report.function.clone())),
        ("subcategory_id", Carried::One(report.subcategory_id.clone())),
        ("record_type", Carried::One(report.record_type.clone())),
        ("file_type", Carried::One(report.file_type.clone())),
    ];
    let mut view = None;
    match () {
        _ if report.action.is_empty() => {}
        _ if !map::truthy(&report.subcategory_id) => notes.error("subcategory_id", None),
        _ if report.function == "download" => {
            match download(pool, &report, chosen.as_ref()).await? {
                Export::File(r) => return Ok(r),
                Export::Empty => notes.say("No records found in selected range"),
                Export::TooBig(_) => notes.say("Number of rows found excedes Excel capacity of 1,000,000 rows"),
            }
            notes.error_html("null", String::new());
        }
        _ if importing && report.action == "prelist" && report.record_type.is_empty() => notes.error("record_type", None),
        _ if importing && report.action == "prelist" => match posted {
            Some(p) => match utility::upload(pool, &staff.session, PATH, &map, p, &mut notes, update, Some(worksheet)).await? {
                Some(up) => view = Some(utility::upload_screen(&up, &map, &carried, false, update)),
                None => notes.error("upload", None),
            },
            None => notes.error("upload", None),
        },
        _ if importing && report.action == "update" => {
            let target = Target::Pricing { module: chosen.as_ref().and_then(|c| c.module), subcategory_id: report.subcategory_id.clone(), only };
            let i = utility::import(pool, staff, PATH, "pricing", &report.subcategory_id, &map, &target, update, false, f.get("external_file")).await?;
            notes.say(&i.message);
            view = Some(utility::import_screen(&i, &map, &carried));
        }
        _ => {}
    }
    let form = Form { notes: &notes };
    let mut body = notes.render();
    body.push_str(SCRIPT);
    body.push_str(&open(PATH, staff.csrf.as_str(), true));
    body.push_str(&hidden("action", ""));
    if let Some(v) = view {
        body.push_str(&v);
        body.push_str(close());
        return Ok(shell::page(pool, staff.view(), TITLE, &body).await);
    }
    let functions: Vec<(String, String)> = [("explain", "Explain Fields"), ("download", "WWW => Desktop"), ("review", "Desktop => WWW (Review only)"), ("upload", "Desktop => WWW (Update)")]
        .iter()
        .map(|(k, v)| (k.to_string(), v.to_string()))
        .collect();
    let groups = catalog::groups_with_output(pool, "smart").await?;
    let input = vec![
        "<table class='standard noborder'>".to_string(),
        "<tr>".into(),
        "<td width='20%'>Action</td>".into(),
        format!("<td width='80%'>{}</td>", form.select("function", &functions, &report.function, Blank::Empty, Opts::new().onchange("utility_screen();"))),
        "</tr>".into(),
        "<tr>".into(),
        "<td>Subcategory</td>".into(),
        format!("<td>{}</td>", form.optgroup("subcategory_id", &groups, &report.subcategory_id, true, Opts::new().onchange("fn_subcategory();"))),
        "</tr>".into(),
        "<tr id=record_type_tr>".into(),
        "<td>Record type</td>".into(),
        format!("<td id='record_type_td'>{}</td>", record_types(&form, chosen.as_ref(), &report.record_type)),
        "</tr>".into(),
        "<tr class='upload_class'>".into(),
        "<td>Import File</td>".into(),
        format!("<td>{}</td>", form.file("upload", 40, Opts::new())),
        "</tr>".into(),
        "<tr class='download_class'>".into(),
        "<td>Export file type</td>".into(),
        format!("<td>{}</td>", form.select("file_type", &map::file_type_options(), &report.file_type, Blank::None, Opts::new().onchange("pricing_screen();"))),
        "</tr>".into(),
        "</table>".into(),
        format!("<p>{}</p>", button("Go!", Opts::new().onclick("fn_action('prelist');"))),
    ];
    let mut tabs = Tabs::new();
    tabs.lines("Input", &input);
    let explain = report.function == "explain" && !notes.has_errors();
    match chosen.as_ref().filter(|_| explain) {
        Some(c) => {
            tabs.land_next();
            tabs.lines(&last_listed(pool, &groups).await?, &summary(pool, c, &report.subcategory_id).await?);
            if let Some(r) = only {
                tabs.land_next();
                tabs.lines(r.name, &detail(pool, &report.subcategory_id, r).await?);
            }
        }
        None => tabs.item("", String::new()),
    }
    body.push_str(&tabs.render());
    body.push_str(close());
    Ok(shell::page(pool, staff.view(), TITLE, &body).await)
}

/// The script's question: the record types of a subcategory, as a select, or a blank cell.
async fn ajax(pool: &SqlitePool, request: &Fields) -> sqlx::Result<Response> {
    let chosen = chosen(pool, request.get("subcategory_id")).await?;
    let notes = Notes::new(TITLE, "");
    let select = record_types(&Form { notes: &notes }, chosen.as_ref(), "");
    let html = if chosen.as_ref().is_some_and(|c| c.module.is_some()) { select } else { "&nbsp;".into() };
    let json = Value::Object([("html".to_string(), Value::from(html))].into_iter().collect()).to_string();
    Ok(shell::html_response(StatusCode::OK, crate::orders::shop::cart::php_json(&json)))
}

fn is_ajax(headers: &HeaderMap) -> bool {
    headers.contains_key("x-requested-with")
}

async fn show(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, headers: HeaderMap, RawQuery(q): RawQuery) -> Response {
    let request = Fields::from_query(q.as_deref());
    let answer = if is_ajax(&headers) { ajax(&auth.pool, &request).await } else { page(&auth.pool, &staff, &request, None).await };
    answer.unwrap_or_else(shell::internal)
}

/// Only applying an upload writes; the page's other posts explain, download or review.
async fn post(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, headers: HeaderMap, RawQuery(q): RawQuery, form: Unchecked) -> Response {
    let query = Fields::from_query(q.as_deref());
    let request = form.fields().clone().with_query(&query);
    if is_ajax(&headers) {
        return ajax(&auth.pool, &request).await.unwrap_or_else(shell::internal);
    }
    let writes = request.get("function") == "upload" && request.get("action") == "update";
    match form.accept(writes) {
        Ok(posted) => page(&auth.pool, &staff, &request, Some(&posted)).await.unwrap_or_else(shell::internal),
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
    fn a_record_type_names_its_columns_and_drops_the_rest() {
        let keys = |m: &Map| m.fields().iter().map(|f| format!("{}={}", f.key, f.name)).collect::<Vec<_>>();
        assert_eq!(pricing_map(None).fields().len(), 12);
        let slides = smart::module(549).unwrap();
        assert_eq!(keys(&pricing_map(slides.record_type("carriage_length"))), ["subcategory_id=subcategory_id", "record_type=record_type", "code0=carriage_length", "price0=Per Carriage price"]);
        let ghd = smart::module(606).unwrap().record_type("ghd_price");
        assert_eq!(keys(&pricing_map(ghd))[2..], ["price0=GHD-3-BLACK price", "price1=GHD-4-BLACK price"]);
    }

    #[test]
    fn stored_options_read_as_a_query() {
        let o = options("carriage_length=2");
        assert_eq!(option(&o, "carriage_length"), "2");
        assert_eq!(option(&o, "rail_length"), "");
    }
}
