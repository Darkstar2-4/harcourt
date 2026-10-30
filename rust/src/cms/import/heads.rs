//! The head and the recorded body values of an FAQ the static export left out, as Yoast, WPML and the theme
//! printed them: Yoast's title from its template for the post type, its Open Graph and Twitter lines and schema
//! graph, the breadcrumb trail, the FAQ widget area, the content as the_content() printed it, and from WordPress's
//! 404 page the parts every page without a form of its own shares (the footer form's scripts and styles). A post
//! whose head a capture recorded keeps it.

use std::collections::BTreeMap;

use super::Import;
use crate::{
    catalog::wp_search::{excerpt_text, texturize::texturize},
    cms::{
        html::esc,
        model::{Field, Kind, Lang, Page, PostType, Site, Status},
        theme::{NOT_FOUND_OPTION, ORIGIN},
        yoast::{self, json},
    },
};

/// The 404 page's holes a post without forms of its own prints the same.
const SHARED: [&str; 10] =
    ["after_html", "doctype_prefix", "footer_menu_id_suffix", "footer_scripts", "gform_head_css", "gform_head_js", "owl_css", "recaptcha_prefetch", "yoast_extra", "islands"];

pub fn apply(import: &mut Import) {
    let site = import.site();
    let heads: Vec<_> = site
        .pages
        .iter()
        .filter(|p| p.kind == PostType::Faq && p.status == Status::Publish && !site.meta(p.id).contains_key("title"))
        .filter_map(|p| {
            let raw = site.fields(p.id).get("@post_content")?;
            let content = the_content(&raw.value, p.lang)?;
            faq(&site, p).map(|h| (p.id, h, content))
        })
        .collect();
    for (id, head, content) in heads {
        import.meta.entry(id).or_default().extend(head);
        import.fields.entry(id).or_default().insert("content".into(), Field { kind: Kind::Html, value: content });
    }
}

/// the_content() of block content made of paragraphs only: do_blocks() prints each block's inner HTML (and the
/// text between blocks as it is) and leaves wpautop() off, then wptexturize() runs. None for anything else (other
/// blocks, images, shortcodes, classic content), which this port does not render.
pub fn the_content(raw: &str, lang: Lang) -> Option<String> {
    if !raw.contains("<!-- wp:") || raw.contains('[') || raw.contains("<img") {
        return None;
    }
    let close = "<!-- /wp:paragraph -->";
    let mut out = String::with_capacity(raw.len());
    let mut rest = raw;
    while let Some(start) = rest.find("<!-- wp:") {
        out.push_str(&rest[..start]);
        let after = &rest[start + "<!-- wp:".len()..];
        let end = after.find("-->")?;
        let opener = after[..end].trim_end();
        if opener.split_whitespace().next() != Some("paragraph") || opener.ends_with('/') {
            return None;
        }
        let inner = &after[end + 3..];
        let stop = inner.find(close)?;
        if inner[..stop].contains("<!-- wp:") {
            return None;
        }
        out.push_str(&inner[..stop]);
        rest = &inner[stop + close.len()..];
    }
    out.push_str(rest);
    Some(texturize(&out, lang))
}

/// wp_strip_all_tags(): script and style elements dropped with their text, other tags removed, then trimmed.
fn strip_all_tags(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    let mut rest = s;
    while let Some(lt) = rest.find('<') {
        out.push_str(&rest[..lt]);
        let tail = &rest[lt..];
        if tail[1..].starts_with(|c: char| c.is_ascii_whitespace()) {
            out.push('<');
            rest = &tail[1..];
            continue;
        }
        let end = tail.find('>').map_or(tail.len(), |e| e + 1);
        let name: String = tail[1..end].chars().take_while(|c| c.is_ascii_alphanumeric()).collect::<String>().to_ascii_lowercase();
        rest = &tail[end..];
        if name == "script" || name == "style" {
            let close = format!("</{name}");
            rest = rest.to_ascii_lowercase().find(&close).and_then(|e| rest[e..].find('>').map(|g| &rest[e + g + 1..])).unwrap_or("");
        }
    }
    out.push_str(rest);
    out.trim_matches([' ', '\t', '\n', '\r', '\0', '\u{b}']).to_string()
}

