//! The accounts pages (campaign.php, the old docroot directories, the pages that are gone) through the page
//! router, against a private in-memory database of synthetic rows.

use std::{net::SocketAddr, sync::Arc};

use axum::{
    body::Body,
    extract::connect_info::MockConnectInfo,
    http::{header, HeaderMap, Method, Request, StatusCode},
    Router,
};
use http_body_util::BodyExt;
use hxweb::{
    accounts::{bot::Dns, Services},
    api::Auth,
    config::Secret,
    db,
    identity::UserId,
    routes, session,
};
use sqlx::SqlitePool;
use tower::ServiceExt;

const ORIGIN: &str = "https://www.harcourt.co";
const VISIT: &str = "PHPSESSID=0123456789abcdef0123456789abcdef";

async fn pool() -> SqlitePool {
    let pool = db::open_memory().await.unwrap();
    for sql in [
        r#"INSERT INTO "user" (id, status, "type", company_name, ip) VALUES
             (1, 'Active', 'Administrator', 'Staff Co', NULL), (2, 'Active', 'Customer', 'Active Co', NULL),
             (3, 'Denied', 'Customer', 'Denied Co', NULL), (4, 'Pending', 'Customer', 'Pending Co', NULL)"#,
        "INSERT INTO language (code, country_code, name, priority, active) VALUES ('EN', 'US', 'English', 1, 1)",
        "INSERT INTO currency (id, code, name, plural, exchange_rate, adder_pct, update_timestamp, active) VALUES
             (1, 'USD', 'US Dollar', 'US dollars', 1, NULL, 1700000000, 1), (2, 'EUR', 'Euro', 'Euros', 0.95, 0.5, 1700000000, 1),
             (3, 'XTS', 'Testing Unit', 'Testing units', 0, 0, 0, 0)",
        r#"INSERT INTO campaign (id, code, name, description, "type", active, from_date, thru_date, title_omit, divs) VALUES
             (1, 'OPEN', 'Open House', 'Come and see', 'Public', 1, '0000-00-00', '0000-00-00', 0, '[]'),
             (2, 'GUEST', 'Guest List', '', 'Invite', 1, '0000-00-00', '0000-00-00', 1, '{"content":"guest_div"}'),
             (3, 'FAR', 'Far Away', '', 'Remote', 1, '0000-00-00', '0000-00-00', 0, '[]'),
             (4, 'VIP', 'Members Only', '', 'Invite', 1, '0000-00-00', '0000-00-00', 0, '[]')"#,
        "INSERT INTO content (record_type, record_id, record_item, name, filetype, html_content) VALUES
             ('campaign', 1, 'Public:000000', 'Welcome', 'html', '<p>Public words</p>'),
             ('campaign', 1, 'Semi-Private:000001', 'Members', 'html', '<p>Semi words</p>'),
             ('campaign', 1, 'Private:000001', 'Inside', 'html', '<p>Private words</p>'),
             ('campaign', 2, 'Public:000000', 'Guests', 'html', '<p>Guest words</p>')",
    ] {
        sqlx::query(sql).execute(&pool).await.unwrap();
    }
    pool
}

fn app(pool: &SqlitePool) -> Router {
    let core = Arc::new(Auth {
        pool: pool.clone(),
        secret: Some(Secret::from_bytes(vec![7; 32])),
        public_origin: ORIGIN.into(),
        trusted_proxies: vec!["127.0.0.1".parse().unwrap()],
        accounts: Services::offline(Dns::fixed(&[], &[])),
    });
    let peer: SocketAddr = "198.51.100.1:40000".parse().unwrap();
    routes::all(core).layer(MockConnectInfo(peer))
}

async fn signed_in(pool: &SqlitePool, id: i64) -> String {
    let token = session::create(pool, UserId(id), &session::Basis::Registration(hxweb::identity::Email::parse("tester@example.com").unwrap()), db::unix_now()).await.unwrap();
    format!("{}={}; {VISIT}", session::COOKIE, token.as_str())
}

async fn send(app: &Router, method: Method, uri: &str, cookie: &str, form: Option<&str>) -> (StatusCode, HeaderMap, String) {
    let posts = method != Method::GET && method != Method::HEAD;
    let mut req = Request::builder().method(method).uri(uri).header(header::COOKIE, cookie);
    if posts {
        req = req.header(header::ORIGIN, ORIGIN);
    }
    let body = match form {
        Some(f) => {
            req = req.header(header::CONTENT_TYPE, "application/x-www-form-urlencoded");
            Body::from(f.to_string())
        }
        None => Body::empty(),
    };
    let res = app.clone().oneshot(req.body(body).unwrap()).await.unwrap();
    let (parts, body) = res.into_parts();
    (parts.status, parts.headers, String::from_utf8_lossy(&body.collect().await.unwrap().to_bytes()).into_owned())
}

