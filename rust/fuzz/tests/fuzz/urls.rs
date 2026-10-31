use std::time::Duration;

use axum::http::{header, HeaderMap, HeaderValue, Uri};
use hxweb::{
    accounts::cookies::{self, Life},
    catalog::query::{self, Value},
    cms::url::{self, Arg},
    orders::form_params,
    staff::csrf::{php_intval, Fields},
};
use hxweb_fuzz::{config, cut, gen, heavy, within, PURE};
use proptest::prelude::*;

/// Safe inside a quoted attribute: nothing that ends the value or opens a tag, no ASCII white space or controls,
/// and every `&` starts an entity.
fn attribute_safe(s: &str) -> bool {
    s.chars().all(|c| !matches!(c, '<' | '>' | '"' | '\'' | '`') && !c.is_ascii_whitespace() && !c.is_ascii_control())
        && s.match_indices('&').all(|(i, _)| hxweb::cms::html::starts_entity(&s[i..]))
}

fn alnum_pairs() -> impl Strategy<Value = Vec<(String, String)>> {
    proptest::collection::vec(("[a-e]{1,2}", "[a-z0-9]{0,4}"), 0..8)
}

proptest! {
    #![proptest_config(config(8192))]

    #[test]
    fn php_encoders_round_trip_through_urldecode(s in gen::text(200)) {
        let enc = within(PURE, "urlencode", || url::urlencode(&s));
        prop_assert!(enc.bytes().all(|b| b.is_ascii_alphanumeric() || b"-_.%+".contains(&b)), "{}", enc);
        prop_assert_eq!(url::urldecode(&enc), s.clone());
        let raw = url::rawurlencode(&s);
        prop_assert!(raw.bytes().all(|b| b.is_ascii_alphanumeric() || b"-_.%~".contains(&b)), "{}", raw);
        prop_assert_eq!(url::urldecode(&raw), s);
    }

    #[test]
    fn urldecode_is_total(s in prop_oneof![gen::url_text(40), gen::text(200)]) {
        within(PURE, "urldecode", || url::urldecode(&s));
    }

    /// The WordPress helpers and the catalog's `$_GET` both emulate PHP's parse_str(): they agree on the variables
    /// a query sets, their order and their values.
    #[test]
    fn both_parse_str_emulations_agree(q in gen::query()) {
        let wp = within(PURE, "cms parse_str", || url::parse_str(&q));
        let cat = within(PURE, "catalog parse_str", || query::parse_str(&q));
        let wp_names: Vec<&str> = wp.iter().map(|(k, _)| k.as_str()).collect();
        let cat_names: Vec<&str> = cat.iter().map(|(k, _)| k.as_str()).collect();
        prop_assert_eq!(&wp_names, &cat_names, "{}", q);
        for ((name, arg), (_, value)) in wp.iter().zip(&cat) {
            match (arg, value) {
                (Arg::One(v), Value::Str(s)) => prop_assert_eq!(v, s, "{} in {}", name, q),
                (Arg::List(list), Value::Map(entries)) => {
                    let keys: Vec<&str> = entries.iter().map(|(k, _)| k.as_str()).collect();
                    let subs: Vec<&str> = list.iter().map(|(s, _)| s.as_str()).collect();
                    prop_assert_eq!(subs, keys, "{} in {}", name, q);
                    for ((_, v), (_, value)) in list.iter().zip(entries) {
                        if let Value::Str(s) = value {
                            prop_assert_eq!(v, s, "{} in {}", name, q);
                        }
                    }
                }
                _ => prop_assert!(false, "{} differs in {}: {:?} {:?}", name, q, arg, value),
            }
        }
    }

    #[test]
    fn parse_str_names_are_php_variable_names(q in gen::query()) {
        for (name, _) in url::parse_str(&q) {
            prop_assert!(!name.is_empty() && !name.starts_with(' '), "{:?} from {}", name, q);
            let base = name.split('[').next().unwrap_or("");
            prop_assert!(!base.contains(['.', ' ']), "{:?} from {}", name, q);
        }
    }

    /// WPML's redirect keeps the request's query on a path of the site, as a Location a browser can follow.
    #[test]
    fn with_query_builds_a_location_that_parses(q in gen::query(), target in prop_oneof![Just("/a/b/"), Just("/?lang=fr"), Just("/x/?p=1&lang=fr")]) {
        let out = within(PURE, "with_query", || url::with_query(target, &q));
        prop_assert!(out.starts_with(target.split('?').next().unwrap_or(target)), "{}", out);
        prop_assert!(HeaderValue::from_str(&out).is_ok(), "{} from {}", cut(&out), cut(&q));
        let uri_safe = |c: char| c.is_ascii_alphanumeric() || "-._~%!$&'()*+,;=:@/?".contains(c);
        prop_assert!(out.chars().all(uri_safe), "{} from {}", cut(&out), cut(&q));
        prop_assert!(out.len() > 65_000 || out.parse::<Uri>().is_ok(), "{} from {}", cut(&out), cut(&q));
    }

    #[test]
    fn remove_query_arg_drops_the_key_and_keeps_the_rest(pairs in alnum_pairs(), key in "[a-e]{1,2}", frag in "(#[a-z]{0,3})?") {
        let query = pairs.iter().map(|(k, v)| format!("{k}={v}")).collect::<Vec<_>>().join("&");
        let out = url::remove_query_arg(&key, &format!("/p/?{query}{frag}"));
        prop_assert!(out.ends_with(&frag));
        let rest = out.strip_suffix(&frag).unwrap_or(&out);
        let kept = rest.strip_prefix("/p/").unwrap_or("").trim_start_matches('?');
        let expected: Vec<(String, Arg)> = url::parse_str(&query).into_iter().filter(|(k, _)| *k != key).collect();
        prop_assert_eq!(url::parse_str(kept), expected);
    }

    #[test]
    fn form_action_and_esc_url_are_attribute_safe(path in gen::path(), q in gen::query(), s in gen::text(120)) {
        let uri = format!("{path}?{q}");
        let action = within(PURE, "form_action", || url::form_action(&uri));
        prop_assert!(attribute_safe(&action), "{:?} from {:?}", action, uri);
        let shown = within(PURE, "esc_url", || url::esc_url(&s));
        prop_assert!(attribute_safe(&shown), "{:?} from {:?}", shown, s);
    }

    #[test]
    fn fields_read_the_last_value_and_prefer_the_form(posted in alnum_pairs(), query in alnum_pairs(), name in "[a-e]{1,2}") {
        let q = query.iter().map(|(k, v)| format!("{k}={v}")).collect::<Vec<_>>().join("&");
        let from_query = Fields::from_query(Some(&q));
        let last = |pairs: &[(String, String)]| pairs.iter().rev().find(|(k, _)| *k == name).map(|(_, v)| v.clone());
        prop_assert_eq!(from_query.get(&name), last(&query).unwrap_or_default());
        let fields = Fields::posted(posted.clone()).with_query(&from_query);
        prop_assert_eq!(fields.get(&name), last(&posted).or_else(|| last(&query)).unwrap_or_default());
        prop_assert_eq!(fields.int(&name), php_intval(fields.get(&name)));
    }

    #[test]
    fn form_params_take_the_body_over_the_query(query in alnum_pairs(), body in alnum_pairs(), name in "[a-e]{1,2}") {
        let join = |p: &[(String, String)]| p.iter().map(|(k, v)| format!("{k}={v}")).collect::<Vec<_>>().join("&");
        let params = form_params(Some(&join(&query)), join(&body).as_bytes());
        let last = |pairs: &[(String, String)]| pairs.iter().rev().find(|(k, _)| *k == name).map(|(_, v)| v.clone());
        prop_assert_eq!(params.get(&name).cloned(), last(&body).or_else(|| last(&query)));
    }

    #[test]
    fn form_params_are_total(query in gen::query(), body in gen::bytes(400)) {
        within(PURE, "form_params", || form_params(Some(&query), &body));
    }

    #[test]
    fn cookie_values_round_trip(value in gen::text(100), name in "[a-z_]{1,12}") {
        let set = cookies::set(&name, &value, Life::Year).expect("an encoded value is a header value");
        let pair = set.to_str().unwrap().split(';').next().unwrap().to_string();
        let mut headers = HeaderMap::new();
        headers.insert(header::COOKIE, HeaderValue::from_str(&format!("other=1; {pair}")).unwrap());
        prop_assert_eq!(cookies::get(&headers, &name), Some(value.clone()));
        let year = cookies::php_year(&name, &value, 1_700_000_000).expect("a year from now");
        let pair = year.to_str().unwrap().split(';').next().unwrap().to_string();
        headers.insert(header::COOKIE, HeaderValue::from_str(&pair).unwrap());
        prop_assert_eq!(cookies::get(&headers, &name), Some(value));
    }

    #[test]
    fn cookie_reading_is_total(raw in gen::bytes(300), name in gen::key(), now in any::<i64>()) {
        let mut headers = HeaderMap::new();
        if let Ok(v) = HeaderValue::from_bytes(&raw) {
            headers.append(header::COOKIE, v);
        }
        within(PURE, "cookies::get", || cookies::get(&headers, &name));
        let _ = cookies::php_year("harcourt", &name, now);
    }

    /// PHP's intval() of a string: leading white space, a sign, the digits, and the most a 64-bit integer holds.
    #[test]
    fn php_intval_reads_leading_digits(ws in "[ \t\n\r]{0,2}", sign in "[-+]?", digits in "[0-9]{0,25}", tail in "[a-z .]{0,4}") {
        let s = format!("{ws}{sign}{digits}{tail}");
        let got = php_intval(&s);
        let n = digits.trim_start_matches('0');
        let expected = if tail.starts_with('.') && !digits.is_empty() {
            (format!("{sign}{digits}").parse::<f64>().unwrap()) as i64
        } else if n.is_empty() {
            0
        } else if n.len() > 19 || (n.len() == 19 && n > "9223372036854775807") {
            if sign == "-" { i64::MIN } else { i64::MAX }
        } else {
            let v: i64 = n.parse().unwrap();
            if sign == "-" { -v } else { v }
        };
        prop_assert_eq!(got, expected, "{:?}", s);
    }
}

proptest! {
    #![proptest_config(heavy(32, 2000))]

    /// A big form posted to a URL with a long query: reading `$_REQUEST` stays linear.
    #[test]
    fn fields_merge_in_linear_time(posted in 0usize..100_000, query in 0usize..10_000) {
        let pairs: Vec<(String, String)> = (0..posted).map(|i| (format!("f{}", i % 7), String::new())).collect();
        let q = (0..query).map(|i| format!("q{i}=1")).collect::<Vec<_>>().join("&");
        let fields = within(Duration::from_millis(500), "Fields::with_query", || Fields::posted(pairs).with_query(&Fields::from_query(Some(&q))));
        prop_assert_eq!(fields.iter().count(), posted + query);
    }

    #[test]
    fn parse_str_handles_a_query_of_many_variables(n in 0usize..16_000, list in any::<bool>()) {
        let q = (0..n).map(|i| if list { format!("a[]={i}") } else { format!("v{i}={i}") }).collect::<Vec<_>>().join("&");
        within(PURE, "cms parse_str", || url::parse_str(&q));
        within(PURE, "catalog parse_str", || query::parse_str(&q));
        within(PURE, "with_query", || url::with_query("/", &q));
    }
}
