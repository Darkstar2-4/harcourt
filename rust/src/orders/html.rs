//! HTML for the orders pages: plain functions that write strings. Every interpolated value goes through `esc`.

/// Escapes text for an HTML element or a quoted attribute (either quote style).
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

#[cfg(test)]
mod tests {
    #[test]
    fn escapes_markup_and_both_quotes() {
        assert_eq!(super::esc(r#"<a href="x" title='y'>&</a>"#), "&lt;a href=&quot;x&quot; title=&#039;y&#039;&gt;&amp;&lt;/a&gt;");
    }
}
