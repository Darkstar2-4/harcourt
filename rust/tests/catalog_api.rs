//! scscpq_api.php's actions through the router, on synthetic rows.

mod catalog_support;

use axum::{body::Body, http::Method};
use catalog_support::{first_get, harness, request, send, signed_in, Account};

fn xhr(form: &str, cookie: Option<&str>) -> axum::http::Request<Body> {
    request(Method::POST, "/scscpq_api.php", cookie, &[("x-requested-with", "XMLHttpRequest"), ("content-type", "application/x-www-form-urlencoded")], Body::from(form.to_string()))
}

#[tokio::test]
async fn a_configured_part_gets_its_family_price_and_buttons() {
    let h = harness().await;
    for sql in [
        "INSERT INTO category (id, name, active) VALUES (1, 'Pins à billes', 1)",
        "INSERT INTO subcategory (id, category_id, name, status, leadtime_option) VALUES (7, 1, 'Ball Lock Pins', 'Active', 'In Stock')",
        "INSERT INTO currency (code, name, plural, exchange_rate, adder_pct, active) VALUES ('USD', 'US Dollar', 'US Dollars', 1, 0, 1)",
        r#"INSERT INTO inventory (id, sku, category_id, subcategory_id, active, leadtime_option, pricing) VALUES (1, 'HBLT-1', 1, 7, 1, 'Subcategory', '[{"price":10.0,"qty_break":1}]')"#,
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
    assert!(body.contains(r#"<div>Pins à billes: Ball Lock Pins<\/div>"#), "{body}");
    assert!(html.starts_with("<div>Pins à billes: Ball Lock Pins</div><br><div>HBLT-1</div><br><div><div>Price: $10.00</div><p>Typical lead time: In Stock</p></div>"), "{html}");
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
    assert_eq!(v["html"]["profile_message"], "Correct 4 error(s)", "email, first and last name, CAD format");
    assert_eq!(v["error"]["profile_email"], 1);
    assert_eq!(v["error"]["cad_code_error"], 1);
    let profile_cookie = |h: &axum::http::HeaderMap| h.get_all("set-cookie").iter().any(|c| c.to_str().unwrap().starts_with("harcourt_profile="));
    assert!(!profile_cookie(&headers));
    let (_, headers, body) = send(&h.app, xhr("action=profile&profile_email=buyer%40example.com&profile_name_first=Pat&profile_name_last=Lee&cad_code=STEP&profile_generate=0", Some(&cookie))).await;
    let v: serde_json::Value = serde_json::from_str(&body).unwrap();
    assert_eq!(v["html"]["profile_message"], "Profile Updated");
    assert_eq!(v["html"]["cad_name"], "STEP AP214");
    assert!(profile_cookie(&headers));
}

#[tokio::test]
async fn a_saved_profile_is_what_the_next_request_reads() {
    let h = harness().await;
    sqlx::query("INSERT INTO registry (domain, module, field, item, data) VALUES ('', 'address', 'profile', 'email', 'required'), ('', 'address', 'profile', 'name', 'required'), ('', 'address', 'profile', 'address', 'required'), ('', 'address', 'profile', 'country_code', 'required')").execute(&h.pool).await.unwrap();
    sqlx::query("INSERT INTO cad (code, name, type, direct, active) VALUES ('STEP', 'STEP AP214', '3D', 0, 1)").execute(&h.pool).await.unwrap();
    let mut a = Account::customer(72);
    a.cad = true;
    let cookie = signed_in(&h.pool, &a).await;
    let gated = |body: &str| body.contains("<div class=profile_box>");
    let (_, _, body) = send(&h.app, request(Method::GET, "/catalog.php?scscpq_wp=1&lang=en", Some(&cookie), &[("x-requested-with", "XMLHttpRequest")], Body::empty())).await;
    assert!(gated(&body), "an account without the address the profile requires is asked for it");
    let quick_cad = || xhr("action=quick_cad&sku=HBLT-1", Some(&cookie));
    let (_, _, body) = send(&h.app, quick_cad()).await;
    assert!(body.contains("Invalid CAD code"), "{body}");
    let form = "action=profile&profile_email=Buyer%40Example.com&profile_name_first=Pat&profile_name_last=Lee&profile_address=1+Main+St&profile_country_code=US&cad_code=STEP&profile_generate=1";
    let (_, _, body) = send(&h.app, xhr(&form.replace("profile_country_code=US", "profile_country_code="), Some(&cookie))).await;
    let v: serde_json::Value = serde_json::from_str(&body).unwrap();
    assert_eq!((v["html"]["profile_message"].as_str(), v["error"]["profile_country_code"].as_i64()), (Some("Correct 1 error(s)"), Some(1)), "{body}");
    let country: Option<String> = sqlx::query_scalar(r#"SELECT country_code FROM "user" WHERE id = 72"#).fetch_one(&h.pool).await.unwrap();
    assert_eq!(country.as_deref(), Some("US"));
    let (_, headers, body) = send(&h.app, xhr(form, Some(&cookie))).await;
    let v: serde_json::Value = serde_json::from_str(&body).unwrap();
    assert_eq!(v["html"]["profile_message"], "Profile Updated", "{body}");
    assert_eq!(v["error"]["profile_address"], 0);
    assert_eq!(v["html"]["quick_cad_status"], "CAD Format: STEP AP214. CAD emailed to buyer@example.com when complete. Click PROFILE to change.");
    assert!(headers.get_all("set-cookie").iter().any(|c| c.to_str().unwrap().starts_with("harcourt=buyer%40example.com;")));
    let address: Option<String> = sqlx::query_scalar(r#"SELECT address FROM "user" WHERE id = 72"#).fetch_one(&h.pool).await.unwrap();
    assert_eq!(address.as_deref(), Some("1 Main St"));
    let (_, _, body) = send(&h.app, request(Method::GET, "/catalog.php?scscpq_wp=1&lang=en", Some(&cookie), &[("x-requested-with", "XMLHttpRequest")], Body::empty())).await;
    assert!(!gated(&body), "{body}");
    let (_, _, body) = send(&h.app, quick_cad()).await;
    assert!(!body.contains("Invalid CAD code"), "the saved format is the one quick CAD uses: {body}");
}

#[tokio::test]
async fn page_requests_name_the_missing_function() {
    let h = harness().await;
    let (_, _, body) = send(&h.app, request(Method::GET, "/scscpq_api.php?action=html", None, &[("referer", "https://www.harcourt.co/catalog/")], Body::empty())).await;
    assert_eq!(body, "function: html_html");
    let (status, headers, _) = send(&h.app, first_get("/scscpq_api.php?action=html")).await;
    assert_eq!((status.as_u16(), &headers["location"]), (302, &"/scs_index.php".parse().unwrap()), "a visit that begins here goes home");
}

#[tokio::test]
async fn a_cad_download_without_a_link_says_so() {
    let h = harness().await;
    let mut a = Account::customer(72);
    a.cad = true;
    let cookie = signed_in(&h.pool, &a).await;
    let (_, _, body) = send(&h.app, request(Method::GET, "/scscpq_api.php?action=download&url=", Some(&cookie), &[("referer", "https://www.harcourt.co/catalog/")], Body::empty())).await;
    assert_eq!(body, "Error detected Empty URL");
}

#[tokio::test]
async fn quick_cad_from_the_configurator_asks_for_the_configured_number() {
    let h = harness().await;
    for sql in [
        "INSERT INTO category (id, name, active) VALUES (1, 'Pins', 1)",
        "INSERT INTO subcategory (id, category_id, name, status, prj) VALUES (7, 1, 'Ball Lock Pins', 'Active', 'info=harcourt/pins/pin.prj'), (8, 1, 'Detent Pins', 'Pending', 'info=harcourt/pins/detent.prj')",
        "INSERT INTO inventory (id, sku, category_id, subcategory_id, active) VALUES (1, 'PIN 10', 1, 7, 1)",
        "INSERT INTO registry (domain, module, field, item, data) VALUES ('', 'cad', 'catalog', '', 'harcourt')",
        "INSERT INTO cad (code, name, type, direct, active) VALUES ('STEP', 'STEP AP214', '3D', 0, 1)",
    ] {
        sqlx::query(sql).execute(&h.pool).await.unwrap();
    }
    let mut a = Account::customer(73);
    a.cad = true;
    let cookie = signed_in(&h.pool, &a).await;
    let (_, _, body) = send(&h.app, xhr("action=profile&profile_email=buyer%40example.com&profile_name_first=Pat&profile_name_last=Lee&cad_code=STEP&profile_generate=1", Some(&cookie))).await;
    assert!(body.contains("Profile Updated"), "{body}");
    let quick_cad = |sku: &str, configurator: Option<&str>| {
        let mut form = form_urlencoded::Serializer::new(String::new());
        form.append_pair("action", "quick_cad").append_pair("sku", sku);
        if let Some(c) = configurator {
            form.append_pair("configurator", c);
        }
        xhr(&form.finish(), Some(&cookie))
    };
    let pool = &h.pool;
    let logged = move || async move {
        sqlx::query_as::<_, (String, String, i64, String, String)>("SELECT subtype, sku, subcategory_id, email, comment FROM log WHERE type = 'PARTSolutions' ORDER BY id")
            .fetch_all(pool)
            .await
            .unwrap()
    };

    let pin = r#"{"standardName":"Ball Lock Pin","lina":"PIN_10","partId":"(harcourt/pins/pin.prj)({LEN=10},{D=6})"}"#;
    let (_, _, body) = send(&h.app, quick_cad("PIN 10", Some(pin))).await;
    let v: serde_json::Value = serde_json::from_str(&body).unwrap();
    assert_eq!(v["quick_cad_status"], "CAD Generator Error", "no PARTsolutions server is set: {body}");
    let row = ("Quick CAD Error".to_string(), "PIN_10".to_string(), 7, "buyer@example.com".to_string(), "Pins: Ball Lock Pins\nCAD Format: STEP AP214\nPARTserver URL Missing".to_string());
    assert_eq!(logged().await, vec![row.clone()], "the configured number and the family its project names");

    let detent = r#"{"lina":"DP_5","partId":"(harcourt/pins/detent.prj)({LEN=5})"}"#;
    let (_, _, body) = send(&h.app, quick_cad("DP 5", Some(detent))).await;
    let v: serde_json::Value = serde_json::from_str(&body).unwrap();
    assert_eq!(v["quick_cad_status"], "Quick CAD not available for DP_5", "a family the catalog does not show this account");
    assert_eq!(logged().await.len(), 1);

    let (_, _, body) = send(&h.app, quick_cad("PIN 10", None)).await;
    let v: serde_json::Value = serde_json::from_str(&body).unwrap();
    assert_eq!(v["quick_cad_status"], "CAD Generator Error");
    assert_eq!(logged().await.last().map(|r| (r.1.as_str(), r.2)), Some(("PIN 10", 7)), "without the configurator, the part's own number");
}
