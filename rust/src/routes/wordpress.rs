//! Owner: the wordpress agent. Routes for what WordPress did: page templates, menus, forms, SEO, redirects, editable page text, and retiring wp-login, xmlrpc and wp-admin.
//! Register every route here and nowhere else; mod.rs only merges this router.
//!
//! A page listed in PAGES is rendered from the cms tables (rust/src/cms); every other WordPress page is still
//! served from the static export by pages.rs. Deleting a path from PAGES hands it back. A Gravity Forms post to a
//! page goes to orders::forms::handle, and its answer takes the form's place in the page.
//!
//! The staff screens that edit this content (cms::admin) are mounted here, under /staff/cms.

use std::{collections::BTreeMap, sync::Arc};

use axum::{
    body::Body,
    extract::{Extension, FromRequestParts, Request, State},
    http::{header, HeaderName, HeaderValue, Response, StatusCode, Uri},
    response::IntoResponse,
    routing::get,
    Router,
};
use percent_encoding::percent_decode_str;

use crate::{
    api::{Auth, ClientIp},
    visitor::SentPath,
    cms::{
        docroot::{self, Owner},
        feed, sitemap,
        forms::{FormSource, Islands, OrdersForms},
        theme,
        url::{urldecode, with_query},
        Content, FormEmbed, Lang, Page, PostType, Site, Status,
    },
    orders::{
        forms::{model, render, submit::Outcome},
        Orders,
    },
};

/// Every path of a page whose template Rust renders, in both languages. `hxweb-cms check` proves each renders as
/// WordPress did.
const PAGES: &[&str] = &[
    "/",
    "/beta_catalog/",
    "/boxjoint-inquiry-form/",
    "/cart/",
    "/catalog/",
    "/catalogue/",
    "/checkout/",
    "/company/",
    "/company/about-harcourt/",
    "/company/career-opportunities/",
    "/company/history/",
    "/company/why-harcourt/",
    "/confidentialite/",
    "/contact/",
    "/contact-form/",
    "/contact-form-hwy-madison-heights-mi/",
    "/contact-form-madison-heights-mi/",
    "/contact-form-troy-mi/",
    "/contact-france/",
    "/contact-india/",
    "/contact-uk/",
    "/customer_portal/",
    "/dashboard/",
    "/demande-dacces/",
    "/formulaire-de-contact/",
    "/gamme-de-produits/",
    "/gamme-de-produits/bridage/",
    "/gamme-de-produits/centrage/",
    "/gamme-de-produits/elements-doutillages/",
    "/gamme-de-produits/jauges/",
    "/gamme-de-produits/les-essentiels-de-loutillage/",
    "/gamme-de-produits/levage-greement/",
    "/gamme-de-produits/outillage-composite/",
    "/gamme-de-produits/percage/",
    "/gamme-de-produits/positionnements/",
    "/gamme-de-produits/stockage-et-transport-doutils/",
    "/impersonate/",
    "/industries/",
    "/industries/automobile/",
    "/industries/industrie-lourde/",
    "/actualites/",
    "/innovations/",
    "/innovations/blackboard/",
    "/innovations/captive/",
    "/innovations/configurateur-de-produits/",
    "/innovations/elements-imperdables/",
    "/innovations/hkit-valises/",
    "/innovations/metrology-solutions/",
    "/innovations/product-configurators/",
    "/innovations/shadowboxing/",
    "/innovations/solutions-de-metrologie/",
    "/innovations/tooling-configurators/",
    "/legal/",
    "/news/",
    "/newuser/",
    "/nouvel-utilisateur/",
    "/portail-clients/",
    "/privacy/",
    "/product/",
    "/product/alignment/",
    "/product/clamping/",
    "/product/composite-tooling/",
    "/product/drilling/",
    "/product/gaging/",
    "/product/hoisting-rigging/",
    "/product/jig-elements/",
    "/product/locators/",
    "/product/tool-storage/",
    "/product/tooling-essentials/",
    "/merci/",
    "/nous-contacter/",
    "/nous-contacter-france/",
    "/nous-contacter-inde/",
    "/nous-contacter-uk/",
    "/panier/",
    "/panier-2/",
    "/recherche/",
    "/request-access/",
    "/resources/",
    "/resources/faq/",
    "/resources/lunch-learns/",
    "/ressources/",
    "/ressources/dejeuners-et-causeries/",
    "/ressources/faq/",
    "/search/",
    "/shopping-cart/",
    "/societe/",
    "/societe/a-propos-dharcourt/",
    "/societe/histoire/",
    "/societe/opportunites-de-carriere/",
    "/societe/pourquoi-harcourt/",
    "/tableau-de-bord/",
    "/thank-you/",
    "/valider-mon-panier/",
];

/// The docroot's robots.txt, which Apache served ahead of Yoast's virtual one.
// A copy of the docroot's robots.txt, so the crate builds from rust/ alone.
const ROBOTS: &str = include_str!("../../assets/robots.txt");

pub struct Wp {
    core: Arc<Auth>,
    content: Content,
    forms: Option<Arc<dyn FormSource>>,
    orders: Arc<Orders>,
    /// WordPress, for a form post whose form the orders tables lack.
    legacy: Option<Islands>,
}

/// The page routes, and WordPress's front controller: every other GET that WordPress answered on prod (Apache
/// sent it to index.php). The front controller sits outside the visitor layer, as WordPress ran without
/// scs_header.php; it hands back what is not WordPress's.
pub struct Routers {
    pub pages: Router,
    pub front: Router,
}

pub fn routers(core: Arc<Auth>) -> Routers {
    let orders = Arc::new(Orders::from_env(core.clone()));
    let forms = OrdersForms { orders: orders.clone(), fallback: Islands::from_env() };
    routers_with(core, Some(Arc::new(forms)), orders, Islands::from_env())
}

/// Both routers in one, with the given source of form markup (tests pass a fixed one).
pub fn router_with_forms(core: Arc<Auth>, forms: Option<Arc<dyn FormSource>>) -> Router {
    let orders = Arc::new(Orders::from_env(core.clone()));
    let r = routers_with(core, forms, orders, None);
    r.pages.merge(r.front)
}

fn routers_with(core: Arc<Auth>, forms: Option<Arc<dyn FormSource>>, orders: Arc<Orders>, legacy: Option<Islands>) -> Routers {
    let wp = Arc::new(Wp { content: Content::new(core.pool.clone()), forms, orders, legacy, core: core.clone() });
    let mut r = Router::new().route("/robots.txt", get(robots));
    for path in PAGES {
        r = r.route(path, get(page).post(page_post));
        if let Some(bare) = path.strip_suffix('/').filter(|p| !p.is_empty()) {
            r = r.route(bare, get(page).post(page_post));
        }
    }
    let front = Router::new().route("/{*path}", get(front)).with_state(wp.clone());
    Routers { pages: r.with_state(wp).merge(crate::cms::admin::routes().with_state(core)), front }
}

fn docroot() -> Option<&'static std::path::Path> {
    static ROOT: std::sync::OnceLock<Option<std::path::PathBuf>> = std::sync::OnceLock::new();
    ROOT.get_or_init(|| std::env::var_os("HXWEB_DOCROOT").map(std::path::PathBuf::from)).as_deref()
}

