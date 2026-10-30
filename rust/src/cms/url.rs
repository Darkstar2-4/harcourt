//! WordPress's URL functions, for the URLs it printed or redirected to: PHP's encoders, parse_str(), the query
//! rebuild of add_query_arg() and WPML's redirect, and esc_url().

use crate::catalog::php::{variable, Variable, MAX_INPUT_VARS};

/// PHP urldecode().
pub fn urldecode(s: &str) -> String {
    percent_encoding::percent_decode_str(&s.replace('+', " ")).decode_utf8_lossy().into_owned()
}

/// PHP rawurlencode().
pub fn rawurlencode(s: &str) -> String {
    encode(s, false)
}

/// PHP urlencode().
pub fn urlencode(s: &str) -> String {
    encode(s, true)
}

fn encode(s: &str, plus: bool) -> String {
    let mut out = String::with_capacity(s.len());
    for b in s.bytes() {
        match b {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' => out.push(b as char),
            b'~' if !plus => out.push('~'),
            b' ' if plus => out.push('+'),
            _ => out.push_str(&format!("%{b:02X}")),
        }
    }
    out
}

/// A value after parse_str(): `a=1`, or `a[]=1&a[x]=2` as a list of (subkey, value).
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Arg {
    One(String),
    List(Vec<(String, String)>),
}

/// PHP's parse_str(), keys and values decoded: a key keeps its first position and its last value, `a[]` keys
/// collect into a list numbered from 0, dots and spaces in a name become underscores.
pub fn parse_str(query: &str) -> Vec<(String, Arg)> {
    let mut args: Vec<(String, Arg)> = Vec::new();
    // The index `[]` takes next in each array: past the largest integer index, none after the largest integer.
    let mut next: std::collections::HashMap<String, Option<i64>> = std::collections::HashMap::new();
    for kv in query.split('&').filter(|kv| !kv.is_empty()).take(MAX_INPUT_VARS) {
        let (k, v) = kv.split_once('=').unwrap_or((kv, ""));
        match variable(&urldecode(k)) {
            Variable::Set(name, indexes) => {
                let sub = match indexes.into_iter().next() {
                    None => None,
                    Some(index) => {
                        if !args.iter().any(|(k, a)| *k == name && matches!(a, Arg::List(_))) {
                            next.insert(name.clone(), Some(0));
                        }
                        let slot = next.entry(name.clone()).or_insert(Some(0));
                        match index {
                            None => {
                                let Some(i) = *slot else { continue };
                                *slot = i.checked_add(1);
                                Some(i.to_string())
                            }
                            Some(s) => {
                                if let (Some(i), Some(n)) = (int_index(&s), *slot) {
                                    if i >= n {
                                        *slot = i.checked_add(1);
                                    }
                                }
                                Some(s)
                            }
                        }
                    }
                };
                set(&mut args, name, sub, urldecode(v));
            }
            Variable::TooDeep(name) => args.retain(|(k, _)| *k != name),
            Variable::Skip => {}
        }
    }
    args
}

/// Sets `name`, or `name[sub]` (an array then, whatever it was).
fn set(args: &mut Vec<(String, Arg)>, name: String, sub: Option<String>, v: String) {
    let slot = args.iter().position(|(key, _)| *key == name);
    match (sub, slot) {
        (None, Some(i)) => args[i].1 = Arg::One(v),
        (None, None) => args.push((name, Arg::One(v))),
        (Some(sub), slot) => {
            let i = slot.unwrap_or_else(|| {
                args.push((name, Arg::List(Vec::new())));
                args.len() - 1
            });
            if let Arg::One(_) = args[i].1 {
                args[i].1 = Arg::List(Vec::new());
            }
            if let Arg::List(list) = &mut args[i].1 {
                match list.iter_mut().find(|(s, _)| *s == sub) {
                    Some(e) => e.1 = v,
                    None => list.push((sub, v)),
                }
            }
        }
    }
}

/// An array index PHP stores as an integer: decimal digits as PHP itself prints the number.
fn int_index(s: &str) -> Option<i64> {
    let digits = s.strip_prefix('-').unwrap_or(s);
    let canonical = !digits.is_empty() && digits.bytes().all(|b| b.is_ascii_digit()) && (digits == "0" || !digits.starts_with('0')) && s != "-0";
    canonical.then(|| s.parse().ok()).flatten()
}

