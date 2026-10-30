//! Who is asking, through a router with the visitor layer, against a private in-memory database of synthetic rows.
//! Addresses are from the documentation ranges; nothing here comes from production.

use std::{net::SocketAddr, sync::Arc};

use axum::{
    body::Body,
    extract::connect_info::MockConnectInfo,
    http::{header, HeaderMap, Request, StatusCode},
    middleware,
    routing::get,
    Json, Router,
};
use http_body_util::BodyExt;
use hxweb::{
    accounts::{bot::Dns, Services},
    api::Auth,
    config::Secret,
    db,
    identity::UserId,
    session,
    visitor::{self, Identity, Via, Visitor},
};
use sqlx::SqlitePool;
use tower::ServiceExt;

const COMPANY_IP: &str = "203.0.113.7";
const CRAWLER_IP: &str = "192.0.2.10";

async fn pool() -> SqlitePool {
    let pool = db::open_memory().await.unwrap();
    sqlx::query("INSERT INTO language (code, country_code, name, priority, active) VALUES ('EN', 'US', 'English', 1, 1), ('FR', 'CA', 'Français', 2, 1)")
        .execute(&pool)
        .await
        .unwrap();
    sqlx::query("INSERT INTO currency (code, name, plural, exchange_rate, active) VALUES ('USD', 'US Dollar', 'US Dollars', 1, 1), ('CAD', 'Canadian Dollar', 'Canadian Dollars', 1.37, 1)")
        .execute(&pool)
        .await
        .unwrap();
    pool
}

fn app(pool: &SqlitePool, peer: &str) -> Router {
    let dns = Dns::fixed(
        &[(CRAWLER_IP.parse().unwrap(), "crawl-10.search.example"), ("192.0.2.11".parse().unwrap(), "crawl-11.search.example")],
        &[("crawl-10.search.example", CRAWLER_IP.parse().unwrap()), ("crawl-11.search.example", "198.51.100.200".parse().unwrap())],
    );
    let core = Arc::new(Auth {
        pool: pool.clone(),
        secret: Some(Secret::from_bytes(vec![7; 32])),
        public_origin: "https://www.harcourt.co".into(),
        trusted_proxies: vec!["127.0.0.1".parse().unwrap()],
        accounts: Services::offline(dns),
    });
    let peer: SocketAddr = format!("{peer}:40000").parse().unwrap();
    Router::new()
        .route("/probe", get(probe))
        .layer(middleware::from_fn_with_state(core.clone(), visitor::layer))
        .with_state(core)
        .layer(MockConnectInfo(peer))
}

async fn probe(v: Visitor) -> Json<serde_json::Value> {
    let (kind, id, via, status) = match &v.identity {
        Identity::Anonymous => ("anonymous", 0, "", ""),
        Identity::Bot(b) => ("bot", b.id, "", ""),
        Identity::Account(s) => (
            "account",
            s.account.id().0,
            match s.via {
                Via::Session => "session",
                Via::CompanyAddress => "address",
            },
            match s.account.status() {
                hxweb::identity::Status::Active => "Active",
                hxweb::identity::Status::Pending => "Pending",
                hxweb::identity::Status::Denied => "Denied",
            },
        ),
    };
    Json(serde_json::json!({
        "kind": kind, "id": id, "via": via, "status": status,
        "role": v.role().as_str(), "catalog": v.catalog(),
        "email": v.signed_in().and_then(|s| s.email.as_ref()).map(|e| e.as_str().to_string()),
        "language": v.language.as_str(), "currency": v.currency.as_str(),
        "campaign": v.campaign.as_ref().map(|c| c.code.clone()),
        "impersonator": v.impersonator.as_ref().map(|i| i.id.0),
    }))
}

