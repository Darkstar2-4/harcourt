//! user_impersonate.php: an Administrator sees the site as an account or as an anonymous visitor, with the
//! account's settings changed for the view. The view is stored against the Administrator's own session
//! (`impersonation`), never written to the account, and it can never show a role above the Administrator's.
//! Any visit to this page while impersonating stops it.

use std::sync::Arc;

use axum::{
    extract::{Form, State},
    http::{header, HeaderMap, HeaderValue, StatusCode},
    response::{IntoResponse, Response},
};
use sqlx::SqlitePool;

use crate::{
    accounts::{
        account::{self, Account, PriceType},
        campaign, clock, cookies,
        html::{self, escape, Blank, Opts},
        locale::{Currencies, Languages},
    },
    api::{self, Auth},
    db::unix_now,
    identity::{Role, Status, UserId},
    session,
    token::Token,
    orders::html::{self as page, Chrome, Head},
    visitor::{self, Via, Visitor},
};

const ROLES: [Role; 6] = [Role::Customer, Role::External, Role::Internal, Role::Executive, Role::Administrator, Role::SuperUser];
const STATUSES: [&str; 3] = ["Pending", "Active", "Denied"];
const PRICE_TYPES: [(PriceType, &str); 4] = [(PriceType::None, "No pricing"), (PriceType::Base, "Base price"), (PriceType::Discount, "Discount"), (PriceType::Plus, "Base plus")];

const STARTED: &str = "<script>window.open('/','_top');\n</script>";

/// What the form asked to see, once checked.
#[derive(Debug, Clone, PartialEq)]
pub struct View {
    pub user_id: Option<UserId>,
    pub role: Role,
    pub status: Status,
    pub catalog: bool,
    pub cad: bool,
    pub ecommerce: bool,
    pub price_type: PriceType,
    pub price_pct: f64,
    pub industry_id: i64,
    pub portal_id: i64,
    pub portal_legacy: bool,
    pub currency_code: String,
    pub language_code: String,
    pub campaign_code: Option<String>,
}

impl View {
    /// user_data_class defaults: what an anonymous visitor looks like.
    fn visitor() -> View {
        View {
            user_id: None,
            role: Role::Customer,
            status: Status::Pending,
            catalog: false,
            cad: false,
            ecommerce: false,
            price_type: PriceType::None,
            price_pct: 0.0,
            industry_id: 0,
            portal_id: 0,
            portal_legacy: false,
            currency_code: "USD".into(),
            language_code: "EN".into(),
            campaign_code: None,
        }
    }
}

fn field<'a>(posted: &'a [(String, String)], name: &str) -> &'a str {
    posted.iter().find(|(k, _)| k == name).map_or("", |(_, v)| v.as_str())
}

fn checked(posted: &[(String, String)], name: &str) -> bool {
    !matches!(field(posted, name), "" | "0")
}

/// includes/functions.php fn_number($text, 2): digits, point and sign kept, a `%` divides by 100.
fn number(text: &str) -> f64 {
    let kept: String = text.chars().filter(|c| c.is_ascii_digit() || *c == '.' || *c == '-').collect();
    let n: f64 = kept.parse().unwrap_or(0.0);
    let n = (n * 100.0).round() / 100.0;
    if text.contains('%') {
        n * 0.01
    } else {
        n
    }
}

