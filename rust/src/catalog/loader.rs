//! scscpq_wp.php: the JSON the site's pages ask for (web/src/scscpq_wp.ts) to decide which blocks to show: the
//! request-access link, the catalog, the search box, the cart and portal links, the dashboard, and the address of
//! the SCS view to load into the page.

use std::{collections::HashMap, sync::Arc};

use axum::{
    body::Bytes,
    extract::{Query, State},
    http::{header, HeaderMap, Method, StatusCode},
    response::{IntoResponse, Response},
    routing::any,
    Router,
};
use serde_json::{json, Value};
use sqlx::SqlitePool;

use super::{
    forms, html,
    strings::{self, Strings},
    viewer::{self, Lang, Viewer},
};
use crate::{
    api::Auth,
    identity::{Role, Status},
    visitor::Visitor,
};

const VERSION: &str = "10/01/2025";

pub fn router() -> Router<Arc<Auth>> {
    Router::new().route("/scscpq_wp.php", any(scscpq_wp))
}

/// What a page posts with action=initial.
pub struct Initial<'a> {
    pub page: &'a str,
    pub document: &'a str,
    /// The request's token, when it sent one.
    pub token: Option<&'a str>,
    pub url: &'a str,
    /// The site origin links are built on, e.g. https://www.harcourt.co.
    pub origin: &'a str,
    pub referrer: Option<&'a str>,
    /// The cart flag of the session's user: a Pending account's stored one, which PHP re-read without clearing.
    pub ecommerce: bool,
    /// An Administrator is viewing the site as this visitor.
    pub impersonating: bool,
    /// The browser sent an `email` cookie: the person is known, so no welcome document.
    pub email_cookie: bool,
}

/// The answer, keys in the order the PHP set them (json_encode keeps it), each value already encoded.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct Loader(Vec<(&'static str, String)>);

impl Loader {
    fn set(&mut self, key: &'static str, value: Value) {
        self.set_encoded(key, php_json(&value));
    }

    fn set_encoded(&mut self, key: &'static str, json: String) {
        match self.0.iter_mut().find(|(k, _)| *k == key) {
            Some(entry) => entry.1 = json,
            None => self.0.push((key, json)),
        }
    }

    /// PHP's json_encode(): slashes and non-ASCII characters escaped.
    pub fn to_json(&self) -> String {
        let fields: Vec<String> = self.0.iter().map(|(k, v)| format!("{}:{v}", php_json(&Value::String((*k).to_string())))).collect();
        format!("{{{}}}", fields.join(","))
    }
}

/// The meta object, keys in the PHP's order; the referrer is null when the request had none.
fn meta(referrer: Option<&str>) -> String {
    let referrer = referrer.map_or_else(|| "null".to_string(), echoed);
    format!("{{\"script\":{},\"referrer\":{referrer},\"version\":{}}}", php_json(&json!("/scscpq_wp.php")), php_json(&json!(VERSION)))
}

/// json_encode() of a value the request supplied. The answer is text/html as the PHP's was, so `<` and `>` are
/// written as < and >: the same string to a JSON reader, never markup to a browser.
fn echoed(s: &str) -> String {
    php_json(&json!(s)).replace('<', "\\u003c").replace('>', "\\u003e")
}

/// json_encode() of one value.
pub fn php_json(v: &Value) -> String {
    match v {
        Value::String(s) => {
            let mut out = String::from("\"");
            for c in s.chars() {
                match c {
                    '"' => out.push_str("\\\""),
                    '\\' => out.push_str("\\\\"),
                    '/' => out.push_str("\\/"),
                    '\n' => out.push_str("\\n"),
                    '\r' => out.push_str("\\r"),
                    '\t' => out.push_str("\\t"),
                    '\u{8}' => out.push_str("\\b"),
                    '\u{c}' => out.push_str("\\f"),
                    c if (c as u32) < 0x20 || (c as u32) > 0x7e => {
                        let mut buf = [0u16; 2];
                        for unit in c.encode_utf16(&mut buf) {
                            out.push_str(&format!("\\u{unit:04x}"));
                        }
                    }
                    c => out.push(c),
                }
            }
            out.push('"');
            out
        }
        Value::Array(items) => format!("[{}]", items.iter().map(php_json).collect::<Vec<_>>().join(",")),
        Value::Object(map) => format!("{{{}}}", map.iter().map(|(k, v)| format!("{}:{}", php_json(&Value::String(k.clone())), php_json(v))).collect::<Vec<_>>().join(",")),
        other => other.to_string(),
    }
}

