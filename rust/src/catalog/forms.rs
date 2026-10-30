//! The form and element helpers of includes/forms_rev2.php, attribute order included: forms_class::options() writes
//! attributes in groups (name, src, value, event, boolean, html5) and, inside a group, in the order they were set.
//! Values are escaped here where the PHP wrote them raw.

use std::{
    path::{Path, PathBuf},
    sync::OnceLock,
};

use super::html::{esc, esc_attr};

const NAME: &[&str] = &["name", "id", "class", "style"];
const BOOLEAN: &[&str] = &["checked", "selected", "disabled", "readonly"];
const HTML5: &[&str] = &["placeholder"];

/// forms_class::options(). `field` names the element: it becomes `name` and, unless set, `id`. Empty values are
/// left out; a boolean attribute is written bare when its value is non-empty.
pub fn options(field: &str, attrs: &[(&str, &str)]) -> String {
    let mut list: Vec<(&str, &str)> = attrs.to_vec();
    if !field.is_empty() {
        match list.iter_mut().find(|(k, _)| *k == "name") {
            Some(entry) => entry.1 = field,
            None => list.push(("name", field)),
        }
        if !list.iter().any(|(k, _)| *k == "id") {
            list.push(("id", field));
        }
    }
    let (mut name, mut src, mut value, mut event, mut boolean, mut html5) =
        (String::new(), String::new(), String::new(), String::new(), String::new(), String::new());
    for (k, v) in list.iter().filter(|(_, v)| !v.is_empty()) {
        let quoted = if *k == "value" { format!(" {k}='{}'", esc(v)) } else { format!(" {k}='{}'", esc_attr(v)) };
        match *k {
            k if NAME.contains(&k) => name.push_str(&quoted),
            k if BOOLEAN.contains(&k) => boolean.push_str(&format!(" {k}")),
            k if HTML5.contains(&k) => html5.push_str(&quoted),
            k if k.starts_with("on") => event.push_str(&format!(" {k}=\"javascript:{}\"", v.replace('"', "&quot;"))),
            "src" => src.push_str(&quoted),
            _ => value.push_str(&quoted),
        }
    }
    format!("{name}{src}{value}{event}{boolean}{html5}")
}

pub fn hidden(field: &str, value: &str) -> String {
    hidden_class(field, value, "")
}

pub fn hidden_class(field: &str, value: &str, class: &str) -> String {
    format!("<input type=hidden{}>\n", options(field, &[("name", field), ("value", value), ("class", class)]))
}

/// forms_class::text(): a text input of `size` characters.
pub fn text(field: &str, value: &str, size: &str, maxlength: &str, attrs: &[(&str, &str)]) -> String {
    let mut list = attrs.to_vec();
    list.push(("size", size));
    list.push(("maxlength", maxlength));
    list.push(("value", value));
    format!("<input type=text{}>\n", options(field, &list))
}

/// forms_class::button(): class nav_button unless another is given.
pub fn button(label: &str, attrs: &[(&str, &str)]) -> String {
    let mut list = attrs.to_vec();
    if !list.iter().any(|(k, _)| *k == "class") {
        list.push(("class", "nav_button"));
    }
    list.push(("value", label));
    format!("<input type=button{}>\n", options("", &list))
}

pub fn checkbox(field: &str, value: &str, compare: &str, attrs: &[(&str, &str)]) -> String {
    let mut list = attrs.to_vec();
    if !list.iter().any(|(k, _)| *k == "checked") {
        list.push(("checked", if compare == value { "checked" } else { "" }));
    }
    list.push(("value", value));
    format!("<input type=checkbox{}>", options(field, &list))
}

/// forms_class::select() over a plain list (each value is its own key), with an empty first option.
pub fn select_list(field: &str, values: &[String], selected: &str, attrs: &[(&str, &str)]) -> String {
    let mut out = format!("<select{}>\n<option></option>\n", options(field, attrs));
    for v in values {
        let mark = if v == selected { " selected" } else { "" };
        out.push_str(&format!("<option value='{}'{mark}>{}</option>\n", esc(v), esc(v)));
    }
    out.push_str("</select>\n");
    out
}

