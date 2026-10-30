//! newuser.php and the OAuth sign-in through the real page router, against a private in-memory database. Every
//! row, address and provider here is synthetic; the OAuth provider is a stand-in served on loopback by the test.

use std::{
    net::SocketAddr,
    sync::{Arc, Mutex},
};

use axum::{
    body::Body,
    extract::connect_info::MockConnectInfo,
    http::{header, HeaderMap, Method, Request, StatusCode},
    routing::{get, post},
    Json, Router,
};
use base64::{engine::general_purpose::URL_SAFE_NO_PAD, Engine};
use http_body_util::BodyExt;
use hxweb::{
    accounts::{
        bot::Dns,
        oauth::{Kind, Provider, Providers},
        Services,
    },
    api::Auth,
    config::Secret,
    db, routes,
};
use sqlx::SqlitePool;
use tower::ServiceExt;

const ORIGIN: &str = "https://www.harcourt.co";
const CLIENT_IP: &str = "198.51.100.23";

async fn pool() -> SqlitePool {
    let pool = db::open_memory().await.unwrap();
    for sql in [
        r#"INSERT INTO "user" (id, status, "type", domain, company_name, catalog, language_code) VALUES (5, 'Active', 'Customer', 'example.com', 'Example Tools', 1, 'EN'), (6, 'Pending', 'Customer', 'waiting.example', 'Waiting Co', 0, 'EN')"#,
        "INSERT INTO registry (domain, module, field, item, data) VALUES ('', 'local', 'remote_days', '', '30'), ('', 'email', 'webmaster', '', 'web@example.net'), ('', 'address', 'profile', 'company_name', 'required'), ('', 'address', 'profile', 'name', 'required'), ('', 'address', 'profile', 'email', 'required'), ('', 'address', 'profile', 'city', 'allowed')",
        "INSERT INTO mailform (domain, code, name, recipient, subject) VALUES ('', 'register', 'Register', 'staff@example.net', 'New access request')",
        "INSERT INTO blacklist (domain, comment) VALUES ('spam.example', 'test')",
    ] {
        sqlx::query(sql).execute(&pool).await.unwrap();
    }
    pool
}

fn app_with(pool: &SqlitePool, oauth: Providers) -> Router {
    let mut services = Services::offline(Dns::fixed(&[], &[]));
    services.oauth = oauth;
    let core = Arc::new(Auth {
        pool: pool.clone(),
        secret: Some(Secret::from_bytes(vec![7; 32])),
        public_origin: ORIGIN.into(),
        trusted_proxies: vec!["127.0.0.1".parse().unwrap()],
        accounts: services,
    });
    let peer: SocketAddr = format!("{CLIENT_IP}:40000").parse().unwrap();
    routes::all(core).layer(MockConnectInfo(peer))
}

fn app(pool: &SqlitePool) -> Router {
    app_with(pool, Providers::none())
}

struct Answer {
    status: StatusCode,
    headers: HeaderMap,
    body: String,
}

impl Answer {
    fn json(&self) -> serde_json::Value {
        serde_json::from_str(&self.body).unwrap()
    }

    fn cookies(&self) -> Vec<String> {
        self.headers.get_all(header::SET_COOKIE).iter().map(|v| v.to_str().unwrap().to_string()).collect()
    }

    /// The name=value pairs a browser would send back.
    fn jar(&self) -> String {
        self.cookies().iter().filter(|c| !c.contains("Max-Age=0")).map(|c| c.split(';').next().unwrap().to_string()).collect::<Vec<_>>().join("; ")
    }
}

