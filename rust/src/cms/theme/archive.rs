//! An FAQ type's archive, the list of its FAQs as the theme's index.php prints it, which the static export did not
//! record: in the 404 page's chrome, with Yoast's head for the archive and WPML's links to it.

use std::collections::BTreeMap;

use super::{content::index_main, dropdown_switcher, menu_switcher, without_post, NOT_FOUND_OPTION, ORIGIN, UPLOADS};
use crate::cms::{
    html::esc,
    media::{Images, Phase},
    model::{Lang, MediaId, Page, Site, Status, Taxonomy, Term},
    yoast::{self, json},
};

/// posts_per_page.
const PER_PAGE: usize = 10;

/// Yoast's default title template for a term archive, and WPML's French translation of it, which lives in WPML's
/// string tables and not in the dump.
const TERM_TITLE: &str = "%%term_title%% Archives %%page%% %%sep%% %%sitename%%";
const TERM_TITLE_FR: &str = "Archives %%term_title%% %%page%% %%sep%% %%sitename%%";

/// Yoast's title for the archive of `term` in `lang`, on its first page.
pub fn term_title(site: &Site, term: &Term, lang: Lang) -> Option<String> {
    let english = yoast::setting(site, &format!("title-tax-{}", term.taxonomy.as_str()))?;
    let template = match lang {
        Lang::En => english,
        Lang::Fr if english == TERM_TITLE => TERM_TITLE_FR,
        Lang::Fr => return None,
    };
    yoast::fill(site, template, &[("term_title", &term.name)])
}

/// The published posts among `posts` in `lang`, newest first, as the main query lists them.
pub fn listed<'a>(posts: impl Iterator<Item = &'a Page>, lang: Lang) -> Vec<&'a Page> {
    let mut posts: Vec<&Page> = posts.filter(|p| p.status == Status::Publish && p.lang == lang).collect();
    posts.sort_by(|a, b| (b.published_at.as_str(), b.id).cmp(&(a.published_at.as_str(), a.id)));
    posts
}

/// The term's translations as WPML links them: each language's term at its address, or the term alone at its plain
/// address when it is not translated.
fn alternates<'a>(site: &'a Site, term: &'a Term, lang: Lang) -> Vec<(Lang, &'a Term, String)> {
    let translations: Vec<&Term> = match term.group_id {
        Some(g) => Lang::ALL.iter().filter_map(|&l| site.terms.iter().find(|t| t.taxonomy == term.taxonomy && t.group_id == Some(g) && t.lang == Some(l))).collect(),
        None => Vec::new(),
    };
    if translations.len() < 2 {
        return vec![(lang, term, format!("{ORIGIN}{}", term.path()))];
    }
    translations.into_iter().filter_map(|t| t.lang.map(|l| (l, t, format!("{ORIGIN}{}{}", t.path(), l.query())))).collect()
}

/// The archive of an FAQ type in `lang` at `uri`, with `{{gform:N}}` holes for the footer's form; None for another
/// taxonomy or when it shows something this port does not render (no FAQ or more than a page of them, a widget area
/// the cms does not hold).
pub fn term(site: &Site, term: &Term, lang: Lang, uri: &str) -> Option<String> {
    if term.taxonomy != Taxonomy::FaqType {
        return None;
    }
    let posts = listed(term.pages.iter().filter_map(|id| site.page(*id)), lang);
    if posts.is_empty() || posts.len() > PER_PAGE || posts.iter().any(|p| p.kind != term.taxonomy.of()) {
        return None;
    }
    let mut images = Images::default();
    images.set_phase(Phase::Loop);
    let main = index_main(site, &posts, &mut images)?;
    let alternates = alternates(site, term, lang);
    let own = alternates.iter().find(|(l, _, _)| *l == lang).map(|(_, _, u)| u.clone())?;
    let holes = holes(site, term, lang, &posts, &alternates)?;
    let html = without_post(site, lang, uri, &holes, &main);
    let name = esc(&term.name);
    let link = format!("{}feed/", if own.ends_with('/') { own.clone() } else { format!("{own}/") });
    let title = match lang {
        Lang::En => format!("Harcourt &raquo; {name} Type Feed"),
        Lang::Fr => format!("Flux pour Harcourt &raquo; {name} Type"),
    };
    Some(after_comments_feed(html, &format!("<link rel=\"alternate\" type=\"application/rss+xml\" title=\"{title}\" href=\"{link}\" />\n")))
}

/// feed_links_extra(): the archive's own feed, after the site's comments feed (the chrome has no hole there).
fn after_comments_feed(html: String, line: &str) -> String {
    let at = html.find("/comments/feed/").and_then(|c| html[c..].find("/>\n").map(|e| c + e + 3));
    match at {
        Some(at) => format!("{}{line}{}", &html[..at], &html[at..]),
        None => html,
    }
}

/// sidebar.php after the loop, where `$post` is the last post shown: an FAQ shows the FAQ widget area, any other
/// post its own `sidebar_select` area (none here when it names one, which only an exported page recorded).
fn last_sidebar(site: &Site, last: &Page) -> Option<String> {
    if last.kind == crate::cms::model::PostType::Faq {
        return yoast::faq_widgets(site, last.lang);
    }
    site.fields(last.id).get("sidebar_select").is_none_or(|f| f.value.is_empty()).then(String::new)
}

