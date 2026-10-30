//! Owner: the accounts agent. Routes for sign-in, sign-up, OAuth, impersonation, cookies, bots, campaigns, language and currency choice.
//! Register every route here and nowhere else; mod.rs only merges this router.

use std::sync::Arc;

use axum::{
    routing::get,
    Router,
};

use crate::{
    accounts::{oauth, signup},
    api::Auth,
};

pub fn router(core: Arc<Auth>) -> Router {
    Router::new()
        .route("/newuser.php", get(signup::page).post(signup::action))
        .route("/oauth/init.php", get(oauth::microsoft_init))
        .route("/oauth/callback.php", get(oauth::microsoft_callback))
        .route("/oauth/google_init.php", get(oauth::google_init))
        .route("/oauth/google_callback.php", get(oauth::google_callback))
        .with_state(core)
}
