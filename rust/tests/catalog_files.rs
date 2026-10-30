//! scs_file.php and the other file routes, per persona.

mod catalog_support;

use axum::{body::Body, http::Method, http::StatusCode};
use catalog_support::{get, harness, request, send, send_bytes, signed_in, Account};
use hxweb::catalog::html::base64_fields;
use sqlx::SqlitePool;

async fn seed(pool: &SqlitePool) {
    for sql in [
        "INSERT INTO category (id, name, active) VALUES (1, 'Pins', 1)",
        "INSERT INTO subcategory (id, category_id, name, status) VALUES (7, 1, 'Ball Lock Pins', 'Active'), (8, 1, 'Secret Pins', 'Pending')",
        "INSERT INTO content (id, record_type, record_id, record_item, language_code, name, filetype, meta, content) VALUES
            (100, 'subcategory', 7, 'download:1', 'EN', 'Datasheet', 'pdf', '{\"name\":\"data sheet (v2).pdf\",\"mime\":\"application/pdf\"}', X'255044462D'),
            (101, 'subcategory', 8, 'download:1', 'EN', 'Draft', 'pdf', '{\"name\":\"draft.pdf\",\"mime\":\"application/pdf\"}', X'255044462D'),
            (102, 'document', 157, '', 'EN', 'Note', 'text', '{\"name\":\"note.txt\",\"mime\":\"text/plain\"}', X'68656C6C6F')",
    ] {
        sqlx::query(sql).execute(pool).await.unwrap();
    }
}

fn file(id: i64) -> String {
    format!("/scs_file.php?content_id={}", base64_fields(&[&id.to_string(), "0"]))
}

fn missing(id: i64) -> String {
    format!("Content missing: {}", base64_fields(&[&id.to_string(), "0"]))
}

#[tokio::test]
async fn family_files_go_to_viewers_who_may_see_the_family() {
    let h = harness().await;
    seed(&h.pool).await;
    let cookie = signed_in(&h.pool, &Account::customer(60)).await;
    let (status, headers, body) = send_bytes(&h.app, get(&file(100), Some(&cookie))).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(headers["content-type"], "application/pdf");
    assert_eq!(headers["content-disposition"], "attachment; filename=data sheet (v2).pdf");
    assert_eq!(body, b"%PDF-");
    let (status, headers, body) = send(&h.app, get(&file(100), None)).await;
    assert_eq!((status, body), (StatusCode::OK, missing(100)), "anonymous visitors may not browse the catalog");
    assert_eq!(headers["content-type"], "text/html; charset=UTF-8");
    let (_, _, body) = send(&h.app, get(&file(101), Some(&cookie))).await;
    assert_eq!(body, missing(101), "a Pending family's files are for administrators");
    sqlx::query("INSERT INTO user_category (user_id, category_id) VALUES (60, 1)").execute(&h.pool).await.unwrap();
    let (_, _, body) = send(&h.app, get(&file(100), Some(&cookie))).await;
    assert_eq!(body, missing(100), "a blocked category's files are closed too");
}

#[tokio::test]
async fn documents_are_public_and_text_shows_inline() {
    let h = harness().await;
    seed(&h.pool).await;
    let (status, headers, body) = send(&h.app, get(&file(102), None)).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(headers["content-type"], "text/plain");
    assert!(headers.get("content-disposition").is_none());
    assert_eq!(body, "hello");
    let (status, _, body) = send(&h.app, get(&format!("{}&override=1", file(102)), None)).await;
    assert_eq!((status, body.as_str()), (StatusCode::OK, "hello"));
    let (status, _, body) = send(&h.app, get("/scs_file.php?content_id=%3Cb%3E", None)).await;
    assert_eq!((status, body.as_str()), (StatusCode::OK, "Content missing: &lt;b&gt;"));
}

#[tokio::test]
async fn named_files_are_never_served() {
    let h = harness().await;
    for path in ["/scs_file.php", "/scs_file.php?code=/scs_images/logo.png", "/scs_file.php?code=../../etc/passwd", "/scs_file.php?filename=wp-config.php"] {
        let (status, _, body) = send(&h.app, get(path, None)).await;
        assert_eq!((status, body.as_str()), (StatusCode::INTERNAL_SERVER_ERROR, ""), "{path}");
    }
    let (status, _, body) = send(&h.app, get("/scs_file.php?watermark=1&code=/scs_images/logo.png", None)).await;
    assert_eq!((status, body.as_str()), (StatusCode::OK, ""));
}

fn from_site(path: &str) -> axum::http::Request<Body> {
    request(Method::GET, path, None, &[("referer", "https://www.harcourt.co/catalog/")], Body::empty())
}

#[tokio::test]
async fn the_portal_page_and_the_old_home() {
    let h = harness().await;
    let (status, headers, _) = send(&h.app, get("/customer_portal.php?scscpq_wp=1", None)).await;
    assert_eq!((status, &headers["location"]), (StatusCode::FOUND, &"/".parse().unwrap()), "a visit that begins here goes home");
    let (status, _, body) = send(&h.app, from_site("/customer_portal.php?scscpq_wp=1")).await;
    assert_eq!(status, StatusCode::OK);
    assert!(body.contains("<title>Untitled Page</title>\n<meta name=\"description\" content=\"Untitled Page\">"), "{body}");
    assert!(body.contains("<iframe src='https://portal.harcourt.co'") && !body.contains("scs_menu"));
    for path in ["/scs_index.php", "/beta.php"] {
        let (status, _, body) = send(&h.app, from_site(path)).await;
        assert_eq!(status, StatusCode::OK, "{path}");
        assert!(body.contains("<title>Harcourt</title>\n<meta") && body.contains("noimageindex\">\n<meta name=\"description\" content=\"Harcourt\">"), "{body}");
        assert!(body.contains("<div id='scs_menu' >") && body.contains("<div id=scscpq_page></div></div> <!-- #scs_content -->"));
    }
    let (status, headers, _) = send(&h.app, get("/beta.php", None)).await;
    assert_eq!((status, &headers["location"]), (StatusCode::FOUND, &"/scs_index.php".parse().unwrap()));
    let (status, _, _) = send(&h.app, get("/scs_index.php", None)).await;
    assert_eq!(status, StatusCode::OK, "the home page is where an anonymous visit lands");
}
