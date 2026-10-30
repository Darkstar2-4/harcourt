//! PHP files that must never run again, answered here before anything is proxied to Apache. Deleting the file is
//! the real fix; this keeps the front closed on a docroot that still has it (a stale deploy, a restored backup).
//!
//! nginx hands hxweb the request target as the client wrote it, and Apache then decodes it, drops `.` and `..`
//! segments and treats `/file.php/anything` as a call to `file.php`. So the check works on the decoded path,
//! resolved the same way, and matches any segment.

use axum::{
    body::Body,
    http::{header, Response, StatusCode},
};
use percent_encoding::percent_decode_str;

/// scs_remote.php: `action=access` set the session user from an unsigned code with no sign-in. Replaced by
/// remote_grant.rs (POST /api/remote/grants, POST /api/remote/redeem).
/// beta_authorize.php: a diagnostic script with no traffic and no replacement.
const RETIRED: &[&str] = &["scs_remote.php", "beta_authorize.php"];

pub fn is_retired(raw_path: &str) -> bool {
    let decoded = percent_decode_str(raw_path).decode_utf8_lossy();
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
    segments.iter().any(|s| RETIRED.iter().any(|r| s.eq_ignore_ascii_case(r)))
}

/// The answer for a request path, or None when the request may go on. This is the whole of main.rs's check.
pub fn guard(raw_path: &str) -> Option<Response<Body>> {
    if !is_retired(raw_path) {
        return None;
    }
    tracing::warn!(path = %raw_path.escape_debug(), "request for a retired endpoint");
    Some(gone())
}

fn gone() -> Response<Body> {
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
    fn matches_every_way_apache_would_reach_the_file() {
        for p in [
            "/scs_remote.php",
            "//scs_remote.php",
            "/./scs_remote.php",
            "/x/../scs_remote.php",
            "/scs_remote.php/",
            "/scs_remote.php/extra/path",
            "/%73cs_remote.php",
            "/scs%5Fremote.php",
            "/SCS_REMOTE.PHP",
            "/a/%2e%2e/scs_remote.php",
        ] {
            assert!(is_retired(p), "{p}");
        }
        assert!(is_retired("/beta_authorize.php"));
    }

    #[test]
    fn leaves_other_paths_alone() {
        for p in ["/", "/catalog/", "/scs_remote", "/scs_remote.phps", "/scs_remote.php/../catalog.php", "/xscs_remote.php", "/api/auth/code", "/beta.php"] {
            assert!(!is_retired(p), "{p}");
        }
    }

    #[test]
    fn guard_answers_410_with_no_cookie_and_no_cache() {
        let r = guard("/scs_remote.php").expect("retired");
        assert_eq!(r.status(), StatusCode::GONE);
        assert!(r.headers().get(header::SET_COOKIE).is_none());
        assert_eq!(r.headers().get(header::CACHE_CONTROL).unwrap(), "no-store");
    }
}