/// The checks of user_impersonate.php. The role shown may not be above `actor`'s.
pub fn check(actor: &Account, target: Option<&Account>, posted: &[(String, String)]) -> Result<View, Vec<(&'static str, &'static str)>> {
    if checked(posted, "visitor") {
        return Ok(View::visitor());
    }
    let mut errors = Vec::new();
    let target = target.filter(|t| t.role() <= actor.role());
    if target.is_none() {
        errors.push(("ajax_user_id", "Invalid User ID"));
    }
    let role = Role::parse(field(posted, "ajax_user_type")).filter(|r| *r <= actor.role());
    if role.is_none() {
        errors.push(("ajax_user_type", "Invalid User Type"));
    }
    let status = Status::parse(field(posted, "ajax_user_status"));
    if status.is_none() {
        errors.push(("ajax_user_status", "Invalid Status"));
    }
    let price_type = PriceType::parse(field(posted, "ajax_user_price_type"));
    let mut price_pct = number(field(posted, "ajax_user_price_pct")) * 0.01;
    match price_type {
        PriceType::None => price_pct = 0.0,
        PriceType::Base if price_pct != 0.0 => errors.push(("ajax_user_price_pct", "Must be 0% for base price")),
        PriceType::Discount if price_pct <= 0.0 || price_pct >= 1.0 => errors.push(("ajax_user_price_pct", "Discount must be > 0% and < 100%")),
        PriceType::Plus if price_pct <= 0.0 || price_pct >= 5.0 => errors.push(("ajax_user_price_pct", "Price plus must be > 0% and < 500%")),
        _ => {}
    }
    let (Some(target), Some(role), Some(status), true) = (target, role, status, errors.is_empty()) else { return Err(errors) };
    Ok(View {
        user_id: Some(target.id()),
        role,
        status,
        catalog: checked(posted, "ajax_user_catalog"),
        cad: checked(posted, "ajax_user_cad"),
        ecommerce: checked(posted, "ajax_user_ecommerce"),
        price_type,
        price_pct,
        industry_id: field(posted, "ajax_user_industry_id").trim().parse().unwrap_or(0),
        portal_id: field(posted, "ajax_user_portal_id").trim().parse().unwrap_or(0),
        portal_legacy: checked(posted, "portal_legacy"),
        currency_code: field(posted, "ajax_user_currency_code").to_string(),
        language_code: field(posted, "ajax_user_language_code").to_string(),
        campaign_code: Some(field(posted, "campaign_code").to_string()).filter(|c| !c.is_empty()),
    })
}

/// fn_url_redirect($menu->page->home): a plain redirect, since the PHP read a `target` the menu page never had.
fn home(visitor: &Visitor) -> Response {
    let to = if visitor.framed { "/" } else { "/scs_index.php" };
    (StatusCode::FOUND, [(header::LOCATION, to), (header::CONTENT_TYPE, visitor::PHP_CONTENT_TYPE)]).into_response()
}

fn page_response(body: String, set: Vec<HeaderValue>) -> Response {
    let mut r = ([(header::CONTENT_TYPE, "text/html; charset=UTF-8"), (header::CACHE_CONTROL, "no-store")], body).into_response();
    for c in set {
        r.headers_mut().append(header::SET_COOKIE, c);
    }
    r
}

fn internal(e: sqlx::Error) -> Response {
    tracing::error!("user_impersonate: {e}");
    (StatusCode::INTERNAL_SERVER_ERROR, "Internal error").into_response()
}

/// Stop an impersonation on this browser's session. True when there was one.
async fn stop(pool: &SqlitePool, headers: &HeaderMap) -> sqlx::Result<bool> {
    let Some(token) = api::session_cookie(headers) else { return Ok(false) };
    let hash = token.hash();
    let hash = hash.as_slice();
    Ok(sqlx::query!("DELETE FROM impersonation WHERE session_hash = ?1", hash).execute(pool).await?.rows_affected() > 0)
}

/// The Administrator this request comes from, if any. An impersonation in progress is handled before this.
fn administrator(visitor: &Visitor) -> Option<&Account> {
    visitor.account().filter(|a| a.user.is_active() && a.role() >= Role::Administrator)
}

pub async fn page(State(core): State<Arc<Auth>>, visitor: Visitor, headers: HeaderMap) -> Response {
    respond(&core, visitor, &headers, None).await
}

