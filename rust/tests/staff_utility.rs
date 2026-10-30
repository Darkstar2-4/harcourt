//! The import/export pages (staff/map.rs, staff/utility.rs) through the router, against an in-memory database with
//! synthetic rows.

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
    exec(&pool, "INSERT INTO category (id, name, active, sort_order) VALUES (1, 'Clamps', 1, 1), (2, 'Pins', 1, 2)").await;
    exec(&pool, "INSERT INTO subcategory (id, category_id, name, status, leadtime_option) VALUES (10, 1, 'Toggle Clamps', 'Active', 'Call'), (11, 2, 'Ball Lock Pins', 'Pending', 'In Stock')").await;
    exec(
        &pool,
        "INSERT INTO inventory (id, sku, category_id, subcategory_id, sort_order, active, leadtime_option, new_date, pricing, parameter0, nparameter0)
         VALUES (1, 'TC-100', 1, 10, 2, 1, 'Subcategory', '2024-03-05', 'a:2:{i:0;O:23:\"inventory_pricing_class\":2:{s:5:\"price\";d:12.5;s:9:\"qty_break\";i:0;}i:1;O:23:\"inventory_pricing_class\":2:{s:5:\"price\";d:11;s:9:\"qty_break\";d:10;}}', 'Steel', 1.5),
                (2, 'TC-200', 1, 10, 1, 0, 'Weeks', '0000-00-00', '', 'Brass', 0),
                (3, 'BL-1', 2, 11, 0, 1, 'Call', '0000-00-00', 'a:0:{}', '', 0)",
    )
    .await;
    Harness { app: hxweb::routes::staff::router(auth), pool }
}

async fn exec(pool: &SqlitePool, sql: &str) {
    sqlx::query(sql).execute(pool).await.unwrap();
}

