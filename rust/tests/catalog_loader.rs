//! scscpq_wp.php's loader JSON through the router, per persona.

mod catalog_support;

use axum::http::StatusCode;
use catalog_support::{get, harness, post_form, send, signed_in, Account};
use serde_json::Value;

const FORM: &str = "action=initial&page=catalog&document=&language=en&remember=0&url=https%3A%2F%2Fwww.harcourt.co%2Fcatalog%2F%3Faction%3Dsubcategory%26subcategory_id%3D7";

async fn load(h: &catalog_support::Harness, cookie: Option<&str>, form: &str) -> (StatusCode, String, Value) {
    let (status, _, body) = send(&h.app, post_form("/scscpq_wp.php", cookie, form)).await;
    let v = serde_json::from_str(&body).unwrap_or(Value::Null);
    (status, body, v)
}

#[tokio::test]
async fn anonymous_visitors_get_the_access_link_and_no_catalog_blocks() {
    let h = harness().await;
    let (status, body, v) = load(&h, None, FORM).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(v["scscpq_newuser"], 1);
    assert_eq!(v["scscpq_catalog"], 0);
    assert!(v["scscpq_search"].is_null() && v["scscpq_cart"].is_null());
    assert_eq!(v["scscpq_link"], "https://www.harcourt.co/catalog.php?action=subcategory&subcategory_id=7&scscpq_wp=1");
    assert_eq!(v["account_language"], "");
    assert_eq!(v["error"], "");
    assert!(v["token"].as_str().is_some_and(|t| !t.is_empty()));
    let keys: Vec<&str> = body.trim_start_matches('{').split(",\"").map(|k| k.trim_start_matches('"').split('"').next().unwrap()).filter(|k| k.starts_with("scscpq") || ["account_language", "meta", "token", "error", "trace"].contains(k)).collect();
    assert_eq!(keys.first(), Some(&"scscpq_newuser"));
    assert!(body.contains("\"scscpq_link\":\"https:\\/\\/www.harcourt.co\\/catalog.php?"), "slashes escaped as json_encode does: {body}");
    assert!(body.ends_with("\"trace\":[\"__construct\",\"initial\",\"token\",\"language\",\"newuser\",\"impersonate\",\"catalog\",\"search\",\"catalog\",\"cart\",\"catalog\",\"portal\",\"dashboard\",\"url\",\"document\"]}"), "{body}");
}

#[tokio::test]
async fn customers_get_the_search_box_and_cart_by_their_flags() {
    let h = harness().await;
    let mut a = Account::customer(50);
    a.ecommerce = true;
    a.language_code = "FR";
    let cookie = signed_in(&h.pool, &a).await;
    let (_, _, v) = load(&h, Some(&cookie), &format!("{FORM}&token=abc")).await;
    assert_eq!(v["scscpq_newuser"], 0);
    assert_eq!(v["scscpq_catalog"], 1);
    assert_eq!(v["scscpq_products"], 1);
    let search = v["scscpq_search"].as_str().unwrap();
    assert!(search.contains("url: \"/scs_ajax.php?\" + jQuery.param(query)") && search.contains("<input type=text name='scscpq_search_text' id='scscpq_search_text' size='20' placeholder='menu_search_placeholder'>"), "{search}");
    assert!(v["scscpq_cart"].as_str().unwrap().contains("<span id=menu_cart_size>0</span>"));
    assert_eq!(v["scscpq_dashboard"], 0);
    assert_eq!(v["account_language"], "FR");
    assert_eq!(v["token"], "abc");
}

#[tokio::test]
async fn executives_get_the_dashboard_and_french_pages_the_french_portal() {
    let h = harness().await;
    let mut a = Account::customer(51);
    a.role = "Executive";
    let cookie = signed_in(&h.pool, &a).await;
    let (_, _, v) = load(&h, Some(&cookie), &FORM.replace("language=en", "language=fr")).await;
    assert_eq!(v["scscpq_dashboard"], 1);
    assert!(v["scscpq_portal"].as_str().unwrap().contains("href=\"/portail-clients/?lang=fr\""));
}

#[tokio::test]
async fn other_methods_and_actions_answer_401() {
    let h = harness().await;
    let (status, _, body) = send(&h.app, get("/scscpq_wp.php", None)).await;
    assert_eq!(status, StatusCode::UNAUTHORIZED);
    let v: Value = serde_json::from_str(&body).unwrap();
    assert_eq!(v["error"], ": 102");
    assert!(v.get("token").is_none());
    let (status, _, v) = load(&h, None, "action=delete").await;
    assert_eq!(status, StatusCode::UNAUTHORIZED);
    assert_eq!(v["error"], "delete: 104");
}

#[tokio::test]
async fn a_flag_click_saves_the_page_language_to_the_account() {
    let h = harness().await;
    let cookie = signed_in(&h.pool, &Account::customer(51)).await;
    let (_, _, v) = load(&h, Some(&cookie), &FORM.replace("language=en&remember=0", "language=fr&remember=0")).await;
    assert_eq!(v["account_language"], "EN", "following a link changes nothing");
    let (_, _, v) = load(&h, Some(&cookie), &FORM.replace("language=en&remember=0", "language=fr&remember=1")).await;
    assert_eq!(v["account_language"], "FR");
    let saved: String = sqlx::query_scalar(r#"SELECT language_code FROM "user" WHERE id = 51"#).fetch_one(&h.pool).await.unwrap();
    assert_eq!(saved, "FR");
}