pub async fn submit(State(core): State<Arc<Auth>>, visitor: Visitor, headers: HeaderMap, Form(posted): Form<Vec<(String, String)>>) -> Response {
    if headers.get(header::ORIGIN).is_some_and(|o| o.as_bytes() != core.public_origin.as_bytes()) {
        return StatusCode::FORBIDDEN.into_response();
    }
    respond(&core, visitor, &headers, Some(posted)).await
}

async fn respond(core: &Auth, visitor: Visitor, headers: &HeaderMap, posted: Option<Vec<(String, String)>>) -> Response {
    let pool = &core.pool;
    if visitor.impersonator.is_some() {
        return match stop(pool, headers).await {
            Ok(_) => home(&visitor),
            Err(e) => internal(e),
        };
    }
    let Some(actor) = administrator(&visitor) else { return home(&visitor) };
    let posted = posted.filter(|p| !field(p, "action").is_empty());
    let Some(posted) = posted else {
        return match form(pool, actor, visitor.framed, headers, &[]).await {
            Ok(body) => page_response(body, Vec::new()),
            Err(e) => internal(e),
        };
    };
    match start(core, &visitor, actor, headers, &posted).await {
        Ok(Ok(set)) => page_response(STARTED.into(), set),
        Ok(Err(errors)) => match form(pool, actor, visitor.framed, headers, &errors).await {
            Ok(body) => page_response(body, Vec::new()),
            Err(e) => internal(e),
        },
        Err(e) => internal(e),
    }
}

/// Record the view against the Administrator's session, making one when they are signed in by company address.
async fn start(core: &Auth, visitor: &Visitor, actor: &Account, headers: &HeaderMap, posted: &[(String, String)]) -> sqlx::Result<Result<Vec<HeaderValue>, Vec<(&'static str, &'static str)>>> {
    let pool = &core.pool;
    let target = match field(posted, "ajax_user_id").trim().parse::<i64>() {
        Ok(id) => account::load(pool, UserId(id)).await?,
        Err(_) => None,
    };
    let mut view = match check(actor, target.as_ref(), posted) {
        Ok(v) => v,
        Err(errors) => return Ok(Err(errors)),
    };
    view.currency_code = Currencies::load(pool).await?.get(&view.currency_code).map_or_else(|| "USD".into(), |c| c.as_str().to_string());
    view.language_code = Languages::load(pool).await?.get(&view.language_code).map_or_else(|| "EN".into(), |l| l.as_str().to_string());
    let today = clock::detroit(unix_now()).date;
    view.campaign_code = match &view.campaign_code {
        Some(code) => campaign::active(pool, code, today).await?.map(|c| c.code),
        None => None,
    };
    let mut set = Vec::new();
    let now = unix_now();
    let token = match (visitor.signed_in().map(|s| s.via), api::session_cookie(headers)) {
        (Some(Via::Session), Some(t)) => t,
        _ => {
            let email = visitor.signed_in().and_then(|s| s.email.as_ref()).map(|e| e.as_str().to_string());
            let t: Token = session::create(pool, actor.id(), email.as_deref(), now).await?;
            set.extend(cookies::session(&t, false));
            t
        }
    };
    if checked(posted, "reset_cookie") {
        set.extend(cookies::set(cookies::EMAIL, "", cookies::Life::Gone));
    }
    let hash = token.hash();
    let hash = hash.as_slice();
    let (user_id, role, status, price_type) = (view.user_id.map(|u| u.0), view.role.as_str(), status_str(view.status), view.price_type.as_str());
    let (catalog, cad, ecommerce, legacy) = (i64::from(view.catalog), i64::from(view.cad), i64::from(view.ecommerce), i64::from(view.portal_legacy));
    sqlx::query!(
        r#"INSERT INTO impersonation (session_hash, user_id, "type", status, catalog, cad, ecommerce, price_type, price_pct, industry_id,
               portal_id, portal_legacy, currency_code, language_code, campaign_code, created_at)
           VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, ?12, ?13, ?14, ?15, ?16)
           ON CONFLICT (session_hash) DO UPDATE SET user_id = excluded.user_id, "type" = excluded."type", status = excluded.status,
               catalog = excluded.catalog, cad = excluded.cad, ecommerce = excluded.ecommerce, price_type = excluded.price_type,
               price_pct = excluded.price_pct, industry_id = excluded.industry_id, portal_id = excluded.portal_id,
               portal_legacy = excluded.portal_legacy, currency_code = excluded.currency_code, language_code = excluded.language_code,
               campaign_code = excluded.campaign_code, created_at = excluded.created_at"#,
        hash,
        user_id,
        role,
        status,
        catalog,
        cad,
        ecommerce,
        price_type,
        view.price_pct,
        view.industry_id,
        view.portal_id,
        legacy,
        view.currency_code,
        view.language_code,
        view.campaign_code,
        now
    )
    .execute(pool)
    .await?;
    Ok(Ok(set))
}

