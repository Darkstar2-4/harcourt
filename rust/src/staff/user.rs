//! user.php: the User Manager. An Administrator lists company accounts by type, status and the start of a company
//! name, address or domain, and edits one: address, role, catalog rights, pricing, portal and landing documents,
//! and the categories it may not see.
//!
//! An Administrator sees and edits only accounts at or below their own role, and can give no higher role (the PHP
//! limited only the pick lists). The Salesforce push on return is not ported: it sent the org id as `old`, so
//! Salesforce discarded every lead it posted.

use std::{net::Ipv4Addr, str::FromStr, sync::Arc, time::Duration};

use axum::{
    extract::{RawQuery, State},
    http::{header, StatusCode},
    response::{IntoResponse, Response},
    routing::get,
    Router,
};
use rust_decimal::{prelude::ToPrimitive, Decimal};
use serde_json::{Map, Value};
use sqlx::SqlitePool;

use super::{
    address::{Address, Block},
    check::posted_text,
    choices,
    csrf::{Fields, Posted},
    html::{button, close, hidden, href, js, list_options, open, page_items, php_float, text, Blank, Form, Notes, Opts, Tabs, PAGE_ROWS},
    shell, time, Staff,
};
use crate::{
    accounts::{account::PriceType, registry},
    api::{Administrator, Auth},
    db::unix_now,
    identity::{Role, Status},
};

const PATH: &str = "/user.php";
const TITLE: &str = "User Manager";

const ROLES: [Role; 6] = [Role::Customer, Role::External, Role::Internal, Role::Executive, Role::Administrator, Role::SuperUser];
const STATUSES: [Status; 3] = [Status::Pending, Status::Active, Status::Denied];

fn status_name(s: Status) -> &'static str {
    match s {
        Status::Pending => "Pending",
        Status::Active => "Active",
        Status::Denied => "Denied",
    }
}

/// user->type_list(): the roles at or below the viewer's.
fn roles_up_to(viewer: Role) -> Vec<&'static str> {
    ROLES.iter().filter(|r| **r <= viewer).map(|r| r.as_str()).collect()
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Action {
    None,
    List,
    Cancel,
    Maintain,
    Update,
    Delete,
}

impl Action {
    fn parse(s: &str) -> Action {
        match s {
            "list" => Action::List,
            "cancel" => Action::Cancel,
            "maintain" => Action::Maintain,
            "update" => Action::Update,
            "delete" => Action::Delete,
            _ => Action::None,
        }
    }
}

/// The account being edited, as user_data_class held it.
struct Account {
    id: i64,
    ip: String,
    address: Address,
    status: Status,
    role: Role,
    last_login: i64,
    login_document_id: i64,
    landing_document_id: i64,
    comment: String,
    portal_id: i64,
    customer_code: String,
    catalog: bool,
    cad: bool,
    ecommerce: bool,
    price_type: PriceType,
    price_pct: Decimal,
    language_code: String,
    industry_id: i64,
    leadscore: bool,
    revenue: String,
    employees: String,
    currency_code: String,
    ipstack: Map<String, Value>,
    recaptcha_score: f64,
}

impl Account {
    /// user_data_class: a new account starts at the address of whoever opens the form, signed in now.
    fn new(client: Option<std::net::IpAddr>, now: i64) -> Account {
        Account {
            id: 0,
            ip: client.map(|ip| ip.to_string()).unwrap_or_default(),
            address: Address::default(),
            status: Status::Pending,
            role: Role::Customer,
            last_login: now,
            login_document_id: 0,
            landing_document_id: 0,
            comment: String::new(),
            portal_id: 0,
            customer_code: String::new(),
            catalog: false,
            cad: false,
            ecommerce: false,
            price_type: PriceType::None,
            price_pct: Decimal::ZERO,
            language_code: "EN".into(),
            industry_id: 0,
            leadscore: true,
            revenue: String::new(),
            employees: String::new(),
            currency_code: "USD".into(),
            ipstack: Map::new(),
            recaptcha_score: 1.0,
        }
    }
}

enum Lookup {
    Found(Box<Account>),
    Missing,
    /// The account's role is above the viewer's.
    Forbidden,
}

async fn read(pool: &SqlitePool, id: i64, viewer: Role) -> sqlx::Result<Lookup> {
    let Some(r) = sqlx::query!(
        r#"SELECT id AS "id!: i64", ip, company_name, address, city, state, zip, country_code, status, "type" AS kind, last_login,
                  login_document_id, landing_document_id, comment, portal_id, customer_code, catalog, cad, ecommerce, price_type,
                  price_pct, language_code, industry_id, leadscore, revenue, employees, currency_code, ipstack_json, recaptcha_score
           FROM "user" WHERE id = ?1"#,
        id
    )
    .fetch_optional(pool)
    .await?
    else {
        return Ok(Lookup::Missing);
    };
    let role = Role::parse(&r.kind).unwrap_or(Role::Customer);
    if role > viewer {
        return Ok(Lookup::Forbidden);
    }
    let ipstack = match r.ipstack_json.as_deref().map(serde_json::from_str::<Value>) {
        Some(Ok(Value::Object(m))) => m,
        _ => Map::new(),
    };
    Ok(Lookup::Found(Box::new(Account {
        id: r.id,
        ip: r.ip.unwrap_or_default(),
        address: Address {
            company_name: r.company_name.unwrap_or_default(),
            address: r.address.unwrap_or_default(),
            city: r.city.unwrap_or_default(),
            state: r.state.unwrap_or_default(),
            zip: r.zip.unwrap_or_default(),
            country_code: r.country_code.unwrap_or_default(),
        },
        status: Status::parse(&r.status).unwrap_or(Status::Pending),
        role,
        last_login: r.last_login,
        login_document_id: r.login_document_id,
        landing_document_id: r.landing_document_id,
        comment: r.comment.unwrap_or_default(),
        portal_id: r.portal_id,
        customer_code: r.customer_code.unwrap_or_default(),
        catalog: r.catalog != 0,
        cad: r.cad != 0,
        ecommerce: r.ecommerce != 0,
        price_type: PriceType::parse(&r.price_type),
        price_pct: Decimal::try_from(r.price_pct).unwrap_or_default().round_dp(4),
        language_code: r.language_code,
        industry_id: r.industry_id,
        leadscore: r.leadscore != 0,
        revenue: r.revenue,
        employees: r.employees,
        currency_code: r.currency_code,
        ipstack,
        recaptcha_score: r.recaptcha_score,
    })))
}

