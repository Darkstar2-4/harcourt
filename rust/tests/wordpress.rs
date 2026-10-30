//! The WordPress page routes and the cms editing functions against a private in-memory database with synthetic
//! content. Nothing here reads or copies production data; every row is inserted by the test that uses it.

use std::sync::Arc;

use axum::{
    body::Body,
    http::{header, Method, Request, StatusCode},
    Router,
};
use http_body_util::BodyExt;
use hxweb::{
    api::Auth,
    cms::{
        edit::{self, Change},
        forms::{FormSource, Markup},
        FormEmbed, PageId,
    },
    db,
    identity::UserId,
    routes::wordpress,
};
use sqlx::SqlitePool;
use tower::ServiceExt;

struct StubForms;

impl FormSource for StubForms {
    fn markup<'a>(&'a self, form: &'a FormEmbed<'a>) -> Markup<'a> {
        Box::pin(async move { Ok(format!("<form id=stub-{} data-page=\"{}\"></form>", form.form, form.page_uri)) })
    }
}

const ISLANDS: &str = r#"[{"form":1,"ajax":true,"theme":"legacy","styles":"","in_content":false}]"#;

async fn seed(pool: &SqlitePool) {
    let pages = [
        (165, "en", "/product/alignment/", "Alignment"),
        (2010, "fr", "/gamme-de-produits/centrage/", "Centrage"),
    ];
    for (id, lang, path, title) in pages {
        sqlx::query("INSERT INTO cms_page (id, group_id, lang, path, template, status, title, menu_order, published_at, modified_at) VALUES (?1, 1, ?2, ?3, 'layouts/template-product-single.php', 'publish', ?4, 0, '2020-01-01 00:00:00', '2020-01-02 00:00:00')")
            .bind(id)
            .bind(lang)
            .bind(path)
            .bind(title)
            .execute(pool)
            .await
            .unwrap();
        for (name, kind, value) in [
            ("hero_title", "html", format!("Synthetic {title} <b>hero</b>")),
            ("hero_headline", "html", "Example headline".to_string()),
            ("hero_description", "html", "An example description. ".to_string()),
            ("block_one", "flag", String::new()),
        ] {
            sqlx::query("INSERT INTO cms_field (page_id, name, kind, value) VALUES (?1, ?2, ?3, ?4)").bind(id).bind(name).bind(kind).bind(value).execute(pool).await.unwrap();
        }
        for (name, value) in [
            ("title", format!("{title} - Example")),
            ("description", "A synthetic page".to_string()),
            ("canonical", format!("https://www.harcourt.co{path}")),
            ("islands", ISLANDS.to_string()),
            ("breadcrumb_title", title.to_string()),
            ("robots", "index, follow".to_string()),
        ] {
            sqlx::query("INSERT INTO cms_page_meta (page_id, name, value) VALUES (?1, ?2, ?3)").bind(id).bind(name).bind(value).execute(pool).await.unwrap();
        }
    }
    for lang in ["en", "fr"] {
        for (name, kind, value) in [("cta_title", "html", "Join us"), ("cta_btn", "html", "Ask"), ("cta_list", "count", "0"), ("@breadcrumbs_home", "html", "Home"), ("website_notification", "html", "")] {
            sqlx::query("INSERT INTO cms_option (lang, name, kind, value) VALUES (?1, ?2, ?3, ?4)").bind(lang).bind(name).bind(kind).bind(value).execute(pool).await.unwrap();
        }
    }
    sqlx::query("INSERT INTO cms_revision (page_id, saved_at, saved_by, note, fields) VALUES (165, 0, 0, 'import', '{}')").execute(pool).await.unwrap();
}

async fn harness() -> (Router, SqlitePool) {
    let pool = db::open_memory().await.unwrap();
    seed(&pool).await;
    let auth = Arc::new(Auth { pool: pool.clone(), secret: None, public_origin: "https://www.harcourt.co".into(), trusted_proxies: vec![] });
    (wordpress::router_with_forms(auth, Some(Arc::new(StubForms))), pool)
}

async fn send(app: &Router, method: Method, uri: &str) -> (StatusCode, Option<String>, String) {
    let res = app.clone().oneshot(Request::builder().method(method).uri(uri).body(Body::empty()).unwrap()).await.unwrap();
    let status = res.status();
    let location = res.headers().get(header::LOCATION).map(|v| v.to_str().unwrap().to_string());
    let body = String::from_utf8(res.into_body().collect().await.unwrap().to_bytes().to_vec()).unwrap();
    (status, location, body)
}

