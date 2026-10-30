//! hxweb: the front of www.harcourt.co. Builds the catalog pages in one response (compose.rs), serves the former
//! WordPress pages from the static export (pages.rs), and passes everything else to the legacy Apache/PHP stack
//! unchanged (proxy.rs).

mod compose;
mod pages;
mod proxy;
mod tabs;
mod vendor;

use std::{collections::BTreeMap, net::SocketAddr, path::PathBuf, sync::Arc, time::{Duration, Instant}};

use axum::{
    body::Body,
    extract::{ConnectInfo, Request, State},
    http::{Method, Response, StatusCode},
    Router,
};
use regex::Regex;
use tokio::sync::RwLock;

pub struct App {
    legacy: String,
    /// The library's routes, called in-process by the composer (the catalog fragment and the loader JSON). None
    /// without a database, in which case the composer passes the page to PHP.
    lib: Option<Router>,
    /// The catalog pages' shell from the cms tables. None without a database.
    cms: Option<compose::Cms>,
    site: String,
    /// The host visitors use. Differs from `site` on a staging copy (stage2.harcourt.co), where links are rewritten.
    public_host: String,
    /// Internal calls hxweb makes for a page (loader, fragment, shell, islands): short timeout.
    http: reqwest::Client,
    /// Requests passed through for the browser: may legitimately run long (exports, CAD generation).
    legacy_http: reqwest::Client,
    shells: RwLock<BTreeMap<&'static str, (Instant, String)>>,
    pages: pages::Pages,
    vendor: vendor::Vendor,
    re: Res,
}

struct Res {
    wrapper: Regex,
    current: Regex,
    placeholder: Regex,
    flag: Regex,
    href: Regex,
    fa_kit: Regex,
    fa_icon: Regex,
    header_bg: Regex,
}

impl App {
    /// Absolute links to the real site, pointed at the staging host (plain and JSON-escaped forms).
    fn public(&self, s: &str) -> String {
        if self.public_host == self.site {
            return s.to_string();
        }
        s.replace(&format!("//{}", self.site), &format!("//{}", self.public_host))
            .replace(&format!("\\/\\/{}", self.site), &format!("\\/\\/{}", self.public_host))
    }

    fn public_bytes(&self, b: &[u8]) -> Vec<u8> {
        let mut out = b.to_vec();
        for (from, to) in [
            (format!("//{}", self.site), format!("//{}", self.public_host)),
            (format!("\\/\\/{}", self.site), format!("\\/\\/{}", self.public_host)),
        ] {
            out = replace_bytes(&out, from.as_bytes(), to.as_bytes());
        }
        out
    }
}

fn replace_bytes(hay: &[u8], from: &[u8], to: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(hay.len());
    let mut i = 0;
    while i < hay.len() {
        if hay[i..].starts_with(from) {
            out.extend_from_slice(to);
            i += from.len();
        } else {
            out.push(hay[i]);
            i += 1;
        }
    }
    out
}

fn client(timeout: Duration) -> reqwest::Client {
    reqwest::Client::builder()
        .danger_accept_invalid_certs(true)
        .redirect(reqwest::redirect::Policy::none())
        .timeout(timeout)
        .build()
        .expect("http client")
}


/// Page rewrites that can be switched off without a new build: HXWEB_OFF=tabs,repeats,dead-assets,preload,aos,fa-static,bg-preload,fonts-css.
pub fn on(feature: &str) -> bool {
    static OFF: std::sync::OnceLock<Vec<String>> = std::sync::OnceLock::new();
    !OFF.get_or_init(|| std::env::var("HXWEB_OFF").unwrap_or_default().split(',').map(|s| s.trim().to_string()).collect())
        .iter()
        .any(|f| f == feature)
}

