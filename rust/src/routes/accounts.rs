//! Owner: the accounts agent. Routes for sign-in, sign-up, OAuth, impersonation, cookies, bots, campaigns, language and currency choice.
//! Register every route here and nowhere else; mod.rs only merges this router.

use std::sync::Arc;

use axum::Router;

use crate::api::Auth;

pub fn router(core: Arc<Auth>) -> Router {
    let _ = core;
    Router::new()
}
