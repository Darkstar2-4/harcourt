use hxweb::{accounts, catalog, cms, orders, staff};
use hxweb_fuzz::{config, gen, within, PURE};
use proptest::prelude::*;

/// The five entities the full escapers write, decoded.
fn unescape(s: &str) -> String {
    s.replace("&lt;", "<").replace("&gt;", ">").replace("&quot;", "\"").replace("&#039;", "'").replace("&amp;", "&")
}

/// A JavaScript string literal's body read back: `\\`, `\'`, `\"`, `\n`, `\r`, `\0`, `\Z` (fn_escape's), `\xNN`
/// and `\uNNNN`.
fn js_unescape(lit: &str) -> Option<String> {
    let body = lit.strip_prefix('\'')?.strip_suffix('\'')?;
    let mut out = String::new();
    let mut chars = body.chars();
    while let Some(c) = chars.next() {
        if c != '\\' {
            if c == '\'' {
                return None;
            }
            out.push(c);
            continue;
        }
        match chars.next()? {
            'n' => out.push('\n'),
            'r' => out.push('\r'),
            '0' => out.push('\0'),
            'Z' => out.push('\x1a'),
            'x' => out.push(char::from_u32(u32::from_str_radix(&chars.by_ref().take(2).collect::<String>(), 16).ok()?)?),
            'u' => out.push(char::from_u32(u32::from_str_radix(&chars.by_ref().take(4).collect::<String>(), 16).ok()?)?),
            c => out.push(c),
        }
    }
    Some(out)
}

/// One tag and nothing else: the value cannot have closed it or opened another.
fn one_tag(html: &str) -> bool {
    let t = html.trim_end();
    t.starts_with('<') && t.ends_with('>') && t.matches('<').count() == 1 && t.matches('>').count() == 1
}

