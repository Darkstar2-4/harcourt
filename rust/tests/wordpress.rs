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
    assert_eq!(send(&app, Method::GET, "/product/clamping/").await.0, StatusCode::NOT_FOUND, "a page the cms lacks");
    assert_eq!(send(&app, Method::POST, "/product/clamping/").await.0, StatusCode::NOT_FOUND);
    assert_eq!(send(&app, Method::GET, "/product/alignment/?rest_route=/wp/v2/pages").await.0, StatusCode::GONE, "WordPress's REST API is gone");
    assert_eq!(send(&app, Method::POST, "/product/alignment/").await.0, StatusCode::OK);
}

/// The old stack's addresses answer 410 by one rule; what WordPress showed for the blog, its feeds, archives and
/// authors is the 404 page now.
#[tokio::test]
async fn the_old_stack_is_gone_and_the_blog_is_not_found() {
    let (app, _) = harness().await;
    for path in ["/wp-login.php", "/wp-admin/", "/wp-admin/admin-ajax.php", "/wp-json/wp/v2/pages/1", "/wp-json/", "/xmlrpc.php", "/scs_remote.php", "/includes/x.php", "/wp-content/plugins/x/readme.txt", "/wp-includes/wlwmanifest.xml"] {
        for method in [Method::GET, Method::POST] {
            let res = app.clone().oneshot(Request::builder().method(method.clone()).uri(path).body(Body::empty()).unwrap()).await.unwrap();
            assert_eq!(res.status(), StatusCode::GONE, "{method} {path}");
            assert!(res.headers().get(header::SET_COOKIE).is_none(), "{path}");
        }
    }
    assert_eq!(send(&app, Method::GET, "/?rest_route=/wp/v2/users").await.0, StatusCode::GONE);
    for path in ["/beta_catalog/", "/beta_catalog"] {
        let (status, location, _) = send(&app, Method::GET, path).await;
        assert_eq!((status, location.as_deref()), (StatusCode::MOVED_PERMANENTLY, Some("https://www.harcourt.co/catalog/")), "the beta catalog has no loader any more");
    }
    for path in ["/feed/", "/comments/feed/", "/category/blog/", "/author/someone/", "/2024/01/", "/a/b/c/", "/product/alignment/feed/", "/post-sitemap.xml", "/author-sitemap.xml", "/category-sitemap.xml"] {
        assert_eq!(send(&app, Method::GET, path).await.0, StatusCode::NOT_FOUND, "{path}");
    }
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
    let (status, _, html) = send(&app, Method::GET, "/a/b/c/").await;
    assert_eq!(status, StatusCode::NOT_FOUND, "no rule matches three segments: the blog query under a 404");
    assert!(html.contains("</i> Error 404\n"), "with no post to list, the 404 notice");
}

#[tokio::test]
async fn a_post_the_export_left_out_is_not_found() {
    let (app, pool) = harness().await;
    sqlx::query("INSERT INTO cms_page (id, group_id, lang, path, template, status, title, menu_order, published_at, modified_at, post_type) VALUES (436, 436, 'en', '/faq/example-question/', 'single-faq.php', 'publish', 'Example question?', 0, '2020-01-01 00:00:00', '2020-01-02 00:00:00', 'faq')")
        .execute(&pool)
        .await
        .unwrap();
    assert_eq!(send(&app, Method::GET, "/faq/example-question/").await.0, StatusCode::NOT_FOUND, "no recorded head, no page");
}

