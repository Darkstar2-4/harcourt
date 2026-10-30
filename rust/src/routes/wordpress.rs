//! Owner: the wordpress agent. Routes for what WordPress did: page templates, menus, forms, SEO, redirects, editable page text, and retiring wp-login, xmlrpc and wp-admin.
//! Register every route here and nowhere else; mod.rs only merges this router.
//!
//! A page listed in PAGES is rendered from the cms tables (rust/src/cms); every other WordPress page is still
//! served from the static export by pages.rs. Deleting a path from PAGES hands it back. GET and HEAD only: form
//! posts to page URLs still reach WordPress until Gravity Forms is ported.

use std::{collections::BTreeMap, sync::Arc};

use axum::{
    body::Body,
    extract::State,
    http::{header, HeaderValue, Response, StatusCode, Uri},
    response::IntoResponse,
    routing::get,
    Router,
};
use percent_encoding::percent_decode_str;

use crate::{
    api::Auth,
    cms::{
        forms::{FormSource, Islands, OrdersForms},
        theme, Content, FormEmbed, Lang, Page, Site, Status,
    },
};

/// Every path of a page whose template Rust renders (product-single, digest, page.php), in both languages. `hxweb-cms
/// check` proves each renders as WordPress did.
const PAGES: [&str; 41] = [
    "/company/",
    "/confidentialite/",
    "/gamme-de-produits/",
    "/gamme-de-produits/bridage/",
    "/gamme-de-produits/centrage/",
    "/gamme-de-produits/elements-doutillages/",
    "/gamme-de-produits/jauges/",
    "/gamme-de-produits/les-essentiels-de-loutillage/",
    "/gamme-de-produits/levage-greement/",
    "/gamme-de-produits/outillage-composite/",
    "/gamme-de-produits/percage/",
    "/gamme-de-produits/positionnements/",
    "/gamme-de-produits/stockage-et-transport-doutils/",
    "/industries/",
    "/industries/automobile/",
    "/industries/industrie-lourde/",
    "/innovations/",
    "/innovations/captive/",
    "/innovations/configurateur-de-produits/",
    "/innovations/elements-imperdables/",
    "/innovations/metrology-solutions/",
    "/innovations/product-configurators/",
    "/innovations/solutions-de-metrologie/",
    "/legal/",
    "/privacy/",
    "/product/",
    "/product/alignment/",
    "/product/clamping/",
    "/product/composite-tooling/",
    "/product/drilling/",
    "/product/gaging/",
    "/product/hoisting-rigging/",
    "/product/jig-elements/",
    "/product/locators/",
    "/product/tool-storage/",
    "/product/tooling-essentials/",
    "/resources/",
    "/resources/lunch-learns/",
    "/ressources/",
    "/ressources/dejeuners-et-causeries/",
    "/societe/",
];

/// The docroot's robots.txt, which Apache served ahead of Yoast's virtual one.
const ROBOTS: &str = include_str!("../../../robots.txt");

pub struct Wp {
    core: Arc<Auth>,
    content: Content,
    forms: Option<Arc<dyn FormSource>>,
}

pub fn router(core: Arc<Auth>) -> Router {
    let forms = OrdersForms { orders: crate::orders::Orders::from_env(core.clone()), fallback: Islands::from_env() };
    router_with_forms(core, Some(Arc::new(forms)))
}

/// The router with the given source of form markup (tests pass a fixed one).
pub fn router_with_forms(core: Arc<Auth>, forms: Option<Arc<dyn FormSource>>) -> Router {
    let wp = Arc::new(Wp { content: Content::new(core.pool.clone()), forms, core });
    let mut r = Router::new().route("/robots.txt", get(robots));
    for path in PAGES {
        r = r.route(path, get(page));
        if let Some(bare) = path.strip_suffix('/').filter(|p| !p.is_empty()) {
            r = r.route(bare, get(add_slash));
        }
    }
    r.with_state(wp)
}

/// Which page a request asks for, from its query: WPML's `lang`, and whether it is the REST API's `rest_route`.
#[derive(Debug, PartialEq, Eq)]
struct Query {
    lang: Lang,
    rest_route: bool,
    /// The request URI WordPress would see, for form actions: without the site's own hxlang marker and without
    /// lang=en, which WPML drops.
    form_uri_query: String,
}

