//! The staff maintenance pages through the router, against a private in-memory database with synthetic rows.

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
    Harness { app: hxweb::routes::staff::router(auth), pool }
}

async fn exec(pool: &SqlitePool, sql: &str) {
    sqlx::query(sql).execute(pool).await.unwrap();
}

async fn account(pool: &SqlitePool, id: i64, role: &str, status: &str, ip: &str) {
    sqlx::query(r#"INSERT INTO "user" (id, ip, status, "type", company_name, country_code) VALUES (?1, ?2, ?3, ?4, ?5, 'US')"#)
        .bind(id)
        .bind(ip)
        .bind(status)
        .bind(role)
        .bind(format!("Test company {id}"))
        .execute(pool)
        .await
        .unwrap();
}

async fn person(pool: &SqlitePool, id: i64, role: &str) -> String {
    account(pool, id, role, "Active", &format!("192.0.2.{id}")).await;
    let token = session::create(pool, UserId(id), None, db::unix_now()).await.unwrap();
    format!("{}={}", session::COOKIE, token.as_str())
}

async fn send(app: &Router, req: Request<Body>) -> (StatusCode, HeaderMap, String) {
    let res = app.clone().oneshot(req).await.unwrap();
    let (parts, body) = res.into_parts();
    let bytes = body.collect().await.unwrap().to_bytes();
    (parts.status, parts.headers, String::from_utf8_lossy(&bytes).into_owned())
}

fn get(path: &str, cookie: Option<&str>) -> Request<Body> {
    let mut req = Request::builder().method(Method::GET).uri(path);
    if let Some(c) = cookie {
        req = req.header(header::COOKIE, c);
    }
    req.body(Body::empty()).unwrap()
}

fn post(path: &str, cookie: &str, pairs: &[(&str, &str)]) -> Request<Body> {
    let body: String = form_urlencoded::Serializer::new(String::new()).extend_pairs(pairs).finish();
    Request::builder()
        .method(Method::POST)
        .uri(path)
        .header(header::COOKIE, cookie)
        .header(header::CONTENT_TYPE, "application/x-www-form-urlencoded")
        .body(Body::from(body))
        .unwrap()
}

fn csrf_of(page: &str) -> String {
    let start = page.find("name='csrf' id='csrf' value='").expect("csrf field") + "name='csrf' id='csrf' value='".len();
    page[start..].split('\'').next().unwrap().to_string()
}

/// A POST carrying the page's own CSRF token.
async fn submit(h: &Harness, path: &str, cookie: &str, pairs: &[(&str, &str)]) -> (StatusCode, HeaderMap, String) {
    let (_, _, page) = send(&h.app, get(path, Some(cookie))).await;
    let token = csrf_of(&page);
    let with: Vec<(&str, &str)> = pairs.iter().copied().chain([("csrf", token.as_str())]).collect();
    send(&h.app, post(path, cookie, &with)).await
}

async fn user_seed(pool: &SqlitePool) {
    exec(pool, "INSERT INTO registry (domain, module, field, item, data) VALUES ('', 'address', 'profile', 'company_name', 'required'), ('', 'address', 'profile', 'city', 'allowed'), ('', 'address', 'profile', 'state', 'allowed'), ('', 'address', 'profile', 'country_code', 'required'), ('', 'address', 'profile', 'email', 'required')").await;
    exec(pool, "INSERT INTO country (country, state, name) VALUES ('US', '', 'United States'), ('CA', '', 'Canada'), ('US', 'MI', 'Michigan'), ('CA', 'ON', 'Ontario')").await;
    exec(pool, "INSERT INTO category (id, name, active, sort_order) VALUES (1, 'Drilling', 1, 1), (2, 'Tool Storage & Shipping', 1, 2)").await;
    exec(pool, "INSERT INTO currency (code, name, exchange_rate) VALUES ('USD', 'US Dollar', 1), ('ALL', 'Albanian Lek', 0)").await;
    exec(pool, "INSERT INTO language (code, name, local_name, priority) VALUES ('EN', 'English', 'English', 1), ('FR', 'French', 'Français', 2)").await;
}

fn user_form<'a>(extra: &[(&'a str, &'a str)]) -> Vec<(&'a str, &'a str)> {
    let mut f = vec![
        ("action", "update"),
        ("ip", "198.51.100.40"),
        ("profile_company_name", "Example Fixtures"),
        ("profile_city", "Springfield"),
        ("profile_country_code", "US"),
        ("profile_state_us", "MI"),
        ("status", "Active"),
        ("type", "Customer"),
        ("currency_code", "USD"),
        ("language_code", "EN"),
        ("catalog", "1"),
        ("price_type", "discount"),
        ("price_pct", "15"),
        ("user_category_2", "1"),
    ];
    f.extend_from_slice(extra);
    f
}

