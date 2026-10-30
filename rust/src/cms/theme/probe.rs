//! Recovering field values from a page WordPress rendered. A template run in probe mode writes a sentinel for every
//! value it prints and real markup for everything else; matching that skeleton against the page WordPress served
//! yields each value as the page showed it, and proves the template reproduces the rest byte for byte.

use super::Slot;

const OPEN: char = '\u{E000}';
const CLOSE: char = '\u{E001}';

pub fn sentinel(index: usize) -> String {
    format!("{OPEN}{index}{CLOSE}")
}

#[derive(Debug, PartialEq, Eq)]
pub struct Mismatch {
    /// Byte offset in the target where the skeleton stopped matching.
    pub at: usize,
    pub expected: String,
    pub found: String,
}

impl std::fmt::Display for Mismatch {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "at byte {}: expected {:?}, found {:?}", self.at, self.expected, self.found)
    }
}

enum Piece<'a> {
    Text(&'a str),
    Hole(usize),
}

fn pieces(skeleton: &str) -> Vec<Piece<'_>> {
    let mut out = Vec::new();
    let mut rest = skeleton;
    while let Some(start) = rest.find(OPEN) {
        let after = &rest[start + OPEN.len_utf8()..];
        let Some(end) = after.find(CLOSE) else { break };
        let Ok(index) = after[..end].parse() else { break };
        if start > 0 {
            out.push(Piece::Text(&rest[..start]));
        }
        out.push(Piece::Hole(index));
        rest = &after[end + CLOSE.len_utf8()..];
    }
    if !rest.is_empty() {
        out.push(Piece::Text(rest));
    }
    out
}

fn snippet(s: &str, at: usize, len: usize) -> String {
    let mut end = (at + len).min(s.len());
    while !s.is_char_boundary(end) {
        end -= 1;
    }
    let mut start = at.min(end);
    while !s.is_char_boundary(start) {
        start -= 1;
    }
    s[start..end].to_string()
}

/// Matches `skeleton` against `target`; returns each hole's text by hole index. A hole takes the shortest text that
/// lets the next literal piece match; two holes with no literal between them are refused as ambiguous.
pub fn extract(skeleton: &str, target: &str) -> Result<Vec<(usize, String)>, Mismatch> {
    let pieces = pieces(skeleton);
    let mut out = Vec::new();
    let mut pos = 0;
    let mut open: Option<usize> = None;
    for (i, piece) in pieces.iter().enumerate() {
        match piece {
            Piece::Hole(h) => {
                if open.is_some() {
                    return Err(Mismatch { at: pos, expected: "a literal between two values".into(), found: snippet(target, pos, 80) });
                }
                open = Some(*h);
                if i == pieces.len() - 1 {
                    out.push((*h, target[pos..].to_string()));
                    pos = target.len();
                    open = None;
                }
            }
            Piece::Text(text) => match open.take() {
                Some(h) => {
                    let Some(found) = target[pos..].find(text) else {
                        return Err(Mismatch { at: pos, expected: snippet(text, 0, 120), found: snippet(target, pos, 160) });
                    };
                    out.push((h, target[pos..pos + found].to_string()));
                    pos += found + text.len();
                }
                None => {
                    if !target[pos..].starts_with(text) {
                        let common = text.bytes().zip(target[pos..].bytes()).take_while(|(a, b)| a == b).count();
                        let at = pos + common;
                        return Err(Mismatch { at, expected: snippet(text, common, 120), found: snippet(target, at, 120) });
                    }
                    pos += text.len();
                }
            },
        }
    }
    if pos != target.len() {
        return Err(Mismatch { at: pos, expected: "end of page".into(), found: snippet(target, pos, 120) });
    }
    Ok(out)
}

/// The value each slot took, checking that a slot printed in several places showed the same text everywhere.
pub fn values(slots: &[Slot], captured: Vec<(usize, String)>) -> Result<Vec<(Slot, String)>, String> {
    let mut out: Vec<(Slot, String)> = Vec::new();
    for (index, text) in captured {
        let slot = slots.get(index).ok_or_else(|| format!("hole {index} has no slot"))?.clone();
        match out.iter().find(|(s, _)| *s == slot) {
            Some((_, seen)) if *seen != text => return Err(format!("{slot:?} shows {seen:?} in one place and {text:?} in another")),
            Some(_) => {}
            None => out.push((slot, text)),
        }
    }
    Ok(out)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn skel(parts: &[&str]) -> String {
        parts.iter().map(|p| p.strip_prefix('#').map(|n| sentinel(n.parse().unwrap())).unwrap_or_else(|| p.to_string())).collect()
    }

    #[test]
    fn captures_values_between_literals() {
        let s = skel(&["<h1>", "#0", "</h1><p>", "#1", "</p>"]);
        assert_eq!(extract(&s, "<h1>Title &amp; more</h1><p></p>").unwrap(), vec![(0, "Title &amp; more".into()), (1, String::new())]);
        let tail = skel(&["<div>", "#0"]);
        assert_eq!(extract(&tail, "<div>rest of it").unwrap(), vec![(0, "rest of it".into())]);
    }

    #[test]
    fn reports_where_the_template_diverges() {
        let s = skel(&["<h1 class=\"a\">", "#0", "</h1>"]);
        let e = extract(&s, "<h1 class=\"b\">x</h1>").unwrap_err();
        assert_eq!(e.at, 11);
        assert!(extract(&skel(&["<p>", "#0", "#1", "</p>"]), "<p>ab</p>").is_err());
        assert!(extract(&skel(&["<p>", "#0", "</p>"]), "<p>x</p>extra").is_err());
    }

    #[test]
    fn a_slot_must_show_the_same_text_everywhere() {
        let slots = vec![Slot::Option(crate::cms::Lang::En, "a".into()), Slot::Option(crate::cms::Lang::En, "a".into())];
        assert!(values(&slots, vec![(0, "x".into()), (1, "x".into())]).unwrap().len() == 1);
        assert!(values(&slots, vec![(0, "x".into()), (1, "y".into())]).is_err());
    }
}