/// Paths a WordPress rewrite rule gives a meaning this port does not answer yet: feeds, embeds, trackbacks,
/// paging and comment pages, archives (category, tag, format, author, date, search), the post types' singles
/// and the favicon. They go to the legacy stack.
fn unported_rewrite(path: &str) -> bool {
    const SEGMENTS: [&str; 10] = ["feed", "rdf", "rss", "rss2", "atom", "embed", "trackback", "page", "attachment", "favicon.ico"];
    const BASES: [&str; 11] = ["category", "tag", "type", "author", "search", "comments", "faq", "faq_type", "case-study", "testimonials", "job"];
    let segments: Vec<String> = path.split('/').filter(|s| !s.is_empty()).map(str::to_ascii_lowercase).collect();
    let numeric = |s: &str| !s.is_empty() && s.bytes().all(|b| b.is_ascii_digit());
    segments.iter().any(|s| SEGMENTS.contains(&s.as_str()) || s.starts_with("comment-page-"))
        || (segments.len() > 1 && BASES.contains(&segments[0].as_str()))
        || segments.first().is_some_and(|s| s.len() == 4 && numeric(s))
        || segments.last().is_some_and(|s| numeric(s))
}

/// Any GET no route claims: a static file as nginx served it, WordPress's answer when WordPress gave one, else what
/// an unclaimed path gets.
async fn front(State(wp): State<Arc<Wp>>, headers: header::HeaderMap, uri: Uri, sent: Option<Extension<SentPath>>) -> Response<Body> {
    let path = percent_decode_str(uri.path()).decode_utf8_lossy().into_owned();
    // WordPress's own front controller: redirect_canonical() drops it from the URL as sent (the front routes
    // /index.php/more as /index.php), except under a search.
    let asked = sent.map_or(path.clone(), |Extension(s)| percent_decode_str(s.uri.path()).decode_utf8_lossy().into_owned());
    if let Some(rest) = asked.strip_prefix("/index.php").filter(|r| r.is_empty() || r.starts_with('/')) {
        let raw_query = uri.query().unwrap_or("");
        if crate::cms::url::parse_str(raw_query).iter().any(|(k, _)| k == "s") {
            return legacy();
        }
        return redirect(&wp.core.public_origin, &with_query(if rest.is_empty() { "/" } else { rest }, raw_query));
    }
    match docroot::owner(&path, docroot()) {
        Owner::Legacy => legacy(),
        Owner::NotFound => crate::accounts::static_dirs::not_found(&wp.core.public_origin),
        Owner::File(file, kind) => static_file(file, kind).await,
        Owner::WordPress => {
            let q = query(uri.query().unwrap_or(""));
            let agent = headers.get(header::USER_AGENT).and_then(|v| v.to_str().ok()).unwrap_or("");
            if let Some(r) = sitemap(&wp, &path, q.lang).await {
                return r;
            }
            if let Some(r) = feed(&wp, &uri, &path, &q, agent).await {
                return r;
            }
            serve(&wp, &uri, agent, None, true).await
        }
    }
}

/// WordPress's feed rules: a redirect to the canonical feed URL (with the query rebuilt), a comments feed, or the
/// 404 page for a feed of no post. The posts feeds are handed back.
async fn feed(wp: &Arc<Wp>, uri: &Uri, path: &str, q: &Query, agent: &str) -> Option<Response<Body>> {
    let site = match wp.content.site().await {
        Ok(s) => s,
        Err(e) => return Some(unavailable(e)),
    };
    let typed = |kind: feed::Kind, mut r: Response<Body>| {
        r.headers_mut().insert(header::CONTENT_TYPE, HeaderValue::from_static(kind.content_type()));
        r
    };
    let raw_query = uri.query().unwrap_or("");
    match feed::parse(&site, path, q.lang)? {
        feed::Feed::Missing(kind) => Some(typed(kind, respond(wp, &site, None, uri, q, agent, None, true).await)),
        feed::Feed::None => Some(respond(wp, &site, None, uri, q, agent, None, true).await),
        feed::Feed::Moved(to, kind) => Some(typed(kind, redirect(&wp.core.public_origin, &with_query(&to, raw_query)))),
        feed::Feed::Show(of, kind) => {
            let canonical = with_query(path, raw_query);
            let asked = if raw_query.is_empty() { path.to_string() } else { format!("{path}?{raw_query}") };
            if canonical != asked {
                return Some(typed(kind, redirect(&wp.core.public_origin, &canonical)));
            }
            let body = feed::comments(&site, of, kind, q.lang, uri.path_and_query().map_or(path, |p| p.as_str()));
            let Some(body) = body else { return Some(legacy()) };
            let mut r = typed(kind, (StatusCode::OK, public(&wp.core.public_origin, &body)).into_response());
            if kind == feed::Kind::Rss2 {
                r.headers_mut().insert(HeaderName::from_static("x-robots-tag"), HeaderValue::from_static("noindex, follow"));
            }
            Some(r)
        }
    }
}

/// Yoast's sitemap rewrite rules: the index, one sitemap per type (`page-sitemap.xml`, page 1 only), its XSL, and
/// WordPress's own /sitemap.xml, which Yoast redirects to its index. None for any other path.
async fn sitemap(wp: &Arc<Wp>, path: &str, lang: Lang) -> Option<Response<Body>> {
    let xml = |body: String| -> Response<Body> {
        ([(header::CONTENT_TYPE, "text/xml; charset=UTF-8"), (HeaderName::from_static("x-robots-tag"), "noindex, follow")], public(&wp.core.public_origin, &body)).into_response()
    };
    let name = path.strip_prefix('/')?;
    if name == "main-sitemap.xsl" {
        return Some(xml(sitemap::XSL.to_string()));
    }
    if name == "sitemap.xml" {
        return Some(redirect(&wp.core.public_origin, "/sitemap_index.xml"));
    }
    let site = match wp.content.site().await {
        Ok(s) => s,
        Err(e) => return Some(unavailable(e)),
    };
    if name == "sitemap_index.xml" || name == "sitemap_index.xml/" {
        return Some(xml(sitemap::index(&site, lang)));
    }
    let (map, page) = name.strip_suffix(".xml")?.split_once("-sitemap")?;
    if !page.bytes().all(|b| b.is_ascii_digit()) {
        return None;
    }
    // A sitemap Yoast does not have is a 404 over the blog query, which lists the posts: not ported.
    let listed = sitemap::Map::parse(map).and_then(|m| sitemap::urlset(&site, m, lang));
    match (page, listed) {
        ("", Some(body)) => Some(xml(body)),
        ("1", Some(_)) => Some(redirect(&wp.core.public_origin, &format!("{}{}", &path[..path.len() - 5], ".xml"))),
        _ => Some(legacy()),
    }
}

async fn static_file(file: std::path::PathBuf, kind: &'static str) -> Response<Body> {
    match tokio::task::spawn_blocking(move || std::fs::read(file)).await {
        Ok(Ok(bytes)) => ([(header::CONTENT_TYPE, kind), (header::CACHE_CONTROL, "public, max-age=3600")], bytes).into_response(),
        _ => legacy(),
    }
}

/// Query keys that do not change what WordPress renders. Prod's hxweb served a page from the static export, finished
/// (vendored assets, static icons), only when every key was one of these, and passed any other request to
/// WordPress, which printed the page as it is.
const NEUTRAL_KEYS: [&str; 9] = ["lang", "hxlang", "utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_content", "gclid", "fbclid"];

