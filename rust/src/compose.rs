//! Catalog pages (/catalog/, /catalogue/) in one response.
//!
//! Before: a WordPress shell whose JS, after DOMContentLoaded, asked /scscpq_wp.php for a link and jQuery.load()ed
//! catalog.php, which re-downloaded jQuery, jQuery UI and the SCS scripts through synchronous XHR. Here the same two
//! PHP calls happen on the server and the catalog markup, scripts included, goes into the shell. Any failure falls
//! back to the legacy response untouched.

use std::time::{Duration, Instant};

use axum::{
    body::Body,
    extract::Request,
    http::{header, HeaderMap, HeaderValue, Response, StatusCode},
};

use crate::{proxy, tabs, App};

pub const SHELL_TTL: Duration = Duration::from_secs(60);
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
    let page = match (uri.path(), lang_of(query)) {
        ("/catalog/", None | Some("en")) => EN,
        ("/catalogue/", Some("fr")) => FR,
        _ => return proxy::pass(app, req).await,
    };
    let nav = headers.contains_key("x-hx-nav");
    let start = Instant::now();
    match compose(app, page, query, flag_click, nav, &headers).await {
        Ok(resp) => {
            tracing::info!("{} composed in {:?}", uri, start.elapsed());
            resp
        }
        Err(e) if nav => {
            tracing::warn!("{} in-page navigation failed, the browser loads the page: {e}", uri);
            json_response(serde_json::json!({ "reload": true }), Vec::new())
        }
        Err(e) => {
            tracing::warn!("{} fallback: {e}", uri);
            proxy::pass(app, req).await
        }
    }
}

/// The catalog markup's own address (/catalog.php?scscpq_wp=1&...) is what hxweb and the catalog's scripts fetch.
/// Opened as a page (a control that navigates there, a copied link) it showed the bare listing without the site
/// header. A browser navigating to it is sent to the catalog page with the same query; fetches are left alone.
pub fn fragment_as_page(path: &str, query: &str, headers: &HeaderMap) -> Option<Response<Body>> {
    let navigation = match headers.get("sec-fetch-dest").and_then(|v| v.to_str().ok()) {
        Some(dest) => dest == "document",
        None => headers.contains_key("upgrade-insecure-requests") && !headers.contains_key("x-requested-with"),
    };
    if path != "/catalog.php" || !navigation || !query.split('&').any(|kv| kv.starts_with("scscpq_wp=")) {
        return None;
    }
    let rest = query.split('&').filter(|kv| !kv.starts_with("scscpq_wp=") && !kv.is_empty()).collect::<Vec<_>>().join("&");
    let page = if lang_of(&rest).is_some_and(|l| l.eq_ignore_ascii_case("fr")) { FR } else { EN };
    Response::builder()
        .status(StatusCode::FOUND)
        .header(header::LOCATION, page_uri(page, &rest))
        .header(header::CACHE_CONTROL, "no-store")
        .header("x-hxweb", "fragment-as-page")
        .body(Body::empty())
        .ok()
}

