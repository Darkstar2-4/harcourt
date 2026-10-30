//! index.php for an FAQ type's archive (`/faq_type/<slug>/`), which the static export did not record: the type's
//! FAQs in the request's language, newest first, as loops/index-post.php prints them, then the FAQ widget area,
//! in the 404 page's chrome with Yoast's head for the archive and WPML's links to the term.

use std::collections::BTreeMap;

use super::{dropdown_switcher, menu_switcher, without_post, NOT_FOUND_OPTION, ORIGIN};
use crate::cms::{
    html::esc,
    model::{Lang, Page, PostType, Site, Status, Term},
    yoast::{self, json},
};

/// posts_per_page.
const PER_PAGE: usize = 10;

/// Yoast's default title template for a term archive, and WPML's French translation of it, which lives in WPML's
/// string tables and not in the dump.
const TERM_TITLE: &str = "%%term_title%% Archives %%page%% %%sep%% %%sitename%%";
const TERM_TITLE_FR: &str = "Archives %%term_title%% %%page%% %%sep%% %%sitename%%";

/// The archive of `term` (an FAQ type) in `lang` at `uri`, with `{{gform:N}}` holes for the footer's form; None
/// when it shows something this port does not render (more than one page, a post without a recorded head).
pub fn faq_type(site: &Site, term: &Term, lang: Lang, uri: &str) -> Option<String> {
    let mut posts: Vec<&Page> = term.pages.iter().filter_map(|id| site.page(*id)).filter(|p| p.status == Status::Publish && p.lang == lang).collect();
    posts.sort_by(|a, b| (b.published_at.as_str(), b.id).cmp(&(a.published_at.as_str(), a.id)));
    if posts.is_empty() || posts.len() > PER_PAGE || posts.iter().any(|p| p.kind != PostType::Faq) {
        return None;
    }
    let mut items = String::new();
    for p in &posts {
        items.push_str(&index_post(site, p)?);
    }
    let main = format!(
        "\n\n<main class=\"container mt-5\">\n  <div class=\"row\">\n\n    <div class=\"col-sm\">\n      <div id=\"content\" role=\"main\"> 1\n\n        \n{items}\n      </div><!-- /#content -->\n    </div>\n\n    "
    );
    let holes = holes(site, term, lang, &posts)?;
    let html = without_post(site, lang, uri, &holes, &main);
    let feed = match lang {
        Lang::En => format!("<link rel=\"alternate\" type=\"application/rss+xml\" title=\"Harcourt &raquo; {} Type Feed\" href=\"{ORIGIN}{}feed/\" />\n", esc(&term.name), term.path()),
        Lang::Fr => format!("<link rel=\"alternate\" type=\"application/rss+xml\" title=\"Flux pour Harcourt &raquo; {} Type\" href=\"{ORIGIN}{}feed/\" />\n", esc(&term.name), term.path()),
    };
    Some(after_comments_feed(html, &feed))
}

/// feed_links_extra(): the archive's own feed, after the site's comments feed (the chrome has no hole there).
fn after_comments_feed(html: String, line: &str) -> String {
    let at = html.find("/comments/feed/").and_then(|c| html[c..].find("/>\n").map(|e| c + e + 3));
    match at {
        Some(at) => format!("{}{line}{}", &html[..at], &html[at..]),
        None => html,
    }
}

/// post_class() of a post in a loop.
fn post_class(site: &Site, p: &Page) -> String {
    let kind = p.kind.as_str();
    let mut classes = format!("post-{} {kind} type-{kind} status-publish hentry", p.id.0);
    for t in site.terms.iter().filter(|t| t.pages.contains(&p.id)) {
        classes.push_str(&format!(" {}-{}", t.taxonomy.as_str(), t.slug));
    }
    classes
}

