//! catalog.php's views (home, subcategory, part, related, search results, lookup, portal, document viewer) and the /catalog/ pages around them.

use std::sync::Arc;

use axum::Router;

use crate::api::Auth;

pub fn router() -> Router<Arc<Auth>> {
    Router::new()
}
