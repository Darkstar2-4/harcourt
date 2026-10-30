//! WordPress's wptexturize() (wp-includes/formatting.php): curly quotes, apostrophes, primes, dashes and the
//! ellipsis in the text between tags, with the locale's quote characters. Each of WordPress's patterns is one
//! left-to-right pass over the text as the previous pass left it, as preg_replace() applied its pattern array.

use crate::cms::Lang as Locale;

const APOS_FLAG: &str = "<!--apos-->";
const OPEN_SQ_FLAG: &str = "<!--osq-->";
const OPEN_Q_FLAG: &str = "<!--oq-->";
const PRIME_FLAG: &str = "<!--wp-prime-or-quote-->";
const NO_TEXTURIZE: [&str; 6] = ["pre", "code", "kbd", "style", "script", "tt"];

/// The characters wptexturize() writes, per locale (WordPress's fr_FR translations for French).
pub struct Marks {
    opening_quote: &'static str,
    closing_quote: &'static str,
    apos: &'static str,
    prime: &'static str,
    double_prime: &'static str,
    opening_single: &'static str,
    closing_single: &'static str,
}

pub fn marks(locale: Locale) -> Marks {
    match locale {
        Locale::En => Marks {
            opening_quote: "&#8220;",
            closing_quote: "&#8221;",
            apos: "&#8217;",
            prime: "&#8242;",
            double_prime: "&#8243;",
            opening_single: "&#8216;",
            closing_single: "&#8217;",
        },
        Locale::Fr => Marks {
            opening_quote: "«\u{a0}",
            closing_quote: "\u{a0}»",
            apos: "&rsquo;",
            prime: "&prime;",
            double_prime: "&Prime;",
            opening_single: "&lsquo;",
            closing_single: "&rsquo;",
        },
    }
}

/// wp_spaces_regexp(): a space before `i`.
fn space_before(s: &str, i: usize) -> bool {
    let b = &s[..i];
    b.ends_with([' ', '\t', '\r', '\n', '\u{a0}']) || b.ends_with("&nbsp;")
}

/// A space starting at `i`.
fn space_at(s: &str, i: usize) -> bool {
    let r = &s[i..];
    r.starts_with([' ', '\t', '\r', '\n', '\u{a0}']) || r.starts_with("&nbsp;")
}

/// PCRE's `\Z`: the end, or a final newline.
fn at_end(s: &str, i: usize) -> bool {
    i == s.len() || (i + 1 == s.len() && s.as_bytes()[i] == b'\n')
}

/// `(?=\Z|[.,:;!?)}\-\]]|&gt;|spaces)`: what may follow a closing quote.
fn closes_at(s: &str, i: usize) -> bool {
    at_end(s, i) || s[i..].starts_with(['.', ',', ':', ';', '!', '?', ')', '}', '-', ']']) || s[i..].starts_with("&gt;") || space_at(s, i)
}

fn digit_at(s: &str, i: usize) -> bool {
    s.as_bytes().get(i).is_some_and(u8::is_ascii_digit)
}

fn digit_before(s: &str, i: usize) -> bool {
    i > 0 && s.as_bytes()[i - 1].is_ascii_digit()
}

/// One preg_replace() pass: at each position, `f` gives the length matched and its replacement.
fn pass(s: &str, mut f: impl FnMut(&str, usize) -> Option<(usize, String)>) -> String {
    let mut out = String::with_capacity(s.len() + 16);
    let mut i = 0;
    while i < s.len() {
        if let Some((len, rep)) = f(s, i) {
            out.push_str(&rep);
            i += len.max(1);
            continue;
        }
        let c = s[i..].chars().next().unwrap_or(' ');
        out.push(c);
        i += c.len_utf8();
    }
    out
}

/// `\d[.,\d]*` at `i`: its length.
fn number_at(s: &str, i: usize) -> Option<usize> {
    if !digit_at(s, i) {
        return None;
    }
    Some(1 + s.as_bytes()[i + 1..].iter().take_while(|b| b.is_ascii_digit() || **b == b'.' || **b == b',').count())
}

