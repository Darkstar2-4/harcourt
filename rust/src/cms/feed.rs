//! WordPress's feeds by their rewrite rules: `[base/]feed/[type]/` and `[base/]type/`, where the base is nothing (the
//! posts), `comments` (every comment), a category or FAQ type (its posts) or a post's path (its comments). The site
//! has no comments, so the comment feeds are their empty channels. The posts feeds are RSS 2.0 (feed-rss2.php)
//! with Yoast's line after each post.

use super::{
    html::esc,
    media::{Images, Phase},
    model::{Lang, Page, PostType, Site, Status, Taxonomy, Term},
    theme::{self, archive, content, ORIGIN},
    url::esc_url,
    yoast,
};
use crate::catalog::wp_search::{autop, excerpt_text, texturize::texturize};

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Kind {
    Rss2,
    Atom,
    Rdf,
}

impl Kind {
    fn parse(s: &str) -> Option<Kind> {
        match s {
            "feed" | "rss" | "rss2" => Some(Kind::Rss2),
            "atom" => Some(Kind::Atom),
            "rdf" => Some(Kind::Rdf),
            _ => None,
        }
    }

    /// The segment of the canonical feed URL after `feed/`.
    fn suffix(self) -> &'static str {
        match self {
            Kind::Rss2 => "",
            Kind::Atom => "atom/",
            Kind::Rdf => "rdf/",
        }
    }

    pub fn content_type(self) -> &'static str {
        match self {
            Kind::Rss2 => "application/rss+xml; charset=UTF-8",
            Kind::Atom => "application/atom+xml; charset=UTF-8",
            Kind::Rdf => "application/rdf+xml; charset=UTF-8",
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Of<'a> {
    Posts,
    Comments,
    PostComments(&'a Page),
    /// The posts of a category or FAQ type.
    Term(&'a Term),
}

#[derive(Debug, PartialEq, Eq)]
pub enum Feed<'a> {
    /// The feed, asked for at its canonical path.
    Show(Of<'a>, Kind),
    /// redirect_canonical() moves the visitor to the feed's canonical path.
    Moved(String, Kind),
    /// A feed rule matched a base that is no post: WordPress's 404 page, sent as the feed's type.
    Missing(Kind),
    /// A post whose type has no feeds (FAQs, case studies, testimonials): no rule matches, the 404 page.
    None,
}

/// The bases of WordPress's archive and post type rewrite rules.
const ARCHIVES: [&str; 11] = ["category", "tag", "type", "author", "search", "faq", "faq_type", "case-study", "testimonials", "job", "page"];

/// The feed a path (as asked, percent-decoded) names, or None when no feed rule this port answers matches it.
pub fn parse<'a>(site: &'a Site, path: &str, lang: Lang) -> Option<Feed<'a>> {
    let segments: Vec<&str> = path.split('/').filter(|s| !s.is_empty()).collect();
    let (&last, rest) = segments.split_last()?;
    let kind = Kind::parse(last)?;
    let base = match rest.split_last() {
        Some((&"feed", base)) => base,
        _ => rest,
    };
    let canonical = |base: &str| format!("{base}feed/{}", kind.suffix());
    let (of, base_path) = match base {
        [] => (Of::Posts, "/".to_string()),
        ["comments"] => (Of::Comments, "/comments/".to_string()),
        [tax @ ("category" | "faq_type"), slug] => {
            let taxonomy = if *tax == "category" { Taxonomy::Category } else { Taxonomy::FaqType };
            // A term asked for in another case or language is not ported; an FAQ type is in no language.
            let term = site.terms.iter().find(|t| t.taxonomy == taxonomy && t.slug == *slug && (taxonomy == Taxonomy::FaqType || t.lang == Some(lang)))?;
            (Of::Term(term), term.path())
        }
        [first, ..] => {
            let p = format!("/{}/", base.join("/"));
            match site.find(lang, &p).filter(|p| p.status == Status::Publish) {
                Some(page) if matches!(page.kind, PostType::Page | PostType::Post) => (Of::PostComments(page), p),
                Some(_) => return Some(Feed::None),
                // Archive feeds (a category's, an author's, a year's, ...) are not ported.
                None if ARCHIVES.contains(first) || (first.len() == 4 && first.bytes().all(|b| b.is_ascii_digit())) => return None,
                None => return Some(Feed::Missing(kind)),
            }
        }
    };
    let want = canonical(&base_path);
    Some(if path == want { Feed::Show(of, kind) } else { Feed::Moved(want, kind) })
}

