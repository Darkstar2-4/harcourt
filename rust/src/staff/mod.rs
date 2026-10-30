//! The staff side of the site: the dashboard, the maintenance pages, the event log, remote-grant management and
//! the scheduled jobs. Pages render here in Rust with the markup of the PHP they replace (html.rs, shell.rs).
//!
//! Every handler takes `Staff<R>`, which holds the `Authed<R>` of a live session at role R or above, with the
//! same minimum role the PHP page checked with `user->access()`. Every form posts a CSRF token (csrf.rs).

use std::{net::IpAddr, sync::Arc};

use axum::{
    extract::FromRequestParts,
    http::{header, request::Parts, StatusCode},
    response::{IntoResponse, Response},
    Router,
};

use crate::{
    api::{self, ApiError, Auth, Authed, MinRole},
    token::TokenHash,
    visitor::Visitor,
};

pub mod address;
pub mod catalog;
pub mod check;
pub mod choices;
pub mod csrf;
pub mod filesort;
pub mod html;
pub mod maintain;
pub mod phpser;
pub mod shell;
pub mod time;

pub mod company;
pub mod dashboard;
pub mod grants;
pub mod increase;
pub mod inventory;
pub mod jobs;
pub mod log;
pub mod mailer;
pub mod portal;
pub mod portalcategory;
pub mod portalitem;
pub mod prj;
pub mod subcategory_order;
pub mod user;

/// Where `user->access()` sent a visitor without the role: `$menu->page->home`, which is `/` inside WordPress.
pub const HOME: &str = "/scs_index.php";
pub const WP_HOME: &str = "/";

/// `Authed<R>` for a page. A visitor without a session, or below R, is redirected home as the PHP did, instead of
/// the JSON 401/403 the API answers. Carries the session's CSRF token for the page's form, the session itself (what
/// a page keeps between requests hangs off it, as PHP's `$_SESSION` did), the client address, and whether the visit
/// is inside WordPress (`?scscpq_wp=1` on this or an earlier page, as the visitor layer keeps it).
pub struct Staff<R: MinRole> {
    pub who: Authed<R>,
    pub csrf: csrf::CsrfToken,
    pub session: TokenHash,
    pub client: Option<IpAddr>,
    pub wp: bool,
}

impl<R: MinRole> Staff<R> {
    pub fn view(&self) -> shell::View {
        shell::View { role: self.who.user.role, wp: self.wp }
    }
}

fn in_wordpress(parts: &Parts) -> bool {
    let wp = csrf::Fields::from_query(parts.uri.query());
    !matches!(wp.get("scscpq_wp"), "" | "0")
}

impl<R: MinRole + Send + Sync + 'static> FromRequestParts<Arc<Auth>> for Staff<R> {
    type Rejection = Response;

    async fn from_request_parts(parts: &mut Parts, auth: &Arc<Auth>) -> Result<Self, Response> {
        let visitor = parts.extensions.get::<Visitor>();
        let wp = in_wordpress(parts) || visitor.is_some_and(|v| v.framed);
        let client = visitor.map_or_else(|| api::client_addr(parts, &auth.trusted_proxies), |v| v.client);
        let who = match Authed::<R>::from_request_parts(parts, auth).await {
            Ok(who) => who,
            Err(ApiError::Unauthenticated | ApiError::Forbidden) => return Err(go_home(wp)),
            Err(other) => return Err(other.into_response()),
        };
        let token = api::session_cookie(&parts.headers).ok_or_else(|| go_home(wp))?;
        let csrf = csrf::CsrfToken::for_request(&parts.headers).ok_or_else(|| go_home(wp))?;
        Ok(Staff { who, csrf, session: token.hash(), client, wp })
    }
}

fn go_home(wp: bool) -> Response {
    let home = if wp { WP_HOME } else { HOME };
    (StatusCode::FOUND, [(header::LOCATION, home), (header::CONTENT_TYPE, "text/html; charset=utf-8"), (header::CACHE_CONTROL, "no-store")]).into_response()
}

pub fn router() -> Router<Arc<Auth>> {
    Router::new()
        .route("/mailer_maintain.php", maintain::route::<mailer::Mailform>())
        .merge(company::routes())
        .merge(dashboard::routes())
        .merge(grants::routes())
        .merge(increase::routes())
        .merge(inventory::routes())
        .merge(log::routes())
        .merge(portal::routes())
        .merge(portalcategory::routes())
        .merge(portalitem::routes())
        .merge(prj::routes())
        .merge(subcategory_order::routes())
        .merge(user::routes())
}
