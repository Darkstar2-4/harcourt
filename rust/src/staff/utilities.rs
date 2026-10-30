use std::sync::Arc;

use axum::Router;

use crate::api::Auth;

pub fn routes() -> Router<Arc<Auth>> {
    Router::new()
}