/// The list's filters, posted with the list and carried through the edit form.
#[derive(Default)]
struct Report {
    page: i64,
    status: String,
    kind: String,
    option_field: String,
    option_value: String,
}

impl Report {
    fn from(posted: &Fields) -> Report {
        Report {
            page: posted.int("page"),
            status: posted.get("report_status").to_string(),
            kind: posted.get("report_type").to_string(),
            option_field: posted.get("report_option_field").to_string(),
            option_value: posted.get("report_option_value").to_string(),
        }
    }
}

/// The Salesforce rows shown when the edit came from the dashboard; never stored.
#[derive(Default)]
struct Salesforce {
    rating: String,
    campaign_code: String,
    call: bool,
    email: bool,
    fax: bool,
}

/// ip2long() round trip: a dotted quad written the canonical way, not 0.0.0.0.
fn valid_ip(ip: &str) -> bool {
    Ipv4Addr::from_str(ip).is_ok_and(|a| !a.is_unspecified() && a.to_string() == ip)
}

/// fn_number(): the digits, sign and point of the text as a number, a hundredth of it if it holds `%`.
fn php_number(s: &str) -> Decimal {
    let kept: String = s.chars().filter(|c| c.is_ascii_digit() || matches!(c, '.' | '-')).collect();
    let bytes = kept.as_bytes();
    let mut end = usize::from(bytes.first() == Some(&b'-'));
    let mut point = false;
    while let Some(&b) = bytes.get(end) {
        match b {
            b'0'..=b'9' => end += 1,
            b'.' if !point => {
                point = true;
                end += 1;
            }
            _ => break,
        }
    }
    let n = Decimal::from_str(kept[..end].trim_end_matches('.')).unwrap_or_default();
    if s.contains('%') {
        n / Decimal::ONE_HUNDRED
    } else {
        n
    }
}

/// The checks across fields the PHP made after reading the form.
fn check_rights(a: &mut Account, notes: &mut Notes) {
    if !a.catalog && a.price_type != PriceType::None {
        notes.error("price_type", Some("Pricing requires catalog access"));
    }
    if !a.catalog && a.cad {
        notes.error("cad", Some("CAD requires catalog access"));
    }
    let pct = a.price_pct;
    match a.price_type {
        PriceType::None => a.price_pct = Decimal::ZERO,
        PriceType::Base if !pct.is_zero() => notes.error("price_pct", Some("Must be 0% for base price")),
        PriceType::Discount if pct <= Decimal::ZERO || pct >= Decimal::ONE => notes.error_html("price_pct", "Discount must be > 0% and < 100%".into()),
        PriceType::Plus if pct <= Decimal::ZERO || pct >= Decimal::from(5) => notes.error_html("price_pct", "Price plus must be > 0% and < 500%".into()),
        _ => {}
    }
    if a.portal_id != 0 && (a.login_document_id != 0 || a.landing_document_id != 0) {
        notes.error("portal_id", None);
        notes.say("Landing documents not allowed when portal is selected");
    }
}

/// The posted form into the account. Status and role must be ones the viewer may give.
fn take_posted(a: &mut Account, posted: &Fields, viewer: Role, block: &Block, notes: &mut Notes) {
    a.ip = posted.get("ip").to_string();
    if !valid_ip(&a.ip) {
        notes.error("ip", None);
    }
    a.address = block.verify(posted, notes);
    match Status::parse(posted.get("status")) {
        Some(s) => a.status = s,
        None => notes.error("status", None),
    }
    match Role::parse(posted.get("type")).filter(|r| *r <= viewer) {
        Some(r) => a.role = r,
        None => notes.error("type", None),
    }
    a.currency_code = posted.get("currency_code").to_string();
    if a.currency_code.is_empty() {
        notes.error("currency_code", None);
    }
    a.language_code = posted.get("language_code").to_string();
    a.portal_id = posted.int("portal_id");
    a.login_document_id = posted.int("login_document_id");
    a.landing_document_id = posted.int("landing_document_id");
    a.customer_code = posted.get("customer_code").trim().to_uppercase();
    a.catalog = posted.checked("catalog");
    a.ecommerce = posted.checked("ecommerce");
    a.price_type = PriceType::parse(posted.get("price_type"));
    a.price_pct = php_number(posted.get("price_pct")) / Decimal::ONE_HUNDRED;
    a.cad = posted.checked("cad");
    a.industry_id = posted.int("industry_id");
    a.leadscore = posted.checked("leadscore");
    a.revenue = posted_text(posted.get("revenue"));
    a.employees = posted_text(posted.get("employees"));
    check_rights(a, notes);
}

