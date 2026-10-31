use hxweb::serialized::{self, Php};
use hxweb_fuzz::{config, gen, heavy, within, PURE};
use proptest::prelude::*;

/// A tokio worker's stack, where the pages read query strings.
const WORKER_STACK: usize = 2 << 20;

fn on_worker_stack<T: Send + 'static>(f: impl FnOnce() -> T + Send + 'static) -> T {
    std::thread::Builder::new().stack_size(WORKER_STACK).spawn(f).expect("thread").join().expect("no panic")
}

fn key() -> impl Strategy<Value = Php> {
    prop_oneof![any::<i64>().prop_map(Php::Int), "[a-z0-9_]{0,6}".prop_map(Php::Str)]
}

fn value() -> impl Strategy<Value = Php> {
    let leaf = prop_oneof![
        Just(Php::Null),
        any::<bool>().prop_map(Php::Bool),
        any::<i64>().prop_map(Php::Int),
        any::<f64>().prop_filter("finite", |f| f.is_finite()).prop_map(Php::Float),
        gen::text(24).prop_map(Php::Str),
    ];
    leaf.prop_recursive(4, 48, 6, |inner| proptest::collection::vec((key(), inner), 0..6).prop_map(Php::Array))
}

/// serialize(), as PHP writes it; an object when `object`.
fn write(v: &Php, object: bool) -> String {
    match v {
        Php::Null => "N;".into(),
        Php::Bool(b) => format!("b:{};", u8::from(*b)),
        Php::Int(n) => format!("i:{n};"),
        Php::Float(f) => format!("d:{f:?};"),
        Php::Str(s) => format!("s:{}:\"{s}\";", s.len()),
        Php::Array(e) => {
            let body: String = e.iter().map(|(k, v)| write(k, false) + &write(v, object)).collect();
            if object { format!("O:8:\"stdClass\":{}:{{{body}}}", e.len()) } else { format!("a:{}:{{{body}}}", e.len()) }
        }
    }
}

/// The value with only the first entry of each key, the integer 5 and the string "5" being one key, as in PHP.
fn distinct(v: &Php) -> Php {
    let text = |k: &Php| match k {
        Php::Int(i) => i.to_string(),
        Php::Str(s) => s.clone(),
        _ => String::new(),
    };
    match v {
        Php::Array(e) => {
            let mut seen = std::collections::HashSet::new();
            Php::Array(e.iter().filter(|(k, _)| seen.insert(text(k))).map(|(k, v)| (k.clone(), distinct(v))).collect())
        }
        other => other.clone(),
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

fn read(s: &str) {
    if let Ok(v) = within(PURE, "unserialize", || serialized::unserialize(s)) {
        within(PURE, "to_json", || v.to_json().map(|j| v.same(&j)));
    }
}

proptest! {
    #![proptest_config(config(8192))]

    /// What PHP wrote reads back, arrays and objects alike, and its JSON holds the same data.
    #[test]
    fn written_values_read_back_and_convert_to_the_same_data(v in value(), object in any::<bool>()) {
        let v = distinct(&v);
        let s = write(&v, object);
        prop_assert_eq!(serialized::unserialize(&s), Ok(v.clone()), "{}", s);
        let json = v.to_json().expect("finite").to_string();
        prop_assert!(v.same(&serde_json::from_str(&json).expect("JSON")), "{}", json);
        let list = v.renumbered();
        let json = list.to_json().expect("finite").to_string();
        prop_assert!(list.same(&serde_json::from_str(&json).expect("JSON")), "{}", json);
    }

    #[test]
    fn the_reader_is_total_on_edited_values(v in value(), edits in proptest::collection::vec((any::<usize>(), any::<u8>(), prop_oneof![Just(';'), Just(':'), Just('"'), Just('{'), Just('}'), proptest::char::range('0', '9'), any::<char>()]), 1..4)) {
        let mut s = write(&v, false);
        for (at, edit, with) in edits {
            s = mutated(&s, at, edit, with);
        }
        read(&s);
    }

    #[test]
    fn the_reader_is_total_on_any_text(s in prop_oneof![gen::text(200), gen::text_of("aiOsdbN:;{}\"0123456789", 0..80)]) {
        read(&s);
    }
}

proptest! {
    #![proptest_config(heavy(96, 500))]

    /// Nesting of any depth, and a declared size of any size, read on a worker's stack in bounded memory.
    #[test]
    fn deep_and_wide_values_are_refused_or_read(depth in 0usize..50_000, tag in prop_oneof![Just("a"), Just("O:1:\"x\"")], count in prop_oneof![Just(1u64), any::<u64>()]) {
        let s = format!("{}N;{}", format!("{tag}:{count}:{{i:0;").repeat(depth), "}".repeat(depth));
        let (_, held) = on_worker_stack(move || hxweb_fuzz::alloc::bounded(|| read(&s)));
        prop_assert!(held < 64 << 20, "{} bytes held", held);
    }

    #[test]
    fn deep_query_keys_parse_on_a_worker_stack(depth in 0usize..30_000, pairs in 1usize..4) {
        let q = vec![format!("a{}=1", "[]".repeat(depth)); pairs].join("&");
        on_worker_stack(move || {
            within(PURE, "catalog parse_str", || hxweb::catalog::query::print_r(&hxweb::catalog::query::Value::Map(hxweb::catalog::query::parse_str(&q))));
            within(PURE, "cms parse_str", || hxweb::cms::url::parse_str(&q));
        });
    }
}