/// A request from the /request-access page, as the browser sends it in the middle of a visit.
async fn send(app: &Router, method: Method, uri: &str, cookie: &str, form: Option<&str>, xhr: bool) -> Answer {
    let mut req = Request::builder().method(method).uri(uri).header(header::REFERER, format!("{ORIGIN}/request-access"));
    req = req.header(header::COOKIE, if cookie.is_empty() { "PHPSESSID=test".to_string() } else { format!("{cookie}; PHPSESSID=test") });
    if xhr {
        req = req.header("x-requested-with", "XMLHttpRequest");
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
    Answer { status: parts.status, headers: parts.headers, body: String::from_utf8_lossy(&body.collect().await.unwrap().to_bytes()).into_owned() }
}

async fn page(app: &Router, cookie: &str) -> Answer {
    send(app, Method::GET, "/newuser.php", cookie, None, false).await
}

async fn ajax(app: &Router, cookie: &str, form: &str) -> Answer {
    send(app, Method::POST, "/newuser.php", cookie, Some(form), true).await
}

async fn scalar(pool: &SqlitePool, sql: &str) -> i64 {
    sqlx::query_scalar(sql).fetch_one(pool).await.unwrap()
}

async fn mails(pool: &SqlitePool) -> Vec<(String, String, String)> {
    sqlx::query_as("SELECT to_email, subject, body FROM outbox ORDER BY id").fetch_all(pool).await.unwrap()
}

fn code_in(body: &str) -> String {
    body.split("code is ").nth(1).unwrap().chars().take(8).collect()
}

#[tokio::test]
async fn an_anonymous_visitor_gets_the_registration_form() {
    let pool = pool().await;
    let a = page(&app(&pool), "").await;
    assert_eq!(a.status, StatusCode::OK);
    assert!(a.body.starts_with("<style>\n#scscpq_content {"));
    for piece in [
        "<form name='scs_form' id='scs_form' onsubmit=\"javascript:return false;\" method=post action='/newuser.php' accept-charset='utf-8' autocomplete=on>\n<div id=scscpq_content><div id=scscpq_input_status></div>",
        "<div id=profile_register>",
        "<a href='/oauth/init.php'",
        "<a href='/oauth/google_init.php'",
        "name='profile_email'",
        "name='profile_name_first'",
        "name='profile_company_name'",
        "name='profile_city'",
        "<input type=submit class='red-button' value='Submit'>",
    ] {
        assert!(a.body.contains(piece), "missing {piece:?}");
    }
    assert!(!a.body.contains("profile_zip"), "zip is not in this registry");
    assert!(a.body.ends_with("</form></div>"));
}

#[tokio::test]
async fn an_address_at_an_active_company_gets_a_code_and_the_right_code_signs_in() {
    let pool = pool().await;
    let app = app(&pool);
    let a = ajax(&app, "", "action=register&profile_email=Pat%40Example.com&g-recaptcha-response=").await;
    let reply = a.json();
    assert!(reply["html"]["scscpq_content"].as_str().unwrap().contains("Welcome Example Tools Employee"));
    assert!(reply["html"]["scscpq_content"].as_str().unwrap().contains("mailed from web@example.net"));
    let jar = a.jar();
    assert!(jar.split("; ").any(|c| c == "harcourt=pat%40example.com"), "{jar}");
    let sent = mails(&pool).await;
    assert_eq!(sent.len(), 2, "{sent:?}");
    assert_eq!((sent[0].0.as_str(), sent[0].1.as_str()), ("staff@example.net", "New Account Auto-Approved: pat@example.com"));
    assert!(sent[0].2.contains("Method: Registration form (access token emailed for verification)"));
    assert_eq!(sent[1].0, "pat@example.com");
    let code = code_in(&sent[1].2);

    let a = ajax(&app, &jar, &format!("action=remote&unlock_code={code}")).await;
    let reply = a.json();
    assert_eq!(reply["menu"]["scscpq_newuser"], 0);
    assert_eq!(reply["menu"]["scscpq_catalog"], 1);
    assert!(reply["html"]["scscpq_content"].as_str().unwrap().starts_with("<h2 class='page-subtitle editable h2-login'>Congratulations, you now have access!<h2><p><input type=button class='red-button' value='Click Here To Enter Our Catalog' onclick=\"javascript:window.location.replace('/catalog');\">"));
    let session = a.cookies().into_iter().find(|c| c.starts_with("__Host-hx_session=")).unwrap();
    assert!(session.contains("Max-Age=31536000"), "{session}");
    assert_eq!(scalar(&pool, "SELECT renew_secs FROM session WHERE email = 'pat@example.com'").await, 30 * 86400);
    assert_eq!(scalar(&pool, "SELECT count(*) FROM log WHERE type = 'User' AND subtype = 'Remote Login' AND user_id = 5").await, 1);

    let signed_in = format!("{jar}; {}", session.split(';').next().unwrap());
    let a = page(&app, &signed_in).await;
    assert!(a.body.contains("<div id=scscpq_input_status></div></form></div>"), "a signed-in visitor sees no form");

    ajax(&app, "", "action=register&profile_email=pat%40example.com").await;
    assert_eq!(mails(&pool).await.iter().filter(|m| m.1.starts_with("New Account Auto-Approved")).count(), 1, "the notice goes out once per address");
}

#[tokio::test]
async fn two_wrong_codes_use_up_the_tries_and_blank_costs_none() {
    let pool = pool().await;
    let app = app(&pool);
    ajax(&app, "", "action=register&profile_email=pat%40example.com").await;
    let code = code_in(&mails(&pool).await[1].2);
    let jar = "harcourt=pat%40example.com";
    let error = |a: Answer| a.json()["html"]["unlock_code_error"].as_str().unwrap().to_string();
    assert_eq!(error(ajax(&app, jar, "action=remote&unlock_code=").await), "Cannot be blank");
    let wrong = if code.starts_with('2') { "3AAAAAAA" } else { "2AAAAAAA" };
    assert_eq!(error(ajax(&app, jar, &format!("action=remote&unlock_code={wrong}")).await), "Invalid access token (1 of 3)");
    assert_eq!(error(ajax(&app, jar, "action=remote&unlock_code=junk").await), "Invalid access token (2 of 3)");
    assert_eq!(error(ajax(&app, jar, &format!("action=remote&unlock_code={code}")).await), "Excess failures. A new token must be resent");
    assert_eq!(scalar(&pool, "SELECT count(*) FROM session").await, 0);
    assert_eq!(error(ajax(&app, "harcourt=nobody%40example.org", &format!("action=remote&unlock_code={code}")).await), "Your access token has expired. A new token must be resent");
}

#[tokio::test]
async fn resending_only_mails_addresses_at_active_companies() {
    let pool = pool().await;
    let app = app(&pool);
    for jar in ["harcourt=pat%40example.com", "harcourt=someone%40elsewhere.example", ""] {
        let a = ajax(&app, jar, "action=token").await;
        assert_eq!(a.json()["html"]["unlock_code_message"], "Access token has been sent", "{jar}");
    }
    let sent = mails(&pool).await;
    assert_eq!(sent.iter().map(|m| m.0.as_str()).collect::<Vec<_>>(), ["pat@example.com"]);
}

#[tokio::test]
async fn any_other_address_asks_for_access_as_a_pending_account() {
    let pool = pool().await;
    let app = app(&pool);
    let a = ajax(&app, "", "action=register&profile_email=lee%40newco.example&profile_name_first=Lee").await;
    let reply = a.json();
    assert_eq!(reply["html"]["profile_message"], "Correct 2 error(s)");
    assert_eq!(reply["error"]["profile_email"], 0);
    assert_eq!(reply["error"]["profile_name_first"], 0);
    assert_eq!(reply["error"]["action"], 0);
    assert_eq!(scalar(&pool, r#"SELECT count(*) FROM "user""#).await, 2);

    let form = "action=register&profile_email=lee%40newco.example&profile_name_first=Lee&profile_name_last=Ray&profile_company_name=New+%3CCo%3E&profile_city=Ottawa";
    let a = ajax(&app, "", form).await;
    assert!(a.json()["html"]["profile_register"].as_str().unwrap().contains("Congratulations, your access request has been submitted!"));
    let (ip, company, status): (String, String, String) = sqlx::query_as(r#"SELECT ip, company_name, status FROM "user" WHERE id > 6"#).fetch_one(&pool).await.unwrap();
    assert_eq!((ip.as_str(), company.as_str(), status.as_str()), (CLIENT_IP, "New <Co>", "Pending"));
    let sent = mails(&pool).await;
    assert_eq!((sent.len(), sent[0].0.as_str(), sent[0].1.as_str()), (1, "staff@example.net", "New access request"));
    assert!(sent[0].2.contains("Company Name: New &lt;Co&gt;<br>Name: Lee Ray<br>City: Ottawa<br>reCAPTCHA Score: 1<br><br>Browser: "), "{}", sent[0].2);
    assert_eq!(scalar(&pool, "SELECT count(*) FROM log WHERE type = 'User' AND subtype = 'Signup'").await, 1);

    let a = page(&app, &a.jar()).await;
    assert!(a.body.contains("You access request is pending approval."), "the registrant now sees pending");
}

#[tokio::test]
async fn a_blacklisted_domain_is_refused() {
    let pool = pool().await;
    let a = ajax(&app(&pool), "", "action=register&profile_email=x%40spam.example&profile_name_first=A&profile_name_last=B&profile_company_name=C").await;
    assert!(a.json()["html"]["profile_register"].as_str().unwrap().contains("Your domain is not authorized"));
    assert_eq!(scalar(&pool, "SELECT count(*) FROM log WHERE type = 'Blacklist' AND subtype = 'Denied'").await, 1);
    assert_eq!(scalar(&pool, r#"SELECT count(*) FROM "user""#).await, 2);
}

#[tokio::test]
async fn posts_from_other_sites_or_without_the_ajax_header_change_nothing() {
    let pool = pool().await;
    let app = app(&pool);
    let form = "action=register&profile_email=pat%40example.com";
    let req = Request::builder()
        .method(Method::POST)
        .uri("/newuser.php")
        .header(header::ORIGIN, "https://evil.example")
        .header(header::COOKIE, "PHPSESSID=test")
        .header("x-requested-with", "XMLHttpRequest")
        .header(header::CONTENT_TYPE, "application/x-www-form-urlencoded")
        .body(Body::from(form))
        .unwrap();
    assert_eq!(app.clone().oneshot(req).await.unwrap().status(), StatusCode::FORBIDDEN);
    let a = send(&app, Method::POST, "/newuser.php", "", Some(form), false).await;
    assert!(a.body.contains("<div id=profile_register>"));
    assert_eq!(mails(&pool).await.len(), 0);
    let a = ajax(&app, "", "action=microsoft_oauth_init").await;
    assert_eq!(a.body, r#"{"redirect":"/oauth/init.php"}"#);
}

/// A provider that answers like Microsoft or Google: the token endpoint returns an ID token with the nonce the
/// authorize redirect carried, and the profile endpoint returns `profile`.
async fn stand_in(kind: Kind, profile: serde_json::Value, nonce: Arc<Mutex<String>>, id_extra: serde_json::Value) -> Provider {
    let n = nonce.clone();
    let token = post(move || {
        let n = n.clone();
        let id_extra = id_extra.clone();
        async move {
            let mut claims = serde_json::json!({ "nonce": *n.lock().unwrap(), "aud": "client-1", "tid": "tenant-1" });
            for (k, v) in id_extra.as_object().unwrap() {
                claims[k] = v.clone();
            }
            let id_token = format!("e30.{}.sig", URL_SAFE_NO_PAD.encode(claims.to_string()));
            Json(serde_json::json!({ "access_token": "at-1", "id_token": id_token }))
        }
    });
    let me = get(move || {
        let profile = profile.clone();
        async move { Json(profile) }
    });
    let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
    let addr = listener.local_addr().unwrap();
    tokio::spawn(async move { axum::serve(listener, Router::new().route("/token", token).route("/me", me)).await.unwrap() });
    Provider {
        kind,
        client_id: "client-1".into(),
        client_secret: "secret-1".into(),
        authorize_url: format!("http://{addr}/authorize"),
        token_url: format!("http://{addr}/token"),
        profile_url: format!("http://{addr}/me"),
    }
}

fn param(location: &str, key: &str) -> String {
    let query = location.split_once('?').unwrap().1;
    let raw = query.split('&').find_map(|kv| kv.strip_prefix(&format!("{key}="))).unwrap();
    percent_encoding::percent_decode_str(raw).decode_utf8().unwrap().into_owned()
}

/// Start at /oauth/init.php, come back to the callback with what the provider would send, then load the page.
async fn round_trip(app: &Router, nonce: &Arc<Mutex<String>>, init: &str, callback: &str, tamper_state: bool) -> (Answer, Answer) {
    let start = send(app, Method::GET, init, "", None, false).await;
    assert_eq!(start.status, StatusCode::FOUND);
    let location = start.headers.get(header::LOCATION).unwrap().to_str().unwrap().to_string();
    assert_eq!(param(&location, "code_challenge_method"), "S256");
    *nonce.lock().unwrap() = param(&location, "nonce");
    let state = if tamper_state { "forged".to_string() } else { param(&location, "state") };
    let jar = start.jar();
    let back = send(app, Method::GET, &format!("{callback}?code=c1&state={state}"), &jar, None, false).await;
    assert_eq!((back.status, back.headers.get(header::LOCATION).unwrap().to_str().unwrap()), (StatusCode::FOUND, "/request-access"));
    let cookies = [jar, back.jar()].into_iter().filter(|c| !c.is_empty()).collect::<Vec<_>>().join("; ");
    let shown = page(app, &cookies).await;
    (back, shown)
}

#[tokio::test]
async fn microsoft_sign_in_at_a_tenant_verified_company_domain_grants_access() {
    let pool = pool().await;
    let nonce = Arc::new(Mutex::new(String::new()));
    let ms = stand_in(Kind::Microsoft, serde_json::json!({"mail": "Pat@example.com", "userPrincipalName": "pat@example.com", "givenName": "Pat"}), nonce.clone(), serde_json::json!({})).await;
    let app = app_with(&pool, Providers { microsoft: Some(ms), google: None });
    let (back, shown) = round_trip(&app, &nonce, "/oauth/init.php", "/oauth/callback.php", false).await;
    assert!(back.cookies().iter().any(|c| c.starts_with("__Host-hx_session=")));
    assert!(shown.body.contains("Congratulations, you now have access!"), "{}", shown.body);
    assert_eq!(scalar(&pool, "SELECT count(*) FROM session WHERE user_id = 5 AND email = 'pat@example.com'").await, 1);
    assert!(mails(&pool).await.iter().any(|m| m.2.contains("Method: Microsoft OAuth")));
    let again = page(&app, &back.jar()).await;
    assert!(!again.body.contains("Congratulations"), "the outcome is shown once");
}

#[tokio::test]
async fn microsoft_mail_at_a_domain_the_tenant_did_not_prove_is_refused() {
    let pool = pool().await;
    let nonce = Arc::new(Mutex::new(String::new()));
    let ms = stand_in(Kind::Microsoft, serde_json::json!({"mail": "pat@example.com", "userPrincipalName": "pat@other-tenant.example"}), nonce.clone(), serde_json::json!({})).await;
    let app = app_with(&pool, Providers { microsoft: Some(ms), google: None });
    let (_, shown) = round_trip(&app, &nonce, "/oauth/init.php", "/oauth/callback.php", false).await;
    assert!(shown.body.contains("Congratulations, your access request has been submitted!"), "signed in as other-tenant.example, not example.com");
    assert_eq!(scalar(&pool, "SELECT count(*) FROM session WHERE user_id = 5").await, 0);
    let (domain, status): (String, String) = sqlx::query_as(r#"SELECT domain, status FROM "user" WHERE id > 6"#).fetch_one(&pool).await.unwrap();
    assert_eq!((domain.as_str(), status.as_str()), ("other-tenant.example", "Pending"));
}

#[tokio::test]
async fn a_callback_with_another_state_or_nonce_signs_no_one_in() {
    let pool = pool().await;
    let nonce = Arc::new(Mutex::new(String::new()));
    let ms = stand_in(Kind::Microsoft, serde_json::json!({"mail": "pat@example.com", "userPrincipalName": "pat@example.com"}), nonce.clone(), serde_json::json!({})).await;
    let app = app_with(&pool, Providers { microsoft: Some(ms), google: None });
    let (_, shown) = round_trip(&app, &nonce, "/oauth/init.php", "/oauth/callback.php", true).await;
    assert!(shown.body.contains("<p style='color:red;'>Invalid OAuth state. Please try again.</p>"));
    assert!(shown.body.contains("<div id=profile_register>"));

    let nonce2 = Arc::new(Mutex::new(String::new()));
    let ms = stand_in(Kind::Microsoft, serde_json::json!({"mail": "pat@example.com", "userPrincipalName": "pat@example.com"}), nonce2.clone(), serde_json::json!({"nonce": "other"})).await;
    let app = app_with(&pool, Providers { microsoft: Some(ms), google: None });
    let (_, shown) = round_trip(&app, &nonce2, "/oauth/init.php", "/oauth/callback.php", false).await;
    assert!(shown.body.contains("Microsoft authentication failed. Please try again."));
    assert_eq!(scalar(&pool, "SELECT count(*) FROM session").await, 0);
}

#[tokio::test]
async fn google_needs_a_verified_address() {
    let pool = pool().await;
    let nonce = Arc::new(Mutex::new(String::new()));
    let g = stand_in(Kind::Google, serde_json::json!({"email": "pat@example.com", "email_verified": false}), nonce.clone(), serde_json::json!({})).await;
    let app = app_with(&pool, Providers { microsoft: None, google: Some(g) });
    let (_, shown) = round_trip(&app, &nonce, "/oauth/google_init.php", "/oauth/google_callback.php", false).await;
    assert!(shown.body.contains("Your Google email address is not verified. Please verify it and try again."));
    assert_eq!(scalar(&pool, "SELECT count(*) FROM session").await, 0);

    let nonce = Arc::new(Mutex::new(String::new()));
    let g = stand_in(Kind::Google, serde_json::json!({"email": "Pat@Example.com", "email_verified": true}), nonce.clone(), serde_json::json!({})).await;
    let app = app_with(&pool, Providers { microsoft: None, google: Some(g) });
    let (_, shown) = round_trip(&app, &nonce, "/oauth/google_init.php", "/oauth/google_callback.php", false).await;
    assert!(shown.body.contains("Congratulations, you now have access!"));
}

#[tokio::test]
async fn an_unconfigured_provider_says_so() {
    let pool = pool().await;
    let app = app(&pool);
    let start = send(&app, Method::GET, "/oauth/init.php", "", None, false).await;
    assert_eq!(start.headers.get(header::LOCATION).unwrap(), "/request-access");
    let shown = page(&app, &start.jar()).await;
    assert!(shown.body.contains("OAuth configuration is missing. Contact the administrator."));
}

#[tokio::test]
async fn a_callback_without_a_sign_in_under_way_leaves_its_message_for_the_visit() {
    let pool = pool().await;
    let app = app(&pool);
    let back = send(&app, Method::GET, "/oauth/google_callback.php?state=made-up&code=x", "", None, false).await;
    assert_eq!(back.headers.get(header::LOCATION).unwrap(), "/request-access");
    let shown = page(&app, "").await;
    assert!(shown.body.contains("<div id=scscpq_content><p style='color:red;'>Invalid OAuth state. Please try again.</p>"), "{}", shown.body);
    let again = page(&app, "").await;
    assert!(!again.body.contains("Invalid OAuth state"), "shown once");
}
