//! What Yoast SEO, WPML and the theme print in the head and around the loop of a view the static export did not
//! record (a post the export left out, an archive), built from Yoast's settings in the dump and from what the
//! exported pages show of the site-wide parts (the social lines, the author, the breadcrumb's first crumb).

use std::sync::LazyLock;

use regex::Regex;

use super::{
    model::{Lang, Page, PostType, Site, Taxonomy},
    theme::ORIGIN,
};
use crate::catalog::wp_search::texturize::texturize;

/// Yoast's robots line for an indexed view.
pub const INDEXED: &str = "index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1";

/// A Yoast setting from wpseo_titles, as the import keeps it.
pub fn setting<'a>(site: &'a Site, key: &str) -> Option<&'a str> {
    site.options(Lang::En).get(&format!("@wpseo:{key}")).map(|f| f.value.as_str())
}

/// Whether Yoast keeps a post out of search engines: by its own robots setting or by its type's.
pub fn noindex(site: &Site, p: &Page) -> bool {
    site.meta(p.id).get("noindex").is_some_and(|v| v == "1") || type_noindex(site, p.kind)
}

/// Whether Yoast keeps every post of a type out of search engines, and so out of the sitemaps.
pub fn type_noindex(site: &Site, kind: PostType) -> bool {
    setting(site, &format!("noindex-{}", kind.as_str())) == Some("1")
}

/// Whether Yoast keeps every archive of a taxonomy out of search engines, and so out of the sitemaps.
pub fn taxonomy_noindex(site: &Site, taxonomy: Taxonomy) -> bool {
    setting(site, &format!("noindex-tax-{}", taxonomy.as_str())) == Some("1")
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

/// A JSON string as Yoast's schema graph writes it (wp_json_encode() with JSON_UNESCAPED_UNICODE): slashes
/// escaped, other characters as they are.
pub fn json(s: &str) -> String {
    json_string(s, false)
}

/// A JSON string as GTM4WP writes its data layer: json()'s escapes, and `<`, `>`, `&`, `'` and `"` as `\u` escapes
/// (JSON_HEX_TAG, JSON_HEX_AMP, JSON_HEX_APOS, JSON_HEX_QUOT).
pub fn data_layer_json(s: &str) -> String {
    json_string(s, true)
}

fn json_string(s: &str, hex: bool) -> String {
    let mut out = String::with_capacity(s.len() + 2);
    out.push('"');
    for c in s.chars() {
        match c {
            '"' if hex => out.push_str("\\u0022"),
            '<' if hex => out.push_str("\\u003C"),
            '>' if hex => out.push_str("\\u003E"),
            '&' if hex => out.push_str("\\u0026"),
            '\'' if hex => out.push_str("\\u0027"),
            '"' => out.push_str("\\\""),
            '\\' => out.push_str("\\\\"),
            '/' => out.push_str("\\/"),
            '\u{8}' => out.push_str("\\b"),
            '\u{c}' => out.push_str("\\f"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\t' => out.push_str("\\t"),
            '\u{2028}' => out.push_str("\\u2028"),
            '\u{2029}' => out.push_str("\\u2029"),
            c if (c as u32) < 0x20 => out.push_str(&format!("\\u{:04x}", c as u32)),
            c => out.push(c),
        }
    }
    out.push('"');
    out
}

/// A name or caption in Yoast's schema graph: smart_strip_tags() of the text (line breaks and `<br>` as spaces, a
/// space after a closing paragraph, div or heading, a bullet after a list item, tags stripped, whitespace collapsed),
/// as json().
pub fn schema_text(s: &str) -> String {
    static SPACED: LazyLock<Vec<(Regex, &str)>> = LazyLock::new(|| {
        [(r"(?i)<br[ \t\n\x0B\x0C\r]*/?>", " "), (r"(?i)</(p|div|h[0-9])>", "</$1> "), (r"(?i)(<li[^>]*>)", "${1}• ")]
            .into_iter()
            .filter_map(|(pattern, with)| Regex::new(pattern).ok().map(|re| (re, with)))
            .collect()
    });
    let mut text = s.replace(['\r', '\n'], " ");
    for (re, with) in SPACED.iter() {
        text = re.replace_all(&text, *with).into_owned();
    }
    json(&fold_spaces(&strip_all_tags(&text), &[' ', '\t', '\n', '\u{b}', '\u{c}', '\r']))
}

/// wp_strip_all_tags(): script and style elements dropped with their text, other tags removed, then trimmed.
pub fn strip_all_tags(s: &str) -> String {
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
            if let Some(after) = rest.to_ascii_lowercase().find(&close).map(|e| &rest[e..]) {
                rest = after.find('>').map_or("", |g| &after[g + 1..]);
            }
        }
    }
    out.push_str(rest);
    php_trim(&out).to_string()
}

