//! Input generators: random text and bytes, and structure-aware shapes (query strings, paths, cookies) built from
//! ordinary parts and then varied. Nothing here is aimed at a weakness; the shapes are what browsers and forms send.

use proptest::{prelude::*, sample::select};

/// Printable ASCII, the controls a form can carry, and a few characters of every UTF-8 length.
pub const SOUP: &str = " !\"#$%&'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~\t\n\r\u{0}\u{7f}éÉ€日\u{feff}\u{a0}😀";

/// What appears in URLs: unreserved and reserved characters, percent signs and hex digits.
pub const URLISH: &str = "abcxyzABC019-_.~%%%2F5Ee+&&==[]?#/;:@!$'()*, ";

/// Query and form field names the pages read.
pub const KEYS: &[&str] = &[
    "lang", "hxlang", "s", "p", "page_id", "page", "name", "cat", "m", "feed", "debug", "scscpq_wp", "campaign", "id", "action", "csrf",
    "search", "q", "document", "category_id", "subcategory_id", "inventory_id", "user_id", "email", "code", "gf_token", "rest_route",
    "utm_source", "content_id", "watermark", "sort", "order", "rows", "limit", "offset", "type", "status", "language", "currency", "x",
];

pub fn text_of(alphabet: &'static str, len: std::ops::Range<usize>) -> impl Strategy<Value = String> {
    let chars: Vec<char> = alphabet.chars().collect();
    proptest::collection::vec(select(chars), len).prop_map(|v| v.into_iter().collect())
}

/// Any text: arbitrary code points, or the soup, which hits the characters parsers care about far more often.
pub fn text(max: usize) -> impl Strategy<Value = String> {
    prop_oneof![
        proptest::collection::vec(any::<char>(), 0..max).prop_map(|v| v.into_iter().collect::<String>()),
        text_of(SOUP, 0..max),
        text_of(URLISH, 0..max),
    ]
}

pub fn bytes(max: usize) -> impl Strategy<Value = Vec<u8>> {
    proptest::collection::vec(any::<u8>(), 0..max)
}

/// A short piece repeated up to `max` times: long inputs, deep nesting and many fields at little generation cost.
pub fn repeated(piece: impl Strategy<Value = String>, max: usize) -> impl Strategy<Value = String> {
    (piece, 0..=max).prop_map(|(p, n)| p.repeat(n))
}

/// `%XX` for a random byte (not always valid UTF-8 once decoded), or a broken escape.
pub fn escape() -> impl Strategy<Value = String> {
    prop_oneof![
        4 => any::<u8>().prop_map(|b| format!("%{b:02X}")),
        1 => any::<u8>().prop_map(|b| format!("%{b:02x}")),
        1 => Just("%".to_string()),
        1 => text_of("0123456789abcdefgxyzG%", 1..3).prop_map(|s| format!("%{s}")),
    ]
}

/// URL-ish text with escapes mixed in.
pub fn url_text(max_pieces: usize) -> impl Strategy<Value = String> {
    proptest::collection::vec(prop_oneof![3 => text_of(URLISH, 0..6), 1 => escape(), 1 => text_of(SOUP, 0..3)], 0..max_pieces)
        .prop_map(|v| v.concat())
}

pub fn key() -> impl Strategy<Value = String> {
    prop_oneof![3 => select(KEYS).prop_map(str::to_string), 1 => url_text(3), 1 => text_of("abc_. []", 1..6)]
}

/// One `name[sub][..]=value` pair of a query or form body, sometimes without `=`.
pub fn pair() -> impl Strategy<Value = String> {
    let subs = proptest::collection::vec(text_of("abc01_", 0..3).prop_map(|s| format!("[{s}]")), 0..3);
    (key(), subs, proptest::option::weighted(0.85, url_text(4))).prop_map(|(k, subs, v)| match v {
        Some(v) => format!("{k}{}={v}", subs.concat()),
        None => format!("{k}{}", subs.concat()),
    })
}

/// A query string or urlencoded body: ordinary pairs, now and then an empty one or a repeat.
pub fn query() -> impl Strategy<Value = String> {
    prop_oneof![
        6 => proptest::collection::vec(pair(), 0..10).prop_map(|v| v.join("&")),
        1 => (pair(), 0..2000usize).prop_map(|(p, n)| vec![p; n].join("&")),
        1 => url_text(30),
    ]
}

/// A path as a browser sends it: segments of URL-ish text, sometimes doubled slashes, dots or a trailing slash.
pub fn path() -> impl Strategy<Value = String> {
    let segment = prop_oneof![4 => text_of("abcdefxyz0123456789-_", 1..10), 1 => url_text(3), 1 => Just(".".to_string()), 1 => Just("".to_string())];
    (proptest::collection::vec(segment, 0..6), any::<bool>()).prop_map(|(segs, slash)| {
        let mut p = format!("/{}", segs.join("/"));
        if slash && !p.ends_with('/') {
            p.push('/');
        }
        p
    })
}
