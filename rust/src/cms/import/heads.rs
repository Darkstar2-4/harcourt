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
        theme::{content, NOT_FOUND_OPTION, ORIGIN},
        yoast::{self, data_layer_json, json, schema_text, strip_all_tags},
    },
};

/// The 404 page's holes a post without forms of its own prints the same.
const SHARED: [&str; 10] =
    ["after_html", "doctype_prefix", "footer_menu_id_suffix", "footer_scripts", "gform_head_css", "gform_head_js", "owl_css", "recaptcha_prefetch", "yoast_extra", "islands"];

/// Gives each published FAQ without a recorded head its head and content. Returns one problem per FAQ it could
/// not build them for, naming what was missing: such a page would print an empty title and heading.
pub fn apply(import: &mut Import) -> Vec<String> {
    let site = import.site();
    let mut problems = Vec::new();
    let mut heads = Vec::new();
    for p in site.pages.iter().filter(|p| p.kind == PostType::Faq && p.status == Status::Publish && !site.meta(p.id).contains_key("title")) {
        let built = site
            .fields(p.id)
            .get("@post_content")
            .ok_or("no post_content")
            .and_then(|raw| content::paragraphs(&raw.value, p.lang).ok_or("content that is not plain paragraphs"))
            .and_then(|content| faq(&site, p).map(|h| (h, content)));
        match built {
            Ok((head, content)) => heads.push((p.id, head, content)),
            Err(missing) => problems.push(format!("faq {} ({}) {}: no head, {missing}", p.id.0, p.lang.code(), p.path)),
        }
    }
    for (id, head, content) in heads {
        import.meta.entry(id).or_default().extend(head);
        import.fields.entry(id).or_default().insert("content".into(), Field { kind: Kind::Html, value: content });
    }
    problems
}

fn faq(site: &Site, p: &Page) -> Result<BTreeMap<String, String>, &'static str> {
    let lang = p.lang;
    let mut m: BTreeMap<String, String> = BTreeMap::new();
    for name in SHARED {
        let v = site.options(lang).get(&format!("{NOT_FOUND_OPTION}{name}")).ok_or("the 404 page's shared values missing (the export's 404 page)")?;
        m.insert(name.to_string(), v.value.clone());
    }
    let site_graph = yoast::site_graph(site, lang).ok_or("Yoast's site graph missing (the export's 404 page schema)")?;
    let title = yoast::post_title(site, p).ok_or("Yoast's FAQ title missing (wp_options blogname and wpseo_titles)")?;
    let author = yoast::author_name(site, p.author_id).ok_or("the author's display name missing (wp_users)")?;
    let home = yoast::home_crumb(site, lang).ok_or("the breadcrumb's home link missing (an exported page's breadcrumbs)")?;
    let sidebar = yoast::faq_widgets(site, lang).ok_or("the FAQ widget area missing (an exported page's sidebar)")?;
    // Yoast links a translation by the post its indexable was built from: the English one.
    let url = site.translation(p, Lang::En).filter(|t| t.status == Status::Publish).unwrap_or(p).url(ORIGIN);
    let noindex = yoast::noindex(site, p);
    let crumb = yoast::breadcrumb_title(&p.title);
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
            schema_text(&title),
            schema_text(&home),
            schema_text(&crumb),
        ),
    );
    m.insert("datalayer".into(), format!("{{\"pagePostType\":\"{kind}\",\"pagePostType2\":\"single-{kind}\",\"pagePostAuthor\":{}}}", data_layer_json(&author)));
    m.insert("the_title".into(), the_title);
    m.insert(
        "breadcrumbs".into(),
        format!("\n<span><span><a href=\"{ORIGIN}/\">{home}</a></span> » <span class=\"breadcrumb_last\" aria-current=\"page\">{crumb}</span></span>\n"),
    );
    m.insert("sidebar".into(), sidebar);
    Ok(m)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::cms::model::{PageId, Template};

    #[test]
    fn an_faq_whose_head_cannot_be_built_is_reported() {
        let faq = Page {
            id: PageId(9),
            kind: PostType::Faq,
            name: "lead-times".into(),
            group_id: 9,
            lang: Lang::En,
            path: "/faq/lead-times/".into(),
            template: Template::Page,
            status: Status::Publish,
            title: "Lead times".into(),
            parent_id: None,
            menu_order: 0,
            published_at: "2024-01-01 00:00:00".into(),
            modified_at: "2024-01-01 00:00:00".into(),
            author_id: None,
        };
        let mut import = Import { pages: vec![faq], ..Default::default() };
        import.fields.entry(PageId(9)).or_default().insert("@post_content".into(), Field { kind: Kind::Html, value: "Two weeks.".into() });
        let problems = apply(&mut import);
        assert_eq!(problems.len(), 1, "{problems:?}");
        assert!(problems[0].starts_with("faq 9 (en) /faq/lead-times/: no head, "), "{problems:?}");
        assert!(!import.meta.contains_key(&PageId(9)), "nothing half built is stored");
    }
}
