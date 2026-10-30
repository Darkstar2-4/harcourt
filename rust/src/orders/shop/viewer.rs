//! orderhd.php, the PO Manager: Executives list orders, open one, and change its status.

use std::sync::Arc;

use axum::{
    body::Bytes,
    extract::State,
    http::{HeaderMap, Uri},
    response::Response,
};
use chrono::{NaiveDate, TimeZone};
use chrono_tz::America::Detroit;
use rust_decimal::Decimal;
use sqlx::SqlitePool;

use super::{
    cart::{self, Line},
    checkout::{self, Address, Choices, Errors, Order},
    Currency, Labels, MoneyFormat,
};
use crate::{
    api::ApiError,
    db::unix_now,
    orders::{
        form_params,
        html::{self, esc, esc_text, js_str},
        mailform::Registry,
        pricing::Price,
        Executive, Orders, Staff,
    },
};

/// `orderhd_constant_class::$status`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Status {
    Pending,
    Accept,
    Changes,
    Reject,
}

impl Status {
    pub const ALL: [Status; 4] = [Status::Pending, Status::Accept, Status::Changes, Status::Reject];

    pub fn as_str(self) -> &'static str {
        match self {
            Status::Pending => "Pending",
            Status::Accept => "Accept",
            Status::Changes => "Changes",
            Status::Reject => "Reject",
        }
    }

    pub fn parse(s: &str) -> Option<Status> {
        Status::ALL.into_iter().find(|v| v.as_str() == s)
    }
}

pub async fn load(pool: &SqlitePool, id: i64) -> sqlx::Result<Option<Order>> {
    let r = sqlx::query!(
        r#"SELECT id AS "id!: i64", coalesce(punchout_id, 0) AS "punchout_id!: i64", coalesce(order_date, 0) AS "order_date!: i64",
                  status AS "status!: String", coalesce(status_date, 0) AS "status_date!: i64", coalesce(email, '') AS "email!: String",
                  coalesce(name_first, '') AS "name_first!: String", coalesce(name_last, '') AS "name_last!: String",
                  coalesce(name, '') AS "name!: String", coalesce(company_name, '') AS "company_name!: String",
                  coalesce(phone, '') AS "phone!: String", coalesce(address, '') AS "address!: String", coalesce(city, '') AS "city!: String",
                  coalesce(state, '') AS "state!: String", coalesce(zip, '') AS "zip!: String", coalesce(country_code, '') AS "country_code!: String",
                  coalesce(billing_address, '') AS "billing_address!: String", coalesce(billing_city, '') AS "billing_city!: String",
                  coalesce(billing_state, '') AS "billing_state!: String", coalesce(billing_zip, '') AS "billing_zip!: String",
                  coalesce(billing_country_code, '') AS "billing_country_code!: String", coalesce(po, '') AS "po!: String",
                  coalesce(comment, '') AS "comment!: String", coalesce(cc, '') AS "cc!: String",
                  coalesce(carrier_name, '') AS "carrier_name!: String", coalesce(carrier_account, '') AS "carrier_account!: String",
                  coalesce(currency_code, 'USD') AS "currency_code!: String"
           FROM orderhd WHERE id = ?1"#,
        id
    )
    .fetch_optional(pool)
    .await?;
    Ok(r.map(|r| Order {
        id: r.id,
        punchout_id: r.punchout_id,
        order_date: r.order_date,
        status: r.status,
        status_date: r.status_date,
        email: r.email,
        name_first: r.name_first,
        name_last: r.name_last,
        name: r.name,
        company_name: r.company_name,
        phone: r.phone,
        ship: Address { address: r.address, city: r.city, state: r.state, zip: r.zip, country_code: r.country_code },
        billing: Address { address: r.billing_address, city: r.billing_city, state: r.billing_state, zip: r.billing_zip, country_code: r.billing_country_code },
        po: r.po,
        comment: r.comment,
        cc: r.cc,
        carrier_name: r.carrier_name,
        carrier_account: r.carrier_account,
        currency_code: r.currency_code,
    }))
}

/// The stored lines, priced as they were ordered.
pub async fn lines(state: &Orders, order_id: i64) -> sqlx::Result<Vec<Line>> {
    let rows = sqlx::query!(
        r#"SELECT sku, description, quantity AS "quantity!: i64", printf('%.5f', price) AS "price!: String",
                  printf('%.2f', extended) AS "extended!: String", coalesce(comment, '') AS "comment!: String"
           FROM orderln WHERE order_id = ?1 ORDER BY line_no"#,
        order_id
    )
    .fetch_all(&state.core.pool)
    .await?;
    let mut out = Vec::new();
    for r in rows {
        let unit = r.price.parse::<Decimal>().unwrap_or_default();
        let extended = r.extended.parse::<Decimal>().unwrap_or_default();
        let thumbnail = cart::thumbnail(state, &r.sku).await?;
        out.push(Line { sku: r.sku, description: r.description, quantity: u32::try_from(r.quantity).unwrap_or(0), comment: r.comment, price: Price::Quoted { unit, extended }, thumbnail });
    }
    Ok(out)
}

