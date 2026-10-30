//! The old PHP and WordPress stack's addresses, answered 410 by one rule. The rule runs only for a request no route
//! claims, so the PHP addresses the program kept (catalog.php, scs_file.php and the rest) are routes and never get
//! here; every other script, WordPress's own files and endpoints and xmlrpc are gone.
//!
//! The path is read as the old front read it: decoded, `.` and `..` resolved, any segment matching.

use axum::{
    body::Body,
    http::{header, Response, StatusCode},
};
use percent_encoding::percent_decode_str;

/// A segment names a PHP script (`*.php`), a WordPress file or endpoint (`wp-*`) or xmlrpc.
pub fn is_retired(raw_path: &str) -> bool {
    let decoded = percent_decode_str(raw_path).decode_utf8_lossy().to_ascii_lowercase();
    let mut segments: Vec<&str> = Vec::new();
    for segment in decoded.split('/') {
        match segment {
            "" | "." => {}
            ".." => {
                segments.pop();
            }
            s => segments.push(s),
        }
    }
    segments.iter().any(|s| s.ends_with(".php") || s.starts_with("wp-") || s.contains("xmlrpc"))
}

/// The answer for an unclaimed request path, or None when it is not the old stack's.
pub fn guard(raw_path: &str) -> Option<Response<Body>> {
    is_retired(raw_path).then(gone)
}

pub fn gone() -> Response<Body> {
    Response::builder()
        .status(StatusCode::GONE)
        .header(header::CONTENT_TYPE, "text/plain; charset=utf-8")
        .header(header::CACHE_CONTROL, "no-store")
        .body(Body::from("Gone\n"))
        .expect("static response")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn scripts_wordpress_and_xmlrpc_are_the_old_stack() {
        for p in [
            "/scs_remote.php",
            "//scs_remote.php",
            "/./scs_remote.php",
            "/x/../scs_remote.php",
            "/scs_remote.php/extra/path",
            "/%73cs_remote.php",
            "/SCS_REMOTE.PHP",
            "/includes/mysql_rev1.php",
            "/wp-login.php",
            "/wp-admin/",
            "/wp-admin/admin-ajax.php",
            "/wp-json/wp/v2/pages/1",
            "/WP-JSON",
            "/wp-content/plugins/x/readme.txt",
            "/en/wp-includes/js/x.js",
            "/xmlrpc.php",
            "/xmlrpc",
        ] {
            assert!(is_retired(p), "{p}");
        }
    }

    #[test]
    fn leaves_other_paths_alone() {
        for p in ["/", "/catalog/", "/scs_remote", "/scs_remote.phps", "/scs_remote.php/../catalog/", "/api/auth/code", "/company/wp/", "/feed/"] {
            assert!(!is_retired(p), "{p}");
        }
    }

    #[test]
    fn gone_is_410_with_no_cookie_and_no_cache() {
        let r = guard("/scs_remote.php").expect("retired");
        assert_eq!(r.status(), StatusCode::GONE);
        assert!(r.headers().get(header::SET_COOKIE).is_none());
        assert_eq!(r.headers().get(header::CACHE_CONTROL).unwrap(), "no-store");
        assert!(guard("/catalog/").is_none());
    }
}
