//! user_impersonate.php, language_change.php and cookie_reset.php through the page router, against a private
//! in-memory database of synthetic accounts.

use std::{net::SocketAddr, sync::Arc};

use axum::{
    body::Body,
    extract::connect_info::MockConnectInfo,
    http::{header, HeaderMap, Method, Request, StatusCode},
    Router,
};
use http_body_util::BodyExt;
use hxweb::{
    accounts::{bot::Dns, Services},
    api::Auth,
    config::Secret,
    db,
    identity::UserId,
    routes, session,
};
use sqlx::SqlitePool;
use tower::ServiceExt;

const ORIGIN: &str = "https://www.harcourt.co";
const OFFICE_IP: &str = "203.0.113.40";
const HOME: &str = "<script language=\"javascript\">\nwindow.open('/','_top')\n</script>";

async fn pool() -> SqlitePool {
    let pool = db::open_memory().await.unwrap();
    for sql in [
        r#"INSERT INTO "user" (id, status, "type", company_name, ip) VALUES (1, 'Active', 'Administrator', 'Staff', NULL), (2, 'Active', 'Customer', 'Customer Co', NULL), (3, 'Active', 'Super User', 'Owner', NULL), (4, 'Active', 'Administrator', 'Office', '203.0.113.40')"#,
        "INSERT INTO language (code, country_code, name, priority, active) VALUES ('EN', 'US', 'English', 1, 1), ('FR', 'CA', 'Français', 2, 1)",
        "INSERT INTO currency (code, name, plural, exchange_rate, active) VALUES ('USD', 'US Dollar', 'US Dollars', 1, 1)",
    ] {
        sqlx::query(sql).execute(&pool).await.unwrap();
    }
    pool
}

fn app(pool: &SqlitePool, peer: &str) -> Router {
    let core = Arc::new(Auth {
        pool: pool.clone(),
        secret: Some(Secret::from_bytes(vec![7; 32])),
        public_origin: ORIGIN.into(),
        trusted_proxies: vec!["127.0.0.1".parse().unwrap()],
        accounts: Services::offline(Dns::fixed(&[], &[])),
    });
    let peer: SocketAddr = format!("{peer}:40000").parse().unwrap();
    routes::all(core).layer(MockConnectInfo(peer))
}

async fn cookie_for(pool: &SqlitePool, id: i64) -> String {
    let token = session::create(pool, UserId(id), None, db::unix_now()).await.unwrap();
    format!("{}={}", session::COOKIE, token.as_str())
}

async fn send(app: &Router, method: Method, uri: &str, headers: &[(&str, &str)], form: Option<&str>) -> (StatusCode, HeaderMap, String) {
    let mut req = Request::builder().method(method).uri(uri);
    for (k, v) in headers {
        req = req.header(*k, *v);
    }
    let body = match form {
        Some(f) => {
            req = req.header(header::CONTENT_TYPE, "application/x-www-form-urlencoded");
            Body::from(f.to_string())
        }
        None => Body::empty(),
    };
    let res = app.clone().oneshot(req.body(body).unwrap()).await.unwrap();
    let (parts, body) = res.into_parts();
    (parts.status, parts.headers, String::from_utf8_lossy(&body.collect().await.unwrap().to_bytes()).into_owned())
}

async fn count(pool: &SqlitePool, sql: &str) -> i64 {
    sqlx::query_scalar(sql).fetch_one(pool).await.unwrap()
}

const AS_CUSTOMER: &str = "action=update&ajax_user_id=2&ajax_user_type=Customer&ajax_user_status=Active&ajax_user_catalog=1&ajax_user_price_type=discount&ajax_user_price_pct=15&ajax_user_language_code=fr&ajax_user_currency_code=USD";

#[tokio::test]
async fn only_an_administrator_gets_the_form() {
    let pool = pool().await;
    let app = app(&pool, "198.51.100.1");
    let customer = cookie_for(&pool, 2).await;
    let (_, _, body) = send(&app, Method::GET, "/user_impersonate.php", &[("cookie", &customer)], None).await;
    assert_eq!(body, HOME);
    let (_, _, body) = send(&app, Method::POST, "/user_impersonate.php", &[("cookie", &customer)], Some(AS_CUSTOMER)).await;
    assert_eq!(body, HOME);
    assert_eq!(count(&pool, "SELECT count(*) FROM impersonation").await, 0);
    let admin = cookie_for(&pool, 1).await;
    let (_, _, body) = send(&app, Method::GET, "/user_impersonate.php", &[("cookie", &admin)], None).await;
    assert!(body.contains("<input type=text name='ajax_user_id' id='ajax_user_id' size='12' value='1'>"), "{body}");
    assert!(body.contains("<option value='Administrator' selected>Administrator</option>"));
}

#[tokio::test]
async fn an_administrator_cannot_pick_a_role_or_a_target_above_their_own() {
    let pool = pool().await;
    let app = app(&pool, "198.51.100.1");
    let admin = cookie_for(&pool, 1).await;
    let upward = AS_CUSTOMER.replace("ajax_user_type=Customer", "ajax_user_type=Super+User");
    let (_, _, body) = send(&app, Method::POST, "/user_impersonate.php", &[("cookie", &admin)], Some(&upward)).await;
    assert!(body.contains("Invalid User Type"), "{body}");
    let above = AS_CUSTOMER.replace("ajax_user_id=2", "ajax_user_id=3");
    let (_, _, body) = send(&app, Method::POST, "/user_impersonate.php", &[("cookie", &admin)], Some(&above)).await;
    assert!(body.contains("Invalid User ID"));
    assert_eq!(count(&pool, "SELECT count(*) FROM impersonation").await, 0);
}