/// The holes of the archive's head and header that differ from the 404 page's.
fn holes(site: &Site, term: &Term, lang: Lang, posts: &[&Page], alternates: &[(Lang, &Term, String)]) -> Option<BTreeMap<&'static str, String>> {
    let tax = term.taxonomy.as_str();
    let title = term_title(site, term, lang)?;
    let noindex = yoast::setting(site, &format!("noindex-tax-{tax}")) == Some("1");
    // Yoast's indexable is the English term's: its address and name stand for every translation.
    let (english_term, url) = alternates.iter().find(|(l, _, _)| *l == Lang::En).map(|(_, t, u)| (*t, u.clone())).unwrap_or((term, format!("{ORIGIN}{}", term.path())));
    let home = yoast::home_crumb(site, lang)?;
    let not_found = |name: &str| site.options(lang).get(&format!("{NOT_FOUND_OPTION}{name}")).map(|f| f.value.clone());
    let (first, last) = (posts.first()?, posts.last()?);
    let in_lang = yoast::in_language(lang);
    let mut h = BTreeMap::new();
    h.insert("title", esc(&title));
    h.insert("robots", if noindex { "noindex, follow" } else { yoast::INDEXED }.to_string());
    h.insert("seo_description_canonical", if noindex { String::new() } else { format!("\t<link rel=\"canonical\" href=\"{url}\" />\n") });
    h.insert("seo_og_type", "\t<meta property=\"og:type\" content=\"article\" />\n".to_string());
    h.insert("seo_og_description_url", format!("\t<meta property=\"og:url\" content=\"{url}\" />\n"));
    h.insert("seo_article_twitter", format!("\t<meta name=\"twitter:card\" content=\"summary_large_image\" />\n{}", yoast::social_line(site, "\"twitter:site\"")));
    let (u, crumbs, primary) = (json(&url), json(&format!("{url}#breadcrumb")), json(&format!("{url}#primaryimage")));
    // The first post's featured image is the page's.
    let image = site.fields(first.id).get("@thumbnail").and_then(|f| f.value.trim().parse::<i64>().ok()).and_then(|id| site.media.get(&MediaId(id)));
    let (image_refs, image_node) = match image {
        Some(m) => {
            let src = json(&format!("{UPLOADS}{}", m.file));
            let caption = if m.alt.is_empty() { String::new() } else { format!(",\"caption\":{}", json(&m.alt)) };
            (
                format!("\"primaryImageOfPage\":{{\"@id\":{primary}}},\"image\":{{\"@id\":{primary}}},\"thumbnailUrl\":{src},"),
                format!("{{\"@type\":\"ImageObject\",\"inLanguage\":\"{in_lang}\",\"@id\":{primary},\"url\":{src},\"contentUrl\":{src},\"width\":{},\"height\":{}{caption}}},", m.width, m.height),
            )
        }
        None => (String::new(), String::new()),
    };
    h.insert(
        "yoast_schema",
        format!(
            "{{\"@context\":\"https:\\/\\/schema.org\",\"@graph\":[{{\"@type\":\"CollectionPage\",\"@id\":{u},\"url\":{u},\"name\":{},\"isPartOf\":{{\"@id\":\"https:\\/\\/www.harcourt.co\\/#website\"}},{image_refs}\"breadcrumb\":{{\"@id\":{crumbs}}},\"inLanguage\":\"{in_lang}\"}},{image_node}{{\"@type\":\"BreadcrumbList\",\"@id\":{crumbs},\"itemListElement\":[{{\"@type\":\"ListItem\",\"position\":1,\"name\":{},\"item\":\"https:\\/\\/www.harcourt.co\\/\"}},{{\"@type\":\"ListItem\",\"position\":2,\"name\":{}}}]}},{}",
            json(&title),
            json(&home),
            json(&english_term.name),
            yoast::site_graph(site, lang)?
        ),
    );
    let kind = first.kind.as_str();
    let categories: Vec<String> = site.terms.iter().filter(|t| t.taxonomy == Taxonomy::Category && t.pages.contains(&first.id)).map(|t| json(&t.slug)).collect();
    let class = format!("archive tax-faq_type term-{} term-{}", term.slug, term.id);
    h.insert("datalayer", format!("{{\"pagePostType\":\"{kind}\",\"pagePostType2\":\"tax-{kind}\",\"pageCategory\":[{}]}}", categories.join(",")));
    h.insert("body_class", not_found("body_class")?.replacen("error404", &class, 1));
    let links: Vec<(Lang, String)> = alternates.iter().map(|(l, _, u)| (*l, u.clone())).collect();
    let hreflang = if links.len() < 2 {
        String::new()
    } else {
        let mut lines: String = links.iter().map(|(l, u)| format!("<link rel=\"alternate\" hreflang=\"{}\" href=\"{}\" />\n", l.code(), esc(u))).collect();
        lines.push_str(&format!("<link rel=\"alternate\" hreflang=\"x-default\" href=\"{}\" />\n", esc(&url)));
        lines
    };
    h.insert("hreflang", hreflang);
    h.insert("lang_switcher", menu_switcher(lang, &links));
    h.insert("wpml_hidden_switcher", dropdown_switcher(lang, &links));
    h.insert("rest_link", format!("<link rel=\"alternate\" title=\"JSON\" type=\"application/json\" href=\"{ORIGIN}/wp-json/wp/v2/faq_type/{}\" />", term.id));
    h.insert("sidebar", last_sidebar(site, last)?);
    Some(h)
}
