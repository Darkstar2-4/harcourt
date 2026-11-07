//! Catalog pages (/catalog/, /catalogue/) in one response.
//!
//! Before: a WordPress shell whose JS, after DOMContentLoaded, asked /scscpq_wp.php for a link and jQuery.load()ed
//! catalog.php, which re-downloaded jQuery, jQuery UI and the SCS scripts through synchronous XHR. Here the same two
//! calls happen on the server and the catalog markup, scripts included, goes into the shell.

use std::{
    net::SocketAddr,
    sync::Arc,
    time::Instant,
};

use axum::{
    body::Body,
    extract::{ConnectInfo, Request},
    http::{header, HeaderMap, HeaderValue, Method, Response, StatusCode},
};
use hxweb::{
    accounts::visit::{self, VisitId},
    api::Auth,
    catalog::viewer::Lang,
    cms::{
        self,
        forms::{FormSource, OrdersForms},
        Content, FormEmbed,
    },
    orders::Orders,
};

use crate::{tabs, App};

/// The Font Awesome kit (5.15.4 Pro, webfont mode) injects three stylesheets, then its auto-accessibility feature
/// watches every DOM change to put aria-hidden on icons. While the parser streams a 1,066-row parts table that
/// watcher cost ~600 ms of main thread. Linking the same stylesheets where the kit put them, and marking the page's
/// few static icons aria-hidden, gives the same result with no script.
const FA_CSS: &str = concat!(
    "<link rel='stylesheet' id='fa-main' href='https://ka-p.fontawesome.com/releases/v5.15.4/css/pro.min.css?token=d628ad4820'>\n",
    "<link rel='stylesheet' id='fa-v4-shims' href='https://ka-p.fontawesome.com/releases/v5.15.4/css/pro-v4-shims.min.css?token=d628ad4820'>\n",
    "<link rel='stylesheet' id='fa-v4-font-face' href='https://ka-p.fontawesome.com/releases/v5.15.4/css/pro-v4-font-face.min.css?token=d628ad4820'>\n",
);
/// On phones a wide parts table (16 columns, 1,900 px) used to widen the whole page and push the menu button
/// off-screen. The catalog scrolls sideways inside its own box instead; desktop is unchanged.
/// In-page catalog navigation (hx/hx-nav.js): clicks inside the catalog swap only the catalog markup. The gate
/// registers first so Back/Forward entries hx-nav created never reach the unit-filter script's popstate handler.
const HX_NAV: &str = "<script src=\"/hx/hx-nav.js?v=9\" defer></script>\n";
const HX_GATE: &str = "<script>addEventListener('popstate', function (e) { if (e.state && e.state.hx && window.hxPopstate) { e.stopImmediatePropagation(); window.hxPopstate(e); } });</script>\n";
/// The catalog header band (#products_header, products.css) shows a background photo, which the browser only asks for
/// once it has styled the band; on subcategory pages it was the largest paint and arrived after the rest of the page.
/// Preloading starts it with the stylesheets.
const HEADER_BG: &str = "<link rel=\"preload\" as=\"image\" href=\"/scs_images/headerbg.jpg\" fetchpriority=\"high\">\n";
const MOBILE_CSS: &str = "<style id=hx-mobile>@media (max-width: 991px) { #scscpq_page { max-width: 100vw; overflow-x: auto; } }</style>\n";

#[derive(Clone, Copy, PartialEq)]
pub struct Page {
    lang: &'static str,
    path: &'static str,
    base_query: &'static str,
}

const EN: Page = Page { lang: "en", path: "/catalog/", base_query: "" };
const FR: Page = Page { lang: "fr", path: "/catalogue/", base_query: "lang=fr" };

fn page_for(lang: &str) -> Option<Page> {
    match lang.to_ascii_lowercase().as_str() {
        "en" => Some(EN),
        "fr" => Some(FR),
        _ => None,
    }
}

pub async fn handle(app: &App, req: Request) -> Response<Body> {
    let uri = req.uri().clone();
    let headers = req.headers().clone();
    let raw_query = uri.query().unwrap_or("");
    let flag_click = raw_query.split('&').any(|kv| kv.starts_with("hxlang="));
    let query = &raw_query.split('&').filter(|kv| !kv.starts_with("hxlang=")).collect::<Vec<_>>().join("&");
    let page = match hxweb::catalog::composed_page(uri.path(), query) {
        Some(Lang::En) => EN,
        Some(Lang::Fr) => FR,
        None => return Response::builder().status(StatusCode::NOT_FOUND).body(Body::from("Not found")).unwrap(),
    };
    let nav = headers.contains_key("x-hx-nav");
    let peer = req.extensions().get::<ConnectInfo<SocketAddr>>().cloned();
    let start = Instant::now();
    match compose(app, page, query, flag_click, nav, &headers, peer).await {
        Ok(resp) => {
            tracing::info!("{} composed in {:?}", uri, start.elapsed());
            resp
        }
        Err(e) if nav => {
            tracing::warn!("{} in-page navigation failed, the browser loads the page: {e}", uri);
            json_response(serde_json::json!({ "reload": true }), Vec::new())
        }
        Err(e) => {
            tracing::error!("{} not composed: {e}", uri);
            Response::builder().status(StatusCode::BAD_GATEWAY).body(Body::from("Bad gateway")).unwrap()
        }
    }
}