fn apostrophes(s: &str, m: &Marks) -> String {
    let two_digits = |s: &str, i: usize| digit_at(s, i) && digit_at(s, i + 1);
    let s = pass(s, |s, i| (s[i..].starts_with('\'') && two_digits(s, i + 1) && s[i + 3..].starts_with('\'') && closes_at(s, i + 4)).then(|| (4, format!("{APOS_FLAG}{}{}", &s[i + 1..i + 3], m.closing_single))));
    let s = pass(&s, |s, i| (s[i..].starts_with('\'') && two_digits(s, i + 1) && s[i + 3..].starts_with('"') && closes_at(s, i + 4)).then(|| (4, format!("{APOS_FLAG}{}{}", &s[i + 1..i + 3], m.closing_quote))));
    let s = pass(&s, |s, i| {
        let next = |k: usize| s.as_bytes().get(i + 3 + k).copied();
        let ok = s[i..].starts_with('\'') && two_digits(s, i + 1) && (at_end(s, i + 3) || !(matches!(next(0), Some(b'%' | b'0'..=b'9')) || (matches!(next(0), Some(b'.' | b',')) && next(1).is_some_and(|b| b.is_ascii_digit()))));
        ok.then(|| (1, APOS_FLAG.to_string()))
    });
    let s = pass(&s, |s, i| {
        if !s[i..].starts_with('\'') || !(i == 0 || space_before(s, i)) {
            return None;
        }
        let n = number_at(s, i + 1)?;
        s[i + 1 + n..].starts_with('\'').then(|| (n + 2, format!("{OPEN_SQ_FLAG}{}{}", &s[i + 1..i + 1 + n], m.closing_single)))
    });
    let s = pass(&s, |s, i| {
        let before = i == 0 || s[..i].ends_with(['(', '[', '{', '"', '-']) || s[..i].ends_with("&lt;") || space_before(s, i);
        (s[i..].starts_with('\'') && before).then(|| (1, OPEN_SQ_FLAG.to_string()))
    });
    let s = pass(&s, |s, i| {
        if !s[i..].starts_with('\'') || space_before(s, i) {
            return None;
        }
        let r = &s[i + 1..];
        let after = at_end(s, i + 1)
            || r.starts_with(['.', ',', ':', ';', '!', '?', '"', '\'', '(', ')', '{', '}', '[', ']', '-'])
            || r.starts_with("&lt;")
            || r.starts_with("&gt;")
            || space_at(s, i + 1);
        (!after).then(|| (1, APOS_FLAG.to_string()))
    });
    let s = primes(&s, "'", m.prime, OPEN_SQ_FLAG, m.closing_single);
    s.replace(APOS_FLAG, m.apos).replace(OPEN_SQ_FLAG, m.opening_single)
}

fn quotes(s: &str, m: &Marks) -> String {
    let s = pass(s, |s, i| {
        if !s[i..].starts_with('"') || !(i == 0 || space_before(s, i)) {
            return None;
        }
        let n = number_at(s, i + 1)?;
        s[i + 1 + n..].starts_with('"').then(|| (n + 2, format!("{OPEN_Q_FLAG}{}{}", &s[i + 1..i + 1 + n], m.closing_quote)))
    });
    let s = pass(&s, |s, i| {
        let before = i == 0 || s[..i].ends_with(['(', '[', '{', '-']) || s[..i].ends_with("&lt;") || space_before(s, i);
        (s[i..].starts_with('"') && before && !space_at(s, i + 1)).then(|| (1, OPEN_Q_FLAG.to_string()))
    });
    let s = primes(&s, "\"", m.double_prime, OPEN_Q_FLAG, m.closing_quote);
    s.replace(OPEN_Q_FLAG, m.opening_quote)
}

