//! The small readers: a catalog page's request, the loader's actions, sitemap names, the search box test, the
//! WordPress dump's INSERT lines and the stored enums, on any text.

use hxweb::{
    catalog::{ajax::search_box_query, loader::Action, pages::Request},
    cms::{import::dump, sitemap::Map, Lang, PostType, Status, Template},
    identity::{Role, Status as UserStatus},
    orders::{form_params, forms::model},
};
use hxweb_fuzz::{config, gen, within, PURE};
use proptest::prelude::*;

/// An INSERT line of a mysqldump: values of every kind, quoted and escaped as mysqldump writes them, then edited.
fn insert_line() -> impl Strategy<Value = String> {
    let value = prop_oneof![
        Just("NULL".to_string()),
        any::<i64>().prop_map(|n| n.to_string()),
        gen::text(16).prop_map(|t| format!("'{}'", t.replace('\\', "\\\\").replace('\'', "\\'").replace('\n', "\\n"))),
        gen::text_of("'\\(),;0123456789abc", 0..12),
    ];
    let row = proptest::collection::vec(value, 1..5).prop_map(|v| format!("({})", v.join(",")));
    (proptest::sample::select(&["wp_posts", "wp_postmeta", "wp_options", "x"][..]), proptest::collection::vec(row, 1..4), any::<bool>())
        .prop_map(|(table, rows, end)| format!("INSERT INTO `{table}` VALUES {}{}", rows.join(","), if end { ";" } else { "" }))
}

proptest! {
    #![proptest_config(config(2048))]

    #[test]
    fn request_readers_are_total(q in gen::query(), word in gen::text(30)) {
        let params = form_params(Some(&q), b"");
        within(PURE, "catalog Request::parse", || Request::parse(&params));
        within(PURE, "search_box_query", || search_box_query(&q));
        let _ = (Action::parse(&word), Map::parse(&word), Template::parse(&word), PostType::parse(&word), Lang::parse(&word), Status::parse(&word));
        let _ = (Role::parse(&word), UserStatus::parse(&word), model::Lang::parse(&word));
    }

    #[test]
    fn dump_reading_is_total(lines in proptest::collection::vec(prop_oneof![3 => insert_line(), 1 => gen::text(60)], 0..6)) {
        let text = lines.join("\n");
        let _ = within(PURE, "dump::parse", || dump::parse(&text));
    }
}
