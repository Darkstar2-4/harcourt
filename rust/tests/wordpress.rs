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
            ("breadcrumbs", format!("<span><span><a href=\"https://www.harcourt.co/\">Home</a></span> » <span class=\"breadcrumb_last\" aria-current=\"page\">{title}</span></span>")),
            ("robots", "index, follow".to_string()),
        ] {
            sqlx::query("INSERT INTO cms_page_meta (page_id, name, value) VALUES (?1, ?2, ?3)").bind(id).bind(name).bind(value).execute(pool).await.unwrap();
        }
    }
    for lang in ["en", "fr"] {
        for (name, kind, value) in [("cta_title", "html", "Join us"), ("cta_btn", "html", "Ask"), ("cta_list", "count", "0"), ("website_notification", "html", "")] {
            sqlx::query("INSERT INTO cms_option (lang, name, kind, value) VALUES (?1, ?2, ?3, ?4)").bind(lang).bind(name).bind(kind).bind(value).execute(pool).await.unwrap();
        }
    }
    sqlx::query("INSERT INTO cms_revision (page_id, saved_at, saved_by, note, fields) VALUES (165, 0, 0, 'import', '{}')").execute(pool).await.unwrap();
}

async fn harness() -> (Router, SqlitePool) {
    let pool = db::open_memory().await.unwrap();
    seed(&pool).await;
    let auth = Arc::new(Auth { pool: pool.clone(), secret: None, public_origin: "https://www.harcourt.co".into(), trusted_proxies: vec![], accounts: hxweb::accounts::Services::offline(hxweb::accounts::bot::Dns::fixed(&[], &[])) });
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
    assert_eq!(send(&app, Method::GET, "/product/alignment").await.1.as_deref(), Some("https://www.harcourt.co/product/alignment/"));
    assert_eq!(send(&app, Method::GET, "/product/alignment?lang=fr").await.1.as_deref(), Some("https://www.harcourt.co/gamme-de-produits/centrage/?lang=fr"));
    assert_eq!(send(&app, Method::GET, "/gamme-de-produits/centrage").await.1.as_deref(), Some("https://www.harcourt.co/product/alignment/"));
    let (status, location, _) = send(&app, Method::GET, "/product/alignment/?lang=fr").await;
    assert_eq!((status, location.as_deref()), (StatusCode::MOVED_PERMANENTLY, Some("https://www.harcourt.co/gamme-de-produits/centrage/?lang=fr")));
    let (status, location, _) = send(&app, Method::GET, "/gamme-de-produits/centrage/").await;
    assert_eq!((status, location.as_deref()), (StatusCode::MOVED_PERMANENTLY, Some("https://www.harcourt.co/product/alignment/")));
    let unknown = app.clone().oneshot(Request::builder().uri("/product/clamping/").body(Body::empty()).unwrap()).await.unwrap();
    assert_eq!(unknown.headers().get("x-hxweb").map(|v| v.to_str().unwrap()), Some("legacy"), "WordPress answers a page the cms lacks");
    assert_eq!(send(&app, Method::POST, "/product/clamping/").await.0, StatusCode::NOT_FOUND);
    assert_eq!(send(&app, Method::GET, "/product/alignment/?rest_route=/wp/v2/users").await.0, StatusCode::GONE);
    assert_eq!(send(&app, Method::POST, "/product/alignment/").await.0, StatusCode::OK);
}