/// The breadcrumb text of a post (its indexable's breadcrumb title): wp_strip_all_tags() of get_the_title() with
/// line breaks removed. Yoast texturizes it with the English marks on every page.
pub fn breadcrumb_title(title: &str) -> String {
    fold_spaces(&strip_all_tags(&texturize(title, Lang::En)), &[' ', '\t', '\n', '\r'])
}

/// Each run of `spaces` as one space, then PHP's trim().
fn fold_spaces(s: &str, spaces: &[char]) -> String {
    let mut out = String::with_capacity(s.len());
    let mut in_run = false;
    for c in s.chars() {
        let space = spaces.contains(&c);
        if !space {
            out.push(c);
        } else if !in_run {
            out.push(' ');
        }
        in_run = space;
    }
    php_trim(&out).to_string()
}

/// Yoast's String_Helper::standardize_whitespace(): line breaks and tabs as spaces, one pass of double spaces made
/// single, trimmed.
pub fn standardize_whitespace(s: &str) -> String {
    php_trim(&s.replace(['\t', '\n', '\r', '\u{c}'], " ").replace("  ", " ")).to_string()
}

fn php_trim(s: &str) -> &str {
    s.trim_matches([' ', '\t', '\n', '\r', '\0', '\u{b}'])
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

    #[test]
    fn breadcrumbs_and_schema_names_are_plain_text() {
        assert_eq!(breadcrumb_title("Lunch & Learns"), "Lunch &#038; Learns");
        assert_eq!(breadcrumb_title("Clearance < 0.5 mm"), "Clearance < 0.5 mm");
        assert_eq!(breadcrumb_title(" Two\n\tlines "), "Two lines");
        assert_eq!(schema_text("Hoisting & Rigging - Harcourt"), "\"Hoisting & Rigging - Harcourt\"");
        assert_eq!(schema_text("Clearance < 0.5 mm"), "\"Clearance < 0.5 mm\"");
        assert_eq!(schema_text("Lunch &#038; Learns"), "\"Lunch &#038; Learns\"");
        assert_eq!(schema_text(" Two\r\n  lines"), "\"Two lines\"");
    }

    #[test]
    fn the_data_layer_writes_markup_characters_as_escapes() {
        assert_eq!(data_layer_json("courtney"), "\"courtney\"");
        assert_eq!(data_layer_json("A & B"), "\"A \\u0026 B\"");
        assert_eq!(data_layer_json("Clearance < 0.5 mm > 0.2"), "\"Clearance \\u003C 0.5 mm \\u003E 0.2\"");
        assert_eq!(data_layer_json("O'Neil \"Jr\" a/b é"), "\"O\\u0027Neil \\u0022Jr\\u0022 a\\/b é\"");
        assert_eq!(json("A & B < C"), "\"A & B < C\"", "Yoast's graph keeps them as they are");
    }

    #[test]
    fn tags_go_with_the_text_of_scripts() {
        assert_eq!(strip_all_tags(" <p>One <b>two</b></p><script>x()</script>\n<style>p{}</style>three&hellip; "), "One two\nthree&hellip;");
        assert_eq!(strip_all_tags("a\u{a0}"), "a\u{a0}", "PHP's trim keeps a no-break space");
        assert_eq!(strip_all_tags("1 < 2"), "1 < 2");
    }
}