/// build_query() of already-encoded args, then add_query_arg()'s cleanup: an empty value loses its `=`.
fn build(args: &[(String, Arg)]) -> String {
    let pair = |k: &str, v: &str| if v.is_empty() { k.to_string() } else { format!("{k}={v}") };
    args.iter()
        .flat_map(|(k, a)| match a {
            Arg::One(v) => vec![pair(k, v)],
            Arg::List(list) => list.iter().map(|(s, v)| pair(&format!("{k}%5B{s}%5D"), v)).collect(),
        })
        .collect::<Vec<_>>()
        .join("&")
}

fn join(base: &str, query: &str) -> String {
    if query.is_empty() {
        base.to_string()
    } else {
        format!("{base}?{query}")
    }
}

/// What WPML's redirect to a translation does with the request's query: parse_str() it, rawurlencode every key and
/// value, and add_query_arg() them to `target`, whose own pairs stay as written.
pub fn with_query(target: &str, query: &str) -> String {
    let (base, existing) = target.split_once('?').unwrap_or((target, ""));
    let mut args: Vec<(String, Arg)> = Vec::new();
    for kv in existing.split('&').filter(|kv| !kv.is_empty()) {
        let (k, v) = kv.split_once('=').unwrap_or((kv, ""));
        args.push((k.to_string(), Arg::One(v.to_string())));
    }
    for (name, arg) in parse_str(query) {
        match arg {
            Arg::One(v) => set(&mut args, rawurlencode(&name), None, rawurlencode(&v)),
            Arg::List(list) => {
                for (sub, v) in list {
                    set(&mut args, rawurlencode(&name), Some(rawurlencode(&sub)), rawurlencode(&v));
                }
            }
        }
    }
    join(base, &build(&args))
}

/// PHP's http_build_query() of parsed args: keys and values urlencode()d, an empty value keeping its `=`.
pub fn http_build_query(args: &[(String, Arg)]) -> String {
    args.iter()
        .flat_map(|(k, a)| match a {
            Arg::One(v) => vec![format!("{}={}", urlencode(k), urlencode(v))],
            Arg::List(list) => list.iter().map(|(s, v)| format!("{}={}", urlencode(&format!("{k}[{s}]")), urlencode(v))).collect(),
        })
        .collect::<Vec<_>>()
        .join("&")
}

/// remove_query_arg($key, $uri): the query parsed, its values urlencode()d (urlencode_deep), `key` dropped, and
/// rebuilt with the keys as parse_str() left them.
pub fn remove_query_arg(key: &str, uri: &str) -> String {
    let (rest, frag) = match uri.find('#') {
        Some(i) => (&uri[..i], &uri[i..]),
        None => (uri, ""),
    };
    let Some((base, query)) = rest.split_once('?') else { return uri.to_string() };
    let args: Vec<(String, Arg)> = parse_str(query)
        .into_iter()
        .filter(|(k, _)| k != key)
        .map(|(k, a)| {
            let a = match a {
                Arg::One(v) => Arg::One(urlencode(&v)),
                Arg::List(list) => Arg::List(list.into_iter().map(|(s, v)| (s, urlencode(&v))).collect()),
            };
            (k, a)
        })
        .collect();
    format!("{}{frag}", join(base, &build(&args)))
}

/// esc_url() for display, on the site-relative URLs WordPress prints: characters outside its allowed set dropped,
/// spaces as %20, and `&` (unless it starts an entity) and `'` as entities.
pub fn esc_url(url: &str) -> String {
    let url = url.trim_start().replace(' ', "%20");
    let allowed = |c: char| c.is_ascii_alphanumeric() || "-~+_.?#=!&;,/:%@$|*'()[]".contains(c) || !c.is_ascii();
    let url: String = url.chars().filter(|c| allowed(*c)).collect();
    let url = url.replace(";//", "://");
    let mut normalized = String::with_capacity(url.len() + 8);
    for (i, c) in url.char_indices() {
        match c {
            '&' if !super::html::starts_entity(&url[i..]) => normalized.push_str("&amp;"),
            c => normalized.push(c),
        }
    }
    normalized.replace("&amp;", "&#038;").replace('\'', "&#039;")
}