fn query(q: &str) -> Query {
    let mut lang = Lang::En;
    let mut rest_route = false;
    let mut kept = Vec::new();
    for kv in q.split('&').filter(|kv| !kv.is_empty()) {
        let key = kv.split('=').next().unwrap_or("");
        match key {
            "lang" if kv == "lang=fr" => lang = Lang::Fr,
            "rest_route" => rest_route = true,
            _ => {}
        }
        if key != "hxlang" && kv != "lang=en" {
            kept.push(kv);
        }
    }
    let form_uri_query = if kept.is_empty() { String::new() } else { format!("?{}", kept.join("&")) };
    Query { lang, rest_route, form_uri_query }
}

/// What a claimed path answers, given the content and the requested language.
#[derive(Debug, PartialEq, Eq)]
enum Answer<'a> {
    Render(&'a Page),
    Redirect(String),
    NotFound,
}

fn resolve<'a>(site: &'a Site, path: &str, lang: Lang) -> Answer<'a> {
    let published = |l: Lang| site.find(l, path).filter(|p| p.status == Status::Publish);
    if let Some(p) = published(lang) {
        return Answer::Render(p);
    }
    let other = if lang == Lang::En { Lang::Fr } else { Lang::En };
    let Some(p) = published(other) else { return Answer::NotFound };
    match (lang, site.translation(p, lang)) {
        // WPML sends a French slug asked for in English to the English page, even an unpublished one (whose
        // permalink, and so its stored path, is /?page_id=N).
        (Lang::En, Some(t)) => Answer::Redirect(format!("{}{}", t.path, t.lang.query())),
        (Lang::En, None) => Answer::Redirect(format!("{}{}", p.path, p.lang.query())),
        (Lang::Fr, Some(t)) if t.status == Status::Publish => Answer::Redirect(format!("{}{}", t.path, t.lang.query())),
        (Lang::Fr, _) => Answer::NotFound,
    }
}

async fn page(State(wp): State<Arc<Wp>>, uri: Uri) -> Response<Body> {
    let path = percent_decode_str(uri.path()).decode_utf8_lossy().into_owned();
    let q = query(uri.query().unwrap_or(""));
    if q.rest_route {
        return gone();
    }
    let site = match wp.content.site().await {
        Ok(s) => s,
        Err(e) => {
            tracing::error!("cms content unavailable: {e}");
            return (StatusCode::SERVICE_UNAVAILABLE, "Service unavailable").into_response();
        }
    };
    let page = match resolve(&site, &path, q.lang) {
        Answer::Render(p) => p,
        Answer::Redirect(to) => return redirect(&wp.core.public_origin, &to),
        Answer::NotFound => return (StatusCode::NOT_FOUND, "Not found").into_response(),
    };
    let wp_uri = format!("{}{}", page.path, q.form_uri_query);
    let Some(html) = theme::render(&site, page, &wp_uri) else {
        tracing::error!("{path} is claimed but its template is not ported");
        return (StatusCode::INTERNAL_SERVER_ERROR, "Internal error").into_response();
    };
    let islands = islands(&site, page);
    let filled = crate::cms::forms::fill(&html, |form, index| {
        let embed = islands.get(&index).filter(|e| e.form == form).cloned();
        let wp = wp.clone();
        let wp_uri = wp_uri.clone();
        let lang = page.lang;
        async move {
            let e = embed.ok_or_else(|| format!("form {form} is not in the page's islands"))?;
            let embed = FormEmbed { form, lang, page_uri: &wp_uri, ajax: e.ajax, theme: &e.theme, styles: &e.styles, in_content: e.in_content };
            match &wp.forms {
                Some(f) => f.markup(&embed).await,
                None => Err("no form source".to_string()),
            }
        }
    })
    .await;
    match filled {
        Ok(html) => html_response(public(&wp.core.public_origin, &html)),
        Err(e) => {
            tracing::error!("{path}: {e}");
            (StatusCode::BAD_GATEWAY, "Bad gateway").into_response()
        }
    }
}

