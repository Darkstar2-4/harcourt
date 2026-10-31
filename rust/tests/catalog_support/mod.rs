//! Shared by the catalog's integration tests: the whole router over a private in-memory database, synthetic
//! accounts and requests. Every row is inserted by the test that needs it; nothing comes from production.
#![allow(dead_code)]

use std::{net::SocketAddr, sync::Arc};

use axum::{
    body::Body,
    extract::connect_info::MockConnectInfo,
    http::{header, HeaderMap, Method, Request, StatusCode},
    Router,
};
use http_body_util::BodyExt;
use hxweb::{api::Auth, config::Secret, db, identity::UserId, routes, session};
use sqlx::SqlitePool;
use tower::ServiceExt;

pub const ORIGIN: &str = "https://www.harcourt.co";

pub struct Harness {
    pub app: Router,
    pub pool: SqlitePool,
}

fn auth(pool: &SqlitePool) -> Arc<Auth> {
    Arc::new(Auth {
        pool: pool.clone(),
        secret: Some(Secret::from_bytes(vec![7; 32])),
        public_origin: ORIGIN.into(),
        trusted_proxies: vec!["127.0.0.1".parse().unwrap()],
        accounts: hxweb::accounts::Services::offline(hxweb::accounts::bot::Dns::fixed(&[], &[])),
    })
}

pub async fn harness() -> Harness {
    let pool = db::open_memory().await.unwrap();
    let app = routes::all(auth(&pool)).layer(MockConnectInfo(SocketAddr::from(([127, 0, 0, 1], 40000))));
    Harness { app, pool }
}

/// The catalog's routes alone, without the rest of the site: what the catalog claims.
pub async fn catalog_only() -> Router {
    let pool = db::open_memory().await.unwrap();
    routes::catalog::router(auth(&pool)).layer(MockConnectInfo(SocketAddr::from(([127, 0, 0, 1], 40000))))
}

/// The catalog fields of a synthetic account. `Account::customer(id)` is an Active Customer with the catalog.
pub struct Account {
    pub id: i64,
    pub status: &'static str,
    pub role: &'static str,
    pub company_name: Option<String>,
    pub catalog: bool,
    pub cad: bool,
    pub ecommerce: bool,
    pub price_type: &'static str,
    pub price_pct: f64,
    pub currency_code: &'static str,
    pub country_code: &'static str,
    pub language_code: &'static str,
    pub industry_id: i64,
    pub portal_id: i64,
    pub landing_document_id: i64,
    pub login_document_id: i64,
}

impl Account {
    pub fn customer(id: i64) -> Account {
        Account {
            id,
            status: "Active",
            role: "Customer",
            company_name: Some(format!("Test company {id}")),
            catalog: true,
            cad: false,
            ecommerce: false,
            price_type: "",
            price_pct: 0.0,
            currency_code: "USD",
            country_code: "US",
            language_code: "EN",
            industry_id: 0,
            portal_id: 0,
            landing_document_id: 0,
            login_document_id: 0,
        }
    }
}

pub async fn seed_account(pool: &SqlitePool, a: &Account) {
    sqlx::query(
        r#"INSERT INTO "user" (id, status, "type", company_name, catalog, cad, ecommerce, price_type, price_pct, currency_code,
                               country_code, language_code, industry_id, portal_id, landing_document_id, login_document_id)
           VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, ?12, ?13, ?14, ?15, ?16)"#,
    )
    .bind(a.id)
    .bind(a.status)
    .bind(a.role)
    .bind(&a.company_name)
    .bind(a.catalog as i64)
    .bind(a.cad as i64)
    .bind(a.ecommerce as i64)
    .bind(a.price_type)
    .bind(a.price_pct)
    .bind(a.currency_code)
    .bind(a.country_code)
    .bind(a.language_code)
    .bind(a.industry_id)
    .bind(a.portal_id)
    .bind(a.landing_document_id)
    .bind(a.login_document_id)
    .execute(pool)
    .await
    .unwrap();
}

/// A session cookie for an account already seeded.
pub async fn cookie_for(pool: &SqlitePool, id: i64) -> String {
    let token = session::create(pool, UserId(id), None, db::unix_now()).await.unwrap();
    format!("{}={}", session::COOKIE, token.as_str())
}

/// Seeds the account and returns its session cookie.
pub async fn signed_in(pool: &SqlitePool, a: &Account) -> String {
    seed_account(pool, a).await;
    cookie_for(pool, a.id).await
}

pub fn get(path: &str, cookie: Option<&str>) -> Request<Body> {
    request(Method::GET, path, cookie, &[], Body::empty())
}

pub fn post_form(path: &str, cookie: Option<&str>, form: &str) -> Request<Body> {
    request(Method::POST, path, cookie, &[(header::CONTENT_TYPE.as_str(), "application/x-www-form-urlencoded")], Body::from(form.to_string()))
}

/// A request from a browser in the middle of a visit: it holds the PHP session cookie.
pub fn request(method: Method, path: &str, cookie: Option<&str>, headers: &[(&str, &str)], body: Body) -> Request<Body> {
    let mut req = Request::builder().method(method).uri(path);
    req = req.header(header::COOKIE, cookie.map_or_else(|| "PHPSESSID=test".to_string(), |c| format!("{c}; PHPSESSID=test")));
    for (k, v) in headers {
        req = req.header(*k, *v);
    }
    req.body(body).unwrap()
}

/// The first request of a visit: no cookies at all.
pub fn first_get(path: &str) -> Request<Body> {
    Request::builder().uri(path).body(Body::empty()).unwrap()
}

pub async fn send(app: &Router, req: Request<Body>) -> (StatusCode, HeaderMap, String) {
    let (status, headers, bytes) = send_bytes(app, req).await;
    (status, headers, String::from_utf8_lossy(&bytes).into_owned())
}

pub async fn send_bytes(app: &Router, req: Request<Body>) -> (StatusCode, HeaderMap, Vec<u8>) {
    let res = app.clone().oneshot(req).await.unwrap();
    let status = res.status();
    let headers = res.headers().clone();
    let bytes = res.into_body().collect().await.unwrap().to_bytes().to_vec();
    (status, headers, bytes)
}
