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
    let auth = Arc::new(Auth { pool: pool.clone(), secret: None, public_origin: "https://www.harcourt.co".into(), trusted_proxies: vec![] });
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

async fn mailform_count(pool: &SqlitePool) -> i64 {
    sqlx::query_scalar("SELECT count(*) FROM mailform").fetch_one(pool).await.unwrap()
}

#[tokio::test]
async fn pages_send_visitors_without_the_role_home() {
    let h = harness().await;
    let exec = person(&h.pool, 2, "Executive").await;
    for cookie in [None, Some(exec.as_str())] {
        let (status, headers, _) = send(&h.app, get("/mailer_maintain.php", cookie)).await;
        assert_eq!(status, StatusCode::FOUND);
        assert_eq!(headers[header::LOCATION], "/scs_index.php");
    }
}

#[tokio::test]
async fn an_administrator_sees_the_list_inside_the_site_chrome() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    sqlx::query("INSERT INTO mailform (code, name, subject, recipient, active) VALUES ('quote', 'Quote <form>', 'Quote', '', 0)")
        .execute(&h.pool)
        .await
        .unwrap();
    let (status, headers, page) = send(&h.app, get("/mailer_maintain.php", Some(&admin))).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(headers[header::CACHE_CONTROL], "no-store");
    assert!(page.starts_with("<!DOCTYPE html>\n<html>\n<head>\n<title>Email Documents</title>"));
    assert!(page.contains("<div id='scs_body' >"));
    assert!(page.contains("<p class='message left'>Email Documents (08/07/2025)</p>"));
    assert!(page.contains("<form name='scs_form' id='scs_form' method=post action='/mailer_maintain.php'"));
    assert!(page.contains("<td>Quote &lt;form&gt;</td>"));
    assert!(page.contains("<td>Visitor</td>"));
    assert!(page.contains("onclick=\"javascript:fn_action('delete','1','quote');\""));
    assert!(page.ends_with("</div> <!-- #scs_content -->\n</div> <!-- #scs_body --></body>\n</html>\n"));
}

#[tokio::test]
async fn a_post_without_the_session_token_changes_nothing() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let other = person(&h.pool, 3, "Administrator").await;
    let (_, _, page) = send(&h.app, get("/mailer_maintain.php", Some(&other))).await;
    let foreign = csrf_of(&page);
    let fields = [("action", "update"), ("record_id", "0"), ("code", "x"), ("name", "X"), ("subject", "S")];
    let (status, _, _) = send(&h.app, post("/mailer_maintain.php", &admin, &fields)).await;
    assert_eq!(status, StatusCode::FORBIDDEN);
    let with_foreign: Vec<_> = fields.iter().copied().chain([("csrf", foreign.as_str())]).collect();
    let (status, _, _) = send(&h.app, post("/mailer_maintain.php", &admin, &with_foreign)).await;
    assert_eq!(status, StatusCode::FORBIDDEN);
    assert_eq!(mailform_count(&h.pool).await, 0);
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
    assert!(page.contains("<td>looked &lt;b&gt;for&lt;/b&gt;<br>widgets</td>"));
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
    assert!(page.contains("<form name='scs_form' id='scs_form' method=post action='/user.php'"));

    let (_, _, page) = send(&h.app, get("/dashboard.php?tab=Campaign", Some(&admin))).await;
    assert!(page.contains(">Pending Access (1)</a>"));
    assert!(page.contains("<td>Pending &lt;Co&gt;</td>\n<td>Pat Example</td>"));
    assert!(page.contains("href=\"/user.php?action=maintain&record_id=9&return_url=%2Fdashboard.php%3Ftab%3DPending%2BAccess%2B%25281%2529\""));
    let tab_index = |title: &str| {
        let at = page.find(&format!(">{title}</a></li>")).unwrap();
        page[..at].matches("<li id='tab_").count() - 1
    };
    assert!(page.contains(&format!("active: {} }}", tab_index("Campaign"))));
    assert!(page.contains("<td>spring</td>"));
}