#[derive(Debug, Clone, serde::Deserialize)]
struct Island {
    form: u32,
    #[serde(default)]
    ajax: bool,
    #[serde(default)]
    theme: String,
    #[serde(default)]
    styles: String,
    #[serde(default)]
    in_content: bool,
}

/// The forms of a page in render order, as the import recorded them.
fn islands(site: &Site, page: &Page) -> BTreeMap<usize, Island> {
    let raw = site.meta(page.id).get("islands").map(String::as_str).unwrap_or("[]");
    serde_json::from_str::<Vec<Island>>(raw).unwrap_or_default().into_iter().enumerate().collect()
}

/// Links in the page point at the public host (a staging copy rewrites them, as the static pages do).
fn public(origin: &str, html: &str) -> String {
    let host = origin.trim_start_matches("https://");
    if host == "www.harcourt.co" {
        return html.to_string();
    }
    html.replace("//www.harcourt.co", &format!("//{host}")).replace("\\/\\/www.harcourt.co", &format!("\\/\\/{host}"))
}

fn html_response(html: String) -> Response<Body> {
    let mut r = Response::new(Body::from(html));
    let h = r.headers_mut();
    h.insert(header::CONTENT_TYPE, HeaderValue::from_static("text/html; charset=UTF-8"));
    h.insert(header::CACHE_CONTROL, HeaderValue::from_static("no-cache"));
    h.insert(header::STRICT_TRANSPORT_SECURITY, HeaderValue::from_static("max-age=31536000; includeSubDomains"));
    h.insert("x-hxweb", HeaderValue::from_static("cms"));
    r
}

/// A 301 as WordPress sends it: an absolute Location on this site, an empty text/html body.
fn redirect(origin: &str, to: &str) -> Response<Body> {
    match HeaderValue::from_str(&format!("{origin}{to}")) {
        Ok(v) => (StatusCode::MOVED_PERMANENTLY, [(header::LOCATION, v), (header::CONTENT_TYPE, HeaderValue::from_static("text/html; charset=UTF-8"))]).into_response(),
        Err(_) => (StatusCode::NOT_FOUND, "Not found").into_response(),
    }
}

fn gone() -> Response<Body> {
    (StatusCode::GONE, [(header::CACHE_CONTROL, "no-store")], "Gone\n").into_response()
}

/// redirect_canonical(): a page path without its trailing slash moves to the slashed one, query kept.
async fn add_slash(State(wp): State<Arc<Wp>>, uri: Uri) -> Response<Body> {
    let to = match uri.query() {
        Some(q) => format!("{}/?{q}", uri.path()),
        None => format!("{}/", uri.path()),
    };
    redirect(&wp.core.public_origin, &to)
}

async fn robots() -> impl IntoResponse {
    ([(header::CONTENT_TYPE, "text/plain")], ROBOTS)
}

/// WordPress entry points nothing needs any more, answered 410 by retired.rs before anything reaches Apache.
/// `segments` is the decoded, dot-resolved path. xmlrpc.php, comment and trackback posting are retired outright;
/// sign-in, the admin screens and the REST API wait for HXWEB_RETIRE_WORDPRESS=1, because staff still use
/// wp-admin until page editing moves to the dashboard. admin-ajax.php stays open while Gravity Forms needs it.
pub fn retired(segments: &[&str]) -> bool {
    static ALL: std::sync::OnceLock<bool> = std::sync::OnceLock::new();
    let all = *ALL.get_or_init(|| std::env::var("HXWEB_RETIRE_WORDPRESS").is_ok_and(|v| v == "1"));
    retired_with(segments, all)
}

