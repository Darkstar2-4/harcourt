//! portalcontent.php through the router, against an in-memory database with synthetic rows.

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
        "INSERT INTO language (code, name, local_name, priority, active) VALUES ('EN', 'English', 'English', 1, 1), ('FR', 'French', 'Français', 2, 1)",
        "INSERT INTO portal (id, name, active) VALUES (3, 'Example Aero', 1)",
        "INSERT INTO portalcontent (id, portal_id, name, form, active) VALUES (7, 3, 'Catalog Link', 'Hyperlink', 1)",
        "INSERT INTO content (id, record_type, record_id, record_item, language_code, name, filetype, meta, html_content, revised)
         VALUES (70, 'portalcontent', 7, '', 'EN', 'Catalog Link', 'hyperlink', '{\"name\":\"manual.html\",\"mime\":\"text/html\"}', 'https://example.com/a', 0)",
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

async fn submit(app: &Router, cookie: &str, body: &str) -> String {
    let (_, page) = send(app, Request::builder().uri("/portalcontent.php").header(header::COOKIE, cookie).body(Body::empty()).unwrap()).await;
    let marker = "name='csrf' id='csrf' value='";
    let start = page.find(marker).unwrap() + marker.len();
    let csrf = page[start..].split('\'').next().unwrap().to_string();
    let req = Request::builder()
        .method(Method::POST)
        .uri("/portalcontent.php")
        .header(header::COOKIE, cookie)
        .header(header::CONTENT_TYPE, "application/x-www-form-urlencoded")
        .body(Body::from(format!("csrf={csrf}&{body}")))
        .unwrap();
    send(app, req).await.1
}

#[tokio::test]
async fn a_hyperlink_is_kept_per_language_and_a_resent_save_is_refused() {
    let (app, pool) = harness().await;
    let executive = person(&pool, 2, "Executive").await;
    let (status, _) = send(&app, Request::builder().uri("/portalcontent.php").header(header::COOKIE, &executive).body(Body::empty()).unwrap()).await;
    assert_eq!(status, StatusCode::FOUND);
    let admin = person(&pool, 1, "Administrator").await;
    let list = submit(&app, &admin, "").await;
    assert!(list.contains("<h3>Example Aero (1)</h3>"));
    let page = submit(&app, &admin, "action=maintain&record_id=7").await;
    assert!(page.contains(">https://example.com/a</textarea>"), "{page}");
    let page = submit(&app, &admin, "action=update&record_id=7&portal_id=3&form=Hyperlink&active=1&bmFtZQlFTg=Catalog+Link&aHlwZXJsaW5rCUVO=http%3A%2F%2Fexample.com%2Fb").await;
    assert!(page.contains("Malformed URL<br><textarea"), "{page}");
    let page = submit(&app, &admin, "action=update&record_id=7&portal_id=3&form=Hyperlink&active=1&bmFtZQlFTg=Catalog+Link&aHlwZXJsaW5rCUVO=https%3A%2F%2Fexample.com%2Fb").await;
    assert!(page.contains("Record maintained"), "{page}");
    let link: String = sqlx::query_scalar("SELECT html_content FROM content WHERE id = 70").fetch_one(&pool).await.unwrap();
    assert_eq!(link, "https://example.com/b");
    let page = submit(&app, &admin, "action=update&record_id=7").await;
    assert!(page.contains("Refresh not allowed"));
}