fn status_str(s: Status) -> &'static str {
    match s {
        Status::Pending => "Pending",
        Status::Active => "Active",
        Status::Denied => "Denied",
    }
}

async fn options(pool: &SqlitePool, sql: OptionsOf) -> sqlx::Result<Vec<(String, String)>> {
    Ok(match sql {
        OptionsOf::Industry => sqlx::query!(r#"SELECT id AS "id!: i64", name FROM industry WHERE active = 1 ORDER BY name"#)
            .fetch_all(pool)
            .await?
            .into_iter()
            .map(|r| (r.id.to_string(), r.name))
            .collect(),
        OptionsOf::Portal => sqlx::query!(r#"SELECT id AS "id!: i64", name FROM portal ORDER BY name"#)
            .fetch_all(pool)
            .await?
            .into_iter()
            .map(|r| (r.id.to_string(), r.name))
            .collect(),
        OptionsOf::Campaign => sqlx::query!(r#"SELECT code AS "code!", name, from_date, thru_date FROM campaign WHERE active = 1 AND code IS NOT NULL ORDER BY sort_order, name"#)
            .fetch_all(pool)
            .await?
            .into_iter()
            .map(|r| {
                let mut label = vec![r.name.unwrap_or_default()];
                label.extend(r.from_date.filter(|d| !d.is_empty() && d != "0000-00-00").map(|d| format!("From: {d}")));
                label.extend(r.thru_date.filter(|d| !d.is_empty() && d != "0000-00-00").map(|d| format!("Thru: {d}")));
                (r.code, label.join(" "))
            })
            .collect(),
    })
}

enum OptionsOf {
    Industry,
    Portal,
    Campaign,
}

/// The form of user_impersonate.php, filled in with the Administrator's own settings as the PHP did.
async fn form(pool: &SqlitePool, me: &Account, framed: bool, headers: &HeaderMap, errors: &[(&str, &str)]) -> sqlx::Result<String> {
    let roles: Vec<(String, String)> = ROLES.iter().map(|r| (r.as_str().to_string(), r.as_str().to_string())).collect();
    let statuses: Vec<(String, String)> = STATUSES.iter().map(|s| (s.to_string(), s.to_string())).collect();
    let prices: Vec<(String, String)> = PRICE_TYPES.iter().map(|(p, l)| (p.as_str().to_string(), l.to_string())).collect();
    let currencies: Vec<(String, String)> = Currencies::load(pool).await?.all().iter().map(|c| (c.code.as_str().to_string(), c.name.clone())).collect();
    let languages: Vec<(String, String)> = Languages::load(pool).await?.all().iter().map(|l| (l.code.as_str().to_string(), l.name.clone())).collect();
    let pct = if me.price_pct == 0.0 { String::new() } else { format!("{:.2}", me.price_pct * 100.0) };
    let remembered = cookies::get(headers, cookies::EMAIL).unwrap_or_default();
    let error_of = |f: &str| errors.iter().find(|(k, _)| *k == f).map(|(_, m)| format!(" <b>{m}</b>")).unwrap_or_default();
    let cb = |name: &str, on: bool| html::checkbox(name, "1", on, Opts::new());
    let rows = [
        "<p class=large><b>Select the user you wish to log in as:</b></p>".to_string(),
        "<table class='standard noborder'>".into(),
        format!("<tr><td width='30%'>Visitor?</td><td width='70%'>{}</td></tr>", cb("visitor", false)),
        format!(
            "<tr><td width='30%'>Impersonate User</td><td width='70%'>{} <b><span id=ajax_user_label></span></b>{}</td></tr>",
            html::text("ajax_user_id", &me.id().0.to_string(), 12, None, Opts::new()),
            error_of("ajax_user_id")
        ),
        format!("<tr><td>User type</td><td>{}{}</td>", html::select("ajax_user_type", &roles, me.role().as_str(), Blank::None, Opts::new()), error_of("ajax_user_type")),
        format!("<tr><td>Status</td><td>{}{}</td></tr>", html::select("ajax_user_status", &statuses, status_str(me.status()), Blank::None, Opts::new()), error_of("ajax_user_status")),
        "</tr>".into(),
        format!("<tr><td>Catalog?</td><td>{}</td></tr>", cb("ajax_user_catalog", me.grants.catalog)),
        format!("<tr><td>CAD?</td><td>{}</td></tr>", cb("ajax_user_cad", me.grants.cad)),
        format!(
            "<tr><td>Pricing: {}</td><td>{}%{}</td></tr>",
            html::select("ajax_user_price_type", &prices, me.price_type.as_str(), Blank::None, Opts::new().event("onchange", "$('#price_pct').val('');")),
            html::text("ajax_user_price_pct", &pct, 6, Some(6), Opts::new()),
            error_of("ajax_user_price_pct")
        ),
        format!("<tr><td>ECommerce?</td><td>{}</td></tr>", cb("ajax_user_ecommerce", me.grants.ecommerce)),
        format!("<tr><td>Industry</td><td>{}</td></tr>", html::select("ajax_user_industry_id", &options(pool, OptionsOf::Industry).await?, &me.industry_id.to_string(), Blank::Empty, Opts::new())),
        format!("<tr><tr><td>Portal</td><td>{}</td></tr>", html::select("ajax_user_portal_id", &options(pool, OptionsOf::Portal).await?, &me.portal_id.to_string(), Blank::Empty, Opts::new())),
        format!("<tr><td>Legacy?</td><td>{}</td></tr>", cb("portal_legacy", false)),
        format!("<tr><td>Campaign</td><td>{}</td></tr>", html::select("campaign_code", &options(pool, OptionsOf::Campaign).await?, "", Blank::Empty, Opts::new())),
        format!("<tr><td>Currency</td><td>{}</td></tr>", html::select("ajax_user_currency_code", &currencies, &me.currency_code, Blank::None, Opts::new())),
        format!("<tr><td>Langauge</td><td>{}</td></tr>", html::select("ajax_user_language_code", &languages, &me.language_code, Blank::None, Opts::new())),
        format!("<tr><td>{} Reset cookie?</td><td>{}</td></tr>", cb("reset_cookie", false), escape(&remembered)),
        "</table>".into(),
        format!("<p>{}</p>", html::submit("Go!", Opts::new())),
    ];
    let message = if errors.is_empty() { String::new() } else { format!("<p class=message>Correct {} Error(s)</p>\n", errors.len()) };
    let chrome = Chrome::load(pool).await?;
    let head = Head { title: "User Impersonate", description: None, js: &[], framed, dashboard: true, catalog_url: "/catalog.php" };
    Ok(format!(
        "{}<p class='message left'>User Impersonate</p>{message}{}{}{}{}{}",
        page::open(&chrome, &head),
        html::form_open("/user_impersonate.php", Opts::new()),
        html::hidden("action", "update", ""),
        rows.concat(),
        html::FORM_CLOSE,
        page::close()
    ))
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::{accounts::account::Grants, identity::User};

    fn account(id: i64, role: Role) -> Account {
        Account {
            user: User { id: UserId(id), role, status: Status::Active, company_name: None },
            ip: None,
            address: None,
            city: None,
            state: None,
            zip: None,
            country_code: None,
            last_login: 0,
            login_document_id: 0,
            landing_document_id: 0,
            comment: None,
            portal_id: 0,
            customer_code: None,
            grants: Grants::default(),
            price_type: PriceType::None,
            price_pct: 0.0,
            language_code: "EN".into(),
            industry_id: 0,
            leadscore: 1,
            revenue: String::new(),
            employees: String::new(),
            domain: None,
            currency_code: "USD".into(),
            recaptcha_score: 1.0,
        }
    }

    fn form(pairs: &[(&str, &str)]) -> Vec<(String, String)> {
        pairs.iter().map(|(k, v)| (k.to_string(), v.to_string())).collect()
    }

    #[test]
    fn the_role_shown_cannot_exceed_the_administrators() {
        let admin = account(1, Role::Administrator);
        let customer = account(2, Role::Customer);
        let ok = check(&admin, Some(&customer), &form(&[("ajax_user_type", "Administrator"), ("ajax_user_status", "Active"), ("ajax_user_catalog", "1")])).unwrap();
        assert_eq!((ok.role, ok.status, ok.catalog, ok.user_id), (Role::Administrator, Status::Active, true, Some(UserId(2))));
        let err = check(&admin, Some(&customer), &form(&[("ajax_user_type", "Super User"), ("ajax_user_status", "Active")])).unwrap_err();
        assert_eq!(err, vec![("ajax_user_type", "Invalid User Type")]);
        let err = check(&admin, Some(&customer), &form(&[("ajax_user_type", "Root"), ("ajax_user_status", "Active")])).unwrap_err();
        assert_eq!(err, vec![("ajax_user_type", "Invalid User Type")]);
    }

    #[test]
    fn a_target_above_the_administrator_or_missing_is_refused() {
        let admin = account(1, Role::Administrator);
        let top = account(3, Role::SuperUser);
        let posted = form(&[("ajax_user_type", "Customer"), ("ajax_user_status", "Active")]);
        assert_eq!(check(&admin, Some(&top), &posted).unwrap_err(), vec![("ajax_user_id", "Invalid User ID")]);
        assert_eq!(check(&admin, None, &posted).unwrap_err(), vec![("ajax_user_id", "Invalid User ID")]);
        assert!(check(&account(4, Role::SuperUser), Some(&top), &posted).is_ok());
    }

    #[test]
    fn pricing_rules_match_the_php() {
        let admin = account(1, Role::Administrator);
        let c = account(2, Role::Customer);
        let with = |t: &str, p: &str| check(&admin, Some(&c), &form(&[("ajax_user_type", "Customer"), ("ajax_user_status", "Active"), ("ajax_user_price_type", t), ("ajax_user_price_pct", p)]));
        assert_eq!(with("", "50").unwrap().price_pct, 0.0);
        assert_eq!(with("base", "5").unwrap_err(), vec![("ajax_user_price_pct", "Must be 0% for base price")]);
        assert!((with("discount", "15").unwrap().price_pct - 0.15).abs() < 1e-9);
        assert_eq!(with("discount", "100").unwrap_err(), vec![("ajax_user_price_pct", "Discount must be > 0% and < 100%")]);
        assert_eq!(with("plus", "0").unwrap_err(), vec![("ajax_user_price_pct", "Price plus must be > 0% and < 500%")]);
    }

    #[test]
    fn the_visitor_box_shows_an_anonymous_visitor() {
        let admin = account(1, Role::Administrator);
        assert_eq!(check(&admin, None, &form(&[("visitor", "1"), ("ajax_user_type", "Super User")])).unwrap(), View::visitor());
    }
}
