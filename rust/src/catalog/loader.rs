//! scscpq_wp.php: the JSON the site's pages ask for to decide which blocks to show, and scscpq_api.php.

use std::sync::Arc;

use axum::Router;

use crate::api::Auth;

pub fn router() -> Router<Arc<Auth>> {
    Router::new()
}
