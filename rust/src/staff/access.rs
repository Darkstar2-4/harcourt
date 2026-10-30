//! Access requests: an Administrator opens a pending account from the dashboard's Pending Access tab and approves or
//! denies it. This is what remains of user.php: the dashboard still links each request to
//! `/user.php?action=maintain&record_id=<id>`, which comes here; any other user.php request is gone (410). Account
//! edits in bulk are `hxweb export user` and `hxweb import user`.

use std::{sync::Arc, time::Duration};

use axum::{
    extract::{Path, RawQuery, State},
    http::{header, StatusCode},
    response::{IntoResponse, Response},
    routing::get,
    Router,
};
use serde_json::{Map, Value};
use sqlx::SqlitePool;

use super::{
    csrf::{Fields, Posted},
    html::{button, close, hidden, number_dp, open, text, Notes, Opts},
    shell, time, Staff,
};
use crate::{
    accounts::registry,
    api::{Administrator, Auth, ClientIp},
    audit::{self, Entry},
    db::unix_now,
    identity::{Role, Status},
};

const PATH: &str = "/staff/access/{id}";
const TITLE: &str = "Access Request";
const DASHBOARD: &str = "/dashboard.php";

/// The dashboard's link to a request, `/user.php?action=maintain&record_id=<id>&return_url=…`, sent to the request.
async fn user_php(RawQuery(q): RawQuery) -> Response {
    let f = Fields::from_query(q.as_deref());
    let id = f.int("record_id");
    if f.get("action") != "maintain" || id <= 0 {
        return gone();
    }
    let back = local(f.get("return_url")).map(|r| super::html::url_query(&[("return_url", r)])).unwrap_or_default();
    found(&format!("/staff/access/{id}{back}"))
}

fn gone() -> Response {
    (StatusCode::GONE, [(header::CONTENT_TYPE, "text/plain; charset=utf-8"), (header::CACHE_CONTROL, "no-store")], "Gone\n").into_response()
}

fn found(to: &str) -> Response {
    (StatusCode::FOUND, [(header::LOCATION, to.to_string()), (header::CACHE_CONTROL, "no-store".to_string())]).into_response()
}

/// A return address on this site: a path, never another host.
fn local(url: &str) -> Option<&str> {
    let safe = url.starts_with('/') && !url.starts_with("//") && !url.contains('\\') && !url.chars().any(char::is_control);
    safe.then_some(url)
}

struct Request {
    id: i64,
    ip: String,
    company_name: String,
    address: String,
    role: Role,
    status: Status,
    recaptcha_score: f64,
    last_login: i64,
    name: String,
    email: String,
}

async fn load(pool: &SqlitePool, id: i64) -> sqlx::Result<Option<Request>> {
    let Some(u) = sqlx::query!(
        r#"SELECT id AS "id!: i64", ip, company_name, address, city, state, zip, country_code, status, "type" AS kind, recaptcha_score, last_login
           FROM "user" WHERE id = ?1"#,
        id
    )
    .fetch_optional(pool)
    .await?
    else {
        return Ok(None);
    };
    let signup = sqlx::query!(
        r#"SELECT comment, email FROM log WHERE user_id = ?1 AND lower(type) = 'user' AND lower(subtype) = 'signup' ORDER BY id DESC LIMIT 1"#,
        id
    )
    .fetch_optional(pool)
    .await?;
    let place: Vec<String> = [u.address, u.city, u.state, u.zip, u.country_code].into_iter().flatten().filter(|s| !s.is_empty()).collect();
    Ok(Some(Request {
        id: u.id,
        ip: u.ip.unwrap_or_default(),
        company_name: u.company_name.unwrap_or_default(),
        address: place.join(", "),
        role: Role::parse(&u.kind).unwrap_or(Role::Customer),
        status: Status::parse(&u.status).unwrap_or(Status::Pending),
        recaptcha_score: u.recaptcha_score,
        last_login: u.last_login,
        name: signup.as_ref().and_then(|s| s.comment.clone()).unwrap_or_default(),
        email: signup.and_then(|s| s.email).unwrap_or_default(),
    }))
}

fn status_name(s: Status) -> &'static str {
    match s {
        Status::Pending => "Pending",
        Status::Active => "Active",
        Status::Denied => "Denied",
    }
}

