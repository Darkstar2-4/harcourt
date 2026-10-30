//! checkout.php: the order form, the order header and lines, and the staff and customer mails.
//!
//! PHP prefilled the form from a year-long `harcourt_ecommerce` cookie holding the buyer's contact details and
//! kept the half-filled form in the PHP session. Here nothing about the buyer is written to a cookie: the form is
//! prefilled from this account's last order placed with the same signed-in address, and a form with errors is
//! shown again from what was posted.

use std::{collections::HashMap, sync::Arc};

use axum::{
    body::Bytes,
    extract::State,
    http::{HeaderMap, Uri},
    response::Response,
};
use rust_decimal::Decimal;
use sqlx::SqlitePool;

use super::{
    cart::{self, Line},
    quoted, shopper, Labels, MoneyFormat, Shopper,
};
use crate::{
    api::{ApiError, ClientIp},
    db::unix_now,
    identity::{Email, Role},
    orders::{
        form_params,
        html::{self, esc, esc_text},
        mail::{self, Mailbox},
        mailform::{self, Letterhead, Merge, Registry},
        Orders,
    },
    visitor::Visitor,
};

#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Address {
    pub address: String,
    pub city: String,
    pub state: String,
    pub zip: String,
    pub country_code: String,
}

/// An order header as the form knows it (`orderhd_data_class`).
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Order {
    pub id: i64,
    pub order_date: i64,
    pub email: String,
    pub name_first: String,
    pub name_last: String,
    pub name: String,
    pub company_name: String,
    pub phone: String,
    pub ship: Address,
    pub billing: Address,
    pub po: String,
    pub comment: String,
    pub cc: String,
    pub carrier_name: String,
    pub carrier_account: String,
    pub currency_code: String,
}

/// Fields the form marked wrong, by input name, with the text forms_rev2 keeps for them.
#[derive(Debug, Default)]
pub struct Errors(Vec<(String, String)>);

impl Errors {
    fn add(&mut self, field: &str, text: &str) {
        if !self.has(field) {
            self.0.push((field.to_string(), text.to_string()));
        }
    }

    pub fn has(&self, field: &str) -> bool {
        self.0.iter().any(|(f, _)| f == field)
    }

    fn text(&self, field: &str) -> &str {
        self.0.iter().find(|(f, _)| f == field).map_or("", |(_, t)| t.as_str())
    }

    pub fn len(&self) -> usize {
        self.0.len()
    }

    pub fn is_empty(&self) -> bool {
        self.0.is_empty()
    }
}

/// `fn_text()`: a `<script ...>` run removed from each line, then trimmed; upper-cased when asked.
pub fn fn_text(s: &str, upper: bool) -> String {
    let cleaned: Vec<String> = s
        .split('\n')
        .map(|line| {
            let lower = line.to_ascii_lowercase();
            match (lower.find("<script"), lower.rfind('>')) {
                (Some(start), Some(end)) if end > start => format!("{}{}", &line[..start], &line[end + 1..]),
                _ => line.to_string(),
            }
        })
        .collect();
    let out = cleaned.join("\n").trim().to_string();
    if upper { out.to_uppercase() } else { out }
}

/// The address fields the registry's `address` module lists for a table (`orderhd`, `orderhd_billing`), each with
/// whether it is required. Without registry rows PHP stops; here the production settings apply.
async fn address_fields(pool: &SqlitePool, table: &str) -> sqlx::Result<Vec<(String, bool)>> {
    let listed = sqlx::query!(
        r#"SELECT item AS "item!: String", coalesce(data, '') AS "data!: String" FROM registry
           WHERE module = 'address' AND field = ?1 AND item <> '' AND domain IN ('', 'global') ORDER BY rowid"#,
        table
    )
    .fetch_all(pool)
    .await?;
    if !listed.is_empty() {
        return Ok(listed.into_iter().map(|r| (r.item, r.data == "required")).collect());
    }
    let defaults: &[&str] = match table {
        "orderhd" => &["name", "company_name", "address", "city", "state", "zip", "country_code", "email", "phone"],
        _ => &["address", "city", "state", "zip", "country_code"],
    };
    Ok(defaults.iter().map(|s| (s.to_string(), true)).collect())
}

async fn required(pool: &SqlitePool, table: &str) -> sqlx::Result<Vec<String>> {
    Ok(address_fields(pool, table).await?.into_iter().filter_map(|(f, required)| required.then_some(f)).collect())
}

