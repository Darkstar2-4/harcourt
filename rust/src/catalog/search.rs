//! The catalog search box: the index built from the search table, the visitor's search scope and the typed autocomplete endpoints that replace scs_ajax.php.

use std::sync::Arc;

use axum::Router;

use crate::api::Auth;

pub fn router() -> Router<Arc<Auth>> {
    Router::new()
}
