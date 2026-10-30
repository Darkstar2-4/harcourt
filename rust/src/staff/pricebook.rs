//! inventory_pricebook.php: a price book for a customer. Internal staff pick the subcategories, the pricing terms and
//! the currency, and download an Excel workbook: a cover sheet, then one sheet per category listing each priced
//! part with its prices (and quantity breaks if asked) and lead time. The page's script asks for a currency's
//! exchange terms by AJAX.
//!
//! The PHP could also mail the workbook; the outbox carries no attachments, so "Email" output says so instead.

use std::sync::Arc;

use axum::{
    extract::State,
    http::{HeaderMap, StatusCode},
    response::Response,
    routing::get,
    Router,
};
use rust_decimal::{prelude::ToPrimitive, Decimal};
use serde_json::Value;
use sqlx::SqlitePool;

use super::{
    check,
    csrf::{php_intval, Fields, Posted},
    html::{button, esc, list_options, open, Blank, Form, Notes, Opts, Tabs},
    inventory::{pricing, Break},
    map::{self, Export, Field, Kind, Map, Record},
    shell, time, Staff,
};
use crate::{
    accounts::{account::PriceType, registry},
    api::{Auth, Internal},
    db::unix_now,
};

const PATH: &str = "/inventory_pricebook.php";
const TITLE: &str = "Price Book";
const REVISED: &str = "06/05/2023";
const ERROR_STYLE: &str = "style='border: 1px solid red !important;'";

const SCRIPT: &str = r##"
<script>
jQuery(document).ready(function() {
	fn_currency_code();
});
jQuery(function() {
    jQuery('#ajax_temp_id').autocomplete({
        source: '/scs_ajax.php?table=user',
        minLength: 2,
        html: true,
		select: function(event, ui) {
        	ajax_fill("temp",ui.item);
            fn_currency_code();
        },
        open: function(event, ui) { jQuery('.ui-autocomplete').css('z-index', 1000) }
    });
});
function fn_currency_code() {
	jQuery.ajax({
        type: "post",
	    dataType: "json",
		data: { action: "currency", currency_code: jQuery("#ajax_temp_pricebook_currency_code").val() },
	    success: function(response) {
	        for (var key in response) {
            	jQuery("#" + key).html(response[key]);
			}
        }
	});
}
function pricebook_create() {
if (!confirm('Create price book?')) return;
	jQuery('#scs_form').submit();
}
</script>"##;

/// `$menu->currency`: the currencies with a rate, the dollar first.
struct Currency {
    code: String,
    name: String,
    plural: String,
    exchange_rate: f64,
    adder_pct: f64,
    updated: i64,
}

async fn currencies(pool: &SqlitePool) -> sqlx::Result<Vec<Currency>> {
    let rows = sqlx::query!(
        r#"SELECT code AS "code!: String", COALESCE(name, '') AS "name!: String", COALESCE(plural, '') AS "plural!: String",
                  exchange_rate AS "exchange_rate!: f64", COALESCE(adder_pct, 0) AS "adder_pct!: f64", COALESCE(update_timestamp, 0) AS "updated!: i64"
           FROM currency WHERE exchange_rate > 0 AND active = 1 ORDER BY code <> 'USD', name COLLATE NOCASE"#
    )
    .fetch_all(pool)
    .await?;
    Ok(rows
        .into_iter()
        .map(|r| Currency { code: r.code, name: r.name, plural: r.plural, exchange_rate: r.exchange_rate, adder_pct: r.adder_pct, updated: r.updated })
        .collect())
}

/// The currency asked for, or the dollar.
fn currency<'a>(list: &'a [Currency], code: &str) -> Option<&'a Currency> {
    list.iter().find(|c| c.code == code).or_else(|| list.iter().find(|c| c.code == "USD"))
}

/// What the form holds: the registry's title and base pricing until something is posted.
#[derive(Default)]
struct Input {
    title: String,
    user_id: Option<i64>,
    company_name: String,
    name: String,
    price_type: String,
    price_pct: Decimal,
    currency_code: String,
    qty_break: bool,
    output: String,
    email: String,
    message: String,
    subcategories: Vec<i64>,
}

/// `fn_base64(array("subcategory", $id))`: a subcategory's checkbox name.
fn box_name(id: i64) -> String {
    use base64::{engine::general_purpose::STANDARD_NO_PAD, Engine};
    STANDARD_NO_PAD.encode(format!("subcategory\t{id}"))
}

