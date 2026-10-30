//! Markup and links, written the way includes/functions.php writes them (fn_url_query, fn_url, fn_href, fn_base64),
//! except that every interpolated value is escaped. A `&` in an href becomes `&amp;`, which the browser reads back
//! as the same address.

use std::fmt::{self, Write};

use base64::{
    alphabet,
    engine::{general_purpose::STANDARD_NO_PAD, DecodePaddingMode, GeneralPurpose, GeneralPurposeConfig},
    Engine,
};

/// Text made safe for an element body or a quoted attribute value.
pub fn esc(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for c in s.chars() {
        match c {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            '"' => out.push_str("&quot;"),
            '\'' => out.push_str("&#039;"),
            c => out.push(c),
        }
    }
    out
}

/// A single-quoted attribute value as the PHP wrote it, with only what could end the attribute or open a tag
/// escaped. A `&` stays as written, so links read `?a=1&b=2` as they did.
pub fn esc_attr(s: &str) -> String {
    escape_only(s, &['<', '>', '\''])
}

/// The same for a double-quoted attribute: a `'` in it (generated JavaScript) stays as written.
pub fn esc_dq(s: &str) -> String {
    escape_only(s, &['<', '>', '"'])
}

fn escape_only(s: &str, which: &[char]) -> String {
    let mut out = String::with_capacity(s.len());
    for c in s.chars() {
        match c {
            '<' if which.contains(&c) => out.push_str("&lt;"),
            '>' if which.contains(&c) => out.push_str("&gt;"),
            '"' if which.contains(&c) => out.push_str("&quot;"),
            '\'' if which.contains(&c) => out.push_str("&#039;"),
            c => out.push(c),
        }
    }
    out
}

/// An attribute value the PHP wrote without quotes (an iframe's src). Kept bare when nothing in it would end it.
pub fn bare_attr(s: &str) -> String {
    if s.is_empty() || s.contains(|c: char| c.is_whitespace() || "\"'<>`".contains(c)) {
        format!("\"{}\"", esc_dq(s))
    } else {
        s.to_string()
    }
}

/// PHP's urlencode: letters, digits and `-_.` stay, a space becomes `+`, every other byte is `%XX`.
pub fn urlencode(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for b in s.bytes() {
        match b {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' => out.push(b as char),
            b' ' => out.push('+'),
            b => {
                let _ = write!(out, "%{b:02X}");
            }
        }
    }
    out
}

/// A query string in the order its keys were first set, as a PHP array is. Setting a key again keeps its place.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Query(Vec<(String, String)>);

impl Query {
    pub fn new() -> Self {
        Query(Vec::new())
    }

    pub fn set(&mut self, key: &str, value: impl Into<String>) -> &mut Self {
        let value = value.into();
        match self.0.iter_mut().find(|(k, _)| k == key) {
            Some(entry) => entry.1 = value,
            None => self.0.push((key.to_string(), value)),
        }
        self
    }

    pub fn with(mut self, key: &str, value: impl Into<String>) -> Self {
        self.set(key, value);
        self
    }

    pub fn remove(&mut self, key: &str) -> &mut Self {
        self.0.retain(|(k, _)| k != key);
        self
    }

    pub fn pairs(&self) -> &[(String, String)] {
        &self.0
    }

    pub fn get(&self, key: &str) -> Option<&str> {
        self.0.iter().find(|(k, _)| k == key).map(|(_, v)| v.as_str())
    }

    /// fn_url_query: keys as given, values urlencoded, empty values left out. Empty when nothing is left.
    pub fn encoded(&self) -> String {
        let mut out = String::new();
        for (k, v) in self.0.iter().filter(|(_, v)| !v.is_empty()) {
            out.push(if out.is_empty() { '?' } else { '&' });
            out.push_str(k);
            out.push('=');
            out.push_str(&urlencode(v));
        }
        out
    }
}

impl fmt::Display for Query {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.encoded())
    }
}

/// fn_url for a site path: the path, then the query (a query already on the path merges under the new keys), then
/// any fragment.
pub fn url(page: &str, query: &Query) -> String {
    let (rest, fragment) = match page.split_once('#') {
        Some((r, f)) => (r, Some(f)),
        None => (page, None),
    };
    let (path, own) = match rest.split_once('?') {
        Some((p, q)) => (p, Some(q)),
        None => (rest, None),
    };
    let mut merged = Query::new();
    for pair in own.unwrap_or("").split('&').filter(|p| !p.is_empty()) {
        let (k, v) = pair.split_once('=').unwrap_or((pair, ""));
        merged.set(&form_decode(k), form_decode(v));
    }
    for (k, v) in &query.0 {
        merged.set(k, v.clone());
    }
    let mut out = format!("{path}{}", merged.encoded());
    if let Some(f) = fragment {
        out.push('#');
        out.push_str(f);
    }
    out
}

fn form_decode(s: &str) -> String {
    percent_encoding::percent_decode_str(&s.replace('+', " ")).decode_utf8_lossy().into_owned()
}

