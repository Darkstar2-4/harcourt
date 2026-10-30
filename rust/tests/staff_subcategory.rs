//! subcategory.php through the router, against an in-memory database with synthetic rows.

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

struct Harness {
    app: Router,
    pool: SqlitePool,
}

async fn harness() -> Harness {
    let pool = db::open_memory().await.unwrap();
    let auth = Arc::new(Auth { pool: pool.clone(), secret: None, public_origin: "https://www.harcourt.co".into(), trusted_proxies: vec![], accounts: hxweb::accounts::Services::offline(hxweb::accounts::bot::Dns::fixed(&[], &[])) });
    for sql in [
        "INSERT INTO language (code, name, local_name, priority, active) VALUES ('EN', 'English', 'English', 1, 1), ('FR', 'French', 'Français', 2, 1)",
        "INSERT INTO category (id, name, active, sort_order) VALUES (1, 'Clamps', 1, 1)",
        "INSERT INTO industry (id, name, active) VALUES (1, 'Aerospace', 1)",
        "INSERT INTO subcategory (id, category_id, name, status, leadtime_option, industry, portal, prj) VALUES (10, 1, 'Toggle Clamps', 'Active', 'Call', '^1^', '^^', 'info=x/a.prj'), (11, 1, 'Old Clamps', 'Inactive', 'Call', '^1^', '^^', NULL)",
        "INSERT INTO content (id, record_type, record_id, record_item, language_code, name, description, filetype, meta, html_content, revised)
         VALUES (5, 'subcategory', 10, '', 'EN', 'Toggle Clamps', 'Fast clamps', '', '{}', '', 0),
                (6, 'subcategory', 10, 'overview:0', 'EN', 'Description', '', 'html', '{\"name\":\"d.html\",\"mime\":\"text/html\"}', '<p>Strong</p>', 0)",
        "INSERT INTO inventory (sku, category_id, subcategory_id) VALUES ('TC-1', 1, 10), ('TC-2', 1, 10)",
    ] {
        sqlx::query(sql).execute(&pool).await.unwrap();
    }
    Harness { app: hxweb::routes::staff::router(auth), pool }
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

fn get(path: &str, cookie: &str) -> Request<Body> {
    Request::builder().method(Method::GET).uri(path).header(header::COOKIE, cookie).body(Body::empty()).unwrap()
}

fn form(path: &str, cookie: &str, pairs: &[(&str, &str)], ajax: bool) -> Request<Body> {
    let body: String = form_urlencoded::Serializer::new(String::new()).extend_pairs(pairs).finish();
    let mut req = Request::builder().method(Method::POST).uri(path).header(header::COOKIE, cookie).header(header::CONTENT_TYPE, "application/x-www-form-urlencoded");
    if ajax {
        req = req.header("X-Requested-With", "XMLHttpRequest");
    }
    req.body(Body::from(body)).unwrap()
}

async fn submit(h: &Harness, cookie: &str, pairs: &[(&str, &str)]) -> String {
    let (_, _, page) = send(&h.app, get("/subcategory.php", cookie)).await;
    let marker = "name='csrf' id='csrf' value='";
    let start = page.find(marker).unwrap() + marker.len();
    let csrf = page[start..].split('\'').next().unwrap().to_string();
    let with: Vec<(&str, &str)> = pairs.iter().copied().chain([("csrf", csrf.as_str())]).collect();
    send(&h.app, form("/subcategory.php", cookie, &with, false)).await.2
}

#[tokio::test]
async fn every_part_of_the_page_needs_an_administrator() {
    let h = harness().await;
    let executive = person(&h.pool, 2, "Executive").await;
    let (status, headers, _) = send(&h.app, form("/subcategory.php", &executive, &[("action", "sku_count"), ("subcategory_id", "10")], true)).await;
    assert_eq!(status, StatusCode::FOUND);
    assert_eq!(headers[header::LOCATION], "/scs_index.php");
    let (status, _, _) = send(&h.app, get("/subcategory.php?action=download&subcategory_id=10&record_item=overview:0&language_code=EN", &executive)).await;
    assert_eq!(status, StatusCode::FOUND);
    let admin = person(&h.pool, 1, "Administrator").await;
    let (_, _, json) = send(&h.app, form("/subcategory.php", &admin, &[("action", "sku_count"), ("subcategory_id", "10")], true)).await;
    assert_eq!(json, r#"{"sku_count":"2","trace":["json","json_sku_count"]}"#);
}

#[tokio::test]
async fn the_list_shows_a_category_by_sort_order_and_name() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let page = submit(&h, &admin, &[("action", "list"), ("report_category_id", "1"), ("report_status", ""), ("report_images", "")]).await;
    let old = page.find("Old Clamps").unwrap();
    let toggle = page.find("Toggle Clamps").unwrap();
    assert!(old < toggle, "both sort 0; names decide");
    assert!(page.contains("<tr style='background-color: #e9967a;'>"), "inactive rows are salmon");
    assert!(page.contains("<input type=checkbox name='delete11' id='delete11' value='1'"), "only inactive rows can be deleted");
    let page = submit(&h, &admin, &[("action", "list"), ("report_category_id", "")]).await;
    assert!(page.contains("Correct 1 error(s)"));
}

#[tokio::test]
async fn the_overview_editor_keeps_its_html_until_the_save() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let page = submit(&h, &admin, &[("action", "maintain"), ("record_id", "10"), ("report_category_id", "1")]).await;
    assert!(page.contains("value='Toggle Clamps'"));
    assert!(page.contains("Name: d.html<br>File Type: html<br>Mime: text/html"));
    let (_, _, json) = send(&h.app, form("/subcategory.php", &admin, &[("action", "html_load"), ("subcategory_id", "10"), ("record_item", "overview:0"), ("language_code", "EN")], true)).await;
    assert!(json.contains(r#""overview_html":"<p>Strong<\/p>""#), "{json}");
    let edit = [("action", "html_update"), ("subcategory_id", "10"), ("record_item", "overview:1"), ("language_code", "FR"), ("overview_name", "Conseils"), ("overview_html", "<p>Bon</p><script>x</script>")];
    let (_, _, json) = send(&h.app, form("/subcategory.php", &admin, &edit, true)).await;
    assert!(json.contains(r#""overview_message":"Update Item #2 (French)""#), "{json}");
    let fields = [
        ("action", "update"),
        ("record_id", "10"),
        ("category_id", "1"),
        ("bmFtZQlFTg", "Toggle Clamps"),
        ("ZGVzY3JpcHRpb24JRU4", "Fast clamps"),
        ("b3ZlcnZpZXcJbmFtZQlFTgkw", "Description"),
        ("b3ZlcnZpZXcJbmFtZQlGUgkx", "Conseils"),
        ("leadtime_option", "Call"),
        ("industry_1", "1"),
        ("status", "Active"),
        ("prj", "info=x/a.prj"),
        ("lookup", "1"),
    ];
    let page = submit(&h, &admin, &fields).await;
    assert!(page.contains("Record maintained"), "{page}");
    let html: String = sqlx::query_scalar("SELECT html_content FROM content WHERE record_id = 10 AND record_item = 'overview:1' AND language_code = 'FR'").fetch_one(&h.pool).await.unwrap();
    assert_eq!(html, "<p>Bon</p>", "script markup is dropped");
    let kept: String = sqlx::query_scalar("SELECT html_content FROM content WHERE id = 6").fetch_one(&h.pool).await.unwrap();
    assert_eq!(kept, "<p>Strong</p>");
    let page = submit(&h, &admin, &[("action", "update"), ("record_id", "10")]).await;
    assert!(!page.contains("Record maintained"), "the draft went with the save");
}

#[tokio::test]
async fn a_save_without_a_name_or_access_is_refused() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    submit(&h, &admin, &[("action", "maintain"), ("record_id", "0"), ("report_category_id", "1")]).await;
    let page = submit(&h, &admin, &[("action", "update"), ("record_id", "0"), ("report_category_id", "1"), ("leadtime_option", "Weeks")]).await;
    assert!(page.contains("No industry/portals selected<br>Correct 4 error(s)"), "{page}");
    let count: i64 = sqlx::query_scalar("SELECT count(*) FROM subcategory").fetch_one(&h.pool).await.unwrap();
    assert_eq!(count, 2);
}

#[tokio::test]
async fn a_form_post_needs_its_token_and_a_download_sends_the_stored_file() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let (status, _, _) = send(&h.app, form("/subcategory.php", &admin, &[("action", "delete"), ("record_id", "11")], false)).await;
    assert_eq!(status, StatusCode::FORBIDDEN);
    let update = [("action", "update"), ("record_id", "11"), ("category_id", "1")];
    let (status, _, page) = send(&h.app, form("/subcategory.php", &admin, &update, false)).await;
    assert_eq!(status, StatusCode::OK, "with no draft to save an update does nothing, as the PHP's did");
    assert!(page.contains("fn_action('list','','');"));
    submit(&h, &admin, &[("action", "maintain"), ("record_id", "11")]).await;
    let (status, _, _) = send(&h.app, form("/subcategory.php", &admin, &update, false)).await;
    assert_eq!(status, StatusCode::FORBIDDEN, "a draft to save needs the token");
    let (status, headers, body) = send(&h.app, get("/subcategory.php?action=download&subcategory_id=10&record_item=overview:0&language_code=EN", &admin)).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(headers[header::CONTENT_DISPOSITION], "attachment; filename=d.html");
    assert_eq!(body, "<p>Strong</p>");
    let page = submit(&h, &admin, &[("action", "delete"), ("record_id", "11")]).await;
    assert!(page.contains("Subcategory Old Clamps deleted"));
}