#[tokio::test]
async fn a_ported_page_renders_from_the_cms_tables_with_its_forms() {
    let (app, _) = harness().await;
    let (status, _, html) = send(&app, Method::GET, "/product/alignment/").await;
    assert_eq!(status, StatusCode::OK);
    assert!(html.starts_with("<!DOCTYPE html>\n<html class=\"no-js\" lang=\"en-US\">"));
    assert!(html.contains("<title>Alignment - Example</title>"));
    assert!(html.contains("<h1 class=\"mt-5\">Synthetic Alignment <b>hero</b></h1>"));
    assert!(html.contains("<link rel=\"alternate\" hreflang=\"fr\" href=\"https://www.harcourt.co/gamme-de-produits/centrage/?lang=fr\" />"));
    assert!(html.contains("<span class=\"breadcrumb_last\" aria-current=\"page\">Alignment</span>"));
    assert!(html.contains("<form id=stub-1 data-page=\"/product/alignment/\"></form>"));
    assert!(!html.contains("{{"), "every hole is filled");

    let (status, _, html) = send(&app, Method::GET, "/gamme-de-produits/centrage/?lang=fr&utm_source=x").await;
    assert_eq!(status, StatusCode::OK);
    assert!(html.contains("lang=\"fr-FR\""));
    assert!(html.contains("<form id=stub-1 data-page=\"/gamme-de-produits/centrage/?lang=fr&utm_source=x\"></form>"));
}

#[tokio::test]
async fn other_shapes_of_a_page_url_redirect_like_wordpress() {
    let (app, _) = harness().await;
    assert_eq!(send(&app, Method::GET, "/product/alignment").await.1.as_deref(), Some("/product/alignment/"));
    assert_eq!(send(&app, Method::GET, "/product/alignment?lang=fr").await.1.as_deref(), Some("/product/alignment/?lang=fr"));
    let (status, location, _) = send(&app, Method::GET, "/product/alignment/?lang=fr").await;
    assert_eq!((status, location.as_deref()), (StatusCode::MOVED_PERMANENTLY, Some("/gamme-de-produits/centrage/?lang=fr")));
    let (status, location, _) = send(&app, Method::GET, "/gamme-de-produits/centrage/").await;
    assert_eq!((status, location.as_deref()), (StatusCode::MOVED_PERMANENTLY, Some("/gamme-de-produits/centrage/?lang=fr")));
    assert_eq!(send(&app, Method::GET, "/product/clamping/").await.0, StatusCode::NOT_FOUND);
    assert_eq!(send(&app, Method::GET, "/product/alignment/?rest_route=/wp/v2/users").await.0, StatusCode::GONE);
    assert_eq!(send(&app, Method::POST, "/product/alignment/").await.0, StatusCode::METHOD_NOT_ALLOWED);
}

#[tokio::test]
async fn robots_txt_is_the_docroot_file() {
    let (app, _) = harness().await;
    let (status, _, body) = send(&app, Method::GET, "/robots.txt").await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(body, "User-agent: *\nCrawl-delay: 30\n\n");
}

#[tokio::test]
async fn an_edit_shows_on_the_next_request_and_a_revision_restores_it() {
    let (app, pool) = harness().await;
    let rev = edit::save_page(&pool, PageId(165), &[Change { name: "hero_title".into(), value: "Edited title".into() }, Change { name: "meta:title".into(), value: "New tab title".into() }], UserId(7), "copy edit", 100)
        .await
        .unwrap();
    let (_, _, html) = send(&app, Method::GET, "/product/alignment/").await;
    assert!(html.contains("<h1 class=\"mt-5\">Edited title</h1>"));
    assert!(html.contains("<title>New tab title</title>"));

    assert_eq!(edit::save_page(&pool, PageId(165), &[Change { name: "block_one".into(), value: "1".into() }], UserId(7), "", 101).await, Err(edit::EditError::NotEditable("block_one".into())));
    assert_eq!(edit::save_page(&pool, PageId(165), &[Change { name: "meta:robots".into(), value: "noindex".into() }], UserId(7), "", 101).await, Err(edit::EditError::NotEditable("meta:robots".into())));
    assert_eq!(edit::save_page(&pool, PageId(999), &[], UserId(7), "", 101).await, Err(edit::EditError::NotFound));

    let first = edit::save_page(&pool, PageId(165), &[Change { name: "hero_title".into(), value: "Second edit".into() }], UserId(8), "", 102).await.unwrap();
    let history = edit::page_revisions(&pool, PageId(165)).await.unwrap();
    assert_eq!(history.iter().map(|r| r.id).take(2).collect::<Vec<_>>(), vec![first, rev]);
    assert_eq!(history[0].saved_by, 8);

    edit::restore(&pool, rev, UserId(9), 103).await.unwrap();
    let (_, _, html) = send(&app, Method::GET, "/product/alignment/").await;
    assert!(html.contains("<h1 class=\"mt-5\">Edited title</h1>"));
}

#[tokio::test]
async fn the_notice_banner_is_a_site_wide_option() {
    let (app, pool) = harness().await;
    edit::save_options(&pool, hxweb::cms::Lang::En, &[Change { name: "website_notification".into(), value: "Closed on Friday".into() }], UserId(7), "", 100).await.unwrap();
    let (_, _, html) = send(&app, Method::GET, "/product/alignment/").await;
    assert!(html.contains("<div class=\"legacy-website p-3\"><p class=\"m-0 small\">Closed on Friday</p></div>"));
    assert!(html.contains("body > header {height: 200px; position: fixed;"));
    assert!(html.contains("<section class=\"product-hero add-margin\""));
    let (_, _, fr) = send(&app, Method::GET, "/gamme-de-produits/centrage/?lang=fr").await;
    assert!(!fr.contains("legacy-website"), "each language has its own banner");
}