fn salesforce(posted: &Fields) -> Salesforce {
    Salesforce {
        rating: posted.get("sf_rating").to_string(),
        campaign_code: posted.get("campaign_code").to_string(),
        call: posted.checked("sf_call"),
        email: posted.checked("sf_email"),
        fax: posted.checked("sf_fax"),
    }
}

fn null_if_empty(s: &str) -> Option<&str> {
    (!s.is_empty()).then_some(s)
}

/// user->update() and the category restrictions in one transaction. Returns the account's id.
async fn save(pool: &SqlitePool, a: &Account, posted: &Fields) -> sqlx::Result<i64> {
    let mut tx = pool.begin().await?;
    let (status, role, price_type) = (status_name(a.status), a.role.as_str(), a.price_type.as_str());
    let pct = a.price_pct.round_dp(4).to_f64().unwrap_or(0.0);
    let (catalog, cad, ecommerce, leadscore) = (i64::from(a.catalog), i64::from(a.cad), i64::from(a.ecommerce), i64::from(a.leadscore));
    let ad = &a.address;
    let (address, city, state, zip, country) = (null_if_empty(&ad.address), null_if_empty(&ad.city), null_if_empty(&ad.state), null_if_empty(&ad.zip), null_if_empty(&ad.country_code));
    let id = if a.id == 0 {
        sqlx::query_scalar!(
            r#"INSERT INTO "user" (ip, company_name, address, city, state, zip, country_code, status, "type", login_document_id, landing_document_id,
                                   portal_id, customer_code, catalog, cad, ecommerce, price_type, price_pct, language_code, currency_code, industry_id,
                                   leadscore, revenue, employees, last_login)
               VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, ?12, ?13, ?14, ?15, ?16, ?17, ?18, ?19, ?20, ?21, ?22, ?23, ?24, ?25)
               RETURNING id AS "id!: i64""#,
            a.ip, ad.company_name, address, city, state, zip, country, status, role, a.login_document_id, a.landing_document_id,
            a.portal_id, a.customer_code, catalog, cad, ecommerce, price_type, pct, a.language_code, a.currency_code, a.industry_id,
            leadscore, a.revenue, a.employees, a.last_login
        )
        .fetch_one(&mut *tx)
        .await?
    } else {
        sqlx::query!(
            r#"UPDATE "user" SET ip = ?2, company_name = ?3, address = ?4, city = ?5, state = ?6, zip = ?7, country_code = ?8, status = ?9, "type" = ?10,
                      login_document_id = ?11, landing_document_id = ?12, portal_id = ?13, customer_code = ?14, catalog = ?15, cad = ?16, ecommerce = ?17,
                      price_type = ?18, price_pct = ?19, language_code = ?20, currency_code = ?21, industry_id = ?22, leadscore = ?23, revenue = ?24,
                      employees = ?25
               WHERE id = ?1"#,
            a.id, a.ip, ad.company_name, address, city, state, zip, country, status, role, a.login_document_id, a.landing_document_id,
            a.portal_id, a.customer_code, catalog, cad, ecommerce, price_type, pct, a.language_code, a.currency_code, a.industry_id,
            leadscore, a.revenue, a.employees
        )
        .execute(&mut *tx)
        .await?;
        a.id
    };
    sqlx::query!("DELETE FROM user_category WHERE user_id = ?1", id).execute(&mut *tx).await?;
    let categories = sqlx::query_scalar!(r#"SELECT id AS "id!: i64" FROM category WHERE active = 1"#).fetch_all(&mut *tx).await?;
    for category in categories.into_iter().filter(|c| posted.checked(&format!("user_category_{c}"))) {
        sqlx::query!("INSERT INTO user_category (user_id, category_id) VALUES (?1, ?2)", id, category).execute(&mut *tx).await?;
    }
    tx.commit().await?;
    Ok(id)
}

const FREE_EMAIL: [&str; 26] = [
    "gmail.com", "googlemail.com", "yahoo.com", "ymail.com", "hotmail.com", "outlook.com", "live.com", "msn.com", "aol.com", "icloud.com", "me.com", "mac.com",
    "protonmail.com", "proton.me", "gmx.com", "gmx.de", "web.de", "mail.com", "zoho.com", "yandex.com", "qq.com", "163.com", "comcast.net", "verizon.net",
    "att.net", "sbcglobal.net",
];

/// The reCAPTCHA bypass an approval adds: the whole company domain, or the one address on a free-mail domain.
fn bypass_entry(email: &str) -> String {
    let email = email.trim().to_lowercase();
    match email.split_once('@') {
        Some((_, domain)) if !domain.is_empty() && !FREE_EMAIL.contains(&domain) => format!("@{domain}"),
        _ => email,
    }
}