#[tokio::test]
async fn paths_wordpress_finds_nothing_for_get_its_404_page() {
    let (app, pool) = harness().await;
    for lang in ["en", "fr"] {
        for (name, value) in [("title", "Page not found - Example"), ("robots", "noindex, follow"), ("body_class", "error404"), ("islands", ISLANDS)] {
            sqlx::query("INSERT INTO cms_option (lang, name, kind, value) VALUES (?1, ?2, 'html', ?3)").bind(lang).bind(format!("@404:{name}")).bind(value).execute(&pool).await.unwrap();
        }
    }
    sqlx::query("INSERT INTO cms_revision (lang, saved_at, saved_by, note, fields) VALUES ('en', 1, 0, 'import', '{}')").execute(&pool).await.unwrap();
    let (status, _, html) = send(&app, Method::GET, "/product/clamping/").await;
    assert_eq!(status, StatusCode::NOT_FOUND);
    assert!(html.contains("<title>Page not found - Example</title>"));
    assert!(html.contains("<body data-cmplz=1 class=\"error404\">"));
    assert!(html.contains("</i> Error 404\n"));
    assert!(html.contains("<form id=stub-1 data-page=\"/product/clamping/\"></form>"), "the footer form posts to the path asked for");
    let (status, _, html) = send(&app, Method::GET, "/no/such-page/?lang=fr").await;
    assert_eq!(status, StatusCode::NOT_FOUND);
    assert!(html.contains("lang=\"fr-FR\""));
    let deep = app.clone().oneshot(Request::builder().uri("/a/b/c/").body(Body::empty()).unwrap()).await.unwrap();
    assert_eq!(deep.headers().get("x-hxweb").map(|v| v.to_str().unwrap()), Some("legacy"), "three segments list the blog under a 404, not ported");
}

#[tokio::test]
async fn a_post_the_export_left_out_stays_with_wordpress() {
    let (app, pool) = harness().await;
    sqlx::query("INSERT INTO cms_page (id, group_id, lang, path, template, status, title, menu_order, published_at, modified_at, post_type) VALUES (436, 436, 'en', '/faq/example-question/', 'single-faq.php', 'publish', 'Example question?', 0, '2020-01-01 00:00:00', '2020-01-02 00:00:00', 'faq')")
        .execute(&pool)
        .await
        .unwrap();
    let res = app.clone().oneshot(Request::builder().uri("/faq/example-question/").body(Body::empty()).unwrap()).await.unwrap();
    assert_eq!(res.headers().get("x-hxweb").map(|v| v.to_str().unwrap()), Some("legacy"), "no recorded head, no page");
}

#[tokio::test]
async fn sitemaps_list_the_pages_as_wpml_links_them() {
    let (app, _) = harness().await;
    let (status, _, xml) = send(&app, Method::GET, "/sitemap_index.xml").await;
    assert_eq!(status, StatusCode::OK);
    assert!(xml.contains("\t<sitemap>\n\t\t<loc>https://www.harcourt.co/page-sitemap.xml</loc>\n\t\t<lastmod>2020-01-02T00:00:00+00:00</lastmod>\n\t</sitemap>\n"));
    assert!(!xml.contains("post-sitemap"), "no blog posts, no post sitemap");
    let (_, _, xml) = send(&app, Method::GET, "/page-sitemap.xml?lang=fr").await;
    assert_eq!(xml.matches("<loc>https://www.harcourt.co/gamme-de-produits/centrage/?lang=fr</loc>").count(), 2, "both pages under the French URL");
    assert!(xml.starts_with("<?xml version=\"1.0\" encoding=\"UTF-8\"?><?xml-stylesheet type=\"text/xsl\" href=\"//www.harcourt.co/main-sitemap.xsl?lang=fr\"?>\n"));
    assert_eq!(send(&app, Method::GET, "/sitemap.xml").await.1.as_deref(), Some("https://www.harcourt.co/sitemap_index.xml"));
    assert_eq!(send(&app, Method::GET, "/page-sitemap1.xml").await.1.as_deref(), Some("https://www.harcourt.co/page-sitemap.xml"));
    let none = app.clone().oneshot(Request::builder().uri("/post-sitemap.xml").body(Body::empty()).unwrap()).await.unwrap();
    assert_eq!(none.headers().get("x-hxweb").map(|v| v.to_str().unwrap()), Some("legacy"));

    let (status, _, xml) = send(&app, Method::GET, "/page-sitemap.xml/").await;
    assert_eq!(status, StatusCode::OK, "Yoast's rules match without the trailing slash");
    assert!(xml.contains("<loc>https://www.harcourt.co/"));
    assert_eq!(send(&app, Method::GET, "/sitemap_index.xml?lang=en").await.1.as_deref(), Some("/sitemap_index.xml/"), "WPML's 302 away from lang=en");
    assert_eq!(send(&app, Method::GET, "/page-sitemap.xml?lang=en").await.1.as_deref(), Some("/page-sitemap.xml/"));
    assert_eq!(send(&app, Method::GET, "/sitemap.xml?lang=fr").await.1.as_deref(), Some("https://www.harcourt.co/sitemap_index.xml?lang=fr"));
    let (status, location, _) = send(&app, Method::GET, "/index.php?lang=en").await;
    assert_eq!((status, location.as_deref()), (StatusCode::FOUND, Some("/index.php/")), "WPML's 302 comes before the front controller's");
    assert_eq!(send(&app, Method::GET, "/index.php?x=1").await.1.as_deref(), Some("https://www.harcourt.co/?x=1"));
    assert_eq!(send(&app, Method::GET, "/index.php/?lang=en").await.1.as_deref(), Some("/index.php/"));
    assert_eq!(send(&app, Method::GET, "/sitemap.xml?lang=en").await.1.as_deref(), Some("/sitemap.xml/"));
    let blog = app.clone().oneshot(Request::builder().uri("/sitemap.xml?order=asc").body(Body::empty()).unwrap()).await.unwrap();
    assert_eq!(blog.headers().get("x-hxweb").map(|v| v.to_str().unwrap()), Some("legacy"), "any other query is the blog query's 404");
}