fn catalog(viewer: &Viewer) -> bool {
    viewer.may_browse()
}

/// The methods of scscpq_wp_class a request may name as its `action` (the PHP called any method by name, case
/// blind). Those that only answer are answered as the PHP answered; the constructor, url() and the version table,
/// which crashed the PHP when called this way, count as unknown.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Action {
    Initial,
    Token,
    Language,
    User,
    Impersonate,
    Dashboard,
    Newuser,
    Catalog,
    Search,
    Cart,
    Portal,
    SearchCss,
    SearchJs,
    Page,
    Document,
}

impl Action {
    pub fn parse(action: &str) -> Option<Action> {
        Some(match action.to_ascii_lowercase().as_str() {
            "initial" => Action::Initial,
            "token" => Action::Token,
            "language" => Action::Language,
            "user" => Action::User,
            "impersonate" => Action::Impersonate,
            "dashboard" => Action::Dashboard,
            "newuser" => Action::Newuser,
            "catalog" => Action::Catalog,
            "search" => Action::Search,
            "cart" => Action::Cart,
            "portal" => Action::Portal,
            "search_css" => Action::SearchCss,
            "search_js" => Action::SearchJs,
            "page" => Action::Page,
            "document" => Action::Document,
            _ => return None,
        })
    }

    /// The request's language applies: initial() and language() read it.
    pub fn reads_language(self) -> bool {
        matches!(self, Action::Initial | Action::Language)
    }
}

/// What the called method handed back, which the PHP kept as `$this->fatal`: nothing (null, false) lets the request
/// go on; anything else is printed as the error with a 401, and a 0 also lets page() run first.
enum Returned {
    Nothing,
    Int(i32),
    Text(String),
}

impl Returned {
    fn lets_page_run(&self) -> bool {
        matches!(self, Returned::Nothing | Returned::Int(0))
    }

    fn error(&self) -> Option<String> {
        match self {
            Returned::Nothing => None,
            Returned::Int(n) => Some(n.to_string()),
            Returned::Text(t) => Some(t.clone()),
        }
    }
}

fn new_token() -> String {
    hex::encode(rand::random::<[u8; 32]>()) + &hex::encode(rand::random::<[u8; 8]>())
}

