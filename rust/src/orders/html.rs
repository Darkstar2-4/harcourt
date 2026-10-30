//! HTML for the orders pages: plain functions that write strings. Every interpolated value goes through `esc`.
//!
//! The page chrome is what scs_header.php and includes/forms_rev2.php print around an SCS page: `html_open()`
//! (head from the registry's `www` module), `$menu->head()` (the scs_body and scs_content divs, and the ribbon
//! outside WordPress), and `$menu->copyright()`. Inside WordPress these pages load in an iframe
//! (`scscpq_wp.php` gives `/cart.php?scscpq_wp=1`); there the ribbon is left out.

use axum::{
    http::{header, HeaderMap, HeaderValue, StatusCode},
    response::{IntoResponse, Response},
};
use sqlx::SqlitePool;

/// Escapes text for an HTML element or a quoted attribute (either quote style), as `htmlspecialchars` does.
pub fn esc(s: &str) -> String {
    let mut out = String::with_capacity(s.len() + 8);
    for c in s.chars() {
        match c {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            '"' => out.push_str("&quot;"),
            '\'' => out.push_str("&#039;"),
            _ => out.push(c),
        }
    }
    out
}

/// Escapes text between tags. Only `<` and `>` can start markup there; quotes and ampersands are left as the
/// PHP printed them, since an entity in text only ever shows as text.
pub fn esc_text(s: &str) -> String {
    s.replace('<', "&lt;").replace('>', "&gt;")
}

/// The registry's `www` module: the stylesheets, scripts and meta lines every SCS page carries.
#[derive(Debug, Clone, Default)]
pub struct Chrome {
    pub title_prefix: String,
    pub icon: String,
    pub meta: Vec<String>,
    pub css: Vec<String>,
    pub js: Vec<String>,
}

impl Chrome {
    pub async fn load(pool: &SqlitePool) -> sqlx::Result<Self> {
        let rows = sqlx::query!(
            r#"SELECT field AS "field!", item AS "item!", coalesce(data, '') AS "data!: String" FROM registry
               WHERE module = 'www' AND domain IN ('', 'global')
               ORDER BY domain = 'global', field, CASE WHEN item GLOB '[0-9]*' THEN printf('%04d', item) ELSE item END"#
        )
        .fetch_all(pool)
        .await?;
        let mut chrome = Chrome::default();
        for r in rows {
            let data = r.data.trim().to_string();
            match (r.field.as_str(), r.item.is_empty()) {
                ("title_prefix", true) => chrome.title_prefix = data,
                ("icon", true) => chrome.icon = data,
                ("meta", false) if !data.is_empty() => chrome.meta.push(data),
                ("css", false) if !data.is_empty() && !chrome.css.contains(&data) => chrome.css.push(data),
                ("js", false) if !data.is_empty() && !chrome.js.contains(&data) => chrome.js.push(data),
                _ => {}
            }
        }
        Ok(chrome)
    }
}

/// Whether the page is inside the WordPress iframe. PHP remembers `?scscpq_wp=1` in the session; a stateless
/// server sees it on the first request and, for the form posts and links inside the frame, in `Sec-Fetch-Dest`.
pub fn framed(query: Option<&str>, headers: &HeaderMap) -> bool {
    let flagged = query.unwrap_or("").split('&').any(|kv| kv.split_once('=').is_some_and(|(k, v)| k == "scscpq_wp" && !v.is_empty() && v != "0"));
    flagged || headers.get("sec-fetch-dest").is_some_and(|v| v.as_bytes() == b"iframe")
}

/// What differs between pages in the chrome.
pub struct Head<'a> {
    pub title: &'a str,
    pub description: Option<&'a str>,
    /// Scripts the page adds after the registry's.
    pub js: &'a [&'a str],
    pub framed: bool,
    /// Executive and above see the dashboard link in the ribbon.
    pub dashboard: bool,
    /// The punch-out shop's catalog URL replaces /catalog.php in the ribbon.
    pub catalog_url: &'a str,
}

