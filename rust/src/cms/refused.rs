//! Requests prod's nginx answers itself with its 404 page before hxweb sees them (its deny rules for WordPress's
//! endpoints): the form-island endpoint, which only hxweb calls (on Apache directly), and WordPress's user listing
//! in both its forms. hxweb answers them the same way, so they never reach WordPress whatever sits in front.

use axum::{
    body::Body,
    extract::Request,
    http::{header, HeaderValue, Response, StatusCode},
    middleware::Next,
};
use percent_encoding::percent_decode_str;

/// nginx's `if ($arg_hx_form)`: the first `hx_form` argument, its name in any case, with a value that is neither
/// empty nor `0`.
fn island(raw_query: &str) -> bool {
    raw_query
        .split('&')
        .find_map(|kv| kv.split_once('=').filter(|(k, _)| k.eq_ignore_ascii_case("hx_form")).map(|(_, v)| v))
        .is_some_and(|v| !v.is_empty() && v != "0")
}

/// nginx's `$args ~* "(^|&)rest_route=(/|%2f)*wp(/|%2f)+v2(/|%2f)+users"`, on the query as sent.
fn user_listing(raw_query: &str) -> bool {
    raw_query.split('&').any(|kv| {
        let lower = kv.to_ascii_lowercase();
        let Some(mut rest) = lower.strip_prefix("rest_route=") else { return false };
        let slashes = |s: &mut &str| {
            let mut n = 0;
            loop {
                if let Some(r) = s.strip_prefix('/').or_else(|| s.strip_prefix("%2f")) {
                    *s = r;
                    n += 1;
                } else {
                    return n;
                }
            }
        };
        slashes(&mut rest);
        let Some(r) = rest.strip_prefix("wp") else { return false };
        rest = r;
        if slashes(&mut rest) == 0 {
            return false;
        }
        let Some(r) = rest.strip_prefix("v2") else { return false };
        rest = r;
        slashes(&mut rest) > 0 && rest.starts_with("users")
    })
}

/// `location ^~ /wp-json/wp/v2/users`, on the path as nginx normalizes it: decoded, dot segments resolved and
/// slashes merged.
fn users_path(raw_path: &str) -> bool {
    let decoded = percent_decode_str(raw_path).decode_utf8_lossy();
    let mut segments: Vec<&str> = Vec::new();
    for s in decoded.split('/') {
        match s {
            "" | "." => {}
            ".." => {
                segments.pop();
            }
            s => segments.push(s),
        }
    }
    format!("/{}", segments.join("/")).starts_with("/wp-json/wp/v2/users")
}

/// Whether prod's nginx refuses the request.
pub fn refused(raw_path: &str, raw_query: &str) -> bool {
    island(raw_query) || user_listing(raw_query) || users_path(raw_path)
}

/// nginx's msie_padding: its error pages carry six comment lines for a browser that would otherwise show its own
/// page (Internet Explorer, and Chrome when the agent names no Gecko).
fn padded(agent: &str) -> bool {
    let msie = agent.find("MSIE ").is_some_and(|at| at + 7 < agent.len()) && !agent.contains("Opera");
    msie || (!agent.contains("Opera") && !agent.contains("Gecko/") && agent.contains("Chrome/"))
}

/// nginx's own 404 page.
pub fn not_found(agent: &str) -> Response<Body> {
    let mut body = String::from("<html>\r\n<head><title>404 Not Found</title></head>\r\n<body>\r\n<center><h1>404 Not Found</h1></center>\r\n<hr><center>nginx</center>\r\n</body>\r\n</html>\r\n");
    if padded(agent) {
        for _ in 0..6 {
            body.push_str("<!-- a padding to disable MSIE and Chrome friendly error page -->\r\n");
        }
    }
    let mut r = Response::new(Body::from(body));
    *r.status_mut() = StatusCode::NOT_FOUND;
    r.headers_mut().insert(header::CONTENT_TYPE, HeaderValue::from_static("text/html"));
    r
}

/// The layer main.rs puts in front of everything.
pub async fn layer(req: Request, next: Next) -> Response<Body> {
    if refused(req.uri().path(), req.uri().query().unwrap_or("")) {
        let agent = req.headers().get(header::USER_AGENT).and_then(|v| v.to_str().ok()).unwrap_or("");
        return not_found(agent);
    }
    next.run(req).await
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_island_endpoint_is_refused_as_nginx_reads_its_argument() {
        assert!(refused("/", "hx_form=1&hx_lang=en&hx_page=/"));
        assert!(refused("/catalog/", "a=b&HX_Form=2"));
        assert!(!refused("/", "hx_form=0"));
        assert!(!refused("/", "hx_form="));
        assert!(!refused("/", "hx_form"));
        assert!(!refused("/", "xhx_form=1"));
        assert!(!refused("/", "hx_form=&hx_form=1"), "nginx reads the first one");
    }

    #[test]
    fn the_user_listing_is_refused_in_both_forms() {
        for q in ["rest_route=/wp/v2/users", "per_page=5&rest_route=%2Fwp%2Fv2%2Fusers%2F1", "rest_route=//WP//v2/Users&x=1", "rest_route=wp/v2/usersx"] {
            assert!(refused("/", q), "{q}");
        }
        for q in ["rest_route=/wp/v2/pages", "rest_route=/wp/v2users", "xrest_route=/wp/v2/users", "rest_route=/wpv2/users"] {
            assert!(!refused("/", q), "{q}");
        }
        assert!(refused("/wp-json/wp/v2/users", ""));
        assert!(refused("/wp-json//wp/./v2/users/1", ""));
        assert!(refused("/wp-json/wp/v2/%75sers", ""));
        assert!(!refused("/wp-json/wp/v2/posts", ""));
        assert!(!refused("/WP-JSON/wp/v2/users", ""), "the location is case-sensitive");
    }

    #[test]
    fn browsers_get_the_padded_page() {
        assert!(padded("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36"));
        assert!(padded("Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.1)"));
        assert!(!padded("Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0"));
        assert!(!padded("curl/8.5.0"));
        assert!(!padded(""));
    }
}