/// Query keys WordPress acts on (WP::$public_query_vars, this site's post types and taxonomies, Yoast's and WP Job
/// Manager's vars, the ones redirect_canonical() and the comment and preview code read, and the form-island
/// endpoint of mu-plugins/hx-form-island.php). A page asked for with one of them is WordPress's to answer: another
/// post, a search, a feed, a redirect.
const WP_KEYS: &[&str] = &[
    "hx_form",
    "m", "p", "posts", "w", "cat", "withcomments", "withoutcomments", "s", "search", "exact", "sentence", "calendar", "page", "paged", "more", "tb",
    "pb", "author", "order", "orderby", "year", "monthnum", "day", "hour", "minute", "second", "name", "category_name", "tag", "feed", "author_name",
    "pagename", "page_id", "error", "attachment", "attachment_id", "subpost", "subpost_id", "preview", "robots", "favicon", "taxonomy", "term", "cpage",
    "post_type", "embed", "rest_route", "sitemap", "sitemap-subtype", "sitemap-stylesheet", "sitemap_n", "yoast-sitemap-xsl", "wpseo_category_redirect",
    "job-manager-api", "case_study", "faq", "faq_type", "testimonials", "job_listing", "job_listing_category", "job_listing_type", "post_format",
    "replytocom", "unapproved", "moderation-hash", "preview_id", "preview_nonce", "customize_changeset_uuid", "customize_theme", "wp_customize",
];

/// Which page a request asks for, from its query.
#[derive(Debug, PartialEq, Eq)]
struct Query {
    /// WPML's `lang`: the last one counts, and anything but `fr` is English.
    lang: Lang,
    /// That last `lang` says `en`, which WPML redirects away.
    lang_en: bool,
    rest_route: bool,
    /// Every key is in NEUTRAL_KEYS.
    neutral: bool,
    /// Some key is in WP_KEYS.
    wordpress: bool,
    /// The query without its `lang` pairs, as WPML's redirect keeps it.
    without_lang: String,
    /// The request URI WordPress would see, for form actions: without lang=en, which WPML drops, and on a neutral
    /// request without the site's own hxlang marker, which the static pages left out.
    form_uri_query: String,
    /// The same with the hxlang marker kept, as WordPress saw a request it printed itself.
    wordpress_form_query: String,
}

fn query(q: &str) -> Query {
    let pairs: Vec<&str> = q.split('&').filter(|kv| !kv.is_empty()).collect();
    let key = |kv: &str| kv.split('=').next().unwrap_or("").to_string();
    let neutral = pairs.iter().all(|kv| NEUTRAL_KEYS.contains(&key(kv).as_str()));
    let wordpress = pairs.iter().any(|kv| WP_KEYS.contains(&urldecode(&key(kv)).as_str()));
    let last_lang = pairs.iter().rev().find(|kv| key(kv) == "lang").copied();
    let lang = if last_lang == Some("lang=fr") { Lang::Fr } else { Lang::En };
    let rest_route = pairs.iter().any(|kv| key(kv) == "rest_route");
    let without_lang = pairs.iter().filter(|kv| key(kv) != "lang").copied().collect::<Vec<_>>().join("&");
    let kept: Vec<&str> = pairs.iter().filter(|kv| !(neutral && key(kv) == "hxlang") && **kv != "lang=en").copied().collect();
    let form_uri_query = if kept.is_empty() { String::new() } else { format!("?{}", kept.join("&")) };
    let all: Vec<&str> = pairs.iter().filter(|kv| **kv != "lang=en").copied().collect();
    let wordpress_form_query = if all.is_empty() { String::new() } else { format!("?{}", all.join("&")) };
    Query { lang, lang_en: last_lang == Some("lang=en"), rest_route, neutral, wordpress, without_lang, form_uri_query, wordpress_form_query }
}

/// What a claimed path answers, given the content and the requested language.
#[derive(Debug, PartialEq, Eq)]
enum Answer<'a> {
    Render(&'a Page),
    /// 301 to a path on this site.
    Redirect(String),
    /// WPML's 302 away from `lang=en`, with the relative Location it sends.
    Found(String),
    NotFound,
    /// A feed's 301, sent with the feed's content type as WordPress sends it.
    FeedRedirect(String, &'static str),
    /// A query WordPress acts on: the legacy stack answers.
    Legacy,
}

/// What WordPress's rewrite rules and main query make of a path (with its slash), in the request's language. Pages
/// are found by path without regard to case (MySQL's collation), and redirect_canonical() compares paths in lower
/// case, so a page asked for in other case is shown where it was asked for.
fn resolve<'a>(site: &'a Site, path: &str, lang: Lang) -> Answer<'a> {
    let published = |l: Lang| {
        site.find(l, path).or_else(|| site.pages.iter().find(|p| p.lang == l && p.path.eq_ignore_ascii_case(path))).filter(|p| p.status == Status::Publish)
    };
    if let Some(p) = published(lang) {
        // A post type's rewrite rule takes the path in any case, but redirect_canonical() sends the visitor on.
        return if p.kind == PostType::Page || p.path == path { Answer::Render(p) } else { Answer::Redirect(format!("{}{}", p.path, p.lang.query())) };
    }
    // A post of a type with its own base, asked for in the other language: WPML's move to the translation.
    let other = if lang == Lang::En { Lang::Fr } else { Lang::En };
    if let Some(p) = published(other).filter(|p| p.kind != PostType::Page && p.kind != PostType::Post) {
        return match site.translation(p, lang).filter(|t| t.status == Status::Publish) {
            Some(t) => Answer::Redirect(format!("{}{}", t.path, t.lang.query())),
            None => Answer::Legacy,
        };
    }
    if unported_rewrite(path) {
        return Answer::Legacy;
    }
    // A doubled slash still matches the page rule (its `.?` takes the extra slash), and redirect_canonical()
    // fixes the URL; no other rule matches, which is the blog query under a 404, not ported.
    if let Some(rest) = path.strip_prefix('/').filter(|r| r.starts_with('/')) {
        let p = format!("/{}", rest.trim_start_matches('/'));
        return match site.find(lang, &p).filter(|p| p.status == Status::Publish) {
            Some(page) => Answer::Redirect(format!("{}{}", page.path, page.lang.query())),
            None => Answer::Legacy,
        };
    }
    let name = path.trim_matches('/');
    let segments = name.split('/').count();
    // In French WPML finds French pages only, so a one-segment English path is a post name (by_name).
    let translated = published(other).filter(|_| lang == Lang::En || segments > 1);
    let Some(p) = translated else {
        return match segments {
            _ if name.is_empty() => Answer::NotFound,
            1 => by_name(site, name, lang),
            // The attachment rule (`a/b/` is attachment b): none of that name, so a 404 whose guess is by b.
            2 => {
                let last = name.rsplit('/').next().unwrap_or("");
                if site.media.values().any(|m| m.name.eq_ignore_ascii_case(last)) {
                    Answer::Legacy
                } else {
                    guess(site, last, lang, None)
                }
            }
            // No rule matches: WordPress runs the blog query under a 404, which lists the posts. Not ported.
            _ => Answer::Legacy,
        };
    };
    match (lang, site.translation(p, lang)) {
        // WPML sends a French slug asked for in English to the English page, even an unpublished one (whose
        // permalink, and so its stored path, is /?page_id=N).
        (Lang::En, Some(t)) => Answer::Redirect(format!("{}{}", t.path, t.lang.query())),
        (Lang::En, None) => Answer::Redirect(format!("{}{}", p.path, p.lang.query())),
        (Lang::Fr, Some(t)) if t.status == Status::Publish => Answer::Redirect(format!("{}{}", t.path, t.lang.query())),
        (Lang::Fr, _) => Answer::NotFound,
    }
}

