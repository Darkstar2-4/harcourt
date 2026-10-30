//! What Yoast SEO, WPML and the theme print in the head and around the loop of a view the static export did not
//! record (a post the export left out, an archive), built from Yoast's settings in the dump and from what the
//! exported pages show of the site-wide parts (the social lines, the author, the breadcrumb's first crumb).

use super::{
    model::{Lang, Page, Site},
    theme::ORIGIN,
};

/// Yoast's robots line for an indexed view.
pub const INDEXED: &str = "index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1";

/// A Yoast setting from wpseo_titles, as the import keeps it.
pub fn setting<'a>(site: &'a Site, key: &str) -> Option<&'a str> {
    site.options(Lang::En).get(&format!("@wpseo:{key}")).map(|f| f.value.as_str())
}

/// Yoast's separator setting as printed.
fn separator(setting: &str) -> &'static str {
    match setting {
        "sc-ndash" => "–",
        "sc-mdash" => "—",
        "sc-colon" => ":",
        "sc-middot" => "·",
        "sc-bull" => "•",
        "sc-star" => "*",
        "sc-smstar" => "⋆",
        "sc-pipe" => "|",
        "sc-tilde" => "~",
        "sc-laquo" => "«",
        "sc-raquo" => "»",
        "sc-lt" => "<",
        "sc-gt" => ">",
        _ => "-",
    }
}

/// A Yoast title template with `vars` replaced, on the first page, whitespace collapsed; None when the template uses
/// a variable not given (this port does not replace it).
pub fn fill(site: &Site, template: &str, vars: &[(&str, &str)]) -> Option<String> {
    let sitename = site.options(Lang::En).get("@blogname").map(|f| f.value.as_str())?;
    let sep = separator(setting(site, "separator").unwrap_or(""));
    let mut text = template.replace("%%page%%", "").replace("%%sep%%", sep).replace("%%sitename%%", sitename);
    for (name, value) in vars {
        text = text.replace(&format!("%%{name}%%"), value);
    }
    if text.contains("%%") {
        return None;
    }
    Some(text.split_whitespace().collect::<Vec<_>>().join(" "))
}

/// Yoast's title for a post from its type's template.
pub fn post_title(site: &Site, p: &Page) -> Option<String> {
    fill(site, setting(site, &format!("title-{}", p.kind.as_str()))?, &[("title", &p.title)])
}

/// A GMT `YYYY-MM-DD HH:MM:SS` as Yoast prints it.
pub fn iso(gmt: &str) -> String {
    format!("{}+00:00", gmt.replacen(' ', "T", 1))
}

/// A JSON string as wp_json_encode() writes it: slashes escaped, other characters as they are.
pub fn json(s: &str) -> String {
    let mut out = String::with_capacity(s.len() + 2);
    out.push('"');
    for c in s.chars() {
        match c {
            '"' => out.push_str("\\\""),
            '\\' => out.push_str("\\\\"),
            '/' => out.push_str("\\/"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\t' => out.push_str("\\t"),
            c if (c as u32) < 0x20 => out.push_str(&format!("\\u{:04x}", c as u32)),
            c => out.push(c),
        }
    }
    out.push('"');
    out
}

/// The `inLanguage` of Yoast's graph.
pub fn in_language(lang: Lang) -> &'static str {
    match lang {
        Lang::En => "en-US",
        Lang::Fr => "fr-FR",
    }
}

/// A line of the site's Yoast social settings (`"article:publisher"`, `"twitter:site"`), as the exported pages
/// print it.
pub fn social_line(site: &Site, marker: &str) -> String {
    site.pages
        .iter()
        .filter_map(|p| site.meta(p.id).get("seo_article_twitter"))
        .flat_map(|v| v.split_inclusive('\n'))
        .find(|l| l.contains(marker))
        .unwrap_or("")
        .to_string()
}

/// The author's display name, which the data layer and Yoast print: the user's, else what the exported pages of the
/// same author recorded (a dump without users).
pub fn author_name(site: &Site, id: Option<i64>) -> Option<String> {
    let id = id?;
    if let Some(a) = site.authors.get(&id) {
        return Some(a.display_name.clone());
    }
    site.pages.iter().filter(|q| q.author_id == Some(id)).find_map(|q| {
        let v: serde_json::Value = serde_json::from_str(site.meta(q.id).get("datalayer")?).ok()?;
        v.get("pagePostAuthor")?.as_str().map(str::to_string)
    })
}

