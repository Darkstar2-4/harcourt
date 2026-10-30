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
    extract::{FromRequestParts, Request, State},
    http::{header, HeaderValue, Response, StatusCode, Uri},
    response::IntoResponse,
    routing::get,
    Router,
};
use percent_encoding::percent_decode_str;

use crate::{
    api::{Auth, ClientIp},
    cms::{
        forms::{FormSource, Islands, OrdersForms},
        theme, Content, FormEmbed, Lang, Page, Site, Status,
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

pub fn router(core: Arc<Auth>) -> Router {
    let orders = Arc::new(Orders::from_env(core.clone()));
    let forms = OrdersForms { orders: orders.clone(), fallback: Islands::from_env() };
    router_with(core, Some(Arc::new(forms)), orders, Islands::from_env())
}

/// The router with the given source of form markup (tests pass a fixed one).
pub fn router_with_forms(core: Arc<Auth>, forms: Option<Arc<dyn FormSource>>) -> Router {
    let orders = Arc::new(Orders::from_env(core.clone()));
    router_with(core, forms, orders, None)
}

fn router_with(core: Arc<Auth>, forms: Option<Arc<dyn FormSource>>, orders: Arc<Orders>, legacy: Option<Islands>) -> Router {
    let wp = Arc::new(Wp { content: Content::new(core.pool.clone()), forms, orders, legacy, core: core.clone() });
    let mut r = Router::new().route("/robots.txt", get(robots));
    for path in PAGES {
        r = r.route(path, get(page).post(page_post));
        if let Some(bare) = path.strip_suffix('/').filter(|p| !p.is_empty()) {
            r = r.route(bare, get(page).post(page_post));
        }
    }
    r.with_state(wp).merge(crate::cms::admin::routes().with_state(core))
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
    Query { lang, lang_en: last_lang == Some("lang=en"), rest_route, neutral, wordpress, without_lang, form_uri_query }
}

/// PHP's urldecode().
fn urldecode(s: &str) -> String {
    percent_decode_str(&s.replace('+', " ")).decode_utf8_lossy().into_owned()
}

/// PHP's rawurlencode().
fn rawurlencode(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for b in s.bytes() {
        match b {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' => out.push(b as char),
            _ => out.push_str(&format!("%{b:02X}")),
        }
    }
    out
}

/// A query value after parse_str(): `a=1`, or `a[]=1&a[x]=2` as a list of (key, value).
enum Arg {
    One(String),
    List(Vec<(String, String)>),
}

/// What WPML's redirect to a translation does with the request's query: parse_str() it, rawurlencode every key
/// and value, and add_query_arg() them to `target`. A key keeps its first position and its last value, `a[]`
/// keys collect into `a%5B0%5D`, and an empty value loses its `=`.
fn with_query(target: &str, query: &str) -> String {
    let (base, existing) = target.split_once('?').unwrap_or((target, ""));
    let mut args: Vec<(String, Arg)> = Vec::new();
    for kv in existing.split('&').filter(|kv| !kv.is_empty()) {
        let (k, v) = kv.split_once('=').unwrap_or((kv, ""));
        args.push((k.to_string(), Arg::One(v.to_string())));
    }
    for kv in query.split('&').filter(|kv| !kv.is_empty()) {
        let (k, v) = kv.split_once('=').unwrap_or((kv, ""));
        let k = urldecode(k);
        let k = k.trim_start();
        let (name, sub) = match k.split_once('[').filter(|(n, rest)| !n.is_empty() && rest.contains(']')) {
            Some((n, rest)) => (n.replace(['.', ' '], "_"), Some(rest[..rest.find(']').unwrap_or(0)].to_string())),
            None => (k.replace(['.', ' '], "_"), None),
        };
        if name.is_empty() {
            continue;
        }
        let (name, v) = (rawurlencode(&name), rawurlencode(&urldecode(v)));
        let slot = args.iter().position(|(key, _)| *key == name);
        match (sub, slot) {
            (None, Some(i)) => args[i].1 = Arg::One(v),
            (None, None) => args.push((name, Arg::One(v))),
            (Some(sub), slot) => {
                let i = slot.unwrap_or_else(|| {
                    args.push((name, Arg::List(Vec::new())));
                    args.len() - 1
                });
                if let Arg::One(_) = args[i].1 {
                    args[i].1 = Arg::List(Vec::new());
                }
                if let Arg::List(list) = &mut args[i].1 {
                    let sub = if sub.is_empty() { list.len().to_string() } else { sub };
                    match list.iter_mut().find(|(s, _)| *s == sub) {
                        Some(e) => e.1 = v,
                        None => list.push((sub, v)),
                    }
                }
            }
        }
    }
    let pair = |k: &str, v: &str| if v.is_empty() { k.to_string() } else { format!("{k}={v}") };
    let q: Vec<String> = args
        .iter()
        .flat_map(|(k, a)| match a {
            Arg::One(v) => vec![pair(k, v)],
            Arg::List(list) => list.iter().map(|(s, v)| pair(&format!("{k}%5B{s}%5D"), v)).collect(),
        })
        .collect();
    if q.is_empty() {
        base.to_string()
    } else {
        format!("{base}?{}", q.join("&"))
    }
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
    /// A query WordPress acts on: the legacy stack answers.
    Legacy,
}

fn resolve<'a>(site: &'a Site, path: &str, lang: Lang) -> Answer<'a> {
    let published = |l: Lang| site.find(l, path).filter(|p| p.status == Status::Publish);
    if let Some(p) = published(lang) {
        return Answer::Render(p);
    }
    let other = if lang == Lang::En { Lang::Fr } else { Lang::En };
    let name = path.trim_matches('/');
    let one_segment = !name.is_empty() && !name.contains('/');
    // An English request finds a one-segment page by its path in either language; WPML limits a French one to
    // French pages, so it goes to guess() however close a page is.
    let Some(p) = published(other).filter(|_| lang == Lang::En || !one_segment) else {
        return if one_segment { guess(site, name, lang) } else { Answer::NotFound };
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

/// A one-segment path with no page in the request's language: WordPress takes it for a post name, finds none, and
/// redirect_guess_404_permalink() sends the visitor to the first published post (in the order the database reads
/// them, by date) whose slug starts with it, which WPML links in the request's language. So /contact/?lang=fr goes
/// to the French contact form, not to the translation of /contact/. WPML links a page with no French translation at
/// its own path with `?lang=fr`; when that is the URL asked for, route() leaves the 404 alone.
fn guess<'a>(site: &'a Site, name: &str, lang: Lang) -> Answer<'a> {
    let slug = |p: &Page| p.path.trim_end_matches('/').rsplit('/').next().unwrap_or("").to_string();
    let Some(g) = site.pages.iter().filter(|p| p.status == Status::Publish && slug(p).starts_with(name)).min_by_key(|p| (p.published_at.as_str(), p.id))
    else {
        return Answer::NotFound;
    };
    match (lang, site.translation(g, lang)) {
        (Lang::En, t) => {
            let t = t.unwrap_or(g);
            Answer::Redirect(format!("{}{}", t.path, t.lang.query()))
        }
        (Lang::Fr, Some(t)) if t.status == Status::Publish => Answer::Redirect(format!("{}{}", t.path, t.lang.query())),
        (Lang::Fr, _) => Answer::Redirect(format!("{}{}", g.path, Lang::Fr.query())),
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
        return Answer::Legacy;
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
        (Answer::Redirect(to), _) => {
            let to = with_query(&to, raw_query);
            let asked = if raw_query.is_empty() { path.to_string() } else { format!("{path}?{raw_query}") };
            if to == asked {
                Answer::NotFound
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
    let seen = if path.ends_with('/') { &page.path } else { &path };
    let wp_uri = format!("{seen}{}", q.form_uri_query);
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
    let finished = q.neutral && replay;
    let agent = if finished { "" } else { agent };
    let target = if replay { path.clone() } else { slashed(&path) };
    let page = match route(&site, &target, &q, raw_query) {
        Answer::Render(p) => p,
        Answer::Redirect(to) => return redirect(&wp.core.public_origin, &to),
        Answer::Found(to) => return found(&to),
        // WordPress prints its themed 404 page until that page is ported.
        Answer::NotFound | Answer::Legacy if replay => return legacy(),
        Answer::NotFound | Answer::Legacy => match resolve(&site, &target, q.lang) {
            Answer::Render(p) => p,
            _ => return (StatusCode::NOT_FOUND, "Not found").into_response(),
        },
    };
    let seen = if path.ends_with('/') { &page.path } else { &path };
    let wp_uri = format!("{seen}{}", q.form_uri_query);
    let Some(html) = theme::render(&site, page, &wp_uri) else {
        tracing::error!("{path} is claimed but its template is not ported");
        return (StatusCode::INTERNAL_SERVER_ERROR, "Internal error").into_response();
    };
    let islands = islands(&site, page);
    let answered = answer.as_ref().and_then(|a| islands.iter().find(|(_, i)| i.form == a.form).map(|(index, _)| *index));
    let filled = crate::cms::forms::fill(&html, |form, index| {
        let embed = islands.get(&index).filter(|e| e.form == form).cloned();
        let answer = answer.as_ref().filter(|_| answered == Some(index)).map(|a| a.html.clone());
        let wp = wp.clone();
        let wp_uri = wp_uri.clone();
        let lang = page.lang;
        async move {
            if let Some(h) = answer {
                return Ok(h);
            }
            let e = embed.ok_or_else(|| format!("form {form} is not in the page's islands"))?;
            let embed = FormEmbed { form, lang, page_uri: &wp_uri, ajax: e.ajax, theme: &e.theme, styles: &e.styles, in_content: e.in_content, user_agent: agent };
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
        Ok(html) => html_response(public(&wp.core.public_origin, &html), finished),
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
}

/// The forms of a page in render order, as the import recorded them.
fn islands(site: &Site, page: &Page) -> BTreeMap<usize, Island> {
    let raw = site.meta(page.id).get("islands").map(String::as_str).unwrap_or("[]");
    serde_json::from_str::<Vec<Island>>(raw).unwrap_or_default().into_iter().enumerate().collect()
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
    fn redirects_keep_the_query_as_redirect_canonical_rebuilds_it() {
        assert_eq!(with_query("/product/", "lang=%27+OR+%271%27%3D%271"), "/product/?lang=%27%20OR%20%271%27%3D%271");
        assert_eq!(with_query("/product/", ""), "/product/");
        assert_eq!(with_query("/a/?lang=fr", "lang=fr&utm_source=x"), "/a/?lang=fr&utm_source=x");
        assert_eq!(with_query("/?page_id=4", "quot=&a.b=1&a.b=2"), "/?page_id=4&quot&a_b=2");
        assert_eq!(with_query("/r/", "x[]=1&x[]=2&y[k]=a+b"), "/r/?x%5B0%5D=1&x%5B1%5D=2&y%5Bk%5D=a%20b");
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
        assert_eq!(resolve(&site, "/legal/", Lang::Fr), Answer::Redirect("/legal/?lang=fr".into()), "an untranslated page, linked in French");
        assert_eq!(route(&site, "/legal/", &query("lang=fr"), "lang=fr"), Answer::NotFound, "already there: the 404 stays");
        assert_eq!(route(&site, "/legal", &query("lang=fr"), "lang=fr"), Answer::Redirect("/legal/?lang=fr".into()));
        assert_eq!(route(&site, "/leg/", &query("lang=fr&x=1"), "lang=fr&x=1"), Answer::Redirect("/legal/?lang=fr&x=1".into()));
        assert_eq!(resolve(&site, "/nowhere/", Lang::En), Answer::NotFound);
        let carts = Site::new(vec![
            page(11, 70, Lang::En, "/shopping-cart/", Status::Publish),
            page(12, 70, Lang::Fr, "/panier-2/", Status::Publish),
            page(13, 80, Lang::En, "/cart/", Status::Publish),
            page(14, 80, Lang::Fr, "/panier/", Status::Publish),
        ]);
        assert_eq!(resolve(&carts, "/panier/", Lang::En), Answer::Redirect("/cart/".into()), "the exact slug, not the oldest page it starts");
        assert_eq!(resolve(&carts, "/pan/", Lang::En), Answer::Redirect("/shopping-cart/".into()));
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
