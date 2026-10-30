//! The staff side of the site: the dashboard, the maintenance pages, the event log, remote-grant management and
//! the scheduled jobs. Pages render here in Rust with the markup of the PHP they replace (html.rs, shell.rs).
//!
//! Every handler takes `Staff<R>`, which holds the `Authed<R>` of a live session at role R or above, with the
//! same minimum role the PHP page checked with `user->access()`. Every form posts a CSRF token (csrf.rs).

use std::sync::Arc;

use axum::{
    extract::FromRequestParts,
    http::{header, request::Parts, StatusCode},
    response::{IntoResponse, Response},
    Router,
};

use crate::api::{ApiError, Auth, Authed, MinRole};

pub mod check;
pub mod csrf;
pub mod html;
pub mod mailer;
pub mod maintain;
pub mod shell;

/// Where `user->access()` sent a visitor without the role: `$menu->page->home` outside WordPress.
pub const HOME: &str = "/scs_index.php";

/// `Authed<R>` for a page. A visitor without a session, or below R, is redirected home as the PHP did, instead of
/// the JSON 401/403 the API answers. Carries the session's CSRF token for the page's form.
pub struct Staff<R: MinRole> {
    pub who: Authed<R>,
    pub csrf: csrf::CsrfToken,
}

impl<R: MinRole + Send + Sync + 'static> FromRequestParts<Arc<Auth>> for Staff<R> {
    type Rejection = Response;

    async fn from_request_parts(parts: &mut Parts, auth: &Arc<Auth>) -> Result<Self, Response> {
        let who = match Authed::<R>::from_request_parts(parts, auth).await {
            Ok(who) => who,
            Err(ApiError::Unauthenticated | ApiError::Forbidden) => return Err(go_home()),
            Err(other) => return Err(other.into_response()),
        };
        let csrf = csrf::CsrfToken::for_request(&parts.headers).ok_or_else(go_home)?;
        Ok(Staff { who, csrf })
    }
}

fn go_home() -> Response {
    (StatusCode::FOUND, [(header::LOCATION, HOME), (header::CACHE_CONTROL, "no-store")]).into_response()
}

pub fn router() -> Router<Arc<Auth>> {
    Router::new().route("/mailer_maintain.php", maintain::route::<mailer::Mailform>())
}
