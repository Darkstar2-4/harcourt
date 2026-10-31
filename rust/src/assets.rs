//! The site's static files (styles, scripts, fonts, images, PDFs, videos), served by their URL path from one
//! directory, `HXWEB_DOCROOT`: the repository's `public/` tree with the uploads beside it.

use std::path::{Path, PathBuf};

use axum::{
    body::Body,
    http::{header, HeaderValue, Response, StatusCode},
    response::IntoResponse,
};

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

pub fn root() -> Option<&'static Path> {
    static ROOT: std::sync::OnceLock<Option<PathBuf>> = std::sync::OnceLock::new();
    ROOT.get_or_init(|| std::env::var_os("HXWEB_DOCROOT").map(PathBuf::from)).as_deref()
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

/// The file with an ETag of its size and modification time; a browser that already holds that version gets 304.
pub async fn serve(file: PathBuf, kind: &'static str, if_none_match: Option<&HeaderValue>) -> Response<Body> {
    let held = if_none_match.and_then(|v| v.to_str().ok()).unwrap_or("").to_string();
    let read = tokio::task::spawn_blocking(move || {
        let meta = std::fs::metadata(&file)?;
        let modified = meta.modified()?.duration_since(std::time::UNIX_EPOCH).map_or(0, |d| d.as_nanos());
        let tag = format!("\"{:x}-{modified:x}\"", meta.len());
        let fresh = held.split(',').any(|t| t.trim().trim_start_matches("W/") == tag);
        Ok::<_, std::io::Error>((tag, if fresh { None } else { Some(std::fs::read(&file)?) }))
    });
    match read.await {
        Ok(Ok((tag, body))) => {
            let headers = [(header::CONTENT_TYPE, kind), (header::CACHE_CONTROL, "public, max-age=3600"), (header::ETAG, tag.as_str())];
            match body {
                Some(bytes) => (headers, bytes).into_response(),
                None => (StatusCode::NOT_MODIFIED, headers).into_response(),
            }
        }
        _ => (StatusCode::NOT_FOUND, "Not found").into_response(),
    }
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

    #[tokio::test]
    async fn a_browser_holding_the_current_version_gets_304() {
        let file = std::env::temp_dir().join(format!("hx-etag-{}.css", std::process::id()));
        std::fs::write(&file, "body{}").unwrap();
        let first = serve(file.clone(), "text/css", None).await;
        let tag = first.headers()[header::ETAG].clone();
        assert_eq!(first.status(), StatusCode::OK);
        assert_eq!(serve(file.clone(), "text/css", Some(&tag)).await.status(), StatusCode::NOT_MODIFIED);
        assert_eq!(serve(file.clone(), "text/css", Some(&HeaderValue::from_static("\"0-0\""))).await.status(), StatusCode::OK);
        std::fs::remove_file(&file).unwrap();
    }
}