#[tokio::test]
async fn an_faq_shows_its_featured_image_above_the_answer() {
    let (app, pool) = harness().await;
    sqlx::query("INSERT INTO cms_page (id, group_id, lang, path, template, status, title, menu_order, published_at, modified_at, post_type) VALUES (436, 436, 'en', '/faq/example-question/', 'single-faq.php', 'publish', 'Example question?', 0, '2020-01-01 00:00:00', '2020-01-02 00:00:00', 'faq')")
        .execute(&pool)
        .await
        .unwrap();
    sqlx::query("INSERT INTO cms_media (id, file, mime, width, height) VALUES (90, '2020/01/example.png', 'image/png', 400, 300)").execute(&pool).await.unwrap();
    for (name, kind, value) in [("content", "html", "<p>An example answer.</p>"), ("@thumbnail", "media", "90")] {
        sqlx::query("INSERT INTO cms_field (page_id, name, kind, value) VALUES (436, ?1, ?2, ?3)").bind(name).bind(kind).bind(value).execute(&pool).await.unwrap();
    }
    for (name, value) in [("title", "Example question? - Example"), ("islands", ISLANDS)] {
        sqlx::query("INSERT INTO cms_page_meta (page_id, name, value) VALUES (436, ?1, ?2)").bind(name).bind(value).execute(&pool).await.unwrap();
    }
    sqlx::query("INSERT INTO cms_revision (page_id, saved_at, saved_by, note, fields) VALUES (436, 1, 0, 'import', '{}')").execute(&pool).await.unwrap();
    let (status, _, html) = send(&app, Method::GET, "/faq/example-question/").await;
    assert_eq!(status, StatusCode::OK);
    assert!(html.contains("class=\"post-436 faq type-faq status-publish has-post-thumbnail hentry\""), "{html}");
    assert!(html.contains(
        "<main itemprop=\"articleBody\">\n\n      <img width=\"400\" height=\"300\" src=\"https://www.harcourt.co/wp-content/uploads/2020/01/example.png\" class=\"attachment-full size-full wp-post-image\" alt=\"\" itemprop=\"image\" decoding=\"async\""
    ));
    assert!(html.contains("<p>An example answer.</p>"));
}

#[tokio::test]
async fn a_page_whose_template_is_not_rendered_goes_to_the_static_export() {
    let (app, pool) = harness().await;
    sqlx::query("INSERT INTO cms_page (id, group_id, lang, path, template, status, title, menu_order, published_at, modified_at) VALUES (437, 437, 'en', '/innovations/example-builder/', 'layouts/template-product-boxjoint.php', 'publish', 'Example', 0, '2020-01-01 00:00:00', '2020-01-02 00:00:00')")
        .execute(&pool)
        .await
        .unwrap();
    let got = |method: Method, uri: &'static str| {
        let app = app.clone();
        async move {
            let res = app.oneshot(Request::builder().method(method).uri(uri).body(Body::empty()).unwrap()).await.unwrap();
            assert_eq!(res.headers().get("x-hxweb").map(|v| v.to_str().unwrap()), Some("export"), "main.rs serves {uri} from the export");
            let asked = res.extensions().get::<wordpress::ExportRequest>().cloned().expect("the export page for the GET");
            assert!(asked.answer.is_none());
            (asked.path, asked.lang, asked.uri)
        }
    };
    let page = "/innovations/example-builder/".to_string();
    assert_eq!(got(Method::GET, "/innovations/example-builder/").await, (page.clone(), "en", page.clone()));
    assert_eq!(got(Method::HEAD, "/innovations/example-builder/?utm_source=x&hxlang=en").await, (page.clone(), "en", format!("{page}?utm_source=x")));
    assert_eq!(got(Method::GET, "/innovations/example-builder/?gbraid=x").await, (page.clone(), "en", format!("{page}?gbraid=x")), "any query the page ignores");
    assert_eq!(got(Method::GET, "/Innovations/Example-Builder/").await, (page.clone(), "en", page.clone()), "a page asked for in other case shows as at its path");
    assert_eq!(got(Method::GET, "/INNOVATIONS/example-builder/?utm_source=x&hxlang=en").await, (page.clone(), "en", format!("{page}?utm_source=x")), "as the page's own URL with that query");
    assert_eq!(got(Method::GET, "/Innovations/Example-Builder/?gbraid=x").await, (page.clone(), "en", format!("{page}?gbraid=x")));

    seed_form(&pool).await;
    let posted = |uri: &'static str, form: &'static str| {
        let app = app.clone();
        async move {
            let req = Request::builder().method(Method::POST).uri(uri).header(header::CONTENT_TYPE, "application/x-www-form-urlencoded").body(Body::from(form)).unwrap();
            let res = app.oneshot(req).await.unwrap();
            assert_eq!(res.headers().get("x-hxweb").map(|v| v.to_str().unwrap()), Some("export"), "{uri} {form}");
            res.extensions().get::<wordpress::ExportRequest>().cloned().expect("what the export page shows for the post")
        }
    };
    let asked = posted("/innovations/example-builder/", "gform_submit=1&input_1=Example+Name").await;
    assert_eq!((asked.path.as_str(), asked.lang, asked.uri.as_str()), ("/innovations/example-builder/", "en", "/innovations/example-builder/"));
    let answer = asked.answer.expect("the form's answer");
    assert!(answer.form == 1 && answer.confirmed && answer.html.contains("Thanks, we will be in touch."));
    let answer = posted("/innovations/example-builder", "gform_submit=1&input_1=").await.answer.expect("the form again");
    assert!(!answer.confirmed && !answer.html.contains("Thanks, we will be in touch."), "a post to the bare path is answered at the page");
    let asked = posted("/Innovations/Example-Builder/", "gform_submit=1&input_1=").await;
    assert_eq!(asked.uri, "/innovations/example-builder/", "a post in other case is answered at the page's path");
    assert!(asked.answer.is_some_and(|a| a.form == 1 && !a.confirmed));
    assert!(posted("/innovations/example-builder/", "s=anything").await.answer.is_none(), "a post without a form shows the page");
    let entries: i64 = sqlx::query_scalar("SELECT count(*) FROM wp_gf_entry").fetch_one(&pool).await.unwrap();
    assert_eq!(entries, 1);
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
    assert_eq!(send(&app, Method::GET, "/page-sitemap1.xml?lang=fr").await.1.as_deref(), Some("https://www.harcourt.co/page-sitemap.xml?lang=fr"));
    assert_eq!(send(&app, Method::GET, "/post-sitemap.xml").await.0, StatusCode::NOT_FOUND);

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
    assert_eq!(send(&app, Method::GET, "/sitemap.xml?order=asc").await.0, StatusCode::NOT_FOUND, "any other query is a 404");
}

