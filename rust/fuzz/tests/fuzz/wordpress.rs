//! WordPress's routing of a path and query over a site of any shape: pages and posts in both languages, published
//! or not, translations, attachments, odd slugs.

use hxweb::{
    cms::{Lang, Media, MediaId, Page, PageId, PostType, Site, Status, Template},
    routes::wordpress::{query, resolve, route, Answer},
};
use hxweb_fuzz::{config, gen, within, PURE};
use proptest::{prelude::*, sample::select};

const SLUGS: &[&str] = &["contact", "contact-form", "company", "product", "alignment", "faq", "news", "c", "a", "2024", "feed", "page", "catalogue", "about-us", "é"];

fn lang() -> impl Strategy<Value = Lang> {
    prop_oneof![Just(Lang::En), Just(Lang::Fr)]
}

fn kind() -> impl Strategy<Value = PostType> {
    prop_oneof![4 => Just(PostType::Page), 2 => Just(PostType::Post), 1 => Just(PostType::Faq), 1 => Just(PostType::CaseStudy), 1 => Just(PostType::Testimonials)]
}

fn slug_path() -> impl Strategy<Value = String> {
    proptest::collection::vec(select(SLUGS), 0..3).prop_map(|s| if s.is_empty() { "/".to_string() } else { format!("/{}/", s.join("/")) })
}

fn page() -> impl Strategy<Value = Page> {
    (1i64..60, 1i64..12, lang(), kind(), slug_path(), any::<bool>(), proptest::option::of(select(SLUGS)), "20[0-2][0-9]-0[1-9]-1[0-9] 00:00:00")
        .prop_map(|(id, group, lang, kind, path, published, name, at)| Page {
            id: PageId(id),
            kind,
            name: name.unwrap_or("").to_string(),
            group_id: group,
            lang,
            path: if published { path } else { format!("/?page_id={id}") },
            template: Template::Page,
            status: if published { Status::Publish } else { Status::Draft },
            title: "A page".into(),
            parent_id: None,
            menu_order: 0,
            published_at: at.clone(),
            modified_at: at,
            author_id: None,
        })
}

fn site() -> impl Strategy<Value = Site> {
    (proptest::collection::vec(page(), 0..14), proptest::collection::vec(select(SLUGS), 0..3)).prop_map(|(mut pages, media)| {
        pages.sort_by_key(|p| p.id);
        pages.dedup_by_key(|p| p.id);
        let mut site = Site::new(pages);
        for (i, name) in media.into_iter().enumerate() {
            let id = MediaId(1000 + i as i64);
            site.media.insert(id, Media { id, file: "2020/01/x.png".into(), mime: "image/png".into(), width: 1, height: 1, alt: String::new(), sizes: Vec::new(), name: name.into(), parent_id: None, lang: None, group_id: None });
        }
        site
    })
}

/// A path as visitors type them: the site's slugs, other case, a missing or doubled slash, or anything.
fn request_path() -> impl Strategy<Value = String> {
    prop_oneof![
        4 => (slug_path(), any::<bool>(), any::<bool>(), any::<bool>()).prop_map(|(p, bare, upper, doubled)| {
            let p = if bare && p.len() > 1 { p.trim_end_matches('/').to_string() } else { p };
            let p = if upper { p.to_uppercase() } else { p };
            if doubled { format!("/{p}") } else { p }
        }),
        1 => gen::path(),
        1 => gen::text(30).prop_map(|t| format!("/{t}")),
    ]
}

fn request_query() -> impl Strategy<Value = String> {
    prop_oneof![
        Just(String::new()),
        Just("lang=fr".to_string()),
        Just("lang=en".to_string()),
        (select(&["p", "page_id", "cat", "m", "name", "feed", "page", "s", "utm_source", "lang"][..]), prop_oneof!["[0-9]{1,7}", select(SLUGS).prop_map(str::to_string), "[a-z0-9]{0,4}"])
            .prop_map(|(k, v)| format!("{k}={v}")),
        gen::query(),
    ]
}

proptest! {
    #![proptest_config(config(16384))]

    /// Every answer is a page that is published, a 404, the legacy stack, or a redirect to a path of this site.
    #[test]
    fn routing_answers_with_a_published_page_or_a_site_path(site in site(), path in request_path(), q in request_query(), extra in proptest::option::of(request_query())) {
        let q = match extra {
            Some(e) if !e.is_empty() && !q.is_empty() => format!("{q}&{e}"),
            Some(e) if !e.is_empty() => e,
            _ => q,
        };
        let parsed = within(PURE, "query", || query(&q));
        let answer = within(PURE, "route", || route(&site, &path, &parsed, &q));
        match &answer {
            Answer::Render(p) => prop_assert_eq!(p.status, Status::Publish),
            Answer::Redirect(to) | Answer::Found(to) | Answer::TranslationFound(to) | Answer::FeedRedirect(to, _) => {
                prop_assert!(to.starts_with('/'), "{:?} for {} ? {}", answer, path, q)
            }
            Answer::Archive(_) | Answer::Search { .. } | Answer::NotFound | Answer::BlogNotFound | Answer::Legacy => {}
        }
        for lang in [Lang::En, Lang::Fr] {
            within(PURE, "resolve", || resolve(&site, &path, lang));
        }
    }
}
