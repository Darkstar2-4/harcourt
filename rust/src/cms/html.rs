//! Escaping as WordPress does it, and the whitespace normalization the static export applied to page bodies.

/// `esc_attr()` / `esc_html()`: `& < > " '` become entities, except an `&` that already starts a valid entity
/// (WordPress does not double-encode).
pub fn esc(s: &str) -> String {
    let mut out = String::with_capacity(s.len() + 8);
    for (i, c) in s.char_indices() {
        match c {
            '&' if starts_entity(&s[i..]) => out.push('&'),
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

/// `&name;`, `&#123;` or `&#x1F;` at the start of `s`.
fn starts_entity(s: &str) -> bool {
    let b = s.as_bytes();
    let body_end = match b.get(1) {
        Some(b'#') => match b.get(2) {
            Some(b'x' | b'X') => 3 + b[3..].iter().take_while(|c| c.is_ascii_hexdigit()).count(),
            _ => 2 + b[2..].iter().take_while(|c| c.is_ascii_digit()).count(),
        },
        Some(c) if c.is_ascii_alphabetic() => 1 + b[1..].iter().take_while(|c| c.is_ascii_alphanumeric()).count(),
        _ => return false,
    };
    let digits = match b.get(1) {
        Some(b'#') if matches!(b.get(2), Some(b'x' | b'X')) => body_end - 3,
        Some(b'#') => body_end - 2,
        _ => body_end - 1,
    };
    digits > 0 && b.get(body_end) == Some(&b';')
}

const BLOCKS: [&str; 39] = [
    "address", "article", "aside", "blockquote", "center", "dd", "details", "dialog", "div", "dl", "dt", "fieldset",
    "figcaption", "figure", "footer", "form", "h1", "h2", "h3", "h4", "h5", "h6", "header", "hgroup", "li", "main",
    "nav", "ol", "p", "section", "summary", "table", "tbody", "td", "tfoot", "th", "thead", "tr", "ul",
];
const SELECT: [&str; 3] = ["select", "optgroup", "option"];
const RAW: [&str; 4] = ["script", "style", "textarea", "title"];

#[derive(Clone, Copy, PartialEq, Eq)]
struct Tag<'a> {
    name: &'a str,
    closing: bool,
}

/// The export's body normalization (export_static.py `pretty`): a newline between two adjacent tags just inside a
/// block element's start or end tag, or between select/option tags. Nothing else changes. Idempotent.
pub fn pretty(html: &str) -> String {
    let mut out = String::with_capacity(html.len() + html.len() / 16);
    let mut prev: Option<Tag> = None;
    let mut pre = 0i32;
    let mut pos = 0;
    let lower = html.to_ascii_lowercase();
    while pos < html.len() {
        let (len, tag) = token(html, pos);
        let text = &html[pos..pos + len];
        if let (Some(t), Some(p)) = (tag, prev) {
            if pre == 0 && at_block_edge(p, t) {
                out.push('\n');
            }
        }
        if let Some(t) = tag {
            if t.name.eq_ignore_ascii_case("pre") {
                pre += if t.closing { -1 } else { 1 };
            }
        }
        out.push_str(text);
        prev = tag;
        pos += len;
        if let Some(t) = tag.filter(|t| !t.closing && RAW.iter().any(|r| t.name.eq_ignore_ascii_case(r))) {
            let close = format!("</{}", t.name.to_ascii_lowercase());
            let end = find_close(&lower, pos, &close).unwrap_or(html.len());
            if end > pos {
                out.push_str(&html[pos..end]);
                prev = None;
            }
            pos = end;
        }
    }
    out
}

/// `</name` followed by optional whitespace and `>`, at or after `from`.
fn find_close(lower: &str, from: usize, close: &str) -> Option<usize> {
    let mut at = from;
    while let Some(i) = lower[at..].find(close) {
        let start = at + i;
        let rest = &lower[start + close.len()..];
        if rest.trim_start().starts_with('>') {
            return Some(start);
        }
        at = start + close.len();
    }
    None
}

fn at_block_edge(prev: Tag, cur: Tag) -> bool {
    let empty = prev.name.eq_ignore_ascii_case(cur.name) && !prev.closing && cur.closing;
    let is = |set: &[&str], n: &str| set.iter().any(|b| b.eq_ignore_ascii_case(n));
    let in_select = is(&SELECT, prev.name)
        && is(&SELECT, cur.name)
        && !(prev.name.eq_ignore_ascii_case("select") && cur.name.eq_ignore_ascii_case("select"));
    !empty && ((is(&BLOCKS, prev.name) && !prev.closing) || (is(&BLOCKS, cur.name) && cur.closing) || in_select)
}

/// The next token at `pos`: a comment, a `<!...>` declaration, a tag, text up to the next `<`, or a lone `<`.
/// Tags come back with their name; comments and declarations as the pseudo-tag "!" (closing).
fn token(html: &str, pos: usize) -> (usize, Option<Tag<'_>>) {
    let rest = &html[pos..];
    let b = rest.as_bytes();
    if b[0] != b'<' {
        return (rest.find('<').unwrap_or(rest.len()), None);
    }
    if let Some(comment) = rest.strip_prefix("<!--") {
        let len = comment.find("-->").map(|i| i + 7).unwrap_or(rest.len());
        return (len, Some(Tag { name: "!", closing: true }));
    }
    if rest.starts_with("<!") {
        let len = rest.find('>').map(|i| i + 1).unwrap_or(rest.len());
        return (len, Some(Tag { name: "!", closing: true }));
    }
    let closing = b.get(1) == Some(&b'/');
    let name_start = if closing { 2 } else { 1 };
    if !b.get(name_start).is_some_and(|c| c.is_ascii_alphabetic()) {
        return (1, None);
    }
    let name_len = b[name_start..].iter().take_while(|c| !c.is_ascii_whitespace() && **c != b'/' && **c != b'>').count();
    let mut i = name_start + name_len;
    let mut quote = None;
    while i < b.len() {
        match (quote, b[i]) {
            (None, b'>') => {
                return (i + 1, Some(Tag { name: &rest[name_start..name_start + name_len], closing }));
            }
            (None, q @ (b'"' | b'\'')) => quote = Some(q),
            (Some(q), c) if c == q => quote = None,
            _ => {}
        }
        i += 1;
    }
    (1, None)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn escapes_like_esc_attr_without_double_encoding() {
        assert_eq!(esc(r#"a & b < "c" 'd'"#), "a &amp; b &lt; &quot;c&quot; &#039;d&#039;");
        assert_eq!(esc("Tool Storage &#038; Transit &amp; &eacute; &#x2122;"), "Tool Storage &#038; Transit &amp; &eacute; &#x2122;");
        assert_eq!(esc("&; &#; &#x; & ;"), "&amp;; &amp;#; &amp;#x; &amp; ;");
    }

    #[test]
    fn pretty_breaks_only_at_block_edges() {
        assert_eq!(pretty("<div><p>a</p><p>b</p></div>"), "<div>\n<p>a</p><p>b</p>\n</div>");
        assert_eq!(pretty("<li><a href=\"x\">y</a></li><li>z</li>"), "<li>\n<a href=\"x\">y</a>\n</li><li>z</li>");
        assert_eq!(pretty("<div></div><span></span>"), "<div></div><span></span>");
        assert_eq!(pretty("<select><option>a</option><option>b</option></select>"), "<select>\n<option>a</option>\n<option>b</option>\n</select>");
        assert_eq!(pretty("<pre><div><b>x</b></div></pre>"), "<pre><div><b>x</b></div></pre>");
        assert_eq!(pretty("<div><script>if(a<b){x='<div><p>'}</script></div>"), "<div>\n<script>if(a<b){x='<div><p>'}</script>\n</div>");
        let once = pretty("<section><div class=\"a\"><h1>t</h1></div></section>");
        assert_eq!(pretty(&once), once);
    }
}