#[tokio::test]
async fn the_request_access_page_renders_its_template_in_both_languages() {
    let (app, pool) = harness().await;
    for (id, lang, path) in [(133, "en", "/request-access/"), (2013, "fr", "/demande-dacces/")] {
        sqlx::query("INSERT INTO cms_page (id, group_id, lang, path, template, status, title, menu_order, published_at, modified_at) VALUES (?1, 133, ?2, ?3, 'layouts/template-request.php', 'publish', 'Access', 0, '2020-01-01 00:00:00', '2020-01-02 00:00:00')")
            .bind(id)
            .bind(lang)
            .bind(path)
            .execute(&pool)
            .await
            .unwrap();
        for (name, kind, value) in [
            ("hero_title", "html", "Synthetic access"),
            ("hero_description", "html", "<p>Example words</p>"),
            ("hero_list", "count", "2"),
            ("hero_list_0_item", "html", "First"),
            ("hero_list_1_item", "html", "Second"),
            ("block_two_title", "html", "Block two"),
        ] {
            sqlx::query("INSERT INTO cms_field (page_id, name, kind, value) VALUES (?1, ?2, ?3, ?4)").bind(id).bind(name).bind(kind).bind(value).execute(&pool).await.unwrap();
        }
        for (name, value) in [("title", "Access - Example"), ("islands", ISLANDS)] {
            sqlx::query("INSERT INTO cms_page_meta (page_id, name, value) VALUES (?1, ?2, ?3)").bind(id).bind(name).bind(value).execute(&pool).await.unwrap();
        }
    }

    let (status, _, html) = send(&app, Method::GET, "/request-access/").await;
    assert_eq!(status, StatusCode::OK);
    assert!(html.contains("<h1 class=\"mt-4\">Synthetic access </h1>"));
    assert!(html.contains("<ul>\n\n              <li>First</li><li>Second</li>            </ul>"));
    assert!(html.contains("<div id=\"scscpq_page\"><div class=\"noshow\">newuser</div></div>"));
    assert!(html.contains("<section class='content-section site-padding left light'><div class='container-fluid'>"));
    assert!(html.contains("<h2>Block two</h2>"));
    assert!(html.contains("Get Access to Our Full Catalog") && html.contains("href=\"#signup\"> New User Sign Up </a>"));

    let (status, _, html) = send(&app, Method::GET, "/demande-dacces/?lang=fr").await;
    assert_eq!(status, StatusCode::OK);
    assert!(html.contains("Accédez à l'ensemble de notre catalogue") && html.contains("href=\"#signup\"> Inscription nouvel utilisateur </a>"));

    assert_eq!(send(&app, Method::GET, "/request-access").await.1.as_deref(), Some("https://www.harcourt.co/request-access/"));
    assert_eq!(send(&app, Method::GET, "/request-access/?lang=fr").await.1.as_deref(), Some("https://www.harcourt.co/demande-dacces/?lang=fr"));
    assert_eq!(send(&app, Method::GET, "/demande-dacces/").await.1.as_deref(), Some("https://www.harcourt.co/request-access/"));
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

async fn admin_cookie(pool: &SqlitePool, role: &str) -> String {
    sqlx::query(r#"INSERT INTO "user" (id, status, "type", domain, company_name) VALUES (500, 'Active', ?1, 'staff.example', 'Example staff')"#).bind(role).execute(pool).await.unwrap();
    let token = hxweb::session::create(pool, UserId(500), Some("editor@staff.example"), db::unix_now()).await.unwrap();
    format!("{}={}", hxweb::session::COOKIE, token.as_str())
}

async fn admin(app: &Router, method: Method, uri: &str, cookie: &str, form: &str) -> (StatusCode, Option<String>, String) {
    let req = Request::builder().method(method).uri(uri).header(header::COOKIE, cookie).header(header::CONTENT_TYPE, "application/x-www-form-urlencoded");
    let res = app.clone().oneshot(req.body(Body::from(form.to_string())).unwrap()).await.unwrap();
    let status = res.status();
    let location = res.headers().get(header::LOCATION).map(|v| v.to_str().unwrap().to_string());
    (status, location, String::from_utf8(res.into_body().collect().await.unwrap().to_bytes().to_vec()).unwrap())
}

fn csrf_of(page: &str) -> String {
    let start = page.find("name='csrf' id='csrf' value='").expect("csrf field") + "name='csrf' id='csrf' value='".len();
    page[start..].split('\'').next().unwrap().to_string()
}

#[tokio::test]
async fn staff_edit_page_text_from_the_dashboard_screens() {
    let (app, pool) = harness().await;
    let cookie = admin_cookie(&pool, "Administrator").await;
    let (status, _, list) = admin(&app, Method::GET, "/staff/cms", &cookie, "").await;
    assert_eq!(status, StatusCode::OK);
    assert!(list.contains("<a href=\"/staff/cms/page/165\">/product/alignment/</a>"));
    let (status, _, form) = admin(&app, Method::GET, "/staff/cms/page/165", &cookie, "").await;
    assert_eq!(status, StatusCode::OK);
    assert!(form.contains("name=\"hero_title\" rows=\"2\">Synthetic Alignment &lt;b&gt;hero&lt;/b&gt;</textarea>"));
    assert!(!form.contains("name=\"block_one\""), "structure is not editable");

    let post = "hero_title=Dashboard+%3Cem%3Eedit%3C%2Fem%3E&meta%3Atitle=Tab&note=from+the+test";
    assert_eq!(admin(&app, Method::POST, "/staff/cms/page/165", &cookie, &format!("{post}&csrf=forged")).await.0, StatusCode::FORBIDDEN);
    assert_eq!(admin(&app, Method::POST, "/staff/cms/page/165", &cookie, post).await.0, StatusCode::FORBIDDEN);
    let token = csrf_of(&form);
    let (status, location, _) = admin(&app, Method::POST, "/staff/cms/page/165", &cookie, &format!("{post}&csrf={token}")).await;
    assert_eq!((status, location.as_deref()), (StatusCode::SEE_OTHER, Some("/staff/cms/page/165")));
    let (_, _, html) = send(&app, Method::GET, "/product/alignment/").await;
    assert!(html.contains("<h1 class=\"mt-5\">Dashboard <em>edit</em></h1>"));
    let history = edit::page_revisions(&pool, PageId(165)).await.unwrap();
    assert_eq!((history[0].saved_by, history[0].note.as_str()), (500, "from the test"));

    let (status, _, _) = admin(&app, Method::POST, "/staff/cms/options/en", &cookie, &format!("website_notification=Closed+Friday&csrf={token}")).await;
    assert_eq!(status, StatusCode::SEE_OTHER);
    assert!(send(&app, Method::GET, "/product/alignment/").await.2.contains("Closed Friday"));
}

#[tokio::test]
async fn only_administrators_reach_the_dashboard_screens() {
    let (app, pool) = harness().await;
    let cookie = admin_cookie(&pool, "Internal").await;
    let home = Some("/scs_index.php");
    let (status, location, _) = admin(&app, Method::GET, "/staff/cms", &cookie, "").await;
    assert_eq!((status, location.as_deref()), (StatusCode::FOUND, home));
    let (status, location, _) = admin(&app, Method::GET, "/staff/cms/page/165", "", "").await;
    assert_eq!((status, location.as_deref()), (StatusCode::FOUND, home));
    let (status, location, _) = admin(&app, Method::POST, "/staff/cms/page/165", &cookie, "hero_title=x").await;
    assert_eq!((status, location.as_deref()), (StatusCode::FOUND, home));
    assert!(edit::page_revisions(&pool, PageId(165)).await.unwrap().len() == 1, "nothing was saved");
}

const FORM: &str = r#"{"id":1,"title":"Request access","button":{"text":"Submit"},"enableHoneypot":false,"requiredIndicator":"asterisk",
    "fields":[{"type":"text","id":1,"label":"Name","isRequired":true,"size":"large"}]}"#;
const CONFIRM: &str = r#"{"c":{"name":"Default","isDefault":true,"type":"message","message":"<strong>Thanks, we will be in touch.</strong>"}}"#;

async fn seed_form(pool: &SqlitePool) {
    sqlx::query("INSERT INTO wp_gf_form (id, title, date_created, is_active, is_trash) VALUES (1, 'Request access', '2020-01-01 00:00:00', 1, 0)").execute(pool).await.unwrap();
    sqlx::query("INSERT INTO wp_gf_form_meta (form_id, display_meta, notifications, confirmations) VALUES (1, ?1, '{}', ?2)").bind(FORM).bind(CONFIRM).execute(pool).await.unwrap();
}

async fn post_form(app: &Router, uri: &str, form: &str) -> (StatusCode, String) {
    let req = Request::builder().method(Method::POST).uri(uri).header(header::CONTENT_TYPE, "application/x-www-form-urlencoded").body(Body::from(form.to_string())).unwrap();
    let res = app.clone().oneshot(req).await.unwrap();
    let status = res.status();
    (status, String::from_utf8(res.into_body().collect().await.unwrap().to_bytes().to_vec()).unwrap())
}

#[tokio::test]
async fn a_form_posted_to_a_page_is_answered_where_the_form_was() {
    let (app, pool) = harness().await;
    seed_form(&pool).await;
    let (status, html) = post_form(&app, "/product/alignment/", "gform_submit=1&input_1=Example+Name").await;
    assert_eq!(status, StatusCode::OK);
    assert!(html.contains("<title>Alignment - Example</title>"), "the page itself");
    assert!(html.contains("Thanks, we will be in touch."));
    assert!(!html.contains("<form id=stub-1"), "the answer replaced the form");

    let (status, html) = post_form(&app, "/product/alignment/", "gform_submit=1&input_1=").await;
    assert_eq!(status, StatusCode::OK);
    assert!(html.contains("<title>Alignment - Example</title>") && !html.contains("Thanks, we will be in touch."));

    let ajax: String = form_urlencoded::byte_serialize(hxweb::orders::forms::render::gform_ajax(1, "legacy").as_bytes()).collect();
    let (status, postback) = post_form(&app, "/product/alignment/", &format!("gform_submit=1&gform_ajax={ajax}&input_1=Example+Name")).await;
    assert_eq!(status, StatusCode::OK);
    assert!(postback.contains("Thanks, we will be in touch.") && !postback.contains("<title>Alignment - Example</title>"));

    let (status, html) = post_form(&app, "/product/alignment/", "s=anything").await;
    assert_eq!(status, StatusCode::OK);
    assert!(html.contains("<form id=stub-1"), "a post without a form shows the page");
}