#[tokio::test]
async fn sitemaps_leave_out_what_yoast_keeps_from_search_engines() {
    let (app, pool) = harness().await;
    sqlx::query("INSERT INTO cms_page_meta (page_id, name, value) VALUES (2010, 'noindex', '1')").execute(&pool).await.unwrap();
    sqlx::query("INSERT INTO cms_revision (page_id, saved_at, saved_by, note, fields) VALUES (2010, 1, 0, 'import', '{}')").execute(&pool).await.unwrap();
    let (_, _, xml) = send(&app, Method::GET, "/page-sitemap.xml?lang=fr").await;
    assert_eq!(xml.matches("<loc>https://www.harcourt.co/gamme-de-produits/centrage/?lang=fr</loc>").count(), 1, "the noindexed page is left out");

    sqlx::query("INSERT INTO cms_option (lang, name, kind, value) VALUES ('en', '@wpseo:noindex-page', 'choice', '1')").execute(&pool).await.unwrap();
    sqlx::query("INSERT INTO cms_revision (lang, saved_at, saved_by, note, fields) VALUES ('en', 2, 0, 'import', '{}')").execute(&pool).await.unwrap();
    let (status, _, xml) = send(&app, Method::GET, "/sitemap_index.xml").await;
    assert_eq!(status, StatusCode::OK);
    assert!(!xml.contains("page-sitemap"), "a post type kept from search engines has no sitemap");
    assert_eq!(send(&app, Method::GET, "/page-sitemap.xml").await.0, StatusCode::NOT_FOUND);
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

    edit::save_page(&pool, PageId(165), &[Change { name: "hero_title".into(), value: "Dated".into() }], UserId(7), "", 1_700_000_000).await.unwrap();
    let (_, _, xml) = send(&app, Method::GET, "/page-sitemap.xml").await;
    assert!(xml.contains("<loc>https://www.harcourt.co/product/alignment/</loc>\n\t\t<lastmod>2023-11-14T22:13:20+00:00</lastmod>"), "{xml}");
}

