//! Owner: the accounts agent. Routes for sign-up and request-access, OAuth sign-in, sign-out and the campaign page.
//! Register every route here and nowhere else; mod.rs only merges this router.

use std::sync::Arc;

use axum::{routing::get, Router};

use crate::{
    accounts::{campaign_page, oauth, signout, signup, static_dirs},
    api::Auth,
};

pub fn router(core: Arc<Auth>) -> Router {
    Router::new()
        .route("/newuser.php", get(signup::page).post(signup::action))
        .route("/campaign.php", get(campaign_page::page).post(campaign_page::post))
        .route("/cookie_reset.php", get(signout::cookie_reset).post(signout::cookie_reset))
        .route("/oauth/init.php", get(oauth::microsoft_init))
        .route("/oauth/callback.php", get(oauth::microsoft_callback))
        .route("/oauth/google_init.php", get(oauth::google_init))
        .route("/oauth/google_callback.php", get(oauth::google_callback))
        .merge(static_dirs::routes())
        .with_state(core)
}