proptest! {
    #![proptest_config(config(2048))]

    #[test]
    fn full_escapers_leave_no_markup_and_read_back(s in gen::text(200)) {
        for (name, esc) in [
            ("orders", orders::html::esc as fn(&str) -> String),
            ("catalog", catalog::html::esc),
            ("staff", staff::html::esc),
            ("accounts", accounts::html::escape),
        ] {
            let out = within(PURE, name, || esc(&s));
            prop_assert!(!out.contains(['<', '>', '"', '\'']), "{} {:?}", name, out);
            prop_assert!(out.match_indices('&').all(|(i, _)| cms::html::starts_entity(&out[i..])), "{} {:?}", name, out);
            prop_assert_eq!(unescape(&out), s.clone(), "{}", name);
        }
    }

    /// WordPress's esc_attr() keeps an entity already written, and escapes everything else.
    #[test]
    fn wordpress_esc_leaves_no_markup(s in gen::text(200)) {
        let out = cms::html::esc(&s);
        prop_assert!(!out.contains(['<', '>', '"', '\'']), "{:?}", out);
        prop_assert!(out.match_indices('&').all(|(i, _)| cms::html::starts_entity(&out[i..])), "{:?}", out);
        if !s.contains('&') {
            prop_assert_eq!(unescape(&out), s);
        }
    }

    #[test]
    fn partial_escapers_escape_what_they_promise(s in gen::text(200)) {
        prop_assert!(!orders::html::esc_text(&s).contains(['<', '>']));
        prop_assert!(!orders::html::esc_dq(&s).contains(['<', '>', '"']));
        prop_assert!(!catalog::html::esc_text(&s).contains('<'));
        prop_assert!(!catalog::html::esc_attr(&s).contains(['<', '>', '\'']));
        prop_assert!(!catalog::html::esc_dq(&s).contains(['<', '>', '"']));
        prop_assert!(!staff::html::text(&s).contains('<'));
        prop_assert!(!accounts::html::plain(&s).contains(['<', '>']));
        let bare = catalog::html::bare_attr(&s);
        match bare.strip_prefix('"').and_then(|b| b.strip_suffix('"')) {
            Some(inner) => prop_assert!(!inner.contains(['"', '<', '>'])),
            None => prop_assert!(!bare.is_empty() && !bare.contains(|c: char| c.is_whitespace() || "\"'<>`".contains(c)), "{:?}", bare),
        }
    }

    #[test]
    fn javascript_literals_read_back_and_stay_inert(s in gen::text(200)) {
        let staff_js = staff::html::js(&s);
        prop_assert!(!staff_js[1..staff_js.len() - 1].contains(['\'', '"', '<', '>', '&', '`', '\n', '\r', '\u{2028}', '\u{2029}']), "{:?}", staff_js);
        prop_assert_eq!(js_unescape(&staff_js), Some(s.clone()));
        let orders_js = orders::html::js_str(&s);
        prop_assert!(!orders_js.contains(['<', '\n', '\r']), "{:?}", orders_js);
        prop_assert_eq!(js_unescape(&orders_js), Some(s.clone()));
        prop_assert_eq!(js_unescape(&catalog::forms::js_quote(&s)), Some(s));
    }

    /// Three copies of PHP's urlencode() agree, and what they write decodes back.
    #[test]
    fn urlencode_copies_agree(s in gen::text(200)) {
        let wp = cms::url::urlencode(&s);
        prop_assert_eq!(&staff::html::urlencode(&s), &wp);
        prop_assert_eq!(&catalog::html::urlencode(&s), &wp);
        prop_assert_eq!(cms::url::urldecode(&wp), s);
    }

    #[test]
    fn query_builders_read_back(pairs in proptest::collection::vec(("[a-z]{1,6}", gen::text(30)), 0..6), page in "/[a-z]{1,8}\\.php(\\?[a-z]{1,3}=[a-z0-9]{0,3})?(#[a-z]{1,4})?") {
        let borrowed: Vec<(&str, &str)> = pairs.iter().map(|(k, v)| (k.as_str(), v.as_str())).collect();
        let mut expected: Vec<(String, String)> = Vec::new();
        for (k, v) in pairs.iter().filter(|(_, v)| !v.is_empty()) {
            expected.push((k.clone(), v.clone()));
        }
        let built = staff::html::url_query(&borrowed);
        let read: Vec<(String, String)> = form_urlencoded_pairs(built.trim_start_matches('?'));
        prop_assert_eq!(read, expected);
        let mut q = catalog::html::Query::new();
        for (k, v) in &pairs {
            q.set(k, v.clone());
        }
        let url = catalog::html::url(&page, &q);
        let (no_frag, _) = url.split_once('#').unwrap_or((&url, ""));
        prop_assert!(no_frag.parse::<axum::http::Uri>().is_ok(), "{:?}", url);
        let read = form_urlencoded_pairs(no_frag.split_once('?').map_or("", |(_, q)| q));
        for (k, v) in q.pairs().iter().filter(|(_, v)| !v.is_empty()) {
            prop_assert!(read.iter().any(|(rk, rv)| rk == k && rv == v), "{} missing from {:?}", k, url);
        }
    }

    #[test]
    fn base64_fields_round_trip(fields in proptest::collection::vec(gen::text_of("abcXYZ019 +/=é€", 0..12), 1..5), junk in gen::text(60)) {
        let borrowed: Vec<&str> = fields.iter().map(String::as_str).collect();
        let encoded = catalog::html::base64_fields(&borrowed);
        prop_assert_eq!(catalog::html::base64_fields_decode(&encoded), Some(fields));
        within(PURE, "base64_fields_decode", || catalog::html::base64_fields_decode(&junk));
    }

    /// json_encode() as PHP writes it: ASCII only, `/` escaped, and the same value to a JSON reader.
    #[test]
    fn php_json_is_ascii_and_reads_back(s in gen::text(200)) {
        let value = serde_json::json!({ "v": s, "list": [s.clone(), 1] });
        for (name, out) in [
            ("accounts", accounts::html::json_encode(&value)),
            ("cart", orders::shop::cart::php_json(&serde_json::to_string(&value).unwrap())),
            ("loader", catalog::loader::php_json(&value)),
        ] {
            prop_assert!(out.is_ascii() && !out.replace("\\/", "").contains('/'), "{} {:?}", name, out);
            let back: serde_json::Value = serde_json::from_str(&out).map_err(|e| TestCaseError::fail(format!("{name}: {e} in {out:?}")))?;
            prop_assert_eq!(&back, &value, "{}", name);
        }
    }

    #[test]
    fn input_builders_draw_one_tag(field in "[a-z_]{1,10}", value in gen::text(80), placeholder in gen::text(30), size in 0u32..200) {
        let drawn = [
            orders::html::text_input("text", &field, &value, size, size, &placeholder, value.len() % 2 == 0),
            accounts::html::text(&field, &value, size, Some(size), accounts::html::Opts::new()),
            accounts::html::hidden(&field, &value, &placeholder),
            catalog::forms::text(&field, &value, "10", "20", &[("placeholder", placeholder.as_str())]),
            catalog::forms::hidden(&field, &value),
        ];
        for html in drawn {
            prop_assert!(one_tag(&html), "{:?}", html);
        }
    }

    /// fn_text(): no line keeps a `<script` run that a `>` closes.
    #[test]
    fn fn_text_removes_script_runs(s in gen::text_of("<scriptSCRIPT> \nab", 0..60), upper in any::<bool>()) {
        for out in [staff::check::posted_text(&s), orders::shop::checkout::fn_text(&s, upper)] {
            for line in out.lines() {
                let lower = line.to_ascii_lowercase();
                if let Some(at) = lower.find("<script") {
                    prop_assert!(!lower[at..].contains('>'), "{:?} from {:?}", out, s);
                }
            }
        }
    }

    #[test]
    fn texturize_changes_only_apostrophes(s in gen::text(200)) {
        let once = orders::forms::render::texturize(&s);
        prop_assert_eq!(once.replace("&rsquo;", "'"), s.replace("&rsquo;", "'"));
        prop_assert_eq!(orders::forms::render::texturize(&once), once);
        for locale in [cms::Lang::En, cms::Lang::Fr] {
            within(PURE, "wptexturize", || catalog::wp_search::texturize::texturize(&s, locale));
        }
    }
}

fn form_urlencoded_pairs(q: &str) -> Vec<(String, String)> {
    q.split('&')
        .filter(|p| !p.is_empty())
        .map(|p| {
            let (k, v) = p.split_once('=').unwrap_or((p, ""));
            (cms::url::urldecode(k), cms::url::urldecode(v))
        })
        .collect()
}
