//! Owner: the accounts agent. Routes for sign-in, sign-up, OAuth, impersonation, cookies, bots, campaigns, language and currency choice.
//! Register every route here and nowhere else; mod.rs only merges this router.

use std::sync::Arc;

use axum::{
    routing::get,
    Router,
};

use crate::{
    accounts::{campaign_page, choice, impersonate, login, manage, oauth, signup, static_dirs},
    api::Auth,
};

pub fn router(core: Arc<Auth>) -> Router {
    Router::new()
        .route("/newuser.php", get(signup::page).post(signup::action))
        .route("/campaign.php", get(campaign_page::page).post(campaign_page::post))
        .route("/login.php", get(login::page).post(login::page))
        .route("/user_impersonate.php", get(impersonate::page).post(impersonate::submit))
        .route("/language_change.php", get(choice::language_change))
        .route("/cookie_reset.php", get(choice::cookie_reset).post(choice::cookie_reset))
        .route("/oauth/init.php", get(oauth::microsoft_init))
        .route("/oauth/callback.php", get(oauth::microsoft_callback))
        .route("/oauth/google_init.php", get(oauth::google_init))
        .route("/oauth/google_callback.php", get(oauth::google_callback))
        .merge(manage::router())
        .merge(static_dirs::routes())
        .with_state(core)
}
