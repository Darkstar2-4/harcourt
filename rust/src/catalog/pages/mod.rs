//! catalog.php's views and the document viewer, rendered from SQLite. The request is parsed once into
//! `Request`, catalog.php's gate (who may browse, the profile, a landing document) picks what is shown, and each
//! view writes its part of the page.

mod assets;
mod chrome;
mod family;

use std::sync::Arc;

use axum::{
    extract::{Query, State},
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
use crate::{api::Auth, db::unix_now, pricing::PriceBook, visitor::Visitor};

pub fn router() -> Router<Arc<Auth>> {
    Router::new().route("/catalog.php", get(catalog_php)).route("/document_viewer.php", get(document_viewer))
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
        Request {
            action: get("action"),
            page: int(q.get("scs_page")),
            item: int(q.get("item")),
            document: int(q.get("document")),
            new_date: int(q.get("new_date")) != 0,
            search_source: get("search_source"),
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
            category_id: int(q.get("category_id")),
            subcategory_id: int(q.get("subcategory_id")),
            search_id: int(q.get("search_id")),
            debug: q.get("debug").is_some_and(|d| !d.is_empty() && d != "0"),
            scscpq_wp: q.get("scscpq_wp").cloned(),
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
            html::esc(&self.viewer.blocked.agreement_message(category_id).unwrap_or_default()),
            html::href("Contact Us", "/contact")
        )
    }

    pub fn buyer(&self) -> crate::pricing::Buyer<'_> {
        crate::pricing::Buyer { terms: self.viewer.price, may_buy: self.viewer.active, currency_code: &self.viewer.currency_code }
    }
}

pub async fn load_ctx<'a>(pool: &'a SqlitePool, viewer: &'a Viewer, req: Request, cookies: &HeaderMap, email: Option<&str>) -> sqlx::Result<Ctx<'a>> {
    let registry = registry::load(pool).await?;
    let catalog = model::load(pool, viewer.lang, &registry, Day::from_unix(unix_now())).await?;
    let strings = strings::load(pool, viewer.lang).await?;
    let book = PriceBook::load(pool, registry.get("local", "configure_contact")).await?;
    let profile = super::profile::load(pool, viewer, cookies, email).await?;
    let php_self = if viewer.lang == Lang::Fr { "/catalogue/" } else { "/catalog/" };
    let (category_id, subcategory_id) = (req.category_id, req.subcategory_id);
    Ok(Ctx { pool, viewer, req, catalog, registry, strings, book, profile, php_self, category_id, subcategory_id, part: None, related_sku: String::new() })
}

/// What the gate and route() decided to show.
enum View {
    Document(i64),
    Profile,
    Family,
    Lookup(String),
    Home,
}

async fn decide(ctx: &mut Ctx<'_>) -> sqlx::Result<View> {
    let req = ctx.req.clone();
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
    if req.search_source == "lookup" {
        return Ok(View::Lookup(req.raw_search_code.clone()));
    }
    let family_view = req.search_source == "name" || (matches!(ctx.req.action.as_str(), "subcategory" | "sku") && ctx.family().is_some());
    Ok(if family_view && ctx.family().is_some() { View::Family } else { View::Home })
}

/// The catalog fragment: catalog.php's whole output for this request.
pub async fn render(ctx: &mut Ctx<'_>) -> sqlx::Result<String> {
    let view = decide(ctx).await?;
    let title = match &view {
        View::Document(_) => String::new(),
        _ => "Products".to_string(),
    };
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
        View::Profile => body.push_str(&super::profile::enter(ctx, true).await?),
        View::Family => family::page(ctx).await?.write_to(&mut body),
        View::Lookup(code) => body.push_str(&family::lookup(ctx, &code).await?),
        View::Home => {}
    }
    body.push_str("</form>");
    Ok(chrome::document(&ctx.registry, &title, &[catalog_css(&ctx.registry)], &body))
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
    fn write_to(self, out: &mut String) {
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
    visitor: Visitor,
    headers: HeaderMap,
    uri: axum::http::Uri,
    Query(q): Query<std::collections::HashMap<String, String>>,
) -> Response {
    if let Some(redirect) = fragment_as_page(uri.query().unwrap_or(""), &headers) {
        return redirect;
    }
    let lang = Lang::from_param(q.get("lang").map(String::as_str));
    let result = async {
        let viewer = viewer::load(&core.pool, &visitor, lang).await?;
        let email = match &visitor {
            Visitor::SignedIn { email, .. } => email.clone(),
            Visitor::Anonymous => None,
        };
        let mut ctx = load_ctx(&core.pool, &viewer, Request::parse(&q), &headers, email.as_deref()).await?;
        render(&mut ctx).await
    }
    .await;
    match result {
        Ok(body) => html_response(body),
        Err(e) => failed(e),
    }
}

/// document_viewer.php: `document` is fn_base64(id, timestamp); without it nothing is shown.
async fn document_viewer(State(core): State<Arc<Auth>>, Query(q): Query<std::collections::HashMap<String, String>>) -> Response {
    let lang = Lang::from_param(q.get("lang").map(String::as_str));
    let id = q
        .get("document")
        .and_then(|d| html::base64_fields_decode(d))
        .and_then(|f| f.first().map(|s| super::php::Value::Str(s.clone()).int()))
        .unwrap_or(0);
    match document_html(&core.pool, id, lang).await {
        Ok(body) => html_response(body.unwrap_or_default()),
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
