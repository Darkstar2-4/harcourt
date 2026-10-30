//! scs_file.php and the other file routes, per persona.

mod catalog_support;

use axum::http::StatusCode;
use catalog_support::{get, harness, send, send_bytes, signed_in, Account};
use hxweb::catalog::html::base64_fields;
use sqlx::SqlitePool;

async fn seed(pool: &SqlitePool) {
    for sql in [
        "INSERT INTO category (id, name, active) VALUES (1, 'Pins', 1)",
        "INSERT INTO subcategory (id, category_id, name, status) VALUES (7, 1, 'Ball Lock Pins', 'Active'), (8, 1, 'Secret Pins', 'Pending')",
        "INSERT INTO content (id, record_type, record_id, record_item, language_code, name, filetype, meta, content) VALUES
            (100, 'subcategory', 7, 'download:1', 'EN', 'Datasheet', 'pdf', '{\"name\":\"datasheet.pdf\",\"mime\":\"application/pdf\"}', X'255044462D'),
            (101, 'subcategory', 8, 'download:1', 'EN', 'Draft', 'pdf', '{\"name\":\"draft.pdf\",\"mime\":\"application/pdf\"}', X'255044462D'),
            (102, 'document', 157, '', 'EN', 'Note', 'text', '{\"name\":\"note.txt\",\"mime\":\"text/plain\"}', X'68656C6C6F')",
    ] {
        sqlx::query(sql).execute(pool).await.unwrap();
    }
}

fn file(id: i64) -> String {
    format!("/scs_file.php?content_id={}", base64_fields(&[&id.to_string(), "0"]))
}

#[tokio::test]
async fn family_files_go_to_viewers_who_may_see_the_family() {
    let h = harness().await;
    seed(&h.pool).await;
    let cookie = signed_in(&h.pool, &Account::customer(60)).await;
    let (status, headers, body) = send_bytes(&h.app, get(&file(100), Some(&cookie))).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(headers["content-type"], "application/pdf");
    assert_eq!(headers["content-disposition"], "attachment; filename=datasheet.pdf");
    assert_eq!(body, b"%PDF-");
    let (status, _, _) = send(&h.app, get(&file(100), None)).await;
    assert_eq!(status, StatusCode::NOT_FOUND, "anonymous visitors may not browse the catalog");
    let (status, _, _) = send(&h.app, get(&file(101), Some(&cookie))).await;
    assert_eq!(status, StatusCode::NOT_FOUND, "a Pending family's files are for administrators");
    sqlx::query("INSERT INTO user_category (user_id, category_id) VALUES (60, 1)").execute(&h.pool).await.unwrap();
    let (status, _, _) = send(&h.app, get(&file(100), Some(&cookie))).await;
    assert_eq!(status, StatusCode::NOT_FOUND, "a blocked category's files are closed too");
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
    let (status, _, _) = send(&h.app, get("/scs_file.php?content_id=junk", None)).await;
    assert_eq!(status, StatusCode::NOT_FOUND);
}

#[tokio::test]
async fn named_files_outside_images_are_refused() {
    let h = harness().await;
    for path in ["/scs_file.php?code=../../etc/passwd", "/scs_file.php?code=/wp-config.php", "/scs_file.php?filename=wp-config.php"] {
        let (status, _, body) = send(&h.app, get(path, None)).await;
        assert_eq!(status, StatusCode::NOT_FOUND, "{path}");
        assert_eq!(body, "Content missing");
    }
}

#[tokio::test]
async fn the_portal_page_and_the_old_home() {
    let h = harness().await;
    let (status, _, body) = send(&h.app, get("/customer_portal.php?scscpq_wp=1", None)).await;
    assert_eq!(status, StatusCode::OK);
    assert!(body.contains("<title>Untitled Page</title>") && body.contains("<iframe src='https://portal.harcourt.co'"));
    let (status, headers, _) = send(&h.app, get("/scs_index.php", None)).await;
    assert_eq!(status, StatusCode::PERMANENT_REDIRECT);
    assert_eq!(headers["location"], "/");
}