/// RFC 2822 of a GMT `YYYY-MM-DD HH:MM:SS`, as mysql2date('D, d M Y H:i:s +0000') prints it.
fn rfc2822(gmt: &str) -> String {
    chrono::NaiveDateTime::parse_from_str(gmt, "%Y-%m-%d %H:%M:%S").map(|t| t.format("%a, %d %b %Y %H:%M:%S +0000").to_string()).unwrap_or_default()
}

fn atom_date(gmt: &str) -> String {
    format!("{}Z", gmt.replacen(' ', "T", 1))
}

/// get_lastpostmodified('GMT'): the newest change to any published post.
fn last_modified(site: &Site) -> &str {
    site.pages.iter().filter(|p| p.status == Status::Publish).map(|p| p.modified_at.as_str()).max().unwrap_or("")
}

/// A comments feed: no comments, so the channel alone. `asked` is the request URI, which RSS's self link repeats.
pub fn comments(site: &Site, of: Of, kind: Kind, lang: Lang, asked: &str) -> Option<String> {
    let fr = lang == Lang::Fr;
    let (rss_title, atom_title, link, alternate, self_atom, built) = match of {
        Of::Posts | Of::Term(_) => return None,
        Of::Comments => {
            let title = if fr { "Commentaires pour Harcourt".to_string() } else { "Comments for Harcourt".to_string() };
            (title.clone(), title, format!("{ORIGIN}/"), format!("{ORIGIN}/"), format!("{ORIGIN}/comments/feed/atom/{}", lang.query()), last_modified(site))
        }
        Of::PostComments(p) => {
            let (rss, atom) = if fr { (format!("Commentaires sur\u{a0}: {}", p.title), format!("Commentaires sur {}", p.title)) } else { (format!("Comments on: {}", p.title), format!("Comments on {}", p.title)) };
            let permalink = p.url(ORIGIN);
            (rss, atom, permalink.clone(), format!("{permalink}#respond"), format!("{ORIGIN}{}feed/{}atom/", p.path, p.lang.query()), p.modified_at.as_str())
        }
    };
    match kind {
        Kind::Rss2 => Some(format!(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?><rss version=\"2.0\"\n\txmlns:content=\"http://purl.org/rss/1.0/modules/content/\"\n\txmlns:dc=\"http://purl.org/dc/elements/1.1/\"\n\txmlns:atom=\"http://www.w3.org/2005/Atom\"\n\txmlns:sy=\"http://purl.org/rss/1.0/modules/syndication/\"\n\t\n\t>\n<channel>\n\t<title>\n\t{rss_title}\t</title>\n\t<atom:link href=\"{}\" rel=\"self\" type=\"application/rss+xml\" />\n\t<link>{link}</link>\n\t<description>Making Tooling Simple</description>\n\t<lastBuildDate>{}</lastBuildDate>\n\t<sy:updatePeriod>\n\thourly\t</sy:updatePeriod>\n\t<sy:updateFrequency>\n\t1\t</sy:updateFrequency>\n\t<generator>https://wordpress.org/?v=6.8.2</generator>\n</channel>\n</rss>\n",
            esc_url(&format!("{ORIGIN}{asked}")),
            rfc2822(built)
        )),
        Kind::Atom => Some(format!(
            "<?xml version=\"1.0\" encoding=\"UTF-8\" ?><feed\n\txmlns=\"http://www.w3.org/2005/Atom\"\n\txml:lang=\"{}\"\n\txmlns:thr=\"http://purl.org/syndication/thread/1.0\"\n\t>\n\t<title type=\"text\">\n\t{atom_title}\t</title>\n\t<subtitle type=\"text\">Making Tooling Simple</subtitle>\n\n\t<updated>{}</updated>\n\n\t<link rel=\"alternate\" type=\"text/html\" href=\"{alternate}\" />\n\t<link rel=\"self\" type=\"application/atom+xml\" href=\"{self_atom}\" />\n\t<id>{self_atom}</id>\n<generator uri=\"https://wordpress.org/\" version=\"6.8.2\">WordPress</generator>\n</feed>\n",
            if fr { "fr-FR" } else { "en-US" },
            atom_date(built)
        )),
        Kind::Rdf => None,
    }
}