fn box_id(name: &str) -> Option<i64> {
    use base64::{engine::general_purpose::STANDARD_NO_PAD, Engine};
    let text = String::from_utf8(STANDARD_NO_PAD.decode(name.trim_end_matches('=')).ok()?).ok()?;
    let (field, id) = text.split_once('\t')?;
    (field == "subcategory").then(|| php_intval(id))
}

async fn read(pool: &SqlitePool, f: &Fields, mailform: bool, notes: &mut Notes) -> sqlx::Result<Input> {
    let mut input = Input { title: check::posted_text(f.get("title")), ..Input::default() };
    if input.title.is_empty() {
        notes.error("title", Some("Cannot be blank"));
    }
    let user_id = f.int("ajax_temp_id");
    if sqlx::query_scalar!(r#"SELECT id AS "id!: i64" FROM "user" WHERE id = ?1"#, user_id).fetch_optional(pool).await?.is_some() {
        input.user_id = Some(user_id);
    }
    input.company_name = check::posted_text(f.get("ajax_temp_company_name"));
    input.name = check::posted_text(f.get("ajax_temp_name"));
    input.price_type = f.get("ajax_temp_price_type").to_string();
    input.price_pct = super::user::php_number(f.get("ajax_temp_price_pct")) / Decimal::ONE_HUNDRED;
    let pct = input.price_pct;
    match PriceType::parse(&input.price_type) {
        PriceType::None => {
            input.price_pct = Decimal::ZERO;
            notes.error("ajax_temp_price_type", Some("Not allowed"));
        }
        PriceType::Base if !pct.is_zero() => notes.error("ajax_temp_price_pct", Some("Must be 0% for base price")),
        PriceType::Discount if pct <= Decimal::ZERO || pct >= Decimal::ONE => notes.error_html("ajax_temp_price_pct", "Discount must be > 0% and < 100%".into()),
        PriceType::Plus if pct <= Decimal::ZERO || pct >= Decimal::from(5) => notes.error_html("ajax_temp_price_pct", "Price plus must be > 0% and < 500%".into()),
        _ => {}
    }
    input.currency_code = f.get("ajax_temp_pricebook_currency_code").to_string();
    input.qty_break = f.checked("qty_break");
    if mailform {
        input.output = f.get("output").to_string();
        let (address, error) = check::email(f.get("ajax_temp_email"), input.output == "Email");
        input.email = address;
        if let Some(e) = error {
            notes.error("ajax_temp_email", Some(e));
        }
    }
    input.message = check::posted_text(f.get("message"));
    input.subcategories = f.iter().filter_map(|(k, _)| box_id(k)).collect();
    if input.subcategories.is_empty() {
        notes.error("subcategory_id", Some("No subcategories selected"));
    }
    Ok(input)
}

/// A category of the Subcategory tab and its subcategories, each labelled with its count of priced parts.
struct Group {
    id: i64,
    name: String,
    items: Vec<(i64, String)>,
}

/// The subcategories a price book can cover: active, not smart, in an active category, with priced parts.
async fn priced(pool: &SqlitePool) -> sqlx::Result<Vec<Group>> {
    let rows = sqlx::query!(
        r#"SELECT c.id AS "category_id!: i64", COALESCE(c.name, '') AS "category!: String", s.id AS "subcategory_id!: i64",
                  COALESCE(s.name, '') AS "subcategory!: String", count(*) AS "n!: i64"
           FROM inventory i LEFT JOIN category c ON c.id = i.category_id LEFT JOIN subcategory s ON s.id = i.subcategory_id
           WHERE s.output <> 'smart' AND c.active = 1 AND s.status = 'Active' AND COALESCE(i.pricing, '') NOT IN ('', 'a:0:{}')
           GROUP BY c.id, s.id ORDER BY c.name COLLATE NOCASE, s.name COLLATE NOCASE"#
    )
    .fetch_all(pool)
    .await?;
    let mut out: Vec<Group> = Vec::new();
    for r in rows {
        let item = (r.subcategory_id, format!("{} ({})", r.subcategory, super::html::number(r.n)));
        match out.last_mut() {
            Some(g) if g.id == r.category_id => g.items.push(item),
            _ => out.push(Group { id: r.category_id, name: r.category, items: vec![item] }),
        }
    }
    Ok(out)
}

