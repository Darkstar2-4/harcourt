//! One module per area of the site, each owned by one agent. A route that no module claims falls through to the
//! legacy Apache/PHP stack (proxy.rs). Porting a PHP page means claiming its path here and, once the page has
//! no traffic for 14 days, deleting the PHP file. When the last file is gone, so is the proxy.

use std::sync::Arc;

use axum::{
    extract::{Request, State},
    http::{header, HeaderMap, Method, StatusCode},
    middleware::{self, Next},
    response::{IntoResponse, Response},
    Router,
};

use crate::{
    api::{self, Auth},
    visitor,
};

pub mod accounts;
pub mod catalog;
pub mod orders;
pub mod staff;
pub mod wordpress;

pub fn all(core: Arc<Auth>) -> Router {
    let wordpress = wordpress::routers(core.clone());
    let pages = Router::new()
        .merge(accounts::router(core.clone()))
        .merge(catalog::router(core.clone()))
        .merge(orders::router(core.clone()))
        .merge(staff::router(core.clone()))
        .merge(wordpress.pages)
        .layer(middleware::from_fn_with_state(core.clone(), visitor::layer));
    Router::new()
        .nest_service("/api", api::router(core.clone()))
        .merge(pages)
        .merge(wordpress.front)
        .layer(middleware::from_fn_with_state(core, same_origin))
}

/// Paths that admit a caller by a token in the request alone, never by a cookie or the client's address. A
/// server calling one sends no Origin and need not send a Referer.
const TOKEN_ONLY: [&str; 1] = ["/api/remote/redeem"];

/// Every request other than a GET or HEAD must come from a page of this site. Its Origin, when sent, must be the
/// public origin. Without one, its Referer must be a page of the public origin. A visitor can be signed in by a
/// cookie or by the company address they connect from, so this holds for every route, not only those behind a
/// session.
pub async fn same_origin(State(core): State<Arc<Auth>>, req: Request, next: Next) -> Response {
    let reads = matches!(*req.method(), Method::GET | Method::HEAD);
    if reads || from_this_site(req.headers(), req.uri().path(), &core.public_origin) {
        next.run(req).await
    } else {
        (StatusCode::FORBIDDEN, "Forbidden").into_response()
    }
}

fn from_this_site(headers: &HeaderMap, path: &str, origin: &str) -> bool {
    match headers.get(header::ORIGIN) {
        Some(sent) => sent.as_bytes() == origin.as_bytes(),
        None => TOKEN_ONLY.contains(&path) || headers.get(header::REFERER).and_then(|r| r.to_str().ok()).is_some_and(|r| on_origin(r, origin)),
    }
}

/// A path on this site and nothing else a browser could read as another host: its path part (before any `?` or `#`)
/// starts with one slash and holds no backslash (read as a slash there), and nothing in it is a control character
/// (tabs and newlines are dropped from URLs).
pub fn local_path(url: &str) -> bool {
    let path = url.split(['?', '#']).next().unwrap_or_default();
    path.starts_with('/') && !path.starts_with("//") && !path.contains('\\') && !url.chars().any(char::is_control)
}

/// A URL on `origin` itself, not on a host that merely starts with the same text.
fn on_origin(url: &str, origin: &str) -> bool {
    url.strip_prefix(origin).is_some_and(|rest| rest.is_empty() || rest.starts_with(['/', '?', '#']))
}

#[cfg(test)]
mod tests {
    use super::*;

    const SITE: &str = "https://www.harcourt.co";

    fn headers(pairs: &[(header::HeaderName, &str)]) -> HeaderMap {
        pairs.iter().map(|(k, v)| (k.clone(), v.parse().unwrap())).collect()
    }

    #[test]
    fn a_page_of_this_site_may_post() {
        assert!(from_this_site(&headers(&[(header::ORIGIN, SITE)]), "/cart.php", SITE));
        assert!(from_this_site(&headers(&[(header::REFERER, "https://www.harcourt.co/cart.php?x=1")]), "/cart.php", SITE));
        assert!(from_this_site(&headers(&[(header::REFERER, SITE)]), "/cart.php", SITE));
        assert!(from_this_site(&headers(&[]), "/api/remote/redeem", SITE));
    }

    #[test]
    fn a_local_path_may_carry_any_query() {
        assert!(local_path("/page/?lang=en&x=a\\b"));
        assert!(local_path("/page/?next=//x#a\\b"));
        assert!(local_path("/cart.php"));
        assert!(!local_path("page/"));
        assert!(!local_path("?x=/a"));
    }
}