/// The request URI as WordPress sees it: WPML redirects the default language's `lang=en` away, French keeps
/// the query as sent.
fn page_uri(page: Page, query: &str) -> String {
    let q = if page.base_query.is_empty() {
        query.split('&').filter(|kv| !kv.is_empty() && *kv != "lang=en").collect::<Vec<_>>().join("&")
    } else if query.split('&').any(|kv| kv.starts_with("lang=")) {
        query.to_string()
    } else if query.is_empty() {
        page.base_query.to_string()
    } else {
        format!("{}&{}", page.base_query, query)
    };
    if q.is_empty() { page.path.to_string() } else { format!("{}?{}", page.path, q) }
}

/// The loader's link as a request target: the PHP wrote the page's query names as they came (a quote, a brace),
/// which the browser's URL parser percent-encoded before asking.
fn request_target(link: &str) -> String {
    const KEEP: &[u8] = b"-._~:/?#[]@!$&'()*+,;=%";
    let mut out = String::with_capacity(link.len());
    for b in link.bytes() {
        if b.is_ascii_alphanumeric() || KEEP.contains(&b) {
            out.push(b as char);
        } else {
            out.push_str(&format!("%{b:02X}"));
        }
    }
    out
}

fn without_lang(query: &str) -> String {
    query.split('&').filter(|kv| !kv.is_empty() && !kv.starts_with("lang=")).collect::<Vec<_>>().join("&")
}

/// The same catalog view in the other language. hxlang marks a flag click, so the page saves the choice to the account.
fn flag_href(page: Page, query: &str) -> String {
    let uri = page_uri(page, &without_lang(query));
    format!("{}{}hxlang={}", uri, if uri.contains('?') { "&" } else { "?" }, page.lang)
}

/// The signed-in account's language as the loader names it; None for a visitor not signed in.
fn account_language(loader: &serde_json::Value) -> Option<Page> {
    loader.get("account_language").and_then(|v| v.as_str()).and_then(page_for)
}

/// The signed-in account's language, when the loader names one other than the page's.
fn other_account_language(loader: &serde_json::Value, page: Page) -> Option<Page> {
    account_language(loader).filter(|a| *a != page)
}

/// The language a signed-in visit's catalog pages are in: the one a flag click chose this visit, else the
/// account's. The flag click's page saves its language to the account only once loaded, and the visit's next
/// requests (a link fetched ahead of a click among them) must not be sent back to the old one meanwhile.
async fn visit_language(app: &App, visit: Option<&VisitId>, account: Page) -> Result<Page, String> {
    let (Some(db), Some(visit)) = (&app.db, visit) else { return Ok(account) };
    let stored = visit::load(db, visit, hxweb::db::unix_now()).await.map_err(|e| format!("visit: {e}"))?;
    Ok(stored.flag_language.as_deref().and_then(page_for).unwrap_or(account))
}

/// A signed-in visitor's flag click: the visit takes the page's language, so the catalog fragment is rendered in it
/// and the visit keeps to it (`visit_language`). Only this browser's visit changes; the account is the page's to save.
async fn choose_flag_language(app: &App, visit: &VisitId, page: Page) -> Result<(), String> {
    let Some(db) = &app.db else { return Ok(()) };
    visit::choose_flag_language(db, visit, &page.lang.to_ascii_uppercase(), hxweb::db::unix_now()).await.map_err(|e| format!("visit: {e}"))
}

/// A flag click's page, once loaded, saves its language to the account, as the loader's request from the page
/// script did (remember=1), and only from the page itself.
fn remember_script(page: Page) -> String {
    format!(
        "<script>fetch('/scscpq_wp.php', {{ method: 'POST', credentials: 'same-origin', body: new URLSearchParams({{ action: 'language', language: '{}', remember: '1' }}) }});</script>\n",
        page.lang
    )
}

