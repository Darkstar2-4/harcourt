//! The session extractor every signed-in page uses, and grant redemption, against a private in-memory database
//! with synthetic accounts. Nothing here reads or copies production data; every row is inserted by the test that
//! uses it.

use std::{net::SocketAddr, sync::Arc};

use axum::{
    body::Body,
    extract::connect_info::MockConnectInfo,
    http::{header, HeaderMap, Method, Request, StatusCode},
    routing::get,
    Router,
};
use http_body_util::BodyExt;
use hxweb::{
    api::{self, Administrator, Auth, Authed, MinRole},
    config::Secret,
    db,
    identity::{Role, UserId},
    remote_grant, retired, session,
};
use sqlx::SqlitePool;
use tower::ServiceExt;

const ORIGIN: &str = "https://www.harcourt.co";

struct AnyRole;
impl MinRole for AnyRole {
    const ROLE: Role = Role::Customer;
}

struct Harness {
    app: Router,
    pool: SqlitePool,
}

/// /api, and two pages that take `Authed<R>` as the signed-in pages do: `/me` for any role, `/admin` for
/// Administrators. Each answers the account id.
async fn harness() -> Harness {
    let pool = db::open_memory().await.unwrap();
    let auth = Arc::new(Auth {
        pool: pool.clone(),
        secret: Some(Secret::from_bytes(vec![7; 32])),
        public_origin: ORIGIN.into(),
        trusted_proxies: vec!["127.0.0.1".parse().unwrap()],
        accounts: hxweb::accounts::Services::offline(hxweb::accounts::bot::Dns::fixed(&[], &[])),
    });
    let pages = Router::new()
        .route("/me", get(|who: Authed<AnyRole>| async move { who.user.id.0.to_string() }))
        .route("/admin", get(|who: Authed<Administrator>| async move { who.user.id.0.to_string() }))
        .with_state(auth.clone());
    let app = Router::new().nest_service("/api", api::router(auth)).merge(pages).layer(MockConnectInfo(SocketAddr::from(([127, 0, 0, 1], 40000))));
    Harness { app, pool }
}