#[tokio::test]
async fn head_texts_print_as_wordpress_escapes_them() {
    let (app, pool) = harness().await;
    sqlx::query("UPDATE cms_page_meta SET value = 'Alignment &#8211; Example' WHERE page_id = 165 AND name = 'title'").execute(&pool).await.unwrap();
    let (_, _, html) = send(&app, Method::GET, "/product/alignment/").await;
    assert!(html.contains("<title>Alignment &#8211; Example</title>"), "imported text keeps its entities");
    let changes = [
        Change { name: "meta:title".into(), value: "Jigs & \"Fixtures\"".into() },
        Change { name: "meta:description".into(), value: "Bushings 1/2\" & up".into() },
    ];
    edit::save_page(&pool, PageId(165), &changes, UserId(7), "", 100).await.unwrap();
    let (_, _, html) = send(&app, Method::GET, "/product/alignment/").await;
    assert!(html.contains("<title>Jigs &amp; &quot;Fixtures&quot;</title>"), "{html}");
    assert!(html.contains("<meta property=\"og:title\" content=\"Jigs &amp; &quot;Fixtures&quot;\" />"));
    assert!(html.contains("<meta name=\"description\" content=\"Bushings 1/2&quot; &amp; up\" />"));
}

#[tokio::test]
async fn an_edited_body_is_what_search_reads() {
    let (_, pool) = harness().await;
    for (name, kind, value) in [("content", "html", "<p>Ships in six weeks &#8211; you&#8217;re set.</p>"), ("@post_content", "choice", "<!-- wp:paragraph -->Ships in six weeks - you're set.")] {
        sqlx::query("INSERT INTO cms_field (page_id, name, kind, value) VALUES (165, ?1, ?2, ?3) ON CONFLICT (page_id, name) DO UPDATE SET value = excluded.value")
            .bind(name)
            .bind(kind)
            .bind(value)
            .execute(&pool)
            .await
            .unwrap();
    }
    let post_content = |pool: SqlitePool| async move { sqlx::query_scalar::<_, String>("SELECT value FROM cms_field WHERE page_id = 165 AND name = '@post_content'").fetch_one(&pool).await.unwrap() };
    let unchanged = Change { name: "content".into(), value: "<p>Ships in six weeks &#8211; you&#8217;re set.</p>".into() };
    edit::save_page(&pool, PageId(165), &[unchanged, Change { name: "meta:title".into(), value: "New title".into() }], UserId(7), "", 100).await.unwrap();
    assert_eq!(post_content(pool.clone()).await, "<!-- wp:paragraph -->Ships in six weeks - you're set.", "a save that leaves the body alone keeps the stored post");
    edit::save_page(&pool, PageId(165), &[Change { name: "content".into(), value: "<p>Ships in six days.</p>".into() }], UserId(7), "", 101).await.unwrap();
    assert_eq!(post_content(pool.clone()).await, "<p>Ships in six days.</p>");
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
    let token = hxweb::session::create(pool, UserId(500), &hxweb::session::Basis::Domain(hxweb::identity::Email::parse("editor@staff.example").unwrap()), db::unix_now()).await.unwrap();
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

/// WordPress's 404 page, whose chrome and footer form the search results share.
async fn seed_not_found(pool: &SqlitePool) {
    for (name, value) in [("title", "Page not found - Example"), ("robots", "noindex, follow"), ("body_class", "error404"), ("islands", ISLANDS)] {
        sqlx::query("INSERT INTO cms_option (lang, name, kind, value) VALUES ('en', ?1, 'html', ?2)").bind(format!("@404:{name}")).bind(value).execute(pool).await.unwrap();
    }
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

    let (status, html) = post_form(&app, "/product/alignment", "gform_submit=1&input_1=Example+Name").await;
    assert_eq!(status, StatusCode::OK, "a post to the bare path is answered at the page, not moved");
    assert!(html.contains("<title>Alignment - Example</title>") && html.contains("Thanks, we will be in touch."));
    let (status, html) = post_form(&app, "/product/alignment", "gform_submit=1&input_1=").await;
    assert_eq!(status, StatusCode::OK);
    assert!(html.contains("<title>Alignment - Example</title>") && !html.contains("Thanks, we will be in touch."));
    let (status, html) = post_form(&app, "/product/alignment", "s=anything").await;
    assert_eq!(status, StatusCode::OK);
    assert!(html.contains("<form id=stub-1"));
    assert_eq!(send(&app, Method::GET, "/product/alignment").await.0, StatusCode::MOVED_PERMANENTLY, "a GET still gets the slash");
}

#[tokio::test]
async fn the_footer_form_is_answered_on_every_page_that_prints_it() {
    let (app, pool) = harness().await;
    seed_form(&pool).await;
    sqlx::query("INSERT INTO cms_page (id, group_id, lang, path, template, status, title, menu_order, published_at, modified_at, post_type) VALUES (436, 436, 'en', '/faq/example-question/', 'single-faq.php', 'publish', 'Example question?', 0, '2020-01-01 00:00:00', '2020-01-02 00:00:00', 'faq')")
        .execute(&pool)
        .await
        .unwrap();
    sqlx::query("INSERT INTO cms_field (page_id, name, kind, value) VALUES (436, 'content', 'html', '<p>An example answer.</p>')").execute(&pool).await.unwrap();
    for (name, value) in [("title", "Example question? - Example"), ("islands", ISLANDS)] {
        sqlx::query("INSERT INTO cms_page_meta (page_id, name, value) VALUES (436, ?1, ?2)").bind(name).bind(value).execute(&pool).await.unwrap();
    }
    seed_not_found(&pool).await;
    sqlx::query("INSERT INTO cms_revision (page_id, saved_at, saved_by, note, fields) VALUES (436, 1, 0, 'import', '{}')").execute(&pool).await.unwrap();

    let (status, html) = post_form(&app, "/faq/example-question/", "gform_submit=1&input_1=Example+Name").await;
    assert_eq!(status, StatusCode::OK);
    assert!(html.contains("<title>Example question? - Example</title>") && html.contains("Thanks, we will be in touch."), "{html}");
    let (status, html) = post_form(&app, "/product/clamping/", "gform_submit=1&input_1=Example+Name").await;
    assert_eq!(status, StatusCode::NOT_FOUND);
    assert!(html.contains("<title>Page not found - Example</title>") && html.contains("Thanks, we will be in touch."), "{html}");
    let (status, html) = post_form(&app, "/product/clamping/", "gform_submit=1&input_1=").await;
    assert_eq!(status, StatusCode::NOT_FOUND);
    assert!(!html.contains("Thanks, we will be in touch."));
    let entries: i64 = sqlx::query_scalar("SELECT count(*) FROM wp_gf_entry").fetch_one(&pool).await.unwrap();
    assert_eq!(entries, 2);
    let (status, html) = post_form(&app, "/product/clamping/", "s=anything").await;
    assert_eq!(status, StatusCode::NOT_FOUND);
    assert!(html.contains("<form id=stub-1"), "a post without a form shows the 404 page");
}

fn multipart(boundary: &str, fields: &[(&str, &str)]) -> String {
    let mut body: String = fields.iter().map(|(k, v)| format!("--{boundary}\r\nContent-Disposition: form-data; name=\"{k}\"\r\n\r\n{v}\r\n")).collect();
    body.push_str(&format!("--{boundary}--\r\n"));
    body
}

#[tokio::test]
async fn a_large_multipart_post_is_read_whole() {
    let (app, pool) = harness().await;
    seed_form(&pool).await;
    let boundary = "----hxtestboundary";
    let long = "x".repeat(3 << 20);
    let send_multipart = |content_type: String, body: String| {
        let req = Request::builder().method(Method::POST).uri("/product/alignment/").header(header::CONTENT_TYPE, content_type).body(Body::from(body)).unwrap();
        app.clone().oneshot(req)
    };
    let res = send_multipart(format!("multipart/form-data; boundary={boundary}"), multipart(boundary, &[("gform_submit", "1"), ("input_1", "Example Name"), ("input_9", &long)])).await.unwrap();
    assert_eq!(res.status(), StatusCode::OK);
    let html = String::from_utf8(res.into_body().collect().await.unwrap().to_bytes().to_vec()).unwrap();
    assert!(html.contains("Thanks, we will be in touch."));

    let res = send_multipart("multipart/form-data".into(), multipart(boundary, &[("gform_submit", "1"), ("input_1", "Example Name")])).await.unwrap();
    assert_eq!(res.status(), StatusCode::BAD_REQUEST, "a body that cannot be read is no empty post");
    let res = send_multipart(format!("multipart/form-data; boundary={boundary}"), multipart(boundary, &[("input_9", &"x".repeat(9 << 20))])).await.unwrap();
    assert_eq!(res.status(), StatusCode::PAYLOAD_TOO_LARGE);
    let entries: i64 = sqlx::query_scalar("SELECT count(*) FROM wp_gf_entry").fetch_one(&pool).await.unwrap();
    assert_eq!(entries, 1);
}

async fn entries(pool: &SqlitePool) -> i64 {
    sqlx::query_scalar("SELECT count(*) FROM wp_gf_entry").fetch_one(pool).await.unwrap()
}

/// The wrapper of a form drawn again with its errors.
const INVALID: &str = "gform-theme--no-framework gform_validation_error'";

#[tokio::test]
async fn the_footer_form_is_answered_on_the_search_results() {
    let (app, pool) = harness().await;
    seed_form(&pool).await;
    seed_not_found(&pool).await;
    let (status, _, html) = send(&app, Method::GET, "/search/nothing+found/").await;
    assert_eq!(status, StatusCode::OK);
    // The search page draws its forms itself.
    assert!(html.contains("id='gform_1'") && !html.contains(INVALID), "{html}");

    let (status, html) = post_form(&app, "/search/nothing+found/", "gform_submit=1&input_1=Example+Name").await;
    assert_eq!(status, StatusCode::OK);
    assert!(html.contains("Thanks, we will be in touch.") && !html.contains("id='gform_1'"), "{html}");
    assert_eq!(entries(&pool).await, 1);

    let (status, html) = post_form(&app, "/search/nothing+found/", "gform_submit=1&input_1=").await;
    assert_eq!(status, StatusCode::OK);
    assert!(!html.contains("Thanks, we will be in touch.") && html.contains(INVALID), "the form again, with its error");
    assert_eq!(entries(&pool).await, 1);

    let boundary = "----hxtestboundary";
    let req = Request::builder()
        .method(Method::POST)
        .uri("/search/nothing+found/page/2/")
        .header(header::CONTENT_TYPE, format!("multipart/form-data; boundary={boundary}"))
        .body(Body::from(multipart(boundary, &[("gform_submit", "1"), ("input_1", "Example Name")])))
        .unwrap();
    let res = app.clone().oneshot(req).await.unwrap();
    assert_eq!(res.status(), StatusCode::NOT_FOUND, "a page of results past the last is WordPress's 404, form answered all the same");
    let html = String::from_utf8(res.into_body().collect().await.unwrap().to_bytes().to_vec()).unwrap();
    assert!(html.contains("Thanks, we will be in touch."), "{html}");
    assert_eq!(entries(&pool).await, 2);

    let (status, html) = post_form(&app, "/?s=nothing+found", "gform_submit=1&input_1=Example+Name").await;
    assert_eq!(status, StatusCode::OK);
    assert!(html.contains("Thanks, we will be in touch.") && !html.contains("id='gform_1'"), "the front page's search, too: {html}");
    assert_eq!(entries(&pool).await, 3);

    let (status, html) = post_form(&app, "/search/nothing+found/", "q=1").await;
    assert_eq!(status, StatusCode::OK);
    assert!(html.contains("id='gform_1'") && !html.contains("Thanks, we will be in touch."), "a post without a form shows the results");
}

/// What a search's address no longer has (its feeds, a var that narrows it) is the 404 page, as the blog's feeds
/// are; an empty search searches for nothing and lists every post, newest first.
#[tokio::test]
async fn a_search_feed_is_not_found_and_an_empty_search_lists_every_post() {
    let (app, pool) = harness().await;
    seed_form(&pool).await;
    seed_not_found(&pool).await;
    sqlx::query("INSERT INTO cms_page (id, group_id, lang, path, template, status, title, menu_order, published_at, modified_at) VALUES (166, 2, 'en', '/product/clamping/', 'layouts/template-product-single.php', 'publish', 'Clamping', 0, '2021-01-01 00:00:00', '2021-01-01 00:00:00')")
        .execute(&pool)
        .await
        .unwrap();
    for uri in ["/search/pins/feed/rss2/", "/search/pins/feed/", "/search/pins/rss2/", "/search/pins?p=1"] {
        let (status, _, html) = send(&app, Method::GET, uri).await;
        assert_eq!(status, StatusCode::NOT_FOUND, "{uri}");
        assert!(html.contains("</i> Error 404\n") && html.contains(&format!("<form id=stub-1 data-page=\"{uri}\"></form>")), "{uri}: {html}");
    }
    assert_eq!(send(&app, Method::HEAD, "/search/pins/feed/").await.0, StatusCode::NOT_FOUND);
    assert_eq!(send(&app, Method::GET, "/search/x.php/feed/").await.0, StatusCode::GONE, "the old stack's addresses stay gone");

    for uri in ["/?s=", "/search/pins/?s="] {
        let (status, _, html) = send(&app, Method::GET, uri).await;
        assert_eq!(status, StatusCode::OK, "{uri}");
        assert!(html.contains("Search Results for &ldquo;&rdquo;"), "{uri}: {html}");
        let (newer, older) = (html.find("id=\"post_166\""), html.find("id=\"post_165\""));
        assert!(newer.is_some() && newer < older, "{uri}: every post of the language, newest first: {html}");
        assert!(!html.contains("id=\"post_2010\""), "{uri}");
        assert!(html.contains("You searched for  - Harcourt"), "Yoast's title with its phrase left empty: {html}");
        assert!(html.contains("href=\"https://www.harcourt.co/search/feed/rss2/\""), "get_search_link() without terms: {html}");
    }
    let (_, _, html) = send(&app, Method::GET, "/search/nothing+found/").await;
    assert!(html.contains("You searched for nothing found - Harcourt") && html.contains("href=\"https://www.harcourt.co/search/nothing+found/feed/rss2/\""), "{html}");
    assert!(html.contains("<meta property=\"og:url\" content=\"https://www.harcourt.co/search/nothing+found/\" />"), "og:url is get_search_link(): {html}");
    let (_, _, html) = send(&app, Method::GET, "/search/a%26b/").await;
    assert!(html.contains("<meta property=\"og:url\" content=\"https://www.harcourt.co/search/a%26b/\" />"), "{html}");
}

#[tokio::test]
async fn results_that_embed_a_form_or_name_a_sidebar_are_drawn() {
    let (app, pool) = harness().await;
    seed_form(&pool).await;
    seed_not_found(&pool).await;
    for (name, value) in [("@post_content", "Pins for every jig. [gravityform id=\"1\" title=\"false\"]"), ("sidebar_select", "products")] {
        sqlx::query("INSERT INTO cms_field (page_id, name, kind, value) VALUES (165, ?1, 'choice', ?2)").bind(name).bind(value).execute(&pool).await.unwrap();
    }
    let (status, _, html) = send(&app, Method::GET, "/search/alignment/").await;
    assert_eq!(status, StatusCode::OK);
    assert!(html.contains("Pins for every jig.") && !html.contains("[gravityform"), "{html}");
}

#[tokio::test]
async fn a_redirect_confirmation_moves_the_visitor_after_an_ajax_post_too() {
    let (app, pool) = harness().await;
    let redirect = r#"{"c":{"name":"Default","isDefault":true,"type":"redirect","url":"https://example.com/thanks/","queryString":"name={Name:1}"}}"#;
    sqlx::query("INSERT INTO wp_gf_form (id, title, date_created, is_active, is_trash) VALUES (1, 'Request access', '2020-01-01 00:00:00', 1, 0)").execute(&pool).await.unwrap();
    sqlx::query("INSERT INTO wp_gf_form_meta (form_id, display_meta, notifications, confirmations) VALUES (1, ?1, '{}', ?2)").bind(FORM).bind(redirect).execute(&pool).await.unwrap();

    let req = Request::builder().method(Method::POST).uri("/product/alignment/").header(header::CONTENT_TYPE, "application/x-www-form-urlencoded").body(Body::from("gform_submit=1&input_1=Example+Name")).unwrap();
    let res = app.clone().oneshot(req).await.unwrap();
    assert_eq!(res.status(), StatusCode::FOUND);
    assert_eq!(res.headers().get(header::LOCATION).unwrap(), "https://example.com/thanks/?name=Example+Name");

    let ajax: String = form_urlencoded::byte_serialize(hxweb::orders::forms::render::gform_ajax(1, "legacy").as_bytes()).collect();
    let (status, postback) = post_form(&app, "/product/alignment/", &format!("gform_submit=1&gform_ajax={ajax}&input_1=Example+Name")).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(
        postback,
        "<!DOCTYPE html><html><head><meta charset='UTF-8' /></head><body class='GF_AJAX_POSTBACK'><script type=\"text/javascript\"> function gformRedirect(){document.location.href=\"https:\\/\\/example.com\\/thanks\\/?name=Example+Name\";}</script></body></html>"
    );
    assert_eq!(entries(&pool).await, 2);
}
