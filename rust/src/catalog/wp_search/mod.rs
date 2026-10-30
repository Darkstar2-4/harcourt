//! WordPress's search results, `/search/<terms>/` (the theme's search.php): WP_Query's search over the published
//! posts of the request's language, ranked as its SQL ranked them, nine to a page, each with the excerpt
//! the_excerpt() printed. The page is the 404 page's chrome with the search's own head (Yoast's title, links and
//! schema, WPML's alternates), which the cms theme renders from the holes given here.

mod texturize;

use std::{
    collections::BTreeMap,
    sync::{Arc, OnceLock},
};

use axum::{
    body::{Body, Bytes},
    extract::Extension,
    http::{header, HeaderMap, HeaderValue, StatusCode, Uri},
    response::{IntoResponse, Response},
    routing::get,
    Router,
};
use regex::Regex;

use self::texturize::{ampersands, texturize};
use crate::{
    api::Auth,
    cms::{
        forms::{FormSource, Islands, OrdersForms},
        theme, Content, FormEmbed, Lang, MediaId, Page, PageId, PostType, Site, Status, Taxonomy,
    },
    orders::Orders,
};

/// The posts_per_page option.
const PER_PAGE: usize = 9;
/// excerpt_length.
const EXCERPT_WORDS: usize = 55;
const ORIGIN: &str = theme::ORIGIN;

pub struct Search {
    content: Content,
    forms: OrdersForms,
    origin: String,
}

pub fn router(core: Arc<Auth>) -> Router<Arc<Auth>> {
    let search = Arc::new(Search {
        content: Content::new(core.pool.clone()),
        forms: OrdersForms { orders: Arc::new(Orders::from_env(core.clone())), fallback: Islands::from_env() },
        origin: core.public_origin.clone(),
    });
    Router::new().route("/search/{*rest}", get(results).post(results)).layer(Extension(search))
}

/// What a request under /search/ asks WordPress for.
#[derive(Debug, PartialEq, Eq)]
enum Asked {
    /// The terms, the page of results, the language, and the `page` query var (a post's page; on a search it
    /// only adds body classes).
    Results { s: String, paged: usize, lang: Lang, page: usize },
    /// WPML's 302 away from `lang=en`, to this relative Location.
    Found(String),
    /// A feed or embed of a search, or a query WordPress acts on: the legacy stack answers.
    Legacy,
}

/// Query keys WordPress acts on besides WPML's `lang` (WP::$public_query_vars and the site's post types).
const WP_KEYS: &[&str] = &[
    "m", "p", "posts", "w", "cat", "withcomments", "withoutcomments", "s", "search", "exact", "sentence", "calendar", "paged", "more", "tb",
    "pb", "author", "order", "orderby", "year", "monthnum", "day", "hour", "minute", "second", "name", "category_name", "tag", "feed", "author_name",
    "pagename", "page_id", "error", "attachment", "attachment_id", "subpost", "subpost_id", "preview", "robots", "favicon", "taxonomy", "term", "cpage",
    "post_type", "embed", "rest_route", "sitemap", "case_study", "faq", "faq_type", "testimonials", "job_listing", "post_format", "replytocom",
];

fn rewrite() -> &'static [Regex; 4] {
    static RULES: OnceLock<[Regex; 4]> = OnceLock::new();
    RULES.get_or_init(|| {
        [
            r"^search/(.+)/feed/(feed|rdf|rss|rss2|atom)$",
            r"^search/(.+)/(feed|rdf|rss|rss2|atom)$",
            r"^search/(.+)/embed$",
            r"^search/(.+)/page/?([0-9]+)$",
        ]
        .map(|r| Regex::new(r).expect("search rewrite rule"))
    })
}

/// $_GET-style decoding: `+` is a space, `%XX` a byte, anything else as written.
fn urldecode_bytes(s: &str) -> Vec<u8> {
    let b = s.as_bytes();
    let mut out = Vec::with_capacity(b.len());
    let mut i = 0;
    while i < b.len() {
        match b[i] {
            b'+' => out.push(b' '),
            b'%' if i + 2 < b.len() && b[i + 1].is_ascii_hexdigit() && b[i + 2].is_ascii_hexdigit() => {
                let hex = |c: u8| (c as char).to_digit(16).unwrap_or(0) as u8;
                out.push(hex(b[i + 1]) * 16 + hex(b[i + 2]));
                i += 2;
            }
            c => out.push(c),
        }
        i += 1;
    }
    out
}

/// The search terms WordPress read from the path: its rewrite match, addslashes()'d into a query string that
/// parse_str() decoded once, then stripslashes()'d by WP_Query, line breaks dropped.
fn terms_from_path(matched: &str) -> String {
    let slashed: String = matched.chars().flat_map(|c| if matches!(c, '\'' | '"' | '\\') { vec!['\\', c] } else { vec![c] }).collect();
    let decoded = urldecode_bytes(&slashed);
    let mut out = Vec::with_capacity(decoded.len());
    let mut it = decoded.into_iter();
    while let Some(c) = it.next() {
        if c == b'\\' {
            if let Some(n) = it.next() {
                out.push(n);
            }
        } else if c != b'\r' && c != b'\n' {
            out.push(c);
        }
    }
    String::from_utf8_lossy(&out).into_owned()
}