fn price_types() -> Vec<(String, String)> {
    [("", "No pricing"), ("base", "Base price"), ("discount", "Discount"), ("plus", "Base plus")].iter().map(|(v, l)| (v.to_string(), l.to_string())).collect()
}

fn with_error(notes: &Notes, field: &str, widget: String, sep: &str) -> String {
    match notes.error_text(field) {
        Some(t) => format!("{widget}{sep}{t}"),
        None => widget,
    }
}

fn input_tab(form: &Form<'_>, input: &Input, list: &[Currency], mailform: bool) -> Vec<String> {
    let notes = form.notes;
    let placeholder = |p: &str| Opts::new().set("placeholder", p.to_string());
    let user_id = input.user_id.map(|i| i.to_string()).unwrap_or_default();
    let pct = input.price_pct * Decimal::ONE_HUNDRED;
    let pct = if pct.is_zero() { String::new() } else { format!("{:.2}", pct.round_dp(2)) };
    let pct_note = notes.error_text("ajax_temp_price_pct").map(|t| format!(" <b>{t}</b>")).unwrap_or_default();
    let currencies: Vec<(String, String)> = list.iter().map(|c| (c.code.clone(), esc(&c.name))).collect();
    let mut out = vec![
        "<table class='noborder'>".to_string(),
        "<tr>".into(),
        "<td>Title</td>".into(),
        format!("<td>{}</td>", with_error(notes, "title", form.text("title", &input.title, 60, 0, placeholder("Mandatory")), "<br>")),
        "</tr>".into(),
        "<tr>".into(),
        "<td width='20%'>Prepared for user ID</td>".into(),
        format!("<td width='80%'>{}</td>", form.text("ajax_temp_id", &user_id, 10, 0, placeholder("Optional"))),
        "</tr>".into(),
        "<tr>".into(),
        "<td>Company Name</td>".into(),
        format!("<td>{}</td>", with_error(notes, "ajax_temp_company_name", form.text("ajax_temp_company_name", &input.company_name, 40, 0, placeholder("Mandatory")), "<br>")),
        "</tr>".into(),
        "<tr>".into(),
        "<td>Name</td>".into(),
        format!("<td>{}</td>", with_error(notes, "ajax_temp_name", form.text("ajax_temp_name", &input.name, 40, 0, placeholder("Optional")), "<br>")),
        "</tr>".into(),
        "<tr>".into(),
        format!(
            "<td>{}</td>",
            with_error(
                notes,
                "ajax_temp_price_type",
                form.select("ajax_temp_price_type", &price_types(), &input.price_type, Blank::None, Opts::new().onchange("jQuery('#ajax_temp_price_pct').val('');")),
                "<br>"
            )
        ),
        format!("<td>{}%{pct_note}</td>", form.text("ajax_temp_price_pct", &pct, 6, 6, Opts::new())),
        "</tr>".into(),
        "<tr>".into(),
        "<td>Currency</td>".into(),
        format!(
            "<td>{}<div id=currency_meta></div></td>",
            form.select("ajax_temp_pricebook_currency_code", &currencies, &input.currency_code, Blank::None, Opts::new().onchange("fn_currency_code();"))
        ),
        "</tr>".into(),
        "<tr>".into(),
        "<td>Include quantity break?</td>".into(),
        format!("<td>{}</td>", form.checkbox("qty_break", "1", input.qty_break, Opts::new())),
        "</tr>".into(),
    ];
    if mailform {
        out.extend([
            "<tr>".to_string(),
            format!("<td>Output: {}</td>", form.select("output", &list_options(&["Download", "Email"]), &input.output, Blank::None, Opts::new())),
            format!("<td>{}</td>", with_error(notes, "ajax_temp_email", form.text("ajax_temp_email", &input.email, 60, 0, placeholder("Mandatory if email output")), "<br>")),
            "</tr>".into(),
        ]);
    }
    out.extend([
        "<tr>".to_string(),
        "<td>Message</td>".into(),
        format!("<td>{}</td>", form.textarea("message", &input.message, 3, 60, placeholder("Optional"))),
        "</tr>".into(),
        "</table>".into(),
    ]);
    out
}

