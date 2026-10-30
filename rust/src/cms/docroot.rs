//! The docroot as prod's nginx and Apache answered from it ahead of WordPress: static files by extension (nginx's
//! 30-hx-static.conf), files and directories Apache found, PHP scripts. A path none of them took went to WordPress
//! (`RewriteRule . /index.php`), and is the cms's to answer.

use std::path::{Path, PathBuf};

#[derive(Debug, PartialEq, Eq)]
pub enum Owner {
    /// A static file nginx served from the docroot, with nginx's content type.
    File(PathBuf, &'static str),
    /// PHP, a file or directory Apache served, or a path main.rs gives another area: the legacy stack's.
    Legacy,
    WordPress,
}

/// The extensions nginx serves from the docroot when the file exists, with the types of its mime.types.
const STATIC: [(&str, &str); 18] = [
    ("css", "text/css"),
    ("js", "application/javascript"),
    ("gif", "image/gif"),
    ("png", "image/png"),
    ("jpg", "image/jpeg"),
    ("jpeg", "image/jpeg"),
    ("webp", "image/webp"),
    ("svg", "image/svg+xml"),
    ("ico", "image/x-icon"),
    ("woff", "application/font-woff"),
    ("woff2", "application/octet-stream"),
    ("ttf", "application/octet-stream"),
    ("eot", "application/vnd.ms-fontobject"),
    ("otf", "application/octet-stream"),
    ("mp4", "video/mp4"),
    ("webm", "video/webm"),
    ("pdf", "application/pdf"),
    ("svgz", "image/svg+xml"),
];

/// The docroot's top-level directories and files Apache serves (PHP, its includes, static sites, icons) that the
/// cms does not, and WordPress's admin and REST API: a request under one goes to the legacy stack. wp-content,
/// wp-includes, hx, hx-vendor, scs_scripts and the catalog image directories are left to the file check, as hxweb's
/// docroot holds their files.
const APACHE: [&str; 42] = [
    ".github",
    ".scs_sql",
    "Landing Pages",
    "Product Web pg",
    "Webpages",
    "campaign",
    "classes",
    "composer",
    "composer8",
    "docs",
    "icons",
    "images",
    "includes",
    "includes_legacy",
    "migrations",
    "misc",
    "oauth",
    "pdf",
    "portalpage",
    "rust",
    "scsps",
    "testing",
    "tools",
    "vendor",
    "web",
    "welcome-pages",
    "xincludes",
    "xincludes_legacy",
    "xscsps",
    "404.shtml",
    "apple-touch-icon-precomposed.png",
    "apple-touch-icon.png",
    "blank.txt",
    "default.htm",
    "favicon.ico",
    "license.txt",
    "logo-imh.svg",
    "readme.html",
    "under_construction.html",
    "cgi-bin",
    "wp-admin",
    "wp-json",
];

/// Who answered `path` (percent-decoded) on prod, given the docroot.
pub fn owner(path: &str, root: Option<&Path>) -> Owner {
    let segments: Vec<&str> = path.split('/').filter(|s| !s.is_empty()).collect();
    if segments.iter().any(|s| *s == ".." || s.starts_with('.') || s.to_ascii_lowercase().ends_with(".php")) {
        return Owner::Legacy;
    }
    if path == "/catalog/" || path == "/catalogue/" || segments.first().is_some_and(|s| APACHE.contains(s)) {
        return Owner::Legacy;
    }
    let Some(last) = segments.last() else { return Owner::WordPress };
    let file = root.map(|r| r.join(segments.join("/")));
    let ext = last.rsplit_once('.').map(|(_, e)| e.to_ascii_lowercase()).unwrap_or_default();
    if let Some((_, kind)) = STATIC.iter().find(|(e, _)| *e == ext) {
        if let Some(f) = file.as_ref().filter(|f| f.is_file()) {
            return Owner::File(f.clone(), kind);
        }
        let lower = path.to_ascii_lowercase();
        let nginx_only = (lower.starts_with("/wp-content/uploads/") && matches!(ext.as_str(), "png" | "jpg" | "jpeg"))
            || ((lower.starts_with("/wp-content/plugins/") || lower.starts_with("/wp-content/themes/") || lower.starts_with("/wp-includes/"))
                && matches!(ext.as_str(), "css" | "js"));
        return if nginx_only { Owner::Legacy } else { Owner::WordPress };
    }
    if file.is_some_and(|f| f.exists()) {
        return Owner::Legacy;
    }
    Owner::WordPress
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn paths_go_where_prod_sent_them() {
        let root = std::env::temp_dir().join(format!("hx-docroot-{}", std::process::id()));
        std::fs::create_dir_all(root.join("wp-content/themes/t")).unwrap();
        std::fs::write(root.join("wp-content/themes/t/a.css"), "x").unwrap();
        std::fs::write(root.join("notes.md"), "x").unwrap();
        let r = Some(root.as_path());
        assert_eq!(owner("/wp-content/themes/t/a.css", r), Owner::File(root.join("wp-content/themes/t/a.css"), "text/css"));
        assert_eq!(owner("/wp-content/themes/t/b.css", r), Owner::Legacy);
        assert_eq!(owner("/wp-content/zzz.css", r), Owner::WordPress);
        assert_eq!(owner("/wp-content/uploads/x.png", r), Owner::Legacy);
        assert_eq!(owner("/wp-content/", r), Owner::Legacy);
        assert_eq!(owner("/notes.md", r), Owner::Legacy);
        for p in ["/catalog.php", "/x/../y", "/.env", "/campaign/x.css", "/campaign/", "/catalog/", "/wp-json/wp/v2/pages", "/Shop/a.PHP"] {
            assert_eq!(owner(p, r), Owner::Legacy, "{p}");
        }
        for p in ["/", "/zzz.html", "/no-such-page/", "/company/zzz", "/sitemap_index.xml", "/hx-vendor/zzz.js"] {
            assert_eq!(owner(p, r), Owner::WordPress, "{p}");
        }
        std::fs::remove_dir_all(&root).unwrap();
    }
}