/// The request: its path by WordPress's search rewrite rules, its query (and a post's body) by WordPress's and
/// WPML's query vars.
fn asked(raw_path: &str, raw_query: &str, posted: &[String]) -> Asked {
    if posted.iter().any(|k| WP_KEYS.contains(&k.as_str()) || k == "page" || k == "gform_submit") {
        return Asked::Legacy;
    }
    let trimmed = raw_path.trim_matches('/');
    let rules = rewrite();
    if rules[..3].iter().any(|r| r.is_match(trimmed)) {
        return Asked::Legacy;
    }
    let (matched, paged) = match rules[3].captures(trimmed) {
        Some(c) => (c.get(1).map_or("", |m| m.as_str()), c[2].parse::<usize>().unwrap_or(0)),
        None => match trimmed.strip_prefix("search/").filter(|m| !m.is_empty()) {
            Some(m) => (m, 1),
            None => return Asked::Legacy,
        },
    };
    let pairs: Vec<(String, &str)> = raw_query
        .split('&')
        .filter(|kv| !kv.is_empty())
        .map(|kv| (String::from_utf8_lossy(&urldecode_bytes(kv.split('=').next().unwrap_or(""))).into_owned(), kv))
        .collect();
    if pairs.iter().any(|(k, _)| WP_KEYS.contains(&k.as_str())) {
        return Asked::Legacy;
    }
    let lang = pairs.iter().rev().find(|(k, _)| k == "lang").map(|(_, kv)| kv.split_once('=').map_or("", |(_, v)| v));
    if lang == Some("en") {
        let rest: Vec<&str> = pairs.iter().filter(|(k, _)| k != "lang").map(|(_, kv)| *kv).collect();
        let to = format!("/{trimmed}/");
        return Asked::Found(if rest.is_empty() { to } else { format!("{to}?{}", rest.join("&")) });
    }
    let page = pairs
        .iter()
        .rev()
        .find(|(k, _)| k == "page")
        .map_or(0, |(_, kv)| kv.split_once('=').map_or("", |(_, v)| v).bytes().take_while(u8::is_ascii_digit).fold(0usize, |n, d| n.saturating_mul(10).saturating_add(usize::from(d - b'0'))));
    Asked::Results { s: terms_from_path(matched), paged: paged.max(1), lang: if lang == Some("fr") { Lang::Fr } else { Lang::En }, page }
}

/// WordPress's search stopwords per locale (the translated wp_search_stopwords list).
fn stopwords(lang: Lang) -> &'static [&'static str] {
    match lang {
        Lang::En => &[
            "about", "an", "are", "as", "at", "be", "by", "com", "for", "from", "how", "in", "is", "it", "of", "on", "or", "that", "the", "this", "to", "was",
            "what", "when", "where", "who", "will", "with", "www",
        ],
        Lang::Fr => &[
            "le", "la", "les", "de", "des", "un", "uns", "une", "unes", "et", "a", "à", "il", "elle", "on", "ne", "je", "tu", "nous", "vous", "ils", "elles",
            "son", "sa", "ses", "que", "quoi", "se", "qui", "ce", "cette", "dans", "en", "du", "au", "aux", "pour", "pas", "sur", "y", "ou", "où", "si",
            "ton", "ta", "te", "mon", "ma", "tout", "toute", "tous", "toutes", "ça", "ni", "www", "com", "fr", "par", "mais", "vos", "votre", "vôtre", "c",
            "d", "j", "l", "m", "n", "s", "t", "sans", "toi", "moi", "lui", "leur", "leurs",
        ],
    }
}

/// The preg_match_all() of WP_Query::parse_search(): quoted phrases, and runs of anything but tabs, spaces,
/// commas, plus signs and quotes.
fn raw_terms(s: &str) -> Vec<&str> {
    let sep = |c: char| matches!(c, '\t' | ' ' | ',' | '+' | '"');
    let mut out = Vec::new();
    let mut i = 0;
    while i < s.len() {
        let c = s[i..].chars().next().unwrap_or(' ');
        if c == '"' {
            let end = s[i + 1..].find('"').map_or(s.len(), |e| i + 1 + e + 1);
            out.push(&s[i..end]);
            i = end;
        } else if sep(c) {
            i += 1;
        } else {
            let end = s[i..].find(sep).map_or(s.len(), |e| i + e);
            out.push(&s[i..end]);
            i = end;
        }
    }
    out
}

/// A search as WP_Query ran it: the terms (folded for LIKE), how many the string held, and the whole string for
/// the sentence ranking (none when a term is negated).
#[derive(Debug)]
struct Terms {
    include: Vec<String>,
    exclude: Vec<String>,
    count: usize,
    sentence: Option<String>,
}

fn parse_terms(s: &str, lang: Lang) -> Terms {
    let found = raw_terms(s);
    let mut checked: Vec<&str> = Vec::new();
    for t in &found {
        let quoted = t.len() >= 3 && t.starts_with('"') && t.ends_with('"');
        let t = if quoted { t.trim_matches(['"', '\'']) } else { t.trim_matches(['"', '\'', ' ']) };
        if t.is_empty() || t == "0" || (t.len() == 1 && t.chars().all(|c| c.is_ascii_alphabetic() || c == '-')) {
            continue;
        }
        if stopwords(lang).contains(&t.to_lowercase().as_str()) {
            continue;
        }
        checked.push(t);
    }
    if found.is_empty() || checked.is_empty() || checked.len() > 9 {
        checked = vec![s];
    }
    let (mut include, mut exclude) = (Vec::new(), Vec::new());
    for t in checked {
        match t.strip_prefix('-') {
            Some(rest) => exclude.push(fold(rest)),
            None => include.push(fold(t)),
        }
    }
    // `(?:\s|^)-` in the string: a negated term, which drops the sentence from the ranking.
    let negated = s.split([' ', '\t', '\n', '\r', '\u{b}', '\u{c}']).any(|w| w.starts_with('-'));
    Terms { include, exclude, count: found.len().max(1), sentence: (!negated).then(|| fold(s)) }
}