/// The `action` Gravity Forms prints on a form: `remove_query_arg('gf_token')` of the request URI, through esc_url().
pub fn form_action(request_uri: &str) -> String {
    esc_url(&remove_query_arg("gf_token", request_uri))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn redirects_keep_the_query_as_wpml_rebuilds_it() {
        assert_eq!(with_query("/product/", "lang=%27+OR+%271%27%3D%271"), "/product/?lang=%27%20OR%20%271%27%3D%271");
        assert_eq!(with_query("/product/", ""), "/product/");
        assert_eq!(with_query("/a/?lang=fr", "lang=fr&utm_source=x"), "/a/?lang=fr&utm_source=x");
        assert_eq!(with_query("/?page_id=4", "quot=&a.b=1&a.b=2"), "/?page_id=4&quot&a_b=2");
        assert_eq!(with_query("/r/", "x[]=1&x[]=2&y[k]=a+b"), "/r/?x%5B0%5D=1&x%5B1%5D=2&y%5Bk%5D=a%20b");
    }

    #[test]
    fn names_and_indexes_are_read_as_php_reads_them() {
        assert_eq!(with_query("/r/", "[k]=1&x[5]=a&x[]=b&y[é]=c&z[a.b=d"), "/r/?x%5B5%5D=a&x%5B6%5D=b&y%5B%C3%A9%5D=c&z_a_b=d");
        assert_eq!(parse_str("\ta=1").first().map(|(k, _)| k.as_str()), Some("\ta"));
    }

    #[test]
    fn form_actions_follow_gravity_forms() {
        assert_eq!(form_action("/contact/"), "/contact/");
        assert_eq!(form_action("/?lang=fr"), "/?lang=fr");
        assert_eq!(form_action("/?get_users=x&_=17"), "/?get_users=x&#038;_=17");
        assert_eq!(form_action("/c/?utm_source=a,b...c"), "/c/?utm_source=a%2Cb...c");
        assert_eq!(form_action("/b/?lang=fr&quot="), "/b/?lang=fr&#038;quot");
        assert_eq!(form_action("/b/?gf_token=1&a=x+y"), "/b/?a=x+y");
        assert_eq!(form_action("/b/?a=%7E"), "/b/?a=%7E");
    }

    #[test]
    fn the_action_is_the_page_query_as_wordpress_rebuilt_it() {
        assert_eq!(form_action("/catalog/"), "/catalog/");
        assert_eq!(form_action("/catalogue/?lang=fr"), "/catalogue/?lang=fr");
        assert_eq!(
            form_action("/catalog/?action=subcategory&subcategory_id=440&page=1&parameter0=7/16-14"),
            "/catalog/?action=subcategory&#038;subcategory_id=440&#038;page=1&#038;parameter0=7%2F16-14"
        );
        assert_eq!(form_action("/catalog/?search_source=search&search_id=21304&search_code="), "/catalog/?search_source=search&#038;search_id=21304&#038;search_code");
        assert_eq!(form_action("/catalog/?search_source=search&amp%3Bsearch_id=698&amp%3Bsearch_code="), "/catalog/?search_source=search&#038;search_id=698&#038;search_code");
        assert_eq!(form_action("/a/?x=1&x=2&y.z=a b&gf_token=t&l[]=p&l[]=q"), "/a/?x=2&#038;y_z=a+b&#038;l%5B0%5D=p&#038;l%5B1%5D=q");
    }

    #[test]
    fn the_action_is_the_request_uri_as_gravity_forms_rebuilt_it() {
        assert_eq!(form_action("/contact/#gf_1"), "/contact/#gf_1");
        assert_eq!(form_action("/contact/?utm_source=Master+Screw,+return...+%28Mass%29&utm_medium=Email#gf_1"), "/contact/?utm_source=Master+Screw%2C+return...+%28Mass%29&#038;utm_medium=Email#gf_1");
        assert_eq!(form_action("/contact/?a=&gf_token=x&b=1&b=2"), "/contact/?a&#038;b=2");
        assert_eq!(form_action("/p/?q=<x>'"), "/p/?q=%3Cx%3E%27");
        assert_eq!(form_action("/catalog/?amp;action=subcategory&amp;subcategory_id=7#gf_1"), "/catalog/?amp;action=subcategory&#038;subcategory_id=7#gf_1");
    }

    #[test]
    fn php_encoders() {
        assert_eq!(urlencode("a b~,"), "a+b%7E%2C");
        assert_eq!(rawurlencode("a b~,"), "a%20b~%2C");
        assert_eq!(urldecode("a+b%2C"), "a b,");
    }
}
