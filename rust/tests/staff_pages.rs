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
async fn only_a_post_that_writes_needs_the_form_token() {
    let h = harness().await;
    user_seed(&h.pool).await;
    let admin = person(&h.pool, 1, "Administrator").await;
    account(&h.pool, 10, "Customer", "Pending", "198.51.100.10").await;
    let (status, _, page) = send(&h.app, post("/user.php", &admin, &[("action", "list"), ("report_option_field", "ip"), ("report_option_value", "198.51.100.")])).await;
    assert_eq!(status, StatusCode::OK);
    assert!(page.contains("<br>1 Records Found"));
    let (status, _, page) = send(&h.app, post("/user.php", &admin, &[("action", "maintain"), ("record_id", "10")])).await;
    assert_eq!(status, StatusCode::OK);
    assert!(page.contains("value='Test company 10'"));
    for writes in [user_form(&[("record_id", "10")]), vec![("action", "delete"), ("record_id", "10")]] {
        let (status, _, _) = send(&h.app, post("/user.php", &admin, &writes)).await;
        assert_eq!(status, StatusCode::FORBIDDEN);
    }
    let row: (String, String) = sqlx::query_as(r#"SELECT ip, status FROM "user" WHERE id = 10"#).fetch_one(&h.pool).await.unwrap();
    assert_eq!(row, ("198.51.100.10".into(), "Pending".into()));
    let (status, _, _) = send(&h.app, post("/inventory.php", &admin, &[("action", "delete"), ("record_id", "1")])).await;
    assert_eq!(status, StatusCode::FORBIDDEN);
    let (status, _, _) = send(&h.app, post("/inventory.php", &admin, &[("action", "list"), ("report_subcategory_id", "0")])).await;
    assert_eq!(status, StatusCode::OK);
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

const PARAMS: &str = r#"a:2:{i:0;O:27:"subcategory_parameter_class":5:{s:2:"id";i:0;s:4:"name";s:8:"Material";s:4:"type";s:12:"Alphanumeric";s:7:"numeric";b:0;s:7:"decimal";i:0;}i:1;O:27:"subcategory_parameter_class":5:{s:2:"id";i:1;s:4:"name";s:6:"Length";s:4:"type";s:9:"Decimal:2";s:7:"numeric";b:1;s:7:"decimal";s:1:"2";}}"#;

async fn inventory_seed(pool: &SqlitePool) {
    exec(pool, "INSERT INTO category (id, name, active) VALUES (5, 'Alignment', 1)").await;
    sqlx::query("INSERT INTO subcategory (id, category_id, name, status, parameters, download) VALUES (50, 5, 'Test Pins', 'Active', ?1, 'a:2:{i:0;s:10:\"Data Sheet\";i:1;s:0:\"\";}'), (51, 5, 'Old Pins', 'Inactive', 'a:0:{}', '')")
        .bind(PARAMS)
        .execute(pool)
        .await
        .unwrap();
    exec(pool, "INSERT INTO language (code, name, local_name, priority) VALUES ('EN', 'English', 'English', 1), ('FR', 'French', 'Français', 2)").await;
    exec(pool, "INSERT INTO inventory (id, sku, category_id, subcategory_id, active, sort_order, parameter0, nparameter1, pricing) VALUES (700, 'TP-100', 5, 50, 1, 1, 'Steel', 1.5, 'a:1:{i:0;O:23:\"inventory_pricing_class\":2:{s:5:\"price\";d:12.5;s:9:\"qty_break\";i:0;}}'), (701, 'TP-200', 5, 50, 0, 2, 'Brass', 0, 'a:0:{}')").await;
    exec(pool, "INSERT INTO content (record_type, record_id, record_item, language_code, description) VALUES ('inventory', 700, 'overview', 'FR', 'Aperçu')").await;
}

fn b64(s: &str) -> String {
    use base64::Engine;
    base64::engine::general_purpose::STANDARD.encode(s)
}

#[tokio::test]
async fn the_inventory_list_shows_a_subcategorys_parts_and_parameters() {
    let h = harness().await;
    inventory_seed(&h.pool).await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let (_, _, page) = submit(&h, "/inventory.php", &admin, &[("action", "list"), ("report_subcategory_id", "50")]).await;
    assert!(page.contains("enctype='multipart/form-data'"));
    assert!(page.contains("<optgroup label='Alignment'>\n<option value='51'>Old Pins (Inactive)</option>\n<option value='50' selected>Test Pins</option>\n</optgroup>"));
    assert!(page.contains("<tr><th>SKU</th><th>Material</th><th>Length</th><th>Del?</th></tr><tr><td colspan=4>"));
    assert!(page.contains(">TP-100</a></td><td>Steel</td><td></td><td class=center></td></tr>"));
    assert!(page.contains("onclick=\"javascript:fn_action(this,'delete','701','TP-200');\""));
}

#[tokio::test]
async fn a_sku_search_opens_the_part() {
    let h = harness().await;
    inventory_seed(&h.pool).await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let (_, _, page) = submit(&h, "/inventory.php", &admin, &[("ajax_inventory_sku", " tp-100|Test pin ")]).await;
    assert!(page.contains("<caption class='left'>Alignment: Test Pins</caption>"));
    assert!(page.contains(&format!("name='{}' id='{}' size='20' maxlength='20' value='1.5'", b64("nparameter\t1"), b64("nparameter\t1"))));
    assert!(page.contains(&format!("${}", "<input type=text name='cHJpY2UJMA==' id='cHJpY2UJMA==' size='10' maxlength='10' value='12.5000'>")));
    assert!(page.contains(&format!("<textarea name='{}' id='{}' cols='60' rows='6'>Aperçu</textarea>", b64("overview\tdescription\tFR"), b64("overview\tdescription\tFR"))));
    assert!(page.contains("<td width='40%'>1. Data Sheet</td>"));
    assert!(page.contains("value='Update' onclick=\"javascript:fn_action(this,'update','700','');\""));
    let (_, _, page) = submit(&h, "/inventory.php", &admin, &[("ajax_inventory_sku", "NOPE")]).await;
    assert!(page.contains("name='ajax_inventory_sku' id='ajax_inventory_sku' size='40' maxlength='40' value='NOPE' style='border: 1px solid red !important;'"));
}

#[tokio::test]
async fn an_inventory_update_saves_the_part_its_breaks_and_descriptions() {
    let h = harness().await;
    inventory_seed(&h.pool).await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let (p0, p1, q1, m, n1) = (b64("price\t0"), b64("price\t1"), b64("qty_break\t1"), b64("parameter\t0"), b64("nparameter\t1"));
    let (fr, en) = (b64("overview\tdescription\tFR"), b64("features\tdescription\tEN"));
    let fields = [
        ("action", "update"), ("record_id", "700"), ("subcategory_id", "50"), ("sku", "tp-100b"), ("sort_order", "3"), ("leadtime_option", "In Stock"),
        ("active", "1"), ("competitor_sku", "zz-9\nab-1"), (p0.as_str(), "1,200.00"), (p1.as_str(), "1100"), (q1.as_str(), "10"), (m.as_str(), "Steel"),
        (n1.as_str(), "2.345"), (fr.as_str(), ""), (en.as_str(), "Hardened"),
    ];
    let (_, _, page) = submit(&h, "/inventory.php", &admin, &fields).await;
    assert!(page.contains("<br>Record maintained"), "{page}");
    let row: (String, i64, String, String, f64) = sqlx::query_as("SELECT sku, sort_order, competitor_sku, pricing, nparameter1 FROM inventory WHERE id = 700").fetch_one(&h.pool).await.unwrap();
    assert_eq!(row.0, "TP-100B");
    assert_eq!(row.1, 3);
    assert_eq!(row.2, "AB-1,ZZ-9");
    assert_eq!(row.3, r#"a:2:{i:0;O:23:"inventory_pricing_class":2:{s:5:"price";d:1200;s:9:"qty_break";i:0;}i:1;O:23:"inventory_pricing_class":2:{s:5:"price";d:1100;s:9:"qty_break";d:10;}}"#);
    assert_eq!(row.4, 2.35);
    let contents: Vec<(String, String, String)> = sqlx::query_as("SELECT language_code, record_item, name FROM content WHERE record_id = 700 ORDER BY record_item").fetch_all(&h.pool).await.unwrap();
    assert_eq!(contents, [("EN".into(), "download:0".into(), "Data Sheet".into()), ("EN".into(), "features".into(), "".into())]);

    let rising = [("action", "update"), ("record_id", "700"), ("subcategory_id", "50"), ("sku", "TP-100B"), (p0.as_str(), "10"), (p1.as_str(), "11"), (q1.as_str(), "0")];
    let (_, _, page) = submit(&h, "/inventory.php", &admin, &rising).await;
    assert!(page.contains("Correct 2 error(s)"));
    let (_, _, page) = submit(&h, "/inventory.php", &admin, &[("action", "update"), ("record_id", "700"), ("subcategory_id", "50"), ("sku", "TP-200")]).await;
    assert!(page.contains("Duplicate entry"));
}

#[tokio::test]
async fn deleting_a_part_takes_its_content_with_it() {
    let h = harness().await;
    inventory_seed(&h.pool).await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let (_, _, page) = submit(&h, "/inventory.php", &admin, &[("action", "delete"), ("record_id", "700")]).await;
    assert!(page.contains("<br>SKU TP-100 deleted"));
    let left: i64 = sqlx::query_scalar("SELECT count(*) FROM content WHERE record_id = 700").fetch_one(&h.pool).await.unwrap();
    assert_eq!(left, 0);
}

fn multipart(path: &str, cookie: &str, fields: &[(&str, &str)], file: (&str, &str, &str)) -> Request<Body> {
    let mut body = String::new();
    for (k, v) in fields {
        body.push_str(&format!("--XB\r\nContent-Disposition: form-data; name=\"{k}\"\r\n\r\n{v}\r\n"));
    }
    body.push_str(&format!("--XB\r\nContent-Disposition: form-data; name=\"{}\"; filename=\"{}\"\r\nContent-Type: text/plain\r\n\r\n{}\r\n--XB--\r\n", file.0, file.1, file.2));
    Request::builder()
        .method(Method::POST)
        .uri(path)
        .header(header::COOKIE, cookie)
        .header(header::CONTENT_TYPE, "multipart/form-data; boundary=XB")
        .body(Body::from(body))
        .unwrap()
}

#[tokio::test]
async fn parts_without_a_prj_link_are_listed_by_subcategory() {
    let h = harness().await;
    inventory_seed(&h.pool).await;
    exec(&h.pool, "INSERT INTO inventory (id, sku, subcategory_id, prj) VALUES (702, 'ZZ-1', 99, ''), (703, 'TP-300', 50, 'prjCatalog=x')").await;
    exec(&h.pool, "UPDATE inventory SET prj = '' WHERE id IN (700, 701)").await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let (status, _, page) = send(&h.app, get("/inventory_missing_prj.php", Some(&admin))).await;
    assert_eq!(status, StatusCode::OK);
    assert!(page.contains("<div class='accordion'>\n"));
    assert!(page.contains("<h3>Alignment: Test Pins (2)</h3>\n<div>\nTP-100<br>\nTP-200</div>\n"));
    assert!(page.contains("<h3>:  (1)</h3>\n<div>\nZZ-1</div>\n"), "{page}");
    assert!(!page.contains("TP-300"));
}

#[tokio::test]
async fn a_prj_import_is_reviewed_then_applied() {
    let h = harness().await;
    inventory_seed(&h.pool).await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let (_, _, page) = send(&h.app, get("/inventory_prj.php", Some(&admin))).await;
    let token = csrf_of(&page);
    let export = "Name;a;b;Part;URL\n;;;TP-100_1;https://example.com/p?prjCatalog=h&prjNode=n&prjName=TP&values=v\n;;;NO-SUCH;https://example.com/p?prjCatalog=h&prjNode=n&prjName=NS&values=v\n";
    let (_, _, page) = send(&h.app, multipart("/inventory_prj.php", &admin, &[("csrf", &token), ("action", "upload")], ("prj", "export.csv", export))).await;
    assert!(page.contains("<br>Review Selection"));
    assert!(page.contains("<td>Unique records:</td>\n<td>2</td>"));
    let start = page.find("name='prj_rows' id='prj_rows' value='").unwrap() + "name='prj_rows' id='prj_rows' value='".len();
    let rows = page[start..].split('\'').next().unwrap().replace("&quot;", "\"").replace("&amp;", "&");
    let (_, _, page) = send(&h.app, post("/inventory_prj.php", &admin, &[("csrf", &token), ("action", "update"), ("filename", "export.csv"), ("prj_rows", &rows)])).await;
    assert!(page.contains("<h3>Changed (1)</h3>\n<div>TP-100</div>\n<h3>Missing/Invalid SKU (1)</h3>\n<div>NO-SUCH</div>\n"), "{page}");
    let prj: String = sqlx::query_scalar("SELECT prj FROM inventory WHERE id = 700").fetch_one(&h.pool).await.unwrap();
    assert_eq!(prj, "prjCatalog=h&prjNode=n&prjName=TP&values=v");
}

#[tokio::test]
async fn a_price_increase_raises_every_break_of_the_chosen_subcategories() {
    let h = harness().await;
    inventory_seed(&h.pool).await;
    exec(&h.pool, "UPDATE subcategory SET output = '' WHERE id = 50").await;
    exec(&h.pool, "UPDATE inventory SET pricing = 'a:2:{i:0;O:23:\"inventory_pricing_class\":2:{s:5:\"price\";d:10;s:9:\"qty_break\";i:0;}i:1;O:23:\"inventory_pricing_class\":2:{s:5:\"price\";d:9.5;s:9:\"qty_break\";d:5;}}' WHERE id = 700").await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let (_, _, page) = send(&h.app, get("/inventory_increase.php", Some(&admin))).await;
    assert!(page.contains("<p class='message left'>Inventory Price Increase<span class=message2><br>Revised 12/19/2023</span></p>"));
    assert!(page.contains("<input type=hidden name='count_subcategory_50' class='count' id='count_subcategory_50' value='2'>\n<tr><td rowspan=1><input type=checkbox class='subcategory_all' name='category_5' id='category_5' value='1' onclick=\"javascript:select_group('category_5',this.checked);\"> Alignment</td>"));
    assert!(page.contains("<td class=right>2</td><td>Never</td></tr>"));
    let (_, _, page) = submit(&h, "/inventory_increase.php", &admin, &[("action", "percent"), ("percent", "10"), ("subcategory_50", "1")]).await;
    assert!(page.contains("<br><br>Subcategories: 1<br>Increase: 10.00%<br>SKUs updated: 1 SKUs skipped: 1<br>Elapsed time: "), "{page}");
    let pricing: String = sqlx::query_scalar("SELECT pricing FROM inventory WHERE id = 700").fetch_one(&h.pool).await.unwrap();
    assert!(pricing.contains("d:11;") && pricing.contains("d:10.45;"), "{pricing}");
    let stamped: i64 = sqlx::query_scalar("SELECT price_increase FROM subcategory WHERE id = 50").fetch_one(&h.pool).await.unwrap();
    assert!(stamped > 0);
    let logged: String = sqlx::query_scalar("SELECT subtype FROM log WHERE type = 'Import Table'").fetch_one(&h.pool).await.unwrap();
    assert_eq!(logged, "Price Increase: Percent");
}

#[tokio::test]
async fn subcategories_are_reordered_in_the_order_posted() {
    let h = harness().await;
    inventory_seed(&h.pool).await;
    exec(&h.pool, "INSERT INTO subcategory (id, category_id, name, status, sort_order) VALUES (52, 6, 'Elsewhere', 'Active', 1)").await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let (_, _, page) = send(&h.app, get("/subcategory_order.php", Some(&admin))).await;
    assert!(page.contains("<p>Category: <select name='report_category_id' id='report_category_id' onchange=\"javascript:fn_action('maintain');\">\n<option></option>\n<option value='5'>Alignment</option>"));
    let (_, _, page) = submit(&h, "/subcategory_order.php", &admin, &[("action", "maintain"), ("report_category_id", "5")]).await;
    assert!(page.contains("<p>Category: <b>Alignment</b><br>Click and drag to desired order</p>\n<ol class='sortable large'>\n<li class='ui-state-default'><input type=hidden name='subcategory_51' id='subcategory_51' value='51'>\nOld Pins</li>"));
    let (_, _, page) = submit(&h, "/subcategory_order.php", &admin, &[("action", "update"), ("report_category_id", "5"), ("subcategory_50", "50"), ("subcategory_51", "51"), ("subcategory_52", "52")]).await;
    assert!(page.contains("<br>3 records updated in category Alignment"));
    let orders: Vec<(i64, i64)> = sqlx::query_as("SELECT id, sort_order FROM subcategory ORDER BY id").fetch_all(&h.pool).await.unwrap();
    assert_eq!(orders, [(50, 5), (51, 10), (52, 1)]);
}

#[tokio::test]
async fn subcategories_sharing_a_project_file_are_listed_together() {
    let h = harness().await;
    inventory_seed(&h.pool).await;
    exec(&h.pool, "UPDATE subcategory SET prj = 'info=harcourt/a/pins.prj' WHERE id = 50").await;
    exec(&h.pool, "UPDATE subcategory SET prj = 'harcourt/b/pins.prj' WHERE id = 51").await;
    exec(&h.pool, "INSERT INTO subcategory (id, category_id, name, status, prj) VALUES (53, 5, 'Alone', 'Active', 'harcourt/c/alone.prj')").await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let (_, _, page) = send(&h.app, get("/subcategory_prj.php", Some(&admin))).await;
    assert!(page.contains("<tbody><tr><td rowspan=2>pins.prj</td><td class=right>51</td><td>Old Pins</td><td></td><td>harcourt/b/pins.prj</td></tr><tr><td class=right>50</td><td>Test Pins</td><td>TP-100</td><td>harcourt/a/pins.prj</td></tr></tbody></table>"));
    assert!(!page.contains("alone.prj"));
}

async fn portal_seed(pool: &SqlitePool) {
    exec(pool, "INSERT INTO portal (id, name, active) VALUES (3, 'Example Aero', 1), (4, 'Example Auto', 1)").await;
    exec(pool, "INSERT INTO portalcategory (id, portal_id, name, output_order, subcategory_id, active) VALUES (30, 3, 'Fixtures', 1, 50, 0), (31, 4, 'Gauges', 1, 0, 1)").await;
    exec(pool, "INSERT INTO portalitem (id, portal_id, portalcategory_id, name) VALUES (40, 3, 30, 'Item A'), (41, 30, 99, 'Unrelated')").await;
    exec(pool, "INSERT INTO portalcontent (id, portal_id, name, form) VALUES (60, 0, 'Banner', 'Category HTML'), (61, 3, 'Aero head', 'Category HTML')").await;
    exec(pool, "INSERT INTO content (record_type, record_id, language_code, name, description) VALUES ('portalcategory', 30, 'EN', 'Fixtures', 'Old headline')").await;
}

#[tokio::test]
async fn portal_categories_are_listed_and_edited_per_language() {
    let h = harness().await;
    inventory_seed(&h.pool).await;
    portal_seed(&h.pool).await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let (_, _, page) = send(&h.app, get("/portalcategory.php", Some(&admin))).await;
    assert!(page.contains("<h3>Example Aero</h3>\n<div><table class='standard border'>"));
    assert!(page.contains("<td><a href=\"javascript:fn_action(this,'maintain','30','3');\" >Fixtures</a></td>\n\n<td>Test Pins</td>\n\n<td class=center><input type=radio value='30' id='delete_30'"));
    let (_, _, page) = submit(&h, "/portalcategory.php", &admin, &[("action", "maintain"), ("record_id", "30"), ("report_portal_id", "3")]).await;
    assert!(page.contains("<td width='70%'><b>Example Aero</b></td>"));
    assert!(page.contains("<select name='headline_portalcontent_id' id='headline_portalcontent_id'>\n<option></option>\n<option value='61'>Aero head</option>\n<option value='60'>Banner (Generic)</option>\n</select>"));
    let (en_name, en_desc, fr_name, fr_desc) = (b64("name\tEN"), b64("description\tEN"), b64("name\tFR"), b64("description\tFR"));
    let form = [("action", "update"), ("record_id", "30"), ("report_portal_id", "3"), (en_name.as_str(), "Fixtures 2"), (en_desc.as_str(), "New"), (fr_name.as_str(), ""), (fr_desc.as_str(), "Titre"), ("output_order", "4"), ("active", "1")];
    let (_, _, page) = submit(&h, "/portalcategory.php", &admin, &form).await;
    assert!(page.contains("Correct 1 error(s)"));
    let mut fixed = form.to_vec();
    fixed[5] = (fr_name.as_str(), "Fixations");
    let (_, _, page) = submit(&h, "/portalcategory.php", &admin, &fixed).await;
    assert!(page.contains("<br>Record maintained"));
    let row: (String, String, i64) = sqlx::query_as("SELECT name, headline, output_order FROM portalcategory WHERE id = 30").fetch_one(&h.pool).await.unwrap();
    assert_eq!(row, ("Fixtures 2".into(), "New".into(), 4));
    let names: Vec<String> = sqlx::query_scalar("SELECT name FROM content WHERE record_type = 'portalcategory' AND record_id = 30 ORDER BY language_code").fetch_all(&h.pool).await.unwrap();
    assert_eq!(names, ["Fixtures 2", "Fixations"]);
    let (_, _, page) = submit(&h, "/portalcategory.php", &admin, &[("action", "delete"), ("record_id", "31")]).await;
    assert!(page.contains("<br>Portal Category Gauges deleted"));
    let items: Vec<i64> = sqlx::query_scalar("SELECT id FROM portalitem ORDER BY id").fetch_all(&h.pool).await.unwrap();
    assert_eq!(items, [40, 41]);
}

#[tokio::test]
async fn portal_items_are_listed_by_category_and_a_new_one_keeps_its_portal() {
    let h = harness().await;
    inventory_seed(&h.pool).await;
    portal_seed(&h.pool).await;
    exec(&h.pool, "INSERT INTO portalcategory (id, portal_id, name, active, subcategory_id) VALUES (32, 3, 'Links', 1, 0)").await;
    exec(&h.pool, "INSERT INTO portalcontent (id, portal_id, name, form) VALUES (62, 3, 'Datasheet', 'Link')").await;
    exec(&h.pool, "INSERT INTO content (record_type, record_id, language_code, name) VALUES ('portalcontent', 62, 'EN', 'sheet.pdf')").await;
    exec(&h.pool, "UPDATE portalitem SET pdf_portalcontent_id = 62, active = 1 WHERE id = 40").await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let (_, _, page) = send(&h.app, get("/portalitem.php", Some(&admin))).await;
    assert!(page.contains("<tr><td colspan=6><b>Fixtures</b></td></tr>\n<tr >\n<td><a href=\"javascript:fn_action(this,'maintain','40','3');\" >Item A</a></td>\n<td><a href=\"/scs_file.php?content_id="));
    assert!(page.contains("target='portal_preview'>sheet.pdf</a></td>"));
    let (_, _, page) = submit(&h, "/portalitem.php", &admin, &[("action", "maintain"), ("record_id", "0"), ("report_portal_id", "3")]).await;
    assert!(page.contains("<input type=hidden name='report_portal_id' id='report_portal_id' value='3'>"));
    assert!(page.contains("<td width='80%'><b>Example Aero</b></td>"));
    assert!(page.contains("<select name='portalcategory_id' id='portalcategory_id'>\n<option></option>\n<option value='32'>Links</option>\n</select>"));
    let en = b64("name\tEN");
    let (_, _, page) = submit(&h, "/portalitem.php", &admin, &[("action", "update"), ("record_id", "0"), ("report_portal_id", "3"), (en.as_str(), "Item B"), ("portalcategory_id", "32"), ("other_portalcontent_id", "62"), ("other_name", "")]).await;
    assert!(page.contains("Correct 1 error(s)"));
    let (_, _, page) = submit(&h, "/portalitem.php", &admin, &[("action", "update"), ("record_id", "0"), ("report_portal_id", "3"), (en.as_str(), "Item B"), ("portalcategory_id", "32"), ("pdf_portalcontent_id", "62"), ("active", "1")]).await;
    assert!(page.contains("<br>Record maintained"));
    let saved: (i64, i64, i64) = sqlx::query_as("SELECT portal_id, portalcategory_id, pdf_portalcontent_id FROM portalitem WHERE name = 'Item B'").fetch_one(&h.pool).await.unwrap();
    assert_eq!(saved, (3, 32, 62));
}

#[tokio::test]
async fn portals_keep_their_headlines_and_subcategories() {
    let h = harness().await;
    inventory_seed(&h.pool).await;
    portal_seed(&h.pool).await;
    exec(&h.pool, "UPDATE subcategory SET portal = '^4^', status = 'Active' WHERE id IN (50, 51)").await;
    exec(&h.pool, "UPDATE portal SET active = 0 WHERE id = 4").await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let (_, _, page) = send(&h.app, get("/portal.php", Some(&admin))).await;
    assert!(page.contains("<p class='message left'>Customer Portal Manager (03/28/2025)</p>"));
    assert!(page.contains("<input type=hidden name='record_id' id='record_id'>\n</tr></tr><table class='standard border tablesorter'>"));
    assert!(page.contains("onclick=\"javascript:fn_action('delete','4','Example Auto');\""));
    let (_, _, page) = submit(&h, "/portal.php", &admin, &[("action", "maintain"), ("record_id", "4")]).await;
    assert!(page.contains("<div id='standard_div' class='standard_div' style='display:none;'>"));
    assert!(page.contains("<input type=checkbox class='subcategory' name='category_5' id='category_5' value='1' onclick=\"javascript:fn_subcategory(this);\" checked>"));
    let (en, fr, s50) = ("ZGVzY3JpcHRpb24JRU4", "ZGVzY3JpcHRpb24JRlI", "c3ViY2F0ZWdvcnkJNTA");
    let (_, _, page) = submit(&h, "/portal.php", &admin, &[("action", "update"), ("record_id", "4"), ("name", "Example Aero"), (en, "x")]).await;
    assert!(page.contains("!important;'>\n Duplicate name"), "{page}");
    let (_, _, _) = submit(&h, "/portal.php", &admin, &[("action", "update"), ("record_id", "4"), ("name", "Example Auto"), ("active", "1"), (en, "Welcome"), (fr, ""), (s50, "1")]).await;
    let portals: Vec<(i64, String)> = sqlx::query_as("SELECT id, portal FROM subcategory WHERE id IN (50, 51) ORDER BY id").fetch_all(&h.pool).await.unwrap();
    assert_eq!(portals, [(50, "^4^".to_string()), (51, "^".to_string())]);
    let headline: String = sqlx::query_scalar("SELECT description FROM content WHERE record_type = 'portal' AND record_id = 4").fetch_one(&h.pool).await.unwrap();
    assert_eq!(headline, "Welcome");
}