/// The text as utf8mb4_unicode_520_ci compares it in LIKE: case and accents ignored.
fn fold(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for c in s.chars().flat_map(char::to_lowercase) {
        match c {
            'à' | 'á' | 'â' | 'ã' | 'ä' | 'å' => out.push('a'),
            'ç' => out.push('c'),
            'è' | 'é' | 'ê' | 'ë' => out.push('e'),
            'ì' | 'í' | 'î' | 'ï' => out.push('i'),
            'ñ' => out.push('n'),
            'ò' | 'ó' | 'ô' | 'õ' | 'ö' | 'ø' => out.push('o'),
            'ù' | 'ú' | 'û' | 'ü' => out.push('u'),
            'ý' | 'ÿ' => out.push('y'),
            'œ' => out.push_str("oe"),
            'æ' => out.push_str("ae"),
            'ß' => out.push_str("ss"),
            c => out.push(c),
        }
    }
    out
}

/// A post as the search reads it.
struct Doc<'a> {
    page: &'a Page,
    title: String,
    excerpt: String,
    content: String,
}

fn field<'a>(site: &'a Site, id: PageId, name: &str) -> &'a str {
    site.fields(id).get(name).map_or("", |f| f.value.as_str())
}

/// post_content as WordPress stored it (the cms keeps the rendered page as `content`).
fn post_content(site: &Site, id: PageId) -> &str {
    site.fields(id).get("@post_content").or_else(|| site.fields(id).get("content")).map_or("", |f| f.value.as_str())
}

/// WP_Query's search: every term in the title, excerpt or content (a negated one in none), ranked by its ORDER BY
/// (the search_orderby CASE, then post_date), as the posts of the language WPML let through.
fn search<'a>(site: &'a Site, terms: &Terms, lang: Lang) -> Vec<&'a Page> {
    if terms.include.is_empty() {
        // WordPress's ORDER BY had no title clause to rank by and the query failed: nothing found.
        return Vec::new();
    }
    let mut found: Vec<(u8, &Doc)> = Vec::new();
    let docs: Vec<Doc> = site
        .pages
        .iter()
        .filter(|p| p.status == Status::Publish && p.lang == lang)
        .map(|p| Doc { page: p, title: fold(&p.title), excerpt: fold(field(site, p.id, "@excerpt")), content: fold(post_content(site, p.id)) })
        .collect();
    for d in &docs {
        let has = |t: &String| d.title.contains(t.as_str()) || d.excerpt.contains(t.as_str()) || d.content.contains(t.as_str());
        if terms.include.iter().all(has) && !terms.exclude.iter().any(has) {
            found.push((rank(d, terms), d));
        }
    }
    found.sort_by(|(ra, a), (rb, b)| ra.cmp(rb).then_with(|| b.page.published_at.cmp(&a.page.published_at)).then_with(|| b.page.id.cmp(&a.page.id)));
    found.into_iter().map(|(_, d)| d.page).collect()
}

fn rank(d: &Doc, t: &Terms) -> u8 {
    let in_title = |w: &String| d.title.contains(w.as_str());
    if t.count <= 1 {
        return if in_title(&t.include[0]) { 0 } else { 1 };
    }
    let sentence = t.sentence.as_ref();
    if sentence.is_some_and(in_title) {
        return 1;
    }
    if t.include.len() < 7 {
        if t.include.iter().all(in_title) {
            return 2;
        }
        if t.include.len() > 1 && t.include.iter().any(in_title) {
            return 3;
        }
    }
    match sentence {
        Some(s) if d.excerpt.contains(s.as_str()) => 4,
        Some(s) if d.content.contains(s.as_str()) => 5,
        _ => 6,
    }
}

/// esc_html()/esc_attr(): special characters as entities, an entity already written left as it is.
fn esc(s: &str) -> String {
    texturize::bare_ampersands(s, "&amp;").replace('<', "&lt;").replace('>', "&gt;").replace('"', "&quot;").replace('\'', "&#039;")
}

/// PHP's urlencode() (`raw` false) and rawurlencode() (`raw` true).
fn urlencode(s: &str, raw: bool) -> String {
    let mut out = String::with_capacity(s.len() * 3);
    for b in s.bytes() {
        match b {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' => out.push(b as char),
            b'~' if raw => out.push('~'),
            b' ' if !raw => out.push('+'),
            _ => out.push_str(&format!("%{b:02X}")),
        }
    }
    out
}

