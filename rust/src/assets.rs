//! The site's static files (styles, scripts, fonts, images, PDFs, videos), served by their URL path from one
//! directory, `HXWEB_DOCROOT` (by default `/home/harcou6/public_html`): the repository's `public/` tree with the
//! uploads beside it.

use std::{
    io::SeekFrom,
    path::{Path, PathBuf},
    time::{Duration, UNIX_EPOCH},
};

use axum::{
    body::Body,
    http::{header, HeaderMap, HeaderValue, Method, Response, StatusCode},
    response::IntoResponse,
};
use tokio::io::{AsyncReadExt, AsyncSeekExt};
use tokio_util::io::ReaderStream;

/// The extensions served, with the content types prod sent for them.
const TYPES: [(&str, &str); 19] = [
    ("xsl", "application/xslt+xml"),
    ("css", "text/css"),
    ("js", "application/javascript"),
    ("gif", "image/gif"),
    ("png", "image/png"),
    ("jpg", "image/jpeg"),
    ("jpeg", "image/jpeg"),
    ("webp", "image/webp"),
    ("svg", "image/svg+xml"),
    ("svgz", "image/svg+xml"),
    ("ico", "image/x-icon"),
    ("woff", "application/font-woff"),
    ("woff2", "application/octet-stream"),
    ("ttf", "application/octet-stream"),
    ("eot", "application/vnd.ms-fontobject"),
    ("otf", "application/octet-stream"),
    ("mp4", "video/mp4"),
    ("webm", "video/webm"),
    ("pdf", "application/pdf"),
];

const DEFAULT_ROOT: &str = "/home/harcou6/public_html";

/// `HXWEB_DOCROOT` when it is set.
pub fn configured() -> Option<&'static Path> {
    static ROOT: std::sync::OnceLock<Option<PathBuf>> = std::sync::OnceLock::new();
    ROOT.get_or_init(|| std::env::var_os("HXWEB_DOCROOT").map(PathBuf::from)).as_deref()
}

/// The asset directory everything reads its files from: `HXWEB_DOCROOT`, else the documented default.
pub fn root() -> &'static Path {
    configured().unwrap_or(Path::new(DEFAULT_ROOT))
}

/// The file for a decoded request path and its content type: a file of a served type under `root`, named by a path
/// with no hidden or parent segment and no trailing slash.
pub fn find(root: &Path, path: &str) -> Option<(PathBuf, &'static str)> {
    if path.ends_with('/') {
        return None;
    }
    let segments: Vec<&str> = path.split('/').filter(|s| !s.is_empty()).collect();
    if segments.iter().any(|s| s.starts_with('.')) {
        return None;
    }
    let ext = segments.last()?.rsplit_once('.')?.1.to_ascii_lowercase();
    let kind = TYPES.iter().find(|(e, _)| *e == ext)?.1;
    let file = root.join(segments.join("/"));
    file.is_file().then_some((file, kind))
}

/// The bytes of a file a request asks for.
#[derive(Debug, PartialEq, Eq)]
enum Span {
    Whole,
    /// First and last byte, inclusive.
    Part(u64, u64),
    Unsatisfiable,
}

fn digits(s: &str) -> Option<u64> {
    (!s.is_empty() && s.bytes().all(|b| b.is_ascii_digit())).then(|| s.parse().ok()).flatten()
}

