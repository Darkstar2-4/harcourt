//! The page around an SCS view in WordPress mode (scscpq_wp): forms_class::html_open()'s head from the registry's
//! www settings, menu_class::head()'s two divs, and copyright()/html_close() after the view.

use crate::catalog::{
    forms,
    html::{self, esc, esc_text},
    registry::Registry,
};

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

const MENU_SEARCH: &str = "\n<script>\nfunction menu_search() {\n\tsearch_code=jQuery('#scscpq_search_text').val();\n\tsearch_code=search_code.trim()\n\tif (search_code.length < 2) {\n\t\talert('Product Search must be at least 2 characters')\n\t\treturn;\n\t}\n\tlocation.href='/catalog.php?search_source=term&search_code=' + encodeURIComponent(search_code);\n}\n</script>\n";

/// menu_class::ribbon(): the SCS menu a page draws outside the WordPress frame, with the dashboard link when the
/// visitor has one (`dashboard` is its label).
pub fn ribbon(dashboard: Option<&str>) -> String {
    let logo = forms::img("/scs_images/logo.png", &[("height", "70"), ("style", "transform: translate(0%, 10px);"), ("title", "Harcourt Industrial")]).unwrap_or_default();
    let mut out = format!("{MENU_SEARCH}<div id='scs_menu' ><div class='scs_menu_left' style='padding-top: 10px;')>{}</div>", html::href(&logo, "/"));
    if let Some(label) = dashboard {
        out.push_str(&format!("<div class=scs_menu_left>{}</div>", html::href(&esc_text(label), "/dashboard.php")));
    }
    out.push_str(&format!("<div class='scs_menu_left scscpq_impersonate'>{}</div>", html::href("Stop Impersonate", "/user_impersonate.php")));
    out.push_str(&format!("<div class='scs_menu_left scscpq_newuser'>{}</div>", html::href("New user", "/newuser.php")));
    out.push_str("<div class='scs_menu_right scscpq_cart'></div><div class='scs_menu_right scscpq_search'></div>");
    out.push_str(&format!("<div id='menu-item-141' class='scs_menu_right scscpq_catalog'>{}</div>", html::href_with("Catalog", "/catalog.php", &[("class", "scs_menu_button")])));
    out.push_str("</div> <!-- #scs_menu -->");
    out
}

/// What forms_class::html_open() writes for a page: its title and the page's own stylesheets.
pub struct Head<'a> {
    pub title: &'a str,
    pub css: &'a [String],
}

impl<'a> Head<'a> {
    pub fn titled(title: &'a str) -> Head<'a> {
        Head { title, css: &[] }
    }
}

/// forms_class::html_open(): through `<body>`. A page outside the WordPress frame also loads the loader script.
pub fn html_open(reg: &Registry, head: &Head<'_>, own_frame: bool) -> String {
    let prefix = reg.get("www", "title_prefix").trim();
    let full = match (head.title.trim().is_empty(), prefix.is_empty()) {
        (true, _) => "Untitled Page".to_string(),
        (false, true) => head.title.to_string(),
        (false, false) => format!("{prefix} {}", head.title),
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
    for href in listed(reg, "www", "css").into_iter().chain(head.css.iter().cloned()) {
        let tag = format!("<link rel='stylesheet' type='text/css' href='{}'>", esc(&href));
        if !css.contains(&tag) {
            css.push(tag);
        }
    }
    let js_async = !reg.get("www", "js_async").is_empty();
    let mut js: Vec<String> = Vec::new();
    let own = own_frame.then(|| "/scs_scripts/scscpq_wp.js?v=LOCAL".to_string());
    for src in listed(reg, "www", "js").into_iter().chain(own) {
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
    out.push_str("</head>\n<body>\n");
    out
}

/// forms_class::html_close().
pub const HTML_CLOSE: &str = "</body>\n</html>\n";

/// menu_class::head(): the document through the opening of scs_content. A page outside the WordPress frame
/// (`ribbon` given) also loads the loader script and draws the ribbon.
pub fn open(reg: &Registry, head: &Head<'_>, ribbon: Option<&str>) -> String {
    let mut out = html_open(reg, head, ribbon.is_some());
    out.push_str("<div id='scs_body' >\n");
    if let Some(r) = ribbon {
        out.push_str(r);
        out.push('\n');
    }
    out.push_str("<div id='scs_content' >");
    out
}

/// menu_class::copyright(): the menu divs closed, then html_close().
pub const CLOSE: &str = "</div> <!-- #scs_content -->\n</div> <!-- #scs_body --></body>\n</html>\n";

/// The whole document: head, then the view between the scs_body and scs_content divs.
pub fn document(reg: &Registry, head: &Head<'_>, body: &str, ribbon: Option<&str>) -> String {
    format!("{}{body}{CLOSE}", open(reg, head, ribbon))
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
        let css = ["/scs_scripts/catalog.css?v=7".to_string()];
        let doc = document(&reg, &Head { css: &css, ..Head::titled("Products") }, "<p>x</p>", None);
        assert!(doc.starts_with("<!DOCTYPE html>\n<html>\n<head>\n<title>Harcourt | Products</title>\n<meta name=\"description\" content=\"Harcourt | Products\">\n<meta name=\"robots\" content=\"noarchive, noimageindex\">\n"));
        assert!(doc.contains("<link rel='stylesheet' type='text/css' href='/scs_scripts/a.css'>\n<link rel='stylesheet' type='text/css' href='/scs_scripts/catalog.css?v=7'>\n<script type='text/javascript' src='/scs_scripts/jquery.js'></script>\n<script type='text/javascript' src='/scs_scripts/scscpq.js'></script>\n</head>"));
        assert!(doc.ends_with("<div id='scs_content' ><p>x</p></div> <!-- #scs_content -->\n</div> <!-- #scs_body --></body>\n</html>\n"));
        let own = document(&reg, &Head::titled("Products"), "<p>x</p>", Some("<div id='scs_menu' ></div> <!-- #scs_menu -->"));
        assert!(own.contains("<script type='text/javascript' src='/scs_scripts/scscpq_wp.js?v=LOCAL'></script>\n</head>"));
        assert!(own.contains("<div id='scs_body' >\n<div id='scs_menu' ></div> <!-- #scs_menu -->\n<div id='scs_content' ><p>x</p>"));
        assert!(ribbon(Some("Dashboard")).contains("<div class=scs_menu_left><a href=\"/dashboard.php\" >Dashboard</a></div><div class='scs_menu_left scscpq_impersonate'>"));
    }
}