/// `html_open()` + `$menu->head()`: everything before the page's own output.
pub fn open(chrome: &Chrome, head: &Head) -> String {
    let title = if chrome.title_prefix.is_empty() { head.title.to_string() } else { format!("{} {}", chrome.title_prefix, head.title) };
    let title = if title.trim().is_empty() { "Untitled Page".to_string() } else { title.trim().to_string() };
    let description = head.description.map(str::trim).filter(|d| !d.is_empty()).unwrap_or(&title);
    let mut lines = vec![format!("<title>{}</title>", esc(&title)), format!("<meta name=\"description\" content=\"{}\">", esc(description))];
    lines.extend(chrome.meta.iter().cloned());
    lines.push("<meta name=\"robots\" content=\"noarchive, noimageindex\">".into());
    lines.push("<meta charset=\"utf-8\">".into());
    if !chrome.icon.is_empty() {
        lines.push(format!("<link rel='icon' href='{}' type='image/x-icon'>", chrome.icon));
    }
    lines.extend(chrome.css.iter().map(|c| format!("<link rel='stylesheet' type='text/css' href='{c}'>")));
    let mut js: Vec<&str> = chrome.js.iter().map(String::as_str).collect();
    for s in head.js.iter().copied().chain((!head.framed).then_some("/scs_scripts/scscpq_wp.js?v=LOCAL")) {
        if !js.contains(&s) {
            js.push(s);
        }
    }
    lines.extend(js.iter().map(|s| format!("<script type='text/javascript' src='{s}'></script>")));
    let mut out = format!("<!DOCTYPE html>\n<html>\n<head>\n{}\n</head>\n<body>\n", lines.join("\n"));
    out.push_str("<div id='scs_body' >\n");
    if !head.framed {
        out.push_str(&ribbon(head));
        out.push('\n');
    }
    out.push_str("<div id='scs_content' >");
    out
}

/// `$menu->copyright()`: closes the divs `open` opened, then the document.
pub fn close() -> &'static str {
    "</div> <!-- #scs_content -->\n</div> <!-- #scs_body --></body>\n</html>\n"
}

fn ribbon(head: &Head) -> String {
    let cat = esc(head.catalog_url);
    let logo = "<img style='transform: translate(0%, 10px);' src='/scs_images/logo.png' height='70' title='Harcourt Industrial' border='0'>";
    let logo = if head.catalog_url == "/catalog.php" { format!("<a href=\"/\" >{logo}</a>") } else { format!("<b>{logo}</b>") };
    let dash = if head.dashboard { "<div class=scs_menu_left><a href=\"/dashboard.php\" >Dashboard</a></div>" } else { "" };
    format!(
        "\n<script>\nfunction menu_search() {{\n\tsearch_code=jQuery('#scscpq_search_text').val();\n\tsearch_code=search_code.trim()\n\tif (search_code.length < 2) {{\n\t\talert('Product Search must be at least 2 characters')\n\t\treturn;\n\t}}\n\tlocation.href='{cat}?search_source=term&search_code=' + encodeURIComponent(search_code);\n}}\n</script>\n<div id='scs_menu' ><div class='scs_menu_left' style='padding-top: 10px;')>{logo}</div>{dash}<div class='scs_menu_left scscpq_impersonate'><a href=\"/user_impersonate.php\" >Stop Impersonate</a></div><div class='scs_menu_left scscpq_newuser'><a href=\"/newuser.php\" >New user</a></div><div class='scs_menu_right scscpq_cart'></div><div class='scs_menu_right scscpq_search'></div><div id='menu-item-141' class='scs_menu_right scscpq_catalog'><a href=\"{cat}\" class='scs_menu_button'>Catalog</a></div></div> <!-- #scs_menu -->"
    )
}