/// On approval, the address the account signed up with skips reCAPTCHA from now on.
async fn bypass_on_approval(pool: &SqlitePool, id: i64) -> sqlx::Result<()> {
    let email = sqlx::query_scalar!(
        r#"SELECT email AS "email!" FROM log WHERE user_id = ?1 AND type = 'User' AND subtype = 'Signup' AND COALESCE(email, '') <> ''
           ORDER BY date_time DESC LIMIT 1"#,
        id
    )
    .fetch_optional(pool)
    .await?;
    let Some(email) = email else { return Ok(()) };
    let entry = bypass_entry(&email);
    let now = unix_now();
    sqlx::query!(
        "INSERT INTO captcha_bypass (email, comment, created) SELECT ?1, 'Auto-added on user approval', ?2
         WHERE NOT EXISTS (SELECT 1 FROM captcha_bypass WHERE lower(email) = ?1)",
        entry,
        now
    )
    .execute(pool)
    .await?;
    Ok(())
}

/// user->ipstack(): look the address up at the configured IPStack URL and keep the answer and its currency.
/// Without a configured URL nothing happens; a failed lookup goes to the server log.
pub async fn ipstack(pool: &SqlitePool, id: i64, ip: &str) -> sqlx::Result<()> {
    let url = registry::get(pool, "ipstack", "api_url").await?.unwrap_or_default();
    if url.trim().is_empty() || ip.is_empty() {
        return Ok(());
    }
    let key = registry::get(pool, "ipstack", "api_key").await?.unwrap_or_default();
    let found = match ipstack_lookup(url.trim(), &key, ip).await {
        Ok(found) => found,
        Err(e) => {
            tracing::warn!("IPStack lookup for account {id}: {e}");
            return Ok(());
        }
    };
    let currency = found.get("currency").and_then(Value::as_object);
    let field = |k: &str| currency.and_then(|c| c.get(k)).and_then(Value::as_str).unwrap_or("").to_string();
    let (code, name, plural) = (field("code"), field("name"), field("plural"));
    let json = Value::Object(found).to_string();
    sqlx::query!(r#"UPDATE "user" SET ipstack_json = ?2, currency_code = CASE WHEN ?3 = '' THEN currency_code ELSE ?3 END WHERE id = ?1"#, id, json, code)
        .execute(pool)
        .await?;
    if !code.is_empty() {
        sqlx::query!(
            "INSERT INTO currency (code, name, plural, active) SELECT ?1, ?2, ?3, 1 WHERE NOT EXISTS (SELECT 1 FROM currency WHERE code = ?1)",
            code,
            name,
            plural
        )
        .execute(pool)
        .await?;
    }
    Ok(())
}

async fn ipstack_lookup(url: &str, key: &str, ip: &str) -> Result<Map<String, Value>, String> {
    let client = reqwest::Client::builder().timeout(Duration::from_secs(10)).build().map_err(|e| e.to_string())?;
    let res = client.get(format!("{url}{ip}")).query(&[("access_key", key), ("format", "1")]).send().await.map_err(|_| "HTTP Code: 0".to_string())?;
    if res.status() != StatusCode::OK {
        return Err(format!("HTTP Code: {}", res.status().as_u16()));
    }
    let body = res.text().await.map_err(|e| e.to_string())?;
    match serde_json::from_str::<Value>(&body) {
        Ok(Value::Object(m)) if !m.contains_key("error") => Ok(m),
        Ok(Value::Object(m)) => Err(m.get("error").map(Value::to_string).unwrap_or_default()),
        _ => Err(format!("Invalid response: {body}")),
    }
}

/// gettype() of a decoded JSON value.
fn php_type(v: &Value) -> &'static str {
    match v {
        Value::Null => "NULL",
        Value::Bool(_) => "boolean",
        Value::Number(n) if n.is_f64() => "double",
        Value::Number(_) => "integer",
        Value::String(_) => "string",
        Value::Array(_) => "array",
        Value::Object(_) => "object",
    }
}

fn php_string(v: &Value) -> String {
    match v {
        Value::Null | Value::Bool(false) => String::new(),
        Value::Bool(true) => "1".into(),
        Value::Number(n) => n.as_f64().filter(|_| n.is_f64()).map_or_else(|| n.to_string(), php_float),
        Value::String(s) => s.clone(),
        other => other.to_string(),
    }
}

/// parse_object(): the IPStack answer as nested lists of field, type and value. Keys come out sorted.
fn parse_object(fields: Vec<(String, &Value)>) -> String {
    let mut out = Vec::new();
    for (name, child) in fields {
        out.push(format!("<ul>Field: <b>{}</b> Type: <b>{}</b>", text(&name), php_type(child)));
        match child {
            Value::Object(m) => out.push(parse_object(m.iter().map(|(k, v)| (k.clone(), v)).collect())),
            Value::Array(a) => out.push(parse_object(a.iter().enumerate().map(|(i, v)| (i.to_string(), v)).collect())),
            v => out.push(format!(" Value: <b>{}</b>", text(&php_string(v)))),
        }
        out.push("</ul>".into());
    }
    out.join("\n")
}

/// user->recaptcha_score(): the score and what it means against the registry thresholds. A stored score prints
/// as the decimal column held it; a new account's default prints as PHP printed the float.
async fn recaptcha_score(pool: &SqlitePool, score: f64, stored: bool) -> sqlx::Result<String> {
    let ignore = registry::number(pool, "recaptcha", "score_ignore").await?;
    let spam = registry::number(pool, "recaptcha", "score_spam").await?;
    let verdict = match () {
        _ if ignore.is_some_and(|t| score < t) => "(Reject/Ignore)",
        _ if spam.is_some_and(|t| score < t) => "(Possible Spam)",
        _ => "(OK)",
    };
    let shown = if stored { format!("{score:.1}") } else { php_float(score) };
    Ok(format!("{shown} {verdict}"))
}

