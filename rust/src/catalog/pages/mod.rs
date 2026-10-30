//! catalog.php's views and the document viewer, rendered from SQLite. The request is parsed once into
//! `Request`, catalog.php's gate (who may browse, the profile, a landing document) picks what is shown, and each
//! view writes its part of the page.

mod assets;
mod chrome;
mod family;
mod home;
mod results;

use std::sync::Arc;

use axum::{
    extract::{Query, State},
    Extension,
    http::{header, HeaderMap, StatusCode},
    response::{IntoResponse, Response},
    routing::get,
    Router,
};
use sqlx::SqlitePool;

use super::{
    forms::hidden,
    html,
    model::{self, Catalog, Day, Part, PARAMETERS},
    profile::Profile,
    registry::{self, Registry},
    strings::{self, Strings},
    tabs::Tabs,
    viewer::{self, Lang, Viewer},
};
use crate::{api::Auth, db::unix_now, identity::Role, pricing::PriceBook, visitor::Visitor};

pub fn router() -> Router<Arc<Auth>> {
    Router::new()
        .route("/catalog.php", get(catalog_php))
        .route("/new_catalog.php", get(new_catalog))
        .route("/document_viewer.php", get(document_viewer))
}

/// The query of a catalog.php request, as its constructor reads it.
#[derive(Debug, Clone, Default)]
pub struct Request {
    pub action: String,
    pub page: i64,
    pub item: i64,
    pub document: i64,
    pub new_date: bool,
    pub search_source: String,
    pub portal_category: String,
    pub unit_type: String,
    pub unit_type_given: Option<String>,
    pub search_code: String,
    pub search_term: String,
    pub raw_search_code: String,
    pub parameter: Vec<String>,
    pub search_complete: String,
    pub sku: String,
    pub related: String,
    pub category_id: i64,
    pub subcategory_id: i64,
    pub search_id: i64,
    pub debug: bool,
    pub scscpq_wp: Option<String>,
    /// $_SERVER['REQUEST_URI']: where the profile gate returns to.
    pub uri: String,
    /// The visit came through the WordPress frame ($_SESSION['scscpq_wp']): links go to the catalog page, and the
    /// page has no menu ribbon of its own.
    pub framed: bool,
}

fn int(s: Option<&String>) -> i64 {
    s.map_or(0, |v| super::php::Value::Str(v.clone()).int())
}

impl Request {
    pub fn parse(q: &std::collections::HashMap<String, String>) -> Request {
        let get = |k: &str| q.get(k).cloned().unwrap_or_default();
        let raw_code = get("search_code");
        let (search_code, search_term) = match raw_code.split_once('|') {
            Some((c, t)) => (c.to_string(), t.to_string()),
            None => (raw_code.clone(), String::new()),
        };
        let sku_param = get("sku");
        let sku = match html::base64_fields_decode(&sku_param) {
            Some(fields) if fields.len() == 1 && html::base64_fields(&[fields[0].as_str()]) == sku_param => fields[0].clone(),
            _ => sku_param,
        };
        let unit = get("unit_type");
        let source = get("search_source");
        let ids_given = !matches!(source.as_str(), "search" | "lookup" | "sku" | "name") && get("action") != "related";
        let id = |k: &str| if ids_given { int(q.get(k)) } else { 0 };
        Request {
            action: get("action"),
            page: int(q.get("scs_page")),
            item: int(q.get("item")),
            document: int(q.get("document")),
            new_date: int(q.get("new_date")) != 0,
            search_source: source,
            portal_category: get("portal_category"),
            unit_type: if unit == "metric" || unit == "imperial" { unit.clone() } else { String::new() },
            unit_type_given: q.get("unit_type").cloned(),
            search_code,
            search_term,
            raw_search_code: raw_code,
            parameter: (0..PARAMETERS).map(|i| get(&format!("parameter{i}")).trim().to_string()).collect(),
            search_complete: get("search_complete"),
            sku,
            related: get("related"),
            category_id: id("category_id"),
            subcategory_id: id("subcategory_id"),
            search_id: int(q.get("search_id")),
            debug: q.get("debug").is_some_and(|d| !d.is_empty() && d != "0"),
            scscpq_wp: q.get("scscpq_wp").cloned(),
            uri: String::new(),
            framed: true,
        }
    }
}