fn state_field(country: &str) -> &'static str {
    match country {
        "US" => "state_us",
        "CA" => "state_ca",
        _ => "state_other",
    }
}

const LABELS: [(&str, &str); 6] = [("company_name", "Company Name"), ("address", "Address"), ("city", "City"), ("state", "State/Province"), ("zip", "Zip/Postal Code"), ("phone", "Phone")];

fn label(field: &str) -> &'static str {
    LABELS.iter().find(|(f, _)| *f == field).map_or("Field", |(_, l)| l)
}

/// `address_class::verify()` for one prefix: reads the posted fields and marks the required ones left blank.
fn read_address(p: &HashMap<String, String>, table: &str, required: &[String], errors: &mut Errors) -> Address {
    let get = |f: &str| p.get(&format!("{table}_{f}")).map_or("", String::as_str);
    let country_code = fn_text(get("country_code"), false);
    let state_input = state_field(&country_code);
    let a = Address {
        address: fn_text(get("address"), false),
        city: fn_text(get("city"), false),
        state: fn_text(get(state_input), false),
        zip: fn_text(get("zip"), true),
        country_code,
    };
    for (field, value, input) in [("address", &a.address, "address"), ("city", &a.city, "city"), ("state", &a.state, state_input), ("zip", &a.zip, "zip"), ("country_code", &a.country_code, "country_code")] {
        if value.is_empty() && required.iter().any(|r| r == field) {
            let text = if field == "country_code" { "Country cannot be blank".to_string() } else { format!("{} cannot be blank", label(field)) };
            errors.add(&format!("{table}_{input}"), &text);
        }
    }
    a
}

/// Reads and checks the posted checkout form into `order`.
pub async fn read_form(pool: &SqlitePool, p: &HashMap<String, String>, order: &mut Order) -> sqlx::Result<Errors> {
    let mut errors = Errors::default();
    let req = required(pool, "orderhd").await?;
    let needs = |f: &str| req.iter().any(|r| r == f);
    let get = |f: &str| p.get(f).map_or("", String::as_str);
    order.ship = read_address(p, "orderhd", &req, &mut errors);
    order.company_name = fn_text(get("orderhd_company_name"), false);
    if needs("company_name") && order.company_name.is_empty() {
        errors.add("orderhd_company_name", "Company Name cannot be blank");
    }
    order.name_first = fn_text(get("orderhd_name_first"), false);
    order.name_last = fn_text(get("orderhd_name_last"), false);
    if needs("name") {
        if order.name_first.is_empty() {
            errors.add("orderhd_name_first", "First Name cannot be blank");
        }
        if order.name_last.is_empty() {
            errors.add("orderhd_name_last", "Last Name cannot be blank");
        }
    }
    order.name = format!("{} {}", order.name_first, order.name_last).trim().to_string();
    order.phone = fn_text(get("orderhd_phone"), false);
    if needs("phone") && order.phone.is_empty() {
        errors.add("orderhd_phone", "Phone cannot be blank");
    }
    let typed = get("orderhd_email").trim();
    match Email::parse(typed) {
        Some(e) => order.email = e.as_str().to_string(),
        None => {
            order.email = fn_text(typed, false);
            if typed.is_empty() {
                if needs("email") {
                    errors.add("orderhd_email", "Email address cannot be blank");
                }
            } else {
                errors.add("orderhd_email", "Invalid email format");
            }
        }
    }
    order.carrier_name = get("carrier_name").to_string();
    if order.carrier_name.is_empty() {
        errors.add("carrier_name", "");
    }
    order.carrier_account = fn_text(get("carrier_account"), false);
    order.po = fn_text(get("po"), true);
    order.comment = fn_text(get("comment"), false);
    order.cc = fn_text(get("cc"), false);
    if !order.cc.is_empty() && !(order.cc.len() == 4 && order.cc.bytes().all(|b| b.is_ascii_digit())) {
        errors.add("cc", "Valid entries are 4 digits or blank");
    }
    if p.contains_key("billing_same_as_shipping") {
        order.billing = order.ship.clone();
    } else {
        let breq = required(pool, "orderhd_billing").await?;
        order.billing = read_address(p, "orderhd_billing", &breq, &mut errors);
    }
    Ok(errors)
}