/// The answer to `action` for this viewer, in the viewer's language, and whether it was refused. The /catalog/
/// composer inlines the answer to initial in the page as `window.hxweb_loader`.
pub async fn answer(pool: &SqlitePool, viewer: &Viewer, req: &Initial<'_>, action: Action, named: &str) -> sqlx::Result<(bool, Loader)> {
    let strings = strings::load(pool, viewer.lang).await?;
    let mut trace = vec!["__construct"];
    let mut token = req.token.map(str::to_string);
    let mut out = Loader::default();
    let newuser = !(viewer.account_id().is_some() && viewer.status != Status::Pending) && !viewer.is_bot();
    let dashboard = viewer.at_least(Role::Executive) && viewer.active();
    let search = catalog(viewer) && !viewer.is_bot();
    let cart = catalog(viewer) && viewer.ecommerce && !viewer.is_bot();
    let returned = match action {
        Action::Initial => {
            trace.push("initial");
            if token.as_deref().is_none_or(str::is_empty) {
                trace.push("token");
                token = Some(new_token());
            }
            trace.extend(["language", "newuser"]);
            out.set("scscpq_newuser", json!(i32::from(newuser)));
            trace.push("impersonate");
            out.set("scscpq_impersonate", json!(i32::from(req.impersonating)));
            trace.push("catalog");
            out.set("scscpq_catalog", json!(i32::from(catalog(viewer))));
            trace.extend(["search", "catalog"]);
            out.set("scscpq_search", if search { json!(search_box(&strings)) } else { Value::Null });
            trace.extend(["cart", "catalog"]);
            out.set("scscpq_cart", if cart { json!(cart_link(&strings, 0)) } else { Value::Null });
            trace.push("portal");
            out.set("scscpq_portal", json!(portal_link(&strings, viewer.lang)));
            trace.push("dashboard");
            out.set("scscpq_dashboard", json!(i32::from(dashboard)));
            out.set("scscpq_products", json!(i32::from(catalog(viewer))));
            let account_language = viewer.account_lang.filter(|_| viewer.account_id().is_some()).map_or("", Lang::code);
            out.set("account_language", json!(account_language));
            Returned::Nothing
        }
        Action::Token => {
            trace.push("token");
            token = Some(new_token());
            Returned::Nothing
        }
        Action::Language => {
            trace.push("language");
            Returned::Nothing
        }
        Action::User => {
            trace.push("user");
            Returned::Nothing
        }
        Action::Impersonate => {
            trace.push("impersonate");
            Returned::Int(i32::from(req.impersonating))
        }
        Action::Dashboard => {
            trace.push("dashboard");
            Returned::Int(i32::from(dashboard))
        }
        Action::Newuser => {
            trace.push("newuser");
            Returned::Int(i32::from(newuser))
        }
        Action::Catalog => {
            trace.push("catalog");
            Returned::Int(i32::from(catalog(viewer)))
        }
        Action::Search => {
            trace.extend(["search", "catalog"]);
            if search { Returned::Text(search_box(&strings)) } else { Returned::Nothing }
        }
        Action::Cart => {
            trace.extend(["cart", "catalog"]);
            if cart { Returned::Text(cart_link(&strings, 0)) } else { Returned::Nothing }
        }
        Action::Portal => {
            trace.push("portal");
            Returned::Text(portal_link(&strings, viewer.lang))
        }
        Action::SearchCss => Returned::Text(SEARCH_CSS.to_string()),
        Action::SearchJs => Returned::Text(SEARCH_JS.to_string()),
        Action::Page => {
            if page_url(viewer, req).is_some() {
                trace.push("url");
            }
            Returned::Nothing
        }
        Action::Document => {
            trace.push("document");
            match document_url(viewer, req) {
                Some(url) => {
                    trace.push("url");
                    Returned::Text(url)
                }
                None => Returned::Nothing,
            }
        }
    };
    if returned.lets_page_run() && !req.page.trim().is_empty() {
        match page_url(viewer, req) {
            Some((url, true)) => {
                trace.push("url");
                out.set("scscpq_link", json!(url));
            }
            Some((url, false)) => {
                trace.push("url");
                out.set("scscpq_iframe", json!(format!("<iframe id=scscpq_wp_iframe src={} width='100%' frameBorder='0' style='height: 100px;'>", forms::js_quote(&url))));
            }
            None => out.set("scscpq_page", json!("")),
        }
    }
    let fatal = returned.error();
    tail(&mut out, &mut trace, viewer, req);
    match &fatal {
        Some(e) => out.set("error", json!(format!("{named}: {e}"))),
        None => {
            out.set_encoded("token", token.as_deref().map_or_else(|| "null".to_string(), echoed));
            out.set("error", json!(""));
        }
    }
    out.set("trace", json!(trace));
    Ok((fatal.is_some(), out))
}

/// What the constructor added to every answer after the method ran: meta and the welcome document.
fn tail(out: &mut Loader, trace: &mut Vec<&'static str>, viewer: &Viewer, req: &Initial) {
    out.set_encoded("meta", meta(req.referrer));
    trace.push("document");
    out.set("scscpq_document", match document_url(viewer, req) {
        Some(url) => {
            trace.push("url");
            json!(url)
        }
        None => Value::Null,
    });
}

