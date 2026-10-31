//! hxweb: www.harcourt.co. The library's routes answer every page (routes/), the catalog pages are composed in one
//! response (compose.rs), a page the cms does not render yet comes from the static export (pages.rs), and the
//! static files come from one asset directory (assets.rs). The old PHP and WordPress addresses that nothing
//! answers get 410 (retired.rs).

mod compose;
mod pages;
mod tabs;
mod vendor;

use std::{net::SocketAddr, path::PathBuf, sync::Arc, time::Duration};

use axum::{
    body::Body,
    extract::{ConnectInfo, Request, State},
    http::{header, Method, Response, StatusCode},
    Router,
};
use regex::Regex;
use tower_http::compression::{
    predicate::{Predicate, SizeAbove},
    CompressionBody, CompressionLayer, CompressionLevel,
};

pub struct App {
    /// The library's routes, called in-process by the composer (the catalog fragment and the loader JSON).
    lib: Option<Router>,
    /// The catalog pages' shell from the cms tables.
    cms: Option<compose::Cms>,
    site: String,
    /// The host visitors use. Differs from `site` on a staging copy (stage2.harcourt.co), where links are rewritten.
    public_host: String,
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
    use tracing_subscriber::{filter::Targets, layer::SubscriberExt, util::SubscriberInitExt};
    // RUST_LOG as `info,sqlx::query=debug` logs every SQL statement (`hx-diff bench --sql-log` counts them).
    let filter = std::env::var("RUST_LOG").ok().and_then(|v| v.parse::<Targets>().ok()).unwrap_or_else(|| Targets::new().with_default(tracing::Level::INFO));
    tracing_subscriber::registry().with(tracing_subscriber::fmt::layer().with_target(false)).with(filter).init();
    let args: Vec<String> = std::env::args().skip(1).collect();
    if let Some(code) = hxweb::staff::cli::run(&args).await {
        std::process::exit(code);
    }
    let env = |k: &str, d: &str| std::env::var(k).unwrap_or_else(|_| d.to_string());
    let listen = env("HXWEB_LISTEN", "127.0.0.1:8090");
    // Every page needs the database and the core settings; without them there is nothing to serve.
    let Some(core) = open_auth().await else { std::process::exit(1) };
    let lib = hxweb::routes::all(core.clone());
    let app = Arc::new(App {
        lib: Some(lib.clone()),
        cms: Some(compose::Cms::new(core)),
        site: env("HXWEB_SITE", "www.harcourt.co"),
        public_host: env("HXWEB_PUBLIC_HOST", &env("HXWEB_SITE", "www.harcourt.co")),
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
    let unclaimed = Router::new().fallback(dispatch).with_state(app.clone());
    let router = lib.layer(axum::middleware::from_fn_with_state(app, finish_cms_pages)).fallback_service(unclaimed);
    // Text is gzipped for browsers that ask; images, fonts, video and PDFs are compressed already.
    let text = |_: StatusCode, _: axum::http::Version, h: &axum::http::HeaderMap, _: &axum::http::Extensions| {
        h.get(header::CONTENT_TYPE).and_then(|v| v.to_str().ok()).is_some_and(|t| t.starts_with("text/") || ["javascript", "json", "xml"].iter().any(|k| t.contains(k)))
    };
    let gzip = CompressionLayer::new().quality(CompressionLevel::Fastest).compress_when(SizeAbove::new(1024).and(text));
    let router = tower::ServiceBuilder::new()
        .map_response(|r: Response<CompressionBody<Body>>| r.map(Body::new))
        .layer(gzip)
        .map_request(hxweb::visitor::apache_paths)
        .service(router);
    let listener = tokio::net::TcpListener::bind(&listen).await.expect("bind");
    tracing::info!("hxweb listening on {listen}");
    axum::serve(listener, axum::ServiceExt::<Request>::into_make_service_with_connect_info::<SocketAddr>(router)).await.expect("serve");
}

/// The database and the settings every route needs. None, with the reason logged, when either is missing or
/// malformed.
async fn open_auth() -> Option<Arc<hxweb::api::Auth>> {
    let config = hxweb::config::Config::from_env(|k| std::env::var(k).ok())
        .inspect_err(|e| tracing::error!("not starting: {e}"))
        .ok()?;
    let pool = hxweb::db::open(&config.db_path)
        .await
        .inspect_err(|e| tracing::error!("not starting: cannot open {}: {e}", config.db_path.display()))
        .ok()?;
    if config.secret.is_none() {
        tracing::warn!("HXWEB_SECRET is not set: sign-in sends no code and form posts are not checked for the anti-spam token");
    }
    let accounts = hxweb::accounts::Services::from_env(|k| std::env::var(k).ok())
        .inspect_err(|e| tracing::error!("not starting: {e}"))
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
/// GET a route hands back goes to `dispatch`: the catalog page (`x-hxweb: compose`) to the composer, a page the cms
/// does not render (`export`) to the static export, a shape the route does not answer (`legacy`) to what an
/// unclaimed path gets.
async fn finish_cms_pages(State(app): State<Arc<App>>, req: Request, next: axum::middleware::Next) -> Response<Body> {
    let again = (req.method() == Method::GET || req.method() == Method::HEAD).then(|| {
        (req.method().clone(), req.uri().clone(), req.headers().clone(), req.extensions().get::<ConnectInfo<SocketAddr>>().cloned())
    });
    let resp = next.run(req).await;
    let handed_back = resp.headers().get("x-hxweb").is_some_and(|v| v == "compose" || v == "export" || v == "legacy");
    if let Some((method, uri, headers, peer)) = again.filter(|_| handed_back) {
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

/// What no route answers: the composed catalog page, a page from the static export, the old stack's 410, or 404.
async fn dispatch(State(app): State<Arc<App>>, req: Request) -> Response<Body> {
    let path = req.uri().path();
    let query = req.uri().query().unwrap_or("");
    if req.method() == Method::GET || req.method() == Method::HEAD {
        if hxweb::catalog::composed_page(path, query).is_some() {
            return compose::handle(&app, req).await;
        }
        if let Some(page) = app.pages.serve(&app, path, query).await {
            return page;
        }
    }
    if let Some(gone) = hxweb::retired::guard(path) {
        return gone;
    }
    Response::builder().status(StatusCode::NOT_FOUND).body(Body::from("Not found")).unwrap()
}