/// The prefill: this account's last order placed with the same signed-in address. Without one, as ecommerce_class
/// did without its cookie: the address the browser remembers (`harcourt`) and the account's company.
async fn prefill(pool: &SqlitePool, shopper: &Shopper, remembered: &str) -> sqlx::Result<Order> {
    let email = shopper.email.clone().unwrap_or_default();
    let last = sqlx::query!(
        r#"SELECT coalesce(name_first, '') AS "name_first!: String", coalesce(name_last, '') AS "name_last!: String",
                  coalesce(name, '') AS "name!: String", coalesce(company_name, '') AS "company_name!: String",
                  coalesce(phone, '') AS "phone!: String", coalesce(address, '') AS "address!: String",
                  coalesce(city, '') AS "city!: String", coalesce(state, '') AS "state!: String", coalesce(zip, '') AS "zip!: String",
                  coalesce(country_code, '') AS "country_code!: String", coalesce(carrier_name, '') AS "carrier_name!: String",
                  coalesce(carrier_account, '') AS "carrier_account!: String"
           FROM orderhd WHERE user_id = ?1 AND email = ?2 AND ?2 <> '' ORDER BY id DESC LIMIT 1"#,
        shopper.user.id.0,
        email
    )
    .fetch_optional(pool)
    .await?;
    let mut order = Order { email: remembered.to_string(), currency_code: shopper.currency.code.clone(), ..Order::default() };
    match last {
        Some(l) => {
            order.email = email;
            order.name_first = l.name_first;
            order.name_last = l.name_last;
            order.name = l.name;
            order.company_name = l.company_name;
            order.phone = l.phone;
            order.ship = Address { address: l.address, city: l.city, state: l.state, zip: l.zip, country_code: l.country_code };
            order.carrier_name = l.carrier_name;
            order.carrier_account = l.carrier_account;
        }
        None => order.company_name = shopper.user.company_name.clone().unwrap_or_default(),
    }
    Ok(order)
}

/// Writes the header and its lines in one transaction. Returns the order number.
pub async fn place(pool: &SqlitePool, user_id: i64, order: &Order, lines: &[Line], now: i64) -> sqlx::Result<i64> {
    let mut tx = pool.begin().await?;
    let o = order;
    let id = sqlx::query_scalar!(
        r#"INSERT INTO orderhd (user_id, punchout_id, order_date, email, name_first, name_last, name, company_name, address, city,
               state, zip, country_code, phone, po, comment, type, status, status_user_id, status_date, carrier_name,
               carrier_account, currency_code, cc, billing_address, billing_city, billing_state, billing_zip, billing_country_code)
           VALUES (?1, 0, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, ?12, ?13, ?14, ?15, 'PO', 'Pending', 0, 0, ?16, ?17, ?18, ?19,
               ?20, ?21, ?22, ?23, ?24)
           RETURNING id AS "id!: i64""#,
        user_id,
        now,
        o.email,
        o.name_first,
        o.name_last,
        o.name,
        o.company_name,
        o.ship.address,
        o.ship.city,
        o.ship.state,
        o.ship.zip,
        o.ship.country_code,
        o.phone,
        o.po,
        o.comment,
        o.carrier_name,
        o.carrier_account,
        o.currency_code,
        o.cc,
        o.billing.address,
        o.billing.city,
        o.billing.state,
        o.billing.zip,
        o.billing.country_code
    )
    .fetch_one(&mut *tx)
    .await?;
    for (n, line) in lines.iter().enumerate() {
        let line_no = n as i64 + 1;
        let (price, extended) = quoted(&line.price).unwrap_or((Decimal::ZERO, Decimal::ZERO));
        let price = price.round_dp(5).to_string();
        let extended = extended.round_dp(2).to_string();
        let quantity = i64::from(line.quantity);
        sqlx::query!(
            "INSERT INTO orderln (order_id, line_no, sku, description, quantity, price, extended, comment) VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8)",
            id,
            line_no,
            line.sku,
            line.description,
            quantity,
            price,
            extended,
            line.comment
        )
        .execute(&mut *tx)
        .await?;
    }
    tx.commit().await?;
    Ok(id)
}

pub struct Choices {
    /// Country and state names by `US` and `US/MI`, for showing an address.
    pub names: HashMap<String, String>,
    pub countries: Vec<(String, String)>,
    pub us: Vec<(String, String)>,
    pub ca: Vec<(String, String)>,
    pub carriers: Vec<(String, String)>,
    /// The fields the registry's `address` module lists for `orderhd` and `orderhd_billing`, and which it requires.
    pub ship_fields: Vec<(String, bool)>,
    pub billing_fields: Vec<(String, bool)>,
}

impl Choices {
    pub fn none() -> Self {
        Choices { names: HashMap::new(), countries: Vec::new(), us: Vec::new(), ca: Vec::new(), carriers: Vec::new(), ship_fields: Vec::new(), billing_fields: Vec::new() }
    }