/// `$forms->message()`: the page title line, further messages after it, and the error count last. Messages are
/// HTML the page wrote; a value from a visitor inside one must already be escaped.
pub fn message(messages: &[String], errors: usize) -> String {
    let mut all: Vec<String> = messages.to_vec();
    if errors > 0 {
        all.push(format!("Correct {errors} error(s)"));
    }
    match all.split_first() {
        None => String::new(),
        Some((title, [])) => format!("<p class='message left'>{title}</p>"),
        Some((title, rest)) => format!("<p class='message left'>{title}<span class=message2><br>{}</span></p>", rest.join("<br>")),
    }
}

/// `$forms->open()`, posting back to `action` (PHP_SELF).
pub fn form_open(action: &str) -> String {
    format!("<form name='scs_form' id='scs_form' method=post action='{}' accept-charset='utf-8' autocomplete=on>\n", esc(action))
}

pub const FORM_CLOSE: &str = "</form>";

/// `$forms->hidden($field, $value)`.
pub fn hidden(field: &str, value: &str) -> String {
    if value.is_empty() {
        format!("<input type=hidden name='{0}' id='{0}'>\n", esc(field))
    } else {
        format!("<input type=hidden name='{0}' id='{0}' value='{1}'>\n", esc(field), esc(value))
    }
}

/// `$forms->button($text, ["onclick" => ..., "class" => ...])`. `onclick` is script the page wrote; values inside
/// it go through `js_str`.
pub fn button(text: &str, onclick: &str, class: &str) -> String {
    format!("<input type=button class='{}' value='{}' onclick=\"javascript:{}\">\n", esc(class), esc(text), esc_dq(onclick))
}

/// Escapes for a double-quoted attribute, leaving single quotes, so script in `onclick` reads as PHP printed it.
pub fn esc_dq(s: &str) -> String {
    s.replace('&', "&amp;").replace('<', "&lt;").replace('>', "&gt;").replace('"', "&quot;")
}