/// A typed report date (m/d/Y, Y-m-d or Y/m/d) as the first second of that day in the site's time zone.
fn report_date(typed: &str) -> Option<(String, i64)> {
    let typed = typed.trim();
    let date = ["%m/%d/%Y", "%Y-%m-%d", "%Y/%m/%d"].iter().find_map(|f| NaiveDate::parse_from_str(typed, f).ok())?;
    let start = Detroit.from_local_datetime(&date.and_hms_opt(0, 0, 0)?).earliest()?.timestamp();
    Some((date.format("%m/%d/%Y").to_string(), start))
}

/// Only a path on this site: `return_url` comes from the request.
fn local_path(url: &str) -> Option<&str> {
    (url.starts_with('/') && !url.starts_with("//") && !url.contains('\\') && !url.chars().any(char::is_control)).then_some(url)
}

const SCRIPT: &str = "<script>\nfunction fn_action(obj,action,id) {\n\tdocument.scs_form.action.value=action;\n\tdocument.scs_form.record_id.value=id;\n\tdocument.scs_form.submit();\n}\n</script>\n";

pub async fn page(State(state): State<Arc<Orders>>, staff: Staff<Executive>, headers: HeaderMap, uri: Uri, body: Bytes) -> Result<Response, ApiError> {
    let pool = &state.core.pool;
    let p = form_params(uri.query(), &body);
    let get = |k: &str| p.get(k).map_or("", String::as_str);
    let action = get("action");
    let record_id: i64 = get("record_id").trim().parse().unwrap_or(0);
    let return_url = local_path(get("return_url")).unwrap_or("");
    let report_status = Status::parse(get("report_status"));
    let report_date = report_date(get("report_order_date"));
    let mut order = if record_id != 0 { load(pool, record_id).await? } else { None };
    let mut messages = vec!["PO Manager".to_string()];
    let mut errors = Errors::default();
    match action {
        "cancel" if !return_url.is_empty() => return Ok(html::redirect(return_url)),
        "cancel" => messages.push("Request cancelled".into()),
        "update" => match (order.as_mut(), Status::parse(get("status"))) {
            (Some(o), Some(new)) if new.as_str() != o.status => {
                let now = unix_now();
                let status = new.as_str();
                sqlx::query!("UPDATE orderhd SET status = ?1, status_date = ?2, status_user_id = ?3 WHERE id = ?4", status, now, staff.user.id.0, o.id)
                    .execute(pool)
                    .await?;
                o.status = status.to_string();
                o.status_date = now;
                messages.push("Record maintained".into());
                if !return_url.is_empty() {
                    return Ok(html::redirect(return_url));
                }
            }
            _ => errors.add_field("status"),
        },
        _ => {}
    }
    let framed = html::framed(uri.query(), &headers);
    let chrome = html::Chrome::load(pool).await?;
    let head = html::Head { title: "PO Manager", description: None, js: &[], framed, dashboard: true, catalog_url: "/catalog.php" };
    let mut out = html::open(&chrome, &head);
    out.push_str(&html::message(&messages, errors.len()));
    out.push_str(SCRIPT);
    out.push_str(&html::form_open("/orderhd.php"));
    out.push_str(&html::hidden("action", ""));
    out.push_str(&html::hidden("record_id", ""));
    out.push_str(&html::hidden("page", ""));
    out.push_str(&html::hidden("return_url", return_url));
    let showing = action == "maintain" || (action == "update" && !errors.is_empty());
    let status_choices: Vec<(String, String)> = Status::ALL.iter().map(|s| (s.as_str().to_string(), s.as_str().to_string())).collect();
    match (&order, showing) {
        _ if action == "email" => {}
        (Some(o), true) => {
            out.push_str(&html::hidden("report_status", report_status.map_or("", Status::as_str)));
            out.push_str(&html::hidden("report_order_date", report_date.as_ref().map_or("", |d| d.0.as_str())));
            let local = Registry::load(pool, "local").await?;
            let names = Choices::names_for(pool, &[&o.ship, &o.billing]).await?;
            out.push_str(&checkout::header(o, false, true, &names, &Errors::default(), &local, false));
            let staff_currency = sqlx::query_scalar!(r#"SELECT currency_code AS "c!: String" FROM "user" WHERE id = ?1"#, staff.user.id.0).fetch_optional(pool).await?.unwrap_or_else(|| "USD".into());
            let order_currency = Currency::load(pool, &o.currency_code).await?;
            let money = MoneyFormat { order: &order_currency, viewer: &staff_currency };
            let labels = Labels::load(pool, "EN").await?;
            out.push_str(&cart::table(&lines(&state, o.id).await?, false, &money, &labels, local.get("thumbnail")));
            let buttons = [
                html::select("status", &status_choices, &o.status, None, "", errors.has("status")),
                html::button("Update", &format!("fn_action(this,'update',{},'');", js_str(&o.id.to_string())), "nav_button"),
                html::button("Cancel", "fn_action(this,'cancel','','');", "nav_button"),
            ];
            out.push_str(&format!("<p class=standard>Status: {}</p>\n", buttons.join(" ")));
        }
        _ => {
            out.push_str("<table class='standard noborder'><tr><td width='30%'>Status</td>");
            out.push_str(&format!("<td width='70%'>{}</td>", html::select("report_status", &status_choices, report_status.map_or("", Status::as_str), Some(""), "", false)));
            out.push_str("</tr><tr><td width='30%'>Order Date</td>");
            let shown = report_date.as_ref().map_or("", |d| d.0.as_str());
            let value = if shown.is_empty() { String::new() } else { format!(" value='{}'", esc(shown)) };
            out.push_str(&format!("<td width='70%'><input type=text class='datepicker' name='report_order_date' id='report_order_date' size='10' maxlength='10'{value}>\n</td>"));
            out.push_str("</tr></table>");
            out.push_str(&format!("<p>{}</p>", html::button("List", "fn_action(this,'list','');", "nav_button")));
            if action == "list" {
                out.push_str(&list(pool, report_status, report_date.map(|d| d.1)).await?);
            }
        }
    }
    out.push_str(html::FORM_CLOSE);
    out.push_str(html::close());
    Ok(html::page_response(out))
}

async fn list(pool: &SqlitePool, status: Option<Status>, since: Option<i64>) -> sqlx::Result<String> {
    let status_text = status.map(Status::as_str);
    let since = since.unwrap_or(i64::MIN);
    let rows = sqlx::query!(
        r#"SELECT id AS "id!: i64", coalesce(company_name, '') AS "company!: String", coalesce(name, '') AS "name!: String",
                  coalesce(email, '') AS "email!: String", coalesce(order_date, 0) AS "order_date!: i64", status AS "status!: String"
           FROM orderhd WHERE (?1 IS NULL OR status = ?1) AND coalesce(order_date, 0) >= ?2 ORDER BY id"#,
        status_text,
        since
    )
    .fetch_all(pool)
    .await?;
    let mut out = String::from("<table class='small border tablesorter'><thead><tr><th>PO#</th><th>Company</th><th>Name</th><th>Email</th><th>Order Date/Time</th>");
    if status.is_none() {
        out.push_str("<th>Status</th>");
    }
    out.push_str("</tr></thead><tbody>");
    for r in rows {
        let when = chrono::DateTime::from_timestamp(r.order_date, 0).unwrap_or_default().with_timezone(&Detroit).format("%m/%d/%Y %I:%M %p");
        out.push_str(&format!(
            "<tr><td class=center><a href=\"javascript:fn_action(this,'maintain',{});\" >{}</a></td><td>{}</td><td>{}</td><td>{}</td><td class=center>{when}</td>",
            html::esc_dq(&js_str(&r.id.to_string())),
            r.id,
            esc_text(&r.company),
            esc_text(&r.name),
            esc_text(&r.email)
        ));
        if status.is_none() {
            out.push_str(&format!("<td>{}</td>", esc_text(&r.status)));
        }
        out.push_str("</tr>");
    }
    out.push_str("</tbody></table>");
    Ok(out)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn report_dates_accept_the_usual_forms() {
        assert_eq!(report_date("10/29/2026").map(|d| d.0), Some("10/29/2026".into()));
        assert_eq!(report_date("2026-10-29").map(|d| d.1), report_date("10/29/2026").map(|d| d.1));
        assert!(report_date("junk").is_none());
    }

    #[test]
    fn return_urls_stay_on_this_site() {
        assert_eq!(local_path("/orderhd.php?x=1"), Some("/orderhd.php?x=1"));
        for bad in ["https://example.com/", "//example.com/", "/\\example.com", "mailto:a@example.com", ""] {
            assert!(local_path(bad).is_none(), "{bad}");
        }
    }

    #[test]
    fn statuses_parse_only_known_values() {
        assert_eq!(Status::parse("Accept"), Some(Status::Accept));
        assert_eq!(Status::parse("accept"), None);
    }
}