fn subcategory_tab(form: &Form<'_>, input: &Input, groups: &[Group]) -> Vec<String> {
    let notes = form.notes;
    let style = if notes.is_error("subcategory_id") { format!(" {ERROR_STYLE}") } else { String::new() };
    let mut out = vec![format!("<input type=hidden name='subcategory_id' id='subcategory_id'{style}>\n")];
    if let Some(t) = notes.error_text("subcategory_id") {
        out.push(format!("<p><b>{t}</b></p>"));
    }
    let all = form.checkbox("all", "1", false, Opts::new().onclick("jQuery('.subcategory_all').prop('checked',this.checked);"));
    out.push(format!("<p>{all} All categories/subcategories?</p>"));
    out.push("<table class='noborder'>".into());
    for Group { id: category_id, name: category, items } in groups {
        let class = format!("category_{category_id}_class");
        let head = form.checkbox(&format!("category_{category_id}"), "1", false, Opts::new().class("subcategory_all").onclick(format!("jQuery('.{class}').prop('checked',this.checked);")));
        let boxes: Vec<String> = items
            .iter()
            .map(|(id, label)| format!("{} {label}", form.checkbox(&box_name(*id), "1", input.subcategories.contains(id), Opts::new().class(format!("subcategory_all {class}")))))
            .collect();
        out.push("<tr>".into());
        out.push(format!("<td>{head} {category}</td>"));
        out.push(format!("<td class=newspaper2>{}</td>", boxes.join("<br>")));
        out.push("</tr>".into());
    }
    out.push("</table>".into());
    out
}

/// One priced part of the book.
struct Part {
    sku: String,
    category_id: i64,
    category: String,
    subcategory_id: i64,
    subcategory: String,
    leadtime_option: String,
    leadtime_text: String,
    breaks: Vec<Break>,
}