async fn person(pool: &SqlitePool, id: i64, role: &str) -> String {
    sqlx::query(r#"INSERT INTO "user" (id, ip, status, "type", company_name, country_code) VALUES (?1, ?2, 'Active', ?3, ?4, 'US')"#)
        .bind(id)
        .bind(format!("192.0.2.{id}"))
        .bind(role)
        .bind(format!("Test company {id}"))
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

async fn token(h: &Harness, path: &str, cookie: &str) -> String {
    let (_, _, page) = send(&h.app, get(path, cookie)).await;
    let marker = "name='csrf' id='csrf' value='";
    let start = page.find(marker).expect("csrf field") + marker.len();
    page[start..].split('\'').next().unwrap().to_string()
}

async fn submit(h: &Harness, path: &str, cookie: &str, pairs: &[(&str, &str)]) -> (StatusCode, HeaderMap, String) {
    let csrf = token(h, path, cookie).await;
    let body: String = form_urlencoded::Serializer::new(String::new()).extend_pairs(pairs).append_pair("csrf", &csrf).finish();
    let req = Request::builder()
        .method(Method::POST)
        .uri(path)
        .header(header::COOKIE, cookie)
        .header(header::CONTENT_TYPE, "application/x-www-form-urlencoded")
        .body(Body::from(body))
        .unwrap();
    send(&h.app, req).await
}

/// A multipart POST with one file in the `upload` field.
async fn upload(h: &Harness, path: &str, cookie: &str, pairs: &[(&str, &str)], file_name: &str, content: &str) -> (StatusCode, HeaderMap, String) {
    let csrf = token(h, path, cookie).await;
    let mut body = String::new();
    for (k, v) in pairs.iter().copied().chain([("csrf", csrf.as_str())]) {
        body.push_str(&format!("--XB\r\nContent-Disposition: form-data; name=\"{k}\"\r\n\r\n{v}\r\n"));
    }
    body.push_str(&format!("--XB\r\nContent-Disposition: form-data; name=\"upload\"; filename=\"{file_name}\"\r\nContent-Type: text/plain\r\n\r\n{content}\r\n--XB--\r\n"));
    let req = Request::builder()
        .method(Method::POST)
        .uri(path)
        .header(header::COOKIE, cookie)
        .header(header::CONTENT_TYPE, "multipart/form-data; boundary=XB")
        .body(Body::from(body))
        .unwrap();
    send(&h.app, req).await
}

#[tokio::test]
async fn the_utilities_are_for_administrators_and_the_email_export_for_executives() {
    let h = harness().await;
    let executive = person(&h.pool, 2, "Executive").await;
    let admin = person(&h.pool, 1, "Administrator").await;
    for path in ["/inventory_utility.php", "/inventory_no_price.php", "/inventory_price.php", "/subcategory_utility.php", "/inventory_xref.php", "/user_utility.php"] {
        let (status, headers, _) = send(&h.app, get(path, &executive)).await;
        assert_eq!(status, StatusCode::FOUND, "{path}");
        assert_eq!(headers[header::LOCATION], "/scs_index.php");
        let (status, _, page) = send(&h.app, get(path, &admin)).await;
        assert_eq!(status, StatusCode::OK, "{path}");
        assert!(page.contains("<input type=hidden name='action' id='action'>"), "{path}");
    }
    let (status, _, page) = send(&h.app, get("/user_email.php", &executive)).await;
    assert_eq!(status, StatusCode::OK);
    assert!(page.contains("<p class='message left'>Email Export (12/03/2024)</p>"));
    assert!(page.contains("<input type=hidden name='action' id='action' value='export'>"));
}

#[tokio::test]
async fn the_field_list_counts_what_a_download_takes() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let (_, _, page) = submit(&h, "/inventory_utility.php", &admin, &[("action", "prelist"), ("function", "download"), ("subcategory_id", "10"), ("file_type", ".txt")]).await;
    assert!(page.contains("<input type=hidden name='title' class='' id='title' value='Clamps: Toggle Clamps'>"), "{page}");
    assert!(page.contains("value='Download 2 records'"));
    assert!(page.contains("<td>Group: pricing</td>"));
    assert!(page.contains("<td class=center rowspan=20>"), "base price plus nine breaks and prices, and the group line");
    let (_, _, page) = submit(&h, "/inventory_no_price.php", &admin, &[("action", "prelist"), ("function", "download"), ("subcategory_id", ""), ("file_type", ".txt")]).await;
    assert!(page.contains("value='Download 2 records'"), "TC-200 and BL-1 have no breaks");
}

#[tokio::test]
async fn a_download_writes_the_chosen_columns_as_text() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let pairs = [("action", "update"), ("function", "download"), ("subcategory_id", "10"), ("file_type", ".csv"), ("option_selected", "1"), ("map_active", "1"), ("map_pricing", "1"), ("map_new_date", "1")];
    let (status, headers, body) = submit(&h, "/inventory_utility.php", &admin, &pairs).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(headers[header::CONTENT_DISPOSITION], "attachment; filename=inventory_10.csv");
    let mut lines = body.trim_start_matches('\u{feff}').lines();
    assert_eq!(lines.next().unwrap(), "\"Harcourt SKU\",\"New Date\",Active,\"Base Price\",\"Qty Break #1\",\"Price #1\",\"Qty Break #2\",\"Price #2\",\"Qty Break #3\",\"Price #3\",\"Qty Break #4\",\"Price #4\",\"Qty Break #5\",\"Price #5\",\"Qty Break #6\",\"Price #6\",\"Qty Break #7\",\"Price #7\",\"Qty Break #8\",\"Price #8\",\"Qty Break #9\",\"Price #9\"");
    assert_eq!(lines.next().unwrap(), "TC-200,,N,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0", "sort order 1 comes first");
    assert_eq!(lines.next().unwrap(), "TC-100,03/05/2024,Y,12.50,10,11.00,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0");
    let logged: i64 = sqlx::query_scalar("SELECT count(*) FROM log WHERE type = 'Export Table' AND subtype = 'inventory'").fetch_one(&h.pool).await.unwrap();
    assert_eq!(logged, 1);
}