/// A post's slug. Rows imported before the cms kept slugs (migration 0501) have none; for those the path ends
/// with it, except the front page's and a draft's.
fn slug(p: &Page) -> &str {
    if !p.name.is_empty() || p.path == "/" || p.path.starts_with("/?") {
        return &p.name;
    }
    p.path.trim_end_matches('/').rsplit('/').next().unwrap_or("")
}

/// A one-segment path that is no top-level page in the request's language: WordPress's post rule (`name=`). WPML
/// looks the name up exactly across every public type and attachments (WPML_404_Guess, other types before
/// pages, the request's language first). A post in the request's language is shown, which redirect_canonical()
/// turns into a redirect to its permalink. Otherwise the query 404s and redirect_guess_404_permalink() guesses,
/// limited to the type WPML found.
fn by_name<'a>(site: &'a Site, name: &str, lang: Lang) -> Answer<'a> {
    let exact = site
        .pages
        .iter()
        .filter(|p| p.status == Status::Publish && slug(p).eq_ignore_ascii_case(name))
        .min_by_key(|p| (p.kind == PostType::Page, p.lang != lang, p.kind != PostType::Post, p.id));
    let attachment = site.media.values().any(|m| m.name.eq_ignore_ascii_case(name));
    match exact {
        _ if attachment && exact.is_none_or(|p| p.kind == PostType::Page) => Answer::Legacy,
        Some(p) if p.lang == lang => Answer::Redirect(format!("{}{}", p.path, p.lang.query())),
        Some(p) => guess(site, name, lang, Some(p.kind)),
        None => guess(site, name, lang, None),
    }
}

/// redirect_guess_404_permalink(): the first published post whose slug starts with `name`, in the order MySQL reads
/// them, linked in the request's language by WPML. With one post type (`of`) MySQL walks the type_status_date
/// index, so by date. Over all types it walks whichever index it estimates reads fewer rows: post_name (every post
/// of any type or status with the prefix, attachments included) when that is fewer than the published posts,
/// alphabetical; else type_status_date, by type then date. So /contact/?lang=fr goes to the French contact form,
/// the oldest page starting with "contact", and /c to the campaign page, the first by name.
fn guess<'a>(site: &'a Site, name: &str, lang: Lang, of: Option<PostType>) -> Answer<'a> {
    let prefix = name.to_lowercase();
    let starts = |s: &str| s.to_lowercase().starts_with(&prefix);
    let mut found: Vec<&Page> = site.pages.iter().filter(|p| p.status == Status::Publish && of.is_none_or(|t| p.kind == t) && starts(slug(p))).collect();
    let rows = site.pages.iter().filter(|p| starts(slug(p))).count() + site.media.values().filter(|m| starts(&m.name)).count();
    let published = site.pages.iter().filter(|p| p.status == Status::Publish).count();
    if of.is_none() && rows < published {
        found.sort_by_cached_key(|p| (slug(p).to_lowercase(), p.id));
    } else {
        found.sort_by_key(|p| (p.kind, p.published_at.as_str(), p.id));
    }
    let Some(&g) = found.first() else { return Answer::NotFound };
    match (lang, site.translation(g, lang)) {
        (Lang::En, t) => {
            let t = t.unwrap_or(g);
            Answer::Redirect(format!("{}{}", t.path, t.lang.query()))
        }
        (Lang::Fr, Some(t)) if t.status == Status::Publish => Answer::Redirect(format!("{}{}", t.path, t.lang.query())),
        // WPML links an untranslated page at its own path with ?lang=fr; asked for there, redirect_canonical()
        // leaves the 404 alone (see route).
        (Lang::Fr, _) => Answer::Redirect(format!("{}?lang=fr", g.path)),
    }
}

/// The query vars of the front page this port answers, and the rest of its query, which redirects keep.
const FRONT_VARS: [&str; 8] = ["p", "page_id", "cat", "m", "feed", "name", "page", "lang"];

/// WordPress's query vars on the front page: a post by id or name, a category by id, a month or year, a feed. Each
/// redirects to the canonical URL (redirect_canonical() keeps the rest of the query); WPML first sends a post or
/// category of the other language to the URL of its own (from English) or of its translation (from French).
/// None for anything else (a search, an author, the blog listing), which is not ported.
fn front_query<'a>(site: &'a Site, raw_query: &str, lang: Lang) -> Option<Answer<'a>> {
    let args = crate::cms::url::parse_str(raw_query);
    let one = |k: &str| {
        args.iter().find(|(name, _)| name == k).and_then(|(_, a)| match a {
            crate::cms::url::Arg::One(v) => Some(v.as_str()),
            crate::cms::url::Arg::List(_) => None,
        })
    };
    if args.iter().any(|(k, _)| WP_KEYS.contains(&k.as_str()) && !FRONT_VARS.contains(&k.as_str())) {
        return None;
    }
    let without = |drop: &[&str]| {
        raw_query.split('&').filter(|kv| !kv.is_empty() && !drop.contains(&urldecode(kv.split('=').next().unwrap_or("")).as_str())).collect::<Vec<_>>().join("&")
    };
    let rest = without(&FRONT_VARS);
    let moved = |to: String| Answer::Redirect(with_query(&to, &rest));
    let other_language = |key: &str, id: i64, own: Lang| match lang {
        Lang::En => Some(Answer::Found(format!("/?{key}={id}&lang={}", own.code()))),
        Lang::Fr => None,
    };
    let numeric = |v: &str| v.parse::<i64>().ok().filter(|n| *n > 0);
    for key in ["p", "page_id"] {
        let Some(v) = one(key) else { continue };
        let id = numeric(v)?;
        if site.media.contains_key(&crate::cms::MediaId(id)) {
            return None;
        }
        let Some(p) = site.page(crate::cms::PageId(id)) else { return Some(Answer::NotFound) };
        // Testimonials are not publicly queryable: WordPress finds nothing by their id.
        if p.kind == PostType::Testimonials {
            return Some(Answer::NotFound);
        }
        let rest = without(&["p", "page_id", "lang"]);
        let moved = |to: String| Answer::Redirect(with_query(&to, &rest));
        if p.status != Status::Publish {
            // WPML sends an unpublished page's id asked for in French to its French translation's.
            return site
                .translation(p, lang)
                .filter(|t| t.status == Status::Publish && lang == Lang::Fr && t.lang == Lang::Fr && rest.is_empty())
                .map(|t| Answer::Found(format!("/?{key}={}&lang=fr", t.id.0)));
        }
        if p.lang == lang {
            return Some(moved(format!("{}{}", p.path, p.lang.query())));
        }
        if !rest.is_empty() {
            return None;
        }
        return match site.translation(p, lang).filter(|t| t.status == Status::Publish && lang == Lang::Fr) {
            Some(t) => Some(Answer::Found(format!("/?{key}={}&lang=fr", t.id.0))),
            None => other_language(key, id, p.lang),
        };
    }
    if let Some(name) = one("name") {
        return match by_name(site, name, lang) {
            Answer::Redirect(to) if rest.is_empty() => Some(Answer::Redirect(to)),
            Answer::NotFound => Some(Answer::NotFound),
            _ => None,
        };
    }
    if let Some(v) = one("cat") {
        let id = numeric(v)?;
        let t = site.terms.iter().find(|t| t.id == id && t.taxonomy == crate::cms::Taxonomy::Category)?;
        return match t.lang {
            Some(l) if l != lang => if rest.is_empty() { other_language("cat", id, l) } else { None },
            _ => Some(moved(format!("{}{}", t.path(), lang.query()))),
        };
    }
    if let Some(m) = one("m") {
        // A date archive with no post 404s; the canonical URL exists only for one that lists something.
        let (prefix, to) = match m.len() {
            4 if numeric(m).is_some() => (m.to_string(), format!("/{m}/")),
            6 if numeric(m).is_some() => (format!("{}-{}", &m[..4], &m[4..]), format!("/{}/{}/", &m[..4], &m[4..])),
            _ => return None,
        };
        let any = site.pages.iter().any(|p| p.kind == PostType::Post && p.status == Status::Publish && p.published_at.starts_with(&prefix));
        return Some(if any { moved(to) } else { Answer::NotFound });
    }
    if let Some(f) = one("feed") {
        let (to, kind) = match f {
            "feed" | "rss" | "rss2" => ("/feed/", "application/rss+xml; charset=UTF-8"),
            "atom" => ("/feed/atom/", "application/atom+xml; charset=UTF-8"),
            "rdf" => ("/feed/rdf/", "application/rdf+xml; charset=UTF-8"),
            "comments-rss2" => ("/comments/feed/", "application/octet-stream; charset=UTF-8"),
            "comments-atom" => ("/comments/feed/atom/", "application/octet-stream; charset=UTF-8"),
            _ => return None,
        };
        return Some(Answer::FeedRedirect(with_query(to, &raw_query.split('&').filter(|kv| !kv.starts_with("feed=")).collect::<Vec<_>>().join("&")), kind));
    }
    match one("page") {
        Some(v) if numeric(v).is_some() => Some(moved(format!("/{}", lang.query()))),
        _ => None,
    }
}