/// A JSON string as wp_json_encode() wrote Yoast's schema: slashes escaped, other characters as they are.
fn json(s: &str) -> String {
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

fn permalink(p: &Page) -> String {
    format!("{ORIGIN}{}{}", p.path, p.lang.query())
}

fn thumbnail(site: &Site, id: PageId) -> Option<i64> {
    field(site, id, "@thumbnail").trim().parse::<i64>().ok().filter(|t| *t > 0)
}

/// post_class() for a result.
fn post_class(site: &Site, p: &Page) -> String {
    let t = p.kind.as_str();
    let mut c = vec![format!("post-{}", p.id.0), t.to_string(), format!("type-{t}"), "status-publish".to_string()];
    if p.kind == PostType::Post {
        c.push("format-standard".into());
    }
    if thumbnail(site, p.id).is_some() {
        c.push("has-post-thumbnail".into());
    }
    c.push("hentry".into());
    for term in site.terms.iter().filter(|t| t.pages.contains(&p.id)) {
        let prefix = if term.taxonomy == Taxonomy::Category { "category" } else { term.taxonomy.as_str() };
        c.push(format!("{prefix}-{}", term.slug));
    }
    c.join(" ")
}

const BLOCK_TAGS: &[&str] = &[
    "table", "thead", "tfoot", "caption", "col", "colgroup", "tbody", "tr", "td", "th", "div", "dl", "dd", "dt", "ul", "ol", "li", "pre", "form", "map",
    "area", "blockquote", "address", "style", "p", "h1", "h2", "h3", "h4", "h5", "h6", "hr", "fieldset", "legend", "section", "article", "aside", "hgroup",
    "header", "footer", "nav", "figure", "figcaption", "details", "menu", "summary",
];
/// The shortcodes registered on the site, which strip_shortcodes() removes from an excerpt.
const SHORTCODES: &[&str] = &[
    "gravityform", "gravityforms", "jobs", "job", "job_summary", "job_apply", "submit_job_form", "job_dashboard", "embed", "caption", "wp_caption",
    "gallery", "playlist", "audio", "video", "year",
];

/// strip_shortcodes(): each registered shortcode, with its content when it has a closing tag.
fn strip_shortcodes(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    let mut rest = s;
    while let Some(open) = rest.find('[') {
        out.push_str(&rest[..open]);
        let tail = &rest[open + 1..];
        let name_len = tail.find(|c: char| !(c.is_ascii_alphanumeric() || c == '_' || c == '-')).unwrap_or(tail.len());
        let name = &tail[..name_len];
        let close = tail.find(']');
        match (SHORTCODES.contains(&name) && name_len > 0, close) {
            (true, Some(c)) => {
                let after = &tail[c + 1..];
                let end_tag = format!("[/{name}]");
                rest = if tail[..c].ends_with('/') { after } else { after.find(&end_tag).map_or(after, |e| &after[e + end_tag.len()..]) };
            }
            _ => {
                out.push('[');
                rest = tail;
            }
        }
    }
    out.push_str(rest);
    out
}

/// A parsed block, or the freeform HTML between blocks (no name).
struct Block {
    name: Option<String>,
    /// The block's inner content in order: its HTML and its inner blocks.
    inner: Vec<Inner>,
}

enum Inner {
    Html(String),
    Block(Block),
}

/// One block comment: `<!-- wp:name {...} -->`, `<!-- /wp:name -->` or `<!-- wp:name /-->`, and its end.
fn delimiter(s: &str, at: usize) -> Option<(bool, String, bool, usize)> {
    let r = &s[at..];
    let body = r.strip_prefix("<!--")?;
    let ws = body.len() - body.trim_start().len();
    if ws == 0 {
        return None;
    }
    let body = body.trim_start();
    let (closer, body) = match body.strip_prefix('/') {
        Some(b) => (true, b),
        None => (false, body),
    };
    let body = body.strip_prefix("wp:")?;
    let end = r.find("-->")?;
    let name_len = body.find(|c: char| !(c.is_ascii_lowercase() || c.is_ascii_digit() || matches!(c, '_' | '-' | '/'))).unwrap_or(body.len());
    let name = &body[..name_len];
    if name.is_empty() || !name.starts_with(|c: char| c.is_ascii_lowercase()) {
        return None;
    }
    let void = r[..end].trim_end().ends_with('/');
    let name = if name.contains('/') { name.to_string() } else { format!("core/{name}") };
    Some((closer, name, void, at + end + 3))
}

/// parse_blocks(): the top-level blocks, freeform HTML between them included.
fn parse_blocks(s: &str) -> Vec<Block> {
    let mut stack: Vec<Block> = Vec::new();
    let mut top: Vec<Block> = Vec::new();
    let mut text_start = 0;
    let mut i = 0;
    let flush = |text: &str, stack: &mut Vec<Block>, top: &mut Vec<Block>| {
        if text.is_empty() {
            return;
        }
        match stack.last_mut() {
            Some(b) => b.inner.push(Inner::Html(text.to_string())),
            None => top.push(Block { name: None, inner: vec![Inner::Html(text.to_string())] }),
        }
    };
    while let Some(off) = s[i..].find("<!--") {
        let at = i + off;
        let Some((closer, name, void, end)) = delimiter(s, at) else {
            i = at + 4;
            continue;
        };
        flush(&s[text_start..at], &mut stack, &mut top);
        if closer {
            if let Some(b) = stack.pop() {
                match stack.last_mut() {
                    Some(parent) => parent.inner.push(Inner::Block(b)),
                    None => top.push(b),
                }
            }
        } else if void {
            let b = Block { name: Some(name), inner: Vec::new() };
            match stack.last_mut() {
                Some(parent) => parent.inner.push(Inner::Block(b)),
                None => top.push(b),
            }
        } else {
            stack.push(Block { name: Some(name), inner: Vec::new() });
        }
        i = end;
        text_start = end;
    }
    flush(&s[text_start..], &mut stack, &mut top);
    while let Some(b) = stack.pop() {
        match stack.last_mut() {
            Some(parent) => parent.inner.push(Inner::Block(b)),
            None => top.push(b),
        }
    }
    top
}

const EXCERPT_INNER: &[&str] = &[
    "core/freeform", "core/heading", "core/html", "core/list", "core/media-text", "core/paragraph", "core/preformatted", "core/pullquote", "core/quote",
    "core/table", "core/verse",
];
const EXCERPT_WRAPPERS: &[&str] = &["core/columns", "core/column", "core/group"];

fn allowed(name: &Option<String>, list: &[&str]) -> bool {
    name.as_deref().is_none_or(|n| list.contains(&n))
}

fn render(b: &Block) -> String {
    b.inner
        .iter()
        .map(|i| match i {
            Inner::Html(h) => h.clone(),
            Inner::Block(b) => render(b),
        })
        .collect()
}

fn children(b: &Block) -> impl Iterator<Item = &Block> {
    b.inner.iter().filter_map(|i| if let Inner::Block(b) = i { Some(b) } else { None })
}

/// _excerpt_render_inner_blocks(): a wrapper's allowed inner blocks, without the wrapper's own markup.
fn render_inner(b: &Block) -> String {
    let all: Vec<&str> = EXCERPT_INNER.iter().chain(EXCERPT_WRAPPERS).copied().collect();
    children(b).filter(|c| allowed(&c.name, &all)).map(|c| if children(c).next().is_none() { render(c) } else { render_inner(c) }).collect()
}

/// excerpt_remove_blocks(): only text blocks, and wrappers of them, are kept for an excerpt.
fn excerpt_blocks(content: &str) -> String {
    if !content.contains("<!-- wp:") {
        return content.to_string();
    }
    let all: Vec<&str> = EXCERPT_INNER.iter().chain(EXCERPT_WRAPPERS).copied().collect();
    let mut out = String::new();
    for b in parse_blocks(content) {
        if !allowed(&b.name, &all) {
            continue;
        }
        if children(&b).next().is_some() {
            if b.name.as_deref().is_some_and(|n| EXCERPT_WRAPPERS.contains(&n)) {
                out.push_str(&render_inner(&b));
                continue;
            }
            if children(&b).any(|c| !allowed(&c.name, EXCERPT_INNER) || children(c).next().is_some()) {
                continue;
            }
        }
        out.push_str(&render(&b));
    }
    out
}

/// wp_strip_all_tags() of the content as wpautop() left it: script and style dropped with their text, comments
/// and tags removed, a line break where wpautop() put one around a block element.
fn strip_tags(s: &str) -> String {
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
        if tail.starts_with("<!--") {
            rest = tail.find("-->").map_or("", |e| &tail[e + 3..]);
            continue;
        }
        let end = tail.find('>').map_or(tail.len(), |e| e + 1);
        let tag = &tail[..end];
        let name: String = tag.trim_start_matches(['<', '/']).chars().take_while(|c| c.is_ascii_alphanumeric()).collect::<String>().to_ascii_lowercase();
        rest = &tail[end..];
        if (name == "script" || name == "style") && !tag.starts_with("</") {
            let close = format!("</{name}");
            if let Some(after) = rest.to_ascii_lowercase().find(&close).map(|e| &rest[e..]) {
                rest = after.find('>').map_or("", |g| &after[g + 1..]);
                continue;
            }
        }
        if BLOCK_TAGS.contains(&name.as_str()) {
            out.push('\n');
        }
    }
    out.push_str(rest);
    out.trim_matches([' ', '\t', '\n', '\r', '\0', '\u{b}']).to_string()
}