    /// Only the names the given addresses need, for an order shown rather than edited.
    pub async fn names_for(pool: &SqlitePool, addresses: &[&Address]) -> sqlx::Result<Self> {
        let mut c = Choices::none();
        for a in addresses {
            let rows = sqlx::query!(
                r#"SELECT country AS "country!: String", state AS "state!: String", coalesce(name, '') AS "name!: String" FROM country
                   WHERE country = ?1 AND (state = '' OR state = ?2)"#,
                a.country_code,
                a.state
            )
            .fetch_all(pool)
            .await?;
            for r in rows {
                let key = if r.state.is_empty() { r.country } else { format!("{}/{}", r.country, r.state) };
                c.names.insert(key, r.name);
            }
        }
        Ok(c)
    }

    /// What the entry form offers, as country_class::select() and carrier_class::select() listed it: active
    /// countries with the US and Canada first, every US state and Canadian province, every carrier after the
    /// blank "Shipping Carrier".
    pub async fn load(pool: &SqlitePool) -> sqlx::Result<Self> {
        let rows = sqlx::query!(
            r#"SELECT country AS "country!: String", state AS "state!: String", coalesce(name, '') AS "name!: String" FROM country
               WHERE (state = '' AND active = 1) OR (state <> '' AND country IN ('US', 'CA'))
               ORDER BY CASE country WHEN 'US' THEN 1 WHEN 'CA' THEN 2 ELSE 3 END, name"#
        )
        .fetch_all(pool)
        .await?;
        let mut c = Choices::none();
        for r in rows {
            match (r.state.is_empty(), r.country.as_str()) {
                (true, _) => c.countries.push((r.country, r.name)),
                (false, "US") => c.us.push((r.state, r.name)),
                (false, _) => c.ca.push((r.state, r.name)),
            }
        }
        c.carriers = std::iter::once((String::new(), "Shipping Carrier".to_string()))
            .chain(
                sqlx::query_scalar!(r#"SELECT name AS "name!: String" FROM carrier WHERE name IS NOT NULL ORDER BY name"#)
                    .fetch_all(pool)
                    .await?
                    .into_iter()
                    .map(|n| (n.clone(), n)),
            )
            .collect();
        c.ship_fields = address_fields(pool, "orderhd").await?;
        c.billing_fields = address_fields(pool, "orderhd_billing").await?;
        Ok(c)
    }
}

fn row(label: &str, value: &str) -> String {
    format!("<tr><td width='20%'>{label}</td><td width='80%'>{value}</td></tr>")
}

/// The order address_class lays its fields out in.
const ADDRESS_ORDER: [&str; 11] = ["company_name", "name", "title", "address", "city", "state", "zip", "country_code", "phone", "fax", "email"];

const STAR: &str = "<font color=red><b>*</b></font>";

/// address_class::input(): a row for each field the registry lists for `table` and the block does not omit,
/// starred when required, then the legend and the script that fits the state input to the country.
fn address_input<'a>(table: &str, fields: &[(String, bool)], omit: &[&str], value: impl Fn(&str) -> &'a str, choices: &Choices, errors: &Errors) -> String {
    let f = |name: &str| format!("{table}_{name}");
    let text = |kind: &str, name: &str, size: u32, max: u32, placeholder: &str| html::text_input(kind, &f(name), value(name), size, max, placeholder, errors.has(&f(name)));
    let mut out = String::new();
    for field in ADDRESS_ORDER.iter().filter(|f| !omit.contains(f)) {
        let Some(required) = fields.iter().find(|(name, _)| name == field).map(|(_, required)| *required) else { continue };
        let star = if required { STAR } else { "&nbsp;" };
        let mut row = |title: &str, content: String| out.push_str(&format!("<tr ><td width='20%'>{star}{title}</td><td width='80%'>{content}</td></tr>"));
        match *field {
            "company_name" => row("Company Name", text("text", "company_name", 40, 40, "Company Name")),
            "name" => {
                row("First Name", text("text", "name_first", 40, 40, "First Name"));
                row("Last Name", text("text", "name_last", 40, 40, "Last Name"));
            }
            "title" => row("Title", text("text", "title", 40, 40, "Title")),
            "address" => row("Address", text("text", "address", 40, 100, "Address")),
            "city" => row("City", text("text", "city", 40, 40, "City")),
            "state" => {
                let state = value("state");
                let inputs = [
                    html::select(&f("state_us"), &choices.us, state, Some("Select State"), "", errors.has(&f("state_us"))),
                    html::select(&f("state_ca"), &choices.ca, state, Some("Select Province"), "", errors.has(&f("state_ca"))),
                    html::text_input("text", &f("state_other"), state, 40, 40, "Province/State", errors.has(&f("state_other"))),
                ];
                row(&format!("<span id={}></span>", f("state_text")), inputs.join(" "));
            }
            "zip" => row(&format!("<span id={}></span>", f("zip_text")), text("text", "zip", 25, 40, "Zip/Postal Code/Zip Code")),
            "country_code" => {
                let onchange = format!("fn_country_address({});", html::js_str(table));
                row("Country", html::select(&f("country_code"), &choices.countries, value("country_code"), Some("Select Country"), &onchange, errors.has(&f("country_code"))));
            }
            "phone" => row("Phone", text("text", "phone", 40, 40, "Phone")),
            "fax" => row("Fax", text("text", "fax", 40, 40, "Fax")),
            _ => row("Email", text("email", "email", 40, 60, "Email")),
        }
    }
    out.push_str(&format!("<tr ><td colspan=2>{STAR}Indicates mandatory fields</td></tr>"));
    out.push_str(&format!("\n<script>\n\nfn_country_address({});\n{}\n\n</script>\n", html::js_str(table), crate::accounts::profile::COUNTRY_JS));
    out
}