/// ent2ncr(): the named entities WordPress's text filters produce, as numeric references.
fn ent2ncr(s: &str) -> String {
    const NAMED: [(&str, &str); 28] = [
        ("&quot;", "&#34;"),
        ("&amp;", "&#38;"),
        ("&lt;", "&#60;"),
        ("&gt;", "&#62;"),
        ("&nbsp;", "&#160;"),
        ("&copy;", "&#169;"),
        ("&laquo;", "&#171;"),
        ("&reg;", "&#174;"),
        ("&deg;", "&#176;"),
        ("&middot;", "&#183;"),
        ("&raquo;", "&#187;"),
        ("&times;", "&#215;"),
        ("&ndash;", "&#8211;"),
        ("&mdash;", "&#8212;"),
        ("&lsquo;", "&#8216;"),
        ("&rsquo;", "&#8217;"),
        ("&sbquo;", "&#8218;"),
        ("&ldquo;", "&#8220;"),
        ("&rdquo;", "&#8221;"),
        ("&bdquo;", "&#8222;"),
        ("&bull;", "&#8226;"),
        ("&hellip;", "&#8230;"),
        ("&prime;", "&#8242;"),
        ("&Prime;", "&#8243;"),
        ("&euro;", "&#8364;"),
        ("&trade;", "&#8482;"),
        ("&eacute;", "&#233;"),
        ("&egrave;", "&#232;"),
    ];
    if !s.contains('&') {
        return s.to_string();
    }
    NAMED.iter().fold(s.to_string(), |acc, (name, number)| acc.replace(name, number))
}

/// One post of a posts feed, its parts as the feed templates print them.
struct Entry {
    /// the_title_rss().
    title: String,
    permalink: String,
    author: String,
    published: String,
    modified: String,
    categories: Vec<String>,
    guid: String,
    /// The excerpt and the content, each followed by Yoast's line naming the post and the site.
    summary: String,
    content: String,
}

fn entry(site: &Site, p: &Page, images: &mut Images) -> Option<Entry> {
    let content = match p.kind {
        PostType::Faq if site.meta(p.id).contains_key("title") => site.fields(p.id).get("content")?.value.clone(),
        PostType::Post => content::post_content(site, p, images)?,
        _ => return None,
    };
    let sitename = &site.options(Lang::En).get("@blogname")?.value;
    let permalink = esc_url(&p.url(ORIGIN));
    let title = texturize(&p.title, p.lang);
    let home = match p.lang {
        Lang::En => ORIGIN.to_string(),
        Lang::Fr => format!("{ORIGIN}/?lang=fr"),
    };
    let after = format!("<p>The post <a href=\"{permalink}\">{}</a> appeared first on <a href=\"{home}\">{}</a>.</p>\n", esc(&title), esc(sitename));
    Some(Entry {
        title: esc(&ent2ncr(&title)),
        author: yoast::author_name(site, p.author_id)?,
        published: p.published_at.clone(),
        modified: p.modified_at.clone(),
        categories: site.terms.iter().filter(|t| t.taxonomy == Taxonomy::Category && t.pages.contains(&p.id)).map(|t| t.name.clone()).collect(),
        guid: esc_url(&site.fields(p.id).get("@guid")?.value),
        summary: format!("{}{after}", autop(&ent2ncr(&excerpt_text(site, p)))),
        content: format!("{}{after}", content.replace("]]>", "]]&gt;")),
        permalink,
    })
}