/// One byte range of a file of `len` bytes. A header this cannot read, or one asking for several ranges, is ignored
/// and the whole file sent, as RFC 9110 allows.
fn parse_range(asked: &str, len: u64) -> Span {
    let Some((unit, spec)) = asked.split_once('=') else { return Span::Whole };
    if !unit.trim().eq_ignore_ascii_case("bytes") || spec.contains(',') {
        return Span::Whole;
    }
    let Some((first, last)) = spec.trim().split_once('-') else { return Span::Whole };
    match (first.trim(), last.trim()) {
        ("", suffix) => match digits(suffix) {
            None => Span::Whole,
            Some(0) => Span::Unsatisfiable,
            Some(_) if len == 0 => Span::Unsatisfiable,
            Some(n) => Span::Part(len.saturating_sub(n), len - 1),
        },
        (first, last) => {
            let Some(first) = digits(first) else { return Span::Whole };
            let last = if last.is_empty() { Some(u64::MAX) } else { digits(last) };
            match last {
                Some(last) if last >= first => {
                    if first >= len {
                        Span::Unsatisfiable
                    } else {
                        Span::Part(first, last.min(len - 1))
                    }
                }
                _ => Span::Whole,
            }
        }
    }
}

fn header_str(headers: &HeaderMap, name: header::HeaderName) -> Option<&str> {
    headers.get(name).and_then(|v| v.to_str().ok())
}