/// wptexturize_primes(): a quote after a digit is a prime unless it closes a quotation opened before it.
fn primes(haystack: &str, needle: &str, prime: &str, open: &str, close: &str) -> String {
    let quote = |s: &str, rep: &str| -> (String, usize) {
        let mut n = 0;
        let out = pass(s, |s, i| {
            (s[i..].starts_with(needle) && closes_at(s, i + needle.len())).then(|| {
                n += 1;
                (needle.len(), rep.to_string())
            })
        });
        (out, n)
    };
    let primed = |s: &str| pass(s, |s, i| (s[i..].starts_with(needle) && digit_before(s, i)).then(|| (needle.len(), prime.to_string())));
    let sentences: Vec<String> = haystack
        .split(open)
        .enumerate()
        .map(|(key, sentence)| {
            if !sentence.contains(needle) {
                return sentence.to_string();
            }
            let mut sentence = if key != 0 && !sentence.contains(close) {
                let (flagged, count) = quote(sentence, PRIME_FLAG);
                match count {
                    0 => primed(&flagged),
                    1 => primed(&flagged.replace(PRIME_FLAG, close)),
                    _ => {
                        let mut count2 = 0;
                        let mut s = pass(&flagged, |s, i| {
                            (s[i..].starts_with(PRIME_FLAG) && !digit_before(s, i)).then(|| {
                                count2 += 1;
                                (PRIME_FLAG.len(), close.to_string())
                            })
                        });
                        if count2 == 0 {
                            let pos = s.rfind(&format!("{PRIME_FLAG}.")).or_else(|| s.rfind(PRIME_FLAG));
                            if let Some(pos) = pos {
                                s.replace_range(pos..pos + PRIME_FLAG.len(), close);
                            }
                        }
                        let s = primed(&s);
                        let s = pass(&s, |s, i| (s[i..].starts_with(PRIME_FLAG) && digit_before(s, i)).then(|| (PRIME_FLAG.len(), prime.to_string())));
                        s.replace(PRIME_FLAG, close)
                    }
                }
            } else {
                quote(&primed(sentence), close).0
            };
            if needle == "\"" && sentence.contains('"') {
                sentence = sentence.replace('"', close);
            }
            sentence
        })
        .collect();
    sentences.join(open)
}

fn dashes(s: &str) -> String {
    let start_or_space = |s: &str, i: usize| i == 0 || space_before(s, i);
    let end_or_space = |s: &str, i: usize| at_end(s, i) || space_at(s, i);
    let s = s.replace("---", "&#8212;");
    let s = pass(&s, |s, i| (s[i..].starts_with("--") && start_or_space(s, i) && end_or_space(s, i + 2)).then(|| (2, "&#8212;".to_string())));
    let s = pass(&s, |s, i| (s[i..].starts_with("--") && !s[..i].ends_with("xn")).then(|| (2, "&#8211;".to_string())));
    pass(&s, |s, i| (s[i..].starts_with('-') && start_or_space(s, i) && end_or_space(s, i + 1)).then(|| (1, "&#8211;".to_string())))
}

fn word_byte(b: u8) -> bool {
    b.is_ascii_alphanumeric() || b == b'_'
}

/// `9x9` as a multiplication sign, never `0x9999`.
fn times(s: &str) -> String {
    let b = s.as_bytes();
    if !(1..b.len().saturating_sub(1)).any(|i| b[i] == b'x' && b[i - 1].is_ascii_digit() && b[i + 1].is_ascii_digit()) {
        return s.to_string();
    }
    let run = |from: usize| b[from..].iter().take_while(|c| c.is_ascii_digit() || **c == b'.' || **c == b',').count();
    pass(s, |s, i| {
        let b = s.as_bytes();
        if !b[i].is_ascii_digit() || (i > 0 && word_byte(b[i - 1])) {
            return None;
        }
        let first = 1 + run(i + 1);
        if b[i] == b'0' && first == 1 {
            return None;
        }
        let x = i + first;
        if b.get(x) != Some(&b'x') || !b.get(x + 1).is_some_and(u8::is_ascii_digit) {
            return None;
        }
        let longest = 1 + run(x + 2);
        (1..=longest).rev().find(|&n| {
            let end = x + 1 + n;
            word_byte(b[end - 1]) != b.get(end).is_some_and(|c| word_byte(*c))
        })
        .map(|n| (first + 1 + n, format!("{}&#215;{}", &s[i..x], &s[x + 1..x + 1 + n])))
    })
}

/// A `&` that does not start an entity, as `&#038;`.
pub fn ampersands(s: &str) -> String {
    bare_ampersands(s, "&#038;")
}

/// Each `&` that does not start an entity, as `rep`.
pub fn bare_ampersands(s: &str, rep: &str) -> String {
    pass(s, |s, i| {
        if !s[i..].starts_with('&') {
            return None;
        }
        let r = &s.as_bytes()[i + 1..];
        let numeric = r.first() == Some(&b'#') && {
            let hex = r.get(1).is_some_and(|c| *c == b'x' || *c == b'X');
            let digits = r[if hex { 2 } else { 1 }..].iter().take_while(|c| if hex { c.is_ascii_hexdigit() } else { c.is_ascii_digit() }).count();
            digits > 0 && r.get(if hex { 2 } else { 1 } + digits) == Some(&b';')
        };
        let named = {
            let n = r.iter().take_while(|c| c.is_ascii_alphabetic() || (b'1'..=b'4').contains(*c)).count();
            (1..=8).contains(&n) && r.get(n) == Some(&b';')
        };
        (!numeric && !named).then(|| (1, rep.to_string()))
    })
}