fn faq(site: &Site, p: &Page) -> Option<BTreeMap<String, String>> {
    let lang = p.lang;
    let mut m: BTreeMap<String, String> = BTreeMap::new();
    for name in SHARED {
        let v = site.options(lang).get(&format!("{NOT_FOUND_OPTION}{name}"))?;
        m.insert(name.to_string(), v.value.clone());
    }
    let site_graph = yoast::site_graph(site, lang)?;
    let title = yoast::post_title(site, p)?;
    let author = yoast::author_name(site, p.author_id)?;
    let home = yoast::home_crumb(site, lang)?;
    let sidebar = yoast::faq_widgets(site, lang)?;
    // Yoast links a translation by the post its indexable was built from: the English one.
    let url = site.translation(p, Lang::En).filter(|t| t.status == Status::Publish).unwrap_or(p).url(ORIGIN);
    let noindex = site.meta(p.id).get("noindex").is_some_and(|v| v == "1") || yoast::setting(site, &format!("noindex-{}", p.kind.as_str())) == Some("1");
    // Yoast texturizes its breadcrumb with the English marks on every page; the theme prints the title with the
    // page's own.
    let crumb = texturize(&p.title, Lang::En);
    let the_title = texturize(&p.title, lang);
    let kind = p.kind.as_str();
    let in_lang = yoast::in_language(lang);
    let (published, modified) = (yoast::iso(&p.published_at), yoast::iso(&p.modified_at));
    let og_description = strip_all_tags(&excerpt_text(site, p));

    m.insert("title".into(), esc(&title));
    m.insert("robots".into(), if noindex { "noindex, follow" } else { yoast::INDEXED }.into());
    m.insert("seo_og_type".into(), "\t<meta property=\"og:type\" content=\"article\" />\n".into());
    m.insert(
        "seo_article_twitter".into(),
        format!(
            "{}\t<meta property=\"article:modified_time\" content=\"{modified}\" />\n\t<meta name=\"twitter:card\" content=\"summary_large_image\" />\n{}",
            yoast::social_line(site, "\"article:publisher\""),
            yoast::social_line(site, "\"twitter:site\"")
        ),
    );
    m.insert("description".into(), String::new());
    m.insert("canonical".into(), if noindex { String::new() } else { url.clone() });
    m.insert("og_description".into(), esc(&og_description));
    m.insert("og_url".into(), url.clone());
    let u = json(&url);
    let crumbs = json(&format!("{url}#breadcrumb"));
    m.insert(
        "yoast_schema".into(),
        format!(
            "{{\"@context\":\"https:\\/\\/schema.org\",\"@graph\":[{{\"@type\":\"WebPage\",\"@id\":{u},\"url\":{u},\"name\":{},\"isPartOf\":{{\"@id\":\"https:\\/\\/www.harcourt.co\\/#website\"}},\"datePublished\":\"{published}\",\"dateModified\":\"{modified}\",\"breadcrumb\":{{\"@id\":{crumbs}}},\"inLanguage\":\"{in_lang}\",\"potentialAction\":[{{\"@type\":\"ReadAction\",\"target\":[{u}]}}]}},{{\"@type\":\"BreadcrumbList\",\"@id\":{crumbs},\"itemListElement\":[{{\"@type\":\"ListItem\",\"position\":1,\"name\":{},\"item\":\"https:\\/\\/www.harcourt.co\\/\"}},{{\"@type\":\"ListItem\",\"position\":2,\"name\":{}}}]}},{site_graph}",
            json(&title),
            json(&home),
            json(&crumb),
        ),
    );
    m.insert("datalayer".into(), format!("{{\"pagePostType\":\"{kind}\",\"pagePostType2\":\"single-{kind}\",\"pagePostAuthor\":{}}}", json(&author)));
    m.insert("the_title".into(), the_title);
    m.insert(
        "breadcrumbs".into(),
        format!("\n<span><span><a href=\"{ORIGIN}/\">{home}</a></span> » <span class=\"breadcrumb_last\" aria-current=\"page\">{crumb}</span></span>\n"),
    );
    m.insert("sidebar".into(), sidebar);
    Some(m)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn tags_go_with_the_text_of_scripts() {
        assert_eq!(strip_all_tags(" <p>One <b>two</b></p><script>x()</script>\n<style>p{}</style>three&hellip; "), "One two\nthree&hellip;");
        assert_eq!(strip_all_tags("a\u{a0}"), "a\u{a0}", "PHP's trim keeps a no-break space");
        assert_eq!(strip_all_tags("1 < 2"), "1 < 2");
    }

    #[test]
    fn paragraph_blocks_print_their_inner_html_texturized() {
        let raw = "<!-- wp:paragraph -->\n<p>It's one.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph {\"x\":\"1\"} -->\n<p>Two</p>\n<!-- /wp:paragraph -->";
        assert_eq!(the_content(raw, Lang::En).as_deref(), Some("\n<p>It&#8217;s one.</p>\n\n\n\n<p>Two</p>\n"));
        assert_eq!(the_content("<!-- wp:paragraph -->\n<p>L'art</p>\n<!-- /wp:paragraph -->", Lang::Fr).as_deref(), Some("\n<p>L&rsquo;art</p>\n"));
        assert_eq!(the_content("<!-- wp:image -->\n<figure></figure>\n<!-- /wp:image -->", Lang::En), None);
        assert_eq!(the_content("<p>classic</p>", Lang::En), None);
        assert_eq!(the_content("<!-- wp:paragraph -->\n<p>[gallery]</p>\n<!-- /wp:paragraph -->", Lang::En), None);
    }
}
