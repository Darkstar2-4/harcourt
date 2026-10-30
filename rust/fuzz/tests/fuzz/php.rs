use hxweb::{
    catalog::php::{self as catalog_php, Key, Value},
    cms::import::php as cms_php,
    pricing::ListPrices,
    staff::phpser::{self, Php},
};
use hxweb_fuzz::{config, gen, heavy, within, PURE};
use proptest::prelude::*;

/// A tokio worker's stack, where the pages read these values.
const WORKER_STACK: usize = 2 << 20;

fn on_worker_stack<T: Send + 'static>(f: impl FnOnce() -> T + Send + 'static) -> T {
    std::thread::Builder::new().stack_size(WORKER_STACK).spawn(f).expect("thread").join().expect("no panic")
}

fn key() -> impl Strategy<Value = Php> {
    prop_oneof![any::<i64>().prop_map(Php::Int), "[a-z0-9_]{0,6}".prop_map(Php::Str)]
}

fn php() -> impl Strategy<Value = Php> {
    let leaf = prop_oneof![
        Just(Php::Null),
        any::<bool>().prop_map(Php::Bool),
        any::<i64>().prop_map(Php::Int),
        any::<f64>().prop_filter("finite", |f| f.is_finite()).prop_map(|f| Php::Float(f.to_string())),
        gen::text(24).prop_map(Php::Str),
    ];
    leaf.prop_recursive(4, 48, 6, |inner| {
        prop_oneof![
            proptest::collection::vec((key(), inner.clone()), 0..6).prop_map(Php::Array),
            ("[A-Za-z_]{1,10}", proptest::collection::vec(("[a-z_]{1,6}".prop_map(Php::Str), inner), 0..4)).prop_map(|(c, e)| Php::Object(c, e)),
        ]
    })
}

/// What the catalog's reader makes of a value: arrays and objects as maps.
fn as_catalog(v: &Php) -> Value {
    match v {
        Php::Null => Value::Null,
        Php::Bool(b) => Value::Bool(*b),
        Php::Int(n) => Value::Int(*n),
        Php::Float(s) => Value::Float(s.parse().unwrap()),
        Php::Str(s) => Value::Str(s.clone()),
        Php::Array(e) | Php::Object(_, e) => Value::Map(
            e.iter()
                .map(|(k, v)| {
                    let k = match k {
                        Php::Int(n) => Key::Int(*n),
                        other => Key::Str(other.text()),
                    };
                    (k, as_catalog(v))
                })
                .collect(),
        ),
    }
}

/// What the WordPress import's reader makes of it: objects as arrays.
fn as_cms(v: &Php) -> cms_php::Php {
    match v {
        Php::Null => cms_php::Php::Null,
        Php::Bool(b) => cms_php::Php::Bool(*b),
        Php::Int(n) => cms_php::Php::Int(*n),
        Php::Float(s) => cms_php::Php::Float(s.parse().unwrap()),
        Php::Str(s) => cms_php::Php::Str(s.clone()),
        Php::Array(e) | Php::Object(_, e) => cms_php::Php::Array(e.iter().map(|(k, v)| (as_cms(k), as_cms(v))).collect()),
    }
}

/// Edits that keep a value's look: a cut, a changed byte, a changed length, a piece repeated.
fn mutated(s: &str, at: usize, edit: u8, with: char) -> String {
    let mut chars: Vec<char> = s.chars().collect();
    let at = if chars.is_empty() { 0 } else { at % chars.len() };
    match edit % 5 {
        0 => chars.truncate(at),
        1 if !chars.is_empty() => chars[at] = with,
        2 => chars.insert(at, with),
        3 if !chars.is_empty() => {
            chars.remove(at);
        }
        _ => {
            let piece: Vec<char> = chars[at..].iter().take(9).copied().collect();
            for c in piece.into_iter().rev() {
                chars.insert(at, c);
            }
        }
    }
    chars.into_iter().collect()
}

fn read_all(s: &str) {
    within(PURE, "phpser::parse", || phpser::parse(s));
    within(PURE, "catalog unserialize", || catalog_php::unserialize(s).map(|v| catalog_php::print_r(&v)));
    let _ = within(PURE, "cms unserialize", || cms_php::unserialize(s));
    within(PURE, "ListPrices::parse", || ListPrices::parse(s));
}

proptest! {
    #![proptest_config(config(8192))]

    #[test]
    fn serialized_values_read_back_in_every_reader(v in php()) {
        let s = phpser::write(&v);
        prop_assert_eq!(phpser::parse(&s), Some(v.clone()), "{}", s);
        prop_assert_eq!(catalog_php::unserialize(&s), Some(as_catalog(&v)), "{}", s);
        prop_assert_eq!(cms_php::unserialize(&s), Ok(as_cms(&v)), "{}", s);
    }

    #[test]
    fn readers_are_total_on_edited_values(v in php(), edits in proptest::collection::vec((any::<usize>(), any::<u8>(), prop_oneof![Just(';'), Just(':'), Just('"'), Just('{'), Just('}'), proptest::char::range('0', '9'), any::<char>()]), 1..4)) {
        let mut s = phpser::write(&v);
        for (at, edit, with) in edits {
            s = mutated(&s, at, edit, with);
        }
        read_all(&s);
    }

    #[test]
    fn readers_are_total_on_any_text(s in prop_oneof![gen::text(200), gen::text_of("aiOsdbN:;{}\"0123456789", 0..80)]) {
        read_all(&s);
    }
}

proptest! {
    #![proptest_config(heavy(96, 500))]

    /// Nesting of any depth, and a declared size of any size, read on a worker's stack in bounded memory.
    #[test]
    fn deep_and_wide_values_are_refused_or_read(depth in 0usize..50_000, tag in prop_oneof![Just("a"), Just("O:1:\"x\"")], count in prop_oneof![Just(1u64), any::<u64>()]) {
        let s = format!("{}N;{}", format!("{tag}:{count}:{{i:0;").repeat(depth), "}".repeat(depth));
        let (_, held) = on_worker_stack(move || hxweb_fuzz::alloc::bounded(|| read_all(&s)));
        prop_assert!(held < 64 << 20, "{} bytes held", held);
    }

    #[test]
    fn deep_query_keys_parse_on_a_worker_stack(depth in 0usize..30_000, pairs in 1usize..4) {
        let q = vec![format!("a{}=1", "[]".repeat(depth)); pairs].join("&");
        on_worker_stack(move || {
            within(PURE, "catalog parse_str", || catalog_php::print_r(&catalog_php::parse_str(&q)));
            within(PURE, "cms parse_str", || hxweb::cms::url::parse_str(&q));
        });
    }
}