async fn seed(pool: &SqlitePool, id: i64, status: &str, role: &str, ip: Option<&str>) {
    sqlx::query(r#"INSERT INTO "user" (id, status, "type", ip, company_name, catalog, language_code, currency_code) VALUES (?1, ?2, ?3, ?4, ?5, 1, 'EN', 'CAD')"#)
        .bind(id)
        .bind(status)
        .bind(role)
        .bind(ip)
        .bind(format!("Test company {id}"))
        .execute(pool)
        .await
        .unwrap();
}

async fn ask(app: &Router, headers: &[(&str, &str)]) -> (StatusCode, HeaderMap, serde_json::Value) {
    ask_at(app, "/probe", headers).await
}

async fn ask_at(app: &Router, uri: &str, headers: &[(&str, &str)]) -> (StatusCode, HeaderMap, serde_json::Value) {
    let mut req = Request::builder().uri(uri);
    for (k, v) in headers {
        req = req.header(*k, *v);
    }
    let res = app.clone().oneshot(req.body(Body::empty()).unwrap()).await.unwrap();
    let (parts, body) = res.into_parts();
    let bytes = body.collect().await.unwrap().to_bytes();
    (parts.status, parts.headers, serde_json::from_slice(&bytes).unwrap_or(serde_json::Value::Null))
}

async fn log_count(pool: &SqlitePool, ty: &str, subtype: &str) -> i64 {
    sqlx::query_scalar("SELECT count(*) FROM log WHERE type = ?1 AND subtype = ?2").bind(ty).bind(subtype).fetch_one(pool).await.unwrap()
}

async fn cookie_for(pool: &SqlitePool, id: i64, email: Option<&str>) -> String {
    let token = session::create(pool, UserId(id), email, db::unix_now()).await.unwrap();
    format!("{}={}", session::COOKIE, token.as_str())
}

#[tokio::test]
async fn an_anonymous_visitor_gets_the_browser_language_and_dollars() {
    let pool = pool().await;
    let (status, _, v) = ask(&app(&pool, "198.51.100.1"), &[("accept-language", "fr-CA,fr;q=0.9")]).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(v["kind"], "anonymous");
    assert_eq!(v["role"], "Customer", "the PHP counted an anonymous visitor as a Customer");
    assert_eq!(v["catalog"], false);
    assert_eq!((v["language"].as_str(), v["currency"].as_str()), (Some("FR"), Some("USD")));
}

#[tokio::test]
async fn a_company_address_signs_in_and_logs_once_per_visit() {
    let pool = pool().await;
    seed(&pool, 3, "Active", "Customer", Some(COMPANY_IP)).await;
    let app = app(&pool, COMPANY_IP);
    for _ in 0..3 {
        let (_, _, v) = ask(&app, &[]).await;
        assert_eq!((v["kind"].as_str(), v["id"].as_i64(), v["via"].as_str()), (Some("account"), Some(3), Some("address")));
        assert_eq!(v["catalog"], true);
        assert_eq!(v["currency"], "CAD");
    }
    assert_eq!(log_count(&pool, "User", "Login").await, 1);
}

#[tokio::test]
async fn only_our_proxy_may_name_the_client_address() {
    let pool = pool().await;
    seed(&pool, 3, "Active", "Customer", Some(COMPANY_IP)).await;
    let (_, _, via_proxy) = ask(&app(&pool, "127.0.0.1"), &[("x-real-ip", COMPANY_IP)]).await;
    assert_eq!(via_proxy["id"], 3);
    for header in ["x-real-ip", "x-forwarded-for", "forwarded", "client-ip"] {
        let value = if header == "forwarded" { format!("for={COMPANY_IP}") } else { COMPANY_IP.to_string() };
        let (_, _, v) = ask(&app(&pool, "198.51.100.1"), &[(header, value.as_str())]).await;
        assert_eq!(v["kind"], "anonymous", "{header} from a client that is not our proxy");
    }
}

#[tokio::test]
async fn proxy_and_loopback_addresses_never_sign_anyone_in() {
    let pool = pool().await;
    seed(&pool, 4, "Active", "Administrator", Some("127.0.0.1")).await;
    let (_, _, v) = ask(&app(&pool, "127.0.0.1"), &[]).await;
    assert_eq!(v["kind"], "anonymous");
}

#[tokio::test]
async fn a_session_wins_and_a_pending_account_sees_nothing() {
    let pool = pool().await;
    seed(&pool, 3, "Active", "Customer", Some(COMPANY_IP)).await;
    seed(&pool, 5, "Pending", "Customer", None).await;
    let active = cookie_for(&pool, 3, Some("pat@example.com")).await;
    let (_, _, v) = ask(&app(&pool, "198.51.100.1"), &[("cookie", active.as_str())]).await;
    assert_eq!((v["via"].as_str(), v["email"].as_str()), (Some("session"), Some("pat@example.com")));
    let pending = cookie_for(&pool, 5, None).await;
    let (_, _, v) = ask(&app(&pool, COMPANY_IP), &[("cookie", pending.as_str())]).await;
    assert_eq!((v["id"].as_i64(), v["status"].as_str()), (Some(5), Some("Pending")), "the session, not the address");
    assert_eq!(v["catalog"], false, "granted catalog, but not Active");
}

#[tokio::test]
async fn a_bot_needs_a_forward_confirmed_name() {
    let pool = pool().await;
    sqlx::query(r#"INSERT INTO bot (id, code, pattern, name, portal_id, catalog, status) VALUES (1, 'crawl', '/\.search\.example$/i', 'Search crawler', 0, 1, 'Active')"#)
        .execute(&pool)
        .await
        .unwrap();
    let (_, _, v) = ask(&app(&pool, CRAWLER_IP), &[]).await;
    assert_eq!((v["kind"].as_str(), v["id"].as_i64(), v["catalog"].as_bool()), (Some("bot"), Some(1), Some(true)));
    assert_eq!(log_count(&pool, "BOT", "Active").await, 1);
    let (_, _, v) = ask(&app(&pool, "192.0.2.11"), &[]).await;
    assert_eq!(v["kind"], "anonymous", "the PTR name resolves somewhere else");
}

#[tokio::test]
async fn a_denied_bot_is_sent_home() {
    let pool = pool().await;
    sqlx::query(r#"INSERT INTO bot (id, code, pattern, name, status) VALUES (2, 'nope', '/search\.example$/', 'Unwanted', 'Denied')"#)
        .execute(&pool)
        .await
        .unwrap();
    let (status, headers, _) = ask(&app(&pool, CRAWLER_IP), &[]).await;
    assert_eq!(status, StatusCode::FOUND);
    assert_eq!(headers.get(header::LOCATION).unwrap(), "/index.php");
}

#[tokio::test]
async fn a_campaign_link_is_kept_for_the_browser_session() {
    let pool = pool().await;
    sqlx::query("INSERT INTO campaign (id, code, name, type, active, from_date, thru_date) VALUES (1, 'SPRING', 'Spring', 'Public', 1, '2020-01-01', '2099-12-31'), (2, 'OLD', 'Old', 'Public', 0, NULL, NULL)")
        .execute(&pool)
        .await
        .unwrap();
    let app = app(&pool, "198.51.100.1");
    let (_, headers, v) = ask_at(&app, "/probe?Campaign=spring", &[]).await;
    assert_eq!(v["campaign"], "SPRING");
    let set = headers.get(header::SET_COOKIE).unwrap().to_str().unwrap().to_string();
    assert!(set.starts_with("hx_campaign=SPRING; Path=/;") && !set.contains("Max-Age"), "{set}");
    assert_eq!(log_count(&pool, "Initial", "Campaign").await, 1);
    let (_, _, v) = ask(&app, &[("cookie", "hx_campaign=SPRING")]).await;
    assert_eq!(v["campaign"], "SPRING");
    let (_, headers, v) = ask_at(&app, "/probe?campaign=OLD", &[("cookie", "hx_campaign=SPRING")]).await;
    assert!(v["campaign"].is_null());
    assert!(headers.get(header::SET_COOKIE).unwrap().to_str().unwrap().starts_with("hx_campaign=; Path=/; Max-Age=0"));
}

#[tokio::test]
async fn language_choice_beats_the_account_which_beats_the_browser() {
    let pool = pool().await;
    seed(&pool, 3, "Active", "Customer", None).await;
    sqlx::query(r#"UPDATE "user" SET language_code = 'FR' WHERE id = 3"#).execute(&pool).await.unwrap();
    let signed_in = cookie_for(&pool, 3, None).await;
    let app = app(&pool, "198.51.100.1");
    let (_, _, v) = ask(&app, &[("cookie", signed_in.as_str()), ("accept-language", "en-US")]).await;
    assert_eq!(v["language"], "FR");
    let both = format!("{signed_in}; hx_lang=en");
    let (_, _, v) = ask(&app, &[("cookie", both.as_str())]).await;
    assert_eq!(v["language"], "EN");
    let (_, _, v) = ask(&app, &[("cookie", "hx_lang=de"), ("accept-language", "fr")]).await;
    assert_eq!(v["language"], "FR", "a code the site does not offer is ignored");
}

#[tokio::test]
async fn an_external_referer_is_logged() {
    let pool = pool().await;
    let app = app(&pool, "198.51.100.1");
    ask(&app, &[("referer", "https://www.search.example/results?q=hose")]).await;
    ask(&app, &[("referer", "https://www.harcourt.co/catalog/")]).await;
    let host: String = sqlx::query_scalar("SELECT comment FROM log WHERE type = 'Initial' AND subtype = 'Referer'").fetch_one(&pool).await.unwrap();
    assert_eq!(host, "search.example");
    assert_eq!(log_count(&pool, "Initial", "Referer").await, 1);
}

async fn impersonate(pool: &SqlitePool, cookie: &str, user_id: Option<i64>, role: &str, status: &str) {
    let token = cookie.split_once('=').unwrap().1;
    let hash = hxweb::token::Token::parse(token).unwrap().hash();
    sqlx::query(r#"INSERT INTO impersonation (session_hash, user_id, "type", status, catalog, language_code, currency_code, created_at) VALUES (?1, ?2, ?3, ?4, 1, 'FR', 'USD', 0)"#)
        .bind(hash.as_slice())
        .bind(user_id)
        .bind(role)
        .bind(status)
        .execute(pool)
        .await
        .unwrap();
}

#[tokio::test]
async fn an_impersonation_shows_the_chosen_view_but_never_above_the_administrator() {
    let pool = pool().await;
    seed(&pool, 1, "Active", "Administrator", None).await;
    seed(&pool, 2, "Pending", "Customer", None).await;
    let admin = cookie_for(&pool, 1, Some("admin@example.com")).await;
    impersonate(&pool, &admin, Some(2), "Super User", "Active").await;
    let (_, _, v) = ask(&app(&pool, "198.51.100.1"), &[("cookie", admin.as_str())]).await;
    assert_eq!((v["id"].as_i64(), v["status"].as_str(), v["catalog"].as_bool()), (Some(2), Some("Active"), Some(true)));
    assert_eq!(v["role"], "Administrator", "capped at the impersonator's role");
    assert_eq!((v["impersonator"].as_i64(), v["language"].as_str()), (Some(1), Some("FR")));

    sqlx::query(r#"UPDATE "user" SET "type" = 'Executive' WHERE id = 1"#).execute(&pool).await.unwrap();
    let (_, _, v) = ask(&app(&pool, "198.51.100.1"), &[("cookie", admin.as_str())]).await;
    assert_eq!((v["id"].as_i64(), v["impersonator"].is_null()), (Some(1), true), "a demoted Administrator impersonates no one");
}

#[tokio::test]
async fn an_impersonated_visitor_is_anonymous() {
    let pool = pool().await;
    seed(&pool, 1, "Active", "Administrator", Some(COMPANY_IP)).await;
    let admin = cookie_for(&pool, 1, None).await;
    impersonate(&pool, &admin, None, "Customer", "Pending").await;
    let (_, _, v) = ask(&app(&pool, COMPANY_IP), &[("cookie", admin.as_str())]).await;
    assert_eq!((v["kind"].as_str(), v["impersonator"].as_i64()), (Some("anonymous"), Some(1)));
}

#[tokio::test]
async fn a_remembered_session_slides_forward_when_used() {
    let pool = pool().await;
    seed(&pool, 3, "Active", "Customer", None).await;
    let now = db::unix_now();
    let renew = 30 * 86400;
    let token = session::create_remembered(&pool, UserId(3), Some("pat@example.com"), now - 10 * 86400, renew).await.unwrap();
    assert!(session::lookup(&pool, &token, now).await.unwrap().is_some());
    let expires: i64 = sqlx::query_scalar("SELECT expires_at FROM session").fetch_one(&pool).await.unwrap();
    assert_eq!(expires, now + renew);
    let stale = session::create_remembered(&pool, UserId(3), None, now - renew - 5, renew).await.unwrap();
    assert!(session::lookup(&pool, &stale, now).await.unwrap().is_none(), "unused for longer than remote_days");
}