fn retired_with(segments: &[&str], all: bool) -> bool {
    let is = |s: &str, name: &str| s.eq_ignore_ascii_case(name);
    if segments.iter().any(|s| ["xmlrpc.php", "wp-comments-post.php", "wp-trackback.php"].iter().any(|n| is(s, n))) {
        return true;
    }
    if !all {
        return false;
    }
    if segments.iter().any(|s| is(s, "wp-login.php")) {
        return true;
    }
    match segments.first() {
        Some(s) if is(s, "wp-json") => true,
        Some(s) if is(s, "wp-admin") => !segments.get(1).is_some_and(|n| is(n, "admin-ajax.php")),
        _ => false,
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::cms::{model::Template, PageId};

    fn page(id: i64, group: i64, lang: Lang, path: &str, status: Status) -> Page {
        Page {
            id: PageId(id),
            group_id: group,
            lang,
            path: path.into(),
            template: Template::ProductSingle,
            status,
            title: String::new(),
            parent_id: None,
            menu_order: 0,
            published_at: String::new(),
            modified_at: String::new(),
        }
    }

    #[test]
    fn languages_come_from_wpml_lang_only() {
        assert_eq!(query("lang=fr&hxlang=fr").lang, Lang::Fr);
        assert_eq!(query("lang=fr").form_uri_query, "?lang=fr");
        assert_eq!(query("lang=en&utm_source=x").lang, Lang::En);
        assert_eq!(query("lang=en&utm_source=x").form_uri_query, "?utm_source=x");
        assert_eq!(query("lang=de").lang, Lang::En);
        assert!(query("rest_route=/wp/v2/users").rest_route);
        assert_eq!(query("").form_uri_query, "");
    }

    #[test]
    fn a_path_resolves_to_its_page_or_to_its_translation() {
        let site = Site::new(vec![
            page(1, 10, Lang::En, "/product/alignment/", Status::Publish),
            page(2, 10, Lang::Fr, "/gamme-de-produits/centrage/", Status::Publish),
            page(3, 20, Lang::Fr, "/industries/", Status::Publish),
            page(4, 20, Lang::En, "/?page_id=4", Status::Draft),
            page(5, 30, Lang::En, "/legal/", Status::Publish),
            page(6, 40, Lang::Fr, "/seulement/", Status::Publish),
        ]);
        assert_eq!(resolve(&site, "/product/alignment/", Lang::En), Answer::Render(&site.pages[0]));
        assert_eq!(resolve(&site, "/gamme-de-produits/centrage/", Lang::Fr), Answer::Render(&site.pages[1]));
        assert_eq!(resolve(&site, "/gamme-de-produits/centrage/", Lang::En), Answer::Redirect("/product/alignment/".into()));
        assert_eq!(resolve(&site, "/product/alignment/", Lang::Fr), Answer::Redirect("/gamme-de-produits/centrage/?lang=fr".into()));
        assert_eq!(resolve(&site, "/industries/", Lang::En), Answer::Redirect("/?page_id=4".into()));
        assert_eq!(resolve(&site, "/seulement/", Lang::En), Answer::Redirect("/seulement/?lang=fr".into()));
        assert_eq!(resolve(&site, "/legal/", Lang::Fr), Answer::NotFound);
        assert_eq!(resolve(&site, "/nowhere/", Lang::En), Answer::NotFound);
    }

    #[test]
    fn retires_wordpress_entry_points() {
        for p in [["xmlrpc.php"].as_slice(), &["en", "XMLRPC.PHP"], &["wp-comments-post.php"], &["wp-trackback.php"]] {
            assert!(retired_with(p, false), "{p:?}");
        }
        assert!(!retired_with(&["wp-login.php"], false));
        for p in [["wp-login.php"].as_slice(), &["fr", "wp-login.php"], &["wp-admin"], &["wp-admin", "options.php"], &["wp-json"], &["wp-json", "wp", "v2", "users"], &["WP-JSON", "oembed"]] {
            assert!(retired_with(p, true), "{p:?}");
        }
        for p in [["wp-admin", "admin-ajax.php"].as_slice(), &["wp-content", "uploads", "x.png"], &["product", "alignment"], &[]] {
            assert!(!retired_with(p, true), "{p:?}");
        }
    }

    #[test]
    fn staging_hosts_get_their_own_links() {
        assert_eq!(public("https://www.harcourt.co", "a //www.harcourt.co/x"), "a //www.harcourt.co/x");
        assert_eq!(public("https://stage2.harcourt.co", "a https://www.harcourt.co/x \"https:\\/\\/www.harcourt.co\\/y\""), "a https://stage2.harcourt.co/x \"https:\\/\\/stage2.harcourt.co\\/y\"");
    }
}
