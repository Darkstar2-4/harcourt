//! The staff pages through the router, against a private in-memory database with synthetic accounts and rows.

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

async fn person(pool: &SqlitePool, id: i64, role: &str) -> String {
    account(pool, id, role, "Active").await
}

async fn account(pool: &SqlitePool, id: i64, role: &str, status: &str) -> String {
    sqlx::query(r#"INSERT INTO "user" (id, status, "type", company_name) VALUES (?1, ?2, ?3, ?4)"#)
        .bind(id)
        .bind(status)
        .bind(role)
        .bind(format!("Test company {id}"))
        .execute(pool)
        .await
        .unwrap();
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

async fn event(pool: &SqlitePool, user_id: i64, age_secs: i64, kind: &str, subtype: &str, comment: &str) {
    sqlx::query("INSERT INTO log (ip, date_time, user_id, email, type, subtype, sku, comment) VALUES ('192.0.2.1', ?1, ?2, 'someone@example.com', ?3, ?4, 'SKU-1', ?5)")
        .bind(db::unix_now() - age_secs)
        .bind(user_id)
        .bind(kind)
        .bind(subtype)
        .bind(comment)
        .execute(pool)
        .await
        .unwrap();
}

#[tokio::test]
async fn the_event_log_is_for_executives_and_above() {
    let h = harness().await;
    let internal = person(&h.pool, 4, "Internal").await;
    let exec = person(&h.pool, 2, "Executive").await;
    for cookie in [None, Some(internal.as_str())] {
        let (status, _, _) = send(&h.app, get("/scs_log.php", cookie)).await;
        assert_eq!(status, StatusCode::FOUND);
    }
    let (status, _, page) = send(&h.app, get("/scs_log.php", Some(&exec))).await;
    assert_eq!(status, StatusCode::OK);
    assert!(page.contains("<p class='message left'>Event Log (09/25/2025)</p>"));
    assert!(page.contains("<input type=text class='datepicker' name='from_date' id='from_date' size='10' maxlength='10' value='"));
    assert!(page.contains("<option value='VXNlcglMb2dpbg'>&nbsp;Login</option>"));
    assert!(!page.contains(">Results</a>"));
}

#[tokio::test]
async fn the_login_link_lists_one_accounts_recent_events_escaped() {
    let h = harness().await;
    let exec = person(&h.pool, 2, "Executive").await;
    person(&h.pool, 7, "Customer").await;
    event(&h.pool, 7, 3600, "Search", "SKU", "looked <b>for</b>\nwidgets").await;
    event(&h.pool, 7, 30 * 86400, "Search", "SKU", "too old").await;
    event(&h.pool, 2, 60, "User", "Login", "someone else").await;
    let (_, _, page) = send(&h.app, get("/scs_log.php?action=login&user_id=7", Some(&exec))).await;
    assert!(page.contains(">Results</a>"));
    assert!(page.contains("active: 1 }"));
    assert!(page.contains("<td>looked &lt;b>for&lt;/b><br>widgets</td>"));
    assert!(page.contains("<td>Test company 7</td>"));
    assert!(!page.contains("too old") && !page.contains("someone else"));
}

#[tokio::test]
async fn the_event_log_exports_csv_with_the_php_columns() {
    let h = harness().await;
    let exec = person(&h.pool, 2, "Executive").await;
    event(&h.pool, 2, 60, "User", "Login", "said \"hi\", twice").await;
    let (_, _, page) = send(&h.app, get("/scs_log.php", Some(&exec))).await;
    let csrf = csrf_of(&page);
    let fields = [("action", "list"), ("from_date", ""), ("thru_date", ""), ("type_subtype", ""), ("ajax_user_id", ""), ("output", "csv"), ("csrf", csrf.as_str())];
    let (status, headers, body) = send(&h.app, post("/scs_log.php", &exec, &fields)).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(headers[header::CONTENT_DISPOSITION], "attachment; filename=log.csv");
    let mut lines = body.lines();
    assert_eq!(lines.next(), Some("\"IP Address\",Email,\"Company Name\",Date/Time,Type,Subtype,SKU,Comment"));
    let row = lines.next().unwrap();
    assert!(row.starts_with("192.0.2.1,someone@example.com,\"Test company 2\","), "{row}");
    assert!(row.ends_with(",User,Login,SKU-1,\"said \"\"hi\"\", twice\""), "{row}");
    let (status, _, _) = send(&h.app, post("/scs_log.php", &exec, &fields[..6])).await;
    assert_eq!(status, StatusCode::FORBIDDEN);
}

#[tokio::test]
async fn the_dashboard_shows_reports_by_role_and_lands_on_the_asked_tab() {
    let h = harness().await;
    let exec = person(&h.pool, 2, "Executive").await;
    let admin = person(&h.pool, 1, "Administrator").await;
    sqlx::query(r#"INSERT INTO "user" (id, status, "type", company_name, ip, last_login) VALUES (9, 'Pending', 'Customer', 'Pending <Co>', '192.0.2.9', 0)"#)
        .execute(&h.pool)
        .await
        .unwrap();
    event(&h.pool, 9, 10, "User", "Signup", "Pat Example").await;
    sqlx::query("INSERT INTO orderhd (id, status, company_name, name, email, order_date) VALUES (41, 'Pending', 'Buyer Co', 'Sam', 'sam@example.com', 0)")
        .execute(&h.pool)
        .await
        .unwrap();
    event(&h.pool, 0, 10, "Initial", "Campaign", "spring").await;

    let (status, _, _) = send(&h.app, get("/dashboard.php", Some(&person(&h.pool, 3, "Internal").await))).await;
    assert_eq!(status, StatusCode::FOUND);

    let (status, _, page) = send(&h.app, get("/dashboard.php", Some(&exec))).await;
    assert_eq!(status, StatusCode::OK);
    assert!(page.contains("<p class='message left'>Executive Dashboard</p>"));
    assert!(page.contains(">Pending PO (1)</a>"));
    assert!(page.contains("<td class=center><a href=\"/staff/orders/41\" >41</a></td>"), "a pending order opens in the order viewer");
    assert!(!page.contains("Pending Access"));

    let (_, _, page) = send(&h.app, get("/dashboard.php?tab=Campaign", Some(&admin))).await;
    assert!(page.contains(">Pending Access (1)</a>"));
    assert!(page.contains("<td>Pending &lt;Co></td>\n<td>Pat Example</td>"));
    assert!(page.contains("href=\"/staff/access/9?return_url=%2Fdashboard.php%3Ftab%3DPending%2BAccess%2B%25281%2529\""));
    let tab_index = |title: &str| {
        let at = page.find(&format!(">{title}</a></li>")).unwrap();
        page[..at].matches("<li id='tab_").count() - 1
    };
    assert!(page.contains(&format!("active: {} }}", tab_index("Campaign"))));
    assert!(page.contains("<td>spring</td>"));
}

#[tokio::test]
async fn the_dashboard_sends_the_visitor_back_to_the_path_apache_ran() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    sqlx::query(r#"INSERT INTO "user" (id, status, "type", company_name, ip, last_login) VALUES (9, 'Pending', 'Customer', 'Pending Co', '192.0.2.9', 0)"#)
        .execute(&h.pool)
        .await
        .unwrap();
    let app = tower::ServiceBuilder::new().map_request(hxweb::visitor::apache_paths).service(h.app.clone());
    for (path, here) in [("/dashboard.php", "/dashboard.php"), ("/dashboard.php/", "/dashboard.php/"), ("//dashboard.php", "/dashboard.php")] {
        let res = app.clone().oneshot(get(path, Some(&admin))).await.unwrap();
        let page = String::from_utf8(res.into_body().collect().await.unwrap().to_bytes().to_vec()).unwrap();
        let back = here.replace('/', "%2F");
        assert!(page.contains(&format!("/staff/access/9?return_url={back}%3Ftab%3DPending%2BAccess%2B%25281%2529\"")), "{path}: {page}");
    }
}

#[tokio::test]
async fn the_company_page_shows_its_cms_document_in_the_accounts_language() {
    let h = harness().await;
    let customer = person(&h.pool, 8, "Customer").await;
    sqlx::query(r#"UPDATE "user" SET language_code = 'FR' WHERE id = 8"#).execute(&h.pool).await.unwrap();
    sqlx::query("INSERT INTO pages (id, record_type, name, url, document_id) VALUES (1, 'general', 'Company', '/company.php', 30)").execute(&h.pool).await.unwrap();
    for (lang, body) in [("EN", "<h1>About us</h1>"), ("FR", "<h1>Qui sommes-nous</h1>")] {
        sqlx::query("INSERT INTO content (record_type, record_id, language_code, content) VALUES ('document', 30, ?1, ?2)")
            .bind(lang)
            .bind(body.as_bytes())
            .execute(&h.pool)
            .await
            .unwrap();
    }
    let (status, _, page) = send(&h.app, get("/company.php", Some(&customer))).await;
    assert_eq!(status, StatusCode::OK);
    assert!(page.contains("<title>Harcourt | Engineering Productivity Solutions | Company</title>"));
    assert!(page.contains("\n<!-- Body Content -->\n<h1>Qui sommes-nous</h1>\n<!-- End Body Content -->\n"));
    let views: i64 = sqlx::query_scalar("SELECT count(*) FROM log WHERE type = 'Page' AND subtype = 'General' AND comment = 'Company'").fetch_one(&h.pool).await.unwrap();
    assert_eq!(views, 1);
    sqlx::query("UPDATE content SET filetype = 'html', html_content = '<h1>Who we are</h1>', content = NULL WHERE language_code = 'EN'").execute(&h.pool).await.unwrap();
    let (status, _, page) = send(&h.app, get("/company.php", None)).await;
    assert_eq!(status, StatusCode::OK, "every visit's session user was a Customer");
    assert!(page.contains("\n<!-- Body Content -->\n<h1>Who we are</h1>\n<!-- End Body Content -->\n"), "an HTML document is kept as text");
}

#[tokio::test]
async fn search_export_writes_the_four_index_files_and_a_private_log() {
    use std::os::unix::fs::PermissionsExt;
    let h = harness().await;
    for sql in [
        "INSERT INTO search (id, table_name, language_code, active, code, category_id, subcategory_id, inventory_id, ranking) VALUES (1, 'inventory', NULL, 1, 'AB-1', 2, 3, 4, 10)",
        "INSERT INTO subcategory (id, category_id, status) VALUES (3, 2, 'Active')",
        "INSERT INTO category (id, name) VALUES (2, 'Clamps')",
        "INSERT INTO content (record_type, record_id, record_item, language_code, name) VALUES ('subcategory', 3, '', 'EN', 'Toggle\tclamps'), ('category', 2, '', 'FR', 'Brides')",
        "INSERT INTO inventory (id, sku, subcategory_id) VALUES (4, 'AB-1', 3)",
    ] {
        sqlx::query(sql).execute(&h.pool).await.unwrap();
    }
    event(&h.pool, 0, 60, "Search", "SKU", "ab-1\nsecond line").await;
    let dir = std::env::temp_dir().join(format!("hx_search_export_{}", std::process::id()));
    let summary = hxweb::staff::jobs::search_export(&h.pool, &dir).await.unwrap();
    assert_eq!(summary, "rows.tsv: 1 rows\nfamilies.tsv: 1 rows\ncategories.tsv: 1 rows\nparts.tsv: 1 rows\n");
    let read = |f: &str| std::fs::read_to_string(dir.join(f)).unwrap();
    assert_eq!(read("rows.tsv"), "1\tinventory\t\t1\tAB-1\t2\t3\t4\t10\n");
    assert_eq!(read("families.tsv"), "3\t2\tActive\tToggle clamps\t\n");
    assert_eq!(read("categories.tsv"), "2\t\tBrides\n");
    assert_eq!(read("parts.tsv"), "4\tAB-1\t3\n");
    assert!(!dir.join("rows.tsv.tmp").exists());
    let log = dir.join("search.log");
    hxweb::staff::jobs::search_log(&h.pool, &log, db::unix_now()).await.unwrap();
    assert_eq!(std::fs::metadata(&log).unwrap().permissions().mode() & 0o777, 0o600);
    let text = read("search.log");
    assert!(text.starts_with("id\tdate_time\tuser_id\tip\tsubtype\tsku\tsubcategory_id\tc\n"));
    assert!(text.ends_with("\t0\t192.0.2.1\tSKU\tSKU-1\t0\tab-1 | second line\n"), "{text}");
    std::fs::remove_dir_all(&dir).unwrap();
}

#[tokio::test]
async fn search_utility_rebuilds_the_search_table_and_converges() {
    let h = harness().await;
    for sql in [
        "INSERT INTO category (id, name, sort_order, active) VALUES (2, 'Clamps', 5, 1), (3, 'Old', 1, 0)",
        "INSERT INTO subcategory (id, category_id, status, sort_order, search_words) VALUES (10, 2, 'Active', 7, 'toggle^tg^Toggle^riser'), (11, 2, 'Pending', 1, 'hidden')",
        "INSERT INTO content (record_type, record_id, record_item, language_code, name) VALUES ('category', 2, '', 'EN', 'Clamps &amp; Pins'), ('category', 3, '', 'EN', 'Old'), ('subcategory', 10, '', 'EN', 'Toggle'), ('subcategory', 11, '', 'EN', 'Hidden')",
        "INSERT INTO inventory (id, sku, category_id, subcategory_id, active, sort_order, competitor_sku) VALUES (20, 'TC-1', 2, 10, 1, 4, 'X-1,X-1'), (21, 'TC-2', 2, 11, 1, 0, '')",
    ] {
        sqlx::query(sql).execute(&h.pool).await.unwrap();
    }
    let summary = hxweb::staff::jobs::search_utility(&h.pool, db::unix_now()).await.unwrap();
    assert!(summary.starts_with("Subcategory: 1\nCategory Languages: 1\nSubcategory Languages: 1\nSearch Words: 3\nInventory: 1\nCompetitor SKU: 2\nPurge Duplicates: 2\n"), "{summary}");
    let rows = || async {
        sqlx::query_as::<_, (String, String, String, String, i64, i64, i64)>(
            "SELECT language_code, table_name, code, code_normalized, subcategory_id, inventory_id, ranking FROM search ORDER BY id",
        )
        .fetch_all(&h.pool)
        .await
        .unwrap()
    };
    let first = rows().await;
    let codes: Vec<(&str, &str)> = first.iter().map(|r| (r.1.as_str(), r.2.as_str())).collect();
    assert_eq!(codes, [("category", "Clamps Pins"), ("subcategory", "Toggle"), ("inventory", "TC-1"), ("search_words", "riser"), ("competitor_sku", "X-1")]);
    assert_eq!(first[4].3, "X1");
    hxweb::staff::jobs::search_utility(&h.pool, db::unix_now()).await.unwrap();
    let again: Vec<_> = rows().await.into_iter().map(|r| (r.1, r.2)).collect();
    assert_eq!(again, first.iter().map(|r| (r.1.clone(), r.2.clone())).collect::<Vec<_>>());
    let runs: i64 = sqlx::query_scalar("SELECT count(*) FROM log WHERE type = 'CRON' AND subtype = 'Search Utility'").fetch_one(&h.pool).await.unwrap();
    assert_eq!(runs, 2);
}

async fn pending(pool: &SqlitePool, id: i64, company: &str, email: &str) {
    sqlx::query(r#"INSERT INTO "user" (id, status, "type", company_name, ip, last_login) VALUES (?1, 'Pending', 'Customer', ?2, '192.0.2.9', 0)"#)
        .bind(id)
        .bind(company)
        .execute(pool)
        .await
        .unwrap();
    sqlx::query("INSERT INTO log (ip, date_time, user_id, email, type, subtype, comment) VALUES ('192.0.2.9', ?1, ?2, ?3, 'User', 'Signup', 'Pat Example')")
        .bind(db::unix_now())
        .bind(id)
        .bind(email)
        .execute(pool)
        .await
        .unwrap();
}

async fn status_of(pool: &SqlitePool, id: i64) -> String {
    sqlx::query_scalar(r#"SELECT status FROM "user" WHERE id = ?1"#).bind(id).fetch_one(pool).await.unwrap()
}

#[tokio::test]
async fn access_requests_are_for_administrators_inside_the_site_chrome() {
    let h = harness().await;
    pending(&h.pool, 9, "Pending Co", "buyer@example.com").await;
    let exec = person(&h.pool, 2, "Executive").await;
    for cookie in [None, Some(exec.as_str())] {
        let (status, headers, _) = send(&h.app, get("/staff/access/9", cookie)).await;
        assert_eq!(status, StatusCode::FOUND);
        assert_eq!(headers[header::LOCATION], "/scs_index.php");
    }
    let admin = person(&h.pool, 1, "Administrator").await;
    let (status, headers, page) = send(&h.app, get("/staff/access/9", Some(&admin))).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(headers[header::CACHE_CONTROL], "no-store");
    assert!(page.starts_with("<!DOCTYPE html>\n<html>\n<head>\n<title>Access Request</title>"), "{page}");
    assert!(page.contains("<div id='scs_body' >"));
    assert!(page.ends_with("</div> <!-- #scs_content -->\n</div> <!-- #scs_body --></body>\n</html>\n"));
    let (status, _, _) = send(&h.app, get("/staff/access/77", Some(&admin))).await;
    assert_eq!(status, StatusCode::NOT_FOUND);
}

#[tokio::test]
async fn an_administrator_approves_a_request_from_the_dashboards_link() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    pending(&h.pool, 9, "Pending <Co>", "Buyer@Example.com").await;
    let (_, _, dashboard) = send(&h.app, get("/dashboard.php", Some(&admin))).await;
    let link = "/staff/access/9?return_url=%2Fdashboard.php%3Ftab%3DPending%2BAccess%2B%25281%2529";
    assert!(dashboard.contains(&format!("href=\"{link}\"")), "{dashboard}");
    let (_, _, page) = send(&h.app, get(link, Some(&admin))).await;
    assert!(page.contains("<td width='30%'>Company</td><td width='70%'>Pending &lt;Co></td>"), "{page}");
    assert!(page.contains("<td width='30%'>Status</td><td width='70%'>Pending</td>"));
    assert!(page.contains("fn_action('approve');") && page.contains("fn_action('deny');"));
    assert!(page.contains("<input type=hidden name='return_url' id='return_url' value='/dashboard.php?tab=Pending+Access+%281%29'>"), "{page}");

    let csrf = csrf_of(&page);
    let approve = [("action", "approve"), ("return_url", "/dashboard.php?tab=Pending+Access+%281%29")];
    let (status, _, _) = send(&h.app, post("/staff/access/9", &admin, &approve)).await;
    assert_eq!(status, StatusCode::FORBIDDEN, "no form token");
    assert_eq!(status_of(&h.pool, 9).await, "Pending");

    let with_token: Vec<_> = approve.iter().copied().chain([("csrf", csrf.as_str())]).collect();
    let (status, headers, _) = send(&h.app, post("/staff/access/9", &admin, &with_token)).await;
    assert_eq!(status, StatusCode::FOUND);
    assert_eq!(headers[header::LOCATION], "/dashboard.php?tab=Pending+Access+%281%29");
    assert_eq!(status_of(&h.pool, 9).await, "Active");
    let bypass: String = sqlx::query_scalar("SELECT email FROM captcha_bypass").fetch_one(&h.pool).await.unwrap();
    assert_eq!(bypass, "@example.com");
    let audit: i64 = sqlx::query_scalar("SELECT count(*) FROM log WHERE type = 'Auth' AND subtype = 'access_approved' AND user_id = 1").fetch_one(&h.pool).await.unwrap();
    assert_eq!(audit, 1);

    let (_, _, page) = send(&h.app, get("/staff/access/9", Some(&admin))).await;
    assert!(page.contains("<td width='30%'>Status</td><td width='70%'>Active</td>") && !page.contains("fn_action('approve');"));
    let (_, _, page) = send(&h.app, post("/staff/access/9", &admin, &[("action", "deny"), ("csrf", csrf.as_str())])).await;
    assert!(page.contains("Already Active"));
    assert_eq!(status_of(&h.pool, 9).await, "Active", "only a pending request is decided");
}

#[tokio::test]
async fn a_denied_request_gets_no_bypass_and_no_one_decides_above_their_role() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    pending(&h.pool, 9, "Pending Co", "buyer@example.com").await;
    sqlx::query(r#"INSERT INTO "user" (id, status, "type", company_name) VALUES (6, 'Pending', 'Super User', 'Upstairs')"#).execute(&h.pool).await.unwrap();
    let (_, _, page) = send(&h.app, get("/staff/access/9", Some(&admin))).await;
    let csrf = csrf_of(&page);
    let (status, headers, _) = send(&h.app, post("/staff/access/9", &admin, &[("action", "deny"), ("csrf", csrf.as_str()), ("return_url", "https://elsewhere.example/")])).await;
    assert_eq!(status, StatusCode::FOUND);
    assert_eq!(headers[header::LOCATION], "/dashboard.php", "a return address off the site is not followed");
    assert_eq!(status_of(&h.pool, 9).await, "Denied");
    let bypasses: i64 = sqlx::query_scalar("SELECT count(*) FROM captcha_bypass").fetch_one(&h.pool).await.unwrap();
    assert_eq!(bypasses, 0);
    let (status, _, _) = send(&h.app, post("/staff/access/6", &admin, &[("action", "approve"), ("csrf", csrf.as_str())])).await;
    assert_eq!(status, StatusCode::NOT_FOUND);
    assert_eq!(status_of(&h.pool, 6).await, "Pending");
}

const DROPPED: [&str; 27] = [
    "/campaign_manager.php",
    "/hero_preview.php",
    "/inventory.php",
    "/inventory_increase.php",
    "/inventory_missing_prj.php",
    "/inventory_no_price.php",
    "/inventory_price.php",
    "/inventory_pricebook.php",
    "/inventory_prj.php",
    "/inventory_utility.php",
    "/inventory_xref.php",
    "/mailer_maintain.php",
    "/portal.php",
    "/portal_preview.php",
    "/portalcategory.php",
    "/portalcontent.php",
    "/portalitem.php",
    "/pricing_utility.php",
    "/scs_track_log.php",
    "/staff/remote-grants",
    "/subcategory.php",
    "/subcategory_order.php",
    "/subcategory_prj.php",
    "/subcategory_utility.php",
    "/user.php",
    "/user_email.php",
    "/user_utility.php",
];

#[tokio::test]
async fn the_dropped_staff_pages_answer_gone_or_not_found() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    for path in DROPPED {
        let (status, _, _) = send(&h.app, get(path, Some(&admin))).await;
        assert_eq!(status, StatusCode::NOT_FOUND, "{path} is not served");
        let (status, _, _) = send(&h.app, post(path, &admin, &[("action", "update")])).await;
        assert_eq!(status, StatusCode::NOT_FOUND, "{path} takes no post");
        let gone = hxweb::retired::guard(path).map(|r| r.status());
        assert!(gone == Some(StatusCode::GONE) || path.starts_with("/staff/"), "{path} answers 410 when no route claims it");
    }
}

#[tokio::test]
async fn every_dashboard_link_is_a_page_the_program_serves() {
    let h = harness().await;
    let superuser = person(&h.pool, 1, "Super User").await;
    let (_, _, page) = send(&h.app, get("/dashboard.php", Some(&superuser))).await;
    let links: Vec<&str> = page.split("<a href=\"").skip(1).filter_map(|s| s.split_once("\" class='nobr'").map(|(href, _)| href)).collect();
    assert!(links.len() >= 3 && links.contains(&"/staff/cms"), "{links:?}");
    let auth = Arc::new(Auth { pool: h.pool.clone(), secret: None, public_origin: "https://www.harcourt.co".into(), trusted_proxies: vec![], accounts: hxweb::accounts::Services::offline(hxweb::accounts::bot::Dns::fixed(&[], &[])) });
    let all = hxweb::routes::all(auth).layer(axum::extract::connect_info::MockConnectInfo(std::net::SocketAddr::from(([127, 0, 0, 1], 40000))));
    for link in links {
        let (status, _, _) = send(&all, get(link, Some(&superuser))).await;
        assert!(status != StatusCode::NOT_FOUND && status != StatusCode::GONE, "{link}: {status}");
    }
}

fn unescape(s: &str) -> String {
    s.replace("&lt;", "<").replace("&gt;", ">").replace("&quot;", "\"").replace("&#039;", "'").replace("&amp;", "&")
}

/// The fields a browser would post from the page's form: its inputs, ticked boxes, chosen options and text areas.
fn form_of(page: &str) -> Vec<(String, String)> {
    let input = regex::Regex::new(r"<input type=(text|hidden|checkbox) ([^>]*)>").unwrap();
    let name = regex::Regex::new(r"name='([^']*)'").unwrap();
    let value = regex::Regex::new(r"value='([^']*)'").unwrap();
    let select = regex::Regex::new(r"(?s)<select name='([^']*)'[^>]*>(.*?)</select>").unwrap();
    let chosen = regex::Regex::new(r"<option value='([^']*)' selected>").unwrap();
    let area = regex::Regex::new(r"(?s)<textarea name='([^']*)'[^>]*>(.*?)</textarea>").unwrap();
    let form = &page[page.find("<form name='scs_form'").expect("the form")..];
    let mut out = Vec::new();
    for c in input.captures_iter(form) {
        let attrs = &c[2];
        if &c[1] == "checkbox" && !attrs.contains(" checked") {
            continue;
        }
        let v = value.captures(attrs).map_or(String::new(), |v| unescape(&v[1]));
        out.push((name.captures(attrs).expect("a name")[1].to_string(), v));
    }
    for c in select.captures_iter(form) {
        out.push((c[1].to_string(), chosen.captures(&c[2]).map_or(String::new(), |v| unescape(&v[1]))));
    }
    for c in area.captures_iter(form) {
        out.push((c[1].to_string(), unescape(&c[2])));
    }
    out
}

/// The form of `page` with some fields changed, as posted.
fn changed(page: &str, set: &[(&str, &str)]) -> Vec<(String, String)> {
    let mut fields: Vec<(String, String)> = form_of(page).into_iter().filter(|(k, _)| !set.iter().any(|(n, _)| n == k)).collect();
    fields.extend(set.iter().map(|(k, v)| (k.to_string(), v.to_string())));
    fields
}

fn token_for(cookie: &str) -> String {
    let mut headers = HeaderMap::new();
    headers.insert(header::COOKIE, cookie.parse().unwrap());
    hxweb::staff::csrf::CsrfToken::for_request(&headers).unwrap().as_str().to_string()
}

async fn post_form(app: &Router, path: &str, cookie: &str, fields: &[(String, String)]) -> (StatusCode, HeaderMap, String) {
    let pairs: Vec<(&str, &str)> = fields.iter().map(|(k, v)| (k.as_str(), v.as_str())).collect();
    send(app, post(path, cookie, &pairs)).await
}

async fn count(pool: &SqlitePool, sql: &str) -> i64 {
    sqlx::query_scalar(sql).fetch_one(pool).await.unwrap()
}

#[tokio::test]
async fn the_table_admin_lists_creates_edits_and_deletes_a_row() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let (status, _, page) = send(&h.app, get("/staff/admin/currency", Some(&admin))).await;
    assert_eq!(status, StatusCode::OK);
    assert!(page.contains("<p class='message left'>Currencies</p>") && page.contains("<a href=\"/staff/admin/currency/new\">Add new</a>"), "{page}");

    let (_, _, page) = send(&h.app, get("/staff/admin/currency/new", Some(&admin))).await;
    let new = changed(&page, &[("code", "EUR"), ("name", "Euro"), ("plural", "Euros"), ("exchange_rate", "0.9"), ("active", "1")]);
    let (status, headers, _) = post_form(&h.app, "/staff/admin/currency/new", &admin, &new).await;
    assert_eq!(status, StatusCode::FOUND);
    let id: i64 = sqlx::query_scalar("SELECT id FROM currency WHERE code = 'EUR'").fetch_one(&h.pool).await.unwrap();
    assert_eq!(headers[header::LOCATION], format!("/staff/admin/currency/{id}?saved=1"));
    let (rate, active, symbol, revised): (f64, i64, Option<String>, i64) =
        sqlx::query_as("SELECT exchange_rate, active, symbol, update_timestamp FROM currency WHERE id = ?1").bind(id).fetch_one(&h.pool).await.unwrap();
    assert_eq!((rate, active, symbol), (0.9, 1, None));
    assert!(revised > 0, "a save stamps the revised time");

    let (_, _, list) = send(&h.app, get("/staff/admin/currency?q=eur", Some(&admin))).await;
    assert!(list.contains(&format!("<td class=center><a href=\"/staff/admin/currency/{id}\">{id}</a></td><td>EUR</td><td>Euro</td><td>Euros</td>")), "{list}");
    let (_, _, list) = send(&h.app, get("/staff/admin/currency?q=yen", Some(&admin))).await;
    assert!(!list.contains("Euros"));

    let path = format!("/staff/admin/currency/{id}");
    let (_, _, page) = send(&h.app, get(&format!("{path}?saved=1"), Some(&admin))).await;
    assert!(page.contains("<span class=message2><br>Saved</span>"));
    let (status, _, again) = post_form(&h.app, &path, &admin, &changed(&page, &[("exchange_rate", "lots")])).await;
    assert_eq!(status, StatusCode::OK);
    assert!(again.contains("Exchange rate ($1.00 USD buys) must be a number"), "{again}");
    assert!(again.contains("value='lots'"), "the form comes back as typed");
    let (status, _, _) = post_form(&h.app, &path, &admin, &changed(&page, &[("exchange_rate", "0.95"), ("active", "")])).await;
    assert_eq!(status, StatusCode::FOUND);
    let (rate, active, symbol): (f64, i64, Option<String>) = sqlx::query_as("SELECT exchange_rate, active, symbol FROM currency WHERE id = ?1").bind(id).fetch_one(&h.pool).await.unwrap();
    assert_eq!((rate, active, symbol), (0.95, 0, None), "an untouched empty field stays NULL");

    let (_, _, blank) = send(&h.app, get("/staff/admin/currency/new", Some(&admin))).await;
    let (status, _, dup) = post_form(&h.app, "/staff/admin/currency/new", &admin, &changed(&blank, &[("code", "EUR"), ("name", "Euro again")])).await;
    assert_eq!(status, StatusCode::OK);
    assert!(dup.contains("value='EUR' style='border: 1px solid red !important;'>\n<br>Another row already has this Code"), "{dup}");

    let (_, _, page) = send(&h.app, get(&path, Some(&admin))).await;
    assert!(page.contains(&format!("formaction='/staff/admin/currency/{id}/delete'")));
    let (status, headers, _) = post_form(&h.app, &format!("{path}/delete"), &admin, &form_of(&page)).await;
    assert_eq!(status, StatusCode::FOUND);
    assert_eq!(headers[header::LOCATION], format!("/staff/admin/currency?deleted={id}"));
    assert_eq!(count(&h.pool, "SELECT count(*) FROM currency").await, 0);
    let audit = count(&h.pool, "SELECT count(*) FROM log WHERE type = 'Auth' AND subtype IN ('admin_create', 'admin_update', 'admin_delete') AND user_id = 1").await;
    assert_eq!(audit, 3);
}

#[tokio::test]
async fn the_table_admin_turns_away_other_roles_and_accounts_that_are_not_active() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let (_, _, page) = send(&h.app, get("/staff/admin/carrier/new", Some(&admin))).await;
    let fields = changed(&page, &[("name", "Truck Co")]);
    let exec = person(&h.pool, 2, "Executive").await;
    let pending = account(&h.pool, 3, "Administrator", "Pending").await;
    let denied = account(&h.pool, 4, "Super User", "Denied").await;
    for cookie in [None, Some(&exec), Some(&pending), Some(&denied)] {
        let (status, headers, _) = send(&h.app, get("/staff/admin/carrier", cookie.map(String::as_str))).await;
        assert_eq!((status, headers.get(header::LOCATION).map(|l| l.to_str().unwrap())), (StatusCode::FOUND, Some("/scs_index.php")));
        if let Some(cookie) = cookie {
            let mut own: Vec<(String, String)> = fields.iter().filter(|(k, _)| k != "csrf").cloned().collect();
            own.push(("csrf".into(), token_for(cookie)));
            let (status, _, _) = post_form(&h.app, "/staff/admin/carrier/new", cookie, &own).await;
            assert_eq!(status, StatusCode::FOUND, "a post with its own session's token");
        }
    }
    assert_eq!(count(&h.pool, "SELECT count(*) FROM carrier").await, 0);
    let (status, _, _) = send(&h.app, get("/staff/admin/nonesuch", Some(&admin))).await;
    assert_eq!(status, StatusCode::NOT_FOUND);
}

#[tokio::test]
async fn a_write_needs_the_sessions_token_and_a_get_never_writes() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let other = person(&h.pool, 2, "Super User").await;
    sqlx::query("INSERT INTO carrier (id, name, active) VALUES (5, 'Truck Co', 1)").execute(&h.pool).await.unwrap();
    let (_, _, page) = send(&h.app, get("/staff/admin/carrier/5", Some(&admin))).await;
    let mine = csrf_of(&page);
    let (_, _, theirs) = send(&h.app, get("/staff/admin/carrier/5", Some(&other))).await;
    let theirs = csrf_of(&theirs);
    for token in [None, Some("not-the-token"), Some(theirs.as_str())] {
        let mut fields: Vec<(String, String)> = changed(&page, &[("name", "Changed")]).into_iter().filter(|(k, _)| k != "csrf").collect();
        if let Some(t) = token {
            fields.push(("csrf".into(), t.into()));
        }
        for path in ["/staff/admin/carrier/5", "/staff/admin/carrier/5/delete", "/staff/admin/carrier/new"] {
            let (status, _, _) = post_form(&h.app, path, &admin, &fields).await;
            assert_eq!(status, StatusCode::FORBIDDEN, "{path} {token:?}");
        }
    }
    let actions = format!("/staff/admin/carrier/5?action=delete&name=Changed&csrf={mine}");
    let (status, _, _) = send(&h.app, get(&actions, Some(&admin))).await;
    assert_eq!(status, StatusCode::OK);
    let (status, _, _) = send(&h.app, get(&format!("/staff/admin/carrier/5/delete?csrf={mine}"), Some(&admin))).await;
    assert_eq!(status, StatusCode::METHOD_NOT_ALLOWED);
    let (status, _, _) = send(&h.app, get(&format!("/staff/admin/carrier/new?name=Other&csrf={mine}"), Some(&admin))).await;
    assert_eq!(status, StatusCode::OK);
    let rows: Vec<(i64, String)> = sqlx::query_as("SELECT id, name FROM carrier").fetch_all(&h.pool).await.unwrap();
    assert_eq!(rows, [(5, "Truck Co".to_string())]);
}

#[tokio::test]
async fn an_administrator_edits_accounts_up_to_their_own_role() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    person(&h.pool, 6, "Super User").await;
    person(&h.pool, 7, "Customer").await;
    sqlx::query(r#"UPDATE "user" SET language_code = 'FR', comment = '' WHERE id = 7"#).execute(&h.pool).await.unwrap();
    let (_, _, list) = send(&h.app, get("/staff/admin/user?q=test+company", Some(&admin))).await;
    assert!(list.contains("<td class=center>6</td><td>Test company 6</td>"), "{list}");
    assert!(list.contains("<a href=\"/staff/admin/user/7\">7</a>"));
    let (status, _, _) = send(&h.app, get("/staff/admin/user/6", Some(&admin))).await;
    assert_eq!(status, StatusCode::NOT_FOUND, "an account above the editor's role does not open");

    let (_, _, page) = send(&h.app, get("/staff/admin/user/7", Some(&admin))).await;
    assert!(!page.contains("<option value='Super User'"), "no role above the editor's is offered");
    assert!(!page.contains("formaction="), "deleting accounts is for Super Users");
    let (status, _, _) = post_form(&h.app, "/staff/admin/user/6", &admin, &changed(&page, &[])).await;
    assert_eq!(status, StatusCode::NOT_FOUND);
    let (status, _, again) = post_form(&h.app, "/staff/admin/user/7", &admin, &changed(&page, &[("type", "Super User")])).await;
    assert_eq!(status, StatusCode::OK);
    assert!(again.contains("Role must be one of the listed values"));
    let (status, _, _) = post_form(&h.app, "/staff/admin/user/7/delete", &admin, &form_of(&page)).await;
    assert_eq!(status, StatusCode::FOUND);
    let (status, _, _) = post_form(&h.app, "/staff/admin/user/7", &admin, &changed(&page, &[("type", "Executive"), ("city", "Springfield")])).await;
    assert_eq!(status, StatusCode::FOUND);
    let row: (String, String, Option<String>, String, Option<String>, i64) =
        sqlx::query_as(r#"SELECT "type", status, city, language_code, comment, catalog FROM "user" WHERE id = 7"#).fetch_one(&h.pool).await.unwrap();
    assert_eq!(row, ("Executive".into(), "Active".into(), Some("Springfield".into()), "FR".into(), Some(String::new()), 0), "the rest of the row is as it was");
}

#[tokio::test]
async fn inventory_is_searched_by_part_number_and_edited_one_row_at_a_time() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Super User").await;
    for i in 0..55 {
        sqlx::query("INSERT INTO inventory (id, sku, subcategory_id, active, leadtime_option) VALUES (?1, ?2, 3, 1, 'Subcategory')")
            .bind(i + 1)
            .bind(format!("AB-{i:03}"))
            .execute(&h.pool)
            .await
            .unwrap();
    }
    sqlx::query("INSERT INTO inventory (id, sku, leadtime_option) VALUES (100, 'CD-1', 'Weeks')").execute(&h.pool).await.unwrap();
    let (_, _, page) = send(&h.app, get("/staff/admin/inventory?q=ab-0", Some(&admin))).await;
    assert!(page.contains("<td>AB-000</td>") && page.contains("<td>AB-049</td>") && !page.contains("<td>AB-050</td>") && !page.contains("CD-1"), "{page}");
    assert!(page.contains("Part# starts with") && !page.contains("Add new"));
    assert!(page.contains("<a href=\"/staff/admin/inventory?q=ab-0&amp;page=1\">Next &gt;</a>"), "{page}");
    let (_, _, rest) = send(&h.app, get("/staff/admin/inventory?q=ab-0&page=1", Some(&admin))).await;
    assert!(rest.contains("<td>AB-054</td>") && !rest.contains("<td>AB-049</td>") && !rest.contains("Next &gt;"));

    let (_, _, page) = send(&h.app, get("/staff/admin/inventory/100", Some(&admin))).await;
    assert!(page.contains("<td width='30%'>Part#</td><td width='70%'>CD-1</td>"), "the part number is shown, not edited");
    let (status, _, _) = post_form(&h.app, "/staff/admin/inventory/100", &admin, &changed(&page, &[("sort_order", "7"), ("active", "1"), ("leadtime_option", "Call")])).await;
    assert_eq!(status, StatusCode::FOUND);
    let row: (String, i64, i64, String, String) =
        sqlx::query_as("SELECT sku, sort_order, active, leadtime_option, new_date FROM inventory WHERE id = 100").fetch_one(&h.pool).await.unwrap();
    assert_eq!(row, ("CD-1".into(), 7, 1, "Call".into(), "0000-00-00".into()));
    for (path, method) in [("/staff/admin/inventory/new", Method::GET), ("/staff/admin/inventory/new", Method::POST), ("/staff/admin/inventory/100/delete", Method::POST)] {
        let req = if method == Method::GET { get(path, Some(&admin)) } else { post(path, &admin, &[("csrf", csrf_of(&page).as_str())]) };
        let (status, _, _) = send(&h.app, req).await;
        assert_eq!(status, StatusCode::NOT_FOUND, "{method} {path}");
    }
    assert_eq!(count(&h.pool, "SELECT count(*) FROM inventory").await, 56);
}

async fn order(pool: &SqlitePool, id: i64, po: &str, company: &str, day: u32) {
    let when = hxweb::staff::time::from_local(2025, 3, day, 10, 30, 0);
    sqlx::query("INSERT INTO orderhd (id, po, company_name, name, email, order_date, status, city, state) VALUES (?1, ?2, ?3, 'Sam', 'sam@example.com', ?4, 'Accept', 'Springfield', 'MI')")
        .bind(id)
        .bind(po)
        .bind(company)
        .bind(when)
        .execute(pool)
        .await
        .unwrap();
}

#[tokio::test]
async fn the_order_viewer_lists_orders_newest_first_and_shows_one_with_its_lines() {
    let h = harness().await;
    let exec = person(&h.pool, 2, "Executive").await;
    order(&h.pool, 41, "PO-7", "Buyer <Co>", 4).await;
    order(&h.pool, 42, "X-9", "Other Inc", 6).await;
    for (line, sku, qty, price) in [(1, "HB-1", 2, 1.5), (2, "HB-2", 1, 1.25)] {
        sqlx::query("INSERT INTO orderln (order_id, line_no, sku, description, quantity, price, extended) VALUES (41, ?1, ?2, 'Pin <b>', ?3, ?4, ?5)")
            .bind(line)
            .bind(sku)
            .bind(qty)
            .bind(price)
            .bind(price * qty as f64)
            .execute(&h.pool)
            .await
            .unwrap();
    }
    let (status, _, _) = send(&h.app, get("/staff/orders", Some(&person(&h.pool, 3, "Internal").await))).await;
    assert_eq!(status, StatusCode::FOUND);
    let (status, _, page) = send(&h.app, get("/staff/orders", Some(&exec))).await;
    assert_eq!(status, StatusCode::OK);
    let (a, b) = (page.find("/staff/orders/42\"").unwrap(), page.find("/staff/orders/41\"").unwrap());
    assert!(a < b, "newest first");
    assert!(page.contains("<td>PO-7</td><td>Buyer &lt;Co></td><td>Sam</td><td>sam@example.com</td><td class=center>03/04/2025 10:30 AM</td><td>Accept</td>"), "{page}");
    let only = |page: &str, id: i64| page.contains(&format!("/staff/orders/{id}\"")) && page.matches("<a href=\"/staff/orders/").count() == 1;
    for (query, id) in [("po=po-7", 41), ("company=other", 42), ("from=03%2F05%2F2025", 42), ("thru=2025-03-04", 41), ("from=03/04/2025&thru=03/04/2025", 41)] {
        let (_, _, page) = send(&h.app, get(&format!("/staff/orders?{query}"), Some(&exec))).await;
        assert!(only(&page, id), "{query}: {page}");
    }
    let (_, _, page) = send(&h.app, get("/staff/orders?from=someday", Some(&exec))).await;
    assert!(page.contains("Not a date (mm/dd/yyyy)") && !page.contains("<a href=\"/staff/orders/"));

    let (status, _, page) = send(&h.app, get("/staff/orders/41", Some(&exec))).await;
    assert_eq!(status, StatusCode::OK);
    assert!(page.contains("<tr><td width='30%'>PO#</td><td width='70%'>PO-7</td></tr>"));
    assert!(page.contains("<tr><td width='30%'>Ship to</td><td width='70%'>Springfield, MI</td></tr>"));
    assert!(page.contains("<tr><td class=center>1</td><td>HB-1</td><td>Pin &lt;b></td><td class=right>2</td><td class=right>1.50</td><td class=right>3.00</td><td></td></tr>"), "{page}");
    assert!(page.contains("<td class=right><b>4.25</b></td>"));
    assert!(!page.contains("<form"), "read only");
    let (status, _, _) = send(&h.app, get("/staff/orders/99", Some(&exec))).await;
    assert_eq!(status, StatusCode::NOT_FOUND);
    let (status, _, _) = send(&h.app, post("/staff/orders/41", &exec, &[("status", "Reject")])).await;
    assert_eq!(status, StatusCode::METHOD_NOT_ALLOWED);
}