fn json_response(v: serde_json::Value, cookies: Vec<HeaderValue>) -> Response<Body> {
    let mut resp = Response::builder()
        .status(StatusCode::OK)
        .header(header::CONTENT_TYPE, "application/json")
        .header(header::CACHE_CONTROL, "no-store")
        .header("x-hxweb", "nav");
    for c in cookies {
        resp = resp.header(header::SET_COOKIE, c);
    }
    resp.body(Body::from(v.to_string())).unwrap()
}

/// Where an in-process answer sends the browser instead (a Denied bot to the home page, a landing page), so the
/// page answers with that.
fn sent_elsewhere(status: StatusCode, headers: &HeaderMap) -> Option<&str> {
    status.is_redirection().then(|| headers.get(header::LOCATION)?.to_str().ok()).flatten()
}

/// The page answers by sending the browser to `to`, with the cookies the internal calls set: hx-nav.js gets it as
/// JSON and follows it itself.
fn redirect(nav: bool, to: &str, why: &'static str, cookies: Vec<HeaderValue>) -> Result<Response<Body>, String> {
    if nav {
        return Ok(json_response(serde_json::json!({ "redirect": to }), cookies));
    }
    let mut resp = Response::builder()
        .status(StatusCode::FOUND)
        .header(header::LOCATION, to)
        .header(header::CACHE_CONTROL, "no-store")
        .header("x-hxweb", why);
    for c in cookies {
        resp = resp.header(header::SET_COOKIE, c);
    }
    resp.body(Body::empty()).map_err(|e| e.to_string())
}