fn location(headers: &HeaderMap) -> Option<&str> {
    headers.get(header::LOCATION).and_then(|v| v.to_str().ok())
}

fn set_cookie_names(headers: &HeaderMap) -> Vec<String> {
    headers.get_all(header::SET_COOKIE).iter().filter_map(|v| v.to_str().ok()?.split('=').next().map(str::to_string)).collect()
}

async fn count(pool: &SqlitePool, sql: &str) -> i64 {
    sqlx::query_scalar(sql).fetch_one(pool).await.unwrap()
}

#[tokio::test]
async fn a_campaign_shows_each_reader_their_part() {
    let pool = pool().await;
    let app = app(&pool);
    let (status, _, body) = send(&app, Method::GET, "/campaign.php?campaign=open", VISIT, None).await;
    assert_eq!(status, StatusCode::OK);
    assert!(body.contains("<title>Open House</title>"));
    assert!(body.contains("<meta name=\"description\" content=\"Come and see\">"));
    assert!(body.contains("<input type=hidden name='campaign' id='campaign' value='open'>"));
    assert!(body.contains("<div class=campaign_content><h1>Welcome</h1><br><div><p>Public words</p></div></div>"));
    assert!(body.contains("value='register'") && !body.contains("Semi words"));

    let denied = signed_in(&pool, 3).await;
    let (_, _, body) = send(&app, Method::GET, "/campaign.php?campaign=OPEN", &denied, None).await;
    assert!(body.contains("Public words") && !body.contains("value='register'") && !body.contains("Semi words"));

    let active = signed_in(&pool, 2).await;
    let (_, _, body) = send(&app, Method::GET, "/campaign.php?campaign=OPEN", &active, None).await;
    assert!(body.contains("Semi words") && body.contains("Welcome Active Co") && !body.contains("Private words"));
    let (_, _, body) = send(&app, Method::GET, "/campaign.php?campaign=OPEN", &format!("{active}; harcourt=pat%40example.com"), None).await;
    assert!(body.contains("Private words") && !body.contains("value='email'"));

    let pending = signed_in(&pool, 4).await;
    let (_, _, body) = send(&app, Method::GET, "/campaign.php?campaign=OPEN", &format!("{pending}; harcourt=pat%40example.com"), None).await;
    assert!(body.contains("Semi words") && !body.contains("Private words"));
}

#[tokio::test]
async fn unknown_remote_and_foreign_invite_campaigns_go_home() {
    let pool = pool().await;
    let app = app(&pool);
    for path in ["/campaign.php?campaign=NOPE", "/campaign.php?campaign=FAR", "/campaign.php"] {
        let (status, headers, _) = send(&app, Method::GET, path, VISIT, None).await;
        assert_eq!((status, location(&headers)), (StatusCode::FOUND, Some("/scs_index.php")), "{path}");
    }
    let (status, _, body) = send(&app, Method::GET, "/campaign.php?campaign=GUEST", VISIT, None).await;
    assert_eq!(status, StatusCode::OK);
    assert!(body.contains("<div class=guest_div><div><p>Guest words</p></div></div>"));
    let (status, _, _) = send(&app, Method::GET, "/campaign.php", VISIT, None).await;
    assert_eq!(status, StatusCode::OK, "the invite stays with the visit");
    let (status, headers, _) = send(&app, Method::GET, "/campaign.php?campaign=VIP", VISIT, None).await;
    assert_eq!((status, location(&headers)), (StatusCode::FOUND, Some("/scs_index.php")), "another visit's invite");
    let (status, _, _) = send(&app, Method::GET, "/campaign.php?campaign=VIP", "PHPSESSID=fedcba9876543210fedcba9876543210", None).await;
    assert_eq!(status, StatusCode::OK);
}