/// Everything a view reads, loaded once per request.
pub struct Ctx<'a> {
    pub pool: &'a SqlitePool,
    pub viewer: &'a Viewer,
    pub req: Request,
    pub catalog: Catalog,
    pub registry: Registry,
    pub strings: Strings,
    pub book: PriceBook,
    pub profile: Profile,
    /// $menu->page->products->url: the catalog page links point at.
    pub php_self: &'static str,
    pub category_id: i64,
    pub subcategory_id: i64,
    /// The part the page is about (inventory_class's current row in the PHP).
    pub part: Option<Part>,
    pub related_sku: String,
    /// The search box's index, for a results page whose keyword search finds nothing.
    pub search: Option<Arc<super::search::Cache>>,
}

impl Ctx<'_> {
    pub fn family(&self) -> Option<&model::Family> {
        self.catalog.family(self.subcategory_id)
    }

    pub fn lang_param(&self) -> &'static str {
        self.viewer.lang.param()
    }

    pub fn blocked(&self, category_id: i64) -> bool {
        self.viewer.blocked.contains(category_id)
    }

    /// The overlay a blocked category shows instead of its content.
    pub fn overlay(&self, category_id: i64) -> String {
        format!(
            "<div class='catalog_category_overlay'>\n<div class='catalog_category_overlay_message'>{} {} for more information.</div>\n</div>",
            html::esc_text(&self.viewer.blocked.agreement_message(category_id).unwrap_or_default()),
            html::href("Contact Us", "/contact")
        )
    }

    pub fn buyer(&self) -> crate::pricing::Buyer<'_> {
        crate::pricing::Buyer { terms: self.viewer.price, may_buy: self.viewer.active, currency_code: &self.viewer.currency_code }
    }
}

pub async fn load_ctx<'a>(pool: &'a SqlitePool, viewer: &'a Viewer, req: Request, cookies: &HeaderMap) -> sqlx::Result<Ctx<'a>> {
    let registry = registry::load(pool).await?;
    let catalog = model::load(pool, viewer.lang, &registry, Day::from_unix(unix_now())).await?;
    let strings = strings::load(pool, viewer.lang).await?;
    let book = PriceBook::load(pool, registry.get("local", "configure_contact")).await?;
    let profile = super::profile::load(pool, viewer, cookies).await?;
    let php_self = match (req.framed, viewer.lang) {
        (false, _) => "/catalog.php",
        (true, Lang::Fr) => "/catalogue/",
        (true, Lang::En) => "/catalog/",
    };
    let (category_id, subcategory_id) = (req.category_id, req.subcategory_id);
    Ok(Ctx { pool, viewer, req, catalog, registry, strings, book, profile, php_self, category_id, subcategory_id, part: None, related_sku: String::new(), search: None })
}

/// What the gate and route() decided to show.
enum View {
    Document(i64),
    Profile,
    Family,
    Lookup(Option<results::Hit>),
    Search(Option<results::Hit>),
    Home,
}

async fn decide(ctx: &mut Ctx<'_>) -> sqlx::Result<View> {
    let req = ctx.req.clone();
    let found = results::resolve(ctx).await?;
    if let Some(hit) = &found {
        ctx.category_id = hit.category_id;
        ctx.subcategory_id = hit.subcategory_id;
    }
    match req.search_source.as_str() {
        "sku" if req.action == "search" => {
            ctx.part = model::part_by_sku(ctx.pool, &req.search_code).await?;
            if let Some(p) = &ctx.part {
                ctx.subcategory_id = p.subcategory_id;
                ctx.req.action = "sku".into();
                ctx.req.sku = p.sku.clone();
            }
        }
        "name" => ctx.subcategory_id = int(Some(&req.search_code)),
        "search" | "lookup" => {}
        _ if req.action == "related" => {
            ctx.req.action = "sku".into();
            match model::part_by_sku(ctx.pool, &req.sku).await? {
                Some(p) => ctx.subcategory_id = p.subcategory_id,
                None => {
                    ctx.related_sku = req.sku.clone();
                    let original = html::base64_fields_decode(&req.related).map(|f| f.join("\t")).unwrap_or_default();
                    ctx.req.sku = original.clone();
                    ctx.subcategory_id = model::part_by_sku(ctx.pool, &original).await?.map_or(0, |p| p.subcategory_id);
                }
            }
        }
        _ => {}
    }
    if let Some(f) = ctx.family() {
        ctx.category_id = f.category_id;
    }
    if !ctx.viewer.may_browse() {
        return Ok(View::Document(157));
    }
    if !ctx.profile.forced(&ctx.registry) {
        return Ok(View::Profile);
    }
    if req.document == 0 {
        if let Some(landing) = ctx.viewer.landing_document_id {
            return Ok(View::Document(landing));
        }
    }
    match req.search_source.as_str() {
        "lookup" => return Ok(View::Lookup(found)),
        "search" => return Ok(View::Search(found)),
        _ => {}
    }
    let family_view = req.search_source == "name" || (matches!(ctx.req.action.as_str(), "subcategory" | "sku") && ctx.family().is_some());
    Ok(if family_view && ctx.family().is_some() { View::Family } else { View::Home })
}