#[tokio::test]
async fn administrators_mint_list_and_revoke_grants_within_their_role() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    person(&h.pool, 5, "Customer").await;
    person(&h.pool, 6, "Super User").await;
    let (status, _, _) = send(&h.app, get("/staff/remote-grants", Some(&person(&h.pool, 2, "Executive").await))).await;
    assert_eq!(status, StatusCode::FOUND);
    let (_, _, page) = send(&h.app, get("/staff/remote-grants", Some(&admin))).await;
    assert!(page.contains("<td colspan=6>No live grants</td>"));
    let csrf = csrf_of(&page);
    let p = |pairs: &[(&str, &str)]| post("/staff/remote-grants", &admin, &[pairs, &[("csrf", csrf.as_str())]].concat());

    let (_, _, page) = send(&h.app, p(&[("action", "mint"), ("user_id", "6")])).await;
    assert!(page.contains("No account you can grant"));
    let (_, _, missing) = send(&h.app, p(&[("action", "mint"), ("user_id", "999")])).await;
    assert!(missing.contains("No account you can grant"));
    let grants: i64 = sqlx::query_scalar("SELECT count(*) FROM remote_grant").fetch_one(&h.pool).await.unwrap();
    assert_eq!(grants, 0);

    let (_, _, page) = send(&h.app, p(&[("action", "mint"), ("user_id", "5")])).await;
    assert!(page.contains("<br>Grant created for user 5</span>"));
    let code = page.split("<b><code>").nth(1).unwrap().split("</code>").next().unwrap();
    assert!(hxweb::token::Token::parse(code).is_some());
    let (_, _, list) = send(&h.app, get("/staff/remote-grants", Some(&admin))).await;
    assert!(list.contains("<td>Test company 5</td>") && !list.contains(code));

    let id: i64 = sqlx::query_scalar("SELECT rowid FROM remote_grant").fetch_one(&h.pool).await.unwrap();
    let id = id.to_string();
    let (_, _, page) = send(&h.app, p(&[("action", "revoke"), ("record_id", &id)])).await;
    assert!(page.contains("<br>Grant revoked</span>") && page.contains("No live grants"));
    let redeemed = hxweb::remote_grant::redeem(&h.pool, &hxweb::token::Token::parse(code).unwrap(), db::unix_now()).await.unwrap();
    assert!(redeemed.is_none());
    let audit: i64 = sqlx::query_scalar("SELECT count(*) FROM log WHERE subtype IN ('remote_granted', 'remote_revoked')").fetch_one(&h.pool).await.unwrap();
    assert_eq!(audit, 2);
}

#[tokio::test]
async fn maintain_update_and_delete_a_document() {
    let h = harness().await;
    let admin = person(&h.pool, 1, "Administrator").await;
    let (_, _, page) = send(&h.app, get("/mailer_maintain.php", Some(&admin))).await;
    let csrf = csrf_of(&page);
    let p = |pairs: &[(&str, &str)]| post("/mailer_maintain.php", &admin, &[pairs, &[("csrf", csrf.as_str())]].concat());

    let (status, _, page) = send(&h.app, p(&[("action", "maintain"), ("record_id", "0")])).await;
    assert_eq!(status, StatusCode::OK);
    assert!(page.contains("<input type=text name='code' id='code' size='20' maxlength='20'>"));
    assert!(page.contains("fn_action('update','0','');"));

    let (_, _, page) = send(&h.app, p(&[("action", "update"), ("record_id", "0"), ("code", ""), ("name", "Quote"), ("bcc", "a@x.com\nbad@x\na@x.com")])).await;
    assert!(page.contains("<span class=message2><br>Correct 3 error(s)</span>"));
    assert!(page.contains("style='border: 1px solid red !important;'>\n<br>Cannot be blank"));
    assert!(page.contains("bad@x: Invalid domain"));
    assert_eq!(mailform_count(&h.pool).await, 0);

    let good = [("action", "update"), ("record_id", "0"), ("code", " Quote "), ("name", "Quote"), ("subject", "Your quote"), ("bcc", "b@x.com"), ("active", "1")];
    let (_, _, page) = send(&h.app, p(&good)).await;
    assert!(page.contains("<br>Record maintained</span>"), "{page}");
    let (id, code, bcc): (i64, String, String) = sqlx::query_as("SELECT id, code, bcc FROM mailform").fetch_one(&h.pool).await.unwrap();
    assert_eq!((code.as_str(), bcc.as_str()), ("quote", r#"["b@x.com"]"#));

    let (_, _, page) = send(&h.app, p(&[("action", "update"), ("record_id", "0"), ("code", "quote"), ("name", "Other"), ("subject", "S")])).await;
    assert!(page.contains("<br>Duplicate code"));
    assert_eq!(mailform_count(&h.pool).await, 1);

    let (_, _, _) = send(&h.app, get(&format!("/mailer_maintain.php?action=delete&record_id={id}"), Some(&admin))).await;
    assert_eq!(mailform_count(&h.pool).await, 1);

    let id = id.to_string();
    let (_, _, page) = send(&h.app, p(&[("action", "delete"), ("record_id", &id)])).await;
    assert!(page.contains("<br>Document deleted: <b>quote </b>"));
    assert_eq!(mailform_count(&h.pool).await, 0);
}