/// `nav`: the request comes from hx-nav.js (in-page navigation) and gets only the catalog markup and the flag links
/// as JSON; the browser keeps the page around it.
async fn compose(app: &App, page: Page, query: &str, flag_click: bool, nav: bool, headers: &HeaderMap, peer: Option<ConnectInfo<SocketAddr>>) -> Result<Response<Body>, String> {
    let uri = page_uri(page, query);
    let page_url = format!("https://{}{}", app.site, uri);

    let mut jar = CookieJar::from_request(headers);
    let mut set_cookies: Vec<HeaderValue> = Vec::new();
    let from = Caller { incoming: headers, peer, page_url: &page_url };

    let form = [
        ("action", "initial"),
        ("page", "catalog"),
        ("document", ""),
        ("token", ""),
        ("url", page_url.as_str()),
        ("language", page.lang),
        ("remember", "0"),
    ]
    .iter()
    .map(|(k, v)| format!("{k}={}", percent_encoding::utf8_percent_encode(v, percent_encoding::NON_ALPHANUMERIC)))
    .collect::<Vec<_>>()
    .join("&");
    // The loader stores the page's language on the visit; a plain link that is then sent to the visit's own
    // language must leave the visit as it was, whether or not the redirect is followed.
    let came_with = hxweb::visitor::visit_of(headers);
    let language_before = match (&app.db, &came_with) {
        (Some(db), Some(v)) => Some(visit::load(db, v, hxweb::db::unix_now()).await.map_err(|e| format!("visit: {e}"))?.language_code),
        _ => None,
    };
    let (status, loader_headers, loader_body) = internal(app, &from, Method::POST, "/scscpq_wp.php", &jar, form).await?;
    collect_cookies(&loader_headers, &mut jar, &mut set_cookies);
    if let Some(to) = sent_elsewhere(status, &loader_headers) {
        return redirect(nav, to, "redirect", set_cookies);
    }
    if !status.is_success() {
        return Err(format!("loader status {status}"));
    }
    let loader_json: serde_json::Value = serde_json::from_slice(&loader_body).map_err(|e| format!("loader json: {e}"))?;
    // A signed-in customer who reached the other language by a plain link (an email, a bookmark) stays in the
    // visit's language; only a flag click changes it.
    let visit = jar.header().and_then(|c| hxweb::visitor::visit_of(&HeaderMap::from_iter([(header::COOKIE, c)])));
    match (account_language(&loader_json), &visit) {
        (Some(_), Some(visit)) if flag_click => choose_flag_language(app, visit, page).await?,
        (Some(account), _) if !flag_click => {
            let to = visit_language(app, visit.as_ref(), account).await?;
            if to != page {
                // The visit the browser came with goes back to its language; one the loader began for this request
                // had none.
                let restore = match (&came_with, &visit) {
                    (Some(v), _) => language_before.clone().map(|before| (v, before)),
                    (None, Some(begun)) => Some((begun, None)),
                    (None, None) => None,
                };
                if let (Some(db), Some((v, before))) = (&app.db, restore) {
                    visit::restore_language(db, v, before.as_deref()).await.map_err(|e| format!("visit: {e}"))?;
                }
                return redirect(nav, &page_uri(to, &without_lang(query)), "account-language", set_cookies);
            }
        }
        _ => {}
    }
    let link = loader_json
        .get("scscpq_link")
        .and_then(|v| v.as_str())
        .ok_or("loader returned no scscpq_link")?;
    let link_path = link.split_once("://").and_then(|(_, rest)| rest.find('/').map(|i| &rest[i..])).ok_or_else(|| format!("unexpected link {link}"))?;
    let link_path = &request_target(link_path);

    // The loader, or a signed-in visitor's flag click, recorded the page's language in the visit, which the fragment
    // request reads through the same session cookie the jar carries, and the browser's own language choice is left
    // alone.
    let (status, frag_headers, frag_body) = internal(app, &from, Method::GET, link_path, &jar, String::new()).await?;
    collect_cookies(&frag_headers, &mut jar, &mut set_cookies);
    if let Some(to) = sent_elsewhere(status, &frag_headers) {
        return redirect(nav, to, "redirect", set_cookies);
    }
    if status != StatusCode::OK {
        return Err(format!("fragment status {status}"));
    }
    let frag_html = String::from_utf8_lossy(&frag_body).into_owned();

    // Keep the catalog's scripts where they are: its CAD iframe's onload calls scscpq_initial() from scscpq.js,
    // so scscpq.js must run before the iframe is parsed, exactly as when jQuery inserted the fragment.
    let markup = app.re.wrapper.replace_all(&frag_html, "");
    let markup = match tabs::preinit(&markup).filter(|_| crate::on("tabs")) {
        Some(m) => m,
        None => markup.into_owned(),
    };
    if nav {
        let flags = serde_json::json!({ "en": flag_href(EN, query), "fr": flag_href(FR, query) });
        return Ok(json_response(
            serde_json::json!({ "url": uri, "html": app.public(&app.vendor.localize(&markup)), "flags": flags }),
            set_cookies,
        ));
    }
    let shell = shell_for(app, page, &uri).await?;
    let open_tag = app.re.placeholder.captures(&shell).ok_or("placeholder missing from shell")?[1].to_string();

    let mut inline = format!("<script>window.hxweb_loader = {};</script>\n", loader_json.to_string().replace("</", "<\\/"));
    if flag_click {
        inline.push_str(&format!("<script>history.replaceState(null, '', {});</script>\n", serde_json::Value::from(uri.as_str())));
        if other_account_language(&loader_json, page).is_some() {
            inline.push_str(&remember_script(page));
        }
    }
    let preload = if crate::on("preload") && app.re.header_bg.is_match(&markup) { HEADER_BG } else { "" };
    let shell = without_fa_kit(app, &shell);
    let shell = app.re.flag.replace_all(&shell, |c: &regex::Captures| {
        let target = flag_href(page_for(&c[2]).unwrap(), query);
        format!("{}{}", &c[1], app.re.href.replace_all(&c[3], regex::NoExpand(&format!("href=\"{target}\""))))
    });
    let html = app
        .re
        .placeholder
        .replacen(&shell, 1, regex::NoExpand(&format!("{open_tag}{markup}</div>")))
        .replacen("</head>", &format!("{preload}{MOBILE_CSS}{HX_GATE}</head>"), 1)
        .replacen("</body>", &format!("{inline}{HX_NAV}</body>"), 1);

    let mut resp = Response::builder()
        .status(StatusCode::OK)
        .header(header::CONTENT_TYPE, "text/html; charset=UTF-8")
        .header(header::CACHE_CONTROL, "no-store, no-cache, must-revalidate")
        .header("strict-transport-security", "max-age=31536000; includeSubDomains")
        .header("x-hxweb", "composed");
    for c in set_cookies {
        resp = resp.header(header::SET_COOKIE, c);
    }
    resp.body(Body::from(app.public(&app.vendor.localize(&html)))).map_err(|e| e.to_string())
}

/// The page with the Font Awesome kit script replaced by the stylesheets it would inject (FA_CSS) and static icons
/// marked aria-hidden as its accessibility feature would. Pages without the kit come back unchanged.
/// Also used for the former WordPress pages, where the kit is a parser-blocking third-party script in <head> that
/// holds DOMContentLoaded, and so the fade-in sections (vendor.rs).
pub fn without_fa_kit(app: &App, html: &str) -> String {
    if !app.re.fa_kit.is_match(html) {
        return html.to_string();
    }
    let html = app.re.fa_kit.replace_all(html, "");
    let html = app.re.fa_icon.replace_all(&html, |c: &regex::Captures| {
        if c[0].contains("aria-hidden") { c[0].to_string() } else { format!("<i{} aria-hidden=\"true\">", &c["a"]) }
    });
    html.replacen("</head>", &format!("{FA_CSS}</head>"), 1)
}