/// The file, streamed: the whole of it, or the one byte range asked for unless `If-Range` is not its ETag. Its
/// ETag is its size and modification time; a browser that already holds that version (by `If-None-Match`, or by
/// `If-Modified-Since` when it sent no ETag) gets 304. HEAD gets the headers alone.
pub async fn serve(file: PathBuf, kind: &'static str, method: &Method, headers: &HeaderMap) -> Response<Body> {
    let not_found = || (StatusCode::NOT_FOUND, "Not found").into_response();
    let Ok(mut opened) = tokio::fs::File::open(&file).await else { return not_found() };
    let Ok(meta) = opened.metadata().await else { return not_found() };
    let len = meta.len();
    let age = meta.modified().ok().and_then(|m| m.duration_since(UNIX_EPOCH).ok()).unwrap_or_default();
    let tag = format!("\"{len:x}-{:x}\"", age.as_nanos());
    let modified = UNIX_EPOCH + Duration::from_secs(age.as_secs());
    let last_modified = httpdate::fmt_http_date(modified);
    let validators = [(header::CONTENT_TYPE, kind), (header::CACHE_CONTROL, "public, max-age=3600"), (header::ETAG, tag.as_str()), (header::LAST_MODIFIED, last_modified.as_str())];

    let held = match header_str(headers, header::IF_NONE_MATCH) {
        Some(held) => held.split(',').any(|t| t.trim().trim_start_matches("W/") == tag),
        None => header_str(headers, header::IF_MODIFIED_SINCE).and_then(|d| httpdate::parse_http_date(d).ok()).is_some_and(|since| modified <= since),
    };
    if held {
        return (StatusCode::NOT_MODIFIED, validators).into_response();
    }
    let span = match header_str(headers, header::RANGE) {
        Some(asked) if header_str(headers, header::IF_RANGE).is_none_or(|v| v == tag) => parse_range(asked, len),
        _ => Span::Whole,
    };
    let (status, first, count, content_range) = match span {
        Span::Whole => (StatusCode::OK, 0, len, None),
        Span::Part(first, last) => (StatusCode::PARTIAL_CONTENT, first, last - first + 1, Some(format!("bytes {first}-{last}/{len}"))),
        Span::Unsatisfiable => {
            let range = format!("bytes */{len}");
            return (StatusCode::RANGE_NOT_SATISFIABLE, validators, [(header::CONTENT_RANGE, range.as_str()), (header::ACCEPT_RANGES, "bytes")]).into_response();
        }
    };
    let body = if *method == Method::HEAD {
        Body::empty()
    } else {
        if first > 0 && opened.seek(SeekFrom::Start(first)).await.is_err() {
            return not_found();
        }
        Body::from_stream(ReaderStream::with_capacity(opened.take(count), 64 * 1024))
    };
    let mut response = (status, validators, [(header::ACCEPT_RANGES, "bytes")], body).into_response();
    let set = response.headers_mut();
    set.insert(header::CONTENT_LENGTH, HeaderValue::from(count));
    if let Some(range) = content_range.and_then(|r| HeaderValue::from_str(&r).ok()) {
        set.insert(header::CONTENT_RANGE, range);
    }
    response
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn files_of_served_types_by_their_path() {
        let root = std::env::temp_dir().join(format!("hx-assets-{}", std::process::id()));
        std::fs::create_dir_all(root.join("wp-content/themes/t")).unwrap();
        std::fs::write(root.join("wp-content/themes/t/a.css"), "x").unwrap();
        std::fs::write(root.join("wp-content/themes/t/A.PNG"), "x").unwrap();
        std::fs::write(root.join("notes.md"), "x").unwrap();
        std::fs::write(root.join(".env.css"), "x").unwrap();
        assert_eq!(find(&root, "/wp-content/themes/t/a.css"), Some((root.join("wp-content/themes/t/a.css"), "text/css")));
        assert_eq!(find(&root, "//wp-content//themes/t/a.css"), Some((root.join("wp-content/themes/t/a.css"), "text/css")));
        assert_eq!(find(&root, "/wp-content/themes/t/A.PNG").map(|f| f.1), Some("image/png"));
        for p in ["/wp-content/themes/t/a.css/", "/wp-content/themes/t/b.css", "/notes.md", "/.env.css", "/wp-content/themes/x/../t/a.css", "/wp-content/themes/t", "/"] {
            assert_eq!(find(&root, p), None, "{p}");
        }
        std::fs::remove_dir_all(&root).unwrap();
    }

    const DIGITS: &str = "0123456789";

    fn sample(name: &str) -> PathBuf {
        let file = std::env::temp_dir().join(format!("hx-{name}-{}.mp4", std::process::id()));
        std::fs::write(&file, DIGITS).unwrap();
        file
    }

    async fn ask(file: &Path, method: Method, sent: &[(header::HeaderName, &str)]) -> (StatusCode, HeaderMap, String) {
        let mut headers = HeaderMap::new();
        for (k, v) in sent {
            headers.insert(k, HeaderValue::from_str(v).unwrap());
        }
        let resp = serve(file.to_path_buf(), "video/mp4", &method, &headers).await;
        let (parts, body) = resp.into_parts();
        let bytes = http_body_util::BodyExt::collect(body).await.unwrap().to_bytes();
        (parts.status, parts.headers, String::from_utf8(bytes.to_vec()).unwrap())
    }

    #[tokio::test]
    async fn a_get_streams_the_whole_file_with_its_validators() {
        let file = sample("whole");
        let (status, h, body) = ask(&file, Method::GET, &[]).await;
        assert_eq!((status, body.as_str()), (StatusCode::OK, DIGITS));
        assert_eq!(h[header::CONTENT_TYPE], "video/mp4");
        assert_eq!(h[header::CONTENT_LENGTH], "10");
        assert_eq!(h[header::ACCEPT_RANGES], "bytes");
        assert!(h[header::ETAG].to_str().unwrap().starts_with("\"a-"));
        assert!(httpdate::parse_http_date(h[header::LAST_MODIFIED].to_str().unwrap()).is_ok());
        std::fs::remove_file(&file).unwrap();
    }

    #[tokio::test]
    async fn a_head_gets_the_headers_without_the_body() {
        let file = sample("head");
        let (status, h, body) = ask(&file, Method::HEAD, &[]).await;
        assert_eq!((status, body.as_str()), (StatusCode::OK, ""));
        assert_eq!(h[header::CONTENT_LENGTH], "10");
        assert_eq!(h[header::ACCEPT_RANGES], "bytes");
        std::fs::remove_file(&file).unwrap();
    }

    #[tokio::test]
    async fn a_range_gets_those_bytes() {
        let file = sample("range");
        for (asked, part, content_range) in [
            ("bytes=2-5", "2345", "bytes 2-5/10"),
            ("bytes=7-", "789", "bytes 7-9/10"),
            ("bytes=-3", "789", "bytes 7-9/10"),
            ("bytes=8-400", "89", "bytes 8-9/10"),
            ("bytes=-40", DIGITS, "bytes 0-9/10"),
        ] {
            let (status, h, body) = ask(&file, Method::GET, &[(header::RANGE, asked)]).await;
            assert_eq!((status, body.as_str()), (StatusCode::PARTIAL_CONTENT, part), "{asked}");
            assert_eq!(h[header::CONTENT_RANGE], content_range, "{asked}");
            assert_eq!(h[header::CONTENT_LENGTH], part.len().to_string().as_str(), "{asked}");
        }
        for asked in ["bytes=10-", "bytes=10-12", "bytes=-0"] {
            let (status, h, body) = ask(&file, Method::GET, &[(header::RANGE, asked)]).await;
            assert_eq!((status, body.as_str()), (StatusCode::RANGE_NOT_SATISFIABLE, ""), "{asked}");
            assert_eq!(h[header::CONTENT_RANGE], "bytes */10", "{asked}");
        }
        for asked in ["bytes=0-1,4-5", "bytes=5-2", "items=0-1", "bytes=x-"] {
            let (status, _, body) = ask(&file, Method::GET, &[(header::RANGE, asked)]).await;
            assert_eq!((status, body.as_str()), (StatusCode::OK, DIGITS), "{asked}");
        }
        std::fs::remove_file(&file).unwrap();
    }

    #[tokio::test]
    async fn if_range_keeps_the_range_only_for_the_current_etag() {
        let file = sample("if-range");
        let (_, h, _) = ask(&file, Method::GET, &[]).await;
        let (tag, date) = (h[header::ETAG].to_str().unwrap(), h[header::LAST_MODIFIED].to_str().unwrap());
        let (status, _, body) = ask(&file, Method::GET, &[(header::RANGE, "bytes=0-1"), (header::IF_RANGE, tag)]).await;
        assert_eq!((status, body.as_str()), (StatusCode::PARTIAL_CONTENT, "01"));
        let weak = format!("W/{tag}");
        for other in ["\"a-0\"", "Thu, 01 Jan 1970 00:00:00 GMT", date, weak.as_str()] {
            let (status, _, body) = ask(&file, Method::GET, &[(header::RANGE, "bytes=0-1"), (header::IF_RANGE, other)]).await;
            assert_eq!((status, body.as_str()), (StatusCode::OK, DIGITS));
        }
        std::fs::remove_file(&file).unwrap();
    }

    #[tokio::test]
    async fn a_browser_holding_the_current_version_gets_304() {
        let file = sample("etag");
        let (_, h, _) = ask(&file, Method::GET, &[]).await;
        let (tag, date) = (h[header::ETAG].to_str().unwrap(), h[header::LAST_MODIFIED].to_str().unwrap());
        let (status, held, body) = ask(&file, Method::GET, &[(header::IF_NONE_MATCH, tag)]).await;
        assert_eq!((status, body.as_str()), (StatusCode::NOT_MODIFIED, ""));
        assert_eq!((&held[header::ETAG], &held[header::LAST_MODIFIED]), (&h[header::ETAG], &h[header::LAST_MODIFIED]));
        assert_eq!(ask(&file, Method::GET, &[(header::IF_NONE_MATCH, "\"0-0\", W/\"1-1\"")]).await.0, StatusCode::OK);
        assert_eq!(ask(&file, Method::GET, &[(header::IF_MODIFIED_SINCE, date)]).await.0, StatusCode::NOT_MODIFIED);
        assert_eq!(ask(&file, Method::GET, &[(header::IF_MODIFIED_SINCE, "Thu, 01 Jan 1970 00:00:00 GMT")]).await.0, StatusCode::OK);
        assert_eq!(ask(&file, Method::GET, &[(header::IF_NONE_MATCH, "\"0-0\""), (header::IF_MODIFIED_SINCE, date)]).await.0, StatusCode::OK, "the ETag decides when both are sent");
        std::fs::remove_file(&file).unwrap();
    }
}
