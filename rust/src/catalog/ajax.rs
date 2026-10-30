//! scs_ajax.php without its table-name parameter: each autocomplete the site has gets its own typed answer with
//! only the fields its caller reads, for the visitors allowed to see them. Any other table is not served.

use std::{collections::HashMap, sync::Arc};

use axum::{
    extract::{Query, State},
    http::{header, HeaderMap, StatusCode},
    response::{IntoResponse, Response},
    routing::get,
    Extension, Json, Router,
};
use serde_json::{json, Value};

use super::{search, viewer};
use crate::{api::Auth, identity::Role, visitor::Visitor};

pub fn router() -> Router<Arc<Auth>> {
    Router::new()
        .route("/scs_ajax.php", get(scs_ajax))
        .route("/catalog-api/companies", get(companies))
        .route("/catalog-api/skus", get(skus))
}

fn limit(q: &HashMap<String, String>) -> i64 {
    q.get("limit").and_then(|l| l.parse::<i64>().ok()).filter(|l| *l > 0).unwrap_or(10).min(100)
}

fn answer(status: StatusCode, v: Value) -> Response {
    (status, [(header::CACHE_CONTROL, "no-store")], Json(v)).into_response()
}

/// Staff autocompletes: an active account at Internal or above.
async fn staff(core: &Auth, visitor: &Visitor) -> sqlx::Result<bool> {
    let v = viewer::load(&core.pool, visitor, viewer::Lang::En).await?;
    Ok(v.active && v.at_least(Role::Internal))
}

async fn scs_ajax(State(core): State<Arc<Auth>>, Extension(cache): Extension<Arc<search::Cache>>, visitor: Visitor, headers: HeaderMap, Query(q): Query<HashMap<String, String>>) -> Response {
    let get = |k: &str| q.get(k).map(String::as_str).unwrap_or("");
    match (get("table").to_lowercase().as_str(), get("source"), get("detail")) {
        ("search", _, _) if matches!(get("action"), "" | "search") => search::suggestions(&core, &cache, &visitor, &headers, get("term"), q.get("limit").map(String::as_str)).await,
        ("user", "company_name", _) => company_list(&core, &visitor, get("term"), limit(&q)).await,
        ("inventory", _, "sku") => sku_list(&core, &visitor, get("term"), limit(&q)).await,
        _ => answer(StatusCode::NOT_FOUND, json!({"error": "Table not found"})),
    }
}

async fn companies(State(core): State<Arc<Auth>>, visitor: Visitor, Query(q): Query<HashMap<String, String>>) -> Response {
    company_list(&core, &visitor, q.get("term").map_or("", String::as_str), limit(&q)).await
}

async fn skus(State(core): State<Arc<Auth>>, visitor: Visitor, Query(q): Query<HashMap<String, String>>) -> Response {
    sku_list(&core, &visitor, q.get("term").map_or("", String::as_str), limit(&q)).await
}

/// user ajax_source_company_name(): company names containing every word of the term.
async fn company_list(core: &Auth, visitor: &Visitor, term: &str, limit: i64) -> Response {
    let result = async {
        if !staff(core, visitor).await? {
            return Ok(None);
        }
        let words: Vec<String> = term.split_whitespace().map(str::to_lowercase).collect();
        let first = format!("%{}%", words.first().cloned().unwrap_or_default());
        let names = sqlx::query_scalar!(
            r#"SELECT company_name AS "name!" FROM "user" WHERE company_name IS NOT NULL AND company_name LIKE ?1
               GROUP BY company_name ORDER BY company_name, min(CASE WHEN domain IS NULL THEN 1 ELSE 0 END)"#,
            first
        )
        .fetch_all(&core.pool)
        .await?;
        let list: Vec<Value> = names.into_iter().filter(|n| words.iter().all(|w| n.to_lowercase().contains(w))).take(limit as usize).map(|n| json!({"value": n})).collect();
        Ok::<_, sqlx::Error>(Some(list))
    }
    .await;
    respond(result)
}

/// inventory ajax_sku(): active part numbers starting with the term, with their category and family.
async fn sku_list(core: &Auth, visitor: &Visitor, term: &str, limit: i64) -> Response {
    let result = async {
        if !staff(core, visitor).await? {
            return Ok(None);
        }
        let prefix = format!("{}%", term.split_whitespace().next().unwrap_or(""));
        let rows = sqlx::query!(
            r#"SELECT i.sku AS "sku!", c.name AS category_name, s.name AS subcategory_name, i.category_id, i.subcategory_id
               FROM inventory i LEFT JOIN subcategory s ON s.id = i.subcategory_id LEFT JOIN category c ON c.id = i.category_id
               WHERE i.sku LIKE ?1 AND i.active = 1 ORDER BY i.sku LIMIT ?2"#,
            prefix,
            limit
        )
        .fetch_all(&core.pool)
        .await?;
        let list = rows
            .into_iter()
            .map(|r| {
                json!({"label": r.sku, "category_name": r.category_name, "subcategory_name": r.subcategory_name,
                       "category_subcategory_id": format!("{}:{}", r.category_id.unwrap_or(0), r.subcategory_id.unwrap_or(0))})
            })
            .collect();
        Ok::<_, sqlx::Error>(Some(list))
    }
    .await;
    respond(result)
}

fn respond(result: sqlx::Result<Option<Vec<Value>>>) -> Response {
    match result {
        Ok(Some(list)) => answer(StatusCode::OK, Value::Array(list)),
        Ok(None) => answer(StatusCode::FORBIDDEN, json!({"error": "Access denied"})),
        Err(e) => {
            tracing::error!("scs_ajax: {e}");
            answer(StatusCode::INTERNAL_SERVER_ERROR, json!({"error": "Internal"}))
        }
    }
}