/// The catalog pages as the cms holds them (the wordpress area renders them from there), and the site's forms.
pub struct Cms {
    content: Content,
    forms: OrdersForms,
}

/// One form of a page, as the cms import recorded it in the page's `islands` meta.
#[derive(serde::Deserialize)]
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
    #[serde(default)]
    texturize: bool,
}

impl Cms {
    pub fn new(core: Arc<Auth>) -> Cms {
        Cms { content: Content::new(core.pool.clone()), forms: OrdersForms { orders: Arc::new(Orders::from_env(core)) } }
    }

    /// The page in the theme's markup with its forms in place, or None when the cms lacks it or its template.
    async fn page(&self, page: Page, uri: &str) -> Result<Option<String>, String> {
        let site = self.content.site().await.map_err(|e| format!("cms: {e}"))?;
        let lang = if page.lang == "fr" { cms::Lang::Fr } else { cms::Lang::En };
        let Some(found) = site.find(lang, page.path).filter(|p| p.status == cms::Status::Publish) else { return Ok(None) };
        let Some(html) = cms::theme::render(&site, found, uri) else { return Ok(None) };
        let islands: Vec<Island> = serde_json::from_str(site.meta(found.id).get("islands").map_or("[]", String::as_str)).unwrap_or_default();
        let filled = cms::forms::fill(&html, |form, index| {
            let island = islands.get(index).filter(|i| i.form == form);
            let position = islands.iter().position(|i| i.form == form).unwrap_or(index) + 1;
            async move {
                let i = island.ok_or_else(|| format!("form {form} is not in the page's islands"))?;
                let embed = FormEmbed {
                    form,
                    lang,
                    page_uri: uri,
                    ajax: i.ajax,
                    theme: &i.theme,
                    styles: &i.styles,
                    in_content: i.in_content,
                    texturize: i.texturize,
                    position,
                    user_agent: "",
                };
                self.forms.markup(&embed).await
            }
        })
        .await?;
        Ok(Some(filled))
    }
}

/// The page around the catalog: from the cms tables, else the static export (WordPress's render, kept in step by
/// the export). Only the menu's current-item marker and the form actions follow the request URI: the forms are
/// rendered for it.
async fn shell_for(app: &App, page: Page, uri: &str) -> Result<String, String> {
    let base_uri = page_uri(page, "");
    let current = |html: String| if uri == base_uri { html } else { app.re.current.replace_all(&html, "").into_owned() };
    if let Some(cms) = &app.cms {
        match cms.page(page, uri).await {
            Ok(Some(html)) => return Ok(current(html)),
            Ok(None) => {}
            Err(e) => tracing::warn!("{uri}: the cms page failed, trying the export: {e}"),
        }
    }
    app.pages.assemble(app, page.path, page.lang, uri, None).await?.map(current).ok_or_else(|| format!("no page for {}", page.path))
}

/// The browser request a composed page answers: what the internal calls carry over from it.
struct Caller<'a> {
    incoming: &'a HeaderMap,
    /// The connection's peer, so the library's routes see the same client (and sign in by company address).
    peer: Option<ConnectInfo<SocketAddr>>,
    page_url: &'a str,
}

/// A request to the library's own routes, in-process: the browser's language and agent headers, its connection,
/// the cookies as updated so far, the page as referer.
async fn internal(app: &App, from: &Caller<'_>, method: Method, path: &str, jar: &CookieJar, body: String) -> Result<(StatusCode, HeaderMap, axum::body::Bytes), String> {
    use tower::ServiceExt;
    let lib = app.lib.clone().ok_or("no database")?;
    let mut req = axum::http::Request::builder().method(method.clone()).uri(path);
    for (k, v) in forward_headers(app, from.incoming, jar, from.page_url).iter() {
        req = req.header(k, v);
    }
    if method == Method::POST {
        req = req.header(header::CONTENT_TYPE, "application/x-www-form-urlencoded");
    }
    if let Some(peer) = from.peer {
        req = req.extension(peer);
    }
    let req = req.body(Body::from(body)).map_err(|e| e.to_string())?;
    let resp = lib.oneshot(req).await.map_err(|e| format!("internal {path}: {e}"))?;
    let (parts, body) = resp.into_parts();
    let bytes = axum::body::to_bytes(body, 64 << 20).await.map_err(|e| format!("internal {path} body: {e}"))?;
    Ok((parts.status, parts.headers, bytes))
}