async fn render(pool: &SqlitePool, staff: &Staff<Administrator>, r: &Request, back: &str, notes: Notes) -> Response {
    let row = |label: &str, value: String| format!("<tr><td width='30%'>{label}</td><td width='70%'>{value}</td></tr>");
    let mut body = vec![
        notes.render(),
        "<script>\nfunction fn_action(action) {\n\tjQuery('#action').val(action);\n\tjQuery('#scs_form').submit();\n}\n</script>".into(),
        open(&format!("/staff/access/{}", r.id), staff.csrf.as_str(), false),
        hidden("action", ""),
        hidden("return_url", back),
        "<table class='standard noborder'>".into(),
        row("Company", text(&r.company_name)),
        row("Name", text(&r.name).replace("&lt;br>", "<br>")),
        row("Email", text(&r.email)),
        row("IP Address", text(&r.ip)),
        row("Address", text(&r.address)),
        row("Score", number_dp(r.recaptcha_score, 1)),
        row("Requested", time::date("m/d/Y h:i A", r.last_login)),
        row("Status", status_name(r.status).into()),
        "</table>".into(),
    ];
    if r.status == Status::Pending {
        let approve = button("Approve", Opts::new().onclick("fn_action('approve');"));
        let deny = button("Deny", Opts::new().onclick("fn_action('deny');"));
        body.push(format!("<p>{approve} {deny}</p>"));
    }
    body.push(format!("<p><a href=\"{}\">Return</a></p>", super::html::esc(back)));
    body.push(close().into());
    shell::page(pool, staff.view(), TITLE, &body.join("\n")).await
}

fn back_to(f: &Fields) -> String {
    local(f.get("return_url")).unwrap_or(DASHBOARD).to_string()
}

/// The request, if the Administrator may decide it: an account at or below their own role.
async fn request(pool: &SqlitePool, staff: &Staff<Administrator>, id: i64) -> sqlx::Result<Option<Request>> {
    Ok(load(pool, id).await?.filter(|r| r.role <= staff.who.user.role))
}

async fn show(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, Path(id): Path<i64>, RawQuery(q): RawQuery) -> Response {
    let back = back_to(&Fields::from_query(q.as_deref()));
    match request(&auth.pool, &staff, id).await {
        Ok(Some(r)) => render(&auth.pool, &staff, &r, &back, Notes::new(TITLE, "")).await,
        Ok(None) => (StatusCode::NOT_FOUND, "No such account\n").into_response(),
        Err(e) => shell::internal(e),
    }
}

async fn decide(State(auth): State<Arc<Auth>>, ClientIp(ip): ClientIp, staff: Staff<Administrator>, Path(id): Path<i64>, posted: Posted) -> Response {
    let pool = &auth.pool;
    let f = &posted.fields;
    let status = match f.get("action") {
        "approve" => Status::Active,
        "deny" => Status::Denied,
        _ => return (StatusCode::BAD_REQUEST, "Approve or deny\n").into_response(),
    };
    let r = match request(pool, &staff, id).await {
        Ok(Some(r)) => r,
        Ok(None) => return (StatusCode::NOT_FOUND, "No such account\n").into_response(),
        Err(e) => return shell::internal(e),
    };
    if r.status != Status::Pending {
        let mut notes = Notes::new(TITLE, "");
        notes.say(&format!("Already {}", status_name(r.status)));
        return render(pool, &staff, &r, &back_to(f), notes).await;
    }
    match decide_request(pool, id, status).await {
        Ok(()) => {
            let subtype = if status == Status::Active { "access_approved" } else { "access_denied" };
            let comment = format!("user {id}");
            audit::record(pool, Entry { subtype, user_id: staff.who.user.id.0, email: staff.who.email.as_deref(), ip: &ip, comment: &comment }).await;
            found(&back_to(f))
        }
        Err(e) => shell::internal(e),
    }
}

/// Approving makes the account Active and lets the address it signed up with skip reCAPTCHA from now on; denying
/// makes it Denied. Only a pending account changes.
pub async fn decide_request(pool: &SqlitePool, id: i64, status: Status) -> sqlx::Result<()> {
    let name = status_name(status);
    let changed = sqlx::query!(r#"UPDATE "user" SET status = ?2 WHERE id = ?1 AND status = 'Pending'"#, id, name).execute(pool).await?.rows_affected();
    if changed == 1 && status == Status::Active {
        bypass_on_approval(pool, id).await?;
    }
    Ok(())
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

pub fn routes() -> Router<Arc<Auth>> {
    Router::new().route("/user.php", get(user_php)).route(PATH, get(show).post(decide))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn approvals_bypass_the_company_domain_or_the_one_free_address() {
        assert_eq!(bypass_entry(" Buyer@Example.COM "), "@example.com");
        assert_eq!(bypass_entry("someone@gmail.com"), "someone@gmail.com");
        assert_eq!(bypass_entry("no-at-sign"), "no-at-sign");
    }

    #[test]
    fn return_addresses_stay_on_the_site() {
        assert_eq!(local("/dashboard.php?tab=x"), Some("/dashboard.php?tab=x"));
        assert_eq!(local("//elsewhere.example/"), None);
        assert_eq!(local("https://elsewhere.example/"), None);
        assert_eq!(back_to(&Fields::default()), DASHBOARD);
    }
}