/// The answer for a request path, with or without its trailing slash, as prod answered it. A neutral request for a
/// page in its own language got the exported page. Everything else was WordPress's: WPML drops `lang=en` with a
/// 302, redirect_canonical() adds a missing slash keeping the query as sent, and a page asked for in the other
/// language moves in one 301 to its translation's URL with the query rebuilt (`with_query`).
fn route<'a>(site: &'a Site, path: &str, q: &Query, raw_query: &str) -> Answer<'a> {
    let slashed = if path.ends_with('/') { None } else { Some(format!("{path}/")) };
    let answer = resolve(site, slashed.as_deref().unwrap_or(path), q.lang);
    if q.neutral && slashed.is_none() && matches!(answer, Answer::Render(_)) {
        return answer;
    }
    if q.wordpress {
        return match path {
            // WPML's 302 away from lang=en comes before any query var.
            "/" if q.lang_en => Answer::Found(if q.without_lang.is_empty() { "/".into() } else { format!("/?{}", q.without_lang) }),
            "/" => front_query(site, raw_query, q.lang).unwrap_or(Answer::Legacy),
            _ => Answer::Legacy,
        };
    }
    if q.lang_en && answer != Answer::NotFound {
        let to = slashed.as_deref().unwrap_or(path);
        return Answer::Found(if q.without_lang.is_empty() { to.to_string() } else { format!("{to}?{}", q.without_lang) });
    }
    match (answer, slashed) {
        // redirect_canonical() points the static front page at home_url('/') with the query rebuilt, and moves the
        // visitor when that differs from what was asked (?a= becomes ?a, a repeated key collapses).
        (Answer::Render(p), None) if p.path == "/" => {
            let canonical = with_query(&format!("/{}", p.lang.query()), raw_query);
            let asked = if raw_query.is_empty() { path.to_string() } else { format!("{path}?{raw_query}") };
            if canonical == asked {
                Answer::Render(p)
            } else {
                Answer::Redirect(canonical)
            }
        }
        (Answer::Render(p), None) => Answer::Render(p),
        (Answer::Render(p), Some(_)) if raw_query.is_empty() => Answer::Redirect(p.path.clone()),
        (Answer::Render(p), Some(_)) => Answer::Redirect(format!("{}?{raw_query}", p.path)),
        // redirect_canonical() never sends a visitor to the URL they asked for: the 404 stands, and its sidebar is
        // the guessed page's widgets in the request's language, which the cms does not hold.
        (Answer::Redirect(to), slashed) => {
            let to = with_query(&to, raw_query);
            let asked = if raw_query.is_empty() { path.to_string() } else { format!("{path}?{raw_query}") };
            if slashed.is_none() && to == asked {
                Answer::Legacy
            } else {
                Answer::Redirect(to)
            }
        }
        (other, _) => other,
    }
}

async fn page(State(wp): State<Arc<Wp>>, headers: header::HeaderMap, uri: Uri) -> Response<Body> {
    // The catalog page in its own language is composed around the catalog (compose.rs); WordPress's answers to the
    // rest (its redirects, a form post) stay here.
    if crate::catalog::composed_page(uri.path(), uri.query().unwrap_or("")).is_some() {
        return legacy();
    }
    let agent = headers.get(header::USER_AGENT).and_then(|v| v.to_str().ok()).unwrap_or("");
    serve(&wp, &uri, agent, None, true).await
}

/// The page a post to `path` reaches: redirect_canonical() adds a missing slash only to a GET, so WordPress answers
/// a post to the bare path at the page itself, and Gravity Forms points the form back at the bare path.
fn slashed(path: &str) -> String {
    if path.ends_with('/') {
        path.to_string()
    } else {
        format!("{path}/")
    }
}

/// A post to a page. One carrying `gform_submit` goes to the Gravity Forms port; its answer replaces that form on
/// the page (an ajax form gets the iframe postback document instead); a form the orders tables lack goes on to
/// WordPress. Any other post shows the page, as WordPress did.
async fn page_post(State(wp): State<Arc<Wp>>, req: Request) -> Response<Body> {
    let (mut parts, body) = req.into_parts();
    let Ok(ClientIp(ip)) = ClientIp::from_request_parts(&mut parts, &wp.core).await;
    let Ok(body) = axum::body::to_bytes(body, 8 << 20).await else {
        return (StatusCode::PAYLOAD_TOO_LARGE, "Payload too large").into_response();
    };
    let params = crate::orders::form_params(None, &body);
    let agent = parts.headers.get(header::USER_AGENT).and_then(|v| v.to_str().ok()).unwrap_or("");
    let site = match wp.content.site().await {
        Ok(s) => s,
        Err(e) => return unavailable(e),
    };
    let path = percent_decode_str(parts.uri.path()).decode_utf8_lossy().into_owned();
    let q = query(parts.uri.query().unwrap_or(""));
    let Answer::Render(page) = resolve(&site, &slashed(&path), q.lang) else { return serve(&wp, &parts.uri, agent, None, false).await };
    let Some(form) = params.get("gform_submit").and_then(|v| v.trim().parse::<u32>().ok()) else {
        return serve(&wp, &parts.uri, agent, None, false).await;
    };
    let wp_uri = format!("{}{}", page.path, q.wordpress_form_query);
    let lang = match page.lang {
        Lang::En => model::Lang::En,
        Lang::Fr => model::Lang::Fr,
    };
    match crate::orders::forms::handle(&wp.orders, &params, lang, &wp_uri, &ip, agent, Some(page.title.clone())).await {
        Ok(Some((Outcome::Redirect(url), _))) => crate::orders::html::redirect(&url),
        Ok(Some((Outcome::Invalid(h) | Outcome::Confirmed { html: h, .. }, true))) => {
            (StatusCode::OK, [(header::CONTENT_TYPE, "text/html; charset=UTF-8"), (header::CACHE_CONTROL, "no-store")], h).into_response()
        }
        Ok(Some((outcome, false))) => serve(&wp, &parts.uri, agent, outcome.on_page(form), false).await,
        Ok(None) => match &wp.legacy {
            Some(legacy) => legacy.forward(&parts, body).await,
            None => serve(&wp, &parts.uri, agent, None, false).await,
        },
        Err(e) => {
            tracing::error!("{path}: form {form} submit: {e}");
            (StatusCode::INTERNAL_SERVER_ERROR, "Internal error").into_response()
        }
    }
}