/// The theme's excerpt_more: what follows an excerpt cut at 55 words.
fn more_link(url: &str) -> String {
    format!("&hellip;</p><p><a class=\"btn btn-primary\" href=\"{url}\">Continue reading <i class=\"fas fa-arrow-right\"></i></a></p>")
}

/// the_excerpt() of a result: the post's own excerpt, else its content's first 55 words with the theme's link on.
fn excerpt(site: &Site, p: &Page) -> String {
    let own = field(site, p.id, "@excerpt");
    let text = if own.trim().is_empty() {
        let content = strip_shortcodes(post_content(site, p.id));
        let words_from = strip_tags(&texturize(&excerpt_blocks(&content), p.lang));
        let words: Vec<&str> = words_from.split([' ', '\t', '\n', '\r']).filter(|w| !w.is_empty()).collect();
        if words.len() > EXCERPT_WORDS {
            format!("{}{}", words[..EXCERPT_WORDS].join(" "), more_link(&permalink(p)))
        } else {
            words.join(" ")
        }
    } else {
        own.to_string()
    };
    autop(&texturize(&text, p.lang))
}

/// wpautop() of an excerpt: paragraphs at blank lines, single line breaks as `<br />`.
fn autop(s: &str) -> String {
    let s = s.trim();
    if s.is_empty() {
        return String::new();
    }
    let s = s.replace("</p><p>", "</p>\n\n<p>");
    s.split("\n\n")
        .map(str::trim)
        .filter(|p| !p.is_empty())
        .map(|p| {
            let p = p.strip_prefix("<p>").unwrap_or(p);
            let p = p.strip_suffix("</p>").unwrap_or(p);
            format!("<p>{}</p>\n", p.replace('\n', "<br />\n"))
        })
        .collect()
}

/// search.php through the content column, with loops/search-results.php for the results.
fn main_html(site: &Site, query: &str, shown: &[&Page]) -> String {
    let mut out = format!(
        "\n\n<main class=\"container mt-5\">\n  <div class=\"row\">\n\n    <div class=\"col-sm\">\n      <div id=\"content\" role=\"main\">\n        <header class=\"mb-4 border-bottom\">\n          <h1>\n            Search Results for &ldquo;{query}&rdquo;\n          </h1>\n        </header>\n        \n"
    );
    for p in shown {
        let title = ampersands(&texturize(&p.title, p.lang));
        out.push_str(&format!(
            "  <article role=\"article\" id=\"post_{}\" class=\"{}\">\n    <header>\n      <h2><a href=\"{}\">{}</a></h2>\n    </header>\n    {}  </article>\n",
            p.id.0,
            post_class(site, p),
            permalink(p),
            title.trim(),
            excerpt(site, p)
        ));
    }
    if shown.is_empty() {
        out.push_str("  <div class=\"alert alert-warning\">\n    <i class=\"fas fa-exclamation-triangle\"></i> Sorry, your search yielded no results.  </div>\n");
    }
    out.push_str("      </div><!-- /#content -->\n    </div>\n\n    ");
    out
}

/// One page of a search's results as asked for.
struct Shown<'a> {
    s: &'a str,
    lang: Lang,
    /// The page of results, and how many there are.
    paged: usize,
    pages: usize,
    /// The `page` query var.
    page: usize,
    /// An XMLHttpRequest, for which WPML prints no alternates.
    ajax: bool,
}

