//! Carrier codes and the contact page through the orders router, with synthetic rows.

use std::{net::SocketAddr, path::PathBuf, sync::Arc};

use axum::{
    body::Body,
    extract::connect_info::MockConnectInfo,
    http::{header, HeaderMap, Method, Request, StatusCode},
    Router,
};
use http_body_util::BodyExt;
use hxweb::{
    api::Auth,
    db,
    identity::UserId,
    orders::{forms, pricing::Unpriced, Orders},
    session,
};
use sqlx::SqlitePool;
use tower::ServiceExt;

async fn harness() -> (Router, SqlitePool) {
    let pool = db::open_memory().await.unwrap();
    let core = Arc::new(Auth { pool: pool.clone(), secret: None, public_origin: "https://www.harcourt.co".into(), trusted_proxies: vec![], accounts: hxweb::accounts::Services::offline(hxweb::accounts::bot::Dns::fixed(&[], &[])) });
    let state = Orders { core: core.clone(), pricing: Arc::new(Unpriced), docroot: PathBuf::from("/nonexistent"), forms: forms::Services::default() };
    let pages = hxweb::orders::router(Arc::new(state)).layer(axum::middleware::from_fn_with_state(core, hxweb::visitor::layer));
    (pages.layer(MockConnectInfo(SocketAddr::from(([192, 0, 2, 30], 40000)))), pool)
}