fn unavailable(e: sqlx::Error) -> Response<Body> {
    tracing::error!("cms content unavailable: {e}");
    (StatusCode::SERVICE_UNAVAILABLE, "Service unavailable").into_response()
}

/// The page at `uri`, with `answer` (a submitted form's markup) in place of the first island of that form. A
/// neutral GET gets the page as hxweb finished the static pages; anything else (a post, other query keys) went to
/// WordPress on prod and gets the page as WordPress printed it, forms marked for the visitor's browser. Only a GET
/// or HEAD (`replay`) can be handed back to the legacy stack; a post that would be handed back gets the page at its
/// path, or a 404.
async fn serve(wp: &Arc<Wp>, uri: &Uri, agent: &str, answer: Option<render::Answer>, replay: bool) -> Response<Body> {
    let path = percent_decode_str(uri.path()).decode_utf8_lossy().into_owned();
    let raw_query = uri.query().unwrap_or("");
    let q = query(raw_query);
    if q.rest_route && (retire_all() || lists_users(raw_query)) {
        return gone();
    }
    let site = match wp.content.site().await {
        Ok(s) => s,
        Err(e) => return unavailable(e),
    };
    let answer_of = match route(&site, &path, &q, raw_query) {
        Answer::Legacy if replay => return legacy(),
        Answer::Legacy => resolve(&site, &path, q.lang),
        other => other,
    };
    let page = match answer_of {
        Answer::Render(p) => Some(p),
        Answer::Redirect(to) => return redirect(&wp.core.public_origin, &to),
        Answer::Found(to) => return found(&to),
        Answer::FeedRedirect(to, kind) => {
            let mut r = redirect(&wp.core.public_origin, &to);
            r.headers_mut().insert(header::CONTENT_TYPE, HeaderValue::from_static(kind));
            return r;
        }
        _ => None,
    };
    respond(wp, &site, page, uri, &q, agent, answer, replay).await
}