fn rss_item(e: &Entry) -> String {
    let categories: String = e.categories.iter().map(|c| format!("\t\t\t\t<category><![CDATA[{c}]]></category>\n")).collect();
    format!(
        "\t<item>\n\t\t<title>{}</title>\n\t\t<link>{}</link>\n\t\t\n\t\t<dc:creator><![CDATA[{}]]></dc:creator>\n\t\t<pubDate>{}</pubDate>\n{categories}\t\t<guid isPermaLink=\"false\">{}</guid>\n\n\t\t\t\t\t<description><![CDATA[{}]]></description>\n\t\t\t\t\t\t\t\t\t\t<content:encoded><![CDATA[{}]]></content:encoded>\n\t\t\t\t\t\n\t\t\n\t\t\n\t\t\t</item>\n",
        e.title,
        e.permalink,
        e.author,
        rfc2822(&e.published),
        e.guid,
        e.summary,
        e.content
    )
}

fn atom_entry(e: &Entry) -> String {
    let categories: String = e.categories.iter().map(|c| format!("\t\t<category scheme=\"{ORIGIN}/\" term=\"{}\" />\n", esc(c))).collect();
    format!(
        "\t<entry>\n\t\t<author>\n\t\t\t<name>{}</name>\n\t\t\t\t\t</author>\n\n\t\t<title type=\"html\"><![CDATA[{}]]></title>\n\t\t<link rel=\"alternate\" type=\"text/html\" href=\"{}\" />\n\n\t\t<id>{}</id>\n\t\t<updated>{}</updated>\n\t\t<published>{}</published>\n{categories}\t\t<summary type=\"html\"><![CDATA[{}]]></summary>\n\n\t\t\t\t\t<content type=\"html\" xml:base=\"{}\"><![CDATA[{}]]></content>\n\t\t\n\t\t\t</entry>\n",
        esc(&e.author),
        e.title,
        e.permalink,
        e.guid,
        atom_date(&e.modified),
        atom_date(&e.published),
        e.summary,
        e.permalink,
        e.content
    )
}

