//! The WordPress search page (`/search/`, `/recherche/`) asked for with WordPress's own query vars. The page rule
//! sets `pagename`; a var in the query either leaves the page found, narrows its query to nothing (WordPress's 404,
//! after which redirect_canonical() guesses the page back by its name), or is one redirect_canonical() moves into
//! the path (`paged`, `feed`) or drops (`page`). WPML moves a request in the other language to the translation the
//! same way. Combinations this does not know are left to the caller.

use super::{field, fold, parse_terms, post_content, urlencode};
use crate::{
    catalog::query::{self, Value},
    cms::{Lang, Page, PostType, Site, Status},
};

/// The search page in each language.
const PATHS: [&str; 2] = ["/search/", "/recherche/"];

/// Vars that do not narrow a page's query: WordPress shows the page with them.
const IGNORED: &[&str] = &[
    "search", "cpage", "orderby", "order", "exact", "sentence", "withcomments", "withoutcomments", "error", "posts", "calendar", "more", "pb",
    "job_listing",
];
/// Taxonomy vars, which a page query ignores. WPML translates their ids for a French page, which is not done here.
const TAXONOMY: &[&str] = &["cat", "category_name", "tag", "taxonomy", "term", "faq_type"];
/// Public query vars this module does not answer for (dates, authors, other posts, endpoints).
const OTHER_VARS: &[&str] = &[
    "preview", "m", "w", "year", "monthnum", "day", "hour", "minute", "second", "author", "author_name", "attachment", "subpost", "subpost_id", "post_format",
    "case_study", "faq", "testimonials", "tb", "robots", "favicon", "replytocom", "rest_route", "sitemap", "embed", "hx_form",
];
/// What redirect_canonical() removes after guessing a 404's page, unless the guessed URL carries it.
const GUESS_DROPS: &[&str] = &["page", "feed", "p", "page_id", "attachment_id", "pagename", "name", "post_type"];