/// The cover sheet, then a sheet per category of its parts by subcategory and SKU, priced on the terms asked.
async fn workbook(pool: &SqlitePool, input: &Input, rate: &Currency) -> sqlx::Result<Export> {
    let mut parts = Vec::new();
    for id in &input.subcategories {
        let rows = sqlx::query!(
            r#"SELECT i.sku AS "sku!: String", c.id AS "category_id!: i64", COALESCE(c.name, '') AS "category!: String", s.id AS "subcategory_id!: i64",
                      COALESCE(s.name, '') AS "subcategory!: String", COALESCE(i.leadtime_option, '') AS "leadtime_option!: String",
                      COALESCE(i.leadtime_text, '') AS "leadtime_text!: String", COALESCE(i.pricing, '') AS "pricing!: String"
               FROM inventory i LEFT JOIN subcategory s ON s.id = i.subcategory_id LEFT JOIN category c ON c.id = i.category_id
               WHERE i.subcategory_id = ?1 AND c.active = 1 AND s.status = 'Active' AND s.output <> 'smart' AND COALESCE(i.pricing, '') NOT IN ('', 'a:0:{}')"#,
            id
        )
        .fetch_all(pool)
        .await?;
        parts.extend(rows.into_iter().map(|r| Part {
            sku: r.sku,
            category_id: r.category_id,
            category: r.category,
            subcategory_id: r.subcategory_id,
            subcategory: r.subcategory,
            leadtime_option: r.leadtime_option,
            leadtime_text: r.leadtime_text,
            breaks: pricing(&r.pricing),
        }));
    }
    let multiplier = match PriceType::parse(&input.price_type) {
        PriceType::Discount => Decimal::ONE - input.price_pct,
        PriceType::Plus => Decimal::ONE + input.price_pct,
        _ => Decimal::ONE,
    }
    .to_f64()
    .unwrap_or(1.0);
    let mut map = Map::new();
    map.add(Field::new("sku", Kind::Uppercase).name("Harcourt SKU").required().width(30));
    map.add(Field::new("subcategory_name", Kind::Alphanumeric).name("Subcategory").width(60));
    map.add(Field::new("price0", Kind::Decimal(2)).name("Base Price").width(12));
    if input.qty_break {
        for i in 1..10 {
            map.add(Field::new(format!("qty_break{i}"), Kind::Decimal(0)).name(format!("Qty Break #{i}")).width(14));
            map.add(Field::new(format!("price{i}"), Kind::Decimal(2)).name(format!("Price #{i}")).width(12));
        }
    }
    map.add(Field::new("leadtime", Kind::Alphanumeric).name("Lead Time").width(20));
    let mut subcategories: Vec<(i64, Option<Value>, String)> = Vec::new();
    for id in parts.iter().map(|p| p.subcategory_id).collect::<std::collections::BTreeSet<_>>() {
        let r = sqlx::query!(
            r#"SELECT currency, COALESCE(leadtime_option, '') AS "leadtime_option!: String", COALESCE(leadtime_text, '') AS "leadtime_text!: String" FROM subcategory WHERE id = ?1"#,
            id
        )
        .fetch_one(pool)
        .await?;
        let currency = r.currency.as_deref().and_then(|c| serde_json::from_str::<Value>(c).ok());
        let leadtime = match (r.leadtime_option.as_str(), r.leadtime_text.as_str()) {
            ("Weeks", "") => String::new(),
            ("Weeks", weeks) => format!("{weeks} Week(s)"),
            (option, _) => option.to_string(),
        };
        subcategories.push((id, currency, leadtime));
    }
    let record = |p: &Part| -> Record {
        let (currency, sub_leadtime) = subcategories.iter().find(|(id, _, _)| *id == p.subcategory_id).map_or((None, ""), |(_, c, l)| (c.as_ref(), l.as_str()));
        let adder = currency.and_then(|c| c.get(&rate.code)).and_then(|v| v.as_f64().or_else(|| v.as_str().map(map::floatval))).unwrap_or(rate.adder_pct);
        let price = |b: &Break| {
            let p = b.price.to_f64().unwrap_or(0.0) * multiplier * rate.exchange_rate * (1.0 + adder);
            map::float_text((p * 100.0).round() / 100.0)
        };
        let leadtime = match (p.leadtime_option.as_str(), p.leadtime_text.as_str()) {
            ("In Stock", _) => "In Stock".to_string(),
            ("Subcategory", _) => sub_leadtime.to_string(),
            (_, "") => String::new(),
            (_, weeks) => format!("{weeks} Week(s)"),
        };
        map.fields()
            .iter()
            .map(|f| match f.key.as_str() {
                "sku" => p.sku.clone(),
                "subcategory_name" => p.subcategory.clone(),
                "leadtime" => leadtime.clone(),
                k => match (k.strip_prefix("price"), k.strip_prefix("qty_break")) {
                    (Some(i), _) => p.breaks.get(php_intval(i) as usize).map(price).unwrap_or_default(),
                    (_, Some(i)) => p.breaks.get(php_intval(i) as usize).map(|b| b.qty.normalize().to_string()).unwrap_or_default(),
                    _ => String::new(),
                },
            })
            .collect()
    };
    let mut categories: Vec<(i64, String)> = parts.iter().map(|p| (p.category_id, p.category.clone())).collect();
    categories.sort_by_key(|c| c.1.to_lowercase());
    categories.dedup_by_key(|c| c.0);
    let mut cover = Map::new();
    cover.add(Field::new("name", Kind::Alphanumeric).name("Title").width(30));
    cover.add(Field::new("comment", Kind::Alphanumeric).name("Comment").width(60));
    let mut lines: Vec<Record> = vec![
        vec!["Subject".into(), input.title.clone()],
        vec!["Website".into(), "https://www.harcourt.co/".into()],
        vec!["Date".into(), time::date("l F j, Y", unix_now())],
    ];
    if !input.company_name.is_empty() {
        lines.push(vec!["Company Name".into(), input.company_name.clone()]);
    }
    if !input.name.is_empty() {
        lines.push(vec!["Name".into(), input.name.clone()]);
    }
    lines.push(vec![format!("Priced in {}", rate.plural), String::new()]);
    if !input.message.is_empty() {
        lines.push(vec![String::new(), String::new()]);
        lines.push(vec!["Message".into(), input.message.clone()]);
    }
    let mut sheets = vec![("Cover Sheet".to_string(), cover, lines)];
    for (id, name) in categories {
        let mut rows: Vec<&Part> = parts.iter().filter(|p| p.category_id == id).collect();
        rows.sort_by(|a, b| a.subcategory.to_lowercase().cmp(&b.subcategory.to_lowercase()).then_with(|| a.sku.cmp(&b.sku)));
        let title = if name.chars().count() > 31 { id.to_string() } else { name };
        sheets.push((title, map.clone(), rows.into_iter().map(record).collect()));
    }
    Ok(map::export_sheets("Price Book.xlsx", &sheets))
}

