//! The answers of WordPress's REST API (`?rest_route=`) that need no WordPress: no route for a path none of the
//! site's routes matches, an invalid id for a post that does not exist, and the ids of the posts. Every other REST
//! request stays with WordPress.

use super::{
    model::{Lang, PageId, PostType, Site, Status},
    url::{parse_str, Arg},
};

/// The bases under `/wp/v2` the site's WordPress registers (its REST index): core's, and the site's post types and
/// taxonomies.
const WP_V2: [&str; 37] = [
    "block-directory", "block-patterns", "block-renderer", "block-types", "blocks", "case_study", "categories", "comments", "faq", "faq_type",
    "font-collections", "font-families", "global-styles", "media", "menu-items", "menu-locations", "menus", "navigation", "pages",
    "pattern-directory", "plugins", "posts", "search", "settings", "sidebars", "statuses", "tags", "taxonomies", "template-parts", "templates",
    "testimonials", "themes", "types", "users", "widget-types", "widgets", "wp_pattern_category",
];

/// The parameters of the posts collection that change what it lists or how it is printed.
const COLLECTION_PARAMS: [&str; 30] = [
    "context", "search", "after", "modified_after", "author", "author_exclude", "before", "modified_before", "exclude", "include", "offset",
    "order", "orderby", "search_columns", "slug", "status", "tax_relation", "categories", "categories_exclude", "tags", "tags_exclude", "sticky",
    "format", "_embed", "_envelope", "_jsonp", "_method", "lang", "password", "wpml_language",
];

/// A REST answer: its status and JSON body.
#[derive(Debug, PartialEq, Eq)]
pub struct Reply {
    pub status: u16,
    pub body: String,
}

fn error(code: &str, message: &str, status: u16) -> Reply {
    Reply { status, body: format!("{{\"code\":\"{code}\",\"message\":\"{message}\",\"data\":{{\"status\":{status}}}}}") }
}

/// The reply to a GET carrying `rest_route`, from the query as sent; None when WordPress must answer.
pub fn answer(site: &Site, raw_query: &str) -> Option<Reply> {
    let args = parse_str(raw_query);
    let one = |k: &str| {
        args.iter().find(|(name, _)| name == k).and_then(|(_, a)| match a {
            Arg::One(v) => Some(v.as_str()),
            Arg::List(_) => None,
        })
    };
    let route = one("rest_route")?;
    // untrailingslashit(), and an empty route is the index.
    let route = match route.trim_end_matches('/') {
        "" => "/",
        r => r,
    };
    if route == "/" {
        return None;
    }
    if ["_method", "_envelope", "_jsonp"].iter().any(|k| args.iter().any(|(name, _)| name == k)) {
        return None;
    }
    let lower = route.to_ascii_lowercase();
    let segments: Vec<&str> = lower.split('/').collect();
    // Routes are matched from their leading slash; anything else matches none.
    if !lower.starts_with('/') {
        return Some(error("rest_no_route", "No route was found matching the URL and request method.", 404));
    }
    // The index, the namespace's own route, or another namespace (the plugins'): WordPress knows those routes.
    if segments.get(1..3) != Some(&["wp", "v2"][..]) {
        return None;
    }
    let base = segments.get(3)?;
    if !WP_V2.contains(base) {
        return Some(error("rest_no_route", "No route was found matching the URL and request method.", 404));
    }
    if *base != "posts" {
        return None;
    }
    match segments.get(4..) {
        Some([]) | None => posts(site, &args),
        Some([id]) if !id.is_empty() && id.bytes().all(|b| b.is_ascii_digit()) => {
            if args.iter().any(|(k, _)| k == "context" || k == "password") {
                return None;
            }
            let id = id.parse::<i64>().ok()?;
            let is_post = site.page(PageId(id)).is_some_and(|p| p.kind == PostType::Post);
            (!is_post).then(|| error("rest_post_invalid_id", "Invalid post ID.", 404))
        }
        _ => None,
    }
}