#[tokio::main]
async fn main() {
    tracing_subscriber::fmt().with_target(false).init();
    let args: Vec<String> = std::env::args().skip(1).collect();
    if args.first().map(String::as_str) == Some("job") {
        std::process::exit(hxweb::staff::jobs::cli(&args[1..]).await);
    }
    let env = |k: &str, d: &str| std::env::var(k).unwrap_or_else(|_| d.to_string());
    let listen = env("HXWEB_LISTEN", "127.0.0.1:8090");
    let core = open_auth().await;
    let lib = core.clone().map(hxweb::routes::all);
    let app = Arc::new(App {
        legacy: env("HXWEB_LEGACY", "https://127.0.0.1:8443"),
        lib: lib.clone(),
        cms: core.map(compose::Cms::new),
        site: env("HXWEB_SITE", "www.harcourt.co"),
        public_host: env("HXWEB_PUBLIC_HOST", &env("HXWEB_SITE", "www.harcourt.co")),
        http: client(Duration::from_secs(30)),
        legacy_http: client(Duration::from_secs(600)),
        shells: RwLock::new(BTreeMap::new()),
        pages: pages::Pages::new(PathBuf::from(env("HXWEB_EXPORT", "/home/harcou6/static_site"))),
        vendor: vendor::Vendor::load(&PathBuf::from(env("HXWEB_DOCROOT", "/home/harcou6/public_html")), &env("HXWEB_SITE", "www.harcourt.co")),
        re: Res {
            wrapper: Regex::new(r"(?is)<!DOCTYPE[^>]*>|</?(html|head|body)\b[^>]*>").unwrap(),
            current: Regex::new(r" current-menu-item active").unwrap(),
            placeholder: Regex::new(r"(<div\b[^>]*\bid=scscpq_page>)catalog</div>").unwrap(),
            flag: Regex::new(r"(<li\b[^>]*\bwpml-ls-item-(en|fr)\b[^>]*>\s*)(<a\b[^>]*>)").unwrap(),
            href: Regex::new(r#"\bhref="[^"]*""#).unwrap(),
            fa_kit: Regex::new(r"<script\b[^>]*kit\.fontawesome\.com/[^>]*>\s*</script>\n?").unwrap(),
            fa_icon: Regex::new(r#"<i\b(?P<a>[^>]*\bclass=["'][^"']*\bfa[a-z]?\b[^>]*)>"#).unwrap(),
            header_bg: Regex::new(r#"<div\b[^>]*\bid=["']?products_header\b"#).unwrap(),
        },
    });
    tracing::info!("rewrites off: {}", std::env::var("HXWEB_OFF").unwrap_or_default());
    app.pages.refresh().await;
    let watcher = app.clone();
    tokio::spawn(async move {
        loop {
            tokio::time::sleep(Duration::from_secs(5)).await;
            watcher.pages.refresh().await;
        }
    });
    let passthrough = app.clone();
    let finisher = app.clone();
    let legacy = Router::new().fallback(dispatch).with_state(app);
    let router = match lib {
        // A path claimed for some methods (GET on a page) still sends the others (a form POST) to the legacy stack.
        Some(lib) => lib
            .method_not_allowed_fallback(move |req: Request| dispatch(State(passthrough.clone()), req))
            .layer(axum::middleware::from_fn_with_state(finisher, finish_cms_pages))
            .fallback_service(legacy),
        None => Router::new().nest_service("/api", hxweb::api::unavailable()).fallback_service(legacy),
    };
    let router = tower::ServiceBuilder::new().map_request(hxweb::visitor::apache_paths).service(router);
    let listener = tokio::net::TcpListener::bind(&listen).await.expect("bind");
    tracing::info!("hxweb listening on {listen}");
    axum::serve(listener, axum::ServiceExt::<Request>::into_make_service_with_connect_info::<SocketAddr>(router)).await.expect("serve");
}

/// The /api routes need their database and settings. If either is missing the pages still serve and /api answers 503.
async fn open_auth() -> Option<Arc<hxweb::api::Auth>> {
    let config = hxweb::config::Config::from_env(|k| std::env::var(k).ok())
        .inspect_err(|e| tracing::error!("/api disabled: {e}"))
        .ok()?;
    let pool = hxweb::db::open(&config.db_path)
        .await
        .inspect_err(|e| tracing::error!("/api disabled: cannot open {}: {e}", config.db_path.display()))
        .ok()?;
    if config.secret.is_none() {
        tracing::warn!("HXWEB_SECRET is not set: emailed-code sign-in answers 503");
    }
    let accounts = hxweb::accounts::Services::from_env(|k| std::env::var(k).ok())
        .inspect_err(|e| tracing::error!("/api disabled: {e}"))
        .ok()?;
    hxweb::orders::mail::start_from_env(pool.clone());
    Some(Arc::new(hxweb::api::Auth {
        pool,
        secret: config.secret,
        public_origin: config.public_origin,
        trusted_proxies: config.trusted_proxies,
        accounts,
    }))
}

/// Pages the cms routes render (marked `x-hxweb: cms`) get the same finishing as the exported ones in pages.rs. A
/// GET they hand back (`x-hxweb: legacy`, a query WordPress acts on) goes where an unclaimed path would.
async fn finish_cms_pages(State(app): State<Arc<App>>, req: Request, next: axum::middleware::Next) -> Response<Body> {
    let again = (req.method() == Method::GET || req.method() == Method::HEAD).then(|| {
        (req.method().clone(), req.uri().clone(), req.headers().clone(), req.extensions().get::<ConnectInfo<SocketAddr>>().cloned())
    });
    let resp = next.run(req).await;
    if let Some((method, uri, headers, peer)) = again.filter(|_| resp.headers().get("x-hxweb").is_some_and(|v| v == "legacy")) {
        let mut req = Request::new(Body::empty());
        *req.method_mut() = method;
        *req.uri_mut() = uri;
        *req.headers_mut() = headers;
        if let Some(peer) = peer {
            req.extensions_mut().insert(peer);
        }
        return dispatch(State(app), req).await;
    }
    if resp.headers().get("x-hxweb").is_none_or(|v| v != "cms") {
        return resp;
    }
    let (mut parts, body) = resp.into_parts();
    let Ok(bytes) = axum::body::to_bytes(body, 16 << 20).await else {
        return Response::builder().status(StatusCode::BAD_GATEWAY).body(Body::from("Bad gateway")).unwrap();
    };
    parts.headers.remove(axum::http::header::CONTENT_LENGTH);
    Response::from_parts(parts, Body::from(pages::finish(&app, String::from_utf8_lossy(&bytes).into_owned())))
}

async fn dispatch(State(app): State<Arc<App>>, req: Request) -> Response<Body> {
    if let Some(gone) = hxweb::retired::guard(req.uri().path()) {
        return gone;
    }
    if req.method() == Method::GET || req.method() == Method::HEAD {
        let path = req.uri().path().to_string();
        if path == "/catalog/" || path == "/catalogue/" {
            return compose::handle(&app, req).await;
        }
        let query = req.uri().query().unwrap_or("").to_string();
        if let Some(resp) = app.pages.serve(&app, &path, &query).await {
            return resp;
        }
    }
    proxy::pass(&app, req).await
}

#[cfg(test)]
mod tests {
    #[test]
    fn bytes() {
        assert_eq!(super::replace_bytes(b"a //www.x/ b \xe9 //www.x", b"//www.x", b"//s2.x"), b"a //s2.x/ b \xe9 //s2.x".to_vec());
    }
}
