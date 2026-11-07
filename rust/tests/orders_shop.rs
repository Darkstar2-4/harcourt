//! The cart and checkout through the orders router, with an in-memory database, synthetic accounts and a fixed price
//! list. Mail is only queued; nothing here sends any.

use std::{collections::HashMap, net::SocketAddr, path::PathBuf, sync::Arc};

use axum::{
    body::Body,
    extract::connect_info::MockConnectInfo,
    http::{header, HeaderMap, Method, Request, StatusCode},
    Router,
};
use base64::{engine::general_purpose::STANDARD_NO_PAD, Engine};
use http_body_util::BodyExt;
use hxweb::{
    api::Auth,
    catalog::viewer::Viewer,
    db,
    identity::UserId,
    orders::{
        forms,
        pricing::{CatalogPrices, Price, PriceFuture, Priced, Pricing},
        Orders,
    },
    session,
};
use rust_decimal::Decimal;
use sqlx::SqlitePool;
use tower::ServiceExt;

struct PriceList(HashMap<&'static str, Option<i64>>);

impl Pricing for PriceList {
    fn price<'a>(&'a self, _pool: &'a SqlitePool, _viewer: &'a Viewer, sku: &'a str, quantity: u32) -> PriceFuture<'a> {
        Box::pin(async move {
            Ok(self.0.get(sku).map(|unit| Priced {
                description: format!("Test category: {sku}"),
                price: match unit {
                    Some(u) => Price::Quoted { unit: Decimal::from(*u), extended: Decimal::from(*u * i64::from(quantity)) },
                    None => Price::OnRequest,
                },
            }))
        })
    }
}

struct Harness {
    app: Router,
    pool: SqlitePool,
}

async fn harness() -> Harness {
    harness_with(Arc::new(PriceList(HashMap::from([("SKU-1", Some(10)), ("SKU-2", Some(3)), ("SKU-Q", None)])))).await
}

async fn harness_with(pricing: Arc<dyn Pricing>) -> Harness {
    let pool = db::open_memory().await.unwrap();
    let core = Arc::new(Auth { pool: pool.clone(), secret: None, public_origin: "https://www.harcourt.co".into(), trusted_proxies: vec![], accounts: hxweb::accounts::Services::offline(hxweb::accounts::bot::Dns::fixed(&[], &[])) });
    let state = Orders { core, pricing, docroot: PathBuf::from("/nonexistent"), forms: forms::Services::default() };
    let app = hxweb::orders::router(Arc::new(state)).layer(MockConnectInfo(SocketAddr::from(([192, 0, 2, 10], 40000))));
    Harness { app, pool }
}

async fn account(pool: &SqlitePool, id: i64, role: &str, ecommerce: i64) {
    sqlx::query(r#"INSERT INTO "user" (id, status, "type", company_name, ecommerce, domain) VALUES (?1, 'Active', ?2, ?3, ?4, 'example.com')"#)
        .bind(id)
        .bind(role)
        .bind(format!("Test company {id}"))
        .bind(ecommerce)
        .execute(pool)
        .await
        .unwrap();
}

async fn cookie(pool: &SqlitePool, id: i64) -> String {
    let domain: String = sqlx::query_scalar(r#"SELECT domain FROM "user" WHERE id = ?1"#).bind(id).fetch_one(pool).await.unwrap();
    let email = hxweb::identity::Email::parse(&format!("buyer@{domain}")).unwrap();
    let token = session::create(pool, UserId(id), &session::Basis::Domain(email), db::unix_now()).await.unwrap();
    format!("{}={}", session::COOKIE, token.as_str())
}

fn request(method: Method, uri: &str, cookie: &str, form: &[(&str, &str)], ajax: bool) -> Request<Body> {
    let body: Vec<String> = form.iter().map(|(k, v)| format!("{}={}", enc(k), enc(v))).collect();
    let mut r = Request::builder()
        .method(method)
        .uri(uri)
        .header(header::COOKIE, cookie)
        .header(header::REFERER, "https://www.harcourt.co/catalog/")
        .header(header::ORIGIN, "https://www.harcourt.co")
        .header(header::CONTENT_TYPE, "application/x-www-form-urlencoded");
    if ajax {
        r = r.header("x-requested-with", "XMLHttpRequest");
    }
    r.body(Body::from(body.join("&"))).unwrap()
}

fn enc(s: &str) -> String {
    percent_encoding::utf8_percent_encode(s, percent_encoding::NON_ALPHANUMERIC).to_string()
}

async fn send(app: &Router, req: Request<Body>) -> (StatusCode, HeaderMap, String) {
    let res = app.clone().oneshot(req).await.unwrap();
    let (parts, body) = res.into_parts();
    let bytes = body.collect().await.unwrap().to_bytes();
    (parts.status, parts.headers, String::from_utf8_lossy(&bytes).into_owned())
}

async fn add(h: &Harness, cookie: &str, sku: &str, quantity: &str) -> serde_json::Value {
    let code = STANDARD_NO_PAD.encode(format!("c\t{sku}"));
    let (status, _, body) = send(&h.app, request(Method::POST, "/cart.php", cookie, &[("json_code", &code), ("json_quantity", quantity)], true)).await;
    assert_eq!(status, StatusCode::OK);
    serde_json::from_str(&body).unwrap()
}

async fn count(pool: &SqlitePool, sql: &str) -> i64 {
    sqlx::query_scalar(sql).fetch_one(pool).await.unwrap()
}

fn field(parts: &[&str]) -> String {
    STANDARD_NO_PAD.encode(parts.join("\t"))
}

#[tokio::test]
async fn the_cart_call_adds_reprices_and_removes() {
    let h = harness().await;
    account(&h.pool, 1, "Customer", 1).await;
    let c = cookie(&h.pool, 1).await;
    let a = add(&h, &c, "SKU-1", "3").await;
    assert_eq!((a["status"].as_str(), a["cart_size"].as_i64(), a["cart_extended"].as_str()), (Some("SKU-1 cart updated"), Some(1), Some("$30.00")));
    assert_eq!(a["quantity"].as_f64(), Some(3.0));
    let a = add(&h, &c, "SKU-2", "2").await;
    assert_eq!((a["cart_size"].as_i64(), a["cart_extended"].as_str()), (Some(2), Some("$36.00")));
    let a = add(&h, &c, "SKU-Q", "1").await;
    assert_eq!(a["cart_extended"].as_str(), Some("Pricing available upon request."));
    let a = add(&h, &c, "NOPE", "1").await;
    assert_eq!((a["status"].as_str(), a["cart_size"].as_i64()), (Some("NOPE invalid"), Some(3)));
    let a = add(&h, &c, "SKU-Q", "0").await;
    assert_eq!((a["status"].as_str(), a["cart_size"].as_i64()), (Some("SKU-Q removed from cart"), Some(2)));
}

#[tokio::test]
async fn the_cart_call_writes_the_total_as_the_cart_page_does() {
    let h = harness().await;
    sqlx::query("INSERT INTO currency (code, name, plural, exchange_rate, active) VALUES ('CAD', 'Canadian dollar', 'Canadian dollars', 1.25, 1), ('EUR', 'Euro', 'Euros', 0.9, 0)").execute(&h.pool).await.unwrap();
    for (id, code, domain) in [(1, "CAD", "example.com"), (2, "EUR", "example.org")] {
        account(&h.pool, id, "Customer", 1).await;
        sqlx::query(r#"UPDATE "user" SET currency_code = ?1, domain = ?2 WHERE id = ?3"#).bind(code).bind(domain).bind(id).execute(&h.pool).await.unwrap();
    }
    let c = cookie(&h.pool, 1).await;
    add(&h, &c, "SKU-1", "1").await;
    let a = add(&h, &c, "SKU-2", "2").await;
    assert_eq!(a["cart_extended"].as_str(), Some("16.00 CAD"));
    let (_, _, page) = send(&h.app, request(Method::GET, "/cart.php", &c, &[], false)).await;
    assert!(page.contains("<span id=cart_extended>16.00 CAD</span>"), "{page}");
    let c = cookie(&h.pool, 2).await;
    let a = add(&h, &c, "SKU-1", "1").await;
    assert_eq!(a["cart_extended"].as_str(), Some("10.00 USD"), "an account whose currency is not offered sees US dollars named");
    let (_, _, page) = send(&h.app, request(Method::GET, "/cart.php", &c, &[], false)).await;
    assert!(page.contains("Priced in <b>US dollars</b>") && page.contains("<span id=cart_extended>10.00 USD</span>"), "{page}");
}

#[tokio::test]
async fn carts_belong_to_one_session_and_end_with_it() {
    let h = harness().await;
    account(&h.pool, 1, "Customer", 1).await;
    let mine = cookie(&h.pool, 1).await;
    let other = cookie(&h.pool, 1).await;
    add(&h, &mine, "SKU-1", "1").await;
    let (_, _, page) = send(&h.app, request(Method::GET, "/cart.php?scscpq_wp=1", &other, &[], false)).await;
    assert!(page.contains("<p>Your shopping cart is empty</p>"));
    let (_, _, page) = send(&h.app, request(Method::GET, "/cart.php?scscpq_wp=1", &mine, &[], false)).await;
    assert!(page.contains("<td class=nobr>SKU-1</td>") && page.contains("<span id=cart_extended>$10.00</span>"));
    assert!(!page.contains("scs_menu"), "no ribbon inside the WordPress frame");
    sqlx::query("DELETE FROM session").execute(&h.pool).await.unwrap();
    assert_eq!(count(&h.pool, "SELECT count(*) FROM cart_item").await, 0);
}

#[tokio::test]
async fn an_account_signed_in_by_company_address_keeps_its_cart_in_the_php_session() {
    let h = harness().await;
    sqlx::query(r#"INSERT INTO "user" (id, status, "type", company_name, ecommerce, domain, ip) VALUES (5, 'Active', 'Customer', 'Test company 5', 1, 'example.com', '192.0.2.10')"#)
        .execute(&h.pool)
        .await
        .unwrap();
    let a = add(&h, "PHPSESSID=visit-one", "SKU-1", "2").await;
    assert_eq!((a["status"].as_str(), a["cart_size"].as_i64()), (Some("SKU-1 cart updated"), Some(1)));
    let (_, _, page) = send(&h.app, request(Method::GET, "/cart.php", "PHPSESSID=visit-one", &[], false)).await;
    assert!(page.contains("<td class=nobr>SKU-1</td>"));
    let (status, _, page) = send(&h.app, request(Method::GET, "/cart.php", "PHPSESSID=visit-two", &[], false)).await;
    assert_eq!(status, StatusCode::OK);
    assert!(page.contains("<p>Your shopping cart is empty</p>"));
    let a = add(&h, "", "SKU-1", "1").await;
    assert_eq!((a["status"].as_str(), a["cart_size"].as_i64()), (Some("SKU-1 invalid"), Some(0)), "no visit, no cart to keep");
}

#[tokio::test]
async fn signing_in_takes_over_the_cart_kept_by_company_address() {
    let h = harness().await;
    sqlx::query(r#"INSERT INTO "user" (id, status, "type", company_name, ecommerce, domain, ip) VALUES (5, 'Active', 'Customer', 'Test company 5', 1, 'example.com', '192.0.2.10')"#)
        .execute(&h.pool)
        .await
        .unwrap();
    account(&h.pool, 6, "Customer", 1).await;
    sqlx::query(r#"UPDATE "user" SET domain = 'example.org' WHERE id = 6"#).execute(&h.pool).await.unwrap();
    let session = cookie(&h.pool, 5).await;
    add(&h, &session, "SKU-1", "1").await;
    add(&h, "PHPSESSID=visit-one", "SKU-1", "2").await;
    add(&h, "PHPSESSID=visit-one", "SKU-2", "1").await;
    let other = format!("PHPSESSID=visit-one; {}", cookie(&h.pool, 6).await);
    let (_, _, page) = send(&h.app, request(Method::GET, "/cart.php", &other, &[], false)).await;
    assert!(page.contains("<p>Your shopping cart is empty</p>"), "another account's sign-in");
    assert_eq!(count(&h.pool, "SELECT count(*) FROM cart_item").await, 3);
    let both = format!("PHPSESSID=visit-one; {session}");
    let (_, _, page) = send(&h.app, request(Method::GET, "/cart.php", &both, &[], false)).await;
    assert!(page.contains("<span id=cart_extended>$33.00</span>"), "{page}");
    let (first, second) = (page.find("<td class=nobr>SKU-1</td>"), page.find("<td class=nobr>SKU-2</td>"));
    assert!(first.is_some() && first < second);
    assert_eq!(count(&h.pool, "SELECT count(*) FROM cart_item").await, 2, "the address cart is gone");
    assert_eq!(count(&h.pool, "SELECT quantity FROM cart_item WHERE sku = 'SKU-1'").await, 3);
    let (_, _, page) = send(&h.app, request(Method::GET, "/cart.php", "PHPSESSID=visit-one", &[], false)).await;
    assert!(page.contains("<p>Your shopping cart is empty</p>"));
}

#[tokio::test]
async fn a_cart_without_a_session_lasts_a_day_from_its_last_use() {
    let h = harness().await;
    sqlx::query(r#"INSERT INTO "user" (id, status, "type", company_name, ecommerce, domain, ip) VALUES (5, 'Active', 'Customer', 'Test company 5', 1, 'example.com', '192.0.2.10')"#)
        .execute(&h.pool)
        .await
        .unwrap();
    add(&h, "PHPSESSID=visit-one", "SKU-1", "2").await;
    sqlx::query("UPDATE cart_item SET added_at = added_at - 25 * 3600, touched_at = touched_at - 2 * 3600").execute(&h.pool).await.unwrap();
    let a = add(&h, "PHPSESSID=visit-one", "SKU-2", "1").await;
    assert_eq!(a["cart_size"].as_i64(), Some(2), "added a day ago, used since: {a}");
    let (_, _, page) = send(&h.app, request(Method::GET, "/cart.php", "PHPSESSID=visit-one", &[], false)).await;
    let (first, second) = (page.find("<td class=nobr>SKU-1</td>"), page.find("<td class=nobr>SKU-2</td>"));
    assert!(first.is_some() && first < second, "lines keep their order: {page}");
    sqlx::query("UPDATE cart_item SET touched_at = touched_at - 25 * 3600").execute(&h.pool).await.unwrap();
    let (_, _, page) = send(&h.app, request(Method::GET, "/cart.php", "PHPSESSID=visit-one", &[], false)).await;
    assert!(page.contains("<p>Your shopping cart is empty</p>"), "unused for a day");
    assert_eq!(count(&h.pool, "SELECT count(*) FROM cart_item").await, 0);
}

#[tokio::test]
async fn accounts_without_ecommerce_cannot_shop() {
    let h = harness().await;
    account(&h.pool, 2, "Customer", 0).await;
    let c = cookie(&h.pool, 2).await;
    let (status, headers, _) = send(&h.app, request(Method::GET, "/cart.php", &c, &[], false)).await;
    assert_eq!((status, headers.get(header::LOCATION).unwrap().to_str().unwrap()), (StatusCode::FOUND, "/scs_index.php"));
    let code = STANDARD_NO_PAD.encode("c\tSKU-1");
    let (status, _, body) = send(&h.app, request(Method::POST, "/cart.php", &c, &[("json_code", &code), ("json_quantity", "1")], true)).await;
    assert_eq!((status, body.as_str()), (StatusCode::OK, ""));
    assert_eq!(count(&h.pool, "SELECT count(*) FROM cart_item").await, 0);
    let (status, _, _) = send(&h.app, request(Method::GET, "/checkout.php", &c, &[], false)).await;
    assert_eq!(status, StatusCode::FOUND);
}

#[tokio::test]
async fn the_cart_page_updates_and_clears() {
    let h = harness().await;
    account(&h.pool, 1, "Customer", 1).await;
    let c = cookie(&h.pool, 1).await;
    add(&h, &c, "SKU-1", "1").await;
    add(&h, &c, "SKU-2", "1").await;
    let q1 = field(&["quantity", "SKU-1"]);
    let c1 = field(&["comment", "SKU-1"]);
    let q2 = field(&["quantity", "SKU-2"]);
    let (_, _, page) = send(&h.app, request(Method::POST, "/cart.php", &c, &[("action", "update"), (&q1, "4"), (&c1, "rush"), (&q2, "0")], false)).await;
    assert!(page.contains("Shopping Cart Updated"));
    assert!(page.contains("value='rush'") && page.contains("<span id=cart_extended>$40.00</span>"));
    assert_eq!(count(&h.pool, "SELECT count(*) FROM cart_item").await, 1);
    let (status, headers, _) = send(&h.app, request(Method::POST, "/cart.php", &c, &[("action", "checkout")], false)).await;
    assert_eq!((status, headers.get(header::LOCATION).unwrap().to_str().unwrap()), (StatusCode::FOUND, "/checkout.php"));
    let (_, _, page) = send(&h.app, request(Method::POST, "/cart.php", &c, &[("action", "clear")], false)).await;
    assert!(page.contains("Shopping Cart Cleared") && page.contains("Your shopping cart is empty"));
}

/// A catalog of one shown family with a priced and an unpriced part, a Pending family, and a family in a category
/// blocked for account 1.
async fn catalog_fixtures(pool: &SqlitePool) {
    for sql in [
        "INSERT INTO currency (code, name, plural, exchange_rate, adder_pct, active) VALUES ('USD', 'US Dollar', 'US Dollars', 1, 0, 1)",
        "INSERT INTO category (id, name, sort_order, active) VALUES (1, 'Fixtures', 1, 1), (2, 'Restricted', 2, 1)",
        "INSERT INTO subcategory (id, category_id, name, status, output) VALUES (7, 1, 'Locating Pins', 'Active', ''), (8, 1, 'Prototype Pins', 'Pending', ''), (9, 2, 'Restricted Pins', 'Active', '')",
        r#"INSERT INTO inventory (sku, category_id, subcategory_id, pricing, active) VALUES
           ('A-1', 1, 7, '[{"price":10.0,"qty_break":1},{"price":9.0,"qty_break":10}]', 1), ('N-1', 1, 7, '', 1),
           ('P-1', 1, 8, '[{"price":5.0,"qty_break":1}]', 1), ('B-1', 2, 9, '[{"price":5.0,"qty_break":1}]', 1),
           ('X-1', 1, 7, '[{"price":5.0,"qty_break":1}]', 0)"#,
        "INSERT INTO user_category (user_id, category_id) VALUES (1, 2)",
    ] {
        sqlx::query(sql).execute(pool).await.unwrap();
    }
    sqlx::query(
        r#"INSERT INTO "user" (id, status, "type", company_name, ecommerce, catalog, price_type, price_pct, currency_code, domain) VALUES
           (1, 'Active', 'Customer', 'Test company 1', 1, 1, 'discount', 0.1, 'USD', 'example.com'),
           (2, 'Active', 'Administrator', 'Test company 2', 1, 1, 'base', 0, 'USD', 'example.org')"#,
    )
    .execute(pool)
    .await
    .unwrap();
}

#[tokio::test]
async fn the_cart_charges_what_the_catalog_shows_the_account() {
    let h = harness_with(Arc::new(CatalogPrices)).await;
    catalog_fixtures(&h.pool).await;
    let customer = cookie(&h.pool, 1).await;
    let a = add(&h, &customer, "A-1", "10").await;
    assert_eq!((a["status"].as_str(), a["cart_extended"].as_str()), (Some("A-1 cart updated"), Some("$81.00")), "10% off the break at 10");
    for hidden in ["P-1", "B-1", "X-1", "NOPE"] {
        let a = add(&h, &customer, hidden, "1").await;
        assert_eq!(a["status"].as_str(), Some(format!("{hidden} invalid").as_str()));
    }
    let a = add(&h, &customer, "N-1", "1").await;
    assert_eq!((a["cart_size"].as_i64(), a["cart_extended"].as_str()), (Some(2), Some("Pricing available upon request.")));
    let (_, _, page) = send(&h.app, request(Method::GET, "/cart.php", &customer, &[], false)).await;
    assert!(page.contains("Fixtures: Locating Pins") && page.contains("<td class=right>$8.10</td>"), "{page}");

    let admin = cookie(&h.pool, 2).await;
    let a = add(&h, &admin, "P-1", "2").await;
    assert_eq!((a["status"].as_str(), a["cart_extended"].as_str()), (Some("P-1 cart updated"), Some("$10.00")), "Administrators see Pending families");
    let a = add(&h, &admin, "B-1", "1").await;
    assert_eq!(a["cart_extended"].as_str(), Some("$15.00"), "the block is account 1's");
}

async fn checkout_fixtures(pool: &SqlitePool) {
    sqlx::query("INSERT INTO mailform (code, name, recipient, bcc, subject) VALUES ('checkout', 'Check Out', 'orders@example.com', '[\"copy@example.com\"]', 'PO #%po%')")
        .execute(pool)
        .await
        .unwrap();
    sqlx::query("INSERT INTO carrier (name, active) VALUES ('Test Freight', 1)").execute(pool).await.unwrap();
    sqlx::query("INSERT INTO country (country, state, name, active) VALUES ('US', '', 'United States', 1), ('US', 'MI', 'Michigan', 1)").execute(pool).await.unwrap();
}

fn order_form<'a>(billing_same: bool) -> Vec<(&'a str, &'a str)> {
    let mut f = vec![
        ("action", "complete"),
        ("orderhd_company_name", "Test Co"),
        ("orderhd_name_first", "Pat"),
        ("orderhd_name_last", "Buyer"),
        ("orderhd_phone", "555-0100"),
        ("orderhd_email", "Buyer@Example.com"),
        ("orderhd_address", "1 Test Way"),
        ("orderhd_city", "Testville"),
        ("orderhd_state_us", "MI"),
        ("orderhd_zip", "48000"),
        ("orderhd_country_code", "US"),
        ("carrier_name", "Test Freight"),
        ("po", "abc-1"),
        ("cc", "1234"),
    ];
    if billing_same {
        f.push(("billing_same_as_shipping", "1"));
    }
    f
}

/// The one-time token the checkout form carries.
async fn checkout_token(h: &Harness, cookie: &str) -> String {
    let (_, _, page) = send(&h.app, request(Method::GET, "/checkout.php", cookie, &[], false)).await;
    let field = "name='checkout_token' id='checkout_token' value='";
    let start = page.find(field).expect("the form carries a token") + field.len();
    page[start..].split('\'').next().unwrap().to_string()
}

#[tokio::test]
async fn a_placed_order_clears_the_cart_queues_its_mail_and_shows_its_confirmation() {
    let h = harness().await;
    account(&h.pool, 1, "Customer", 1).await;
    checkout_fixtures(&h.pool).await;
    let c = cookie(&h.pool, 1).await;
    add(&h, &c, "SKU-1", "3").await;
    add(&h, &c, "SKU-Q", "1").await;
    let token = checkout_token(&h, &c).await;
    let mut form = order_form(true);
    form.push(("checkout_token", &token));
    let (status, headers, _) = send(&h.app, request(Method::POST, "/checkout.php", &c, &form, false)).await;
    assert_eq!(status, StatusCode::SEE_OTHER);
    let id: i64 = sqlx::query_scalar("SELECT id FROM orderhd").fetch_one(&h.pool).await.unwrap();
    let location = headers.get(header::LOCATION).unwrap().to_str().unwrap().to_string();
    assert_eq!(location, format!("/checkout.php?order={id}"));
    let (status, _, page) = send(&h.app, request(Method::GET, &location, &c, &[], false)).await;
    assert_eq!(status, StatusCode::OK);
    assert!(page.contains("Thank you Pat Buyer for your order!"), "{page}");
    assert!(page.contains("Order Complete!<span class=message2><br>Email successfully sent to orders@example.com<br>Email successfully sent to buyer@example.com</span>"), "{page}");
    assert!(page.contains(&format!("<tr><td>Order #</td><td>{id}</tr>")) && page.contains("<td class=right>$30.00</td>"));
    let (po, email, status, billing, user): (String, String, String, String, i64) =
        sqlx::query_as("SELECT po, email, status, billing_city, user_id FROM orderhd").fetch_one(&h.pool).await.unwrap();
    assert_eq!((po.as_str(), email.as_str(), status.as_str(), billing.as_str(), user), ("ABC-1", "buyer@example.com", "Pending", "Testville", 1));
    let lines: Vec<(String, String, String)> =
        sqlx::query_as("SELECT sku, printf('%.5f', price), printf('%.2f', extended) FROM orderln ORDER BY line_no").fetch_all(&h.pool).await.unwrap();
    assert_eq!(lines, [("SKU-1".into(), "10.00000".into(), "30.00".into()), ("SKU-Q".into(), "0.00000".into(), "0.00".into())]);
    let mails: Vec<(String, String)> = sqlx::query_as("SELECT to_email, subject FROM outbox ORDER BY id").fetch_all(&h.pool).await.unwrap();
    assert_eq!(mails, [("orders@example.com".into(), "PO #ABC-1".into()), ("buyer@example.com".into(), "Order Confirmation".into())]);
    assert_eq!(count(&h.pool, "SELECT count(*) FROM outbox_recipient WHERE kind = 'bcc' AND email = 'copy@example.com'").await, 2);
    assert_eq!(count(&h.pool, "SELECT count(*) FROM cart_item").await, 0);
    assert_eq!(count(&h.pool, "SELECT count(*) FROM log WHERE type = 'User' AND subtype = 'PO'").await, 1);
}

#[tokio::test]
async fn a_confirmation_shows_the_currency_the_order_was_placed_in() {
    let h = harness().await;
    sqlx::query("INSERT INTO currency (code, name, plural, exchange_rate, active) VALUES ('CAD', 'Canadian dollar', 'Canadian dollars', 1.25, 1)").execute(&h.pool).await.unwrap();
    account(&h.pool, 1, "Customer", 1).await;
    sqlx::query(r#"UPDATE "user" SET currency_code = 'CAD' WHERE id = 1"#).execute(&h.pool).await.unwrap();
    checkout_fixtures(&h.pool).await;
    let c = cookie(&h.pool, 1).await;
    add(&h, &c, "SKU-1", "3").await;
    let token = checkout_token(&h, &c).await;
    let mut form = order_form(true);
    form.push(("checkout_token", &token));
    let (_, headers, _) = send(&h.app, request(Method::POST, "/checkout.php", &c, &form, false)).await;
    let location = headers.get(header::LOCATION).unwrap().to_str().unwrap().to_string();
    sqlx::query("UPDATE currency SET active = 0 WHERE code = 'CAD'").execute(&h.pool).await.unwrap();
    let (_, _, page) = send(&h.app, request(Method::GET, &location, &c, &[], false)).await;
    assert!(page.contains("<span id=cart_extended>30.00 CAD</span>") && page.contains("Priced in <b>Canadian dollars</b>"), "{page}");
}

#[tokio::test]
async fn one_form_submitted_twice_places_one_order() {
    let h = harness().await;
    account(&h.pool, 1, "Customer", 1).await;
    checkout_fixtures(&h.pool).await;
    let c = cookie(&h.pool, 1).await;
    add(&h, &c, "SKU-1", "2").await;
    let token = checkout_token(&h, &c).await;
    let mut form = order_form(false);
    form.extend([("orderhd_billing_address", "1 Test Way"), ("orderhd_billing_city", "Testville"), ("orderhd_billing_state_us", "MI"), ("orderhd_billing_zip", "48000"), ("orderhd_billing_country_code", "US"), ("checkout_token", &token)]);
    let (first, first_headers, _) = send(&h.app, request(Method::POST, "/checkout.php", &c, &form, false)).await;
    let (again, again_headers, _) = send(&h.app, request(Method::POST, "/checkout.php", &c, &form, false)).await;
    assert_eq!((first, again), (StatusCode::SEE_OTHER, StatusCode::SEE_OTHER));
    assert_eq!(first_headers.get(header::LOCATION), again_headers.get(header::LOCATION), "both answer with the one order");
    assert_eq!(count(&h.pool, "SELECT count(*) FROM orderhd").await, 1);
    assert_eq!(count(&h.pool, "SELECT count(*) FROM orderln").await, 1);
    assert_eq!(count(&h.pool, "SELECT count(*) FROM outbox").await, 2);
}

#[tokio::test]
async fn a_checkout_with_errors_writes_nothing_and_keeps_the_input() {
    let h = harness().await;
    account(&h.pool, 1, "Customer", 1).await;
    checkout_fixtures(&h.pool).await;
    let c = cookie(&h.pool, 1).await;
    add(&h, &c, "SKU-1", "1").await;
    let token = checkout_token(&h, &c).await;
    let mut form: Vec<(&str, &str)> = order_form(false).into_iter().filter(|(k, _)| *k != "orderhd_city" && *k != "carrier_name").collect();
    form.extend([("cc", "12"), ("checkout_token", &token)]);
    let (_, _, page) = send(&h.app, request(Method::POST, "/checkout.php", &c, &form, false)).await;
    assert!(page.contains("error(s)"), "{page}");
    assert!(page.contains("Valid entries are 4 digits or blank"));
    assert!(page.contains("name='orderhd_city' id='orderhd_city' size='40' maxlength='40' placeholder='City' style='border: 1px solid red !important;'"));
    assert!(page.contains("value='Pat'"));
    assert_eq!(count(&h.pool, "SELECT count(*) FROM orderhd").await, 0);
    assert_eq!(count(&h.pool, "SELECT count(*) FROM outbox").await, 0);
}

#[tokio::test]
async fn a_checkout_places_the_cart_its_form_showed() {
    let h = harness().await;
    account(&h.pool, 1, "Customer", 1).await;
    checkout_fixtures(&h.pool).await;
    let c = cookie(&h.pool, 1).await;
    add(&h, &c, "SKU-1", "2").await;
    let shown = checkout_token(&h, &c).await;
    add(&h, &c, "SKU-2", "1").await;
    let mut form = order_form(true);
    form.push(("checkout_token", &shown));
    let (status, _, page) = send(&h.app, request(Method::POST, "/checkout.php", &c, &form, false)).await;
    assert_eq!(status, StatusCode::OK);
    assert!(page.contains("Your shopping cart has changed. Check the order and place it again.") && page.contains("value='Pat'"), "{page}");
    assert_eq!(count(&h.pool, "SELECT count(*) FROM orderhd").await, 0);
    assert_eq!(count(&h.pool, "SELECT count(*) FROM cart_item").await, 2);

    let first = checkout_token(&h, &c).await;
    let second = checkout_token(&h, &c).await;
    let mut form = order_form(true);
    form.push(("checkout_token", &first));
    let (status, _, _) = send(&h.app, request(Method::POST, "/checkout.php", &c, &form, false)).await;
    assert_eq!(status, StatusCode::SEE_OTHER);
    assert_eq!(count(&h.pool, "SELECT count(*) FROM orderln").await, 2);
    add(&h, &c, "SKU-1", "5").await;
    let mut form = order_form(true);
    form.push(("checkout_token", &second));
    let (status, _, page) = send(&h.app, request(Method::POST, "/checkout.php", &c, &form, false)).await;
    assert_eq!(status, StatusCode::OK, "a second form for the cart already placed does not place the new cart");
    assert!(page.contains("Your shopping cart has changed."), "{page}");
    assert_eq!(count(&h.pool, "SELECT count(*) FROM orderhd").await, 1);
    assert_eq!(count(&h.pool, "SELECT count(*) FROM cart_item").await, 1);
}

#[tokio::test]
async fn the_checkout_form_stars_what_the_registry_requires() {
    let h = harness().await;
    account(&h.pool, 1, "Customer", 1).await;
    checkout_fixtures(&h.pool).await;
    sqlx::query("INSERT INTO registry (domain, module, field, item, data) VALUES ('', 'address', 'orderhd', 'name', 'required'), ('', 'address', 'orderhd', 'company_name', 'allowed'), ('', 'address', 'orderhd', 'email', 'required')")
        .execute(&h.pool)
        .await
        .unwrap();
    let c = format!("{}; harcourt=remembered@example.com", cookie(&h.pool, 1).await);
    add(&h, &c, "SKU-1", "1").await;
    let (_, _, page) = send(&h.app, request(Method::GET, "/checkout.php", &c, &[], false)).await;
    assert!(page.contains("<tr ><td width='20%'><font color=red><b>*</b></font>First Name</td>"), "{page}");
    assert!(page.contains("<tr ><td width='20%'>&nbsp;Company Name</td>"));
    assert!(!page.contains("orderhd_phone"), "a field the registry does not list is not shown");
    assert!(page.contains("value='remembered@example.com'"));
    assert!(page.contains("<option value='' selected>Shipping Carrier</option>"));
    assert_eq!(page.matches("Indicates mandatory fields").count(), 3);
}