/// The holes of the search's head and header that differ from the 404 page's: Yoast's title, Open Graph,
/// Twitter and schema for a search, WPML's alternates and switchers with the search in their links.
fn holes(site: &Site, v: &Shown, results: &[&Page]) -> BTreeMap<&'static str, String> {
    let (s, lang, paged, pages) = (v.s, v.lang, v.paged, v.pages);
    let (first, last) = (results.first().copied(), results.last().copied());
    let not_found = |name: &str| site.options(lang).get(&format!("{}{name}", theme::NOT_FOUND_OPTION)).map(|f| f.value.clone()).unwrap_or_default();
    let (shown, u) = (esc(s), urlencode(s, false));
    let page = if paged > 1 { if lang == Lang::Fr { format!(" - Page {paged} sur {pages}") } else { format!(" - Page {paged} of {pages}") } } else { String::new() };
    let title = format!("You searched for {shown}{page} - Harcourt");
    let mut h = BTreeMap::new();
    // WPML prints no alternates for a later page of results.
    if paged == 1 && !v.ajax {
        h.insert(
            "hreflang",
            format!(
                "<link rel=\"alternate\" hreflang=\"en\" href=\"{ORIGIN}/?s={u}\" />\n<link rel=\"alternate\" hreflang=\"fr\" href=\"{ORIGIN}/?lang=fr&#038;s={u}\" />\n<link rel=\"alternate\" hreflang=\"x-default\" href=\"{ORIGIN}/?s={u}\" />\n"
            ),
        );
    }
    h.insert("seo_og_type", "\t<meta property=\"og:type\" content=\"article\" />\n".to_string());
    h.insert("seo_og_description_url", format!("\t<meta property=\"og:url\" content=\"{ORIGIN}/search/{shown}/{}\" />\n", lang.query()));
    h.insert(
        "seo_article_twitter",
        format!("\t<meta name=\"twitter:card\" content=\"summary_large_image\" />\n\t<meta name=\"twitter:title\" content=\"{title}\" />\n\t<meta name=\"twitter:site\" content=\"@theharcourtco\" />\n"),
    );
    h.insert("yoast_schema", schema(site, lang, &shown, &title, first, &not_found("yoast_schema")));
    h.insert("datalayer", "{\"pagePostType\":\"search-results\"}".to_string());
    let found = if first.is_some() { "search-results" } else { "search-no-results" };
    let body_class = not_found("body_class").replacen("error404", &format!("search {found}"), 1);
    // body_class(): `paged` for a later page of results; the page number from the `page` var, else the page.
    let number = if v.page > 1 { v.page } else { paged };
    let body_class = match (paged > 1, number) {
        (false, 0 | 1) => body_class,
        (is_paged, n) => body_class.replacen(" wp-custom-logo", &format!("{} wp-custom-logo paged-{n} search-paged-{n}", if is_paged { " paged" } else { "" }), 1),
    };
    h.insert("body_class", body_class);
    let links = |v: String| {
        v.replace(&format!("href=\"{ORIGIN}/?lang=fr\""), &format!("href=\"{ORIGIN}/?lang=fr&amp;s={u}\""))
            .replace(&format!("href=\"{ORIGIN}/\""), &format!("href=\"{ORIGIN}/?s={u}\""))
    };
    h.insert("lang_switcher", links(not_found("lang_switcher")));
    h.insert("wpml_hidden_switcher", links(not_found("wpml_hidden_switcher")));
    h.insert("title", title);
    h.insert("sidebar", sidebar(site, lang, last));
    h
}

/// sidebar.php after the loop, where `$post` is the last result shown: a FAQ shows the FAQ widget area, the
/// `[location]` widget (custom_html-3); any other post its own `sidebar_select` area, which the export recorded as
/// the page's `sidebar`; no result shows none. custom_html-2 is the same widget, and the export holds its output.
fn sidebar(site: &Site, lang: Lang, last: Option<&Page>) -> String {
    let own = |p: &Page| site.meta(p.id).get("sidebar").cloned();
    match last {
        Some(p) if p.kind == PostType::Faq => site
            .pages
            .iter()
            .filter(|q| q.lang == lang)
            .filter_map(own)
            .find(|s| s.contains("custom_html-2 widget_custom_html"))
            .map(|s| s.replace("custom_html-2 widget_custom_html", "custom_html-3 widget_custom_html"))
            .unwrap_or_default(),
        Some(p) => own(p).unwrap_or_default(),
        None => String::new(),
    }
}

/// Yoast's graph for a search: the results page, the first result's featured image, the breadcrumb, then the
/// site's own pieces as the 404 page prints them.
fn schema(site: &Site, lang: Lang, shown: &str, title: &str, first: Option<&Page>, not_found: &str) -> String {
    let in_lang = if lang == Lang::Fr { "fr-FR" } else { "en-US" };
    let id = json(&format!("{ORIGIN}/?s={}", urlencode(shown, true)));
    let image = first.and_then(|p| thumbnail(site, p.id)).and_then(|t| site.media.get(&MediaId(t)));
    let mut graph = format!("{{\"@type\":[\"CollectionPage\",\"SearchResultsPage\"],\"@id\":{id},\"url\":{id},\"name\":{},\"isPartOf\":{{\"@id\":\"https:\\/\\/www.harcourt.co\\/#website\"}},", json(title));
    if let Some(m) = image {
        let url = json(&format!("{}{}", theme::UPLOADS, m.file));
        graph.push_str(&format!("\"primaryImageOfPage\":{{\"@id\":\"#primaryimage\"}},\"image\":{{\"@id\":\"#primaryimage\"}},\"thumbnailUrl\":{url},"));
    }
    graph.push_str(&format!("\"breadcrumb\":{{\"@id\":\"#breadcrumb\"}},\"inLanguage\":\"{in_lang}\"}},"));
    if let Some(m) = image {
        let url = json(&format!("{}{}", theme::UPLOADS, m.file));
        graph.push_str(&format!("{{\"@type\":\"ImageObject\",\"inLanguage\":\"{in_lang}\",\"@id\":\"#primaryimage\",\"url\":{url},\"contentUrl\":{url},\"width\":{},\"height\":{}", m.width, m.height));
        if !m.alt.is_empty() {
            graph.push_str(&format!(",\"caption\":{}", json(&m.alt)));
        }
        graph.push_str("},");
    }
    let (home, searched) = if lang == Lang::Fr { ("Accueil", "Vous avez recherché") } else { ("Home", "You searched for") };
    graph.push_str(&format!(
        "{{\"@type\":\"BreadcrumbList\",\"@id\":\"#breadcrumb\",\"itemListElement\":[{{\"@type\":\"ListItem\",\"position\":1,\"name\":\"{home}\",\"item\":\"https:\\/\\/www.harcourt.co\\/\"}},{{\"@type\":\"ListItem\",\"position\":2,\"name\":{}}}]}},",
        json(&format!("{searched} {shown}"))
    ));
    match not_found.split_once("\"@graph\":[") {
        Some((head, tail)) => format!("{head}\"@graph\":[{graph}{tail}"),
        None => not_found.to_string(),
    }
}