/// `address_class::output("table")`: the non-empty fields in the class's order, state and country by name.
fn address_output(rows: &[(&str, String)]) -> String {
    rows.iter()
        .filter(|(_, v)| !v.is_empty())
        .map(|(label, v)| {
            let shown = if *label == "Address" { esc_text(v).replace('\n', "<br>") } else { esc_text(v) };
            row(label, &shown)
        })
        .collect::<Vec<_>>()
        .join("\n")
}

fn place_rows(a: &Address, choices: &Choices) -> Vec<(&'static str, String)> {
    let state = choices.names.get(&format!("{}/{}", a.country_code, a.state)).cloned().unwrap_or_else(|| a.state.clone());
    let country = choices.names.get(&a.country_code).cloned().unwrap_or_else(|| a.country_code.clone());
    vec![("Address", a.address.clone()), ("City", a.city.clone()), ("State/Province", state), ("Zip/Postal Code", a.zip.clone()), ("Country", country)]
}

fn date(ts: i64) -> String {
    chrono::DateTime::from_timestamp(ts, 0).unwrap_or_default().with_timezone(&chrono_tz::America::Detroit).format("%m/%d/%Y %I:%M%p").to_string()
}

/// `$database->orderhd->output_header()`: the entry form, or the placed order.
fn header(o: &Order, entry: bool, choices: &Choices, errors: &Errors, local: &Registry, billing_same: bool) -> String {
    let mut r: Vec<String> = vec!["<table class=noborder>".into(), "<tr>".into(), "<td width='60%'>".into(), "<table class='checkout_entry noborder'>".into()];
    if o.id != 0 {
        r.push("<tr><td class='checkout_caption' colspan=2>Order Information</td></tr>".into());
        r.push(format!("<tr><td>Order #</td><td>{}</tr>", o.id));
        r.push(format!("<tr><td>Order Date/ Time</td><td>{}</tr>", date(o.order_date)));
    }
    r.push("<tr><td class='checkout_caption' colspan=2>Customer Information</td></tr>".into());
    let t = |field: &str, value: &str, max: u32, ph: &str| html::text_input("text", field, value, 40, max, ph, errors.has(field));
    let ship = |field: &str| -> &str {
        match field {
            "company_name" => &o.company_name,
            "name_first" => &o.name_first,
            "name_last" => &o.name_last,
            "phone" => &o.phone,
            "email" => &o.email,
            "address" => &o.ship.address,
            "city" => &o.ship.city,
            "state" => &o.ship.state,
            "zip" => &o.ship.zip,
            "country_code" => &o.ship.country_code,
            _ => "",
        }
    };
    let billing = |field: &str| -> &str {
        match field {
            "address" => &o.billing.address,
            "city" => &o.billing.city,
            "state" => &o.billing.state,
            "zip" => &o.billing.zip,
            "country_code" => &o.billing.country_code,
            _ => "",
        }
    };
    if entry {
        r.push(address_input("orderhd", &choices.ship_fields, &["company_name", "address", "city", "state", "zip", "country_code"], ship, choices, errors));
    } else {
        r.push(address_output(&[("Name", o.name.clone()), ("Phone", o.phone.clone()), ("Email", o.email.clone())]));
    }
    r.push("<tr><td class='checkout_caption' colspan=2>Shipping Address</td></tr>".into());
    r.push(if entry {
        address_input("orderhd", &choices.ship_fields, &["name", "title", "email", "phone", "fax"], ship, choices, errors)
    } else {
        let mut rows = vec![("Company Name", o.company_name.clone())];
        rows.extend(place_rows(&o.ship, choices));
        address_output(&rows)
    });
    r.push("<tr><td class='checkout_caption' colspan=2>Billing Address</td></tr>".into());
    if entry {
        let checked = if billing_same { " checked" } else { "" };
        r.push(format!("<tr><td colspan=2><input type=checkbox name='billing_same_as_shipping' id='billing_same_as_shipping' value='1' onclick=\"javascript:fn_billing_same_as_shipping(this.checked);\"{checked}> Same as shipping address</td></tr>"));
    }
    r.push(if entry {
        address_input("orderhd_billing", &choices.billing_fields, &["name", "title", "email", "phone", "fax", "company_name"], billing, choices, errors)
    } else {
        address_output(&place_rows(&o.billing, choices))
    });
    r.push("<tr><td class='checkout_caption' colspan=2>Order Information</td></tr>".into());
    if entry {
        r.push(format!("<tr><td>PO#</td><td>{}</td></tr>", t("po", &o.po, 45, "PO# (Optional)")));
        r.push(format!("<tr><td>Comment</td><td>{}</td></tr>", html::text_input("text", "comment", &o.comment, 40, 60, "Comment (Optional)", errors.has("comment"))));
        let mut cc = vec![format!("Last 4 digits: {}", html::text_input("text", "cc", &o.cc, 4, 4, "Optional", errors.has("cc")))];
        if !errors.text("cc").is_empty() {
            cc.push(errors.text("cc").to_string());
        }
        r.extend(["<tr>".to_string(), "<td>Credit Card on File</td>".to_string(), format!("<td>{}</td>", cc.join("<br>")), "</tr>".to_string()]);
    } else {
        if !o.po.is_empty() {
            r.push(format!("<tr><td>PO#</td><td>{}</td></tr>", esc_text(&o.po)));
        }
        if !o.comment.is_empty() {
            r.push(format!("<tr><td>Comment</td><td>{}</td></tr>", esc_text(&o.comment).replace('\n', "<br>")));
        }
        if !o.cc.is_empty() {
            r.push(format!("<tr><td>Credit Card on File</td><td>.... {}</td></tr>", esc_text(&o.cc)));
        }
    }
    r.push("<tr><td class='checkout_caption' colspan=2>Shipping Method</td></tr>".into());
    if entry {
        r.push(format!("<tr><td>Shipping Carrier</td><td>{}</td></tr>", html::select("carrier_name", &choices.carriers, &o.carrier_name, None, "", errors.has("carrier_name"))));
        r.push(format!("<tr><td>Collect Account#</td><td>{}</td></tr>", html::text_input("text", "carrier_account", &o.carrier_account, 40, 60, "Collect Account# (Optional)", false)));
    } else {
        if !o.carrier_name.is_empty() {
            r.push(format!("<tr><td>Shipping Carrier</td><td>{}</td></tr>", esc_text(&o.carrier_name)));
        }
        if !o.carrier_account.is_empty() {
            r.push(format!("<tr><td>Collect Account#</td><td>{}</td></tr>", esc_text(&o.carrier_account)));
        }
    }
    r.push("</table>".into());
    r.push("<td width='40%'>".into());
    if entry {
        r.push("<div style='border: 1px solid; padding: 1em; width: 90%'>".into());
        if !local.get("cc_checkout").is_empty() {
            r.push(format!("<p>{}</p>", local.get("cc_checkout")));
        }
        r.push("</div>".into());
    }
    r.extend(["</td>", "</tr>", "</table>", "<p>Freight charges will be shown on your invoice unless shipping collect.</p>"].map(String::from));
    r.join("\n")
}

