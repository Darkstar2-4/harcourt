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
use crate::{api::Auth, identity::Role, token::Token, visitor::Visitor};

const VERSION: &str = "10/01/2025";

pub fn router() -> Router<Arc<Auth>> {
    Router::new().route("/scscpq_wp.php", any(scscpq_wp))
}

/// What a page posts with action=initial.
pub struct Initial<'a> {
    pub page: &'a str,
    pub document: &'a str,
    pub token: &'a str,
    pub url: &'a str,
    /// The site origin links are built on, e.g. https://www.harcourt.co.
    pub origin: &'a str,
    pub referrer: &'a str,
}

/// The answer, keys in the order the PHP set them (json_encode keeps it).
#[derive(Debug, Clone, Default, PartialEq)]
pub struct Loader(Vec<(&'static str, Value)>);

impl Loader {
    fn set(&mut self, key: &'static str, value: Value) {
        match self.0.iter_mut().find(|(k, _)| *k == key) {
            Some(entry) => entry.1 = value,
            None => self.0.push((key, value)),
        }
    }

    pub fn get(&self, key: &str) -> Option<&Value> {
        self.0.iter().find(|(k, _)| *k == key).map(|(_, v)| v)
    }

    /// PHP's json_encode(): slashes and non-ASCII characters escaped.
    pub fn to_json(&self) -> String {
        let mut out = String::from("{");
        for (i, (k, v)) in self.0.iter().enumerate() {
            if i > 0 {
                out.push(',');
            }
            out.push_str(&php_json(&Value::String((*k).to_string())));
            out.push(':');
            out.push_str(&php_json(v));
        }
        out.push('}');
        out
    }
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

/// action=initial for this viewer, in the viewer's language. The /catalog/ composer inlines it in the page as
/// `window.hxweb_loader`.
pub async fn initial(pool: &SqlitePool, viewer: &Viewer, req: &Initial<'_>) -> sqlx::Result<Loader> {
    let strings = strings::load(pool, viewer.lang).await?;
    let mut trace = vec!["__construct", "initial"];
    let token = if req.token.is_empty() {
        trace.push("token");
        Token::generate().as_str().to_string()
    } else {
        req.token.to_string()
    };
    trace.push("language");
    let mut out = Loader::default();
    trace.push("newuser");
    let newuser = !(viewer.account_id().is_some() && viewer.active) && !viewer.is_bot();
    out.set("scscpq_newuser", json!(i32::from(newuser)));
    trace.push("impersonate");
    out.set("scscpq_impersonate", json!(0));
    trace.push("catalog");
    out.set("scscpq_catalog", json!(i32::from(catalog(viewer))));
    trace.extend(["search", "catalog"]);
    out.set("scscpq_search", if catalog(viewer) && !viewer.is_bot() { json!(search_box(&strings)) } else { Value::Null });
    trace.extend(["cart", "catalog"]);
    out.set("scscpq_cart", if catalog(viewer) && viewer.ecommerce && !viewer.is_bot() { json!(cart_link(&strings, 0)) } else { Value::Null });
    trace.push("portal");
    out.set("scscpq_portal", json!(portal_link(&strings, viewer.lang)));
    trace.push("dashboard");
    out.set("scscpq_dashboard", json!(i32::from(viewer.at_least(Role::Executive) && viewer.active)));
    out.set("scscpq_products", json!(i32::from(catalog(viewer))));
    let account_language = viewer.account_lang.filter(|_| viewer.account_id().is_some()).map_or("", Lang::code);
    out.set("account_language", json!(account_language));
    if !req.page.is_empty() {
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
    out.set("meta", json!({"script": "/scscpq_wp.php", "referrer": req.referrer, "version": VERSION}));
    trace.push("document");
    out.set("scscpq_document", match document_url(viewer, req) {
        Some(url) => {
            trace.push("url");
            json!(url)
        }
        None => Value::Null,
    });
    out.set("token", json!(token));
    out.set("error", json!(""));
    out.set("trace", json!(trace));
    Ok(out)
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
            ("/catalog.php", true)
        }
        "campaign" => {
            for (k, v) in url_query(req.url) {
                query.set(&k, v);
            }
            ("/campaign.php", false)
        }
        "customer_portal" => ("/customer_portal.php", true),
        "cart" if viewer.ecommerce => ("/cart.php", false),
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
    if req.document.trim().to_lowercase() != "document_email" || viewer.account_id().is_none() {
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
        html::esc(strings.get("menu_cart"))
    );
    html::href_with(&text, "/cart/", &[("class", "nav-link"), ("title", strings.get("menu_cart_title"))])
}

fn portal_link(strings: &Strings, lang: Lang) -> String {
    let label = strings.get("menu_portal");
    let text = format!("<img src='/scs_images/portal.png' id=menu_portal_img> {}", html::esc(label));
    let url = if lang == Lang::Fr { "/portail-clients/?lang=fr" } else { "/customer_portal/" };
    html::href_with(&text, url, &[("class", "nav-link"), ("title", label)])
}

fn json_response(status: StatusCode, body: String) -> Response {
    (status, [(header::CONTENT_TYPE, "application/json; charset=utf-8"), (header::CACHE_CONTROL, "no-store")], body).into_response()
}

fn form(body: &Bytes) -> HashMap<String, String> {
    url_query(&format!("?{}", String::from_utf8_lossy(body))).into_iter().collect()
}

/// POST with action=initial. Any other method or action answers 401 with the PHP's error codes.
async fn scscpq_wp(
    State(core): State<Arc<Auth>>,
    visitor: Visitor,
    method: Method,
    headers: HeaderMap,
    Query(query): Query<HashMap<String, String>>,
    body: Bytes,
) -> Response {
    let mut params = query;
    params.extend(form(&body));
    let get = |k: &str| params.get(k).map(String::as_str).unwrap_or("");
    let action = get("action");
    let referrer = headers.get(header::REFERER).and_then(|v| v.to_str().ok()).unwrap_or("");
    let fatal = if method != Method::POST {
        Some("102")
    } else if action.is_empty() {
        Some("103")
    } else if action != "initial" {
        Some("104")
    } else {
        None
    };
    if let Some(code) = fatal {
        let mut out = Loader::default();
        out.set("meta", json!({"script": "/scscpq_wp.php", "referrer": referrer, "version": VERSION}));
        out.set("scscpq_document", Value::Null);
        out.set("error", json!(format!("{action}: {code}")));
        out.set("trace", json!(["__construct", "document"]));
        return json_response(StatusCode::UNAUTHORIZED, out.to_json());
    }
    let lang = language(get("language"));
    let result = async {
        let viewer = viewer::load(&core.pool, &visitor, lang).await?;
        let req = Initial { page: get("page"), document: get("document"), token: get("token"), url: get("url"), origin: &core.public_origin, referrer };
        initial(&core.pool, &viewer, &req).await
    }
    .await;
    match result {
        Ok(out) => json_response(StatusCode::OK, out.to_json()),
        Err(e) => {
            tracing::error!("scscpq_wp: {e}");
            json_response(StatusCode::INTERNAL_SERVER_ERROR, "{\"error\":\"internal\"}".into())
        }
    }
}

/// The page's language (document.documentElement.lang, two letters): French or English.
fn language(code: &str) -> Lang {
    Lang::from_code(&code.chars().take(2).collect::<String>()).unwrap_or(Lang::En)
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
            var query={ limit: 20, term: term };
            jQuery.ajax({
                url: "/catalog-api/search?" + jQuery.param(query),
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
        let req = Initial { page: "catalog", document: "", token: "", url: "https://www.harcourt.co/catalogue/?lang=fr&action=sku&sku=SEJMVC0z", origin: "https://www.harcourt.co", referrer: "" };
        assert_eq!(page_url(&v, &req), Some(("https://www.harcourt.co/catalog.php?lang=fr&action=sku&sku=SEJMVC0z&scscpq_wp=1".into(), true)));
        assert_eq!(page_url(&v, &Initial { page: "dashboard", ..req }), None);
        assert_eq!(language("fr-CA"), Lang::Fr);
        assert_eq!(language(""), Lang::En);
    }
}