#[tokio::test]
async fn the_campaign_sign_up_records_a_pending_company() {
    let pool = pool().await;
    let app = app(&pool);
    let (_, headers, body) = send(&app, Method::POST, "/campaign.php", VISIT, Some("campaign=OPEN&action=register&first_name=Pat&last_name=&company_name=New+Co&email=bad")).await;
    assert!(body.contains("Correct 2 error(s)") && body.contains("value='Pat'"));
    assert!(set_cookie_names(&headers).iter().all(|n| n != "harcourt"));
    assert_eq!(count(&pool, r#"SELECT count(*) FROM "user" WHERE company_name = 'New Co'"#).await, 0);

    let (status, headers, body) = send(&app, Method::POST, "/campaign.php", VISIT, Some("campaign=OPEN&action=register&first_name=Pat&last_name=Lee&company_name=New+Co&email=pat%40example.com")).await;
    assert_eq!(status, StatusCode::OK);
    assert!(set_cookie_names(&headers).contains(&"harcourt".to_string()));
    assert_eq!(count(&pool, r#"SELECT count(*) FROM "user" WHERE company_name = 'New Co' AND status = 'Pending'"#).await, 1);
    assert!(body.contains("Semi words") && !body.contains("value='register'"));

    let cross_site = Request::builder()
        .method(Method::POST)
        .uri("/campaign.php")
        .header(header::ORIGIN, "https://elsewhere.example")
        .header(header::COOKIE, VISIT)
        .header(header::CONTENT_TYPE, "application/x-www-form-urlencoded")
        .body(Body::from("campaign=OPEN&action=register&first_name=A&last_name=B&company_name=C&email=a%40example.com"))
        .unwrap();
    assert_eq!(app.clone().oneshot(cross_site).await.unwrap().status(), StatusCode::FORBIDDEN);
}

#[tokio::test]
async fn a_campaign_sign_up_makes_the_profile_the_new_persons() {
    let pool = pool().await;
    let app = app(&pool);
    let held = format!("{VISIT}; harcourt_profile={}", percent_encoding::utf8_percent_encode(r#"{"email":"old@elsewhere.example","name_first":"Old","name_last":"Name","generate":1}"#, percent_encoding::NON_ALPHANUMERIC));
    let form = "campaign=OPEN&action=register&first_name=Pat&last_name=Lee&company_name=New+Co&email=pat%40example.com";
    let (status, headers, _) = send(&app, Method::POST, "/campaign.php", &held, Some(form)).await;
    assert_eq!(status, StatusCode::OK);
    let (profile, _) = profile_set(&headers).expect("the profile cookie the browser keeps");
    assert_eq!(profile["email"], "pat@example.com");
    assert_eq!((profile["name_first"].as_str(), profile["name_last"].as_str(), profile["name"].as_str()), (Some("Pat"), Some("Lee"), Some("Pat Lee")));
    assert_eq!(profile["generate"], 1);
}

/// The last `harcourt_profile` cookie an answer sets, decoded, and as the browser sends it back.
fn profile_set(headers: &HeaderMap) -> Option<(serde_json::Value, String)> {
    let pair = headers.get_all(header::SET_COOKIE).iter().filter_map(|v| v.to_str().ok()?.split(';').next()).rfind(|p| p.starts_with("harcourt_profile="))?;
    let value = percent_encoding::percent_decode_str(pair.trim_start_matches("harcourt_profile=")).decode_utf8_lossy().into_owned();
    Some((serde_json::from_str(&value).ok()?, pair.to_string()))
}

#[tokio::test]
async fn sign_out_leaves_the_browser_its_choices_and_no_ones_details() {
    let pool = pool().await;
    let app = app(&pool);
    let held = format!("{VISIT}; harcourt_profile={}", percent_encoding::utf8_percent_encode(r#"{"language_code":"FR","generate":1}"#, percent_encoding::NON_ALPHANUMERIC));
    let form = "campaign=OPEN&action=register&first_name=Pat&last_name=Lee&company_name=New+Co&email=pat%40example.com";
    let (_, headers, _) = send(&app, Method::POST, "/campaign.php", &held, Some(form)).await;
    let (_, pats) = profile_set(&headers).expect("the admitted person's profile");

    let (status, headers, _) = send(&app, Method::GET, "/cookie_reset.php", &format!("{VISIT}; {pats}"), None).await;
    assert_eq!(status, StatusCode::OK);
    let (left, _) = profile_set(&headers).expect("the profile cookie is rewritten");
    assert_eq!((left["email"].is_null(), left["name_first"].is_null(), left["company_name"].is_null()), (true, true, true), "{left}");
    assert_eq!((left["language_code"].as_str(), left["generate"].as_i64()), (Some("FR"), Some(1)), "{left}");

    let (_, headers, _) = send(&app, Method::GET, "/cookie_reset.php", VISIT, None).await;
    assert!(!set_cookie_names(&headers).contains(&"harcourt_profile".to_string()), "a browser with no profile gets none");
}

#[tokio::test]
async fn a_campaign_sign_up_at_an_active_company_goes_on_to_the_emailed_code() {
    let pool = pool().await;
    sqlx::query(r#"UPDATE "user" SET domain = 'active.example' WHERE id = 2"#).execute(&pool).await.unwrap();
    let app = app(&pool);
    let form = "campaign=OPEN&action=register&first_name=Pat&last_name=Lee&company_name=Active+Co&email=pat%40active.example";
    let (status, headers, _) = send(&app, Method::POST, "/campaign.php", VISIT, Some(form)).await;
    assert_eq!((status, location(&headers)), (StatusCode::FOUND, Some("/request-access")));
    let names = set_cookie_names(&headers);
    assert!(names.contains(&"harcourt".to_string()) && !names.iter().any(|n| n.contains("session")), "{names:?}");
    assert_eq!(count(&pool, r#"SELECT count(*) FROM "user""#).await, 4, "no new account");
    assert_eq!(count(&pool, "SELECT count(*) FROM session").await, 0);
    assert_eq!(count(&pool, "SELECT count(*) FROM remote WHERE email = 'pat@active.example'").await, 1);

    let (status, _, body) = send(&app, Method::POST, "/campaign.php", VISIT, Some(&form.replace("active.example", "new.example"))).await;
    assert_eq!(status, StatusCode::OK);
    assert!(body.contains("Semi words"));
    assert_eq!(count(&pool, r#"SELECT count(*) FROM log WHERE type = 'User' AND subtype = 'Signup' AND email = 'pat@new.example'"#).await, 1);
}

#[tokio::test]
async fn a_campaign_sign_up_for_an_account_awaiting_approval_says_so() {
    let pool = pool().await;
    sqlx::query(r#"UPDATE "user" SET domain = 'pending.example' WHERE id = 4"#).execute(&pool).await.unwrap();
    let app = app(&pool);
    let form = "campaign=OPEN&action=register&first_name=Pat&last_name=Lee&company_name=Pending+Co&email=pat%40pending.example";
    let (status, headers, body) = send(&app, Method::POST, "/campaign.php", VISIT, Some(form)).await;
    assert_eq!(status, StatusCode::OK);
    assert!(body.contains("<p style='color:red;'>You access request is pending approval."), "{body}");
    assert!(body.contains("value='register'") && !body.contains("Semi words"));
    assert!(set_cookie_names(&headers).iter().all(|n| !n.contains("session")));
    assert_eq!(count(&pool, r#"SELECT count(*) FROM "user""#).await, 4, "no new account");
    assert_eq!(count(&pool, "SELECT count(*) FROM session").await, 0);
}

#[tokio::test]
async fn paths_apache_ran_a_script_for_reach_the_script() {
    let pool = pool().await;
    let app = tower::ServiceBuilder::new().map_request(hxweb::visitor::apache_paths).service(app(&pool));
    let get = |uri: &str, cookie: Option<&str>| {
        let mut req = Request::builder().uri(uri);
        if let Some(c) = cookie {
            req = req.header(header::COOKIE, c);
        }
        app.clone().oneshot(req.body(Body::empty()).unwrap())
    };
    for uri in ["//cookie_reset.php", "/cookie_reset.php/x", "/cookie_reset.php/", "/cookie_reset%2Ephp?a=1"] {
        let res = get(uri, Some(VISIT)).await.unwrap();
        assert_eq!(res.status(), StatusCode::OK, "{uri}");
        let body = res.into_body().collect().await.unwrap().to_bytes();
        assert!(String::from_utf8_lossy(&body).contains("<p>Your cookies and cache have been reset</p>"), "{uri}");
    }

    let res = get("/campaign.php/more?campaign=OPEN", Some(VISIT)).await.unwrap();
    let page = String::from_utf8(res.into_body().collect().await.unwrap().to_bytes().to_vec()).unwrap();
    assert!(page.contains("method=post action='/campaign.php/more'"), "the form posts back to PHP_SELF: {page}");

    let landing = |res: axum::response::Response| res.headers().get(header::LOCATION).map(|v| v.to_str().unwrap().to_string());
    assert_eq!(landing(get("/scs_index.php/x", None).await.unwrap()).as_deref(), Some("/scs_index.php"));
    assert_eq!(landing(get("//scs_index.php", None).await.unwrap()), None);
}

#[tokio::test]
async fn the_old_docroot_directories_answer_as_apache_did() {
    let pool = pool().await;
    let app = app(&pool);
    for uri in ["/campaign/", "/campaign/slide/css/", "/welcome-pages/xwelcomel.php", "/oauth/"] {
        let (status, headers, body) = send(&app, Method::GET, uri, "", None).await;
        assert_eq!(status, StatusCode::FORBIDDEN, "{uri}");
        assert_eq!(headers.get(header::CONTENT_TYPE).unwrap(), "text/html; charset=iso-8859-1");
        assert!(body.contains("<h1>Forbidden</h1>\n<p>You don't have permission to access this resource.</p>"), "{body}");
    }
    let (status, headers, body) = send(&app, Method::GET, "/welcome-pages?a=1&b", "", None).await;
    assert_eq!((status, location(&headers)), (StatusCode::MOVED_PERMANENTLY, Some("https://www.harcourt.co/welcome-pages/?a=1&b")));
    assert!(body.contains("<a href=\"https://www.harcourt.co/welcome-pages/?a=1&amp;b\">here</a>"), "{body}");
}

/// Pages with no traffic, or replaced (impersonation by remote grants, the code tables and lists by the database
/// itself): no route of the page router answers them, and the guard main.rs runs on every unclaimed path says 410.
#[tokio::test]
async fn the_dropped_accounts_pages_are_gone() {
    let pool = pool().await;
    let app = app(&pool);
    let admin = signed_in(&pool, 1).await;
    for path in [
        "/login.php",
        "/user_impersonate.php",
        "/bot_impersonate.php",
        "/language_change.php",
        "/currency.php",
        "/language.php",
        "/bot.php",
        "/blacklist.php",
        "/captcha_bypass.php",
        "/remote_access.php",
        "/remote_token.php",
        "/cookie.php",
        "/bot_apache.php",
        "/remote_code.php",
    ] {
        for method in [Method::GET, Method::POST] {
            let (status, headers, _) = send(&app, method.clone(), path, &admin, Some("action=update")).await;
            let handed_on = headers.get("x-hxweb").is_some_and(|v| v == "legacy") || matches!(status, StatusCode::NOT_FOUND | StatusCode::METHOD_NOT_ALLOWED | StatusCode::GONE);
            assert!(handed_on, "{method} {path}: {status}");
        }
        assert_eq!(hxweb::retired::guard(path).map(|r| r.status()), Some(StatusCode::GONE), "{path}");
    }
}

#[tokio::test]
async fn campaign_codes_match_as_mysql_compared_them_and_the_ribbon_speaks_the_readers_language() {
    let pool = pool().await;
    sqlx::query(r#"INSERT INTO language (code, name, local_name, priority, active, menu) VALUES ('FR', 'French', 'Français', 1, 1, '{"menu_dashboard":"Tableau de bord"}')"#).execute(&pool).await.unwrap();
    sqlx::query(r#"INSERT INTO "user" (id, status, "type", company_name, language_code) VALUES (5, 'Active', 'Executive', 'Exec Co', 'FR')"#).execute(&pool).await.unwrap();
    let app = app(&pool);
    let (status, _, _) = send(&app, Method::GET, "/campaign.php?campaign=open%20", VISIT, None).await;
    assert_eq!(status, StatusCode::OK, "trailing spaces did not count");
    let executive = signed_in(&pool, 5).await;
    let (_, _, body) = send(&app, Method::GET, "/campaign.php?campaign=OPEN", &executive, None).await;
    assert!(body.contains("<a href=\"/dashboard.php\" >Tableau de bord</a>"), "{body}");
}

#[tokio::test]
async fn a_page_inside_a_visit_sets_the_visits_cookie_again() {
    let pool = pool().await;
    let app = app(&pool);
    let (_, headers, _) = send(&app, Method::GET, "/campaign.php?campaign=open", VISIT, None).await;
    let set: Vec<&str> = headers.get_all(header::SET_COOKIE).iter().map(|v| v.to_str().unwrap()).collect();
    assert!(set.contains(&"PHPSESSID=0123456789abcdef0123456789abcdef; path=/; secure; HttpOnly; SameSite=Lax"), "{set:?}");
}
