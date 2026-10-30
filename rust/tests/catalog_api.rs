//! scscpq_api.php's actions through the router, on synthetic rows.

mod catalog_support;

use axum::{body::Body, http::Method};
use catalog_support::{harness, request, send, signed_in, Account};

fn xhr(form: &str, cookie: Option<&str>) -> axum::http::Request<Body> {
    request(Method::POST, "/scscpq_api.php", cookie, &[("x-requested-with", "XMLHttpRequest"), ("content-type", "application/x-www-form-urlencoded")], Body::from(form.to_string()))
}

#[tokio::test]
async fn a_configured_part_gets_its_family_price_and_buttons() {
    let h = harness().await;
    for sql in [
        "INSERT INTO category (id, name, active) VALUES (1, 'Pins', 1)",
        "INSERT INTO subcategory (id, category_id, name, status, leadtime_option) VALUES (7, 1, 'Ball Lock Pins', 'Active', 'In Stock')",
        "INSERT INTO currency (code, name, plural, exchange_rate, adder_pct, active) VALUES ('USD', 'US Dollar', 'US Dollars', 1, 0, 1)",
        r#"INSERT INTO inventory (id, sku, category_id, subcategory_id, active, leadtime_option, pricing) VALUES (1, 'HBLT-1', 1, 7, 1, 'Subcategory', 'a:1:{i:0;O:23:"inventory_pricing_class":2:{s:5:"price";d:10;s:9:"qty_break";i:1;}}')"#,
    ] {
        sqlx::query(sql).execute(&h.pool).await.unwrap();
    }
    let mut a = Account::customer(70);
    a.price_type = "base";
    a.ecommerce = true;
    let cookie = signed_in(&h.pool, &a).await;
    let (_, _, body) = send(&h.app, xhr("action=sku&data%5Blina%5D=hblt-1", Some(&cookie))).await;
    let v: serde_json::Value = serde_json::from_str(&body).unwrap();
    let html = v["html"].as_str().unwrap();
    assert!(html.starts_with("<div>Pins: Ball Lock Pins</div><br><div>HBLT-1</div><br><div><div>Price: $10.00</div><p>Typical lead time: In Stock</p></div>"), "{html}");
    assert!(html.contains("value='Add to Cart'"));
    assert_eq!(v["data"]["lina"], "hblt-1");
    let (_, _, body) = send(&h.app, xhr("action=sku&data%5Blina%5D=nope", Some(&cookie))).await;
    assert!(body.contains("Part number<br>NOPE"), "{body}");
}

#[tokio::test]
async fn the_profile_form_is_checked_then_saved_in_its_cookie() {
    let h = harness().await;
    sqlx::query("INSERT INTO registry (domain, module, field, item, data) VALUES ('', 'address', 'profile', 'email', 'required'), ('', 'address', 'profile', 'name', 'required')").execute(&h.pool).await.unwrap();
    sqlx::query("INSERT INTO cad (code, name, type, direct, active) VALUES ('STEP', 'STEP AP214', '3D', 0, 1)").execute(&h.pool).await.unwrap();
    let cookie = signed_in(&h.pool, &Account::customer(71)).await;
    let (_, headers, body) = send(&h.app, xhr("action=profile&profile_email=&cad_code=", Some(&cookie))).await;
    let v: serde_json::Value = serde_json::from_str(&body).unwrap();
    assert_eq!(v["html"]["profile_message"], "Correct 3 error(s)");
    assert_eq!(v["error"]["profile_email"], 1);
    assert!(headers.get("set-cookie").is_none());
    let (_, headers, body) = send(&h.app, xhr("action=profile&profile_email=buyer%40example.com&profile_name_first=Pat&profile_name_last=Lee&cad_code=STEP&profile_generate=0", Some(&cookie))).await;
    let v: serde_json::Value = serde_json::from_str(&body).unwrap();
    assert_eq!(v["html"]["profile_message"], "Profile Updated");
    assert_eq!(v["html"]["cad_name"], "STEP AP214");
    assert!(headers["set-cookie"].to_str().unwrap().starts_with("harcourt_profile="));
}

#[tokio::test]
async fn page_requests_name_the_missing_function() {
    let h = harness().await;
    let (_, _, body) = send(&h.app, request(Method::GET, "/scscpq_api.php?action=html", None, &[("referer", "https://www.harcourt.co/catalog/")], Body::empty())).await;
    assert_eq!(body, "function: html_html");
    let (status, headers, _) = send(&h.app, request(Method::GET, "/scscpq_api.php?action=html", None, &[], Body::empty())).await;
    assert_eq!((status.as_u16(), &headers["location"]), (302, &"/scs_index.php".parse().unwrap()), "a visit that begins here goes home");
}