#[tokio::test]
async fn the_user_manager_is_for_administrators() {
    let h = harness().await;
    let exec_cookie = person(&h.pool, 2, "Executive").await;
    let admin = person(&h.pool, 1, "Administrator").await;
    for cookie in [None, Some(exec_cookie.as_str())] {
        let (status, headers, _) = send(&h.app, get("/user.php", cookie)).await;
        assert_eq!(status, StatusCode::FOUND);
        assert_eq!(headers[header::LOCATION], "/scs_index.php");
    }
    let (status, _, page) = send(&h.app, get("/user.php", Some(&admin))).await;
    assert_eq!(status, StatusCode::OK);
    assert!(page.contains("<p class='message left'>User Manager</p>"));
    assert!(page.contains("<option value='Administrator'>Administrator</option>\n</select>"));
    assert!(!page.contains("Super User"));
    assert!(page.contains("Copy from: <input type=text name='ajax_user_id' id='ajax_user_id' size='8'>"));
    assert!(page.contains("<tbody></tbody></table></form>"));
}

#[tokio::test]
async fn the_list_holds_accounts_up_to_the_viewers_role() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    account(&h.pool, 10, "Customer", "Pending", "198.51.100.10").await;
    account(&h.pool, 11, "Super User", "Active", "198.51.100.11").await;
    let (_, _, page) = submit(&h, "/user.php", &admin, &[("action", "list"), ("report_option_field", "ip"), ("report_option_value", "198.51.100.")]).await;
    assert!(page.contains("<tr><td><a href=\"javascript:fn_action(this,'maintain','10','');\" >198.51.100.10</a></td><td>Test company 10</td><td></td><td></td><td></td><td>US</td><td>Pending</td><td class=center><input type=radio value='10' id='delete_10' name='delete' onclick=\"javascript:fn_action(this,'delete','10','198.51.100.10');\">\n</td></td></tr>"));
    assert!(!page.contains("198.51.100.11"));
    assert!(page.contains("<p class='standard center'>\n<br>1 Records Found\n</p>"));
    let (_, _, page) = submit(&h, "/user.php", &admin, &[("action", "list"), ("report_type", "Super User")]).await;
    assert!(page.contains("<br>0 Records Found"));
}

#[tokio::test]
async fn editing_shows_the_address_block_and_category_tabs() {
    let h = harness().await;
    user_seed(&h.pool).await;
    let admin = person(&h.pool, 1, "Administrator").await;
    account(&h.pool, 10, "Customer", "Pending", "198.51.100.10").await;
    exec(&h.pool, "INSERT INTO user_category (user_id, category_id) VALUES (10, 1)").await;
    let (_, _, page) = send(&h.app, get("/user.php?action=maintain&record_id=10&return_url=%2Fdashboard.php", Some(&admin))).await;
    assert!(page.contains("<input type=hidden name='return_url' id='return_url' value='/dashboard.php'>"));
    assert!(page.contains("<tr ><td width='20%'><font color=red><b>*</b></font>Company Name</td><td width='80%'><input type=text name='profile_company_name' id='profile_company_name' size='40' maxlength='40' value='Test company 10' placeholder='Company Name'>\n</td></tr><tr ><td width='20%'>&nbsp;City</td>"));
    assert!(page.contains("<select name='profile_country_code' id='profile_country_code' onchange=\"javascript:fn_country_address('profile');\">\n<option value=''>Select Country</option>\n<option value='US' selected>United States</option>\n<option value='CA'>Canada</option>\n</select>"));
    assert!(page.contains("<optgroup label='Priced'>\n<option value='USD' selected>US Dollar</option>\n</optgroup>\n<optgroup label='No Price'>"));
    assert!(page.contains("<input type=checkbox name='user_category_1' id='user_category_1' value='1' checked>Drilling</label>"));
    assert!(page.contains("<input type=checkbox name='user_category_2' id='user_category_2' value='1'>Tool Storage & Shipping</label>"));
    assert!(page.contains("<b>Salesforce Integration</b>"));
    assert!(page.contains("<li id='' class='scs_jquery_tabs'></li>"));
    assert!(page.contains("value='Update' onclick=\"javascript:fn_action(this,'update','10','');\""));
}

