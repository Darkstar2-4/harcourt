//! The whole router, as main.rs mounts it (path clean-up, visitor layer, every area), over an in-memory database of
//! synthetic rows. Requests of any method, path, query, header set and body go to every path the program claims;
//! each is answered within a time bound and without a panic.

use std::{
    collections::{BTreeMap, BTreeSet},
    net::SocketAddr,
    path::Path,
    sync::{Arc, Mutex, OnceLock},
    time::Duration,
};

use axum::{
    body::Body,
    extract::{connect_info::MockConnectInfo, Request},
    http::{header, HeaderMap, HeaderName, HeaderValue, Method, StatusCode},
    Router,
};
use http_body_util::BodyExt;
use hxweb::{api::Auth, config::Secret, db, identity::UserId, routes, session, staff::csrf::CsrfToken, visitor::apache_paths};
use hxweb_fuzz::{cut, gen, shard};
use proptest::{prelude::*, sample::select, test_runner::TestRunner};
use sqlx::SqlitePool;
use tower::ServiceExt;

const ANSWER: Duration = Duration::from_secs(10);

/// Every site path the source names: route literals, path constants and the WordPress page list.
fn claimed() -> &'static [String] {
    static PATHS: OnceLock<Vec<String>> = OnceLock::new();
    PATHS.get_or_init(|| {
        let route = regex::Regex::new(r#"\.route\(\s*"(/[^"]*)""#).unwrap();
        let constant = regex::Regex::new(r#"const [A-Z_]+: &str = "(/[^"]*)";"#).unwrap();
        let listed = regex::Regex::new(r#"(?m)^\s*"(/[^"]*)",\s*$"#).unwrap();
        let mut found = BTreeSet::new();
        let mut stack = vec![Path::new(env!("CARGO_MANIFEST_DIR")).join("../src")];
        while let Some(dir) = stack.pop() {
            for entry in std::fs::read_dir(&dir).expect("the hxweb source").flatten() {
                let p = entry.path();
                if p.is_dir() && !p.ends_with("bin") {
                    stack.push(p);
                } else if p.extension().is_some_and(|e| e == "rs") {
                    let text = std::fs::read_to_string(&p).unwrap();
                    let api = p.ends_with("api.rs") && p.parent().is_some_and(|d| d.ends_with("src"));
                    for c in route.captures_iter(&text) {
                        found.insert(if api { format!("/api{}", &c[1]) } else { c[1].to_string() });
                    }
                    for c in constant.captures_iter(&text) {
                        found.insert(c[1].to_string());
                    }
                    if p.ends_with("routes/wordpress.rs") {
                        for c in listed.captures_iter(&text) {
                            found.insert(c[1].to_string());
                        }
                    }
                }
            }
        }
        assert!(found.len() > 100, "found only {} paths", found.len());
        found.into_iter().collect()
    })
}

/// Names the pages read from a query or form, from the source.
fn field_names() -> &'static [String] {
    static NAMES: OnceLock<Vec<String>> = OnceLock::new();
    NAMES.get_or_init(|| {
        let get = regex::Regex::new(r#"\b(?:get|has|int|checked|all|contains_key)\(&?"([a-z][a-z0-9_]{0,30})"\)"#).unwrap();
        let mut found = BTreeSet::new();
        let mut stack = vec![Path::new(env!("CARGO_MANIFEST_DIR")).join("../src")];
        while let Some(dir) = stack.pop() {
            for entry in std::fs::read_dir(&dir).unwrap().flatten() {
                let p = entry.path();
                if p.is_dir() {
                    stack.push(p);
                } else if p.extension().is_some_and(|e| e == "rs") {
                    for c in get.captures_iter(&std::fs::read_to_string(&p).unwrap()) {
                        found.insert(c[1].to_string());
                    }
                }
            }
        }
        found.into_iter().collect()
    })
}

struct Persona {
    cookie: Option<String>,
    csrf: Option<String>,
}

struct World {
    rt: tokio::runtime::Runtime,
    app: tower::util::MapRequest<Router, fn(Request) -> Request>,
    personas: Vec<Persona>,
}

const META: &str = r#"{"id":1,"title":"Contact","button":{"text":"Submit"},"enableHoneypot":true,"honeypotAction":"spam","markupVersion":2,
    "fields":[{"type":"text","id":1,"label":"Name","isRequired":true},{"type":"email","id":2,"label":"Email","isRequired":true},
              {"type":"phone","id":3,"label":"Phone","phoneFormat":"standard"},{"type":"date","id":4,"label":"Date"},
              {"type":"textarea","id":5,"label":"Message"}]}"#;
const NOTIFICATIONS: &str = r#"{"a":{"name":"Admin","isActive":true,"event":"form_submission","toType":"email","to":"desk@example.com","fromName":"{Name:1}","replyTo":"{Email:2}","subject":"From {Name:1}","message":"{all_fields}"}}"#;
const CONFIRMATIONS: &str = r#"{"c":{"isDefault":true,"type":"message","message":"Thanks"}}"#;
const ISLANDS: &str = r#"[{"form":1,"ajax":false,"theme":"gravity-theme","styles":"[]","in_content":true}]"#;

async fn seed(pool: &SqlitePool) -> Vec<Persona> {
    let rows = [
        "INSERT INTO language (code, name, local_name, priority, active) VALUES ('EN', 'English', 'English', 1, 1), ('FR', 'French', 'Français', 2, 1)",
        "INSERT INTO currency (code, name, plural, exchange_rate, active) VALUES ('USD', 'US Dollar', 'US Dollars', 1, 1), ('CAD', 'Canadian Dollar', 'Canadian Dollars', 1.37, 1)",
        "INSERT INTO category (id, name) VALUES (1, 'Pins'), (2, 'Clamps')",
        "INSERT INTO subcategory (id, category_id, name, status, leadtime_option) VALUES (7, 1, 'Ball Lock Pins', 'Active', 'In Stock'), (8, 2, 'Toggle Clamps', 'Pending', '')",
        r#"INSERT INTO inventory (id, sku, category_id, subcategory_id, active, leadtime_option, pricing) VALUES
           (1, 'HBLT-1', 1, 7, 1, 'Subcategory', '[{"price":10.0,"qty_break":1}]'),
           (2, 'HBLT-2', 1, 7, 1, '', ''), (3, 'TC-9', 2, 8, 1, '', '[]')"#,
        "INSERT INTO content (record_type, record_id, record_item, language_code, name) VALUES ('subcategory', 7, '', 'EN', 'Ball lock pins'), ('category', 2, '', 'FR', 'Brides')",
        "INSERT INTO wp_gf_form (id, title, date_created, is_active, is_trash) VALUES (1, 'Contact', '2020-01-01 00:00:00', 1, 0)",
    ];
    for sql in rows {
        sqlx::query(sql).execute(pool).await.unwrap();
    }
    sqlx::query("INSERT INTO wp_gf_form_meta (form_id, display_meta, notifications, confirmations) VALUES (1, ?1, ?2, ?3)")
        .bind(META)
        .bind(NOTIFICATIONS)
        .bind(CONFIRMATIONS)
        .execute(pool)
        .await
        .unwrap();
    for (id, lang, path, template) in [
        (165, "en", "/contact/", "layouts/template-contact.php"),
        (166, "fr", "/nous-contacter/", "layouts/template-contact.php"),
        (170, "en", "/product/alignment/", "layouts/template-product-single.php"),
        (171, "en", "/", ""),
    ] {
        sqlx::query("INSERT INTO cms_page (id, group_id, lang, path, template, status, title, menu_order, published_at, modified_at) VALUES (?1, ?1, ?2, ?3, ?4, 'publish', 'A page', 0, '2020-01-01 00:00:00', '2020-01-02 00:00:00')")
            .bind(id)
            .bind(lang)
            .bind(path)
            .bind(template)
            .execute(pool)
            .await
            .unwrap();
        for (name, value) in [("title", "A page - Example"), ("islands", ISLANDS)] {
            sqlx::query("INSERT INTO cms_page_meta (page_id, name, value) VALUES (?1, ?2, ?3)").bind(id).bind(name).bind(value).execute(pool).await.unwrap();
        }
    }
    let mut personas = vec![Persona { cookie: None, csrf: None }, Persona { cookie: Some("PHPSESSID=fuzz".into()), csrf: None }];
    let people = [(10, "Active", "Customer"), (11, "Pending", "Customer"), (12, "Denied", "Customer"), (20, "Active", "Executive"), (21, "Active", "Administrator"), (22, "Active", "Super User")];
    for (id, status, role) in people {
        sqlx::query(r#"INSERT INTO "user" (id, status, "type", company_name, catalog, ecommerce, currency_code, country_code, language_code) VALUES (?1, ?2, ?3, 'Example company', 1, 1, 'USD', 'US', 'EN')"#)
            .bind(id)
            .bind(status)
            .bind(role)
            .execute(pool)
            .await
            .unwrap();
        let token = session::create(pool, UserId(id), Some(&format!("person{id}@example.com")), db::unix_now()).await.unwrap();
        let cookie = format!("{}={}; PHPSESSID=fuzz{id}", session::COOKIE, token.as_str());
        let mut headers = HeaderMap::new();
        headers.insert(header::COOKIE, HeaderValue::from_str(&cookie).unwrap());
        let csrf = CsrfToken::for_request(&headers).map(|t| t.as_str().to_string());
        personas.push(Persona { cookie: Some(cookie), csrf });
    }
    personas
}

/// Each shard's own server and database, so shards run side by side.
fn world() -> &'static World {
    thread_local! {
        static WORLD: &'static World = Box::leak(Box::new(new_world()));
    }
    WORLD.with(|w| *w)
}

/// Nothing seeded configures an outside service (reCAPTCHA, Akismet, PARTsolutions, ipstack, OAuth), and bot
/// checks use fixed DNS answers, so no request leaves the process.
fn new_world() -> World {
    let rt = tokio::runtime::Builder::new_current_thread().enable_all().build().unwrap();
    let (app, personas) = rt.block_on(async {
        let pool = db::open_memory().await.unwrap();
        let personas = seed(&pool).await;
        let auth = Arc::new(Auth {
            pool,
            secret: Some(Secret::from_bytes(vec![7; 32])),
            public_origin: "https://www.harcourt.co".into(),
            trusted_proxies: vec!["127.0.0.1".parse().unwrap()],
            accounts: hxweb::accounts::Services::offline(hxweb::accounts::bot::Dns::fixed(&[], &[])),
        });
        let app = routes::all(auth).layer(MockConnectInfo(SocketAddr::from(([127, 0, 0, 1], 40000))));
        (tower::ServiceBuilder::new().map_request(apache_paths as fn(Request) -> Request).service(app), personas)
    });
    World { rt, app, personas }
}

#[derive(Debug, Clone)]
struct Sent {
    method: Method,
    target: String,
    persona: usize,
    headers: Vec<(String, String)>,
    body: Vec<u8>,
}

/// A claimed path as visitors and their browsers reach it: placeholders filled, a slash added or dropped, other
/// case, more path after it, a doubled slash, an escaped character.
fn filled() -> BoxedStrategy<String> {
    static PLACEHOLDERS: OnceLock<(regex::Regex, regex::Regex)> = OnceLock::new();
    let (star, one) = PLACEHOLDERS.get_or_init(|| (regex::Regex::new(r"\{\*[a-z_]+\}").unwrap(), regex::Regex::new(r"\{[a-z_]+\}").unwrap()));
    (select(claimed().to_vec()), "[a-z0-9_.-]{1,12}", gen::path())
        .prop_map(move |(p, seg, rest)| one.replace_all(&star.replace_all(&p, rest.trim_start_matches('/')), seg.as_str()).into_owned())
        .boxed()
}

fn path() -> impl Strategy<Value = String> {
    prop_oneof![
        6 => filled(),
        3 => (filled(), 0u8..6, gen::path(), any::<prop::sample::Index>()).prop_map(|(p, how, more, at)| match how {
            0 => if p.ends_with('/') { p.trim_end_matches('/').to_string() } else { format!("{p}/") },
            1 => p.to_uppercase(),
            2 => format!("{}{more}", p.trim_end_matches('/')),
            3 => format!("/{p}"),
            4 => {
                let i = at.index(p.len().max(1));
                match p.char_indices().nth(i) {
                    Some((b, c)) if c.is_ascii_alphanumeric() => format!("{}%{:02X}{}", &p[..b], c as u32, &p[b + 1..]),
                    _ => p,
                }
            }
            _ => format!("{p}{more}"),
        }),
        1 => gen::path(),
    ]
}

fn field_pair() -> impl Strategy<Value = String> {
    let names = select(field_names().to_vec());
    (names, prop_oneof![3 => "[a-zA-Z0-9 ]{0,8}".boxed(), 1 => gen::url_text(4).boxed(), 1 => "-?[0-9]{1,12}".boxed()]).prop_map(|(k, v)| format!("{k}={}", v.replace(' ', "+")))
}

fn query() -> impl Strategy<Value = String> {
    prop_oneof![
        2 => Just(String::new()),
        4 => proptest::collection::vec(field_pair(), 1..6).prop_map(|v| v.join("&")),
        2 => gen::query(),
    ]
}

fn method() -> impl Strategy<Value = Method> {
    prop_oneof![
        8 => Just(Method::GET),
        6 => Just(Method::POST),
        1 => Just(Method::HEAD),
        1 => select(vec![Method::PUT, Method::DELETE, Method::OPTIONS, Method::PATCH, Method::from_bytes(b"FUZZ").unwrap()]),
    ]
}

fn headers() -> impl Strategy<Value = Vec<(String, String)>> {
    fn named(name: &'static str, values: impl Strategy<Value = String> + 'static) -> BoxedStrategy<(String, String)> {
        values.prop_map(move |v| (name.to_string(), v)).boxed()
    }
    let one = prop_oneof![
        named("referer", prop_oneof!["https://(www\\.)?[a-z]{1,8}\\.(com|co|fr)/[a-z]{0,6}".boxed(), gen::text(20).boxed()]),
        named("user-agent", prop_oneof![select(vec!["Mozilla/5.0 (Windows NT 10.0) Chrome/120.0", "Googlebot/2.1 (+http://www.google.com/bot.html)", "curl/8.0", ""]).prop_map(str::to_string).boxed(), gen::text(40).boxed()]),
        named("accept-language", prop_oneof!["(fr|en|de)(-[A-Z]{2})?(,(fr|en);q=0\\.[0-9])?".boxed(), gen::text(20).boxed()]),
        named("x-requested-with", Just("XMLHttpRequest".to_string())),
        named("x-forwarded-for", "(10|192|203)\\.[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}"),
        named("origin", prop_oneof![Just("https://www.harcourt.co".to_string()).boxed(), "https://[a-z]{1,8}\\.com".boxed()]),
        named("accept", select(vec!["text/html", "application/json", "*/*"]).prop_map(str::to_string)),
        named("cookie", prop_oneof!["harcourt=[a-z0-9%@.]{0,20}".boxed(), "harcourt_profile=[a-zA-Z0-9%]{0,30}".boxed(), gen::text(30).boxed()]),
    ];
    proptest::collection::vec(one, 0..4)
}

/// A body of the shape its Content-Type says, or not.
fn body() -> impl Strategy<Value = (Option<String>, Vec<u8>)> {
    let form = proptest::collection::vec(prop_oneof![3 => field_pair(), 1 => gen::pair()], 0..10).prop_map(|v| v.join("&"));
    let part = (select(field_names().to_vec()), proptest::option::of(prop_oneof![Just("upload.csv"), Just("upload.xlsx"), Just("upload.txt"), Just("x")]), gen::bytes(200));
    prop_oneof![
        3 => form.prop_map(|f| (Some("application/x-www-form-urlencoded".to_string()), f.into_bytes())),
        1 => proptest::collection::vec(part, 0..4).prop_map(|parts| {
            let mut out = Vec::new();
            for (name, file, bytes) in parts {
                out.extend_from_slice(b"--fuzzboundary\r\n");
                match file {
                    Some(f) => out.extend_from_slice(format!("Content-Disposition: form-data; name=\"{name}\"; filename=\"{f}\"\r\nContent-Type: application/octet-stream\r\n\r\n").as_bytes()),
                    None => out.extend_from_slice(format!("Content-Disposition: form-data; name=\"{name}\"\r\n\r\n").as_bytes()),
                }
                out.extend_from_slice(&bytes);
                out.extend_from_slice(b"\r\n");
            }
            out.extend_from_slice(b"--fuzzboundary--\r\n");
            (Some("multipart/form-data; boundary=fuzzboundary".to_string()), out)
        }),
        1 => (proptest::option::of(select(vec!["application/json", "text/plain", "multipart/form-data", "application/x-www-form-urlencoded; charset=UTF-8"]).prop_map(str::to_string)), gen::bytes(300)),
        1 => proptest::collection::vec((select(field_names().to_vec()), prop_oneof![
            "\"[a-z0-9@.]{0,20}\"".boxed(), "-?[0-9]{1,6}".boxed(), Just("null".to_string()).boxed(), Just("[]".to_string()).boxed(), gen::text(12).prop_map(|t| serde_json::json!(t).to_string()).boxed(),
        ]), 0..5).prop_map(|kv| {
            let body = kv.iter().map(|(k, v)| format!("\"{k}\":{v}")).collect::<Vec<_>>().join(",");
            (Some("application/json".to_string()), format!("{{{body}}}").into_bytes())
        }),
    ]
}

fn request() -> impl Strategy<Value = Sent> {
    (method(), path(), query(), 0usize..8, headers(), body(), any::<bool>()).prop_map(|(method, path, q, persona, mut headers, (kind, mut body), with_token)| {
        let target = if q.is_empty() { path } else { format!("{path}?{q}") };
        if method == Method::GET || method == Method::HEAD {
            body.clear();
        } else if let Some(k) = kind {
            headers.push(("content-type".into(), k));
        }
        if with_token && !body.is_empty() && headers.iter().any(|(k, v)| k == "content-type" && v.starts_with("application/x-www-form-urlencoded")) {
            body.extend_from_slice(b"&csrf=");
            body.extend_from_slice(b"{token}");
        }
        Sent { method, target, persona, headers, body }
    })
}

fn send(s: &Sent) -> Result<StatusCode, TestCaseError> {
    let w = world();
    let persona = &w.personas[s.persona % w.personas.len()];
    let Ok(uri) = s.target.parse::<axum::http::Uri>() else { return Ok(StatusCode::BAD_REQUEST) };
    let mut req = Request::builder().method(s.method.clone()).uri(uri);
    let mut cookies: Vec<String> = persona.cookie.iter().cloned().collect();
    for (k, v) in &s.headers {
        if k == "cookie" {
            cookies.push(v.clone());
        } else if let (Ok(name), Ok(value)) = (HeaderName::try_from(k.as_str()), HeaderValue::from_str(v)) {
            req = req.header(name, value);
        }
    }
    if let Ok(v) = HeaderValue::from_str(&cookies.join("; ")) {
        if !cookies.is_empty() {
            req = req.header(header::COOKIE, v);
        }
    }
    let token = persona.csrf.as_deref().unwrap_or("none");
    let body = String::from_utf8_lossy(&s.body).replace("{token}", token).into_bytes();
    let req = req.body(Body::from(body)).unwrap();
    let app = w.app.clone();
    let answered = w.rt.block_on(async move {
        tokio::time::timeout(ANSWER, async move {
            let res = app.oneshot(req).await.expect("infallible");
            let status = res.status();
            let bytes = res.into_body().collect().await.map(|b| b.to_bytes().len());
            (status, bytes)
        })
        .await
    });
    let Ok((status, bytes)) = answered else {
        return Err(TestCaseError::fail(format!("no answer within {ANSWER:?}")));
    };
    prop_assert!(bytes.is_ok(), "the body could not be read");
    Ok(status)
}

/// Where a 5xx came from: the path up to its script, or its first two segments.
fn origin_of(target: &str) -> String {
    let path = target.split('?').next().unwrap_or("");
    match path.to_ascii_lowercase().find(".php") {
        Some(i) => path[..i + 4].to_string(),
        None => path.split('/').take(3).collect::<Vec<_>>().join("/"),
    }
}

/// Runs one shard of requests and prints how they were answered, and where the 5xx answers came from (a page that
/// fails as its PHP did, or a database error), for a person to read.
fn run_shard(n: u64) {
    let statuses = Mutex::new(BTreeMap::<u16, u64>::new());
    let failing = Mutex::new(BTreeMap::<String, (u64, String)>::new());
    let mut runner = TestRunner::new(shard(2500, 5, n));
    let result = runner.run(&request(), |s| {
        let status = send(&s)?;
        *statuses.lock().unwrap().entry(status.as_u16()).or_default() += 1;
        if status.is_server_error() {
            let mut failing = failing.lock().unwrap();
            let seen = failing.entry(format!("{} {} {}", status.as_u16(), s.method, origin_of(&s.target))).or_insert_with(|| {
                (0, cut(&format!("{} {} persona {} headers {:?} body {:?}", s.method, s.target, s.persona, s.headers, String::from_utf8_lossy(&s.body))))
            });
            seen.0 += 1;
        }
        Ok(())
    });
    eprintln!("router shard {n}: {} paths, {} field names; statuses {:?}", claimed().len(), field_names().len(), statuses.lock().unwrap());
    let mut failing: Vec<(String, (u64, String))> = failing.into_inner().unwrap().into_iter().collect();
    failing.sort_by_key(|f| std::cmp::Reverse(f.1 .0));
    for (f, (count, example)) in failing.iter().take(60) {
        eprintln!("router shard {n}: {count} x {f}, as in {example}");
    }
    if let Err(e) = result {
        panic!("{e}");
    }
}

#[test]
fn every_claimed_path_answers_1() {
    run_shard(1);
}

#[test]
fn every_claimed_path_answers_2() {
    run_shard(2);
}

#[test]
fn every_claimed_path_answers_3() {
    run_shard(3);
}

#[test]
fn every_claimed_path_answers_4() {
    run_shard(4);
}

#[test]
fn every_claimed_path_answers_5() {
    run_shard(5);
}

#[test]
fn every_claimed_path_answers_6() {
    run_shard(6);
}

#[test]
fn every_claimed_path_answers_7() {
    run_shard(7);
}

#[test]
fn every_claimed_path_answers_8() {
    run_shard(8);
}
