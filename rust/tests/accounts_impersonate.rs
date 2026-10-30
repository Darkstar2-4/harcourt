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
fn went_home(status: StatusCode, headers: &HeaderMap) -> bool {
    status == StatusCode::FOUND && headers.get(header::LOCATION).is_some_and(|l| l == "/scs_index.php")
}

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

fn set_cookie(headers: &HeaderMap, name: &str) -> Option<String> {
    headers.get_all(header::SET_COOKIE).iter().map(|v| v.to_str().unwrap().to_string()).find(|c| c.split('=').next() == Some(name))
}

async fn cookie_for(pool: &SqlitePool, id: i64) -> String {
    let token = session::create(pool, UserId(id), None, db::unix_now()).await.unwrap();
    format!("{}={}", session::COOKIE, token.as_str())
}

/// A request from a browser in the middle of a visit (it holds a PHP session cookie), from one of the site's own
/// pages unless the case names its own Referer.
async fn send(app: &Router, method: Method, uri: &str, headers: &[(&str, &str)], form: Option<&str>) -> (StatusCode, HeaderMap, String) {
    let cookie = headers.iter().find(|(k, _)| *k == "cookie").map_or_else(|| "PHPSESSID=test".to_string(), |(_, c)| format!("{c}; PHPSESSID=test"));
    let mut rest: Vec<(&str, &str)> = headers.iter().copied().filter(|(k, _)| *k != "cookie").collect();
    rest.push(("cookie", &cookie));
    send_cold(app, method, uri, &rest, form).await
}

/// A request as it comes, with no PHP session unless the case sends one.
async fn send_cold(app: &Router, method: Method, uri: &str, headers: &[(&str, &str)], form: Option<&str>) -> (StatusCode, HeaderMap, String) {
    let mut req = Request::builder().method(method).uri(uri);
    if !headers.iter().any(|(k, _)| *k == "referer") {
        req = req.header(header::REFERER, format!("{ORIGIN}/request-access"));
    }
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
    let (status, headers, _) = send(&app, Method::GET, "/user_impersonate.php", &[("cookie", &customer)], None).await;
    assert!(went_home(status, &headers));
    let (status, headers, _) = send(&app, Method::POST, "/user_impersonate.php", &[("cookie", &customer)], Some(AS_CUSTOMER)).await;
    assert!(went_home(status, &headers));
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
    let (status, headers, _) = send(&app, Method::GET, "/user_impersonate.php", &[("cookie", &admin)], None).await;
    assert!(went_home(status, &headers));
    assert_eq!(count(&pool, "SELECT count(*) FROM impersonation").await, 0);
    assert_eq!(count(&pool, "SELECT count(*) FROM session").await, 1, "the Administrator stays signed in");
}