/// The catalog fragment: catalog.php's whole output for this request.
pub async fn render(ctx: &mut Ctx<'_>) -> sqlx::Result<String> {
    let view = decide(ctx).await?;
    let title = "Products";
    let mut body = String::new();
    body.push_str(assets::JS);
    body.push_str(assets::CSS);
    body.push_str("<form name='scs_form' id='scs_form' method=post action='/catalog.php' accept-charset='utf-8' autocomplete=on>\n");
    for (field, value) in [
        ("action", ctx.req.action.clone()),
        ("category_id", ctx.category_id.to_string()),
        ("subcategory_id", ctx.subcategory_id.to_string()),
        ("document", ctx.req.document.to_string()),
        ("scs_page", ctx.req.page.to_string()),
        ("item", ctx.req.item.to_string()),
        ("sku", String::new()),
        ("search_complete", String::new()),
        ("cad_name", ctx.profile.cad_name.clone()),
    ] {
        body.push_str(&hidden(field, &value));
    }
    match view {
        View::Document(id) => body.push_str(&document_html(ctx.pool, id, ctx.viewer.lang).await?.unwrap_or_default()),
        View::Profile => body.push_str(&super::profile::enter(ctx, Some(&ctx.req.uri)).await?),
        View::Family => family::page(ctx).await?.write_to(&mut body),
        View::Lookup(found) => body.push_str(&family::lookup(ctx, found).await?),
        View::Search(found) => body.push_str(&results::search(ctx, found).await?),
        View::Home => body.push_str(&home::page(ctx).await?),
    }
    body.push_str("</form>");
    let ribbon = (!ctx.req.framed).then(|| chrome::ribbon(ctx.viewer.at_least(Role::Executive).then(|| ctx.strings.get("menu_dashboard"))));
    let css = [catalog_css(&ctx.registry)];
    Ok(chrome::document(&ctx.registry, &chrome::Head { css: &css, ..chrome::Head::titled(title) }, &body, ribbon.as_deref()))
}

fn catalog_css(reg: &Registry) -> String {
    format!("/scs_scripts/catalog.css?v={}", reg.get("local", "filmstrip_timestamp"))
}

/// The HTML of a document (`content` rows of record_type document) in the viewer's language, else English.
pub async fn document_html(pool: &SqlitePool, id: i64, lang: Lang) -> sqlx::Result<Option<String>> {
    let code = lang.code();
    let row = sqlx::query!(
        r#"SELECT meta, html_content, content FROM content
           WHERE record_type = 'document' AND record_id = ?1 AND language_code IN ('EN', ?2)
           ORDER BY CASE WHEN language_code = 'EN' THEN 2 ELSE 1 END, id LIMIT 1"#,
        id,
        code
    )
    .fetch_optional(pool)
    .await?;
    Ok(row.and_then(|r| {
        let mime = r.meta.as_deref().and_then(|m| serde_json::from_str::<serde_json::Value>(m).ok()).and_then(|m| m.get("mime").and_then(|v| v.as_str()).map(str::to_string));
        (mime.as_deref() == Some("text/html")).then(|| r.html_content.unwrap_or_else(|| r.content.map(|b| String::from_utf8_lossy(&b).into_owned()).unwrap_or_default()))
    }))
}

/// A view's output: what catalog.php printed before the tabs, and the tabs.
pub struct Page {
    pub head: String,
    pub tabs: Tabs,
    pub tail: String,
}

impl Page {
    pub(super) fn write_to(self, out: &mut String) {
        out.push_str(&self.head);
        out.push_str(&self.tail);
        out.push_str(&self.tabs.render());
    }
}

