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

    /// The widgets every area draws: values and labels come out escaped, inputs stay one tag, a field in error
    /// keeps its markup.
    #[test]
    fn widgets_escape_values_and_labels(field in "[a-z_]{1,10}", choices in proptest::collection::vec((gen::text(20), gen::text(20)), 0..5), value in gen::text(40), error in any::<bool>()) {
        let mark = |s: &str| format!("Zq{s}qZ");
        let choices: Vec<(String, String)> = choices.iter().map(|(v, l)| (mark(v), mark(l))).collect();
        let value = mark(&value);
        let selected = choices.first().map_or(String::new(), |c| c.0.clone());
        let plain: Vec<String> = choices.iter().map(|c| c.1.clone()).collect();
        let mut notes = staff::html::Notes::new("Fuzz", "");
        if error {
            notes.error(&field, Some("wrong"));
        }
        let form = staff::html::Form { notes: &notes };
        let o = staff::html::Opts::new;
        let tags = [
            form.text(&field, &value, 10, 20, o()),
            form.date(&field, &value, o()),
            form.checkbox(&field, &value, error, o()),
            form.radio(&field, &value, error, o()),
            staff::html::hidden(&field, &value),
            staff::html::button(&value, o()),
            staff::html::submit(&value, o()),
            orders::html::hidden(&field, &value),
            accounts::html::checkbox(&field, &value, error, accounts::html::Opts::new()),
            accounts::html::button(&value, accounts::html::Opts::new()),
            catalog::forms::checkbox(&field, &value, &value, &[]),
            catalog::forms::radio(&field, &value, "", &[]),
            catalog::forms::button(&value, &[("title", value.as_str())]),
        ];
        for html in &tags {
            prop_assert!(html.matches('<').count() == html.matches('>').count(), "{:?}", html);
        }
        let in_attribute = |s: &String| s.contains(['<', '>', '\'']);
        let in_text = |s: &String| s.contains(['<', '>']);
        let values: Vec<&String> = choices.iter().map(|(v, _)| v).filter(|v| in_attribute(v)).collect();
        let labels: Vec<&String> = choices.iter().map(|(_, l)| l).filter(|l| in_text(l)).collect();
        let none: Vec<&String> = Vec::new();
        let value_attr: Vec<&String> = [&value].into_iter().filter(|v| in_attribute(v)).collect();
        let value_text: Vec<&String> = [&value].into_iter().filter(|v| in_text(v)).collect();
        let select_values: Vec<&String> = plain.iter().filter(|v| in_attribute(v)).collect();
        // Each widget with what may not appear in it as written: attributes are single-quoted, and the staff
        // select's labels are markup by contract.
        let drawn: [(String, &[&String], &[&String]); 7] = [
            (form.select(&field, &choices, &selected, staff::html::Blank::Empty, o()), &values, &none),
            (form.textarea(&field, &value, 4, 40, o()), &value_text, &none),
            (orders::html::select(&field, &choices, &selected, Some("Pick"), "", error), &values, &labels),
            (accounts::html::select(&field, &choices, &selected, accounts::html::Blank::Label("Pick"), accounts::html::Opts::new()), &values, &labels),
            (catalog::forms::select_list(&field, &plain, &selected, &[]), &select_values, &none),
            (staff::html::href(&staff::html::esc(&value), "/x.php", o()), &value_attr, &none),
            (tags.concat(), &value_attr, &none),
        ];
        for (html, a, b) in &drawn {
            for raw in a.iter().chain(b.iter()) {
                prop_assert!(!html.contains(raw.as_str()), "{:?} drawn raw in {:?}", raw, html);
            }
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