fn lang_of(query: &str) -> Option<&str> {
    query.split('&').find_map(|kv| kv.strip_prefix("lang="))
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

fn without_lang(query: &str) -> String {
    query.split('&').filter(|kv| !kv.is_empty() && !kv.starts_with("lang=")).collect::<Vec<_>>().join("&")
}

/// The same catalog view in the other language. hxlang marks a flag click, so the choice is saved to the account.
fn flag_href(page: Page, query: &str) -> String {
    let uri = page_uri(page, &without_lang(query));
    format!("{}{}hxlang={}", uri, if uri.contains('?') { "&" } else { "?" }, page.lang)
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

/// `nav`: the request comes from hx-nav.js (in-page navigation) and gets only the catalog markup and the flag links
/// as JSON; the browser keeps the page around it.
async fn compose(app: &App, page: Page, query: &str, flag_click: bool, nav: bool, headers: &HeaderMap) -> Result<Response<Body>, String> {
    let uri = page_uri(page, query);
    let page_url = format!("https://{}{}", app.site, uri);

    let mut jar = CookieJar::from_request(headers);
    let mut set_cookies: Vec<HeaderValue> = Vec::new();

    let loader = app
        .http
        .post(format!("{}/scscpq_wp.php", app.legacy))
        .headers(forward_headers(app, headers, &jar, &page_url))
        .form(&[
            ("action", "initial"),
            ("page", "catalog"),
            ("document", ""),
            ("token", ""),
            ("url", page_url.as_str()),
            ("language", page.lang),
            ("remember", if flag_click { "1" } else { "0" }),
        ])
        .send()
        .await
        .map_err(|e| format!("loader: {e}"))?;
    if !loader.status().is_success() {
        return Err(format!("loader status {}", loader.status()));
    }
    collect_cookies(loader.headers(), &mut jar, &mut set_cookies);
    let loader_json: serde_json::Value = loader.json().await.map_err(|e| format!("loader json: {e}"))?;
    // A signed-in customer who reached the other language by a plain link (an email, a bookmark) stays in the
    // account's language; only a flag click changes it.
    let account = loader_json.get("account_language").and_then(|v| v.as_str()).and_then(page_for);
    if let Some(account) = account.filter(|a| *a != page && !flag_click) {
        if nav {
            return Ok(json_response(serde_json::json!({ "redirect": page_uri(account, &without_lang(query)) }), set_cookies));
        }
        let mut resp = Response::builder()
            .status(StatusCode::FOUND)
            .header(header::LOCATION, page_uri(account, &without_lang(query)))
            .header(header::CACHE_CONTROL, "no-store")
            .header("x-hxweb", "account-language");
        for c in set_cookies {
            resp = resp.header(header::SET_COOKIE, c);
        }
        return resp.body(Body::empty()).map_err(|e| e.to_string());
    }
    let link = loader_json
        .get("scscpq_link")
        .and_then(|v| v.as_str())
        .ok_or("loader returned no scscpq_link")?;
    let link_path = link
        .strip_prefix(&format!("https://{}", app.site))
        .ok_or_else(|| format!("unexpected link {link}"))?;

    let frag = app
        .http
        .get(format!("{}{}", app.legacy, link_path))
        .headers(forward_headers(app, headers, &jar, &page_url))
        .send()
        .await
        .map_err(|e| format!("fragment: {e}"))?;
    if frag.status() != StatusCode::OK {
        return Err(format!("fragment status {}", frag.status()));
    }
    collect_cookies(frag.headers(), &mut jar, &mut set_cookies);
    let frag_html = frag.text().await.map_err(|e| format!("fragment body: {e}"))?;

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

    let mut inline = format!(
        "<script>window.hxweb_loader = {};</script>\n",
        serde_json::to_string(&loader_json).map_err(|e| e.to_string())?.replace("</", "<\\/")
    );
    if flag_click {
        inline.push_str(&format!("<script>history.replaceState(null, '', {});</script>\n", serde_json::Value::from(uri.as_str())));
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

/// The page around the catalog: from the static export when it has the page (WordPress's render, kept in step by
/// the export), else WordPress itself. Only the menu's current-item marker and the form actions follow the request
/// URI; the export's form islands take the URI directly, the WordPress fallback is patched.
async fn shell_for(app: &App, page: Page, uri: &str) -> Result<String, String> {
    if let Some(html) = app.pages.assemble(app, page.path, page.lang, uri).await? {
        let base_uri = page_uri(page, "");
        return Ok(if uri == base_uri { html } else { app.re.current.replace_all(&html, "").into_owned() });
    }
    let base_uri = page_uri(page, "");
    let cached = app.shells.read().await.get(page.path).filter(|(at, _)| at.elapsed() < SHELL_TTL).map(|(_, s)| s.clone());
    let base = match cached {
        Some(s) => s,
        None => {
            let r = app
                .http
                .get(format!("{}{}", app.legacy, base_uri))
                .header(header::HOST, &app.site)
                .header(header::USER_AGENT, "hxweb/0.1 shell")
                .send()
                .await
                .map_err(|e| format!("shell: {e}"))?;
            if r.status() != StatusCode::OK {
                return Err(format!("shell status {}", r.status()));
            }
            let s = r.text().await.map_err(|e| format!("shell body: {e}"))?;
            app.shells.write().await.insert(page.path, (Instant::now(), s.clone()));
            s
        }
    };
    if uri == base_uri {
        return Ok(base);
    }
    let from = format!("action='{}#gf_", base_uri.replace('&', "&#038;"));
    let to = format!("action='{}#gf_", uri.replace('&', "&#038;"));
    Ok(app.re.current.replace_all(&base, "").replace(&from, &to))
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
    fn fragment_opened_as_a_page() {
        let mut nav = HeaderMap::new();
        nav.insert("sec-fetch-dest", HeaderValue::from_static("document"));
        let loc = |q: &str, h: &HeaderMap| fragment_as_page("/catalog.php", q, h).map(|r| r.headers()[header::LOCATION].to_str().unwrap().to_string());
        assert_eq!(loc("scscpq_wp=1", &nav).as_deref(), Some("/catalog/"));
        assert_eq!(loc("scscpq_wp=1&action=subcategory&subcategory_id=731", &nav).as_deref(), Some("/catalog/?action=subcategory&subcategory_id=731"));
        assert_eq!(loc("lang=fr&action=sku&scscpq_wp=1", &nav).as_deref(), Some("/catalogue/?lang=fr&action=sku"));
        let mut fetch = HeaderMap::new();
        fetch.insert("sec-fetch-dest", HeaderValue::from_static("empty"));
        assert_eq!(loc("scscpq_wp=1&unit_type=metric", &fetch), None);
        let mut xhr = HeaderMap::new();
        xhr.insert("upgrade-insecure-requests", HeaderValue::from_static("1"));
        xhr.insert("x-requested-with", HeaderValue::from_static("XMLHttpRequest"));
        assert_eq!(loc("scscpq_wp=1", &xhr), None);
        assert_eq!(loc("action=subcategory", &nav), None);
        assert!(fragment_as_page("/cart.php", "scscpq_wp=1", &nav).is_none());
    }

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
}