#[tokio::test]
async fn impersonating_stores_the_view_and_any_visit_stops_it() {
    let pool = pool().await;
    let app = app(&pool, "198.51.100.1");
    let admin = cookie_for(&pool, 1).await;
    let (_, _, body) = send(&app, Method::POST, "/user_impersonate.php", &[("cookie", &admin)], Some(AS_CUSTOMER)).await;
    assert_eq!(body, "<script>window.open('/','_top');\n</script>");
    let (user, ty, pct, lang): (i64, String, f64, String) =
        sqlx::query_as(r#"SELECT user_id, "type", price_pct, language_code FROM impersonation"#).fetch_one(&pool).await.unwrap();
    assert_eq!((user, ty.as_str(), lang.as_str()), (2, "Customer", "FR"));
    assert!((pct - 0.15).abs() < 1e-9);
    assert_eq!(count(&pool, r#"SELECT count(*) FROM "user" WHERE id = 2 AND price_pct = 0 AND price_type = ''"#).await, 1, "the account itself is not changed");

    let (_, _, body) = send(&app, Method::GET, "/newuser.php", &[("cookie", &admin)], None).await;
    assert!(!body.contains("profile_register"), "seen as the signed-in customer");
    let (_, _, body) = send(&app, Method::GET, "/user_impersonate.php", &[("cookie", &admin)], None).await;
    assert_eq!(body, HOME);
    assert_eq!(count(&pool, "SELECT count(*) FROM impersonation").await, 0);
    assert_eq!(count(&pool, "SELECT count(*) FROM session").await, 1, "the Administrator stays signed in");
}

#[tokio::test]
async fn an_administrator_signed_in_by_address_gets_a_session_to_hold_the_view() {
    let pool = pool().await;
    let app = app(&pool, OFFICE_IP);
    let (_, headers, body) = send(&app, Method::POST, "/user_impersonate.php", &[], Some("action=update&visitor=1")).await;
    assert_eq!(body, "<script>window.open('/','_top');\n</script>");
    let set = headers.get(header::SET_COOKIE).unwrap().to_str().unwrap();
    assert!(set.starts_with("__Host-hx_session="));
    assert_eq!(count(&pool, "SELECT count(*) FROM impersonation WHERE user_id IS NULL").await, 1);
    let cookie = set.split(';').next().unwrap().to_string();
    let (_, _, body) = send(&app, Method::GET, "/newuser.php", &[("cookie", &cookie)], None).await;
    assert!(body.contains("User impersonating / no update allowed."), "seen as an anonymous visitor");
}

#[tokio::test]
async fn a_post_from_another_site_is_refused() {
    let pool = pool().await;
    let app = app(&pool, OFFICE_IP);
    let (status, _, _) = send(&app, Method::POST, "/user_impersonate.php", &[("origin", "https://evil.example")], Some(AS_CUSTOMER)).await;
    assert_eq!(status, StatusCode::FORBIDDEN);
    assert_eq!(count(&pool, "SELECT count(*) FROM impersonation").await, 0);
}

#[tokio::test]
async fn a_language_choice_is_kept_and_saved_to_the_account() {
    let pool = pool().await;
    let app = app(&pool, "198.51.100.1");
    let (status, headers, _) = send(&app, Method::GET, "/language_change.php?language_code=fr", &[("referer", "https://www.harcourt.co/catalog/")], None).await;
    assert_eq!((status, headers.get(header::LOCATION).unwrap().to_str().unwrap()), (StatusCode::FOUND, "/catalog/"));
    assert!(headers.get(header::SET_COOKIE).unwrap().to_str().unwrap().starts_with("hx_lang=FR; Path=/;"));

    let customer = cookie_for(&pool, 2).await;
    let saved = || async { sqlx::query_scalar::<_, String>(r#"SELECT language_code FROM "user" WHERE id = 2"#).fetch_one(&pool).await.unwrap() };
    send(&app, Method::GET, "/language_change.php?language_code=FR", &[("cookie", &customer), ("referer", "https://evil.example/x")], None).await;
    assert_eq!(saved().await, "EN", "a link from another site does not change the account");
    send(&app, Method::GET, "/language_change.php?language_code=FR", &[("cookie", &customer), ("referer", "https://www.harcourt.co/catalog/")], None).await;
    assert_eq!(saved().await, "FR");

    let (_, headers, _) = send(&app, Method::GET, "/language_change.php?language_code=xx", &[("referer", "https://evil.example/x")], None).await;
    assert_eq!(headers.get(header::LOCATION).unwrap(), "/");
    assert!(headers.get(header::SET_COOKIE).is_none());
}

#[tokio::test]
async fn cookie_reset_signs_out_and_forgets_the_choices() {
    let pool = pool().await;
    let app = app(&pool, "198.51.100.1");
    let customer = cookie_for(&pool, 2).await;
    let (status, headers, body) = send(&app, Method::GET, "/cookie_reset.php", &[("cookie", &format!("{customer}; hx_lang=FR"))], None).await;
    assert_eq!(status, StatusCode::OK);
    assert!(body.contains("<p>Your cookies and cache have been reset</p>"));
    let cleared: Vec<String> = headers.get_all(header::SET_COOKIE).iter().map(|v| v.to_str().unwrap().split('=').next().unwrap().to_string()).collect();
    assert_eq!(cleared, ["__Host-hx_session", "harcourt", "hx_lang", "hx_campaign"]);
    assert_eq!(count(&pool, "SELECT count(*) FROM session").await, 0);
}
