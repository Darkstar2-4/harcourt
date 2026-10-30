//! The cart, checkout and the PO Manager through the orders router, with an in-memory database, synthetic accounts
//! and a fixed price list. Mail is only queued; nothing here sends any.

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
    db,
    identity::UserId,
    orders::{
        forms,
        pricing::{Price, PriceFuture, Priced, Pricing},
        Orders,
    },
    session,
};
use rust_decimal::Decimal;
use sqlx::SqlitePool;
use tower::ServiceExt;

struct PriceList(HashMap<&'static str, Option<i64>>);

impl Pricing for PriceList {
    fn price<'a>(&'a self, _pool: &'a SqlitePool, _account: UserId, sku: &'a str, quantity: u32) -> PriceFuture<'a> {
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
    let pool = db::open_memory().await.unwrap();
    let core = Arc::new(Auth { pool: pool.clone(), secret: None, public_origin: "https://www.harcourt.co".into(), trusted_proxies: vec![], accounts: hxweb::accounts::Services::offline(hxweb::accounts::bot::Dns::fixed(&[], &[])) });
    let prices = PriceList(HashMap::from([("SKU-1", Some(10)), ("SKU-2", Some(3)), ("SKU-Q", None)]));
    let state = Orders { core, pricing: Arc::new(prices), docroot: PathBuf::from("/nonexistent"), forms: forms::Services::default() };
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
    let token = session::create(pool, UserId(id), Some("buyer@example.com"), db::unix_now()).await.unwrap();
    format!("{}={}", session::COOKIE, token.as_str())
}

fn request(method: Method, uri: &str, cookie: &str, form: &[(&str, &str)], ajax: bool) -> Request<Body> {
    let body: Vec<String> = form.iter().map(|(k, v)| format!("{}={}", enc(k), enc(v))).collect();
    let mut r = Request::builder()
        .method(method)
        .uri(uri)
        .header(header::COOKIE, cookie)
        .header(header::REFERER, "https://www.harcourt.co/catalog/")
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

#[tokio::test]
async fn checkout_writes_the_order_and_queues_both_mails() {
    let h = harness().await;
    account(&h.pool, 1, "Customer", 1).await;
    checkout_fixtures(&h.pool).await;
    let c = cookie(&h.pool, 1).await;
    add(&h, &c, "SKU-1", "3").await;
    add(&h, &c, "SKU-Q", "1").await;
    let (status, _, page) = send(&h.app, request(Method::POST, "/checkout.php", &c, &order_form(true), false)).await;
    assert_eq!(status, StatusCode::OK);
    assert!(page.contains("Thank you Pat Buyer for your order!"), "{page}");
    assert!(page.contains("Order Complete!"));
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
async fn a_checkout_with_errors_writes_nothing_and_keeps_the_input() {
    let h = harness().await;
    account(&h.pool, 1, "Customer", 1).await;
    checkout_fixtures(&h.pool).await;
    let c = cookie(&h.pool, 1).await;
    add(&h, &c, "SKU-1", "1").await;
    let mut form: Vec<(&str, &str)> = order_form(false).into_iter().filter(|(k, _)| *k != "orderhd_city" && *k != "carrier_name").collect();
    form.push(("cc", "12"));
    let (_, _, page) = send(&h.app, request(Method::POST, "/checkout.php", &c, &form, false)).await;
    assert!(page.contains("error(s)"), "{page}");
    assert!(page.contains("Valid entries are 4 digits or blank"));
    assert!(page.contains("name='orderhd_city' id='orderhd_city' size='40' maxlength='40' placeholder='City' style='border: 1px solid red !important;'"));
    assert!(page.contains("value='Pat'"));
    assert_eq!(count(&h.pool, "SELECT count(*) FROM orderhd").await, 0);
    assert_eq!(count(&h.pool, "SELECT count(*) FROM outbox").await, 0);
}

#[tokio::test]
async fn the_po_manager_is_for_executives() {
    let h = harness().await;
    account(&h.pool, 1, "Customer", 1).await;
    account(&h.pool, 9, "Executive", 0).await;
    checkout_fixtures(&h.pool).await;
    let buyer = cookie(&h.pool, 1).await;
    add(&h, &buyer, "SKU-1", "2").await;
    send(&h.app, request(Method::POST, "/checkout.php", &buyer, &order_form(true), false)).await;
    let id: i64 = sqlx::query_scalar("SELECT id FROM orderhd").fetch_one(&h.pool).await.unwrap();
    let (status, headers, _) = send(&h.app, request(Method::GET, "/orderhd.php", &buyer, &[], false)).await;
    assert_eq!((status, headers.get(header::LOCATION).unwrap().to_str().unwrap()), (StatusCode::FOUND, "/scs_index.php"));
    let exec = cookie(&h.pool, 9).await;
    let (_, _, page) = send(&h.app, request(Method::POST, "/orderhd.php", &exec, &[("action", "list")], false)).await;
    assert!(page.contains(&format!(">{id}</a>")) && page.contains("<td>Test Co</td>"));
    let id_text = id.to_string();
    let (_, _, page) = send(&h.app, request(Method::POST, "/orderhd.php", &exec, &[("action", "maintain"), ("record_id", &id_text)], false)).await;
    assert!(page.contains("<td class=right>$20.00</td>") && page.contains("<tr><td>Status</td><td>Pending</td></tr>"));
    let (status, headers, _) =
        send(&h.app, request(Method::POST, "/orderhd.php", &exec, &[("action", "update"), ("record_id", &id_text), ("status", "Accept"), ("return_url", "/orderhd.php")], false)).await;
    assert_eq!((status, headers.get(header::LOCATION).unwrap().to_str().unwrap()), (StatusCode::FOUND, "/orderhd.php"));
    let (st, by): (String, i64) = sqlx::query_as("SELECT status, status_user_id FROM orderhd").fetch_one(&h.pool).await.unwrap();
    assert_eq!((st.as_str(), by), ("Accept", 9));
    let (_, _, page) = send(&h.app, request(Method::POST, "/orderhd.php", &exec, &[("action", "update"), ("record_id", &id_text), ("status", "Accept")], false)).await;
    assert!(page.contains("Correct 1 error(s)"), "same status is an error");
}
