//! campaign_manager.php through the router, against an in-memory database with synthetic rows.

use std::sync::Arc;

use axum::{
    body::Body,
    http::{header, Method, Request, StatusCode},
    Router,
};
use http_body_util::BodyExt;
use hxweb::{api::Auth, db, identity::UserId, session};
use sqlx::SqlitePool;
use tower::ServiceExt;

async fn harness() -> (Router, SqlitePool) {
    let pool = db::open_memory().await.unwrap();
    let auth = Arc::new(Auth { pool: pool.clone(), secret: None, public_origin: "https://www.harcourt.co".into(), trusted_proxies: vec![], accounts: hxweb::accounts::Services::offline(hxweb::accounts::bot::Dns::fixed(&[], &[])) });
    for sql in [
        "INSERT INTO campaign (id, code, name, active, \"type\", sort_order) VALUES (1, 'SPRING', 'Spring Kits', 1, 'Public', 0), (2, 'OLD', 'Old Offer', 0, 'Invite', 1)",
        "INSERT INTO content (id, record_type, record_id, record_item, language_code, name, description, filetype, meta, html_content, revised)
         VALUES (40, 'campaign', 1, 'Public:000000', '', 'Welcome', '', 'html', '{\"name\":\"w.html\",\"mime\":\"text/html\"}', '<p>Hi</p>', 0),
                (41, 'campaign', 1, 'Private:000001', '', 'Prices', 'For members', 'html', '{}', '', 0)",
    ] {
        sqlx::query(sql).execute(&pool).await.unwrap();
    }
    (hxweb::routes::staff::router(auth), pool)
}

async fn person(pool: &SqlitePool, id: i64, role: &str) -> String {
    sqlx::query(r#"INSERT INTO "user" (id, ip, status, "type", company_name, country_code) VALUES (?1, ?2, 'Active', ?3, 'Test', 'US')"#)
        .bind(id)
        .bind(format!("192.0.2.{id}"))
        .bind(role)
        .execute(pool)
        .await
        .unwrap();
    let token = session::create(pool, UserId(id), None, db::unix_now()).await.unwrap();
    format!("{}={}", session::COOKIE, token.as_str())
}

async fn send(app: &Router, req: Request<Body>) -> (StatusCode, String) {
    let res = app.clone().oneshot(req).await.unwrap();
    let status = res.status();
    let bytes = res.into_body().collect().await.unwrap().to_bytes();
    (status, String::from_utf8_lossy(&bytes).into_owned())
}

fn post(cookie: &str, body: &str, ajax: bool) -> Request<Body> {
    let mut req = Request::builder().method(Method::POST).uri("/campaign_manager.php").header(header::COOKIE, cookie).header(header::CONTENT_TYPE, "application/x-www-form-urlencoded");
    if ajax {
        req = req.header("X-Requested-With", "XMLHttpRequest");
    }
    req.body(Body::from(body.to_string())).unwrap()
}

async fn csrf(app: &Router, cookie: &str) -> String {
    let (_, page) = send(app, Request::builder().uri("/campaign_manager.php").header(header::COOKIE, cookie).body(Body::empty()).unwrap()).await;
    let marker = "name='csrf' id='csrf' value='";
    let start = page.find(marker).unwrap() + marker.len();
    page[start..].split('\'').next().unwrap().to_string()
}

#[tokio::test]
async fn the_ajax_rows_need_an_administrator_too() {
    let (app, pool) = harness().await;
    let executive = person(&pool, 2, "Executive").await;
    let (status, _) = send(&app, post(&executive, "ajax_action=add&ajax_row=3", true)).await;
    assert_eq!(status, StatusCode::FOUND);
    let admin = person(&pool, 1, "Administrator").await;
    let (_, json) = send(&app, post(&admin, "ajax_action=add&ajax_row=3", true)).await;
    assert!(json.starts_with(r#"{"row":"<div id=content_div3><h3 id=content_label3>Empty: New Content<\/h3>"#), "{json}");
    assert!(json.ends_with(r#""status":"Add row 3"}"#));
}

#[tokio::test]
async fn a_campaign_keeps_its_documents_in_order_through_an_edit() {
    let (app, pool) = harness().await;
    let admin = person(&pool, 1, "Administrator").await;
    let token = csrf(&app, &admin).await;
    let (_, list) = send(&app, post(&admin, &format!("csrf={token}"), false)).await;
    assert!(list.find("SPRING").unwrap() < list.find("OLD").unwrap(), "active campaigns first");
    assert!(list.contains("<tr style='background-color: #ffff00;'>"), "an inactive campaign is yellow");
    let (_, page) = send(&app, post(&admin, &format!("csrf={token}&action=maintain&record_id=1"), false)).await;
    assert!(page.contains("<h3 id=content_label0>Public: Welcome</h3>"));
    assert!(page.contains("<input type=hidden name='row' id='row' value='1'>"));
    let (_, json) = send(&app, post(&admin, "ajax_action=delete&ajax_content_id=41", true)).await;
    assert_eq!(json, r#"{"status":"Delete content id 41"}"#);
    let body = format!("csrf={token}&action=update&record_id=1&code=spring&name=Spring+Kits&type=Public&active=1&content_name0=Welcome&content_content_id0=40&content_access0=Semi-Private&content_description0=");
    let (_, page) = send(&app, post(&admin, &body, false)).await;
    assert!(page.contains("Record maintained"), "{page}");
    let items: Vec<(i64, String)> = sqlx::query_as("SELECT id, record_item FROM content WHERE record_type = 'campaign' ORDER BY id").fetch_all(&pool).await.unwrap();
    assert_eq!(items, [(40, "Semi-Private:000000".to_string())], "the deleted row went with the save");
    let html: String = sqlx::query_scalar("SELECT html_content FROM content WHERE id = 40").fetch_one(&pool).await.unwrap();
    assert_eq!(html, "<p>Hi</p>");
}

#[tokio::test]
async fn a_campaign_without_a_code_or_content_is_refused() {
    let (app, pool) = harness().await;
    let admin = person(&pool, 1, "Administrator").await;
    let token = csrf(&app, &admin).await;
    send(&app, post(&admin, &format!("csrf={token}&action=maintain&record_id="), false)).await;
    let (_, page) = send(&app, post(&admin, &format!("csrf={token}&action=update&record_id=&code=&name=New&type=Public&css=sheet.css"), false)).await;
    assert!(page.contains("Content required<br>Correct 3 error(s)"), "{page}");
    assert!(page.contains("<br>sheet.css must begin with /"));
    let count: i64 = sqlx::query_scalar("SELECT count(*) FROM campaign").fetch_one(&pool).await.unwrap();
    assert_eq!(count, 2);
}
