//! Docroot directories Apache answered for itself: no directory listing (403) and mod_dir's 301 for a directory
//! asked for without its slash. Two of them hold old static pages and campaign assets, welcome-pages/ and campaign/
//! (whose slide assets shadow the WordPress page at /campaign/), and Apache ran no PHP there (403). Their other
//! files are static assets, served with the rest of the docroot.

use std::sync::Arc;

use axum::{
    extract::State,
    http::{header, HeaderValue, StatusCode, Uri},
    response::{IntoResponse, Response},
    routing::any,
    Router,
};

use crate::api::Auth;

const DIRS: &[&str] = &[
    "/campaign",
    "/campaign/slide",
    "/campaign/slide/css",
    "/campaign/slide/images",
    "/campaign/slide/scripts",
    "/campaign/slide/scripts/slicknav",
    "/campaign/slide/scripts/slicknav/css",
    "/campaign/slide/scripts/slicknav/js",
    "/oauth",
    "/welcome-pages",
];

const SCRIPTS: &[&str] = &["/welcome-pages/xwelcome-usual.php", "/welcome-pages/xwelcomel.php"];

/// The path is in the trees where Apache ran no PHP.
pub fn contains(path: &str) -> bool {
    ["/campaign", "/welcome-pages"].iter().any(|d| path.strip_prefix(d).is_some_and(|rest| rest.is_empty() || rest.starts_with('/')))
}

pub fn routes() -> Router<Arc<Auth>> {
    let mut r = Router::new();
    for dir in DIRS {
        r = r.route(dir, any(add_slash)).route(&format!("{dir}/"), any(forbidden));
    }
    for script in SCRIPTS {
        r = r.route(script, any(forbidden));
    }
    r
}

/// The host and port Apache named in its error pages.
fn server(origin: &str) -> String {
    format!("Apache/2.4.52 (Ubuntu) Server at {} Port 443", origin.trim_start_matches("https://"))
}

fn apache_page(status: StatusCode, location: Option<HeaderValue>, title: &str, body: &str, origin: &str) -> Response {
    let html = format!(
        "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\">\n<html><head>\n<title>{} {title}</title>\n</head><body>\n<h1>{title}</h1>\n<p>{body}</p>\n<hr>\n<address>{}</address>\n</body></html>\n",
        status.as_u16(),
        server(origin)
    );
    let mut r = (status, [(header::CONTENT_TYPE, "text/html; charset=iso-8859-1")], html).into_response();
    if let Some(l) = location {
        r.headers_mut().insert(header::LOCATION, l);
    }
    r
}

/// Apache's 404, for a path it found no script or file for.
pub fn not_found(origin: &str) -> Response {
    apache_page(StatusCode::NOT_FOUND, None, "Not Found", "The requested URL was not found on this server.", origin)
}

async fn forbidden(State(core): State<Arc<Auth>>) -> Response {
    apache_page(StatusCode::FORBIDDEN, None, "Forbidden", "You don't have permission to access this resource.", &core.public_origin)
}

/// mod_dir: the directory with its slash, the query kept.
async fn add_slash(State(core): State<Arc<Auth>>, uri: Uri) -> Response {
    let to = match uri.query() {
        Some(q) => format!("{}{}/?{q}", core.public_origin, uri.path()),
        None => format!("{}{}/", core.public_origin, uri.path()),
    };
    let Ok(location) = HeaderValue::from_str(&to) else { return StatusCode::BAD_REQUEST.into_response() };
    let body = format!("The document has moved <a href=\"{}\">here</a>.", escape(&to));
    apache_page(StatusCode::MOVED_PERMANENTLY, Some(location), "Moved Permanently", &body, &core.public_origin)
}

/// ap_escape_html().
fn escape(s: &str) -> String {
    s.replace('&', "&amp;").replace('<', "&lt;").replace('>', "&gt;").replace('"', "&quot;")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_the_two_trees_are_apaches() {
        for p in ["/campaign", "/campaign/", "/campaign/slide/css/index.css", "/welcome-pages/xwelcomel.php"] {
            assert!(contains(p), "{p}");
        }
        for p in ["/campaign.php", "/campaigns/", "/campagne/", "/welcome-pages.html", "/"] {
            assert!(!contains(p), "{p}");
        }
    }

    #[test]
    fn mod_dir_escapes_the_link_it_prints() {
        assert_eq!(escape("https://h/campaign/?x=1&y=\"2\""), "https://h/campaign/?x=1&amp;y=&quot;2&quot;");
    }
}