/// A document navigation to the fragment address (a copied link, a control that navigates) goes to the catalog page
/// with the same query; fetches get the fragment.
fn fragment_as_page(query: &str, headers: &HeaderMap) -> Option<Response> {
    let navigation = match headers.get("sec-fetch-dest").and_then(|v| v.to_str().ok()) {
        Some(dest) => dest == "document",
        None => headers.contains_key("upgrade-insecure-requests") && !headers.contains_key("x-requested-with"),
    };
    if !navigation || !query.split('&').any(|kv| kv.starts_with("scscpq_wp=")) {
        return None;
    }
    let rest: Vec<&str> = query.split('&').filter(|kv| !kv.starts_with("scscpq_wp=") && !kv.is_empty()).collect();
    let french = rest.iter().any(|kv| kv.strip_prefix("lang=").is_some_and(|l| l.eq_ignore_ascii_case("fr")));
    let location = if french {
        let q = if rest.iter().any(|kv| kv.starts_with("lang=")) { rest.join("&") } else { std::iter::once("lang=fr").chain(rest.iter().copied()).collect::<Vec<_>>().join("&") };
        format!("/catalogue/?{q}")
    } else {
        let q: Vec<&str> = rest.into_iter().filter(|kv| *kv != "lang=en").collect();
        if q.is_empty() { "/catalog/".to_string() } else { format!("/catalog/?{}", q.join("&")) }
    };
    Some((StatusCode::FOUND, [(header::LOCATION, location), (header::CACHE_CONTROL, "no-store".to_string())]).into_response())
}

fn html_response(body: String) -> Response {
    (
        StatusCode::OK,
        [(header::CONTENT_TYPE, "text/html; charset=UTF-8"), (header::CACHE_CONTROL, "no-store, no-cache, must-revalidate")],
        body,
    )
        .into_response()
}

fn failed(e: sqlx::Error) -> Response {
    tracing::error!("catalog page: {e}");
    (StatusCode::INTERNAL_SERVER_ERROR, [(header::CACHE_CONTROL, "no-store")], "Internal error").into_response()
}

async fn catalog_php(
    State(core): State<Arc<Auth>>,
    Extension(cache): Extension<Arc<super::search::Cache>>,
    visitor: Visitor,
    headers: HeaderMap,
    uri: axum::http::Uri,
    Query(q): Query<std::collections::HashMap<String, String>>,
) -> Response {
    if let Some(redirect) = fragment_as_page(uri.query().unwrap_or(""), &headers) {
        return redirect;
    }
    if headers.contains_key("x-hx-view") {
        return StatusCode::NO_CONTENT.into_response();
    }
    if let Some(r) = landing(&core, &visitor, &uri, &headers).await {
        return r;
    }
    catalog_view(&core, cache, &visitor, &headers, &uri, &q).await
}

/// scs_header.php's first-visit redirect for an SCS page, or None to answer the request.
async fn landing(core: &Auth, visitor: &Visitor, uri: &axum::http::Uri, headers: &HeaderMap) -> Option<Response> {
    match crate::visitor::landing(core, visitor, uri.path(), uri.query(), headers).await {
        Ok(r) => r,
        Err(e) => Some(failed(e)),
    }
}

/// Whether the PHP session was inside the WordPress frame: the query says so, or the request is inside a visit,
/// and the site's visits come through the catalog page.
fn framed(core: &Auth, q: &Params, headers: &HeaderMap) -> bool {
    q.get("scscpq_wp").is_some_and(|v| !v.is_empty() && v != "0") || !crate::visitor::begins_visit(headers, &core.public_origin)
}

type Params = std::collections::HashMap<String, String>;

async fn catalog_view(core: &Auth, cache: Arc<super::search::Cache>, visitor: &Visitor, headers: &HeaderMap, uri: &axum::http::Uri, q: &Params) -> Response {
    let result = async {
        let viewer = viewer::load(&core.pool, visitor, viewer::lang_for(visitor)).await?;
        let uri = uri.path_and_query().map_or_else(|| uri.path().to_string(), |pq| pq.as_str().to_string());
        let req = Request { uri, framed: framed(core, q, headers), ..Request::parse(q) };
        let mut ctx = load_ctx(&core.pool, &viewer, req, headers).await?;
        ctx.search = Some(cache);
        render(&mut ctx).await
    }
    .await;
    match result {
        Ok(body) => html_response(body),
        Err(e) => failed(e),
    }
}