/// scscpq_wp_class::page(): the SCS view the page loads, as a link (`true`) or an iframe.
fn page_url(viewer: &Viewer, req: &Initial) -> Option<(String, bool)> {
    let page = req.page.trim().to_lowercase();
    let mut query = html::Query::new();
    let (path, link) = match page.as_str() {
        "dashboard" if viewer.at_least(Role::Executive) => ("/dashboard.php", false),
        "newuser" if !viewer.is_bot() => ("/newuser.php", true),
        "products" | "catalog" => {
            for (k, v) in url_query(req.url) {
                query.set(&k, v);
            }
            (if req.url.to_ascii_lowercase().contains("beta") { "/beta_catalog.php" } else { "/catalog.php" }, true)
        }
        "campaign" => {
            for (k, v) in url_query(req.url) {
                query.set(&k, v);
            }
            ("/campaign.php", false)
        }
        "impersonate" if req.impersonating => ("/user_impersonate.php", false),
        "customer_portal" => ("/customer_portal.php", true),
        "cart" if req.ecommerce => ("/cart.php", false),
        _ => return None,
    };
    query.set("scscpq_wp", "1");
    Some((format!("{}{path}{}", req.origin, query.encoded()), link))
}

/// The page's own query, as parse_str() reads it.
fn url_query(url: &str) -> Vec<(String, String)> {
    let query = url.split_once('?').map_or("", |(_, q)| q);
    let query = query.split_once('#').map_or(query, |(q, _)| q);
    query
        .split('&')
        .filter(|p| !p.is_empty())
        .map(|p| {
            let (k, v) = p.split_once('=').unwrap_or((p, ""));
            let decode = |s: &str| percent_encoding::percent_decode_str(&s.replace('+', " ")).decode_utf8_lossy().into_owned();
            (decode(k), decode(v))
        })
        .collect()
}

/// scscpq_wp_class::document(): the sign-in welcome document for an account whose person is not known yet.
fn document_url(viewer: &Viewer, req: &Initial) -> Option<String> {
    if req.document.trim().to_lowercase() != "document_email" || viewer.account_id().is_none() || req.email_cookie {
        return None;
    }
    let id = viewer.login_document_id.unwrap_or(156);
    let stamp = crate::db::unix_now().to_string();
    let q = html::Query::new().with("document", html::base64_fields(&[&id.to_string(), &stamp]));
    Some(format!("{}/document_viewer.php{}", req.origin, q.encoded()))
}

fn search_box(strings: &Strings) -> String {
    let input = forms::text("scscpq_search_text", "", "20", "", &[("placeholder", strings.get("menu_search_placeholder"))]);
    format!("{SEARCH_CSS}{SEARCH_JS}<form id=scscpq_search_form  method=GET action=\"javascript:search_content(0, jQuery('#scscpq_search_text').val());\">{input}</form>")
}

fn cart_link(strings: &Strings, items: usize) -> String {
    let text = format!(
        "<img src='/scs_images/cart.png' id=menu_cart_img title='View Cart'> <span id=menu_cart_size>{items}</span> {}",
        html::esc_text(strings.get("menu_cart"))
    );
    html::href_with(&text, "/cart/", &[("class", "nav-link"), ("title", strings.get("menu_cart_title"))])
}

fn portal_link(strings: &Strings, lang: Lang) -> String {
    let label = strings.get("menu_portal");
    let text = format!("<img src='/scs_images/portal.png' id=menu_portal_img> {}", html::esc_text(label));
    let url = if lang == Lang::Fr { "/portail-clients/?lang=fr" } else { "/customer_portal/" };
    html::href_with(&text, url, &[("class", "nav-link"), ("title", label)])
}

fn json_response(status: StatusCode, body: String) -> Response {
    (status, [(header::CONTENT_TYPE, crate::visitor::PHP_CONTENT_TYPE), (header::CACHE_CONTROL, "no-store")], body).into_response()
}

fn form(body: &Bytes) -> HashMap<String, String> {
    url_query(&format!("?{}", String::from_utf8_lossy(body))).into_iter().collect()
}