/// loops/index-post.php for an FAQ: the title, no date (momentumst_post_date() prints one for posts only), the
/// author's link, the comments link and the content as the_content() printed it.
fn index_post(site: &Site, p: &Page) -> Option<String> {
    if !site.meta(p.id).contains_key("title") || site.fields(p.id).get("@thumbnail").is_some_and(|f| super::truthy(&f.value)) {
        return None;
    }
    let content = &site.fields(p.id).get("content")?.value;
    let name = yoast::author_name(site, p.author_id)?;
    let slug = yoast::author_slug(site, p.author_id)?;
    let title = site.meta(p.id).get("the_title")?;
    let url = esc(&p.url(ORIGIN));
    let posts_by = match p.lang {
        Lang::En => "Posts by",
        Lang::Fr => "Articles par",
    };
    Some(format!(
        "\n  \n<article role=\"article\" id=\"post_{id}\" class=\"mb-5 {class}\" >\n  <header>\n    <h2>\n      <a href=\"{url}\">\n        {title}      </a>\n    </h2>\n    <p class=\"text-muted\">\n      <i class=\"far fa-calendar-alt\"></i>&nbsp;&nbsp;|\n      <i class=\"far fa-user\"></i>&nbsp; By <a href=\"{ORIGIN}/author/{slug}/{q}\" title=\"{posts_by} {name}\" rel=\"author\">{name}</a>&nbsp;|\n      <i class=\"far fa-comment\"></i>&nbsp;<a href=\"{url}#respond\">0 Comments</a>\n    </p>\n  </header>\n  <div>\n    \n    {content}  </div>\n</article>\n",
        id = p.id.0,
        class = post_class(site, p),
        q = p.lang.query(),
        name = esc(&name),
    ))
}

/// The holes of the archive's head and header that differ from the 404 page's.
fn holes(site: &Site, term: &Term, lang: Lang, posts: &[&Page]) -> Option<BTreeMap<&'static str, String>> {
    let english = yoast::setting(site, "title-tax-faq_type")?;
    let template = match lang {
        Lang::En => english,
        Lang::Fr if english == TERM_TITLE => TERM_TITLE_FR,
        Lang::Fr => return None,
    };
    let title = yoast::fill(site, template, &[("term_title", &term.name)])?;
    let noindex = yoast::setting(site, "noindex-tax-faq_type") == Some("1");
    let url = format!("{ORIGIN}{}", term.path());
    let home = yoast::home_crumb(site, lang)?;
    let not_found = |name: &str| site.options(lang).get(&format!("{NOT_FOUND_OPTION}{name}")).map(|f| f.value.clone());
    let first = posts.first()?;
    let mut h = BTreeMap::new();
    h.insert("title", esc(&title));
    h.insert("robots", if noindex { "noindex, follow" } else { yoast::INDEXED }.to_string());
    h.insert("seo_description_canonical", if noindex { String::new() } else { format!("\t<link rel=\"canonical\" href=\"{url}\" />\n") });
    h.insert("seo_og_type", "\t<meta property=\"og:type\" content=\"article\" />\n".to_string());
    h.insert("seo_og_description_url", format!("\t<meta property=\"og:url\" content=\"{url}\" />\n"));
    h.insert("seo_article_twitter", format!("\t<meta name=\"twitter:card\" content=\"summary_large_image\" />\n{}", yoast::social_line(site, "\"twitter:site\"")));
    let (u, crumbs) = (json(&url), json(&format!("{url}#breadcrumb")));
    h.insert(
        "yoast_schema",
        format!(
            "{{\"@context\":\"https:\\/\\/schema.org\",\"@graph\":[{{\"@type\":\"CollectionPage\",\"@id\":{u},\"url\":{u},\"name\":{},\"isPartOf\":{{\"@id\":\"https:\\/\\/www.harcourt.co\\/#website\"}},\"breadcrumb\":{{\"@id\":{crumbs}}},\"inLanguage\":\"{}\"}},{{\"@type\":\"BreadcrumbList\",\"@id\":{crumbs},\"itemListElement\":[{{\"@type\":\"ListItem\",\"position\":1,\"name\":{},\"item\":\"https:\\/\\/www.harcourt.co\\/\"}},{{\"@type\":\"ListItem\",\"position\":2,\"name\":{}}}]}},{}",
            json(&title),
            yoast::in_language(lang),
            json(&home),
            json(&term.name),
            yoast::site_graph(site, lang)?
        ),
    );
    let kind = first.kind.as_str();
    h.insert("datalayer", format!("{{\"pagePostType\":\"{kind}\",\"pagePostType2\":\"tax-{kind}\",\"pageCategory\":[]}}"));
    h.insert("body_class", not_found("body_class")?.replacen("error404", &format!("archive tax-faq_type term-{} term-{}", term.slug, term.id), 1));
    // The type is not translated: WPML links the archive in the request's language only, at its plain address.
    h.insert("hreflang", String::new());
    h.insert("lang_switcher", menu_switcher(lang, &[(lang, url.clone())]));
    h.insert("wpml_hidden_switcher", dropdown_switcher(lang, &[(lang, url.clone())]));
    h.insert("rest_link", format!("<link rel=\"alternate\" title=\"JSON\" type=\"application/json\" href=\"{ORIGIN}/wp-json/wp/v2/faq_type/{}\" />", term.id));
    h.insert("sidebar", yoast::faq_widgets(site, lang)?);
    Some(h)
}
