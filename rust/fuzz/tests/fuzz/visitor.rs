//! The front's path clean-up: a request Apache would have run a PHP script for is routed as that script.

use axum::{body::Body, extract::Request, http::Uri};
use hxweb::visitor::{apache_paths, SentPath};
use hxweb_fuzz::{config, gen, within, PURE};
use proptest::prelude::*;

fn with_script() -> impl Strategy<Value = String> {
    (gen::path(), proptest::sample::select(&["login.php", "cart.php", "catalog.php", "x.PHP", "a.php", "dashboard.php"][..]), gen::path(), any::<bool>())
        .prop_map(|(before, script, after, rest)| if rest { format!("{before}/{script}{after}") } else { format!("{before}/{script}") })
}

proptest! {
    #![proptest_config(config(4096))]

    #[test]
    fn the_clean_up_is_total_idempotent_and_names_a_script(path in prop_oneof![with_script(), gen::path(), gen::url_text(12).prop_map(|t| format!("/{t}"))], q in proptest::option::of(gen::query())) {
        let target = match &q {
            Some(q) => format!("{path}?{q}"),
            None => path.clone(),
        };
        let Ok(uri) = target.parse::<Uri>() else { return Ok(()) };
        let req = Request::builder().uri(uri.clone()).body(Body::empty()).unwrap();
        let once = within(PURE, "apache_paths", || apache_paths(req));
        let routed = once.uri().clone();
        if routed != uri {
            let script = routed.path();
            prop_assert!(script.to_ascii_lowercase().ends_with(".php"), "{} from {}", script, uri);
            prop_assert!(script.split('/').skip(1).all(|s| !matches!(s, "" | "." | "..")), "{} from {}", script, uri);
            prop_assert_eq!(routed.query(), uri.query());
            prop_assert!(once.extensions().get::<SentPath>().is_some_and(|s| s.uri == uri));
        }
        let twice = apache_paths(Request::builder().uri(routed.clone()).body(Body::empty()).unwrap());
        prop_assert_eq!(twice.uri(), &routed, "from {}", uri);
    }
}
