//! WordPress's feeds by their rewrite rules: `[base/]feed/[type]/` and `[base/]type/`, where the base is nothing (the
//! posts), `comments` (every comment) or a post's path (its comments). The site has no comments, so the comment
//! feeds are their empty channels; the posts feed is not ported yet.

use super::{
    model::{Lang, Page, Site, Status},
    theme::ORIGIN,
    url::esc_url,
};

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
}

#[derive(Debug, PartialEq, Eq)]
pub enum Feed<'a> {
    /// The feed, asked for at its canonical path.
    Show(Of<'a>, Kind),
    /// redirect_canonical() moves the visitor to the feed's canonical path.
    Moved(String, Kind),
    /// A feed rule matched a base that is no post: WordPress's 404 page, sent as the feed's type.
    Missing(Kind),
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
        [first, ..] => {
            let p = format!("/{}/", base.join("/"));
            match site.find(lang, &p).filter(|p| p.status == Status::Publish) {
                Some(page) => (Of::PostComments(page), p),
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
        Of::Posts => return None,
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

#[cfg(test)]
mod tests {
    use super::*;

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