/// fn_href: a link whose label is already markup.
pub fn href(label_html: &str, url: &str) -> String {
    format!("<a href=\"{}\" >{label_html}</a>", esc_dq(url))
}

/// fn_href with element attributes after the href, in the order given; attributes with empty values are left out.
pub fn href_with(label_html: &str, url: &str, attrs: &[(&str, &str)]) -> String {
    format!("<a href=\"{}\" {}>{label_html}</a>", esc_dq(url), attributes(attrs).trim_start())
}

/// ` name='value'` for each non-empty value, single-quoted as forms_rev2.php writes them.
pub fn attributes(attrs: &[(&str, &str)]) -> String {
    let mut out = String::new();
    for (name, value) in attrs.iter().filter(|(_, v)| !v.is_empty()) {
        let _ = write!(out, " {name}='{}'", esc_attr(value));
    }
    out
}

/// fn_base64 for the values the catalog puts in links and field names: tab-joined, standard alphabet, no padding.
pub fn base64_fields(fields: &[&str]) -> String {
    STANDARD_NO_PAD.encode(fields.join("\t"))
}

/// fn_base64($x, FALSE): lenient as PHP's base64_decode is (stray characters and missing padding are ignored).
/// Fields are tab-separated; a value with no tab is one field. None when the bytes are not text.
pub fn base64_fields_decode(s: &str) -> Option<Vec<String>> {
    const LENIENT: GeneralPurpose =
        GeneralPurpose::new(&alphabet::STANDARD, GeneralPurposeConfig::new().with_decode_padding_mode(DecodePaddingMode::Indifferent));
    let clean: String = s.chars().filter(|c| c.is_ascii_alphanumeric() || *c == '+' || *c == '/').collect();
    let usable = match clean.len() % 4 {
        1 => &clean[..clean.len() - 1],
        _ => clean.as_str(),
    };
    let bytes = LENIENT.decode(usable).ok()?;
    let text = String::from_utf8(bytes).ok()?;
    Some(text.split('\t').map(str::to_string).collect())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn escapes_markup_and_quotes() {
        assert_eq!(esc(r#"<a href="x">Tom's & Co</a>"#), "&lt;a href=&quot;x&quot;&gt;Tom&#039;s &amp; Co&lt;/a&gt;");
    }

    #[test]
    fn urlencode_matches_php() {
        assert_eq!(urlencode("HBLT-3-0.50-S"), "HBLT-3-0.50-S");
        assert_eq!(urlencode("a b/c~d|é"), "a+b%2Fc%7Ed%7C%C3%A9");
    }

    #[test]
    fn query_keeps_first_position_and_drops_empty_values() {
        let mut q = Query::new();
        q.set("lang", "en").set("action", "sku").set("sku", "").set("scs_page", "0").set("lang", "fr");
        assert_eq!(q.encoded(), "?lang=fr&action=sku&scs_page=0");
        q.remove("lang");
        assert_eq!(q.to_string(), "?action=sku&scs_page=0");
        assert_eq!(Query::new().with("x", "").encoded(), "");
    }

    #[test]
    fn url_merges_the_page_query_and_keeps_the_fragment() {
        let q = Query::new().with("action", "subcategory").with("subcategory_id", "731");
        assert_eq!(url("/catalog/", &q), "/catalog/?action=subcategory&subcategory_id=731");
        assert_eq!(url("/catalog/?lang=fr&action=x", &q), "/catalog/?lang=fr&action=subcategory&subcategory_id=731");
        assert_eq!(url("/catalog/#category3", &Query::new()), "/catalog/#category3");
        assert_eq!(url("/x?a=b+c", &Query::new()), "/x?a=b+c");
    }

    #[test]
    fn links_escape_their_address() {
        assert_eq!(href("<b>X</b>", "/catalog/?a=1&b=2"), "<a href=\"/catalog/?a=1&b=2\" ><b>X</b></a>");
        assert_eq!(href("x", "/c?q=\"><script>"), "<a href=\"/c?q=&quot;&gt;&lt;script&gt;\" >x</a>");
        assert_eq!(href_with("Go", "/c", &[("class", "pill active"), ("title", "")]), "<a href=\"/c\" class='pill active'>Go</a>");
        assert_eq!(bare_attr("https://x/?a=1&b=2"), "https://x/?a=1&b=2");
        assert_eq!(bare_attr("a b"), "\"a b\"");
    }

    #[test]
    fn base64_round_trips_like_fn_base64() {
        assert_eq!(base64_fields(&["sku", "HBLT-3"]), "c2t1CUhCTFQtMw");
        assert_eq!(base64_fields_decode("c2t1CUhCTFQtMw"), Some(vec!["sku".to_string(), "HBLT-3".to_string()]));
        assert_eq!(base64_fields_decode("SEJMVC0z"), Some(vec!["HBLT-3".to_string()]));
        assert_eq!(base64_fields_decode("SEJMVC0z=="), Some(vec!["HBLT-3".to_string()]));
        assert_eq!(base64_fields_decode("/w"), None);
    }
}