async fn seed(pool: &SqlitePool, id: i64, status: &str, role: &str) {
    sqlx::query(r#"INSERT INTO "user" (id, status, "type", company_name) VALUES (?1, ?2, ?3, ?4)"#)
        .bind(id)
        .bind(status)
        .bind(role)
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

fn get_page(path: &str, cookie: Option<&str>) -> Request<Body> {
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

#[tokio::test]
async fn a_request_without_a_live_session_is_refused() {
    let h = harness().await;
    seed(&h.pool, 2, "Active", "Customer").await;
    let unknown_but_well_formed = format!("{}={}", session::COOKIE, "A".repeat(43));
    for cookie in [None, Some("garbage"), Some("__Host-hx_session=not-a-token"), Some(unknown_but_well_formed.as_str())] {
        for path in ["/me", "/admin"] {
            let (status, headers, _) = send(&h.app, get_page(path, cookie)).await;
            assert_eq!(status, StatusCode::UNAUTHORIZED, "{path} with cookie {cookie:?}");
            assert!(headers.get(header::SET_COOKIE).is_none());
        }
    }
    assert_eq!(count(&h.pool, "session").await, 0);
}

#[tokio::test]
async fn a_session_below_the_pages_role_is_forbidden() {
    let h = harness().await;
    seed(&h.pool, 1, "Active", "Customer").await;
    seed(&h.pool, 2, "Active", "Executive").await;
    seed(&h.pool, 3, "Active", "Administrator").await;
    for (id, expected) in [(1, StatusCode::FORBIDDEN), (2, StatusCode::FORBIDDEN), (3, StatusCode::OK)] {
        let cookie = cookie_for(&h.pool, id).await;
        let (status, _, body) = send(&h.app, get_page("/admin", Some(&cookie))).await;
        assert_eq!(status, expected, "user {id}");
        if status == StatusCode::OK {
            assert_eq!(body, "3");
        }
    }
}

#[tokio::test]
async fn sessions_of_pending_and_denied_accounts_are_refused() {
    let h = harness().await;
    seed(&h.pool, 1, "Pending", "Administrator").await;
    seed(&h.pool, 2, "Denied", "Customer").await;
    for (id, path) in [(1, "/me"), (1, "/admin"), (2, "/me")] {
        let cookie = cookie_for(&h.pool, id).await;
        let (status, _, _) = send(&h.app, get_page(path, Some(&cookie))).await;
        assert_eq!(status, StatusCode::UNAUTHORIZED, "user {id} at {path}");
    }
}

#[tokio::test]
async fn expired_sessions_are_refused() {
    let h = harness().await;
    seed(&h.pool, 1, "Active", "Customer").await;
    let long_ago = db::unix_now() - session::TTL_SECS - 5;
    let token = session::create(&h.pool, UserId(1), None, long_ago).await.unwrap();
    let cookie = format!("{}={}", session::COOKIE, token.as_str());
    let (status, _, _) = send(&h.app, get_page("/me", Some(&cookie))).await;
    assert_eq!(status, StatusCode::UNAUTHORIZED);
}

/// Minting is the remote-grants command's and the emailed code is the sign-up page's; under /api only redeeming a
/// grant is left.
#[tokio::test]
async fn only_redeeming_is_left_under_api() {
    let h = harness().await;
    seed(&h.pool, 1, "Active", "Administrator").await;
    seed(&h.pool, 5, "Active", "Customer").await;
    let admin = cookie_for(&h.pool, 1).await;
    for (path, body) in [
        ("/api/remote/grants", r#"{"user_id":5}"#),
        ("/api/auth/code", r#"{"email":"pat@example.com"}"#),
        ("/api/auth/verify", r#"{"email":"pat@example.com","code":"2AAAAAAA"}"#),
        ("/api/auth/logout", "{}"),
    ] {
        let (status, _, _) = send(&h.app, post(path, Some(&admin), body)).await;
        assert_eq!(status, StatusCode::NOT_FOUND, "{path}");
    }
    let (status, _, _) = send(&h.app, get_page("/api/me", Some(&admin))).await;
    assert_eq!(status, StatusCode::NOT_FOUND);
    assert_eq!((count(&h.pool, "remote_grant").await, count(&h.pool, "outbox").await), (0, 0));
}

#[tokio::test]
async fn a_grant_redeems_once() {
    let h = harness().await;
    seed(&h.pool, 1, "Active", "Administrator").await;
    seed(&h.pool, 2, "Active", "Customer").await;
    let grant = remote_grant::mint(&h.pool, UserId(2), UserId(1), db::unix_now()).await.unwrap().0;

    let redeem_body = format!(r#"{{"token":"{}"}}"#, grant.as_str());
    let (status, headers, _) = send(&h.app, post("/api/remote/redeem", None, &redeem_body)).await;
    assert_eq!(status, StatusCode::NO_CONTENT);
    let set_cookie = headers.get(header::SET_COOKIE).unwrap().to_str().unwrap().to_string();
    for attribute in ["__Host-hx_session=", "Secure", "HttpOnly", "SameSite=Lax", "Path=/"] {
        assert!(set_cookie.contains(attribute), "{attribute} missing from {set_cookie}");
    }
    let cookie = set_cookie.split(';').next().unwrap();
    let (status, _, body) = send(&h.app, get_page("/me", Some(cookie))).await;
    assert_eq!((status, body.as_str()), (StatusCode::OK, "2"));

    let (status, headers, _) = send(&h.app, post("/api/remote/redeem", None, &redeem_body)).await;
    assert_eq!(status, StatusCode::UNAUTHORIZED, "a grant works once");
    assert!(headers.get(header::SET_COOKIE).is_none());
}

#[tokio::test]
async fn redeem_rejects_unknown_malformed_expired_and_orphaned_tokens() {
    let h = harness().await;
    seed(&h.pool, 1, "Active", "Administrator").await;
    seed(&h.pool, 2, "Active", "Customer").await;

    let expired = remote_grant::mint(&h.pool, UserId(2), UserId(1), db::unix_now() - 10_000).await.unwrap().0;
    let orphaned = remote_grant::mint(&h.pool, UserId(2), UserId(1), db::unix_now()).await.unwrap().0;
    sqlx::query(r#"UPDATE "user" SET status = 'Denied' WHERE id = 2"#).execute(&h.pool).await.unwrap();

    let unknown = "B".repeat(43);
    for token in ["", "nope", "two words", unknown.as_str(), expired.as_str(), orphaned.as_str()] {
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
async fn cross_origin_posts_are_refused() {
    let h = harness().await;
    for (origin, expected) in [("https://elsewhere.example", StatusCode::FORBIDDEN), ("null", StatusCode::FORBIDDEN), (ORIGIN, StatusCode::UNAUTHORIZED)] {
        let req = Request::builder()
            .method(Method::POST)
            .uri("/api/remote/redeem")
            .header(header::ORIGIN, origin)
            .header(header::CONTENT_TYPE, "application/json")
            .body(Body::from(r#"{"token":"nope"}"#))
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

/// The old scs_remote.php entry points, as a request line, answer 410 with no cookie: the guard main.rs answers
/// every unclaimed request with.
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