#[tokio::test]
async fn an_import_reviews_first_and_updates_from_the_kept_rows() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let file = "Harcourt SKU\tSort Order\tActive\ntc-100\t7\tN\n\nNO-SUCH-SKU\t1\tY\n";
    let (_, _, page) = upload(&h, "/inventory_utility.php", &admin, &[("action", "prelist"), ("function", "upload"), ("subcategory_id", "")], "parts.txt", file).await;
    assert!(page.contains("Upload file: <b>parts.txt</b><br>Import update -> review"), "{page}");
    assert!(page.contains("Records in table: 4\n<br>Records accepted for update: 2\n<br>Empty & header records: 2"));
    assert!(page.contains("value='Continue (update only)'"));
    let (_, _, page) = submit(&h, "/inventory_utility.php", &admin, &[("action", "update"), ("function", "upload"), ("external_file", "parts.txt")]).await;
    assert!(page.contains("Update complete / Errors detected"), "{page}");
    assert!(page.contains("Fields: Harcourt SKU, Sort Order, Active<br>Updated records: 1<br>Empty records: 1<br>Rejected records: 1"), "{page}");
    assert!(page.contains("<td>Invalid SKU: NO-SUCH-SKU</td>"));
    let (sort, active): (i64, i64) = sqlx::query_as("SELECT sort_order, active FROM inventory WHERE sku = 'TC-100'").fetch_one(&h.pool).await.unwrap();
    assert_eq!((sort, active), (7, 0));
    let (_, _, page) = submit(&h, "/inventory_utility.php", &admin, &[("action", "update"), ("function", "upload"), ("external_file", "parts.txt")]).await;
    assert!(page.contains("Internal file missing"), "an import runs once");
}

#[tokio::test]
async fn a_review_saves_nothing_and_a_rejected_line_rolls_back_a_subcategory_update() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let file = "Subcategory ID\tLeadtime Option\tLeadtime Weeks\n10\tweeks\t4\n11\tsoon\t\n";
    upload(&h, "/subcategory_utility.php", &admin, &[("action", "prelist"), ("function", "upload"), ("category_id", "")], "lead.txt", file).await;
    let (_, _, page) = submit(&h, "/subcategory_utility.php", &admin, &[("action", "update"), ("function", "upload"), ("external_file", "lead.txt")]).await;
    assert!(page.contains("Update complete / Errors detected (Transaction Rolled Back)"), "{page}");
    assert!(page.contains("<td>Allowed values: In Stock, Weeks, Call</td>"));
    let option: String = sqlx::query_scalar("SELECT leadtime_option FROM subcategory WHERE id = 10").fetch_one(&h.pool).await.unwrap();
    assert_eq!(option, "Call");
    upload(&h, "/subcategory_utility.php", &admin, &[("action", "prelist"), ("function", "review")], "lead.txt", "Subcategory ID\tLeadtime Option\tLeadtime Weeks\n10\tWeeks\t4\n").await;
    let (_, _, page) = submit(&h, "/subcategory_utility.php", &admin, &[("action", "update"), ("function", "review"), ("external_file", "lead.txt")]).await;
    assert!(page.contains("Review complete"));
    let option: String = sqlx::query_scalar("SELECT leadtime_option FROM subcategory WHERE id = 10").fetch_one(&h.pool).await.unwrap();
    assert_eq!(option, "Call");
}

#[tokio::test]
async fn an_account_import_gives_no_role_above_the_importers() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    exec(&h.pool, "INSERT INTO currency (code, name, exchange_rate) VALUES ('USD', 'US Dollar', 1)").await;
    exec(&h.pool, "INSERT INTO language (code, name, local_name, priority) VALUES ('EN', 'English', 'English', 1)").await;
    exec(&h.pool, "INSERT INTO country (country, state, name) VALUES ('US', '', 'United States')").await;
    let file = "id\tip\ttype\tCompany Name\n1\t192.0.2.1\tSuper User\tTest company 1\n";
    upload(&h, "/user_utility.php", &admin, &[("action", "prelist"), ("function", "upload")], "users.txt", file).await;
    let (_, _, page) = submit(&h, "/user_utility.php", &admin, &[("action", "update"), ("function", "upload"), ("external_file", "users.txt")]).await;
    assert!(page.contains("<td>Invalid: Super User</td>"), "{page}");
    let role: String = sqlx::query_scalar(r#"SELECT "type" FROM "user" WHERE id = 1"#).fetch_one(&h.pool).await.unwrap();
    assert_eq!(role, "Administrator");
}

#[tokio::test]
async fn a_post_without_the_form_token_changes_nothing() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let body = "action=update&function=download&subcategory_id=10";
    let req = Request::builder()
        .method(Method::POST)
        .uri("/inventory_utility.php")
        .header(header::COOKIE, &admin)
        .header(header::CONTENT_TYPE, "application/x-www-form-urlencoded")
        .body(Body::from(body))
        .unwrap();
    let (status, _, _) = send(&h.app, req).await;
    assert_eq!(status, StatusCode::FORBIDDEN);
    let logged: i64 = sqlx::query_scalar("SELECT count(*) FROM log").fetch_one(&h.pool).await.unwrap();
    assert_eq!(logged, 0);
}