/// new_catalog.php, the 2020 catalog, kept for old links: its own gate (the access document for visitors who
/// are not signed in, the sign-in document for a browser that never gave its address), then the catalog.
async fn new_catalog(
    State(core): State<Arc<Auth>>,
    Extension(cache): Extension<Arc<super::search::Cache>>,
    visitor: Visitor,
    headers: HeaderMap,
    uri: axum::http::Uri,
    Query(q): Query<Params>,
) -> Response {
    if let Some(r) = landing(&core, &visitor, &uri, &headers).await {
        return r;
    }
    let gate = async {
        let viewer = viewer::load(&core.pool, &visitor, viewer::lang_for(&visitor)).await?;
        let remembered = crate::accounts::cookies::get(&headers, crate::accounts::cookies::EMAIL).is_some_and(|e| !e.is_empty());
        Ok::<_, sqlx::Error>(match viewer.kind {
            viewer::Kind::Anonymous => {
                let reg = registry::load(&core.pool).await?;
                let ribbon = (!framed(&core, &q, &headers)).then(|| chrome::ribbon(None));
                let doc = document_html(&core.pool, 157, viewer.lang).await?.unwrap_or_default();
                Some(format!("{}{doc}", chrome::open(&reg, &chrome::Head::titled("Products"), ribbon.as_deref())))
            }
            viewer::Kind::Account(_) if !remembered => Some(document_html(&core.pool, viewer.login_document_id.unwrap_or(156), viewer.lang).await?.unwrap_or_default()),
            _ => None,
        })
    }
    .await;
    match gate {
        Ok(Some(body)) => html_response(body),
        Ok(None) => catalog_view(&core, cache, &visitor, &headers, &uri, &q).await,
        Err(e) => failed(e),
    }
}

/// document_viewer.php: `document` is fn_base64(id, timestamp); without it nothing is shown. With a login_url it
/// is a page linking there, which is followed only to an address on this site.
async fn document_viewer(State(core): State<Arc<Auth>>, visitor: Visitor, headers: HeaderMap, uri: axum::http::Uri, Query(q): Query<Params>) -> Response {
    if let Some(r) = landing(&core, &visitor, &uri, &headers).await {
        return r;
    }
    let id = q
        .get("document")
        .and_then(|d| html::base64_fields_decode(d))
        .and_then(|f| f.first().map(|s| super::php::Value::Str(s.clone()).int()))
        .unwrap_or(0);
    let login_url = q.get("login_url").filter(|u| u.starts_with('/') && !u.starts_with("//") && !u.starts_with("/\\"));
    let result = async {
        if let Some(url) = login_url {
            let reg = registry::load(&core.pool).await?;
            let link = format!("<p class='center large'><a href='{}' target='_top'>Click here to continue</a></p>\n", html::esc_attr(url));
            return Ok(format!("{}{link}{}", chrome::html_open(&reg, &chrome::Head::titled(""), false), chrome::HTML_CLOSE));
        }
        Ok(document_html(&core.pool, id, viewer::lang_for(&visitor)).await?.unwrap_or_default())
    }
    .await;
    match result {
        Ok(body) => html_response(body),
        Err(e) => failed(e),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn q(pairs: &[(&str, &str)]) -> std::collections::HashMap<String, String> {
        pairs.iter().map(|(k, v)| (k.to_string(), v.to_string())).collect()
    }

    #[test]
    fn requests_parse_as_the_constructor_reads_them() {
        let r = Request::parse(&q(&[("action", "sku"), ("sku", "SEJMVC0z"), ("scs_page", "2"), ("parameter3", " 1/4 "), ("search_code", "abc|def"), ("unit_type", "x")]));
        assert_eq!(r.sku, "HBLT-3");
        assert_eq!(r.page, 2);
        assert_eq!(r.parameter[3], "1/4");
        assert_eq!((r.search_code.as_str(), r.search_term.as_str()), ("abc", "def"));
        assert_eq!(r.unit_type, "");
        assert_eq!(Request::parse(&q(&[("sku", "HBLT 3")])).sku, "HBLT 3");
        assert_eq!(Request::parse(&q(&[("subcategory_id", "664")])).subcategory_id, 664);
        assert_eq!(Request::parse(&q(&[("subcategory_id", "664"), ("search_source", "search")])).subcategory_id, 0, "a search reads its ids from the search table");
    }

    #[test]
    fn fragment_navigations_go_to_the_catalog_page() {
        let mut nav = HeaderMap::new();
        nav.insert("sec-fetch-dest", "document".parse().unwrap());
        let loc = |query: &str, h: &HeaderMap| fragment_as_page(query, h).map(|r| r.headers()[header::LOCATION].to_str().unwrap().to_string());
        assert_eq!(loc("scscpq_wp=1", &nav).as_deref(), Some("/catalog/"));
        assert_eq!(loc("scscpq_wp=1&action=subcategory&subcategory_id=731", &nav).as_deref(), Some("/catalog/?action=subcategory&subcategory_id=731"));
        assert_eq!(loc("lang=fr&action=sku&scscpq_wp=1", &nav).as_deref(), Some("/catalogue/?lang=fr&action=sku"));
        let mut fetch = HeaderMap::new();
        fetch.insert("sec-fetch-dest", "empty".parse().unwrap());
        assert!(loc("scscpq_wp=1&unit_type=metric", &fetch).is_none());
        assert!(loc("action=subcategory", &nav).is_none());
    }
}