fn forward_headers(app: &App, incoming: &HeaderMap, jar: &CookieJar, page_url: &str) -> HeaderMap {
    let mut h = HeaderMap::new();
    h.insert(header::HOST, HeaderValue::from_str(&app.site).unwrap());
    for name in [header::USER_AGENT, header::ACCEPT_LANGUAGE] {
        if let Some(v) = incoming.get(&name) {
            h.insert(name, v.clone());
        }
    }
    for name in ["x-real-ip", "x-forwarded-for", "x-hx-prefetch"] {
        if let Some(v) = incoming.get(name) {
            h.insert(header::HeaderName::from_static(name), v.clone());
        }
    }
    if let Ok(v) = HeaderValue::from_str(page_url) {
        h.insert(header::REFERER, v);
    }
    if let Ok(v) = HeaderValue::from_str(&app.public_origin) {
        h.insert(header::ORIGIN, v);
    }
    h.insert("x-requested-with", HeaderValue::from_static("XMLHttpRequest"));
    if let Some(c) = jar.header() {
        h.insert(header::COOKIE, c);
    }
    h
}

/// Cookies the browser sent, updated by Set-Cookie from each internal call so the second call sees the first
/// call's session.
struct CookieJar(Vec<(String, String)>);

impl CookieJar {
    fn from_request(h: &HeaderMap) -> Self {
        let mut v = Vec::new();
        for raw in h.get_all(header::COOKIE) {
            for part in raw.to_str().unwrap_or("").split(';') {
                if let Some((k, val)) = part.trim().split_once('=') {
                    v.push((k.to_string(), val.to_string()));
                }
            }
        }
        CookieJar(v)
    }
    fn set(&mut self, name: &str, value: &str) {
        match self.0.iter_mut().find(|(k, _)| k == name) {
            Some(e) => e.1 = value.to_string(),
            None => self.0.push((name.to_string(), value.to_string())),
        }
    }
    fn header(&self) -> Option<HeaderValue> {
        if self.0.is_empty() {
            return None;
        }
        HeaderValue::from_str(&self.0.iter().map(|(k, v)| format!("{k}={v}")).collect::<Vec<_>>().join("; ")).ok()
    }
}