async fn page(pool: &SqlitePool, staff: &Staff<Internal>, posted: Option<&Posted>) -> sqlx::Result<Response> {
    let mut notes = Notes::new(TITLE, "");
    notes.say(&format!("Revised: {REVISED}"));
    let mailform = registry::get(pool, "local", "pricebook_mailform").await?.is_some_and(|m| map::truthy(m.trim()));
    let list = currencies(pool).await?;
    let f = posted.map(|p| p.fields.clone()).unwrap_or_default();
    let input = if f.get("action").is_empty() {
        let title = registry::get(pool, "local", "pricebook_title").await?.unwrap_or_default();
        Input { title, price_type: "base".into(), ..Input::default() }
    } else {
        let input = read(pool, &f, mailform, &mut notes).await?;
        if !notes.has_errors() {
            let rate = currency(&list, &input.currency_code);
            match rate {
                Some(rate) if input.output != "Email" => match workbook(pool, &input, rate).await? {
                    Export::File(r) => return Ok(r),
                    _ => notes.say("No records found in selected range"),
                },
                Some(_) => notes.say("Email output is not available here; download the price book and send it"),
                None => notes.say("No currency with an exchange rate"),
            }
        }
        input
    };
    let form = Form { notes: &notes };
    let mut body = notes.render();
    body.push_str(SCRIPT);
    body.push_str(&open(PATH, staff.csrf.as_str(), false));
    body.push_str(&super::html::hidden("action", "update"));
    let mut tabs = Tabs::new();
    tabs.lines("Input", &input_tab(&form, &input, &list, mailform));
    tabs.lines("Subcategory", &subcategory_tab(&form, &input, &priced(pool).await?));
    body.push_str(&tabs.render());
    body.push_str(&format!("<p>{}</p>", button("Create Price Book", Opts::new().onclick("pricebook_create();"))));
    Ok(shell::unclosed(pool, staff.view(), TITLE, &body).await)
}

/// The script's question: when the currency was revised and its rate and adder; nothing for the dollar. Any other
/// action gets an empty object.
async fn ajax(pool: &SqlitePool, f: &Fields) -> sqlx::Result<Response> {
    if f.get("action") != "currency" {
        return Ok(shell::html_response(StatusCode::OK, "{}".into()));
    }
    let list = currencies(pool).await?;
    let mut meta = Vec::new();
    if let Some(c) = currency(&list, f.get("currency_code")).filter(|c| c.code != "USD") {
        meta.push(format!("Revised: {}", time::date("m/d/Y h:iA", c.updated)));
        meta.push(format!("Exchange rate: {}%", crate::pricing::number_format(c.exchange_rate, 4)));
        if c.adder_pct != 0.0 {
            meta.push(format!("Adder percent: {}%", crate::pricing::number_format(c.adder_pct * 100.0, 4)));
        }
    }
    let json = Value::Object([("currency_meta".to_string(), Value::from(meta.join("<br>")))].into_iter().collect()).to_string();
    Ok(shell::html_response(StatusCode::OK, crate::orders::shop::cart::php_json(&json)))
}

fn is_ajax(headers: &HeaderMap) -> bool {
    headers.get("x-requested-with").and_then(|v| v.to_str().ok()).is_some_and(|v| v.eq_ignore_ascii_case("xmlhttprequest"))
}

async fn show(State(auth): State<Arc<Auth>>, staff: Staff<Internal>, headers: HeaderMap) -> Response {
    let answer = if is_ajax(&headers) { ajax(&auth.pool, &Fields::default()).await } else { page(&auth.pool, &staff, None).await };
    answer.unwrap_or_else(shell::internal)
}

/// Making a price book reads only; the currency question is the page's script.
async fn post(State(auth): State<Arc<Auth>>, staff: Staff<Internal>, headers: HeaderMap, form: super::csrf::Unchecked) -> Response {
    let posted = form.read_only();
    let answer = if is_ajax(&headers) { ajax(&auth.pool, &posted.fields).await } else { page(&auth.pool, &staff, Some(&posted)).await };
    answer.unwrap_or_else(shell::internal)
}

pub fn routes() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(show).post(post))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn subcategory_boxes_name_their_id() {
        assert_eq!(box_name(390), "c3ViY2F0ZWdvcnkJMzkw");
        assert_eq!(box_name(40), "c3ViY2F0ZWdvcnkJNDA");
        assert_eq!(box_id("c3ViY2F0ZWdvcnkJNDA"), Some(40));
        assert_eq!(box_id("title"), None);
    }
}