/// The feed of the posts (`Of::Posts`, RSS 2.0 or Atom) or of a term's posts (RSS 2.0) in `lang`, `asked` being the
/// request URI its self link repeats. None for another feed, or for one this port does not render.
pub fn posts(site: &Site, of: Of, kind: Kind, lang: Lang, asked: &str) -> Option<String> {
    let sitename = site.options(Lang::En).get("@blogname")?.value.clone();
    let (title, link, listed) = match (of, kind) {
        (Of::Posts, Kind::Rss2 | Kind::Atom) => (sitename, format!("{ORIGIN}/"), archive::listed(site.pages.iter().filter(|p| p.kind == PostType::Post), lang)),
        (Of::Term(t), Kind::Rss2) => {
            // The channel links the English term, as Yoast's indexable does.
            let english = t.group_id.and_then(|g| site.terms.iter().find(|o| o.taxonomy == t.taxonomy && o.group_id == Some(g) && o.lang == Some(Lang::En))).unwrap_or(t);
            (archive::term_title(site, t, lang)?, format!("{ORIGIN}{}", english.path()), archive::listed(t.pages.iter().filter_map(|id| site.page(*id)), lang))
        }
        _ => return None,
    };
    // posts_per_rss
    if listed.is_empty() || listed.len() > 10 {
        return None;
    }
    let description = esc(&site.options(Lang::En).get("@blogdescription")?.value);
    let built = listed.iter().map(|p| p.modified_at.as_str()).max().unwrap_or("");
    let icon = theme::site_icon()?;
    let mut images = Images::default();
    images.set_phase(Phase::Loop);
    let mut entries = Vec::new();
    for p in &listed {
        entries.push(entry(site, p, &mut images)?);
    }
    let me = esc_url(&format!("{ORIGIN}{asked}"));
    let t = esc(&ent2ncr(&title));
    Some(match kind {
        Kind::Atom => format!(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?><feed\n\txmlns=\"http://www.w3.org/2005/Atom\"\n\txmlns:thr=\"http://purl.org/syndication/thread/1.0\"\n\txml:lang=\"{}\"\n\t>\n\t<title type=\"text\">{t}</title>\n\t<subtitle type=\"text\">{description}</subtitle>\n\n\t<updated>{}</updated>\n\n\t<link rel=\"alternate\" type=\"text/html\" href=\"{link}\" />\n\t<id>{ORIGIN}/feed/atom/{}</id>\n\t<link rel=\"self\" type=\"application/atom+xml\" href=\"{me}\" />\n\n\t<generator uri=\"https://wordpress.org/\" version=\"6.8.2\">WordPress</generator>\n<icon>{icon}</icon>\n{}\t</feed>\n",
            yoast::in_language(lang),
            atom_date(built),
            lang.query(),
            entries.iter().map(atom_entry).collect::<String>()
        ),
        _ => format!(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?><rss version=\"2.0\"\n\txmlns:content=\"http://purl.org/rss/1.0/modules/content/\"\n\txmlns:wfw=\"http://wellformedweb.org/CommentAPI/\"\n\txmlns:dc=\"http://purl.org/dc/elements/1.1/\"\n\txmlns:atom=\"http://www.w3.org/2005/Atom\"\n\txmlns:sy=\"http://purl.org/rss/1.0/modules/syndication/\"\n\txmlns:slash=\"http://purl.org/rss/1.0/modules/slash/\"\n\t>\n\n<channel>\n\t<title>{t}</title>\n\t<atom:link href=\"{me}\" rel=\"self\" type=\"application/rss+xml\" />\n\t<link>{link}</link>\n\t<description>{description}</description>\n\t<lastBuildDate>{}</lastBuildDate>\n\t<language>{}</language>\n\t<sy:updatePeriod>\n\thourly\t</sy:updatePeriod>\n\t<sy:updateFrequency>\n\t1\t</sy:updateFrequency>\n\t<generator>https://wordpress.org/?v=6.8.2</generator>\n\n<image>\n\t<url>{icon}</url>\n\t<title>{t}</title>\n\t<link>{link}</link>\n\t<width>32</width>\n\t<height>32</height>\n</image> \n{}\t</channel>\n</rss>\n",
            rfc2822(built),
            yoast::in_language(lang),
            entries.iter().map(rss_item).collect::<String>()
        ),
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_term_feed_lists_its_posts_of_the_language_with_yoasts_line() {
        use crate::cms::model::{Author, Field, Kind as FieldKind, PageId, Template};
        let faq = |id: i64, lang: Lang, slug: &str, title: &str, date: &str| Page {
            id: PageId(id),
            kind: PostType::Faq,
            name: slug.into(),
            group_id: id,
            lang,
            path: format!("/faq/{slug}/"),
            template: Template::SingleFaq,
            status: Status::Publish,
            title: title.into(),
            parent_id: None,
            menu_order: 0,
            published_at: date.into(),
            modified_at: date.into(),
            author_id: Some(6),
        };
        let mut site = Site::new(vec![faq(1, Lang::En, "first", "Isn't it?", "2020-01-02 03:04:05"), faq(2, Lang::En, "second", "Second", "2021-01-02 03:04:05"), faq(3, Lang::Fr, "troisieme", "Troisième", "2022-01-02 03:04:05")]);
        let f = |v: &str| Field { kind: FieldKind::Choice, value: v.into() };
        site.options.insert(Lang::En, [("@blogname", "Example"), ("@blogdescription", "An example"), ("@wpseo:title-tax-faq_type", "%%term_title%% Archives %%page%% %%sep%% %%sitename%%")].into_iter().map(|(k, v)| (k.to_string(), f(v))).collect());
        for id in 1..=3 {
            site.fields.insert(PageId(id), [("content", "\n<p>Text</p>\n"), ("@post_content", "<!-- wp:paragraph -->\n<p>Text</p>\n<!-- /wp:paragraph -->"), ("@guid", "https://example.com/?post_type=faq&p=1")].into_iter().map(|(k, v)| (k.to_string(), f(v))).collect());
            site.meta.insert(PageId(id), [("title".to_string(), "x".to_string())].into_iter().collect());
        }
        site.authors.insert(6, Author { id: 6, nicename: "editor".into(), display_name: "editor".into(), profile_updated: 0 });
        site.terms.push(Term { id: 17, taxonomy: Taxonomy::FaqType, slug: "general".into(), name: "General".into(), lang: Some(Lang::En), group_id: None, modified_at: String::new(), pages: vec![PageId(1), PageId(2), PageId(3)] });
        assert_eq!(parse(&site, "/faq_type/general/feed/", Lang::En), Some(Feed::Show(Of::Term(&site.terms[0]), Kind::Rss2)));
        assert_eq!(parse(&site, "/faq_type/general/feed", Lang::En), Some(Feed::Moved("/faq_type/general/feed/".into(), Kind::Rss2)));
        let Some(rss) = posts(&site, Of::Term(&site.terms[0]), Kind::Rss2, Lang::En, "/faq_type/general/feed/") else { panic!("no feed") };
        assert!(rss.contains("<title>General Archives - Example</title>"));
        assert!(rss.contains("<atom:link href=\"https://www.harcourt.co/faq_type/general/feed/\""));
        assert!(rss.contains("<lastBuildDate>Sat, 02 Jan 2021 03:04:05 +0000</lastBuildDate>"));
        assert!(rss.find("<title>Second</title>") < rss.find("<title>Isn&#8217;t it?</title>"), "newest first, titles texturized with numeric references");
        assert!(!rss.contains("Troisième"), "the request's language only");
        assert!(rss.contains("<guid isPermaLink=\"false\">https://example.com/?post_type=faq&#038;p=1</guid>"));
        assert!(rss.contains("<description><![CDATA[<p>Text</p>\n<p>The post <a href=\"https://www.harcourt.co/faq/second/\">Second</a> appeared first on <a href=\"https://www.harcourt.co\">Example</a>.</p>\n]]></description>"));
        assert_eq!(posts(&site, Of::Term(&site.terms[0]), Kind::Atom, Lang::En, "/faq_type/general/feed/atom/"), None, "a term's Atom feed is not ported");
    }

    #[test]
    fn named_entities_become_numeric_references() {
        assert_eq!(ent2ncr("l&rsquo;art&hellip; &#8217; &amp; x"), "l&#8217;art&#8230; &#8217; &#38; x");
        assert_eq!(ent2ncr("plain"), "plain");
    }

    #[test]
    fn feed_paths_follow_the_rewrite_rules() {
        let site = Site::new(Vec::new());
        let p = |path: &str| parse(&site, path, Lang::En);
        assert_eq!(p("/feed/"), Some(Feed::Show(Of::Posts, Kind::Rss2)));
        assert_eq!(p("/feed"), Some(Feed::Moved("/feed/".into(), Kind::Rss2)));
        assert_eq!(p("/rss2/"), Some(Feed::Moved("/feed/".into(), Kind::Rss2)));
        assert_eq!(p("/feed/rss/"), Some(Feed::Moved("/feed/".into(), Kind::Rss2)));
        assert_eq!(p("/feed/feed/"), Some(Feed::Moved("/feed/".into(), Kind::Rss2)));
        assert_eq!(p("/atom"), Some(Feed::Moved("/feed/atom/".into(), Kind::Atom)));
        assert_eq!(p("/comments/rss/"), Some(Feed::Moved("/comments/feed/".into(), Kind::Rss2)));
        assert_eq!(p("/comments/feed/atom/"), Some(Feed::Show(Of::Comments, Kind::Atom)));
        assert_eq!(p("/zzz/feed/"), Some(Feed::Missing(Kind::Rss2)));
        assert_eq!(p("/product/"), None);
        assert_eq!(p("/category/blog/feed/"), None);
        assert_eq!(p("/faq/x/feed/"), None);
        assert_eq!(rfc2822("2026-10-23 20:03:45"), "Fri, 23 Oct 2026 20:03:45 +0000");
    }
}