fn collect_cookies(h: &HeaderMap, jar: &mut CookieJar, out: &mut Vec<HeaderValue>) {
    for sc in h.get_all(header::SET_COOKIE) {
        if let Some((k, v)) = sc.to_str().unwrap_or("").split(';').next().and_then(|kv| kv.split_once('=')) {
            jar.set(k.trim(), v.trim());
        }
        out.push(sc.clone());
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn uris() {
        assert_eq!(page_uri(EN, ""), "/catalog/");
        assert_eq!(page_uri(EN, "lang=en&action=sku&sku=X"), "/catalog/?action=sku&sku=X");
        assert_eq!(page_uri(FR, ""), "/catalogue/?lang=fr");
        assert_eq!(page_uri(FR, "lang=fr&action=sku"), "/catalogue/?lang=fr&action=sku");
        assert_eq!(page_uri(FR, "action=sku"), "/catalogue/?lang=fr&action=sku");
        assert_eq!(flag_href(FR, "lang=en&action=subcategory&subcategory_id=658"), "/catalogue/?lang=fr&action=subcategory&subcategory_id=658&hxlang=fr");
        assert_eq!(flag_href(EN, "lang=fr&action=subcategory&subcategory_id=658"), "/catalog/?action=subcategory&subcategory_id=658&hxlang=en");
        assert_eq!(flag_href(EN, "lang=fr"), "/catalog/?hxlang=en");
    }

    #[test]
    fn the_accounts_other_language() {
        let loader = |code: &str| serde_json::json!({ "account_language": code });
        assert!(other_account_language(&loader("FR"), EN) == Some(FR), "a plain link goes there, a flag click stays");
        assert!(other_account_language(&loader("EN"), EN).is_none());
        assert!(other_account_language(&loader(""), FR).is_none(), "not signed in");
        assert!(other_account_language(&serde_json::json!({}), FR).is_none());
        let script = remember_script(FR);
        assert!(script.contains("fetch('/scscpq_wp.php'") && script.contains("action: 'language', language: 'fr', remember: '1'"));
    }

    /// The composer over the library's routes and a private in-memory database with one French-named family.
    async fn composer() -> (App, sqlx::SqlitePool) {
        use axum::extract::connect_info::MockConnectInfo;
        let pool = hxweb::db::open_memory().await.unwrap();
        for sql in [
            "INSERT INTO language (code, name, local_name, active, menu) VALUES ('EN', 'English', 'English', 1, NULL), ('FR', 'French', 'Français', 1, NULL)",
            "INSERT INTO currency (code, name, plural, exchange_rate, adder_pct, active) VALUES ('USD', 'US Dollar', 'US Dollars', 1, 0, 1)",
            "INSERT INTO category (id, name, sort_order, active) VALUES (1, 'Pins', 1, 1)",
            r#"INSERT INTO subcategory (id, category_id, name, parameters, parameter_search, status, page_break, output, leadtime_option) VALUES (7, 1, 'Ball Lock Pins', '[{"hyperlink":0,"id":0,"name":"Diameter","type":"Decimal:2"}]', '', 'Active', 1, '', 'In Stock')"#,
            r#"INSERT INTO inventory (id, sku, category_id, subcategory_id, parameter0, nparameter0, pricing, active, sort_order, leadtime_option) VALUES (1, 'HBLT-1', 1, 7, '0.25', 0.25, '[{"price":10.0,"qty_break":1}]', 1, 1, 'Subcategory')"#,
            "INSERT INTO content (record_type, record_id, record_item, language_code, name, filetype, meta, html_content) VALUES ('subcategory', 7, '', 'FR', 'Goupilles à billes', '', NULL, NULL)",
            r#"INSERT INTO content (record_type, record_id, record_item, language_code, name, filetype, meta, html_content) VALUES ('document', 157, '', 'EN', 'Register', 'html', '{"mime":"text/html"}', '<p>Please request access</p>'), ('document', 157, '', 'FR', 'Inscription', 'html', '{"mime":"text/html"}', '<p>Veuillez demander un accès</p>')"#,
        ] {
            sqlx::query(sql).execute(&pool).await.unwrap();
        }
        let core = Arc::new(Auth {
            pool: pool.clone(),
            secret: Some(hxweb::config::Secret::from_bytes(vec![7; 32])),
            public_origin: "https://www.harcourt.co".into(),
            trusted_proxies: Vec::new(),
            accounts: hxweb::accounts::Services::offline(hxweb::accounts::bot::Dns::fixed(&[], &[])),
        });
        let app = App {
            lib: Some(hxweb::routes::all(core).layer(MockConnectInfo(SocketAddr::from(([192, 0, 2, 1], 40000))))),
            db: Some(pool.clone()),
            cms: None,
            site: "www.harcourt.co".into(),
            public_host: "www.harcourt.co".into(),
            public_origin: "https://www.harcourt.co".into(),
            pages: crate::pages::Pages::new(std::path::PathBuf::new()),
            vendor: crate::vendor::Vendor::load(std::path::Path::new(""), "www.harcourt.co"),
            re: crate::Res::new(),
        };
        (app, pool)
    }

    /// A signed-in English account's cookies, inside a visit of its own.
    async fn signed_in(pool: &sqlx::SqlitePool, id: i64, status: &str) -> String {
        sqlx::query(
            r#"INSERT INTO "user" (id, status, "type", company_name, catalog, cad, ecommerce, price_type, price_pct, currency_code,
                                   country_code, language_code, industry_id, portal_id, landing_document_id, login_document_id)
               VALUES (?1, ?2, 'Customer', 'Test company', 1, 0, 0, '', 0, 'USD', 'US', 'EN', 0, 0, 0, 0)"#,
        )
        .bind(id)
        .bind(status)
        .execute(pool)
        .await
        .unwrap();
        let basis = hxweb::session::Basis::Registration(hxweb::identity::Email::parse("tester@example.com").unwrap());
        let token = hxweb::session::create(pool, hxweb::identity::UserId(id), &basis, hxweb::db::unix_now()).await.unwrap();
        format!("{}={}; PHPSESSID=visit{id}", hxweb::session::COOKIE, token.as_str())
    }

    /// An in-page navigation from hx-nav.js.
    async fn nav(app: &App, uri: &str, cookie: &str) -> serde_json::Value {
        let req = Request::builder().uri(uri).header("x-hx-nav", "1").header(header::COOKIE, cookie).body(Body::empty()).unwrap();
        let body = axum::body::to_bytes(handle(app, req).await.into_body(), 1 << 22).await.unwrap();
        serde_json::from_slice(&body).unwrap()
    }

    #[tokio::test]
    async fn a_visit_begun_by_a_link_sent_to_the_accounts_language_keeps_no_language() {
        let (app, pool) = composer().await;
        let cookie = signed_in(&pool, 63, "Active").await;
        let session_only = cookie.split("; PHPSESSID").next().unwrap().to_string();
        let req = Request::builder()
            .uri("/catalogue/?lang=fr&action=subcategory&subcategory_id=7")
            .header("x-hx-nav", "1")
            .header(header::COOKIE, &session_only)
            .body(Body::empty())
            .unwrap();
        let resp = handle(&app, req).await;
        let sid = resp
            .headers()
            .get_all(header::SET_COOKIE)
            .iter()
            .filter_map(|v| v.to_str().ok())
            .find_map(|c| c.strip_prefix("PHPSESSID=").map(|c| c.split(';').next().unwrap_or("").to_string()))
            .expect("the page began a visit");
        let body: serde_json::Value = serde_json::from_slice(&axum::body::to_bytes(resp.into_body(), 1 << 22).await.unwrap()).unwrap();
        assert_eq!(body, serde_json::json!({ "redirect": "/catalog/?action=subcategory&subcategory_id=7" }));
        let stored = visit::load(&pool, &VisitId::of(&sid), hxweb::db::unix_now()).await.unwrap();
        assert_eq!(stored.language_code, None, "the account's language stands for the visit, whether or not the redirect is followed");
    }

    #[tokio::test]
    async fn a_flag_click_keeps_the_visit_in_the_clicked_language() {
        let (app, pool) = composer().await;
        let fr = "/catalogue/?lang=fr&action=subcategory&subcategory_id=7";
        let en = "/catalog/?action=subcategory&subcategory_id=7";
        // A Pending account is shown the access document, an Active one the family.
        for (id, status, french) in [(61, "Pending", "Veuillez demander un accès"), (62, "Active", "<h1>Goupilles à billes</h1>")] {
            let french = |v: &serde_json::Value| v["html"].as_str().is_some_and(|h| h.contains(french));
            let cookie = signed_in(&pool, id, status).await;
            assert_eq!(nav(&app, fr, &cookie).await, serde_json::json!({ "redirect": en }), "{status}: a plain link goes to the account's language");
            let clicked = nav(&app, &format!("{fr}&hxlang=fr"), &cookie).await;
            assert!(french(&clicked), "{status}: the flag click's page is in French: {clicked}");
            let next = nav(&app, fr, &cookie).await;
            assert!(french(&next), "{status}: so is the next link: {next}");
            let language = || async { visit::load(&pool, &VisitId::of(&format!("visit{id}")), hxweb::db::unix_now()).await.unwrap().language_code };
            let before = language().await;
            assert_eq!(nav(&app, en, &cookie).await, serde_json::json!({ "redirect": fr }), "{status}: a plain link to English goes back to French");
            let after = language().await;
            assert_eq!(after, before, "{status}: and the visit's language is as it was, whether or not the redirect is followed");
        }
    }

    #[test]
    fn links_become_valid_request_targets() {
        assert_eq!(request_target("/catalog.php?action=sku&sku=A%2FB&scscpq_wp=1"), "/catalog.php?action=sku&sku=A%2FB&scscpq_wp=1");
        assert_eq!(request_target("/catalog.php?a\"b{c}=1&é=2"), "/catalog.php?a%22b%7Bc%7D=1&%C3%A9=2");
        assert!(axum::http::Uri::try_from(request_target("/catalog.php?a b|c^=1")).is_ok());
    }

    #[tokio::test]
    async fn an_inner_redirect_becomes_the_pages_answer_with_its_cookies() {
        let mut inner = HeaderMap::new();
        inner.insert(header::LOCATION, HeaderValue::from_static("/index.php"));
        inner.insert(header::SET_COOKIE, HeaderValue::from_static("PHPSESSID=abc; path=/"));
        assert_eq!(sent_elsewhere(StatusCode::FOUND, &inner), Some("/index.php"));
        assert_eq!(sent_elsewhere(StatusCode::INTERNAL_SERVER_ERROR, &inner), None, "a failure is not a redirect");
        assert_eq!(sent_elsewhere(StatusCode::NOT_MODIFIED, &HeaderMap::new()), None);

        let (mut jar, mut cookies) = (CookieJar::from_request(&HeaderMap::new()), Vec::new());
        collect_cookies(&inner, &mut jar, &mut cookies);
        let page = redirect(false, "/index.php", "redirect", cookies.clone()).unwrap();
        assert_eq!(page.status(), StatusCode::FOUND);
        assert_eq!(page.headers()[header::LOCATION], "/index.php");
        assert_eq!(page.headers()[header::SET_COOKIE], "PHPSESSID=abc; path=/");

        let nav = redirect(true, "/index.php", "redirect", cookies).unwrap();
        assert_eq!((nav.status(), &nav.headers()[header::SET_COOKIE]), (StatusCode::OK, &HeaderValue::from_static("PHPSESSID=abc; path=/")));
        let body = axum::body::to_bytes(nav.into_body(), 1 << 10).await.unwrap();
        assert_eq!(serde_json::from_slice::<serde_json::Value>(&body).unwrap(), serde_json::json!({ "redirect": "/index.php" }));
    }
}
