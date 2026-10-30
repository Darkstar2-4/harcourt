//! hero_preview.php and scs_track_log.php through the router, against an in-memory database with synthetic rows.

use std::sync::Arc;

use axum::{
    body::Body,
    http::{header, HeaderMap, Method, Request, StatusCode},
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
        "INSERT INTO language (code, name, local_name, priority, active) VALUES ('EN', 'English', 'English', 1, 1), ('FR', 'French', 'Français', 2, 1)",
        "INSERT INTO hero (id, effective_date, portal_id, industry_id, language_code, html, css, js, access, status)
         VALUES (1, '0000-00-00', 0, 0, 'EN', '<b>All</b>', '', '', '', 'Active'), (2, '2020-01-01', 0, 0, 'FR', '<b>Tous</b>', '', '', 'Administrator', 'Active')",
        "INSERT INTO log (ip, date_time, type, subtype, comment) VALUES ('198.51.100.70', 1700000000, 'Track', 'Page', 'a'), ('198.51.100.70', 1700000000, 'User', 'Login', 'b')",
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

async fn send(app: &Router, req: Request<Body>) -> (StatusCode, HeaderMap, String) {
    let res = app.clone().oneshot(req).await.unwrap();
    let (parts, body) = res.into_parts();
    let bytes = body.collect().await.unwrap().to_bytes();
    (parts.status, parts.headers, String::from_utf8_lossy(&bytes).into_owned())
}

fn request(method: Method, path: &str, cookie: &str, body: &str, ajax: bool) -> Request<Body> {
    let mut req = Request::builder().method(method).uri(path).header(header::COOKIE, cookie).header(header::CONTENT_TYPE, "application/x-www-form-urlencoded");
    if ajax {
        req = req.header("X-Requested-With", "XMLHttpRequest");
    }
    req.body(Body::from(body.to_string())).unwrap()
}

#[tokio::test]
async fn the_hero_preview_answers_for_the_role_and_language_asked() {
    let (app, pool) = harness().await;
    let executive = person(&pool, 2, "Executive").await;
    let (status, _, _) = send(&app, request(Method::POST, "/hero_preview.php", &executive, "action=load", true)).await;
    assert_eq!(status, StatusCode::FOUND);
    let admin = person(&pool, 1, "Administrator").await;
    let (_, _, json) = send(&app, request(Method::POST, "/hero_preview.php", &admin, "action=load&date=10/30/2026&language_code=FR&role=Customer", true)).await;
    assert!(json.contains(r#"<td width='80%'>1<\/td>"#), "the French hero is for Administrators: {json}");
    let (_, _, json) = send(&app, request(Method::POST, "/hero_preview.php", &admin, "action=load&date=10/30/2026&language_code=FR&role=Administrator", true)).await;
    assert!(json.contains(r#"<td>Effective Date<\/td><td>01\/01\/2020<\/td>"#), "{json}");
    assert!(json.contains("<b>Tous<\\/b>"));
    let (_, _, json) = send(&app, request(Method::POST, "/hero_preview.php", &admin, "action=load&date=01/01/2019&language_code=FR&role=Customer&portal_id=5", true)).await;
    assert!(json.contains(r#""preview_summary":"<table"#), "the undated English hero still matches");
}

#[tokio::test]
async fn the_tracking_log_is_for_administrators_and_clears_only_track_rows() {
    let (app, pool) = harness().await;
    let (status, _, _) = send(&app, Request::builder().uri("/scs_track_log.php").body(Body::empty()).unwrap()).await;
    assert_eq!(status, StatusCode::FOUND);
    let admin = person(&pool, 1, "Administrator").await;
    let (_, _, page) = send(&app, request(Method::GET, "/scs_track_log.php", &admin, "", false)).await;
    let marker = "name='csrf' id='csrf' value='";
    let start = page.find(marker).unwrap() + marker.len();
    let csrf = page[start..].split('\'').next().unwrap().to_string();
    let body = format!("csrf={csrf}&action=list&from_timestamp=01/01/2020&thru_timestamp=&ip=198.51.100.70&clear=1");
    let (_, _, page) = send(&app, request(Method::POST, "/scs_track_log.php", &admin, &body, false)).await;
    assert!(page.contains("Tracking Log<span class=message2><br>1 records deleted"), "{page}");
    let left: i64 = sqlx::query_scalar("SELECT count(*) FROM log WHERE ip = '198.51.100.70'").fetch_one(&pool).await.unwrap();
    assert_eq!(left, 1);
}
