//! Owner: the wordpress agent. Routes for what WordPress did: page templates, menus, forms, SEO, redirects, editable page text, and retiring wp-login, xmlrpc and wp-admin.
//! Register every route here and nowhere else; mod.rs only merges this router.

use std::sync::Arc;

use axum::Router;

use crate::api::Auth;

pub fn router(core: Arc<Auth>) -> Router {
    let _ = core;
    Router::new()
}
