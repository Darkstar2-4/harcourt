//! The accounts pages ported in round two (campaign.php, login.php and the staff pages under `accounts::manage`)
//! through the page router, against a private in-memory database of synthetic rows.

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
             (1, 'OPEN', 'Open House', 'Come and see', 'Public', 1, '0000-00-00', '0000-00-00', 0, 'a:0:{}'),
             (2, 'GUEST', 'Guest List', '', 'Invite', 1, '0000-00-00', '0000-00-00', 1, 'a:1:{s:7:"content";s:9:"guest_div";}'),
             (3, 'FAR', 'Far Away', '', 'Remote', 1, '0000-00-00', '0000-00-00', 0, 'a:0:{}'),
             (4, 'VIP', 'Members Only', '', 'Invite', 1, '0000-00-00', '0000-00-00', 0, 'a:0:{}')"#,
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
    let token = session::create(pool, UserId(id), None, db::unix_now()).await.unwrap();
    format!("{}={}; {VISIT}", session::COOKIE, token.as_str())
}

async fn send(app: &Router, method: Method, uri: &str, cookie: &str, form: Option<&str>) -> (StatusCode, HeaderMap, String) {
    let mut req = Request::builder().method(method).uri(uri).header(header::COOKIE, cookie);
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

fn csrf_of(page: &str) -> String {
    let start = page.find("name='csrf' id='csrf' value='").expect("csrf field") + "name='csrf' id='csrf' value='".len();
    page[start..].split('\'').next().unwrap().to_string()
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
async fn login_answers_a_plain_message_not_a_database_error() {
    let pool = pool().await;
    let app = app(&pool);
    let (status, _, body) = send(&app, Method::GET, "/login.php?return_url=/products.php", VISIT, None).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(body, "<p>Sign-in is by emailed code on the Request Access page.</p>\n");
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
    for uri in ["//login.php", "/login.php/x", "/login.php/", "/login%2Ephp?return_url=/products.php"] {
        let res = get(uri, Some(VISIT)).await.unwrap();
        assert_eq!(res.status(), StatusCode::OK, "{uri}");
        let body = res.into_body().collect().await.unwrap().to_bytes();
        assert_eq!(&body[..], b"<p>Sign-in is by emailed code on the Request Access page.</p>\n", "{uri}");
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

#[tokio::test]
async fn currency_codes_are_kept_by_administrators() {
    let pool = pool().await;
    let app = app(&pool);
    let customer = signed_in(&pool, 2).await;
    let (status, headers, _) = send(&app, Method::GET, "/currency.php", &customer, None).await;
    assert_eq!((status, location(&headers)), (StatusCode::FOUND, Some("/scs_index.php")));

    let admin = signed_in(&pool, 1).await;
    let (status, _, page) = send(&app, Method::GET, "/currency.php", &admin, None).await;
    assert_eq!(status, StatusCode::OK);
    assert!(page.contains("<td class=center>0.9500</td><td class=center>50.00%</td><td class=center>1.4250</td>"));
    assert!(page.contains("<p class=center>3 Currency Codes Found</p>"));
    assert!(page.find(">USD<").unwrap() < page.find(">EUR<").unwrap() && page.find(">EUR<").unwrap() < page.find(">XTS<").unwrap());
    assert!(page.contains("id='delete_3'") && !page.contains("id='delete_2'"));

    let csrf = csrf_of(&page);
    let (_, _, body) = send(&app, Method::POST, "/currency.php", &admin, Some(&format!("csrf={csrf}&action=update&record_id=1&code=usd&name=US+Dollar&plural=US+dollars&exchange_rate=7&adder_pct=9&active=1"))).await;
    assert!(body.contains("Record maintained"));
    assert_eq!(count(&pool, "SELECT CAST(exchange_rate AS INTEGER) FROM currency WHERE id = 1").await, 1, "US dollars stay the base");

    let (_, _, body) = send(&app, Method::POST, "/currency.php", &admin, Some(&format!("csrf={csrf}&action=update&record_id=2&code=EUR&name=Euro&plural=Euros&exchange_rate=0&adder_pct=5&active=1"))).await;
    assert!(body.contains("Must be 0.00% if no exchange rate"));
    let (status, _, _) = send(&app, Method::POST, "/currency.php", &admin, Some("action=delete&record_id=3")).await;
    assert_eq!(status, StatusCode::FORBIDDEN);
    assert_eq!(count(&pool, "SELECT count(*) FROM currency").await, 3);
}

#[tokio::test]
async fn a_bot_that_is_not_active_loses_catalog_and_portal() {
    let pool = pool().await;
    let app = app(&pool);
    let admin = signed_in(&pool, 1).await;
    let (_, _, page) = send(&app, Method::GET, "/bot.php?action=maintain&record_id=0", &admin, None).await;
    assert!(page.contains("name='catalog' id='catalog' value='1' checked"), "a new bot starts with the catalog");
    let csrf = csrf_of(&page);
    let (_, _, body) = send(&app, Method::POST, "/bot.php", &admin, Some(&format!("csrf={csrf}&action=update&record_id=0&code=Crawl.Example.com&name=Example&status=Denied&catalog=1&portal_id=4"))).await;
    assert!(body.contains("Record maintained"));
    let row: (String, String, i64, i64) = sqlx::query_as("SELECT code, pattern, catalog, portal_id FROM bot").fetch_one(&pool).await.unwrap();
    assert_eq!(row, ("crawl.example.com".into(), r"/\.crawl\.example\.com$/i".into(), 0, 0));
    let (_, _, body) = send(&app, Method::POST, "/bot.php", &admin, Some(&format!("csrf={csrf}&action=update&record_id=0&code=http://x&name=&status="))).await;
    assert!(body.contains("Correct 3 error(s)"));
    let (_, _, list) = send(&app, Method::GET, "/bot.php?action=delete&record_id=1", &admin, None).await;
    assert!(list.contains("crawl.example.com"), "a link never deletes");
}

#[tokio::test]
async fn languages_list_the_active_ones_english_first() {
    let pool = pool().await;
    sqlx::query("INSERT INTO language (code, name, local_name, priority, active) VALUES ('DE', 'German', 'Deutsch', 0, 1), ('ES', 'Spanish', 'Español', 0, 0)").execute(&pool).await.unwrap();
    let app = app(&pool);
    let admin = signed_in(&pool, 1).await;
    let (_, _, page) = send(&app, Method::GET, "/language.php", &admin, None).await;
    assert!(page.find(">EN-US<").unwrap() < page.find(">DE<").unwrap(), "code and country, English first");
    assert!(!page.contains(">ES<"));
    let csrf = csrf_of(&page);
    let (_, _, form) = send(&app, Method::POST, "/language.php", &admin, Some(&format!("csrf={csrf}&action=maintain&record_id=1"))).await;
    assert!(form.contains("name='bWVudQltZW51X2hvbWU=' id='bWVudQltZW51X2hvbWU=' size='30' maxlength='30' value='Home'"));
    let (_, _, body) = send(&app, Method::POST, "/language.php", &admin, Some(&format!("csrf={csrf}&action=update&record_id=1&code=en&name=English&local_name=English&priority=1&active=1"))).await;
    assert!(body.contains("Correct 33 error(s)"), "every menu word is required");
}

#[tokio::test]
async fn the_blacklist_changes_only_from_its_own_script() {
    let pool = pool().await;
    let app = app(&pool);
    let admin = signed_in(&pool, 1).await;
    let (_, _, page) = send(&app, Method::GET, "/blacklist.php", &admin, None).await;
    assert!(page.contains("No blacklisted domains"));
    let xhr = |body: &'static str, origin: Option<&'static str>| {
        let mut req = Request::builder()
            .method(Method::POST)
            .uri("/blacklist.php")
            .header(header::COOKIE, admin.clone())
            .header("x-requested-with", "XMLHttpRequest")
            .header(header::CONTENT_TYPE, "application/x-www-form-urlencoded");
        if let Some(o) = origin {
            req = req.header(header::ORIGIN, o);
        }
        req.body(Body::from(body)).unwrap()
    };
    let res = app.clone().oneshot(xhr("action=add&domain=Spam.Example.com&comment=x&page=1&search=", Some(ORIGIN))).await.unwrap();
    let body = String::from_utf8_lossy(&res.into_body().collect().await.unwrap().to_bytes()).into_owned();
    assert!(body.starts_with(r#"{"blacklist_message":"spam.example.com has been blacklisted","blacklist_content":"<table"#), "{body}");
    let res = app.clone().oneshot(xhr("action=add&domain=other.example.com&comment=x", Some("https://elsewhere.example"))).await.unwrap();
    assert_eq!(res.status(), StatusCode::FORBIDDEN);
    let (_, _, page) = send(&app, Method::POST, "/blacklist.php", &admin, Some("action=add&domain=third.example.com")).await;
    assert!(page.contains("<title>"), "a plain post only shows the page");
    assert_eq!(count(&pool, "SELECT count(*) FROM blacklist").await, 1);
    let res = app.clone().oneshot(xhr("action=remove&id=1", None)).await.unwrap();
    let body = String::from_utf8_lossy(&res.into_body().collect().await.unwrap().to_bytes()).into_owned();
    assert!(body.contains("spam.example.com has been removed from the blacklist"));
    assert_eq!(count(&pool, "SELECT count(*) FROM blacklist").await, 0);
    assert_eq!(count(&pool, "SELECT count(*) FROM log WHERE type = 'Blacklist'").await, 2);
}

fn xhr(path: &str, cookie: &str, body: &str) -> Request<Body> {
    Request::builder()
        .method(Method::POST)
        .uri(path)
        .header(header::COOKIE, cookie)
        .header("x-requested-with", "XMLHttpRequest")
        .header(header::CONTENT_TYPE, "application/x-www-form-urlencoded")
        .body(Body::from(body.to_string()))
        .unwrap()
}

async fn answer(app: &Router, req: Request<Body>) -> (StatusCode, String) {
    let res = app.clone().oneshot(req).await.unwrap();
    let status = res.status();
    (status, String::from_utf8_lossy(&res.into_body().collect().await.unwrap().to_bytes()).into_owned())
}

#[tokio::test]
async fn remote_access_gives_a_company_domain_to_one_address() {
    let pool = pool().await;
    sqlx::query(r#"INSERT INTO "user" (id, status, "type", company_name, ip, last_login, domain) VALUES
         (10, 'Active', 'Customer', 'Acme Tools', '192.0.2.10', 1700000000, NULL), (11, 'Active', 'Customer', 'Acme Tools', '192.0.2.11', 1700000100, 'old.example'),
         (12, 'Active', 'Customer', 'Other Co', '192.0.2.12', 1700000000, 'taken.example')"#)
        .execute(&pool)
        .await
        .unwrap();
    let app = app(&pool);
    let customer = signed_in(&pool, 2).await;
    let (status, _) = answer(&app, xhr("/remote_access.php", &customer, "action=load&company_name=Acme+Tools")).await;
    assert_eq!(status, StatusCode::FOUND, "the AJAX calls need an Administrator too");

    let admin = signed_in(&pool, 1).await;
    let (_, body) = answer(&app, xhr("/remote_access.php", &admin, "action=load&company_name=acme+tools")).await;
    assert!(body.contains(r#"value='old.example'"#) && body.contains("192.0.2.10") && body.contains("192.0.2.11"), "{body}");
    let (_, body) = answer(&app, xhr("/remote_access.php", &admin, "action=update&company_name=Acme+Tools&domain=taken.example&ip=192.0.2.10")).await;
    assert_eq!(body, r#"{"domain_status":"Domain taken.example already assigned to Other Co"}"#);
    let (_, body) = answer(&app, xhr("/remote_access.php", &admin, "action=update&company_name=Acme+Tools&domain=New.Example&ip=192.0.2.10")).await;
    assert_eq!(body, r#"{"domain_status":"Company name: Acme Tools Domain: new.example IP Address: 192.0.2.10"}"#);
    let domains: Vec<(i64, Option<String>)> = sqlx::query_as(r#"SELECT id, domain FROM "user" WHERE id IN (10, 11) ORDER BY id"#).fetch_all(&pool).await.unwrap();
    assert_eq!(domains, vec![(10, Some("new.example".into())), (11, None)]);
    let (_, body) = answer(&app, xhr("/remote_access.php", &admin, "action=update&company_name=Acme+Tools&domain=&ip=0.0.0.0")).await;
    assert!(body.contains("Remote access denied"));
    assert_eq!(count(&pool, r#"SELECT count(*) FROM "user" WHERE domain IS NOT NULL AND company_name = 'Acme Tools'"#).await, 0);
}

#[tokio::test]
async fn removing_a_remote_address_ends_its_sign_ins() {
    let pool = pool().await;
    sqlx::query("INSERT INTO remote (email, expiry, token, unlock_code) VALUES ('pat@acme.example', 4000000000, '', '')").execute(&pool).await.unwrap();
    session::create(&pool, UserId(2), Some("pat@acme.example"), db::unix_now()).await.unwrap();
    let app = app(&pool);
    let admin = signed_in(&pool, 1).await;
    let (_, page) = answer(&app, xhr("/remote_token.php", &admin, "action=Remove&domain=acme.example&email=pat%40acme.example")).await;
    assert!(page.starts_with(r#"{"remote_message":"pat@acme.example Removed","remote_content":"<table"#), "{page}");
    assert_eq!(count(&pool, "SELECT count(*) FROM remote").await, 0);
    assert_eq!(count(&pool, "SELECT count(*) FROM session WHERE email = 'pat@acme.example'").await, 0);
}

#[tokio::test]
async fn an_administrator_can_view_the_site_as_a_bot() {
    let pool = pool().await;
    sqlx::query("INSERT INTO bot (id, code, pattern, name, catalog, status) VALUES (7, 'crawler.example', '/\\.crawler\\.example$/i', 'Crawler', 1, 'Active')").execute(&pool).await.unwrap();
    let app = app(&pool);
    let admin = signed_in(&pool, 1).await;
    let (_, _, page) = send(&app, Method::GET, "/bot_impersonate.php", &admin, None).await;
    assert!(page.contains("<optgroup label='Active'>\n<option value='7'>Crawler (crawler.example)</option>"));
    let csrf = csrf_of(&page);
    let (status, headers, _) = send(&app, Method::POST, "/bot_impersonate.php", &admin, Some(&format!("csrf={csrf}&action=update&bot_id=7"))).await;
    assert_eq!((status, location(&headers)), (StatusCode::FOUND, Some("/index.php")));
    let (_, _, campaign) = send(&app, Method::GET, "/campaign.php?campaign=OPEN", &admin, None).await;
    assert!(campaign.contains("Welcome Crawler"), "the visitor is the bot now");
    let (_, _, page) = send(&app, Method::GET, "/bot_impersonate.php", &admin, None).await;
    assert!(page.contains("Impersonating Stopped"));
    assert_eq!(count(&pool, "SELECT count(*) FROM impersonation").await, 0);
}

#[test]
fn retired_accounts_scripts_are_gone() {
    for path in ["/cookie.php", "/bot_apache.php", "/remote_code.php"] {
        assert_eq!(hxweb::retired::guard(path).map(|r| r.status()), Some(StatusCode::GONE), "{path}");
    }
    assert!(hxweb::retired::guard("/cookie_reset.php").is_none());
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
