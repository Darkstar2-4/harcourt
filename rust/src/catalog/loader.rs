//! scscpq_wp.php: the JSON the site's pages ask for to decide which blocks to show, and scscpq_api.php.

use std::sync::Arc;

use axum::Router;
use sqlx::SqlitePool;

use super::viewer::Viewer;
use crate::api::Auth;

pub fn router() -> Router<Arc<Auth>> {
    Router::new()
}

/// What a page posts with action=initial.
pub struct Initial<'a> {
    pub page: &'a str,
    pub document: &'a str,
    pub token: &'a str,
    pub url: &'a str,
}

/// The action=initial answer for this viewer, in the viewer's language. The /catalog/ composer inlines it in the
/// page as `window.hxweb_loader`.
pub async fn initial(pool: &SqlitePool, viewer: &Viewer, req: &Initial<'_>) -> sqlx::Result<serde_json::Value> {
    let _ = (pool, viewer, req);
    Ok(serde_json::json!({}))
}
