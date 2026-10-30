//! The WordPress search page asked for with WordPress's query vars, through the page routes, on synthetic content.

use std::sync::Arc;

use axum::{
    body::Body,
    http::{header, Method, Request, StatusCode},
    Router,
};
use hxweb::{
    api::Auth,
    cms::forms::{FormSource, Markup},
    cms::FormEmbed,
    db,
    routes::wordpress,
};
use sqlx::SqlitePool;
use tower::ServiceExt;

struct NoForms;

impl FormSource for NoForms {
    fn markup<'a>(&'a self, _: &'a FormEmbed<'a>) -> Markup<'a> {
        Box::pin(async move { Ok(String::new()) })
    }
}

async fn seed(pool: &SqlitePool) {
    for (id, lang, name, path, title) in [(897, "en", "search", "/search/", "Search"), (898, "fr", "recherche", "/recherche/", "Recherche")] {
        sqlx::query("INSERT INTO cms_page (id, group_id, lang, path, template, status, title, menu_order, published_at, modified_at, post_type, name) VALUES (?1, 897, ?2, ?3, 'layouts/template-scscpq.php', 'publish', ?4, 0, '2020-01-01 00:00:00', '2020-01-02 00:00:00', 'page', ?5)")
            .bind(id)
            .bind(lang)
            .bind(path)
            .bind(title)
            .bind(name)
            .execute(pool)
            .await
            .unwrap();
        sqlx::query("INSERT INTO cms_field (page_id, name, kind, value) VALUES (?1, '@post_content', 'html', 'scscpq')").bind(id).execute(pool).await.unwrap();
        for (name, value) in [("title", format!("{title} - Example")), ("islands", "[]".to_string()), ("robots", "index, follow".to_string())] {
            sqlx::query("INSERT INTO cms_page_meta (page_id, name, value) VALUES (?1, ?2, ?3)").bind(id).bind(name).bind(value).execute(pool).await.unwrap();
        }
        sqlx::query("INSERT INTO cms_option (lang, name, kind, value) VALUES (?1, '@404:title', 'html', 'Page not found - Example')").bind(lang).execute(pool).await.unwrap();
    }
}

async fn harness() -> Router {
    let pool = db::open_memory().await.unwrap();
    seed(&pool).await;
    let auth = Arc::new(Auth { pool, secret: None, public_origin: "https://www.harcourt.co".into(), trusted_proxies: vec![], accounts: hxweb::accounts::Services::offline(hxweb::accounts::bot::Dns::fixed(&[], &[])) });
    wordpress::router_with_forms(auth, Some(Arc::new(NoForms)))
}

async fn ask(app: &Router, method: Method, uri: &str) -> (StatusCode, String, String, String) {
    let res = app.clone().oneshot(Request::builder().method(method).uri(uri).body(Body::empty()).unwrap()).await.unwrap();
    let h = |name: header::HeaderName| res.headers().get(name).map_or(String::new(), |v| v.to_str().unwrap().to_string());
    let (location, kind, hxweb) = (h(header::LOCATION), h(header::CONTENT_TYPE), h(header::HeaderName::from_static("x-hxweb")));
    (res.status(), location, kind, hxweb)
}

#[tokio::test]
async fn wordpress_vars_on_the_search_page_answer_as_wordpress_did() {
    let app = harness().await;
    let get = |uri: &'static str| {
        let app = app.clone();
        async move { ask(&app, Method::GET, uri).await }
    };
    // The theme's templates need the export's content to render; here it is enough that the page is answered here,
    // as the page (no 404 status) or as WordPress's 404.
    let (status, _, _, hxweb) = get("/search/?s=pin").await;
    assert!(status != StatusCode::OK && !status.is_redirection() && hxweb != "legacy", "the search narrows the page to nothing");
    let (status, _, _, hxweb) = get("/search/?s=search").await;
    assert!(status != StatusCode::NOT_FOUND && !status.is_redirection() && hxweb != "legacy", "the page's own title matches");
    let (status, location, ..) = get("/search/?s=alignment+pin").await;
    assert_eq!((status, location.as_str()), (StatusCode::MOVED_PERMANENTLY, "https://www.harcourt.co/search/?s=alignment%20pin"));
    let (status, location, ..) = get("/search/?paged=2").await;
    assert_eq!((status, location.as_str()), (StatusCode::MOVED_PERMANENTLY, "https://www.harcourt.co/search/page/2/"));
    let (status, location, kind, _) = get("/search/?feed=rss2").await;
    assert_eq!((status, location.as_str()), (StatusCode::MOVED_PERMANENTLY, "https://www.harcourt.co/search/feed/"));
    assert!(kind.starts_with("application/rss+xml"), "{kind}");
    let (status, location, ..) = get("/search/?lang=en&s=pin").await;
    assert_eq!((status, location.as_str()), (StatusCode::FOUND, "/search/?s=pin"));
    let (status, location, ..) = get("/search/?s=pin&lang=fr").await;
    assert_eq!((status, location.as_str()), (StatusCode::MOVED_PERMANENTLY, "https://www.harcourt.co/recherche/?lang=fr&s=pin"));
    assert_eq!(get("/search/?year=2020").await.3, "legacy", "a var not ported still goes to WordPress");
    let (status, _, _, hxweb) = ask(&app, Method::PUT, "/search/").await;
    assert!(status != StatusCode::METHOD_NOT_ALLOWED && hxweb != "legacy", "any method is a GET to WordPress");
}
