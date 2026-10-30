//! The search box's suggestions, from an index the program builds from the search tables.

mod catalog_support;

use axum::{body::Body, http::{Method, StatusCode}};
use catalog_support::{harness, request, send, signed_in, Account};
use sqlx::SqlitePool;

async fn seed(pool: &SqlitePool) {
    for sql in [
        "INSERT INTO category (id, name, sort_order, active) VALUES (1, 'Pins', 1, 1), (2, 'Secret', 2, 1)",
        "INSERT INTO subcategory (id, category_id, name, status) VALUES (7, 1, 'Ball Lock Pins', 'Active'), (8, 2, 'Hidden Pins', 'Active')",
        "INSERT INTO content (record_type, record_id, record_item, language_code, name) VALUES ('subcategory', 7, '', 'EN', 'Ball Lock Pins'), ('subcategory', 7, '', 'FR', 'Goupilles à billes')",
        "INSERT INTO inventory (id, sku, category_id, subcategory_id, active) VALUES (1, 'HBLT-1', 1, 7, 1), (2, 'HBLT-2', 1, 7, 1), (3, 'HX-9', 2, 8, 1)",
        "INSERT INTO search (id, language_code, active, table_name, code, category_id, subcategory_id, inventory_id, ranking) VALUES
            (10, 'EN', 1, 'subcategory', 'Ball Lock Pins', 1, 7, 0, 100), (11, 'FR', 1, 'subcategory', 'Goupilles à billes', 1, 7, 0, 100),
            (20, '', 1, 'inventory', 'HBLT-1', 1, 7, 1, 2000001), (21, '', 1, 'inventory', 'HBLT-2', 1, 7, 2, 2000002),
            (22, '', 1, 'inventory', 'HX-9', 2, 8, 3, 2000003)",
    ] {
        sqlx::query(sql).execute(pool).await.unwrap();
    }
}

fn suggest(term: &str, cookie: Option<&str>, page: &str) -> axum::http::Request<Body> {
    let referer = format!("https://www.harcourt.co{page}");
    request(Method::GET, &format!("/catalog-api/search?limit=20&term={term}"), cookie, &[("referer", referer.as_str())], Body::empty())
}

#[tokio::test]
async fn customers_get_suggestions_scoped_to_what_they_may_see() {
    let h = harness().await;
    seed(&h.pool).await;
    let cookie = signed_in(&h.pool, &Account::customer(40)).await;
    let (status, _, body) = send(&h.app, suggest("hblt", Some(&cookie), "/catalog/")).await;
    assert_eq!(status, StatusCode::OK);
    let v: serde_json::Value = serde_json::from_str(&body).unwrap();
    let labels: Vec<&str> = v["items"].as_array().unwrap().iter().map(|i| i["label"].as_str().unwrap()).collect();
    assert!(labels.contains(&"HBLT-1") && labels.contains(&"HBLT-2"), "{body}");
    assert_eq!(v["items"][0]["tier"], "prefix");

    sqlx::query("INSERT INTO user_category (user_id, category_id) VALUES (40, 2)").execute(&h.pool).await.unwrap();
    let (_, _, body) = send(&h.app, suggest("hx-9", Some(&cookie), "/catalog/")).await;
    assert!(!body.contains("\"label\":\"HX-9\""), "a blocked category is not suggested: {body}");

    let (_, _, body) = send(&h.app, suggest("goupilles", Some(&cookie), "/catalogue/?lang=fr")).await;
    assert!(body.contains("Goupilles à billes"), "{body}");
}

#[tokio::test]
async fn visitors_who_may_not_browse_get_nothing() {
    let h = harness().await;
    seed(&h.pool).await;
    let (status, _, body) = send(&h.app, suggest("hblt", None, "/catalog/")).await;
    assert_eq!(status, StatusCode::FORBIDDEN);
    assert!(!body.contains("HBLT"));
    let mut pending = Account::customer(41);
    pending.status = "Pending";
    let cookie = signed_in(&h.pool, &pending).await;
    let (status, _, _) = send(&h.app, suggest("hblt", Some(&cookie), "/catalog/")).await;
    assert_eq!(status, StatusCode::FORBIDDEN);
}

#[tokio::test]
async fn an_entered_term_the_keyword_search_misses_opens_the_best_suggestion() {
    let h = harness().await;
    seed(&h.pool).await;
    sqlx::query("INSERT INTO language (code, name, active) VALUES ('EN', 'English', 1)").execute(&h.pool).await.unwrap();
    let cookie = signed_in(&h.pool, &Account::customer(42)).await;
    let req = request(Method::GET, "/catalog.php?scscpq_wp=1&search_source=search&search_id=0&search_code=hblt%201", Some(&cookie), &[("x-requested-with", "XMLHttpRequest")], Body::empty());
    let (_, _, body) = send(&h.app, req).await;
    assert!(body.contains("Part number: HBLT-1"), "{body}");
}

#[tokio::test]
async fn staff_autocompletes_answer_staff_only_with_the_fields_their_pages_read() {
    let h = harness().await;
    seed(&h.pool).await;
    let customer = signed_in(&h.pool, &Account::customer(43)).await;
    let mut staff = Account::customer(44);
    staff.role = "Internal";
    staff.company_name = Some("Acme Tooling".into());
    let staff = signed_in(&h.pool, &staff).await;
    let get = |path: &str, cookie: &str| request(Method::GET, path, Some(cookie), &[], Body::empty());
    let (status, _, _) = send(&h.app, get("/catalog-api/companies?term=acme", &customer)).await;
    assert_eq!(status, StatusCode::FORBIDDEN);
    let (status, _, body) = send(&h.app, get("/scs_ajax.php?table=user&source=company_name&term=acme", &staff)).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(body, r#"[{"value":"Acme Tooling"}]"#);
    let (_, _, body) = send(&h.app, get("/scs_ajax.php?table=inventory&detail=sku&term=HBLT", &staff)).await;
    assert!(body.starts_with(r#"[{"category_name":"Pins","category_subcategory_id":"1:7","label":"HBLT-1","subcategory_name":"Ball Lock Pins"}"#), "{body}");
    let (status, _, _) = send(&h.app, get("/scs_ajax.php?table=remote&term=x", &staff)).await;
    assert_eq!(status, StatusCode::NOT_FOUND);
    let (status, _, body) = send(&h.app, request(Method::GET, "/scs_ajax.php?table=search&limit=20&term=hblt", Some(&customer), &[("referer", "https://www.harcourt.co/catalog/")], Body::empty())).await;
    assert_eq!(status, StatusCode::OK);
    assert!(body.contains("HBLT-1"));
}
