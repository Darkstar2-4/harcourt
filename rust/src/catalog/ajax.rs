//! scs_ajax.php without its table-name dispatch. The search box's own request (`table=search`) is answered as live
//! hxweb answered it, before PHP. Of the rest, only the staff autocompletes the site's pages call are served, to
//! staff; any other table or caller gets the PHP's refusal, a bare 401. portal_ajax.php, the old welcome pages'
//! sign-in form, is here as well.

use std::{collections::HashMap, sync::Arc};

use axum::{
    extract::{Query, State},
    http::{header, HeaderMap, StatusCode},
    response::{IntoResponse, Response},
    routing::get,
    Extension, Router,
};
use serde_json::{json, Value};

use super::{search, viewer};
use crate::{
    api::Auth,
    identity::Role,
    visitor::{Identity, Visitor, PHP_CONTENT_TYPE},
};

pub fn router() -> Router<Arc<Auth>> {
    Router::new().route("/scs_ajax.php", get(scs_ajax)).route("/portal_ajax.php", get(portal_ajax).post(portal_ajax))
}

/// The autocompletes behind scs_ajax.php that Rust still answers.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Source {
    /// user ajax_source_company_name(): design_partner_agreement.php and remote_access.php.
    Companies,
    /// inventory ajax_sku(): suppliersku.php.
    Skus,
}

/// ajax_class's request, as its constructor read $_REQUEST: values trimmed, empty ones ignored.
struct AjaxRequest {
    action: String,
    source: Option<Source>,
    terms: Vec<String>,
    limit: i64,
}

impl AjaxRequest {
    fn parse(q: &HashMap<String, String>) -> AjaxRequest {
        let get = |k: &str| q.get(k).map(|v| v.trim()).filter(|v| !v.is_empty());
        let source = match (get("table").unwrap_or("").to_lowercase().as_str(), get("source"), get("detail")) {
            ("user", Some("company_name"), _) => Some(Source::Companies),
            ("inventory", _, Some("sku")) => Some(Source::Skus),
            _ => None,
        };
        AjaxRequest {
            action: get("action").unwrap_or("search").to_lowercase(),
            terms: get("term").map(|t| t.split_whitespace().map(str::to_string).collect()).unwrap_or_default(),
            limit: get("limit").and_then(|l| l.parse::<i64>().ok()).filter(|l| *l > 0).unwrap_or(10).min(100),
            source,
        }
    }
}

/// The search box's request, which live hxweb took before PHP saw it: `table=search` exactly, searching. PHP never
/// ran for it, so neither did scs_header.php's visit handling.
fn is_search_box(q: &HashMap<String, String>) -> bool {
    q.get("table").is_some_and(|t| t == "search") && q.get("action").is_none_or(|a| a == "search")
}

/// `is_search_box` for a raw query string, as the visitor layer sees it.
pub fn search_box_query(query: &str) -> bool {
    let q: HashMap<String, String> = query
        .split('&')
        .filter_map(|kv| kv.split_once('=').or(Some((kv, ""))))
        .map(|(k, v)| (k.to_string(), percent_encoding::percent_decode_str(&v.replace('+', " ")).decode_utf8_lossy().into_owned()))
        .collect();
    is_search_box(&q)
}

/// ajax_class's gates: a Referer on this host and an XMLHttpRequest.
fn from_site_script(headers: &HeaderMap) -> bool {
    let get = |h: &str| headers.get(h).and_then(|v| v.to_str().ok()).unwrap_or("");
    let (host, referer) = (get("host"), get("referer").to_ascii_lowercase());
    let on_host = !host.is_empty() && ["http://", "https://"].iter().any(|s| referer.starts_with(&format!("{s}{}", host.to_ascii_lowercase())));
    on_host && get("x-requested-with").eq_ignore_ascii_case("xmlhttprequest")
}

fn refused() -> Response {
    (StatusCode::UNAUTHORIZED, [(header::CONTENT_TYPE, PHP_CONTENT_TYPE)]).into_response()
}

/// One PHP array as a JSON object, keys in the order given.
fn object(fields: &[(&str, Value)]) -> String {
    let fields: Vec<String> = fields.iter().map(|(k, v)| format!("{}:{v}", Value::from(*k))).collect();
    format!("{{{}}}", fields.join(","))
}

/// json_encode($results, JSON_UNESCAPED_UNICODE) of a list of objects, sent as PHP sent it: as HTML.
fn php_json(rows: &[String]) -> Response {
    let body = format!("[{}]", rows.join(",")).replace('/', "\\/");
    (StatusCode::OK, [(header::CONTENT_TYPE, PHP_CONTENT_TYPE)], body).into_response()
}