/// The author's slug in their archive's URL: the user's nicename, else sanitize_title() of the name.
pub fn author_slug(site: &Site, id: Option<i64>) -> Option<String> {
    if let Some(a) = id.and_then(|id| site.authors.get(&id)) {
        return Some(a.nicename.clone());
    }
    let name = author_name(site, id)?;
    let slug: String = name.to_lowercase().split_whitespace().collect::<Vec<_>>().join("-");
    slug.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'-' || b == b'_').then_some(slug)
}

/// The breadcrumb trail's first crumb in `lang`, as the exported pages print it.
pub fn home_crumb(site: &Site, lang: Lang) -> Option<String> {
    let start = format!("<a href=\"{ORIGIN}/\">");
    site.pages.iter().filter(|p| p.lang == lang).filter_map(|p| site.meta(p.id).get("breadcrumbs")).find_map(|b| {
        let after = &b[b.find(&start)? + start.len()..];
        Some(after[..after.find("</a>")?].to_string())
    })
}

/// The FAQ widget area, the `[location]` widget (custom_html-3). custom_html-2 is the same widget, and the export
/// recorded it as the whole widget area of the pages that show it.
pub fn faq_widgets(site: &Site, lang: Lang) -> Option<String> {
    let marker = "<section class=\"widget_text custom_html-2 widget_custom_html\">";
    let mut pages: Vec<&Page> = site.pages.iter().collect();
    pages.sort_by_key(|p| p.lang != lang);
    pages
        .into_iter()
        .filter_map(|p| site.meta(p.id).get("sidebar"))
        .find(|s| s.starts_with(marker) && s.matches("<section").count() == 1)
        .map(|s| s.replace("custom_html-2 widget_custom_html", "custom_html-3 widget_custom_html"))
}

/// The site's own pieces of Yoast's graph (the website and the organization), as the 404 page of `lang` prints
/// them: everything after the graph's opening bracket.
pub fn site_graph(site: &Site, lang: Lang) -> Option<String> {
    let schema = &site.options(lang).get(&format!("{}yoast_schema", super::theme::NOT_FOUND_OPTION))?.value;
    schema.split_once("\"@graph\":[").map(|(_, tail)| tail.to_string())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn titles_follow_the_templates() {
        use crate::cms::model::{Field, Kind, PageId, PostType, Status, Template};
        let page = Page {
            id: PageId(1),
            kind: PostType::Faq,
            name: "q".into(),
            group_id: 1,
            lang: Lang::En,
            path: "/faq/q/".into(),
            template: Template::SingleFaq,
            status: Status::Publish,
            title: "Can't we?".into(),
            parent_id: None,
            menu_order: 0,
            published_at: "2020-01-02 03:04:05".into(),
            modified_at: String::new(),
            author_id: None,
        };
        let mut site = Site::new(vec![page.clone()]);
        let opt = |v: &str| Field { kind: Kind::Choice, value: v.into() };
        site.options.insert(
            Lang::En,
            [("@blogname", "Example"), ("@wpseo:title-faq", "%%title%% %%page%% %%sep%% %%sitename%%"), ("@wpseo:separator", "sc-dash")]
                .into_iter()
                .map(|(k, v)| (k.to_string(), opt(v)))
                .collect(),
        );
        assert_eq!(post_title(&site, &page).as_deref(), Some("Can't we? - Example"));
        assert_eq!(fill(&site, "%%term_title%% Archives %%page%% %%sep%% %%sitename%%", &[("term_title", "General")]).as_deref(), Some("General Archives - Example"));
        site.options.get_mut(&Lang::En).unwrap().insert("@wpseo:title-faq".into(), opt("%%title%% %%primary_category%%"));
        assert_eq!(post_title(&site, &page), None, "a variable not replaced leaves the view to WordPress");
        assert_eq!(iso(&page.published_at), "2020-01-02T03:04:05+00:00");
        assert_eq!(json("a/b \"c\" é"), "\"a\\/b \\\"c\\\" é\"");
    }
}