/// The text between delimiters, texturized.
fn text(t: &str, m: &Marks) -> String {
    let mut t = t.replace("...", "&#8230;").replace("``", m.opening_quote).replace("''", m.closing_quote).replace(" (tm)", " &#8482;");
    if t.contains('\'') {
        t = apostrophes(&t, m);
    }
    if t.contains('"') {
        t = quotes(&t, m);
    }
    if t.contains('-') {
        t = dashes(&t);
    }
    ampersands(&times(&t))
}

/// wptexturize(): tags and comments pass through (a tag's bare `&` as `&#038;`), text inside pre, code, kbd,
/// style, script and tt is left alone.
pub fn texturize(s: &str, locale: Locale) -> String {
    if s.is_empty() {
        return String::new();
    }
    let m = marks(locale);
    let mut out = String::with_capacity(s.len() + 64);
    let mut stack: Vec<String> = Vec::new();
    let mut rest = s;
    while !rest.is_empty() {
        let (piece, tail) = if rest.starts_with('<') {
            let end = if rest.starts_with("<!--") { rest.find("-->").map(|e| e + 3) } else { rest.find('>').map(|e| e + 1) };
            rest.split_at(end.unwrap_or(rest.len()))
        } else {
            rest.split_at(rest.find('<').unwrap_or(rest.len()))
        };
        rest = tail;
        if piece.starts_with("<!--") {
            out.push_str(piece);
        } else if piece.starts_with('<') {
            out.push_str(&ampersands(piece));
            push_pop(piece, &mut stack);
        } else if piece.trim().is_empty() || !stack.is_empty() {
            out.push_str(piece);
        } else {
            out.push_str(&text(piece, &m));
        }
    }
    out
}

/// _wptexturize_pushpop_element(): open and close the no-texturize elements.
fn push_pop(tag: &str, stack: &mut Vec<String>) {
    let closing = tag.as_bytes().get(1) == Some(&b'/');
    if closing && stack.is_empty() {
        return;
    }
    let offset = if closing { 2 } else { 1 };
    let name = &tag[offset..tag.find(' ').unwrap_or(tag.len() - 1).max(offset)];
    if !NO_TEXTURIZE.contains(&name) {
        return;
    }
    if !closing {
        stack.push(name.to_string());
    } else if stack.last().map(String::as_str) == Some(name) {
        stack.pop();
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn en(s: &str) -> String {
        texturize(s, Locale::En)
    }

    #[test]
    fn quotes_and_apostrophes_as_wordpress_curls_them() {
        assert_eq!(en("you're"), "you&#8217;re");
        assert_eq!(en("provided 'as is' and 'as available'"), "provided &#8216;as is&#8217; and &#8216;as available&#8217;");
        assert_eq!(en("He said \"hello\" to me."), "He said &#8220;hello&#8221; to me.");
        assert_eq!(en("a 9' pole and 9\" pipe"), "a 9&#8242; pole and 9&#8243; pipe");
        assert_eq!(en("back in '99 we"), "back in &#8217;99 we");
        assert_eq!(texturize("d'outillage", Locale::Fr), "d&rsquo;outillage");
        assert_eq!(texturize("fournis \" tels quels \" et", Locale::Fr), "fournis \u{a0}» tels quels \u{a0}» et");
    }

    #[test]
    fn dashes_ellipses_times_and_ampersands() {
        assert_eq!(en("Harcourt - Magazine"), "Harcourt &#8211; Magazine");
        assert_eq!(en("a -- b --- c"), "a &#8212; b &#8212; c");
        assert_eq!(en("wait..."), "wait&#8230;");
        assert_eq!(en("a 2x4 board, 0x9999"), "a 2&#215;4 board, 0x9999");
        assert_eq!(en("R&D &amp; more"), "R&#038;D &amp; more");
    }

    #[test]
    fn markup_and_code_are_left_alone() {
        assert_eq!(en("<p class='x'>it's</p>"), "<p class='x'>it&#8217;s</p>");
        assert_eq!(en("<code>it's</code> it's"), "<code>it's</code> it&#8217;s");
        assert_eq!(en("<!-- it's -->"), "<!-- it's -->");
    }
}