async fn scs_ajax(State(core): State<Arc<Auth>>, Extension(cache): Extension<Arc<search::Cache>>, visitor: Visitor, headers: HeaderMap, Query(q): Query<HashMap<String, String>>) -> Response {
    if is_search_box(&q) {
        return search::suggestions(&core, &cache, &visitor, &headers, q.get("term").map_or("", String::as_str), q.get("limit").map(String::as_str)).await;
    }
    let result = async {
        let req = AjaxRequest::parse(&q);
        let Some(source) = req.source.filter(|_| req.action == "search" && !req.terms.is_empty() && from_site_script(&headers)) else {
            return Ok(refused());
        };
        let v = viewer::load(&core.pool, &visitor, viewer::Lang::En).await?;
        if !(v.active() && v.at_least(Role::Internal)) {
            return Ok(refused());
        }
        let list = match source {
            Source::Companies => companies(&core, &req).await?,
            Source::Skus => skus(&core, &req).await?,
        };
        Ok::<_, sqlx::Error>(php_json(&list))
    }
    .await;
    result.unwrap_or_else(|e| {
        tracing::error!("scs_ajax: {e}");
        (StatusCode::INTERNAL_SERVER_ERROR, "Internal error").into_response()
    })
}

/// The sign-in form portal_ajax.php handed the old welcome pages, posting the email to the catalog.
const WELCOME_FORM: &str = "<form name='scs_form' id='scs_form' target='_top' method=post action='/catalog.php' accept-charset='utf-8' autocomplete=on>\n<input type=hidden name='action' id='action' value='email'>\n<div class='entry' id='firstentry'>Email Address: <input name='email' type='text' /></div><div class='submitbutton'><input type=image id='Image1' src='/scs_images/continuebutton.gif' width='140' height='31' border='0' onmouseover=\"javascript:MM_swapImage('Image1','','images/continuebuttonover.gif',1)\" onmouseout=\"javascript:MM_swapImgRestore();\"></div></form>";
const FORM_HOLDER: &str = "<form name='scs_form' id='scs_form' target='_top' method=post action='/catalog.php' accept-charset='utf-8' autocomplete=on>\n<input type=hidden name='action' id='action' value='email'>\n<div id='text-entry'><input type='text' name='email' id='email' /></div><div id='email-address'>E-mail Address: </div><div id='continue'><img src='/welcome-pages/continuebutton.gif' width='140' height='31' id='Image1' onmouseover='MM_swapImage('Image1','','/welcome-pages/continuebuttonover.gif',1)' onmouseout='MM_swapImgRestore()' onclick='document.scs_form.submit();' border='0'/></div><img src='/welcome-pages/formbackground2.gif' width='393' height='133' /></form>";

/// portal_ajax.php: for a script's request with action catalog or products, the visitor's company and the two
/// sign-in forms; for another action an empty list; for anything but a script's request, a refusal.
async fn portal_ajax(visitor: Visitor, headers: HeaderMap, Query(q): Query<HashMap<String, String>>) -> Response {
    let html = |body: String| (StatusCode::OK, [(header::CONTENT_TYPE, PHP_CONTENT_TYPE)], body).into_response();
    if !headers.get("x-requested-with").and_then(|v| v.to_str().ok()).is_some_and(|v| v.eq_ignore_ascii_case("xmlhttprequest")) {
        return html("Direct request".into());
    }
    if !matches!(q.get("action").map(String::as_str), Some("catalog" | "products")) {
        return html("[]".into());
    }
    let company = match &visitor.identity {
        Identity::Account(s) => s.account.user.company_name.clone(),
        Identity::Bot(b) => Some(b.name.clone()),
        Identity::Anonymous => None,
    };
    let php = super::loader::php_json;
    html(format!(
        "{{\"welcome_company_name\":{},\"userformwelcome\":{},\"form-holder\":{}}}",
        php(&company.map_or(Value::Null, Value::String)),
        php(&json!(WELCOME_FORM)),
        php(&json!(FORM_HOLDER))
    ))
}

