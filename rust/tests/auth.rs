//! The /api routes against a private in-memory database with synthetic accounts. Nothing here reads or copies
//! production data; every row is inserted by the test that uses it.

use std::{net::SocketAddr, sync::Arc};

use axum::{
    body::Body,
    extract::connect_info::MockConnectInfo,
    http::{header, HeaderMap, Method, Request, StatusCode},
    Router,
};
use http_body_util::BodyExt;
use hxweb::{
    api::{self, Auth},
    config::Secret,
    db,
    identity::UserId,
    remote_grant, retired, session,
};
use sqlx::SqlitePool;
use tower::ServiceExt;

const ORIGIN: &str = "https://www.harcourt.co";

struct Harness {
    app: Router,
    pool: SqlitePool,
}

async fn harness() -> Harness {
    let pool = db::open_memory().await.unwrap();
    let auth = Arc::new(Auth {
        pool: pool.clone(),
        secret: Some(Secret::from_bytes(vec![7; 32])),
        public_origin: ORIGIN.into(),
        trusted_proxies: vec!["127.0.0.1".parse().unwrap()],
        accounts: hxweb::accounts::Services::offline(hxweb::accounts::bot::Dns::fixed(&[], &[])),
    });
    let app = Router::new().nest_service("/api", api::router(auth)).layer(MockConnectInfo(SocketAddr::from(([127, 0, 0, 1], 40000))));
    Harness { app, pool }
}