#[tokio::test]
async fn an_administrator_signed_in_by_address_gets_a_session_to_hold_the_view() {
    let pool = pool().await;
    let app = app(&pool, OFFICE_IP);
    let (_, headers, body) = send(&app, Method::POST, "/user_impersonate.php", &[], Some("action=update&visitor=1")).await;
    assert_eq!(body, "<script>window.open('/','_top');\n</script>");
    let set = set_cookie(&headers, "__Host-hx_session").unwrap();
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
    let chosen = || async { sqlx::query_scalar::<_, Option<String>>("SELECT language_code FROM visit").fetch_optional(&pool).await.unwrap().flatten() };
    let (status, headers, _) = send(&app, Method::GET, "/language_change.php?language_code=fr", &[("referer", "https://www.harcourt.co/catalog/")], None).await;
    assert_eq!((status, headers.get(header::LOCATION).unwrap().to_str().unwrap()), (StatusCode::FOUND, "https://www.harcourt.co/catalog/"));
    assert_eq!(chosen().await, None, "codes are upper case");
    let (_, headers, _) = send(&app, Method::GET, "/language_change.php?language_code=FR", &[("referer", "https://www.harcourt.co/catalog/")], None).await;
    assert_eq!(chosen().await.as_deref(), Some("FR"), "kept for the visit");
    assert!(set_cookie(&headers, "hx_lang").is_none());

    let customer = cookie_for(&pool, 2).await;
    let saved = || async { sqlx::query_scalar::<_, String>(r#"SELECT language_code FROM "user" WHERE id = 2"#).fetch_one(&pool).await.unwrap() };
    send(&app, Method::GET, "/language_change.php?language_code=FR", &[("cookie", &customer), ("referer", "https://evil.example/x")], None).await;
    assert_eq!(saved().await, "EN", "a link from another site does not change the account");
    send(&app, Method::GET, "/language_change.php?language_code=FR", &[("cookie", &customer), ("referer", "https://www.harcourt.co/catalog/")], None).await;
    assert_eq!(saved().await, "FR");

    let (_, headers, _) = send(&app, Method::GET, "/language_change.php?language_code=xx", &[("referer", "https://www.harcourt.co/societe/")], None).await;
    assert_eq!(headers.get(header::LOCATION).unwrap(), "https://www.harcourt.co/societe/");
    let (_, headers, _) = send(&app, Method::GET, "/language_change.php?language_code=EN", &[("referer", "https://evil.example/x")], None).await;
    assert_eq!(headers.get(header::LOCATION).unwrap(), "/", "only back to a page on this site");
}

#[tokio::test]
async fn a_visit_that_begins_on_an_scs_page_goes_home_first() {
    let pool = pool().await;
    sqlx::query("INSERT INTO subcategory (id, category_id, status) VALUES (1, 1, 'Active')").execute(&pool).await.unwrap();
    let outside = [("referer", "https://search.example/")];
    let location = |h: &HeaderMap| h.get(header::LOCATION).map(|v| v.to_str().unwrap().to_string());
    let cases = [
        ("198.51.100.1", "/newuser.php", None, "/scs_index.php"),
        ("198.51.100.1", "/newuser.php?scscpq_wp=1", None, "/"),
        ("198.51.100.1", "/user_impersonate.php", Some(2), "/catalog.php"),
        ("198.51.100.1", "/language_change.php?language_code=FR", Some(2), "/catalog.php"),
        ("198.51.100.1", "/newuser.php", Some(1), "/dashboard.php"),
        (OFFICE_IP, "/newuser.php?scscpq_wp=1", None, "/dashboard.php"),
    ];
    for (peer, path, signed_in, expected) in cases {
        let cookie = match signed_in {
            Some(id) => cookie_for(&pool, id).await,
            None => String::new(),
        };
        let mut h = outside.to_vec();
        if !cookie.is_empty() {
            h.push(("cookie", cookie.as_str()));
        }
        let (status, headers, _) = send_cold(&app(&pool, peer), Method::GET, path, &h, None).await;
        assert_eq!((status, location(&headers).as_deref()), (StatusCode::FOUND, Some(expected)), "{path} as {signed_in:?}");
        let set: Vec<&str> = headers.get_all(header::SET_COOKIE).iter().map(|v| v.to_str().unwrap().split('=').next().unwrap()).collect();
        assert_eq!(set, ["PHPSESSID", "harcourt_profile"], "the visit's PHP session, and the profile PHP loaded");
    }
    let admin = cookie_for(&pool, 1).await;
    let (status, _, _) = send_cold(&app(&pool, "198.51.100.1"), Method::GET, "/user_impersonate.php", &[("cookie", &admin)], None).await;
    assert_eq!(status, StatusCode::FOUND, "a link from one of the site's pages does not make a visit");
    let (status, headers, _) = send(&app(&pool, "198.51.100.1"), Method::GET, "/user_impersonate.php", &[("cookie", &admin)], None).await;
    assert_eq!(status, StatusCode::OK, "inside a visit the page itself answers");
    assert!(set_cookie(&headers, "harcourt_profile").is_none(), "an Active account's profile is not reloaded mid-visit");
    let (_, headers, _) = send(&app(&pool, "198.51.100.1"), Method::GET, "/newuser.php", &[], None).await;
    assert!(set_cookie(&headers, "PHPSESSID").is_none());
    assert!(set_cookie(&headers, "harcourt_profile").is_some(), "an anonymous visitor's is, on every page");
}

#[tokio::test]
async fn cookie_reset_signs_out_and_forgets_the_choices() {
    let pool = pool().await;
    let app = app(&pool, "198.51.100.1");
    let customer = cookie_for(&pool, 2).await;
    send(&app, Method::GET, "/language_change.php?language_code=FR", &[("cookie", &customer), ("referer", "https://www.harcourt.co/catalog/")], None).await;
    assert_eq!(count(&pool, "SELECT count(*) FROM visit").await, 1);
    let (status, headers, body) = send(&app, Method::GET, "/cookie_reset.php", &[("cookie", &customer)], None).await;
    assert_eq!(status, StatusCode::OK);
    assert!(body.contains("<p>Your cookies and cache have been reset</p>"));
    let cleared: Vec<String> = headers.get_all(header::SET_COOKIE).iter().map(|v| v.to_str().unwrap().split('=').next().unwrap().to_string()).collect();
    assert_eq!(cleared, ["harcourt", "__Host-hx_session"], "the sign-in, and always harcourt");
    assert_eq!(count(&pool, "SELECT count(*) FROM session").await, 0);
    assert_eq!(count(&pool, "SELECT count(*) FROM visit WHERE language_code IS NULL AND destroyed = 1").await, 1, "the visit is forgotten");
    let (status, headers, _) = send(&app, Method::GET, "/newuser.php", &[], None).await;
    assert_eq!((status, headers.get(header::LOCATION).unwrap().to_str().unwrap()), (StatusCode::FOUND, "/scs_index.php"), "back with the old id: a new visit");
    let (status, _, _) = send(&app, Method::GET, "/newuser.php", &[], None).await;
    assert_eq!(status, StatusCode::OK, "and then inside it");
}
