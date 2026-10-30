//! The page around an SCS view in WordPress mode (scscpq_wp): forms_class::html_open()'s head from the registry's
//! www settings, menu_class::head()'s two divs, and copyright()/html_close() after the view.

use crate::catalog::{html::esc, registry::Registry};

fn listed(reg: &Registry, module: &str, field: &str) -> Vec<String> {
    let items = reg.items(module, field);
    if items.is_empty() {
        let single = reg.get(module, field).trim();
        return if single.is_empty() { Vec::new() } else { vec![single.to_string()] };
    }
    items.iter().map(|(_, v)| v.trim().to_string()).filter(|v| !v.is_empty()).collect()
}

fn absolute(url: &str) -> bool {
    let lower = url.to_ascii_lowercase();
    lower.starts_with("http:") || lower.starts_with("https:") || lower.starts_with("ftp:")
}

/// The whole document: head, then the view between the scs_body and scs_content divs.
pub fn document(reg: &Registry, title: &str, extra_css: &[String], body: &str) -> String {
    let prefix = reg.get("www", "title_prefix").trim();
    let full = match (title.trim().is_empty(), prefix.is_empty()) {
        (true, _) => "Untitled Page".to_string(),
        (false, true) => title.to_string(),
        (false, false) => format!("{prefix} {title}"),
    };
    let mut meta = vec![format!("<title>{}</title>", esc(&full)), format!("<meta name=\"description\" content=\"{}\">", esc(full.trim()))];
    meta.extend(reg.items("www", "meta").iter().map(|(_, v)| v.trim().to_string()).filter(|v| !v.is_empty()));
    meta.push("<meta name=\"robots\" content=\"noarchive, noimageindex\">".into());
    meta.push("<meta charset=\"utf-8\">".into());
    let icon = reg.get("www", "icon").trim();
    if !icon.is_empty() {
        meta.push(format!("<link rel='icon' href='{}' type='image/x-icon'>", esc(icon)));
    }
    let mut css: Vec<String> = Vec::new();
    for href in listed(reg, "www", "css").into_iter().chain(extra_css.iter().cloned()) {
        let tag = format!("<link rel='stylesheet' type='text/css' href='{}'>", esc(&href));
        if !css.contains(&tag) {
            css.push(tag);
        }
    }
    let js_async = !reg.get("www", "js_async").is_empty();
    let mut js: Vec<String> = Vec::new();
    for src in listed(reg, "www", "js") {
        let tag = format!("<script type='text/javascript' src='{}'{}></script>", esc(&src), if js_async && absolute(&src) { " async" } else { "" });
        if !js.contains(&tag) {
            js.push(tag);
        }
    }
    let mut out = String::from("<!DOCTYPE html>\n<html>\n<head>\n");
    for group in [&meta, &css, &js] {
        if !group.is_empty() {
            out.push_str(&group.join("\n"));
            out.push('\n');
        }
    }
    out.push_str("</head>\n<body>\n<div id='scs_body' >\n<div id='scs_content' >");
    out.push_str(body);
    out.push_str("</div> <!-- #scs_content -->\n</div> <!-- #scs_body --></body>\n</html>\n");
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_head_comes_from_the_registry() {
        let reg = Registry::from_rows([
            ("", "www", "title_prefix", "", Some("Harcourt |")),
            ("", "www", "css", "1", Some("/scs_scripts/a.css")),
            ("", "www", "js", "1", Some("/scs_scripts/jquery.js")),
            ("", "www", "js", "2", Some("/scs_scripts/scscpq.js")),
        ]);
        let doc = document(&reg, "Products", &["/scs_scripts/catalog.css?v=7".into()], "<p>x</p>");
        assert!(doc.starts_with("<!DOCTYPE html>\n<html>\n<head>\n<title>Harcourt | Products</title>\n<meta name=\"description\" content=\"Harcourt | Products\">\n<meta name=\"robots\" content=\"noarchive, noimageindex\">\n"));
        assert!(doc.contains("<link rel='stylesheet' type='text/css' href='/scs_scripts/a.css'>\n<link rel='stylesheet' type='text/css' href='/scs_scripts/catalog.css?v=7'>\n<script type='text/javascript' src='/scs_scripts/jquery.js'></script>\n<script type='text/javascript' src='/scs_scripts/scscpq.js'></script>\n</head>"));
        assert!(doc.ends_with("<div id='scs_content' ><p>x</p></div> <!-- #scs_content -->\n</div> <!-- #scs_body --></body>\n</html>\n"));
    }
}