pub fn font(color: &str, inner_html: &str, after_html: &str) -> String {
    format!("<font color={color}>{inner_html}</font>{after_html}")
}

/// forms_class::img(). A site path that is not on disk gives no element, as the PHP checked the document root;
/// without HXWEB_DOCROOT every path counts as present.
pub fn img(src: &str, attrs: &[(&str, &str)]) -> Option<String> {
    if src.is_empty() || !site_file_exists(src) {
        return None;
    }
    let mut list = attrs.to_vec();
    list.push(("src", src));
    if !list.iter().any(|(k, _)| *k == "border") {
        list.push(("border", "0"));
    }
    Some(format!("<img{}>", options("", &list)))
}

/// The PHP's test `preg_match("/^[http|https]/i", $src)` is a character class: any src starting with one of
/// h, t, p, s or | skipped the document-root check.
fn site_file_exists(src: &str) -> bool {
    if src.starts_with(|c: char| "htps|HTPS".contains(c)) {
        return true;
    }
    match docroot() {
        Some(root) => {
            let path = if src.starts_with('/') { src.to_string() } else { format!("/{src}") };
            !path.split('/').any(|seg| seg == "..") && root.join(path.trim_start_matches('/')).is_file()
        }
        None => true,
    }
}

fn docroot() -> Option<&'static Path> {
    static ROOT: OnceLock<Option<PathBuf>> = OnceLock::new();
    ROOT.get_or_init(|| std::env::var_os("HXWEB_DOCROOT").map(PathBuf::from)).as_deref()
}

/// fn_escape() outside SQL: the value in single quotes with quotes and backslashes escaped, as the PHP put it into
/// generated JavaScript.
pub fn js_quote(s: &str) -> String {
    let mut out = String::with_capacity(s.len() + 2);
    out.push('\'');
    for c in s.chars() {
        match c {
            '\\' => out.push_str("\\\\"),
            '\'' => out.push_str("\\'"),
            '"' => out.push_str("\\\""),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\0' => out.push_str("\\0"),
            '\x1a' => out.push_str("\\Z"),
            c => out.push(c),
        }
    }
    out.push('\'');
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn attributes_come_out_in_the_php_groups() {
        assert_eq!(hidden("sku", ""), "<input type=hidden name='sku' id='sku'>\n");
        assert_eq!(hidden("action", "subcategory"), "<input type=hidden name='action' id='action' value='subcategory'>\n");
        assert_eq!(
            hidden_class("profile_tab", "", "scscpq_input"),
            "<input type=hidden name='profile_tab' class='scscpq_input' id='profile_tab'>\n"
        );
        assert_eq!(
            button("Add to Cart", &[("class", "cart_button"), ("onclick", "cart_update(this,'a','b');")]),
            "<input type=button class='cart_button' value='Add to Cart' onclick=\"javascript:cart_update(this,'a','b');\">\n"
        );
        assert_eq!(text("q", "", "6", "6", &[("class", "products_quantity")]), "<input type=text class='products_quantity' name='q' id='q' size='6' maxlength='6'>\n");
        assert_eq!(checkbox("c", "1", "0", &[]), "<input type=checkbox name='c' id='c' value='1'>");
        assert_eq!(checkbox("c", "1", "1", &[]), "<input type=checkbox name='c' id='c' value='1' checked>");
    }

    #[test]
    fn selects_mark_the_current_value() {
        let values = vec!["1/4".to_string(), "3/8".to_string()];
        assert_eq!(
            select_list("parameter2", &values, "3/8", &[("onchange", "fn_search_select(this,'0');")]),
            "<select name='parameter2' id='parameter2' onchange=\"javascript:fn_search_select(this,'0');\">\n<option></option>\n<option value='1/4'>1/4</option>\n<option value='3/8' selected>3/8</option>\n</select>\n"
        );
    }

    #[test]
    fn images_and_quoting() {
        assert_eq!(img("/scs_images/a.png", &[("align", "top"), ("width", "450")]).unwrap(), "<img src='/scs_images/a.png' align='top' width='450' border='0'>");
        assert_eq!(img("", &[]), None);
        assert_eq!(js_quote("O'Brien \\ x"), "'O\\'Brien \\\\ x'");
    }
}