/// feed_links_extra(): the search's own feed, after the site's comments feed (the chrome has no hole there).
fn with_feed_link(html: String, lang: Lang, s: &str) -> String {
    let (shown, u) = (esc(s), urlencode(s, false));
    let line = match lang {
        Lang::En => format!("<link rel=\"alternate\" type=\"application/rss+xml\" title=\"Harcourt &raquo; Search Results for &#8220;{shown}&#8221; Feed\" href=\"{ORIGIN}/search/{u}/feed/rss2/\" />\n"),
        Lang::Fr => format!(
            "<link rel=\"alternate\" type=\"application/rss+xml\" title=\"Harcourt &raquo; Flux de résultats de la recherche pour «\u{a0}{shown}\u{a0}»\" href=\"{ORIGIN}/search/{u}/?lang=fr/feed/rss2/\" />\n"
        ),
    };
    let at = html.find("/comments/feed/").and_then(|c| html[c..].find("/>\n").map(|e| c + e + 3));
    match at {
        Some(at) => format!("{}{line}{}", &html[..at], &html[at..]),
        None => html,
    }
}

#[derive(Debug, Clone, serde::Deserialize)]
struct Island {
    form: u32,
    #[serde(default)]
    ajax: bool,
    #[serde(default)]
    theme: String,
    #[serde(default)]
    styles: String,
    #[serde(default)]
    in_content: bool,
    #[serde(default)]
    texturize: bool,
}

fn legacy() -> Response {
    let mut r = Response::new(Body::empty());
    r.headers_mut().insert("x-hxweb", HeaderValue::from_static("legacy"));
    r
}

/// A search page, for a GET or a post (WordPress showed the page for a post that submits no form).
async fn results(Extension(search): Extension<Arc<Search>>, uri: Uri, headers: HeaderMap, body: Bytes) -> Response {
    let posted: Vec<String> = form_urlencoded::parse(&body).map(|(k, _)| k.into_owned()).collect();
    let (s, paged, lang, page) = match asked(uri.path(), uri.query().unwrap_or(""), &posted) {
        Asked::Results { s, paged, lang, page } => (s, paged, lang, page),
        Asked::Found(to) => {
            return match HeaderValue::from_str(&to) {
                Ok(v) => (StatusCode::FOUND, [(header::LOCATION, v), (header::CONTENT_TYPE, HeaderValue::from_static("text/html; charset=UTF-8"))]).into_response(),
                Err(_) => StatusCode::NOT_FOUND.into_response(),
            };
        }
        Asked::Legacy => return legacy(),
    };
    let site = match search.content.site().await {
        Ok(site) => site,
        Err(e) => {
            tracing::error!("search page: cms content unavailable: {e}");
            return (StatusCode::SERVICE_UNAVAILABLE, "Service unavailable").into_response();
        }
    };
    // An export without WordPress's 404 page left nothing to draw the page from.
    if !site.options(lang).contains_key(&format!("{}title", theme::NOT_FOUND_OPTION)) {
        return legacy();
    }
    let found = search_posts(&site, &s, lang);
    let pages = found.len().div_ceil(PER_PAGE);
    let request_uri = uri.path_and_query().map_or("/", |p| p.as_str()).to_string();
    let (status, html) = if paged > 1 && paged > pages {
        (StatusCode::NOT_FOUND, theme::not_found(&site, lang, &request_uri))
    } else {
        let shown: Vec<&Page> = found.iter().skip((paged - 1) * PER_PAGE).take(PER_PAGE).copied().collect();
        let ajax = headers.get("x-requested-with").and_then(|v| v.to_str().ok()).is_some_and(|v| v.eq_ignore_ascii_case("xmlhttprequest"));
        let holes = holes(&site, &Shown { s: &s, lang, paged, pages, page, ajax }, &shown);
        let html = theme::without_post(&site, lang, &request_uri, &holes, &main_html(&site, &esc(&s), &shown));
        (StatusCode::OK, with_feed_link(html, lang, &s))
    };
    let islands: Vec<Island> = serde_json::from_str(site.options(lang).get(&format!("{}islands", theme::NOT_FOUND_OPTION)).map_or("[]", |f| f.value.as_str())).unwrap_or_default();
    let agent = headers.get(header::USER_AGENT).and_then(|v| v.to_str().ok()).unwrap_or("");
    let filled = crate::cms::forms::fill(&html, |form, index| {
        let island = islands.get(index).filter(|i| i.form == form);
        let position = islands.iter().position(|i| i.form == form).unwrap_or(index) + 1;
        let (search, request_uri) = (search.clone(), &request_uri);
        async move {
            let i = island.ok_or_else(|| format!("form {form} is not in the 404 page's islands"))?;
            let embed = FormEmbed {
                form,
                lang,
                page_uri: request_uri,
                ajax: i.ajax,
                theme: &i.theme,
                styles: &i.styles,
                in_content: i.in_content,
                texturize: i.texturize,
                position,
                user_agent: agent,
            };
            search.forms.markup(&embed).await
        }
    })
    .await;
    match filled {
        Ok(html) => {
            let host = search.origin.trim_start_matches("https://");
            let html = if host == "www.harcourt.co" { html } else { html.replace("//www.harcourt.co", &format!("//{host}")).replace("\\/\\/www.harcourt.co", &format!("\\/\\/{host}")) };
            let mut r = Response::new(Body::from(html));
            *r.status_mut() = status;
            let h = r.headers_mut();
            h.insert(header::CONTENT_TYPE, HeaderValue::from_static("text/html; charset=UTF-8"));
            h.insert(header::CACHE_CONTROL, HeaderValue::from_static("no-cache"));
            h.insert(header::STRICT_TRANSPORT_SECURITY, HeaderValue::from_static("max-age=31536000; includeSubDomains"));
            h.insert("x-hxweb", HeaderValue::from_static("cms-raw"));
            r
        }
        Err(e) => {
            tracing::error!("search page: {e}");
            (StatusCode::BAD_GATEWAY, "Bad gateway").into_response()
        }
    }
}