const SCRIPT: &str = "\t    <script>\n\t    function fn_action(action) {\n\t        if ( (action=='complete') && (!confirm('Place Order?')) ) return;\n\t        document.scs_form.action.value=action;\n\t        document.scs_form.submit();\n\t    }\n\t    function fn_billing_same_as_shipping(checked) {\n\t        jQuery('#orderhd_billing_address').val(checked ? jQuery('#orderhd_address').val() : '');\n\t        jQuery('#orderhd_billing_city').val(checked ? jQuery('#orderhd_city').val() : '');\n\t        jQuery('#orderhd_billing_state_us').val(checked ? jQuery('#orderhd_state_us').val() : '');\n\t        jQuery('#orderhd_billing_state_ca').val(checked ? jQuery('#orderhd_state_ca').val() : '');\n\t        jQuery('#orderhd_billing_state_other').val(checked ? jQuery('#orderhd_state_other').val() : '');\n\t        jQuery('#orderhd_billing_zip').val(checked ? jQuery('#orderhd_zip').val() : '');\n\t        jQuery('#orderhd_billing_country_code').val(checked ? jQuery('#orderhd_country_code').val() : '');\n\t        fn_country_address('orderhd_billing');\n\t    }\n\t    </script>\n\t    ";

/// The merge values scsmail_rev1.php builds from the order (`%po%`, `%name%`, ...) on top of the `scs_*` tags.
fn merge(o: &Order, id: i64, base: Merge) -> Merge {
    let mut m = base;
    let ship = &o.ship;
    let bill = &o.billing;
    for (k, v) in [
        ("id", id.to_string()),
        ("email", o.email.clone()),
        ("name_first", o.name_first.clone()),
        ("name_last", o.name_last.clone()),
        ("name", o.name.clone()),
        ("company_name", o.company_name.clone()),
        ("address", ship.address.clone()),
        ("city", ship.city.clone()),
        ("state", ship.state.clone()),
        ("zip", ship.zip.clone()),
        ("country_code", ship.country_code.clone()),
        ("phone", o.phone.clone()),
        ("po", o.po.clone()),
        ("comment", o.comment.clone()),
        ("type", "PO".into()),
        ("status", "Pending".into()),
        ("carrier_name", o.carrier_name.clone()),
        ("carrier_account", o.carrier_account.clone()),
        ("currency_code", o.currency_code.clone()),
        ("cc", o.cc.clone()),
        ("billing_address", bill.address.clone()),
        ("billing_city", bill.city.clone()),
        ("billing_state", bill.state.clone()),
        ("billing_zip", bill.zip.clone()),
        ("billing_country_code", bill.country_code.clone()),
    ] {
        m.set(k, v);
    }
    m
}