async fn seed(pool: &SqlitePool, id: i64, status: &str, role: &str, domain: Option<&str>) {
    sqlx::query(r#"INSERT INTO "user" (id, status, "type", domain, company_name) VALUES (?1, ?2, ?3, ?4, ?5)"#)
        .bind(id)
        .bind(status)
        .bind(role)
        .bind(domain)
        .bind(format!("Test company {id}"))
        .execute(pool)
        .await
        .unwrap();
}

async fn count(pool: &SqlitePool, table: &str) -> i64 {
    sqlx::query_scalar(&format!("SELECT count(*) FROM {table}")).fetch_one(pool).await.unwrap()
}

async fn cookie_for(pool: &SqlitePool, id: i64) -> String {
    let token = session::create(pool, UserId(id), None, db::unix_now()).await.unwrap();
    format!("{}={}", session::COOKIE, token.as_str())
}

fn post(path: &str, cookie: Option<&str>, body: &str) -> Request<Body> {
    let mut req = Request::builder().method(Method::POST).uri(path).header(header::CONTENT_TYPE, "application/json");
    if let Some(c) = cookie {
        req = req.header(header::COOKIE, c);
    }
    req.body(Body::from(body.to_string())).unwrap()
}

fn get(path: &str, cookie: Option<&str>) -> Request<Body> {
    let mut req = Request::builder().method(Method::GET).uri(path);
    if let Some(c) = cookie {
        req = req.header(header::COOKIE, c);
    }
    req.body(Body::empty()).unwrap()
}

async fn send(app: &Router, req: Request<Body>) -> (StatusCode, HeaderMap, String) {
    let res = app.clone().oneshot(req).await.unwrap();
    let (parts, body) = res.into_parts();
    let bytes = body.collect().await.unwrap().to_bytes();
    (parts.status, parts.headers, String::from_utf8_lossy(&bytes).into_owned())
}

fn json_field(body: &str, key: &str) -> String {
    let v: serde_json::Value = serde_json::from_str(body).unwrap();
    v[key].as_str().map(str::to_string).unwrap_or_else(|| v[key].to_string())
}

#[tokio::test]
async fn grant_without_a_session_is_rejected_and_mints_nothing() {
    let h = harness().await;
    seed(&h.pool, 2, "Active", "Customer", None).await;
    let unknown_but_well_formed = format!("{}={}", session::COOKIE, "A".repeat(43));
    for cookie in [None, Some("garbage"), Some("__Host-hx_session=not-a-token"), Some(unknown_but_well_formed.as_str())] {
        let (status, headers, _) = send(&h.app, post("/api/remote/grants", cookie, r#"{"user_id":2}"#)).await;
        assert_eq!(status, StatusCode::UNAUTHORIZED, "cookie {cookie:?}");
        assert!(headers.get(header::SET_COOKIE).is_none());
    }
    assert_eq!(count(&h.pool, "remote_grant").await, 0);
    assert_eq!(count(&h.pool, "session").await, 0);
}

#[tokio::test]
async fn a_customer_session_cannot_grant() {
    let h = harness().await;
    seed(&h.pool, 1, "Active", "Customer", None).await;
    seed(&h.pool, 2, "Active", "Customer", None).await;
    let cookie = cookie_for(&h.pool, 1).await;
    let (status, _, _) = send(&h.app, post("/api/remote/grants", Some(&cookie), r#"{"user_id":2}"#)).await;
    assert_eq!(status, StatusCode::FORBIDDEN);
    assert_eq!(count(&h.pool, "remote_grant").await, 0);
}

#[tokio::test]
async fn an_administrator_grant_redeems_once() {
    let h = harness().await;
    seed(&h.pool, 1, "Active", "Administrator", None).await;
    seed(&h.pool, 2, "Active", "Customer", None).await;
    let admin = cookie_for(&h.pool, 1).await;

    let (status, _, body) = send(&h.app, post("/api/remote/grants", Some(&admin), r#"{"user_id":2}"#)).await;
    assert_eq!(status, StatusCode::CREATED);
    let grant = json_field(&body, "token");

    let redeem_body = format!(r#"{{"token":"{grant}"}}"#);
    let (status, headers, _) = send(&h.app, post("/api/remote/redeem", None, &redeem_body)).await;
    assert_eq!(status, StatusCode::NO_CONTENT);
    let set_cookie = headers.get(header::SET_COOKIE).unwrap().to_str().unwrap().to_string();
    for attribute in ["__Host-hx_session=", "Secure", "HttpOnly", "SameSite=Lax", "Path=/"] {
        assert!(set_cookie.contains(attribute), "{attribute} missing from {set_cookie}");
    }
    let cookie = set_cookie.split(';').next().unwrap();
    let (status, _, body) = send(&h.app, get("/api/me", Some(cookie))).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(json_field(&body, "user_id"), "2");

    let (status, headers, _) = send(&h.app, post("/api/remote/redeem", None, &redeem_body)).await;
    assert_eq!(status, StatusCode::UNAUTHORIZED, "a grant works once");
    assert!(headers.get(header::SET_COOKIE).is_none());
}

#[tokio::test]
async fn an_administrator_cannot_grant_above_their_role_and_cannot_probe_ids() {
    let h = harness().await;
    seed(&h.pool, 1, "Active", "Administrator", None).await;
    seed(&h.pool, 3, "Active", "Super User", None).await;
    let admin = cookie_for(&h.pool, 1).await;
    let (above, _, above_body) = send(&h.app, post("/api/remote/grants", Some(&admin), r#"{"user_id":3}"#)).await;
    let (missing, _, missing_body) = send(&h.app, post("/api/remote/grants", Some(&admin), r#"{"user_id":999}"#)).await;
    assert_eq!((above, missing), (StatusCode::FORBIDDEN, StatusCode::FORBIDDEN));
    assert_eq!(above_body, missing_body);
    assert_eq!(count(&h.pool, "remote_grant").await, 0);
}

#[tokio::test]
async fn redeem_rejects_unknown_malformed_expired_and_orphaned_tokens() {
    let h = harness().await;
    seed(&h.pool, 1, "Active", "Administrator", None).await;
    seed(&h.pool, 2, "Active", "Customer", None).await;

    let expired = remote_grant::mint(&h.pool, UserId(2), UserId(1), db::unix_now() - 10_000).await.unwrap().0;
    let orphaned = remote_grant::mint(&h.pool, UserId(2), UserId(1), db::unix_now()).await.unwrap().0;
    sqlx::query(r#"UPDATE "user" SET status = 'Denied' WHERE id = 2"#).execute(&h.pool).await.unwrap();

    let unknown = "B".repeat(43);
    for token in ["", "nope", "' OR 1=1 --", unknown.as_str(), expired.as_str(), orphaned.as_str()] {
        let body = serde_json::json!({ "token": token }).to_string();
        let (status, headers, _) = send(&h.app, post("/api/remote/redeem", None, &body)).await;
        assert_eq!(status, StatusCode::UNAUTHORIZED, "token {token:?}");
        assert!(headers.get(header::SET_COOKIE).is_none());
    }
    let (status, _, _) = send(&h.app, post("/api/remote/redeem", None, "{}")).await;
    assert!(status.is_client_error());
    assert_eq!(count(&h.pool, "session").await, 0);
}

#[tokio::test]
async fn sessions_of_pending_and_denied_accounts_are_refused() {
    let h = harness().await;
    seed(&h.pool, 1, "Pending", "Administrator", None).await;
    seed(&h.pool, 2, "Denied", "Customer", None).await;
    for id in [1, 2] {
        let cookie = cookie_for(&h.pool, id).await;
        let (status, _, _) = send(&h.app, get("/api/me", Some(&cookie))).await;
        assert_eq!(status, StatusCode::UNAUTHORIZED, "user {id}");
    }
    let admin_cookie = cookie_for(&h.pool, 1).await;
    let (status, _, _) = send(&h.app, post("/api/remote/grants", Some(&admin_cookie), r#"{"user_id":2}"#)).await;
    assert_eq!(status, StatusCode::UNAUTHORIZED);
}

#[tokio::test]
async fn expired_sessions_are_refused() {
    let h = harness().await;
    seed(&h.pool, 1, "Active", "Customer", None).await;
    let long_ago = db::unix_now() - session::TTL_SECS - 5;
    let token = session::create(&h.pool, UserId(1), None, long_ago).await.unwrap();
    let cookie = format!("{}={}", session::COOKIE, token.as_str());
    let (status, _, _) = send(&h.app, get("/api/me", Some(&cookie))).await;
    assert_eq!(status, StatusCode::UNAUTHORIZED);
}

fn code_from_outbox(body: &str) -> String {
    body.split("code is ").nth(1).unwrap().chars().take(8).collect()
}

async fn last_mail(pool: &SqlitePool) -> (String, String) {
    sqlx::query_as("SELECT to_email, body FROM outbox ORDER BY id DESC LIMIT 1").fetch_one(pool).await.unwrap()
}

#[tokio::test]
async fn emailed_code_signs_in_once() {
    let h = harness().await;
    seed(&h.pool, 5, "Active", "Customer", Some("example.com")).await;
    let (status, _, _) = send(&h.app, post("/api/auth/code", None, r#"{"email":"Pat@Example.com"}"#)).await;
    assert_eq!(status, StatusCode::ACCEPTED);
    let (to, body) = last_mail(&h.pool).await;
    assert_eq!(to, "pat@example.com");
    let code = code_from_outbox(&body);

    let verify = format!(r#"{{"email":"pat@example.com","code":"{code}"}}"#);
    let (status, headers, _) = send(&h.app, post("/api/auth/verify", None, &verify)).await;
    assert_eq!(status, StatusCode::NO_CONTENT);
    let cookie = headers.get(header::SET_COOKIE).unwrap().to_str().unwrap().split(';').next().unwrap().to_string();
    let (_, _, me) = send(&h.app, get("/api/me", Some(&cookie))).await;
    assert_eq!(json_field(&me, "user_id"), "5");
    assert_eq!(json_field(&me, "email"), "pat@example.com");

    let (status, _, _) = send(&h.app, post("/api/auth/verify", None, &verify)).await;
    assert_eq!(status, StatusCode::UNAUTHORIZED, "a code works once");

    let (status, headers, _) = send(&h.app, post("/api/auth/logout", Some(&cookie), "{}")).await;
    assert_eq!(status, StatusCode::NO_CONTENT);
    assert!(headers.get(header::SET_COOKIE).unwrap().to_str().unwrap().contains("Max-Age=0"));
    let (status, _, _) = send(&h.app, get("/api/me", Some(&cookie))).await;
    assert_eq!(status, StatusCode::UNAUTHORIZED, "sign-out ends the session");
}

#[tokio::test]
async fn five_wrong_codes_lock_the_right_one_out() {
    let h = harness().await;
    seed(&h.pool, 5, "Active", "Customer", Some("example.com")).await;
    send(&h.app, post("/api/auth/code", None, r#"{"email":"pat@example.com"}"#)).await;
    let code = code_from_outbox(&last_mail(&h.pool).await.1);
    let wrong = if code.starts_with('2') { "3AAAAAAA" } else { "2AAAAAAA" };

    for _ in 0..5 {
        let body = format!(r#"{{"email":"pat@example.com","code":"{wrong}"}}"#);
        let (status, _, _) = send(&h.app, post("/api/auth/verify", None, &body)).await;
        assert_eq!(status, StatusCode::UNAUTHORIZED);
    }
    let right = format!(r#"{{"email":"pat@example.com","code":"{code}"}}"#);
    let (status, headers, _) = send(&h.app, post("/api/auth/verify", None, &right)).await;
    assert_eq!(status, StatusCode::UNAUTHORIZED);
    assert!(headers.get(header::SET_COOKIE).is_none());
    assert_eq!(count(&h.pool, "session").await, 0);
}

#[tokio::test]
async fn a_second_request_within_a_minute_sends_no_second_mail() {
    let h = harness().await;
    seed(&h.pool, 5, "Active", "Customer", Some("example.com")).await;
    for _ in 0..3 {
        let (status, _, _) = send(&h.app, post("/api/auth/code", None, r#"{"email":"pat@example.com"}"#)).await;
        assert_eq!(status, StatusCode::ACCEPTED);
    }
    assert_eq!(count(&h.pool, "outbox").await, 1);
}

#[tokio::test]
async fn code_requests_answer_the_same_for_known_and_unknown_addresses() {
    let h = harness().await;
    seed(&h.pool, 5, "Active", "Customer", Some("example.com")).await;
    seed(&h.pool, 6, "Pending", "Customer", Some("waiting.example")).await;
    let mut answers = Vec::new();
    for email in ["pat@example.com", "nobody@unknown.example", "pat@waiting.example", "not an email"] {
        let body = serde_json::json!({ "email": email }).to_string();
        let (status, _, text) = send(&h.app, post("/api/auth/code", None, &body)).await;
        answers.push((status, text));
    }
    assert!(answers.iter().all(|a| *a == answers[0]), "{answers:?}");
    assert_eq!(answers[0].0, StatusCode::ACCEPTED);
    assert_eq!(count(&h.pool, "outbox").await, 1, "only the Active company's address got mail");
}

#[tokio::test]
async fn cross_origin_posts_are_refused() {
    let h = harness().await;
    for (origin, expected) in [("https://evil.example", StatusCode::FORBIDDEN), ("null", StatusCode::FORBIDDEN), (ORIGIN, StatusCode::NO_CONTENT)] {
        let req = Request::builder()
            .method(Method::POST)
            .uri("/api/auth/logout")
            .header(header::ORIGIN, origin)
            .header(header::CONTENT_TYPE, "application/json")
            .body(Body::from("{}"))
            .unwrap();
        let (status, _, _) = send(&h.app, req).await;
        assert_eq!(status, expected, "origin {origin}");
    }
}

#[tokio::test]
async fn api_answers_503_when_it_is_not_configured() {
    let app = Router::new().nest_service("/api", api::unavailable());
    let (status, _, _) = send(&app, post("/api/remote/redeem", None, r#"{"token":"x"}"#)).await;
    assert_eq!(status, StatusCode::SERVICE_UNAVAILABLE);
}

/// The old scs_remote.php entry points, as a request line, answer 410 with no cookie. The same guard main.rs calls
/// runs before anything is proxied, so no request shaped like them reaches the PHP file or sets a session.
#[test]
fn the_retired_scs_remote_paths_are_gone_for_every_method_shape() {
    for target in [
        "/scs_remote.php",
        "/scs_remote.php/",
        "//scs_remote.php",
        "/./scs_remote.php",
        "/%73cs_remote.php",
        "/scs_remote.php/anything/after",
    ] {
        let response = retired::guard(target).unwrap_or_else(|| panic!("{target} was not retired"));
        assert_eq!(response.status(), StatusCode::GONE, "{target}");
        assert!(response.headers().get(header::SET_COOKIE).is_none(), "{target}");
    }
    assert!(retired::guard("/catalog/").is_none());
}