/// Company names containing every word of the term, one per name, in MySQL's case-blind order.
async fn companies(core: &Auth, req: &AjaxRequest) -> sqlx::Result<Vec<String>> {
    let words: Vec<String> = req.terms.iter().map(|w| w.to_lowercase()).collect();
    let first = format!("%{}%", words.first().cloned().unwrap_or_default());
    let names = sqlx::query_scalar!(
        r#"SELECT company_name AS "name!" FROM "user" WHERE company_name IS NOT NULL AND company_name LIKE ?1
           GROUP BY company_name COLLATE NOCASE ORDER BY company_name COLLATE NOCASE, min(CASE WHEN domain IS NULL THEN 1 ELSE 0 END)"#,
        first
    )
    .fetch_all(&core.pool)
    .await?;
    Ok(names.into_iter().filter(|n| words.iter().all(|w| n.to_lowercase().contains(w))).take(req.limit as usize).map(|n| object(&[("value", json!(n))])).collect())
}

/// Active part numbers starting with the term's first word, with their category and family names.
async fn skus(core: &Auth, req: &AjaxRequest) -> sqlx::Result<Vec<String>> {
    let prefix = format!("{}%", req.terms.first().map_or("", String::as_str));
    let rows = sqlx::query!(
        r#"SELECT i.sku AS "sku!", c.name AS category_name, s.name AS subcategory_name, i.category_id, i.subcategory_id
           FROM inventory i LEFT JOIN subcategory s ON s.id = i.subcategory_id LEFT JOIN category c ON c.id = i.category_id
           WHERE i.sku LIKE ?1 AND i.active = 1 ORDER BY i.sku COLLATE NOCASE LIMIT ?2"#,
        prefix,
        req.limit
    )
    .fetch_all(&core.pool)
    .await?;
    let id = |v: Option<i64>| v.map(|v| v.to_string()).unwrap_or_default();
    Ok(rows
        .into_iter()
        .map(|r| {
            object(&[
                ("label", json!(r.sku)),
                ("category_name", json!(r.category_name)),
                ("subcategory_name", json!(r.subcategory_name)),
                ("category_subcategory_id", json!(format!("{}:{}", id(r.category_id), id(r.subcategory_id)))),
            ])
        })
        .collect())
}

#[cfg(test)]
mod tests {
    use axum::http::HeaderValue;

    use super::*;

    fn q(pairs: &[(&str, &str)]) -> HashMap<String, String> {
        pairs.iter().map(|(k, v)| ((*k).to_string(), (*v).to_string())).collect()
    }

    #[test]
    fn the_search_box_is_exactly_table_search() {
        assert!(is_search_box(&q(&[("table", "search"), ("term", "ls")])));
        assert!(is_search_box(&q(&[("table", "search"), ("action", "search")])));
        assert!(!is_search_box(&q(&[("table", "Search")])));
        assert!(!is_search_box(&q(&[("table", "search"), ("action", "save")])));
        assert!(search_box_query("table=search&limit=20&term=HS"));
        assert!(!search_box_query("table=user&source=company_name"));
    }

    #[test]
    fn requests_parse_as_ajax_class_read_them() {
        let r = AjaxRequest::parse(&q(&[("table", " User "), ("source", "company_name"), ("term", " acme  tool "), ("limit", "0")]));
        assert_eq!((r.source, r.action.as_str(), r.terms.clone(), r.limit), (Some(Source::Companies), "search", vec!["acme".to_string(), "tool".to_string()], 10));
        assert_eq!(AjaxRequest::parse(&q(&[("table", "inventory"), ("detail", "sku")])).source, Some(Source::Skus));
        assert_eq!(AjaxRequest::parse(&q(&[("table", "user")])).source, None, "full user rows are not served");
    }

    #[test]
    fn the_caller_must_be_a_script_on_this_host() {
        let mut h = HeaderMap::new();
        h.insert("host", HeaderValue::from_static("www.harcourt.co"));
        h.insert("referer", HeaderValue::from_static("https://www.harcourt.co/suppliersku.php"));
        assert!(!from_site_script(&h));
        h.insert("x-requested-with", HeaderValue::from_static("XMLHttpRequest"));
        assert!(from_site_script(&h));
        h.insert("referer", HeaderValue::from_static("https://elsewhere.example/"));
        assert!(!from_site_script(&h));
    }

    #[tokio::test]
    async fn json_is_php_shaped() {
        let r = php_json(&[object(&[("label", json!("A/B")), ("category_name", json!("Pinces é")), ("subcategory_name", Value::Null)])]);
        assert_eq!(r.headers()["content-type"], PHP_CONTENT_TYPE);
        let body = axum::body::to_bytes(r.into_body(), 1 << 16).await.unwrap();
        assert_eq!(std::str::from_utf8(&body).unwrap(), r#"[{"label":"A\/B","category_name":"Pinces é","subcategory_name":null}]"#);
    }
}