async fn cookie(pool: &SqlitePool, id: i64, role: &str) -> String {
    sqlx::query(r#"INSERT INTO "user" (id, status, "type", language_code) VALUES (?1, 'Active', ?2, 'FR')"#).bind(id).bind(role).execute(pool).await.unwrap();
    let token = session::create(pool, UserId(id), None, db::unix_now()).await.unwrap();
    format!("{}={}", session::COOKIE, token.as_str())
}

async fn send(app: &Router, method: Method, uri: &str, cookie: Option<&str>, form: &str, lang: Option<&str>) -> (StatusCode, HeaderMap, String) {
    let mut r = Request::builder()
        .method(method)
        .uri(uri)
        .header(header::REFERER, "https://www.harcourt.co/contact-us/")
        .header(header::CONTENT_TYPE, "application/x-www-form-urlencoded")
        .header(header::COOKIE, cookie.map_or_else(|| "PHPSESSID=mid-visit".to_string(), |c| format!("{c}; PHPSESSID=mid-visit")));
    if let Some(l) = lang {
        r = r.header(header::ACCEPT_LANGUAGE, l);
    }
    let res = app.clone().oneshot(r.body(Body::from(form.to_string())).unwrap()).await.unwrap();
    let (parts, body) = res.into_parts();
    (parts.status, parts.headers, String::from_utf8_lossy(&body.collect().await.unwrap().to_bytes()).into_owned())
}

#[tokio::test]
async fn carriers_are_maintained_by_administrators() {
    let (app, pool) = harness().await;
    let customer = cookie(&pool, 1, "Customer").await;
    let (status, headers, _) = send(&app, Method::GET, "/carrier.php", Some(&customer), "", None).await;
    assert_eq!((status, headers.get(header::LOCATION).unwrap().to_str().unwrap()), (StatusCode::FOUND, "/scs_index.php"));
    let admin = cookie(&pool, 2, "Administrator").await;
    let (_, _, page) = send(&app, Method::POST, "/carrier.php", Some(&admin), "action=update&record_id=0&name=Test+Freight&active=1", None).await;
    assert!(page.contains("Record maintained"));
    let (_, _, page) = send(&app, Method::POST, "/carrier.php", Some(&admin), "action=update&record_id=0&name=", None).await;
    assert!(page.contains("Correct 1 error(s)") && page.contains("style='border: 1px solid red !important;'"));
    let id: i64 = sqlx::query_scalar("SELECT id FROM carrier WHERE name = 'Test Freight'").fetch_one(&pool).await.unwrap();
    let (_, _, page) = send(&app, Method::POST, "/carrier.php", Some(&admin), &format!("action=delete&record_id={id}"), None).await;
    assert!(!page.contains("deleted"), "an active carrier is not deleted");
    send(&app, Method::POST, "/carrier.php", Some(&admin), &format!("action=update&record_id={id}&name=Test+Freight"), None).await;
    let (_, _, page) = send(&app, Method::GET, "/carrier.php", Some(&admin), "", None).await;
    assert!(page.contains(&format!("name='delete' id='delete_{id}'")), "an inactive carrier can be deleted");
    let (_, _, page) = send(&app, Method::POST, "/carrier.php", Some(&admin), &format!("action=delete&record_id={id}"), None).await;
    assert!(page.contains("Carrier Test Freight deleted"));
    let left: i64 = sqlx::query_scalar("SELECT count(*) FROM carrier").fetch_one(&pool).await.unwrap();
    assert_eq!(left, 0);
}

#[tokio::test]
async fn the_contact_page_shows_its_document_in_the_visitors_language() {
    let (app, pool) = harness().await;
    sqlx::query("INSERT INTO language (code, country_code, name, active) VALUES ('EN', 'US', 'English', 1), ('FR', 'FR', 'Français', 1)").execute(&pool).await.unwrap();
    sqlx::query("INSERT INTO pages (record_type, name, url, document_id) VALUES ('general', 'Contact Us', '/contact.php', 42)").execute(&pool).await.unwrap();
    sqlx::query("INSERT INTO content (record_type, record_id, language_code, filetype, html_content) VALUES ('document', 42, 'EN', 'html', '<p>Call us</p>'), ('document', 42, 'FR', 'html', '<p>Appelez-nous</p>')")
        .execute(&pool)
        .await
        .unwrap();
    let (status, _, page) = send(&app, Method::GET, "/contact.php", None, "", Some("en-US,en;q=0.9")).await;
    assert_eq!(status, StatusCode::OK);
    assert!(page.contains("<title>Harcourt | Engineering Productivity Solutions | Contact Us</title>"));
    assert!(page.contains("\n<!-- Body Content -->\n<p>Call us</p>\n<!-- End Body Content -->\n"));
    let (_, _, page) = send(&app, Method::GET, "/contact.php", None, "", Some("fr-CA,fr;q=0.9")).await;
    assert!(page.contains("<p>Appelez-nous</p>"));
    let signed_in = cookie(&pool, 3, "Customer").await;
    let (_, _, page) = send(&app, Method::GET, "/contact.php", Some(&signed_in), "", Some("en-US")).await;
    assert!(page.contains("<p>Appelez-nous</p>"), "the account's language wins");
    let views: i64 = sqlx::query_scalar("SELECT count(*) FROM log WHERE type = 'Page' AND subtype = 'General' AND comment = 'Contact Us'").fetch_one(&pool).await.unwrap();
    assert_eq!(views, 3);
}

async fn ajax(app: &Router, cookie: &str, form: &str) -> serde_json::Value {
    let req = Request::builder()
        .method(Method::POST)
        .uri("/design_partner_agreement.php")
        .header(header::COOKIE, format!("{cookie}; PHPSESSID=mid-visit"))
        .header("x-requested-with", "XMLHttpRequest")
        .header(header::CONTENT_TYPE, "application/x-www-form-urlencoded")
        .body(Body::from(form.to_string()))
        .unwrap();
    let res = app.clone().oneshot(req).await.unwrap();
    serde_json::from_slice(&res.into_body().collect().await.unwrap().to_bytes()).unwrap()
}

#[tokio::test]
async fn design_partner_blocks_are_saved_per_company() {
    let (app, pool) = harness().await;
    sqlx::query("INSERT INTO category (id, name, sort_order, active) VALUES (1, 'Alpha', 1, 1), (2, 'Beta', 2, 1), (3, 'Gone', 3, 0)").execute(&pool).await.unwrap();
    sqlx::query(r#"INSERT INTO "user" (id, status, "type", company_name) VALUES (50, 'Active', 'Customer', 'Test Co')"#).execute(&pool).await.unwrap();
    let customer = cookie(&pool, 1, "Customer").await;
    let (status, _, _) = send(&app, Method::GET, "/design_partner_agreement.php", Some(&customer), "", None).await;
    assert_eq!(status, StatusCode::FOUND);
    let admin = cookie(&pool, 2, "Administrator").await;
    let a = ajax(&app, &admin, "action=load&company_name=Nobody").await;
    assert!(a["message"].as_str().unwrap().contains("No users found for company &ldquo;Nobody&rdquo;"));
    let a = ajax(&app, &admin, "action=save&company_name=Test+Co&company_category_2=1&company_category_1=0&company_category_3=1").await;
    assert_eq!(a["status"].as_str(), Some("success"));
    assert!(a["content"].as_str().unwrap().contains("name='company_category_2' id='company_category_2' value='1' checked>Beta"));
    let blocked: Vec<i64> = sqlx::query_scalar("SELECT category_id FROM company_category_block WHERE company_name = 'Test Co'").fetch_all(&pool).await.unwrap();
    assert_eq!(blocked, [2], "inactive categories are never blocked");
    let a = ajax(&app, &admin, "action=save&company_name=").await;
    assert_eq!(a["status"].as_str(), Some("error"));
}

#[tokio::test]
async fn a_visit_that_begins_on_an_scs_page_goes_home_first() {
    let (app, pool) = harness().await;
    let admin = cookie(&pool, 2, "Administrator").await;
    let first = |path: &str, cookie: &str| Request::builder().uri(path).header(header::COOKIE, cookie).body(Body::empty()).unwrap();
    let res = app.clone().oneshot(first("/carrier.php", &admin)).await.unwrap();
    assert_eq!(res.status(), StatusCode::FOUND);
    assert_eq!(res.headers()[header::LOCATION], "/dashboard.php");
    assert_eq!(res.headers()[header::CONTENT_TYPE], "text/html; charset=UTF-8");
    let res = app.clone().oneshot(first("/carrier.php", &format!("{admin}; PHPSESSID=mid-visit"))).await.unwrap();
    assert_eq!(res.status(), StatusCode::OK);
    let res = app.clone().oneshot(first("/contact.php", "")).await.unwrap();
    assert_eq!(res.headers()[header::LOCATION], "/scs_index.php");
}
