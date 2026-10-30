//! The accounts area's staff pages: currency and language codes, bots, the sign-up blacklist and reCAPTCHA bypass
//! list, and the company remote-access settings. They are built on the staff helpers (`Staff<R>`, `Posted`,
//! `maintain`, `shell`) and answer like the other staff pages.

use std::sync::Arc;

use axum::Router;

use crate::{api::Auth, staff::maintain};

pub mod bot;
pub mod bot_impersonate;
pub mod currency;
pub mod language;
pub mod listed;
pub mod remote_access;
pub mod remote_token;

pub fn router() -> Router<Arc<Auth>> {
    Router::new()
        .route("/currency.php", maintain::route::<currency::Currency>())
        .route("/language.php", maintain::route::<language::Language>())
        .route("/bot.php", maintain::route::<bot::Bot>())
        .route("/blacklist.php", listed::route::<listed::Blacklist>())
        .route("/captcha_bypass.php", listed::route::<listed::CaptchaBypass>())
        .route("/remote_access.php", remote_access::route())
        .route("/remote_token.php", remote_token::route())
        .route("/bot_impersonate.php", bot_impersonate::route())
}