/// A POST naming one of the class's methods as its action. Anything else answers 401 with the PHP's error codes.
async fn scscpq_wp(
    State(core): State<Arc<Auth>>,
    visitor: Visitor,
    method: Method,
    headers: HeaderMap,
    Query(query): Query<HashMap<String, String>>,
    body: Bytes,
) -> Response {
    // $_REQUEST: the query, then the body of a POST only, as PHP fills $_POST for nothing else.
    let mut params = query;
    if method == Method::POST {
        params.extend(form(&body));
    }
    let get = |k: &str| params.get(k).map(String::as_str).unwrap_or("");
    let named = get("action");
    let action = Action::parse(named);
    let refused = if method != Method::POST {
        Some("102")
    } else if named.is_empty() {
        Some("103")
    } else if action.is_none() {
        Some("104")
    } else {
        None
    };
    let chosen = action.filter(|a| a.reads_language()).and_then(|_| language(get("language")));
    let remember = !matches!(get("remember"), "" | "0");
    let cookie = |name: &str| crate::accounts::cookies::get(&headers, name).is_some_and(|s| !s.is_empty() && s != "0");
    let (inside_visit, email_cookie) = (cookie("PHPSESSID"), cookie("email"));
    let referrer = headers.get(header::REFERER).and_then(|v| v.to_str().ok());
    let result = async {
        let mut viewer = viewer::load(&core.pool, &visitor, chosen.unwrap_or_else(|| viewer::lang_for(&visitor))).await?;
        let ecommerce = match visitor.account() {
            Some(a) if a.user.status == Status::Pending && inside_visit => a.grants.ecommerce,
            _ => viewer.ecommerce,
        };
        let req = Initial {
            page: get("page"),
            document: get("document"),
            token: params.get("token").map(String::as_str),
            url: get("url"),
            origin: &core.public_origin,
            referrer,
            ecommerce,
            impersonating: visitor.impersonator.is_some(),
            email_cookie,
        };
        let (Some(action), None) = (action, refused) else {
            let mut out = Loader::default();
            let mut trace = vec!["__construct"];
            tail(&mut out, &mut trace, &viewer, &req);
            out.set_encoded("error", echoed(&format!("{named}: {}", refused.unwrap_or_default())));
            out.set("trace", json!(trace));
            return Ok((true, out));
        };
        if let (Some(chosen), Some(visit), false) = (chosen, &visitor.visit, viewer.status == Status::Pending) {
            crate::accounts::visit::set_language(&core.pool, visit, chosen.code(), crate::db::unix_now()).await?;
        }
        if let (true, Some(id), Some(account), Some(chosen)) = (remember, viewer.account_id(), viewer.account_lang, chosen) {
            if account != chosen {
                crate::accounts::account::set_language(&core.pool, id, chosen.code()).await?;
                viewer.account_lang = Some(chosen);
            }
        }
        answer(&core.pool, &viewer, &req, action, named).await
    }
    .await;
    match result {
        Ok((refused, out)) => json_response(if refused { StatusCode::UNAUTHORIZED } else { StatusCode::OK }, out.to_json()),
        Err(e) => {
            tracing::error!("scscpq_wp: {e}");
            json_response(StatusCode::INTERNAL_SERVER_ERROR, "{\"error\":\"internal\"}".into())
        }
    }
}

/// The page's language (document.documentElement.lang, its first two letters), when it names one. Without it the
/// visit keeps its language. A visit whose user PHP re-read on every page (anyone not signed in, a Pending account)
/// lost the choice on the next page, so it is kept only for the others.
fn language(code: &str) -> Option<Lang> {
    Lang::from_code(&code.trim().chars().take(2).collect::<String>())
}

const SEARCH_CSS: &str = "\n<style>\n.ui-autocomplete {\n    z-index: 1000;\n    position: absolute;\n    overflow: visible;\n}\n</style>";