/// A single-quoted JavaScript string literal, as `fn_escape` quoted values for the inline handlers.
pub fn js_str(s: &str) -> String {
    let mut out = String::from("'");
    for c in s.chars() {
        match c {
            '\\' => out.push_str("\\\\"),
            '\'' => out.push_str("\\'"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '<' => out.push_str("\\x3c"),
            _ => out.push(c),
        }
    }
    out.push('\'');
    out
}

/// `$forms->text($field, $value, $size, $maxlength, $type, ["placeholder" => ...])`.
pub fn text_input(kind: &str, field: &str, value: &str, size: u32, maxlength: u32, placeholder: &str, error: bool) -> String {
    let mut out = format!("<input type={kind} name='{0}' id='{0}' size='{size}' maxlength='{maxlength}'", esc(field));
    if !value.is_empty() {
        out.push_str(&format!(" value='{}'", esc(value)));
    }
    if !placeholder.is_empty() {
        out.push_str(&format!(" placeholder='{}'", esc(placeholder)));
    }
    if error {
        out.push_str(ERROR_STYLE);
    }
    out.push_str(">\n");
    out
}

/// `$forms->select($field, $choices, $selected, $blank)`: `(value, label)` pairs, an optional blank first option.
pub fn select(field: &str, choices: &[(String, String)], selected: &str, blank: Option<&str>, onchange: &str, error: bool) -> String {
    let mut out = format!("<select name='{0}' id='{0}'", esc(field));
    if !onchange.is_empty() {
        out.push_str(&format!(" onchange=\"javascript:{}\"", esc_dq(onchange)));
    }
    if error {
        out.push_str(ERROR_STYLE);
    }
    out.push_str(">\n");
    match blank {
        Some("") => out.push_str("<option></option>\n"),
        Some(b) => out.push_str(&format!("<option value=''>{}</option>\n", esc(b))),
        None => {}
    }
    for (value, label) in choices {
        let sel = if value == selected { " selected" } else { "" };
        out.push_str(&format!("<option value='{}'{sel}>{}</option>\n", esc(value), esc_text(label)));
    }
    out.push_str("</select>\n");
    out
}

/// The red border forms_rev2.php adds to a field with an error.
pub const ERROR_STYLE: &str = " style='border: 1px solid red !important;'";

pub fn page_response(html: String) -> Response {
    let mut r = (StatusCode::OK, html).into_response();
    r.headers_mut().insert(header::CONTENT_TYPE, HeaderValue::from_static("text/html; charset=UTF-8"));
    r.headers_mut().insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store, no-cache, must-revalidate"));
    r
}

/// `fn_url_redirect()`: a 302 with no body, labelled as PHP labels every answer.
pub fn redirect(location: &str) -> Response {
    let mut r = StatusCode::FOUND.into_response();
    r.headers_mut().insert(header::CONTENT_TYPE, HeaderValue::from_static(crate::visitor::PHP_CONTENT_TYPE));
    if let Ok(v) = HeaderValue::from_str(location) {
        r.headers_mut().insert(header::LOCATION, v);
    }
    r
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn escapes_markup_and_both_quotes() {
        assert_eq!(esc(r#"<a href="x" title='y'>&</a>"#), "&lt;a href=&quot;x&quot; title=&#039;y&#039;&gt;&amp;&lt;/a&gt;");
    }

    #[test]
    fn handler_strings_cannot_close_their_quote_or_attribute() {
        assert_eq!(js_str(r#"a'b\c"#), r"'a\'b\\c'");
        assert_eq!(button("X", &format!("cart_delete({});", js_str("A\"B")), "cart_delete"), "<input type=button class='cart_delete' value='X' onclick=\"javascript:cart_delete('A&quot;B');\">\n");
        assert_eq!(text_input("text", "po", "1'2", 40, 45, "PO# (Optional)", false), "<input type=text name='po' id='po' size='40' maxlength='45' value='1&#039;2' placeholder='PO# (Optional)'>\n");
    }

    #[test]
    fn messages_follow_forms_rev2() {
        assert_eq!(message(&[], 0), "");
        assert_eq!(message(&["Shopping Cart".into()], 0), "<p class='message left'>Shopping Cart</p>");
        assert_eq!(
            message(&["Checkout".into(), "Saved".into()], 1),
            "<p class='message left'>Checkout<span class=message2><br>Saved<br>Correct 1 error(s)</span></p>"
        );
    }

    #[test]
    fn framed_comes_from_the_flag_or_the_iframe_fetch() {
        let mut h = HeaderMap::new();
        assert!(framed(Some("scscpq_wp=1"), &h));
        assert!(!framed(Some("scscpq_wp=0&x=1"), &h));
        assert!(!framed(None, &h));
        h.insert("sec-fetch-dest", HeaderValue::from_static("iframe"));
        assert!(framed(None, &h));
    }

    #[test]
    fn the_document_wraps_the_page_like_scs_header() {
        let chrome = Chrome { css: vec!["/a.css".into()], js: vec!["/a.js".into()], meta: vec!["<meta name=\"viewport\">".into()], icon: "/i.ico".into(), ..Chrome::default() };
        let head = Head { title: "Shopping Cart", description: None, js: &[], framed: true, dashboard: false, catalog_url: "/catalog.php" };
        assert_eq!(
            open(&chrome, &head),
            "<!DOCTYPE html>\n<html>\n<head>\n<title>Shopping Cart</title>\n<meta name=\"description\" content=\"Shopping Cart\">\n<meta name=\"viewport\">\n<meta name=\"robots\" content=\"noarchive, noimageindex\">\n<meta charset=\"utf-8\">\n<link rel='icon' href='/i.ico' type='image/x-icon'>\n<link rel='stylesheet' type='text/css' href='/a.css'>\n<script type='text/javascript' src='/a.js'></script>\n</head>\n<body>\n<div id='scs_body' >\n<div id='scs_content' >"
        );
        let unframed = open(&chrome, &Head { framed: false, dashboard: true, ..head });
        assert!(unframed.contains("src='/scs_scripts/scscpq_wp.js?v=LOCAL'></script>\n</head>"));
        assert!(unframed.contains("<a href=\"/dashboard.php\" >Dashboard</a>"));
        assert!(unframed.ends_with("<!-- #scs_menu -->\n<div id='scs_content' >"));
    }
}