struct Placed {
    html: String,
    messages: Vec<String>,
}

async fn complete(state: &Orders, shopper: &Shopper, ip: &str, order: &mut Order, lines: &[Line], local: &Registry) -> Result<Placed, ApiError> {
    let pool = &state.core.pool;
    let now = unix_now();
    order.order_date = now;
    order.id = place(pool, shopper.user.id.0, order, lines, now).await?;
    let choices = Choices::names_for(pool, &[&order.ship, &order.billing]).await?;
    let money = MoneyFormat { order: &shopper.currency, viewer: &shopper.currency.code };
    let labels = Labels::load(pool, &shopper.language).await?;
    let thumb = local.get("thumbnail").to_string();
    let mut html = header(order, false, &choices, &Errors::default(), local, false);
    html.push_str(&cart::table(lines, false, &money, &labels, &thumb));
    if !local.get("cc_email").is_empty() {
        html.push_str(&format!("<p>{}</p>", local.get("cc_email")));
    }
    let comment = format!("Order #{}", order.id);
    log_order(pool, ip, shopper, &comment, now).await;
    if let Some(key) = &shopper.cart {
        cart::clear(pool, key).await?;
    }
    let mut messages = vec!["Order Complete!".to_string()];
    if let Some(template) = mailform::load(pool, "checkout").await? {
        let host = state.core.public_origin.trim_start_matches("https://");
        let company = Registry::load(pool, "company").await?;
        let base = Merge::standard(now, ip, host, shopper.user.id.0, shopper.user.company_name.as_deref().unwrap_or(""), &company);
        let merge = merge(order, order.id, base);
        let letterhead = Letterhead::load(pool, &state.docroot, &template).await?;
        if let Some(staff) = template.compose(&letterhead, &merge, None, None, &html) {
            mail::enqueue(pool, &staff, now).await?;
            messages.push(format!("Email successfully sent to {}", staff.to.email.as_str()));
        }
        let mut customer_html = "<p>This confirms that your web order has been successfully sent to our team. A formal order acknowledgement will follow from sales@harcourt.co as soon as your order has been processed in our system.</p>".to_string();
        customer_html.push_str(&html);
        if !local.get("cc_email").is_empty() {
            customer_html.push_str("<p>No orders will ship without a credit card on file.</p>");
        }
        let to = Email::parse(&order.email).map(|e| Mailbox::new(e, &order.name));
        if let Some(customer) = to.and_then(|to| template.compose(&letterhead, &merge, Some("Order Confirmation"), Some(to), &customer_html)) {
            mail::enqueue(pool, &customer, now).await?;
            messages.push(format!("Email successfully sent to {}", customer.to.email.as_str()));
        }
    }
    Ok(Placed { html, messages })
}