#[tokio::test]
async fn an_update_saves_the_account_its_categories_and_the_approval_bypass() {
    let h = harness().await;
    user_seed(&h.pool).await;
    let admin = person(&h.pool, 1, "Administrator").await;
    account(&h.pool, 10, "Customer", "Pending", "198.51.100.10").await;
    exec(&h.pool, "INSERT INTO user_category (user_id, category_id) VALUES (10, 1)").await;
    exec(&h.pool, "INSERT INTO log (ip, date_time, user_id, email, type, subtype) VALUES ('198.51.100.10', 5, 10, 'Buyer@Example.com', 'User', 'Signup')").await;
    let form = user_form(&[("record_id", "10"), ("revenue", "1M")]);
    let (status, _, page) = submit(&h, "/user.php", &admin, &form).await;
    assert_eq!(status, StatusCode::OK);
    assert!(page.contains("<span class=message2><br>Record maintained</span>"), "{page}");
    let row: (String, String, String, String, String, f64, i64, String) =
        sqlx::query_as(r#"SELECT ip, company_name, state, status, price_type, price_pct, catalog, revenue FROM "user" WHERE id = 10"#).fetch_one(&h.pool).await.unwrap();
    assert_eq!(row, ("198.51.100.40".into(), "Example Fixtures".into(), "MI".into(), "Active".into(), "discount".into(), 0.15, 1, "1M".into()));
    let blocked: Vec<i64> = sqlx::query_scalar("SELECT category_id FROM user_category WHERE user_id = 10").fetch_all(&h.pool).await.unwrap();
    assert_eq!(blocked, [2]);
    let bypass: Vec<String> = sqlx::query_scalar("SELECT email FROM captcha_bypass").fetch_all(&h.pool).await.unwrap();
    assert_eq!(bypass, ["@example.com"]);
}

#[tokio::test]
async fn errors_keep_the_form_and_change_nothing() {
    let h = harness().await;
    user_seed(&h.pool).await;
    let admin = person(&h.pool, 1, "Administrator").await;
    account(&h.pool, 10, "Customer", "Pending", "198.51.100.10").await;
    let form: Vec<(&str, &str)> = user_form(&[("record_id", "10"), ("cad", "1"), ("type", "Super User")]).into_iter().filter(|(k, v)| *k != "catalog" && !(*k == "type" && *v == "Customer")).collect();
    let (_, _, page) = submit(&h, "/user.php", &admin, &form).await;
    assert!(page.contains("<span style='background-color: #ff0000'><input type=checkbox name='cad' id='cad' value='1' checked style='border: 1px solid red !important;'></span>\n CAD requires catalog access"));
    assert!(page.contains("Pricing requires catalog access"));
    assert!(page.contains("Correct 3 error(s)"));
    let kind: String = sqlx::query_scalar(r#"SELECT "type" FROM "user" WHERE id = 10"#).fetch_one(&h.pool).await.unwrap();
    assert_eq!(kind, "Customer");
}

#[tokio::test]
async fn deleting_takes_a_post_and_returns_go_back_to_the_site_only() {
    let h = harness().await;
    user_seed(&h.pool).await;
    let admin = person(&h.pool, 1, "Administrator").await;
    account(&h.pool, 10, "Customer", "Pending", "198.51.100.10").await;
    account(&h.pool, 11, "Super User", "Pending", "198.51.100.11").await;
    let count = || async { sqlx::query_scalar::<_, i64>(r#"SELECT count(*) FROM "user""#).fetch_one(&h.pool).await.unwrap() };
    send(&h.app, get("/user.php?action=delete&record_id=10", Some(&admin))).await;
    assert_eq!(count().await, 3);
    let (_, _, page) = submit(&h, "/user.php", &admin, &[("action", "delete"), ("record_id", "11")]).await;
    assert!(!page.contains("deleted"));
    assert_eq!(count().await, 3);
    let (_, _, page) = submit(&h, "/user.php", &admin, &[("action", "delete"), ("record_id", "10")]).await;
    assert!(page.contains("<br>IP address 198.51.100.10 deleted"));
    assert_eq!(count().await, 2);

    account(&h.pool, 12, "Customer", "Pending", "198.51.100.12").await;
    let (status, headers, _) = submit(&h, "/user.php", &admin, &user_form(&[("record_id", "12"), ("return_url", "/dashboard.php?tab=Pending")])).await;
    assert_eq!(status, StatusCode::FOUND);
    assert_eq!(headers[header::LOCATION], "/dashboard.php?tab=Pending");
    let (status, _, page) = submit(&h, "/user.php", &admin, &user_form(&[("record_id", "12"), ("return_url", "https://example.com/")])).await;
    assert_eq!(status, StatusCode::OK);
    assert!(page.contains("Record maintained"));
}
