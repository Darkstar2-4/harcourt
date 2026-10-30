//! Owner: the orders agent. Routes for cart, checkout, orders, quotes, punch-out, contact and request forms, and the mail sender that drains the outbox.
//! Register every route here and nowhere else; mod.rs only merges this router.

use std::sync::Arc;

use axum::Router;

use crate::api::Auth;

pub fn router(core: Arc<Auth>) -> Router {
    let _ = core;
    Router::new()
}
