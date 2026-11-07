//! scs_ajax.php without its table-name dispatch. The search box's own request (`table=search`) is answered as live
//! hxweb answered it, before PHP; any other table or caller gets the PHP's refusal, a bare 401.

use std::{collections::HashMap, sync::Arc};

use axum::{
    extract::{Query, State},
    http::{header, HeaderMap, StatusCode},
    response::{IntoResponse, Response},
    routing::get,
    Extension, Router,
};

use super::search;
use crate::{
    api::Auth,
    visitor::{Visitor, PHP_CONTENT_TYPE},
};

pub fn router() -> Router<Arc<Auth>> {
    Router::new().route("/scs_ajax.php", get(scs_ajax))
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

async fn scs_ajax(State(core): State<Arc<Auth>>, Extension(cache): Extension<Arc<search::Cache>>, visitor: Visitor, headers: HeaderMap, Query(q): Query<HashMap<String, String>>) -> Response {
    if is_search_box(&q) {
        return search::suggestions(&core, &cache, &visitor, &headers, q.get("term").map_or("", String::as_str), q.get("limit").map(String::as_str)).await;
    }
    (StatusCode::UNAUTHORIZED, [(header::CONTENT_TYPE, PHP_CONTENT_TYPE)]).into_response()
}

#[cfg(test)]
mod tests {
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
}