/// The results of a search for `s` in `lang`, in WordPress's order.
fn search_posts<'a>(site: &'a Site, s: &str, lang: Lang) -> Vec<&'a Page> {
    search(site, &parse_terms(s, lang), lang)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_rewrite_rules_and_wpml_read_the_request() {
        assert_eq!(asked("/search/products", "q=alignment+pin", &[]), Asked::Results { s: "products".into(), paged: 1, lang: Lang::En, page: 0 });
        assert_eq!(asked("/search/alignment+pin/", "lang=fr", &[]), Asked::Results { s: "alignment pin".into(), paged: 1, lang: Lang::Fr, page: 0 });
        assert_eq!(asked("/search/the/page/2/", "", &[]), Asked::Results { s: "the".into(), paged: 2, lang: Lang::En, page: 0 });
        assert_eq!(asked("/search/%2570in/", "", &[]), Asked::Results { s: "%70in".into(), paged: 1, lang: Lang::En, page: 0 }, "decoded once");
        assert_eq!(asked("/search/a/b/", "", &[]), Asked::Results { s: "a/b".into(), paged: 1, lang: Lang::En, page: 0 });
        assert_eq!(asked("/search/products", "lang=en", &[]), Asked::Found("/search/products/".into()));
        assert_eq!(asked("/search/products", "lang=en&q=1", &[]), Asked::Found("/search/products/?q=1".into()));
        assert_eq!(asked("/search/products/feed/", "", &[]), Asked::Legacy);
        assert_eq!(asked("/search/products/feed/rss2/", "", &[]), Asked::Legacy);
        assert_eq!(asked("/search/products", "paged=2", &[]), Asked::Legacy);
        assert_eq!(asked("/search/products", "page=2", &[]), Asked::Results { s: "products".into(), paged: 1, lang: Lang::En, page: 2 });
        assert_eq!(asked("/search/products", "", &["q".into()]), Asked::Results { s: "products".into(), paged: 1, lang: Lang::En, page: 0 });
        assert_eq!(asked("/search/products", "", &["gform_submit".into()]), Asked::Legacy);
    }

    #[test]
    fn terms_split_drop_stopwords_and_fall_back_to_the_sentence() {
        let t = parse_terms("alignment pin", Lang::En);
        assert_eq!((t.include.clone(), t.count), (vec!["alignment".to_string(), "pin".to_string()], 2));
        let t = parse_terms("the", Lang::En);
        assert_eq!((t.include.clone(), t.count), (vec!["the".to_string()], 1));
        let t = parse_terms("\"Ball Lock\" -pin", Lang::En);
        assert_eq!((t.include.clone(), t.exclude.clone(), t.sentence.is_none()), (vec!["ball lock".to_string()], vec!["pin".to_string()], true));
        assert_eq!(parse_terms("Élément", Lang::Fr).include, vec!["element".to_string()]);
    }

    #[test]
    fn escaping_follows_wordpress() {
        assert_eq!(esc("pin's & \"x\" &amp;"), "pin&#039;s &amp; &quot;x&quot; &amp;");
        assert_eq!(urlencode("pin's & \"x\"", false), "pin%27s+%26+%22x%22");
        assert_eq!(urlencode("a&#039;b c", true), "a%26%23039%3Bb%20c");
        assert_eq!(json("a/b \"c\" é"), "\"a\\/b \\\"c\\\" é\"");
    }

    #[test]
    fn excerpts_keep_text_blocks_only() {
        let content = "<!-- wp:heading -->\n<h2>Privacy Policy</h2>\n<!-- /wp:heading -->\n\n<!-- wp:image {\"id\":5} -->\n<figure><img src=\"x\"/></figure>\n<!-- /wp:image -->\n\n<!-- wp:paragraph -->\n<p>It's ours.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[gravityform id=\"1\"]\n<!-- /wp:shortcode -->";
        let text = strip_tags(&texturize(&excerpt_blocks(&strip_shortcodes(content)), Lang::En));
        assert_eq!(text.split_whitespace().collect::<Vec<_>>().join(" "), "Privacy Policy It&#8217;s ours.");
        let group = "<!-- wp:group -->\n<div class=\"g\"><!-- wp:paragraph -->\n<p>Inside</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->";
        assert_eq!(strip_tags(&excerpt_blocks(group)), "Inside");
        assert_eq!(strip_shortcodes("a [year] b [gravityform id=1]x[/gravityform] c [other]"), "a  b  c [other]");
    }

    #[test]
    fn wpautop_of_an_excerpt() {
        assert_eq!(autop(""), "");
        assert_eq!(autop("Our products."), "<p>Our products.</p>\n");
        let cut = format!("words{}", more_link("https://www.harcourt.co/privacy/"));
        assert_eq!(
            autop(&cut),
            "<p>words&hellip;</p>\n<p><a class=\"btn btn-primary\" href=\"https://www.harcourt.co/privacy/\">Continue reading <i class=\"fas fa-arrow-right\"></i></a></p>\n"
        );
    }
}