/// `page` as WordPress printed it at `uri`, or WordPress's 404 page.
#[allow(clippy::too_many_arguments)]
async fn respond(wp: &Arc<Wp>, site: &Site, page: Option<&Page>, uri: &Uri, q: &Query, agent: &str, answer: Option<render::Answer>, replay: bool) -> Response<Body> {
    let path = percent_decode_str(uri.path()).decode_utf8_lossy().into_owned();
    // Prod's hxweb served the export for its exact paths only; WordPress printed a page asked for in other case.
    let exact = page.is_some_and(|p| p.path == path || p.path.strip_suffix('/') == Some(path.as_str()));
    // Only pages were in the static export; WordPress printed every other post as it is.
    let finished = q.neutral && replay && exact && page.is_some_and(|p| p.kind == PostType::Page);
    let agent = if finished { "" } else { agent };
    let (status, html, islands, lang, wp_uri) = match page {
        Some(page) => {
            let asked = if exact { page.path.as_str() } else { uri.path() };
            let wp_uri = format!("{asked}{}", if finished { &q.form_uri_query } else { &q.wordpress_form_query });
            let Some(html) = theme::render(site, page, &wp_uri) else {
                if replay {
                    return legacy();
                }
                tracing::error!("{path} is claimed but its template is not ported");
                return (StatusCode::INTERNAL_SERVER_ERROR, "Internal error").into_response();
            };
            (StatusCode::OK, html, islands(site, page), page.lang, wp_uri)
        }
        // An export without WordPress's 404 page left nothing to render it from.
        None if !site.options(q.lang).contains_key(&format!("{}title", theme::NOT_FOUND_OPTION)) => {
            return if replay { legacy() } else { (StatusCode::NOT_FOUND, "Not found").into_response() };
        }
        None => {
            let request_uri = uri.path_and_query().map_or("/", |p| p.as_str()).to_string();
            let html = theme::not_found(site, q.lang, &request_uri);
            (StatusCode::NOT_FOUND, html, not_found_islands(site, q.lang), q.lang, request_uri)
        }
    };
    let answered = answer.as_ref().and_then(|a| islands.iter().find(|(_, i)| i.form == a.form).map(|(index, _)| *index));
    let filled = crate::cms::forms::fill(&html, |form, index| {
        let embed = islands.get(&index).filter(|e| e.form == form).cloned();
        let position = islands.values().position(|i| i.form == form).unwrap_or(index) + 1;
        let answer = answer.as_ref().filter(|_| answered == Some(index)).map(|a| a.html.clone());
        let wp = wp.clone();
        let wp_uri = wp_uri.clone();
        async move {
            if let Some(h) = answer {
                return Ok(h);
            }
            let e = embed.ok_or_else(|| format!("form {form} is not in the page's islands"))?;
            let embed = FormEmbed {
                form,
                lang,
                page_uri: &wp_uri,
                ajax: e.ajax,
                theme: &e.theme,
                styles: &e.styles,
                in_content: e.in_content,
                texturize: e.texturize,
                position,
                user_agent: agent,
            };
            match &wp.forms {
                Some(f) => f.markup(&embed).await,
                None => Err("no form source".to_string()),
            }
        }
    })
    .await
    .map(|html| match (&answer, answered) {
        (Some(a), Some(index)) => a.page(&html, index + 1),
        _ => html,
    });
    match filled {
        Ok(html) => {
            let mut r = html_response(public(&wp.core.public_origin, &html), finished);
            *r.status_mut() = status;
            r
        }
        Err(e) => {
            tracing::error!("{path}: {e}");
            (StatusCode::BAD_GATEWAY, "Bad gateway").into_response()
        }
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

/// The forms of a page in render order, as the import recorded them.
fn islands(site: &Site, page: &Page) -> BTreeMap<usize, Island> {
    parse_islands(site.meta(page.id).get("islands").map(String::as_str))
}

/// The forms of the 404 page (the footer's).
fn not_found_islands(site: &Site, lang: Lang) -> BTreeMap<usize, Island> {
    parse_islands(site.options(lang).get(&format!("{}islands", theme::NOT_FOUND_OPTION)).map(|f| f.value.as_str()))
}

fn parse_islands(raw: Option<&str>) -> BTreeMap<usize, Island> {
    serde_json::from_str::<Vec<Island>>(raw.unwrap_or("[]")).unwrap_or_default().into_iter().enumerate().collect()
}

/// Links in the page point at the public host (a staging copy rewrites them, as the static pages do).
fn public(origin: &str, html: &str) -> String {
    let host = origin.trim_start_matches("https://");
    if host == "www.harcourt.co" {
        return html.to_string();
    }
    html.replace("//www.harcourt.co", &format!("//{host}")).replace("\\/\\/www.harcourt.co", &format!("\\/\\/{host}"))
}

/// `finished` marks the page for main.rs's finishing (`x-hxweb: cms`), which the static pages got.
fn html_response(html: String, finished: bool) -> Response<Body> {
    let mut r = Response::new(Body::from(html));
    let h = r.headers_mut();
    h.insert(header::CONTENT_TYPE, HeaderValue::from_static("text/html; charset=UTF-8"));
    h.insert(header::CACHE_CONTROL, HeaderValue::from_static("no-cache"));
    h.insert(header::STRICT_TRANSPORT_SECURITY, HeaderValue::from_static("max-age=31536000; includeSubDomains"));
    h.insert("x-hxweb", HeaderValue::from_static(if finished { "cms" } else { "cms-raw" }));
    r
}

/// A 301 as WordPress sends it: an absolute Location on this site, an empty text/html body.
fn redirect(origin: &str, to: &str) -> Response<Body> {
    match HeaderValue::from_str(&format!("{origin}{to}")) {
        Ok(v) => (StatusCode::MOVED_PERMANENTLY, [(header::LOCATION, v), (header::CONTENT_TYPE, HeaderValue::from_static("text/html; charset=UTF-8"))]).into_response(),
        Err(_) => (StatusCode::NOT_FOUND, "Not found").into_response(),
    }
}

/// WPML's redirect: a 302 with the Location relative, as WordPress's wp_redirect() leaves it.
fn found(to: &str) -> Response<Body> {
    match HeaderValue::from_str(to) {
        Ok(v) => (StatusCode::FOUND, [(header::LOCATION, v), (header::CONTENT_TYPE, HeaderValue::from_static("text/html; charset=UTF-8"))]).into_response(),
        Err(_) => (StatusCode::NOT_FOUND, "Not found").into_response(),
    }
}

/// Hands the request back: main.rs sends a response marked `x-hxweb: legacy` on to the legacy stack.
fn legacy() -> Response<Body> {
    let mut r = Response::new(Body::empty());
    r.headers_mut().insert("x-hxweb", HeaderValue::from_static("legacy"));
    r
}

fn gone() -> Response<Body> {
    (StatusCode::GONE, [(header::CACHE_CONTROL, "no-store")], "Gone\n").into_response()
}

/// `rest_route=/wp/v2/users...`, the user listing prod's nginx answers 404 in both its forms. It never reaches
/// WordPress from here.
fn lists_users(raw_query: &str) -> bool {
    raw_query.split('&').filter_map(|kv| kv.strip_prefix("rest_route=")).any(|v| {
        let v = urldecode(v).to_ascii_lowercase();
        let parts: Vec<&str> = v.split('/').filter(|s| !s.is_empty()).collect();
        parts.len() >= 3 && parts[0] == "wp" && parts[1] == "v2" && parts[2].starts_with("users")
    })
}

async fn robots() -> impl IntoResponse {
    ([(header::CONTENT_TYPE, "text/plain")], ROBOTS)
}

fn retire_all() -> bool {
    static ALL: std::sync::OnceLock<bool> = std::sync::OnceLock::new();
    *ALL.get_or_init(|| std::env::var("HXWEB_RETIRE_WORDPRESS").is_ok_and(|v| v == "1"))
}

/// WordPress entry points nothing needs any more, answered 410 by retired.rs before anything reaches Apache.
/// `segments` is the decoded, dot-resolved path. xmlrpc.php, comment and trackback posting are retired outright;
/// sign-in, the admin screens and the REST API wait for HXWEB_RETIRE_WORDPRESS=1, because staff still use
/// wp-admin until page editing moves to the dashboard. admin-ajax.php stays open while Gravity Forms needs it.
pub fn retired(segments: &[&str]) -> bool {
    retired_with(segments, retire_all())
}

fn retired_with(segments: &[&str], all: bool) -> bool {
    let is = |s: &str, name: &str| s.eq_ignore_ascii_case(name);
    if segments.iter().any(|s| ["xmlrpc.php", "wp-comments-post.php", "wp-trackback.php"].iter().any(|n| is(s, n))) {
        return true;
    }
    if !all {
        return false;
    }
    if segments.iter().any(|s| is(s, "wp-login.php")) {
        return true;
    }
    match segments.first() {
        Some(s) if is(s, "wp-json") => true,
        Some(s) if is(s, "wp-admin") => !segments.get(1).is_some_and(|n| is(n, "admin-ajax.php")),
        _ => false,
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::cms::{model::Template, PageId};

    fn page(id: i64, group: i64, lang: Lang, path: &str, status: Status) -> Page {
        Page {
            id: PageId(id),
            kind: PostType::Page,
            name: path.trim_end_matches('/').rsplit('/').next().unwrap_or("").to_string(),
            group_id: group,
            lang,
            path: path.into(),
            template: Template::ProductSingle,
            status,
            title: String::new(),
            parent_id: None,
            menu_order: 0,
            published_at: String::new(),
            modified_at: String::new(),
            author_id: None,
        }
    }

    #[test]
    fn languages_come_from_wpml_lang_only() {
        assert_eq!(query("lang=fr&hxlang=fr").lang, Lang::Fr);
        assert_eq!(query("lang=fr").form_uri_query, "?lang=fr");
        assert_eq!(query("lang=en&utm_source=x").lang, Lang::En);
        assert_eq!(query("lang=en&utm_source=x").form_uri_query, "?utm_source=x");
        assert_eq!(query("lang=de").lang, Lang::En);
        assert!(query("rest_route=/wp/v2/users").rest_route);
        assert_eq!(query("").form_uri_query, "");
        assert!(query("lang=fr&hxlang=fr&utm_source=x&fbclid=y").neutral);
        assert!(!query("lang=fr&quot=").neutral);
        assert_eq!(query("hxlang=fr&theme=x").form_uri_query, "?hxlang=fr&theme=x");
        assert_eq!(query("lang=fr&lang=en").lang, Lang::En);
        assert!(query("lang=fr&lang=en").lang_en);
        assert!(query("%70=1").wordpress);
        assert!(lists_users("rest_route=%2Fwp%2Fv2%2Fusers&per_page=100"));
        assert!(lists_users("rest_route=//WP/v2/users/1"));
        assert!(!lists_users("rest_route=/wp/v2/pages"));
    }

    #[test]
    fn a_path_without_its_slash_goes_straight_to_the_page_url() {
        let site = Site::new(vec![page(1, 10, Lang::En, "/product/alignment/", Status::Publish), page(2, 10, Lang::Fr, "/gamme-de-produits/centrage/", Status::Publish)]);
        let r = |path: &str, q: &str| match route(&site, path, &query(q), q) {
            Answer::Render(p) => format!("render {}", p.id.0),
            Answer::Redirect(to) => to,
            Answer::Found(to) => format!("302 {to}"),
            Answer::NotFound => "404".into(),
            Answer::Legacy => "legacy".into(),
            Answer::FeedRedirect(to, _) => format!("feed {to}"),
        };
        assert_eq!(r("/product/alignment/", "lang=en"), "render 1");
        assert_eq!(r("/product/alignment/", "lang=en&x=1"), "302 /product/alignment/?x=1");
        assert_eq!(r("/gamme-de-produits/centrage/", "lang=en"), "302 /gamme-de-produits/centrage/");
        assert_eq!(r("/gamme-de-produits/centrage", "lang=en"), "302 /gamme-de-produits/centrage/");
        assert_eq!(r("/product/alignment/", "lang=en&lang=fr"), "/gamme-de-produits/centrage/?lang=fr");
        assert_eq!(r("/product/alignment/", "p=212"), "legacy");
        assert_eq!(r("/product/alignment", "s=x"), "legacy");
        assert_eq!(r("/product/alignment/", "theme=light"), "render 1");
        assert_eq!(r("/product/alignment/", "quot="), "render 1");
        let home = Site::new(vec![page(8, 70, Lang::En, "/", Status::Publish), page(9, 70, Lang::Fr, "/", Status::Publish)]);
        let h = |q: &str| match route(&home, "/", &query(q), q) {
            Answer::Render(p) => format!("render {}", p.id.0),
            Answer::Redirect(to) => to,
            _ => "other".into(),
        };
        assert_eq!(h("ref=x"), "render 8");
        assert_eq!(h("utm_source=a+b"), "render 8");
        assert_eq!(h("lang=fr"), "render 9");
        assert_eq!(h("phpinfo="), "/?phpinfo");
        assert_eq!(h("r=/x&r=/x"), "/?r=%2Fx");
        assert_eq!(r("/product/alignment/", ""), "render 1");
        assert_eq!(r("/product/alignment", "utm_source=a+b"), "/product/alignment/?utm_source=a+b");
        assert_eq!(r("/gamme-de-produits/centrage", ""), "/product/alignment/");
        assert_eq!(r("/gamme-de-produits/centrage", "lang=fr"), "/gamme-de-produits/centrage/?lang=fr");
        assert_eq!(r("/gamme-de-produits/centrage/", "lang=x"), "/product/alignment/?lang=x");
        assert_eq!(r("/nowhere", ""), "404");
    }

    #[test]
    fn a_path_resolves_to_its_page_or_to_its_translation() {
        let site = Site::new(vec![
            page(1, 10, Lang::En, "/product/alignment/", Status::Publish),
            page(2, 10, Lang::Fr, "/gamme-de-produits/centrage/", Status::Publish),
            page(3, 20, Lang::Fr, "/industries/", Status::Publish),
            page(4, 20, Lang::En, "/?page_id=4", Status::Draft),
            page(5, 30, Lang::En, "/legal/", Status::Publish),
            page(6, 40, Lang::Fr, "/seulement/", Status::Publish),
            page(7, 50, Lang::En, "/contact-form/", Status::Publish),
            page(8, 50, Lang::Fr, "/formulaire-de-contact/", Status::Publish),
            page(9, 60, Lang::En, "/contact/", Status::Publish),
            page(10, 60, Lang::Fr, "/nous-contacter/", Status::Publish),
        ]);
        assert_eq!(resolve(&site, "/contact/", Lang::Fr), Answer::Redirect("/formulaire-de-contact/?lang=fr".into()));
        assert_eq!(resolve(&site, "/nous-contacter/", Lang::En), Answer::Redirect("/contact/".into()));
        assert_eq!(resolve(&site, "/product/alignment/", Lang::En), Answer::Render(&site.pages[0]));
        assert_eq!(resolve(&site, "/gamme-de-produits/centrage/", Lang::Fr), Answer::Render(&site.pages[1]));
        assert_eq!(resolve(&site, "/gamme-de-produits/centrage/", Lang::En), Answer::Redirect("/product/alignment/".into()));
        assert_eq!(resolve(&site, "/product/alignment/", Lang::Fr), Answer::Redirect("/gamme-de-produits/centrage/?lang=fr".into()));
        assert_eq!(resolve(&site, "/industries/", Lang::En), Answer::Redirect("/?page_id=4".into()));
        assert_eq!(resolve(&site, "/seulement/", Lang::En), Answer::Redirect("/seulement/?lang=fr".into()));
        assert_eq!(resolve(&site, "/legal/", Lang::Fr), Answer::Redirect("/legal/?lang=fr".into()));
        assert_eq!(route(&site, "/legal/", &query("lang=fr"), "lang=fr"), Answer::Legacy);
        assert_eq!(route(&site, "/legal", &query("lang=fr"), "lang=fr"), Answer::Redirect("/legal/?lang=fr".into()));
        assert_eq!(resolve(&site, "/LEGAL/", Lang::En), Answer::Render(&site.pages[4]));
        assert_eq!(resolve(&site, "/seulement/x/", Lang::En), Answer::NotFound);
        assert_eq!(resolve(&site, "/x/contact/", Lang::En), Answer::Redirect("/contact/".into()));
        assert_eq!(resolve(&site, "/a/b/c/", Lang::En), Answer::Legacy);
        assert_eq!(resolve(&site, "/product/alignment/feed/", Lang::En), Answer::Legacy);
        assert_eq!(resolve(&site, "/nowhere/", Lang::En), Answer::NotFound);
        let carts = Site::new(vec![
            page(11, 70, Lang::En, "/shopping-cart/", Status::Publish),
            page(12, 70, Lang::Fr, "/panier-2/", Status::Publish),
            page(13, 80, Lang::En, "/cart/", Status::Publish),
            page(14, 80, Lang::Fr, "/panier/", Status::Publish),
        ]);
        assert_eq!(resolve(&carts, "/panier/", Lang::En), Answer::Redirect("/cart/".into()), "the exact slug, not the oldest page it starts");
        assert_eq!(resolve(&carts, "/pan/", Lang::En), Answer::Redirect("/cart/".into()), "a prefix guess lands on the first slug by name, as the oracle answers /pan/");
    }

    #[test]
    fn retires_wordpress_entry_points() {
        for p in [["xmlrpc.php"].as_slice(), &["en", "XMLRPC.PHP"], &["wp-comments-post.php"], &["wp-trackback.php"]] {
            assert!(retired_with(p, false), "{p:?}");
        }
        assert!(!retired_with(&["wp-login.php"], false));
        for p in [["wp-login.php"].as_slice(), &["fr", "wp-login.php"], &["wp-admin"], &["wp-admin", "options.php"], &["wp-json"], &["wp-json", "wp", "v2", "users"], &["WP-JSON", "oembed"]] {
            assert!(retired_with(p, true), "{p:?}");
        }
        for p in [["wp-admin", "admin-ajax.php"].as_slice(), &["wp-content", "uploads", "x.png"], &["product", "alignment"], &[]] {
            assert!(!retired_with(p, true), "{p:?}");
        }
    }

    #[test]
    fn staging_hosts_get_their_own_links() {
        assert_eq!(public("https://www.harcourt.co", "a //www.harcourt.co/x"), "a //www.harcourt.co/x");
        assert_eq!(public("https://stage2.harcourt.co", "a https://www.harcourt.co/x \"https:\\/\\/www.harcourt.co\\/y\""), "a https://stage2.harcourt.co/x \"https:\\/\\/stage2.harcourt.co\\/y\"");
    }
}
