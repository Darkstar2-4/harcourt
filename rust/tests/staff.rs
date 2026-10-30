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
    sqlx::query(r#"INSERT INTO "user" (id, status, "type", company_name) VALUES (?1, 'Active', ?2, ?3)"#)
        .bind(id)
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
        assert!(gone == Some(StatusCode::GONE) || path.starts_with("/staff/"), "{path} answers 410 in front of the router");
    }
    for kept in ["/dashboard.php", "/scs_log.php", "/company.php", "/staff/access/1"] {
        assert!(hxweb::retired::guard(kept).is_none(), "{kept}");
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
        assert!(hxweb::retired::guard(link).is_none(), "{link} is retired");
        let (status, _, _) = send(&all, get(link, Some(&superuser))).await;
        assert!(status != StatusCode::NOT_FOUND && status != StatusCode::GONE, "{link}: {status}");
    }
}
