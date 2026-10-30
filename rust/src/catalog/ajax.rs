//! scs_ajax.php without its table-name dispatch. The search box's own request (`table=search`) is answered as live
//! hxweb answered it, before PHP. Of the rest, only the company autocomplete staff pages call is served, to staff;
//! any other table or caller gets the PHP's refusal, a bare 401.

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
    visitor::{Visitor, PHP_CONTENT_TYPE},
};

pub fn router() -> Router<Arc<Auth>> {
    Router::new().route("/scs_ajax.php", get(scs_ajax))
}

/// ajax_class's request, as its constructor read $_REQUEST: values trimmed, empty ones ignored.
struct AjaxRequest {
    action: String,
    /// user ajax_source_company_name(), for design_partner_agreement.php and remote_access.php: the only source
    /// besides the search box that is still answered.
    companies: bool,
    terms: Vec<String>,
    limit: i64,
}

impl AjaxRequest {
    fn parse(q: &HashMap<String, String>) -> AjaxRequest {
        let get = |k: &str| q.get(k).map(|v| v.trim()).filter(|v| !v.is_empty());
        AjaxRequest {
            action: get("action").unwrap_or("search").to_lowercase(),
            terms: get("term").map(|t| t.split_whitespace().map(str::to_string).collect()).unwrap_or_default(),
            limit: get("limit").and_then(|l| l.parse::<i64>().ok()).filter(|l| *l > 0).unwrap_or(10).min(100),
            companies: get("table").is_some_and(|t| t.eq_ignore_ascii_case("user")) && get("source") == Some("company_name"),
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
        if !(req.companies && req.action == "search" && !req.terms.is_empty() && from_site_script(&headers)) {
            return Ok(refused());
        }
        let v = viewer::load(&core.pool, &visitor, viewer::Lang::En).await?;
        if !(v.active() && v.at_least(Role::Internal)) {
            return Ok(refused());
        }
        Ok::<_, sqlx::Error>(php_json(&companies(&core, &req).await?))
    }
    .await;
    result.unwrap_or_else(|e| {
        tracing::error!("scs_ajax: {e}");
        (StatusCode::INTERNAL_SERVER_ERROR, "Internal error").into_response()
    })
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
        assert_eq!((r.companies, r.action.as_str(), r.terms.clone(), r.limit), (true, "search", vec!["acme".to_string(), "tool".to_string()], 10));
        assert!(!AjaxRequest::parse(&q(&[("table", "inventory"), ("detail", "sku")])).companies, "the supplier pages' part list is gone");
        assert!(!AjaxRequest::parse(&q(&[("table", "user")])).companies, "full user rows are not served");
    }

    #[test]
    fn the_caller_must_be_a_script_on_this_host() {
        let mut h = HeaderMap::new();
        h.insert("host", HeaderValue::from_static("www.harcourt.co"));
        h.insert("referer", HeaderValue::from_static("https://www.harcourt.co/remote_access.php"));
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