#[derive(Debug, PartialEq, Eq)]
pub enum Vars<'a> {
    /// The page, printed by WordPress for this query.
    Show(&'a Page),
    /// WordPress's 404 page.
    NotFound,
    /// A 301 to this path and query on the site.
    Moved(String),
    /// A 301 to a feed, sent with the feed's content type.
    FeedMoved(String, &'static str),
    /// WPML's 302 away from `lang=en`, to this relative Location.
    Found(String),
}

pub fn is_search_page(path: &str) -> bool {
    PATHS.contains(&path)
}

/// PHP's absint() of a query value: its leading digits.
pub(super) fn absint(v: &str) -> u64 {
    let v = v.trim_start();
    let v = v.strip_prefix(['+', '-']).unwrap_or(v);
    v.bytes().take_while(u8::is_ascii_digit).fold(0u64, |n, d| n.saturating_mul(10).saturating_add(u64::from(d - b'0')))
}

/// add_query_arg() of rawurlencoded pairs onto a permalink: the link's own vars keep their place.
fn with_args(permalink: &str, rest: &[(String, String)]) -> String {
    let (path, own) = permalink.split_once('?').unwrap_or((permalink, ""));
    let mut pairs: Vec<(String, String)> = own
        .split('&')
        .filter(|kv| !kv.is_empty())
        .map(|kv| {
            let (k, v) = kv.split_once('=').unwrap_or((kv, ""));
            (k.to_string(), v.to_string())
        })
        .collect();
    for (k, v) in rest {
        let (k, v) = (urlencode(k, true), urlencode(v, true));
        match pairs.iter_mut().find(|(have, _)| *have == k) {
            Some(slot) => slot.1 = v,
            None => pairs.push((k, v)),
        }
    }
    if pairs.is_empty() {
        return path.to_string();
    }
    format!("{path}?{}", pairs.iter().map(|(k, v)| format!("{k}={v}")).collect::<Vec<_>>().join("&"))
}

/// The page's query narrowed by a search for `s`: every term in its title, excerpt or content, none of the negated.
fn page_matches(site: &Site, p: &Page, s: &str) -> bool {
    let terms = parse_terms(s, p.lang);
    if terms.include.is_empty() {
        return false;
    }
    let (title, excerpt, content) = (fold(&p.title), fold(field(site, p.id, "@excerpt")), fold(post_content(site, p.id)));
    let has = |t: &String| title.contains(t.as_str()) || excerpt.contains(t.as_str()) || content.contains(t.as_str());
    terms.include.iter().all(has) && !terms.exclude.iter().any(has)
}

/// The answer for `path` (as asked, percent-decoded) with `raw_query`, when the path is a search page and every
/// WordPress var in the query is one this knows; None otherwise.
pub fn vars<'a>(site: &'a Site, path: &str, raw_query: &str) -> Option<Vars<'a>> {
    let slashed = if path.ends_with('/') { path.to_string() } else { format!("{path}/") };
    if !PATHS.contains(&slashed.as_str()) || path.starts_with("//") {
        return None;
    }
    let entries = query::parse_str(raw_query);
    let mut pairs: Vec<(String, String)> = Vec::with_capacity(entries.len());
    for (k, v) in entries {
        let Value::Str(v) = v else { return None };
        pairs.push((k, v));
    }
    let get = |k: &str| pairs.iter().find(|(have, _)| have == k).map(|(_, v)| v.as_str());
    let last_lang = raw_query.split('&').filter_map(|kv| kv.strip_prefix("lang=")).next_back();
    if last_lang == Some("en") {
        let rest: Vec<&str> = raw_query.split('&').filter(|kv| !kv.is_empty() && kv.split('=').next() != Some("lang")).collect();
        return Some(Vars::Found(if rest.is_empty() { slashed } else { format!("{slashed}?{}", rest.join("&")) }));
    }
    let lang = if last_lang == Some("fr") { Lang::Fr } else { Lang::En };
    let published = |l: Lang| site.find(l, &slashed).filter(|p| p.status == Status::Publish && p.kind == PostType::Page);
    let (page, translated) = match published(lang) {
        Some(p) => (p, false),
        None => {
            let other = if lang == Lang::En { Lang::Fr } else { Lang::En };
            let t = published(other).and_then(|p| site.translation(p, lang)).filter(|t| t.status == Status::Publish && PATHS.contains(&t.path.as_str()))?;
            (t, true)
        }
    };
    for (k, _) in &pairs {
        let k = k.as_str();
        let known = matches!(k, "s" | "page" | "paged" | "p" | "page_id" | "attachment_id" | "name" | "pagename" | "post_type" | "feed" | "lang")
            || IGNORED.contains(&k)
            || (TAXONOMY.contains(&k) && lang == Lang::En);
        if !known && (OTHER_VARS.contains(&k) || TAXONOMY.contains(&k) || super::WP_KEYS.contains(&k)) {
            return None;
        }
    }
    // pagename and name can only name this page; another name is a different query.
    if get("pagename").is_some_and(|v| v != page.name) || get("name").is_some_and(|v| !v.is_empty() && v != page.name) {
        return None;
    }
    let post_type = get("post_type").unwrap_or("");
    let other_type = match post_type {
        "" | "page" | "any" => false,
        "post" | "faq" | "case-study" | "testimonials" | "job_listing" | "attachment" => true,
        _ => return None,
    };
    let feed = match get("feed").unwrap_or("") {
        "" => None,
        "feed" | "rss" | "rss2" => Some(("feed/", "application/rss+xml; charset=UTF-8")),
        "atom" => Some(("feed/atom/", "application/atom+xml; charset=UTF-8")),
        "rdf" => Some(("feed/rdf/", "application/rdf+xml; charset=UTF-8")),
        _ => return None,
    };
    let page_var = get("page").filter(|v| !v.is_empty() && *v != "0");
    let paged = absint(get("paged").unwrap_or(""));
    let s = get("s").unwrap_or("");
    let not_found = translated
        || (!s.is_empty() && !page_matches(site, page, s))
        || absint(get("p").unwrap_or("")) > 0
        || absint(get("page_id").unwrap_or("")) > 0
        || absint(get("attachment_id").unwrap_or("")) > 0
        || get("name").is_some_and(|v| !v.is_empty())
        || other_type
        || page_var.is_some_and(|v| absint(v) > 1);
    let permalink = format!("{}{}", page.path, page.lang.query());
    let asked = if raw_query.is_empty() { path.to_string() } else { format!("{path}?{raw_query}") };
    let moved = |to: String| if to == asked { None } else { Some(Vars::Moved(to)) };
    if not_found {
        if other_type {
            return Some(Vars::NotFound);
        }
        let rest: Vec<(String, String)> = pairs.into_iter().filter(|(k, _)| !GUESS_DROPS.contains(&k.as_str())).collect();
        return Some(moved(with_args(&permalink, &rest)).unwrap_or(Vars::NotFound));
    }
    let mut drops: Vec<&str> = Vec::new();
    let mut base = page.path.clone();
    if page_var.is_some() {
        drops.push("page");
    }
    if paged > 0 {
        drops.push("paged");
        if paged > 1 {
            base.push_str(&format!("page/{paged}/"));
        }
    }
    if let Some((f, _)) = feed {
        drops.push("feed");
        base.push_str(f);
    }
    if drops.is_empty() {
        // Only the trailing slash: the query stays as it was sent.
        return Some(if path == slashed { Vars::Show(page) } else { Vars::Moved(format!("{slashed}{}", if raw_query.is_empty() { String::new() } else { format!("?{raw_query}") })) });
    }
    let rest: Vec<(String, String)> = pairs.into_iter().filter(|(k, _)| !drops.contains(&k.as_str())).collect();
    Some(match (moved(with_args(&format!("{base}{}", page.lang.query()), &rest)), feed) {
        (Some(Vars::Moved(to)), Some((_, kind))) => Vars::FeedMoved(to, kind),
        (Some(v), _) => v,
        (None, _) => Vars::Show(page),
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::cms::{PageId, Template};

    fn page(id: i64, group: i64, lang: Lang, name: &str, path: &str, title: &str) -> Page {
        Page {
            id: PageId(id),
            kind: PostType::Page,
            name: name.into(),
            group_id: group,
            lang,
            path: path.into(),
            template: Template::Page,
            status: Status::Publish,
            title: title.into(),
            parent_id: None,
            menu_order: 0,
            published_at: String::new(),
            modified_at: String::new(),
            author_id: None,
        }
    }

    fn site() -> Site {
        let mut site = Site::new(vec![page(1, 1, Lang::En, "search", "/search/", "Search"), page(2, 1, Lang::Fr, "recherche", "/recherche/", "Recherche")]);
        site.fields.entry(PageId(1)).or_default().insert("@post_content".into(), crate::cms::Field { kind: crate::cms::Kind::Html, value: "scscpq".into() });
        site
    }

    fn ask(path: &str, q: &str) -> Option<String> {
        let site = site();
        vars(&site, path, q).map(|v| match v {
            Vars::Show(p) => format!("show {}", p.path),
            Vars::NotFound => "404".into(),
            Vars::Moved(to) | Vars::FeedMoved(to, _) => format!("301 {to}"),
            Vars::Found(to) => format!("302 {to}"),
        })
    }

    #[test]
    fn a_search_narrows_the_page_and_the_guess_brings_it_back() {
        assert_eq!(ask("/search/", "s=pin").as_deref(), Some("404"));
        assert_eq!(ask("/search/", "s=search").as_deref(), Some("show /search/"));
        assert_eq!(ask("/search/", "s=scscpq").as_deref(), Some("show /search/"));
        assert_eq!(ask("/search/", "s=").as_deref(), Some("show /search/"));
        assert_eq!(ask("/search/", "s=alignment+pin").as_deref(), Some("301 /search/?s=alignment%20pin"));
        assert_eq!(ask("/search/", "s=alignment%20pin").as_deref(), Some("404"));
        assert_eq!(ask("/search/", "s=pin&paged=2").as_deref(), Some("404"));
        assert_eq!(ask("/search/", "s=pin&post_type=page").as_deref(), Some("301 /search/?s=pin"));
        assert_eq!(ask("/search", "s=pin").as_deref(), Some("301 /search/?s=pin"));
        assert_eq!(ask("/search/", "p=1").as_deref(), Some("301 /search/"));
        assert_eq!(ask("/search/", "page_id=2").as_deref(), Some("301 /search/"));
        assert_eq!(ask("/search/", "name=search").as_deref(), Some("301 /search/"));
        assert_eq!(ask("/search/", "post_type=post").as_deref(), Some("404"));
        assert_eq!(ask("/search/", "page=2&paged=3").as_deref(), Some("301 /search/?paged=3"));
    }

    #[test]
    fn paging_and_feeds_move_into_the_path() {
        assert_eq!(ask("/search/", "page=1").as_deref(), Some("301 /search/"));
        assert_eq!(ask("/search/", "page=0").as_deref(), Some("show /search/"));
        assert_eq!(ask("/search/", "paged=2").as_deref(), Some("301 /search/page/2/"));
        assert_eq!(ask("/search/", "paged=abc").as_deref(), Some("show /search/"));
        assert_eq!(ask("/search/", "q=pin&paged=2").as_deref(), Some("301 /search/page/2/?q=pin"));
        assert_eq!(ask("/search/", "s=search&paged=2").as_deref(), Some("301 /search/page/2/?s=search"));
        assert_eq!(ask("/search/", "feed=rss2").as_deref(), Some("301 /search/feed/"));
        assert_eq!(ask("/search/", "feed=atom").as_deref(), Some("301 /search/feed/atom/"));
        assert_eq!(ask("/search/", "cat=1").as_deref(), Some("show /search/"));
        assert_eq!(ask("/search", "s=").as_deref(), Some("301 /search/?s="));
    }

    #[test]
    fn languages_follow_wpml() {
        assert_eq!(ask("/search/", "lang=en&s=pin").as_deref(), Some("302 /search/?s=pin"));
        assert_eq!(ask("/search/", "s=pin&lang=fr").as_deref(), Some("301 /recherche/?lang=fr&s=pin"));
        assert_eq!(ask("/search/", "lang=fr&page=2").as_deref(), Some("301 /recherche/?lang=fr"));
        assert_eq!(ask("/search/", "lang=fr&paged=2").as_deref(), Some("301 /recherche/?lang=fr&paged=2"));
        assert_eq!(ask("/recherche/", "s=pin").as_deref(), Some("301 /search/?s=pin"));
        assert_eq!(ask("/recherche/", "lang=fr&s=pin").as_deref(), Some("404"));
        assert_eq!(ask("/recherche/", "lang=fr&s=recherche").as_deref(), Some("show /recherche/"));
        assert_eq!(ask("/recherche/", "lang=fr&paged=2").as_deref(), Some("301 /recherche/page/2/?lang=fr"));
        assert_eq!(ask("/recherche/", "lang=fr&cat=1"), None, "WPML translates the category");
        assert_eq!(ask("/search/", "year=2020"), None);
        assert_eq!(ask("/catalog/", "s=pin"), None);
    }
}