fn price_options() -> Vec<(String, String)> {
    [("", "No pricing"), ("base", "Base price"), ("discount", "Discount"), ("plus", "Base plus")].iter().map(|(v, l)| (v.to_string(), l.to_string())).collect()
}

fn row(label: &str, widget: String) -> [String; 4] {
    ["<tr>".into(), format!("<td>{label}</td>"), format!("<td>{widget}</td>"), "</tr>".into()]
}

/// A widget followed by its error text, joined by `sep`, as the page built `implode($sep, $text)`.
fn with_note(notes: &Notes, field: &str, widget: String, sep: &str, wrap: (&str, &str)) -> String {
    match notes.error_text(field) {
        Some(t) => format!("{widget}{sep}{}{t}{}", wrap.0, wrap.1),
        None => widget,
    }
}

struct InfoTab<'a> {
    account: &'a Account,
    salesforce: Option<&'a Salesforce>,
    offer_ipstack: bool,
    viewer: Role,
}

/// tab_info().
async fn info(pool: &SqlitePool, form: &Form<'_>, block: &Block, t: InfoTab<'_>) -> sqlx::Result<Vec<String>> {
    let a = t.account;
    let notes = form.notes;
    let mut out: Vec<String> = vec!["<table class='standard noborder'>".into(), "<tr>".into(), "<td width=30%>IP Address</td>".into()];
    out.push(format!("<td width=70%>{}</td>", form.text("ip", &a.ip, 50, 50, Opts::new())));
    out.push("</tr>".into());
    if !a.comment.is_empty() {
        out.extend(row("Request comment", format!("<b>{}</b>", text(&a.comment))));
    }
    out.push(block.input(pool, form, &a.address).await?);
    out.extend(row("Annual revenue", form.text("revenue", &a.revenue, 20, 20, Opts::new())));
    out.extend(row("Employees", form.text("employees", &a.employees, 20, 20, Opts::new())));
    out.extend(row("Language", form.select("language_code", &choices::languages(pool).await?, &a.language_code, Blank::None, Opts::new())));
    out.extend(row("Currency", form.optgroup("currency_code", &choices::currencies(pool).await?, &a.currency_code, true, Opts::new())));
    out.extend(row("Industry", form.select("industry_id", &choices::industries(pool).await?, &a.industry_id.to_string(), Blank::Empty, Opts::new())));
    let statuses: Vec<&str> = STATUSES.iter().map(|s| status_name(*s)).collect();
    out.extend(row("Status", form.select("status", &list_options(&statuses), status_name(a.status), Blank::None, Opts::new())));
    out.extend(row("Type", form.select("type", &list_options(&roles_up_to(t.viewer)), a.role.as_str(), Blank::None, Opts::new())));
    out.extend(row("Customer #", form.text("customer_code", &a.customer_code, 12, 12, Opts::new())));
    out.extend(row("Lead Score?", form.checkbox("leadscore", "1", a.leadscore, Opts::new())));
    for (field, label, on) in [("catalog", "Catalog?", a.catalog), ("cad", "CAD?", a.cad), ("ecommerce", "ECommerce?", a.ecommerce)] {
        out.extend(row(label, with_note(notes, field, form.checkbox(field, "1", on, Opts::new()), " ", ("", ""))));
    }
    let price_type = form.select("price_type", &price_options(), a.price_type.as_str(), Blank::None, Opts::new().onchange("$('#price_pct').val('');"));
    let pct = a.price_pct * Decimal::ONE_HUNDRED;
    let pct = if pct.is_zero() { String::new() } else { format!("{:.2}", pct.round_dp(2)) };
    let pct = format!("{}%", form.text("price_pct", &pct, 6, 6, Opts::new()));
    out.push("<tr>".into());
    out.push(format!("<td>{}</td>", with_note(notes, "price_type", price_type, "<br>", ("", ""))));
    out.push(format!("<td>{}</td>", with_note(notes, "price_pct", pct, " ", ("<b>", "</b>"))));
    out.push("</tr>".into());
    out.extend(row("Portal", form.select("portal_id", &choices::portals(pool).await?, &a.portal_id.to_string(), Blank::Empty, Opts::new())));
    let documents = choices::documents(pool).await?;
    out.extend(row("Login page", form.optgroup("login_document_id", &documents, &a.login_document_id.to_string(), true, Opts::new())));
    out.extend(row("Landing page", form.optgroup("landing_document_id", &documents, &a.landing_document_id.to_string(), true, Opts::new())));
    if t.offer_ipstack {
        let [open_row, label, widget, _] = row("Load IP Stack?", form.checkbox("ipstack", "1", false, Opts::new()));
        out.extend([open_row.clone(), label, widget, open_row]);
    }
    if let Some(sf) = t.salesforce {
        out.push("<tr><td colspan=2><br><b>Salesforce Integration</b></td></tr>".into());
        out.extend(row("Rating", form.select("sf_rating", &list_options(&["Hot", "Warm", "Cold"]), &sf.rating, Blank::Empty, Opts::new())));
        out.extend(row("Campaign Code", form.select("campaign_code", &choices::campaigns(pool, false, true).await?, &sf.campaign_code, Blank::Empty, Opts::new())));
        out.extend(row("Do not call", form.checkbox("sf_call", "1", sf.call, Opts::new())));
        out.extend(row("Do not email", form.checkbox("sf_email", "1", sf.email, Opts::new())));
        out.extend(row("Do not fax", form.checkbox("sf_fax", "1", sf.fax, Opts::new())));
    }
    if a.last_login != 0 {
        out.extend(row("reCAPTCHA Score", recaptcha_score(pool, a.recaptcha_score, a.id != 0).await?));
        out.extend(row("Last Login", time::date("m/d/Y h:i A", a.last_login)));
    }
    out.push("</table>".into());
    Ok(out)
}