/// scscpq_wp.php search_js(), pointed at the typed suggestion endpoint instead of scs_ajax.php.
const SEARCH_JS: &str = r###"
<script>
jQuery(function() {
    var box=jQuery("#scscpq_search_text");
    box.autocomplete({
        // hxweb answers in about a millisecond, so ask on every keystroke instead of after jQuery UI's 300 ms pause
        delay: 0,
        source: function(request, response) {
            var term=this.term.trim();
            if (term.length < 2) return;
            var query={ table: "search", limit: 20, term: term };
            jQuery.ajax({
                url: "/scs_ajax.php?" + jQuery.param(query),
                dataType: 'json',
                success: function (data) {
                    // Enter opens the first suggestion when it is the part number or name typed, or starts with it
                    // (hxweb's tiers exact and prefix); otherwise Enter still opens the search results page
                    var tier=(data.items.length > 0) ? data.items[0].tier : "";
                    box.autocomplete("option", "autoFocus", tier == "exact" || tier == "prefix");
                    if (data.items.length > 0) {
                        response(data.items);
                        } else {
                        response([{ label: 'No results found.', val: '', id: 0 }]);
                    }
                }
            })
        },
        select: function(event, ui) {
            switch (true) {
              case (ui.item['category'] == "Lookup"):
                search_content(0, ui.item['value'], "lookup");
                break;
              case (ui.item['id'] != 0):
                search_content(ui.item['id'], '');
                break;
            }
        }
    });
});
/*
jQuery(function() {
    jQuery("#scscpq_search_text").autocomplete({
        source: function(request, response) {
            var term=this.term.trim();
            if (term.length < 2) return;
            var query={ table: "search", limit: 20, term: term };
            jQuery.ajax({
                url: "/scs_ajax.php?" + jQuery.param(query),
                dataType: 'json',
                success: function (data) {
                    if (data.items.length > 0) {
                        response(data.items);
                        } else {
                        response([{ label: 'No results found.', val: '', id: 0 }]);
                    }
                }
            })
        },
        select: function(event, ui) {
            if (ui.item['id']) search_content(ui.item['id'], '')
        }
    });
});
*/
function search_content(id, code, search_source="search") {
    code=code.trim();
    if ( (!id) && (code.length < 2) ) {
        alert("Your search term must include at least 2 characters");
        return;
    }
    // the French catalogue keeps its own address, so a pick there can swap in place too
    var french=/^\/catalogue\//.test(location.pathname);
    var url=(french ? "/catalogue/?lang=fr&" : "/catalog/?") + jQuery.param( { search_source: search_source, search_id: id, search_code: code } );
    // on a catalog page hx-nav swaps in just the catalog, as it does for catalog links
    if (!(window.hxGo && window.hxGo(url))) window.location.href=url;
}
</script>"###;

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn json_is_encoded_as_php_does() {
        assert_eq!(php_json(&json!("a/b é \"q\"")), "\"a\\/b \\u00e9 \\\"q\\\"\"");
        assert_eq!(php_json(&json!(["x", 1, null])), "[\"x\",1,null]");
        let mut l = Loader::default();
        l.set("b", json!(1));
        l.set("a", json!("x"));
        l.set("b", json!(2));
        assert_eq!(l.to_json(), "{\"b\":2,\"a\":\"x\"}");
    }

    #[test]
    fn the_catalog_page_links_its_own_query_back() {
        let v = Viewer::anonymous(Lang::En);
        let req = Initial { page: "catalog", document: "", token: None, url: "https://www.harcourt.co/catalogue/?lang=fr&action=sku&sku=SEJMVC0z", origin: "https://www.harcourt.co", referrer: None, ecommerce: false, impersonating: false, email_cookie: false };
        assert_eq!(page_url(&v, &req), Some(("https://www.harcourt.co/catalog.php?lang=fr&action=sku&sku=SEJMVC0z&scscpq_wp=1".into(), true)));
        assert_eq!(page_url(&v, &Initial { page: "dashboard", ..req }), None);
        let beta = Initial { url: "https://www.harcourt.co/beta_catalog/", ..req };
        assert_eq!(page_url(&v, &beta), Some(("https://www.harcourt.co/beta_catalog.php?scscpq_wp=1".into(), true)));
        assert_eq!(language(" fr-CA"), Some(Lang::Fr));
        assert_eq!(language("en"), Some(Lang::En));
        assert_eq!(language(""), None);
    }
}