/// The posts collection asked for their ids only (`_fields=id`): the published posts of the default language,
/// newest first, one page of them.
fn posts(site: &Site, args: &[(String, Arg)]) -> Option<Reply> {
    let one = |k: &str| {
        args.iter().find(|(name, _)| name == k).map(|(_, a)| match a {
            Arg::One(v) => Some(v.as_str()),
            Arg::List(_) => None,
        })
    };
    if one("_fields")?? != "id" || args.iter().any(|(k, _)| COLLECTION_PARAMS.contains(&k.as_str())) {
        return None;
    }
    let number = |k: &str, default: usize| -> Option<usize> {
        match one(k) {
            None => Some(default),
            Some(v) => v?.parse::<usize>().ok().filter(|n| *n >= 1),
        }
    };
    let (per_page, page) = (number("per_page", 10)?, number("page", 1)?);
    if per_page > 100 {
        return None;
    }
    let mut listed: Vec<_> = site.pages.iter().filter(|p| p.kind == PostType::Post && p.status == Status::Publish && p.lang == Lang::En).collect();
    listed.sort_by(|a, b| (b.published_at.as_str(), b.id).cmp(&(a.published_at.as_str(), a.id)));
    // A page past the last is WordPress's rest_post_invalid_page_number.
    if page > 1 && (page - 1) * per_page >= listed.len() {
        return None;
    }
    let ids: Vec<String> = listed.iter().skip((page - 1) * per_page).take(per_page).map(|p| format!("{{\"id\":{}}}", p.id.0)).collect();
    Some(Reply { status: 200, body: format!("[{}]", ids.join(",")) })
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::cms::model::{Page, Template};

    fn post(id: i64, lang: Lang, date: &str) -> Page {
        Page {
            id: PageId(id),
            kind: PostType::Post,
            name: format!("p{id}"),
            group_id: id,
            lang,
            path: format!("/p{id}/"),
            template: Template::Single,
            status: Status::Publish,
            title: String::new(),
            parent_id: None,
            menu_order: 0,
            published_at: date.into(),
            modified_at: date.into(),
            author_id: None,
        }
    }

    #[test]
    fn rest_routes_the_site_does_not_register_have_no_route() {
        let site = Site::new(vec![post(5, Lang::En, "2020-01-01 00:00:00"), post(6, Lang::En, "2021-01-01 00:00:00"), post(7, Lang::Fr, "2022-01-01 00:00:00")]);
        let a = |q: &str| answer(&site, q).map(|r| format!("{} {}", r.status, r.body));
        let no_route = "404 {\"code\":\"rest_no_route\",\"message\":\"No route was found matching the URL and request method.\",\"data\":{\"status\":404}}";
        assert_eq!(a("rest_route=x&postsToShow=5").as_deref(), Some(no_route));
        assert_eq!(a("rest_route=/wp/v2/product&per_page=5").as_deref(), Some(no_route));
        assert_eq!(a("rest_route=%2FWP%2Fv2%2Fcourse%2F").as_deref(), Some(no_route));
        assert_eq!(a("rest_route=/wp/v2/posts/999&per_page=5").as_deref(), Some("404 {\"code\":\"rest_post_invalid_id\",\"message\":\"Invalid post ID.\",\"data\":{\"status\":404}}"));
        assert_eq!(a("rest_route=/wp/v2/posts/5"), None, "a post's own JSON is WordPress's");
        assert_eq!(a("rest_route=/wp/v2/posts&per_page=1&_fields=id&probe=1").as_deref(), Some("200 [{\"id\":6}]"));
        assert_eq!(a("rest_route=/wp/v2/posts&_fields=id").as_deref(), Some("200 [{\"id\":6},{\"id\":5}]"), "the default language's, newest first");
        assert_eq!(a("rest_route=/wp/v2/posts&_fields=id&per_page=1&page=3"), None);
        assert_eq!(a("rest_route=/wp/v2/posts&_fields=id&search=x"), None);
        assert_eq!(a("rest_route=/wp/v2/posts"), None);
        assert_eq!(a("rest_route=/wp/v2/pages"), None);
        assert_eq!(a("rest_route=/"), None);
        assert_eq!(a("rest_route="), None, "an empty route is the index");
        assert_eq!(a("rest_route=//"), None);
        assert_eq!(a("rest_route=/yoast/v1/x"), None);
        assert_eq!(a("rest_route=x&_jsonp=f"), None);
    }
}