/// tab_category(): every active category, checked where the account may not see it.
async fn categories(pool: &SqlitePool, form: &Form<'_>, id: i64) -> sqlx::Result<Vec<String>> {
    let blocked = sqlx::query_scalar!(r#"SELECT category_id AS "id!: i64" FROM user_category WHERE user_id = ?1"#, id).fetch_all(pool).await?;
    let rows = sqlx::query!(r#"SELECT id AS "id!: i64", name FROM category WHERE active = 1 ORDER BY sort_order, name COLLATE NOCASE"#).fetch_all(pool).await?;
    let mut out = vec!["<table class='standard noborder'>".to_string(), "<tr><td colspan=2>Check to restrict access to a category (Integrator Agreement required)</td></tr>".into()];
    for r in rows {
        let check = form.checkbox(&format!("user_category_{}", r.id), "1", blocked.contains(&r.id), Opts::new());
        out.push("<tr>".into());
        out.push(format!("<td><label style='display:flex;align-items:center;gap:8px;cursor:pointer;'>{check}{}</label></td>", text(&r.name.unwrap_or_default())));
        out.push("</tr>".into());
    }
    out.push("</table>".into());
    Ok(out)
}

/// The list under the filters: accounts at or below the viewer's role, by company name and address, a page at a time.
async fn list(pool: &SqlitePool, form: &Form<'_>, report: &Report, viewer: Role) -> sqlx::Result<(String, i64)> {
    let rank = ROLES.iter().position(|r| *r == viewer).unwrap_or(0) as i64;
    let page = report.page.max(1);
    let offset = (page - 1) * PAGE_ROWS;
    let (kind, status, field, value) = (&report.kind, &report.status, &report.option_field, &report.option_value);
    let total = sqlx::query_scalar!(
        r#"SELECT count(*) AS "n!: i64" FROM "user"
           WHERE CASE "type" WHEN 'Customer' THEN 0 WHEN 'External' THEN 1 WHEN 'Internal' THEN 2 WHEN 'Executive' THEN 3 WHEN 'Administrator' THEN 4 ELSE 5 END <= ?1
             AND (?2 = '' OR "type" = ?2) AND (?3 = '' OR status = ?3)
             AND (?5 = '' OR (CASE ?4 WHEN 'company_name' THEN company_name WHEN 'ip' THEN ip WHEN 'domain' THEN domain END) LIKE ?5 || '%')"#,
        rank,
        kind,
        status,
        field,
        value
    )
    .fetch_one(pool)
    .await?;
    let rows = sqlx::query!(
        r#"SELECT id AS "id!: i64", ip, company_name, city, state, zip, country_code, status FROM "user"
           WHERE CASE "type" WHEN 'Customer' THEN 0 WHEN 'External' THEN 1 WHEN 'Internal' THEN 2 WHEN 'Executive' THEN 3 WHEN 'Administrator' THEN 4 ELSE 5 END <= ?1
             AND (?2 = '' OR "type" = ?2) AND (?3 = '' OR status = ?3)
             AND (?5 = '' OR (CASE ?4 WHEN 'company_name' THEN company_name WHEN 'ip' THEN ip WHEN 'domain' THEN domain END) LIKE ?5 || '%')
           ORDER BY company_name COLLATE NOCASE, ip LIMIT ?6 OFFSET ?7"#,
        rank,
        kind,
        status,
        field,
        value,
        PAGE_ROWS,
        offset
    )
    .fetch_all(pool)
    .await?;
    let mut out = String::new();
    for r in rows {
        let ip = r.ip.unwrap_or_default();
        let id = js(&r.id.to_string());
        let del = if r.status == "Active" {
            "&nbsp;".to_string()
        } else {
            form.radio("delete", &r.id.to_string(), false, Opts::new().onclick(format!("fn_action(this,'delete',{id},{});", js(&ip))))
        };
        let cells = [r.company_name, r.city, r.state, r.zip, r.country_code, Some(r.status)].map(|c| format!("<td>{}</td>", text(&c.unwrap_or_default()))).concat();
        out.push_str(&format!(
            "<tr><td>{}</td>{cells}<td class=center>{del}</td></td></tr>",
            href(&text(&ip), &format!("javascript:fn_action(this,'maintain',{id},'');"), Opts::new())
        ));
    }
    Ok((out, total))
}

const SCRIPT: &str = "<script>
function fn_action(obj,action,id,name) {
console.log(action);
\tif ( (action == 'delete') && (!confirm('Delete: #' + id + ' ' + name)) ) {
        obj.checked=false;
        return;
    }
\tdocument.scs_form.action.value=action;
\tdocument.scs_form.record_id.value=id;
\tdocument.scs_form.submit();
}
</script>
";

/// A return address the page may send the browser to after a save: a path on this site.
fn local_return(url: &str) -> Option<&str> {
    let safe = url.starts_with('/') && !url.starts_with("//") && !url.contains('\\') && !url.chars().any(char::is_control);
    safe.then_some(url)
}

async fn run(pool: &SqlitePool, staff: &Staff<Administrator>, request: &Fields, posted: &Fields, is_post: bool) -> sqlx::Result<Response> {
    let viewer = staff.who.user.role;
    let mut action = Action::parse(request.get("action"));
    if !is_post && matches!(action, Action::Update | Action::Delete) {
        action = Action::None;
    }
    let record_id = request.int("record_id");
    let return_url = request.get("return_url").to_string();
    let report = Report::from(posted);
    let source = if record_id != 0 { record_id } else { posted.int("ajax_user_id") };
    let lookup = if source != 0 { read(pool, source, viewer).await? } else { Lookup::Missing };
    let mut account = match lookup {
        Lookup::Found(a) => *a,
        Lookup::Missing => Account::new(staff.client, unix_now()),
        Lookup::Forbidden => {
            action = Action::None;
            Account::new(staff.client, unix_now())
        }
    };
    let block = Block::load(pool, "profile").await;
    let mut notes = Notes::new(TITLE, "");
    let mut sf = None;
    let mut edit = action == Action::Maintain;
    match action {
        Action::Cancel => notes.say("Request cancelled"),
        Action::Delete => {
            sqlx::query!(r#"DELETE FROM "user" WHERE id = ?1"#, account.id).execute(pool).await?;
            notes.say(&format!("IP address {} deleted", account.ip));
        }
        Action::Update => {
            let previous = account.status;
            take_posted(&mut account, posted, viewer, &block, &mut notes);
            if !return_url.is_empty() {
                sf = Some(salesforce(posted));
            }
            if notes.has_errors() {
                edit = true;
            } else {
                let created = account.id == 0;
                account.id = save(pool, &account, posted).await?;
                notes.say("Record maintained");
                if previous != Status::Active && account.status == Status::Active {
                    bypass_on_approval(pool, account.id).await?;
                }
                if created || posted.checked("ipstack") {
                    ipstack(pool, account.id, &account.ip).await?;
                }
                if let Some(to) = local_return(&return_url) {
                    return Ok((StatusCode::FOUND, [(header::LOCATION, to.to_string()), (header::CACHE_CONTROL, "no-store".to_string())]).into_response());
                }
            }
        }
        Action::None | Action::List | Action::Maintain => {}
    }
    if edit && sf.is_none() && !return_url.is_empty() {
        sf = Some(Salesforce::default());
    }
    let form = Form { notes: &notes };
    let mut body = notes.render();
    body.push_str(SCRIPT);
    body.push_str(&open(PATH, staff.csrf.as_str(), false));
    body.push_str(&hidden("action", ""));
    body.push_str(&hidden("record_id", ""));
    body.push_str(&hidden("page", ""));
    body.push_str(&hidden("return_url", &return_url));
    if edit {
        for (field, value) in [("report_status", &report.status), ("report_type", &report.kind), ("report_option_field", &report.option_field), ("report_option_value", &report.option_value)] {
            body.push_str(&hidden(field, value));
        }
        let ipstack = parse_object(account.ipstack.iter().map(|(k, v)| (k.clone(), v)).collect());
        let mut tabs = Tabs::new();
        let tab = InfoTab { account: &account, salesforce: sf.as_ref(), offer_ipstack: ipstack.is_empty(), viewer };
        tabs.lines("Info", &info(pool, &form, &block, tab).await?);
        tabs.lines("Category Access", &categories(pool, &form, account.id).await?);
        tabs.item("IPStack", ipstack);
        body.push_str(&tabs.render());
        let update = button("Update", Opts::new().onclick(format!("fn_action(this,'update',{},'');", js(request.get("record_id")))));
        let cancel = button("Cancel", Opts::new().onclick("fn_action(this,'cancel','0','');"));
        body.push_str(&format!("<p>{update} {cancel}</p>"));
    } else {
        let types = roles_up_to(viewer);
        let statuses: Vec<&str> = STATUSES.iter().map(|s| status_name(*s)).collect();
        let fields = [("company_name", "Company Name"), ("ip", "IP Address"), ("domain", "Domain")].map(|(v, l)| (v.to_string(), l.to_string()));
        body.push_str("<table class='standard noborder'>");
        body.push_str(&format!("<tr><td width='20%'>Type</td><td width='80%'>{}</td></tr>", form.select("report_type", &list_options(&types), &report.kind, Blank::Empty, Opts::new())));
        body.push_str(&format!("<tr><td width='20%'>Status</td><td width='80%'>{}</td></tr>", form.select("report_status", &list_options(&statuses), &report.status, Blank::Empty, Opts::new())));
        body.push_str(&format!(
            "<tr><td width='20%'>{}</td><td width='80%'>{}</td></tr>",
            form.select("report_option_field", &fields, &report.option_field, Blank::None, Opts::new()),
            form.text("report_option_value", &report.option_value, 30, 0, Opts::new())
        ));
        body.push_str("</table>");
        body.push_str(&format!("<p>{}</p>", button("List", Opts::new().onclick("fn_action(this,'list','','');"))));
        body.push_str("<table class='small border tablesorter'><caption>&nbsp;</caption><thead><tr><th>IP</th><th>Company Name</th><th>City</th><th>State</th><th>Zip</th><th>Country</th><th>Status</th><th>Del?</th></tr></thead>");
        body.push_str(&format!(
            "<tr><td class=standard colspan=5>Copy from: {} {}<br><span id='ajax_user_label'></span></td></tr>",
            form.text("ajax_user_id", "", 8, 0, Opts::new()),
            button("Add New", Opts::new().onclick("fn_action(this,'maintain','0','');"))
        ));
        body.push_str("<tbody>");
        let listed = if action == Action::List { Some(list(pool, &form, &report, viewer).await?) } else { None };
        if let Some((rows, _)) = &listed {
            body.push_str(rows);
        }
        body.push_str("</tbody></table>");
        if let Some((_, total)) = listed {
            body.push_str(&page_items(total, report.page, "fn_page_item('list',%page%,00);"));
        }
    }
    body.push_str(close());
    Ok(shell::page(pool, staff.view(), TITLE, &body).await)
}

async fn show(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, RawQuery(q): RawQuery) -> Response {
    let query = Fields::from_query(q.as_deref());
    run(&auth.pool, &staff, &query, &Fields::default(), false).await.unwrap_or_else(shell::internal)
}

async fn post(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, RawQuery(q): RawQuery, posted: Posted) -> Response {
    let request = posted.fields.clone().with_query(&Fields::from_query(q.as_deref()));
    run(&auth.pool, &staff, &request, &posted.fields, true).await.unwrap_or_else(shell::internal)
}

pub fn routes() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(show).post(post))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_canonical_dotted_quads_are_addresses() {
        assert!(valid_ip("198.51.100.7"));
        assert!(!valid_ip("0.0.0.0"));
        assert!(!valid_ip(" 198.51.100.7"));
        assert!(!valid_ip("198.51.100"));
        assert!(!valid_ip("2001:db8::1"));
    }

    #[test]
    fn numbers_read_like_fn_number() {
        assert_eq!(php_number("15"), Decimal::from(15));
        assert_eq!(php_number(" 12.5 %"), Decimal::from_str("0.125").unwrap());
        assert_eq!(php_number("abc"), Decimal::ZERO);
        assert_eq!(php_number("1.2.3"), Decimal::from_str("1.2").unwrap());
    }

    fn priced(catalog: bool, kind: PriceType, pct: &str) -> (Account, Notes) {
        let mut a = Account::new(None, 0);
        a.catalog = catalog;
        a.price_type = kind;
        a.price_pct = Decimal::from_str(pct).unwrap();
        let mut notes = Notes::new(TITLE, "");
        check_rights(&mut a, &mut notes);
        (a, notes)
    }

    #[test]
    fn pricing_needs_the_catalog_and_a_percentage_in_range() {
        assert_eq!(priced(false, PriceType::Discount, "0.1").1.error_text("price_type"), Some("Pricing requires catalog access"));
        assert!(!priced(true, PriceType::Discount, "0.1").1.has_errors());
        assert!(priced(true, PriceType::Discount, "1").1.is_error("price_pct"));
        assert!(priced(true, PriceType::Plus, "4.99").1.error_text("price_pct").is_none());
        assert!(priced(true, PriceType::Plus, "5").1.is_error("price_pct"));
        assert!(priced(true, PriceType::Base, "0.01").1.is_error("price_pct"));
        assert_eq!(priced(true, PriceType::None, "0.3").0.price_pct, Decimal::ZERO);
    }

    #[test]
    fn a_portal_rules_out_landing_documents() {
        let mut a = Account::new(None, 0);
        a.portal_id = 3;
        a.landing_document_id = 9;
        let mut notes = Notes::new(TITLE, "");
        check_rights(&mut a, &mut notes);
        assert!(notes.is_error("portal_id"));
        assert!(notes.render().contains("<br>Landing documents not allowed when portal is selected<br>Correct 1 error(s)"));
    }

    #[test]
    fn approvals_bypass_the_company_domain_but_not_free_mail() {
        assert_eq!(bypass_entry(" Buyer@Example.COM "), "@example.com");
        assert_eq!(bypass_entry("someone@gmail.com"), "someone@gmail.com");
        assert_eq!(bypass_entry("no-at-sign"), "no-at-sign");
    }

    #[test]
    fn returns_stay_on_the_site() {
        assert_eq!(local_return("/dashboard.php?tab=Pending"), Some("/dashboard.php?tab=Pending"));
        assert_eq!(local_return("https://example.com/"), None);
        assert_eq!(local_return("//example.com/"), None);
        assert_eq!(local_return("/\\example.com"), None);
    }

    #[test]
    fn ipstack_answers_print_as_nested_lists() {
        let v: Value = serde_json::from_str(r#"{"ip":"198.51.100.7","currency":{"code":"USD"},"is_eu":false}"#).unwrap();
        let Value::Object(m) = v else { panic!() };
        assert_eq!(
            parse_object(m.iter().map(|(k, v)| (k.clone(), v)).collect()),
            "<ul>Field: <b>currency</b> Type: <b>object</b>\n<ul>Field: <b>code</b> Type: <b>string</b>\n Value: <b>USD</b>\n</ul>\n</ul>\n<ul>Field: <b>ip</b> Type: <b>string</b>\n Value: <b>198.51.100.7</b>\n</ul>\n<ul>Field: <b>is_eu</b> Type: <b>boolean</b>\n Value: <b></b>\n</ul>"
        );
    }
}