/// The `user:po` row classes/log.php writes for a placed order (its canonical names are User and PO).
async fn log_order(pool: &SqlitePool, ip: &str, shopper: &Shopper, comment: &str, now: i64) {
    let result = sqlx::query!(
        "INSERT INTO log (ip, date_time, user_id, email, type, subtype, comment) VALUES (?1, ?2, ?3, ?4, 'User', 'PO', ?5)",
        ip,
        now,
        shopper.user.id.0,
        shopper.email,
        comment
    )
    .execute(pool)
    .await;
    if let Err(e) = result {
        tracing::warn!("order log write failed: {e}");
    }
}

pub async fn page(State(state): State<Arc<Orders>>, visitor: Visitor, ClientIp(ip): ClientIp, headers: HeaderMap, uri: Uri, body: Bytes) -> Result<Response, ApiError> {
    let pool = &state.core.pool;
    let framed = visitor.framed;
    let home = if framed { "/" } else { "/scs_index.php" };
    let Some(shopper) = shopper(pool, &visitor, &headers).await? else { return Ok(html::redirect(home)) };
    let lines = cart::lines(&state, &shopper).await?;
    if lines.is_empty() {
        return Ok(html::redirect(home));
    }
    let params = form_params(uri.query(), &body);
    let local = Registry::load(pool, "local").await?;
    let remembered = crate::accounts::cookies::get(&headers, crate::accounts::cookies::EMAIL).unwrap_or_default();
    let mut order = prefill(pool, &shopper, &remembered).await?;
    let mut errors = Errors::default();
    let completing = params.get("action").is_some_and(|a| a == "complete");
    let mut placed = None;
    if completing {
        errors = read_form(pool, &params, &mut order).await?;
        if errors.is_empty() {
            placed = Some(complete(&state, &shopper, &ip, &mut order, &lines, &local).await?);
        }
    }
    let chrome = html::Chrome::load(pool).await?;
    let head = html::Head { title: "Shopping Cart Checkout", description: None, js: &[], framed, dashboard: shopper.user.role >= Role::Executive, catalog_url: "/catalog.php" };
    let mut out = html::open(&chrome, &head);
    let messages = placed.as_ref().map_or_else(|| vec!["Shopping Cart Checkout".to_string()], |p| p.messages.clone());
    out.push_str(&html::message(&messages, errors.len()));
    out.push_str(SCRIPT);
    out.push_str(&html::form_open("/checkout.php"));
    out.push_str(&html::hidden("action", ""));
    match &placed {
        Some(p) => {
            out.push_str(&format!("<p>Thank you {} for your order! You will receive an emailed order confirmation shortly with firm shipping dates. You will be notified further once the product ships.</p>\n", esc(&order.name)));
            out.push_str(&p.html);
        }
        None => {
            let choices = Choices::load(pool).await?;
            let billing_same = completing && params.contains_key("billing_same_as_shipping");
            out.push_str(&header(&order, true, &choices, &errors, &local, billing_same));
            let money = MoneyFormat { order: &shopper.currency, viewer: &shopper.currency.code };
            let labels = Labels::load(pool, &shopper.language).await?;
            out.push_str(&cart::table(&lines, false, &money, &labels, local.get("thumbnail")));
            let buttons = [html::button("<< Edit Order", "window.location.href='/cart.php';", "cart_button"), html::button("Place Order >>", "fn_action('complete');", "cart_button")];
            out.push_str(&format!("<p class=center>{}</p>", buttons.join(" ")));
        }
    }
    out.push_str(html::FORM_CLOSE);
    out.push_str(html::close());
    Ok(html::page_response(out))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn fn_text_drops_script_tags_and_trims() {
        assert_eq!(fn_text("  a<script src=x>b  ", false), "ab");
        assert_eq!(fn_text("po 12", true), "PO 12");
        assert_eq!(fn_text("line1\nline2<SCRIPT>", false), "line1\nline2");
    }
}
