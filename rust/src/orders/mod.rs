//! Orders: the cart, checkout and the order viewer (`shop`), the site's forms (`forms`), the other order-side pages
//! (`pages`), and outgoing mail (`mail`, `mailform`). Every route takes `Arc<Orders>` as its state; the session
//! extractors reach the shared `Auth` through it.

use std::{collections::HashMap, convert::Infallible, marker::PhantomData, path::PathBuf, sync::Arc};

use percent_encoding::percent_decode_str;

use axum::{
    extract::{FromRequestParts, Request, State},
    http::{header, request::Parts, Method, StatusCode},
    middleware::{self, Next},
    response::{IntoResponse, Response},
    Router,
};

use crate::{
    api::{ApiError, Auth, ClientIp, MinRole},
    identity::{Role, User},
    visitor::Visitor,
};

pub mod forms;
pub mod html;
pub mod mail;
pub mod mailform;
pub mod pages;
pub mod pricing;
pub mod shop;

pub struct Orders {
    pub core: Arc<Auth>,
    pub pricing: Arc<dyn pricing::Pricing>,
    /// The PHP document root: mail stylesheets and other files the pages read from disk.
    pub docroot: PathBuf,
    pub forms: forms::Services,
}

impl Orders {
    pub fn from_env(core: Arc<Auth>) -> Self {
        let get = |k: &str| std::env::var(k).ok();
        Orders {
            core,
            pricing: Arc::new(pricing::Unpriced),
            docroot: PathBuf::from(get("HXWEB_DOCROOT").unwrap_or_else(|| "/home/harcou6/public_html".into())),
            forms: forms::Services::from_env(get),
        }
    }
}

pub fn router(state: Arc<Orders>) -> Router {
    Router::new()
        .merge(shop::routes())
        .merge(pages::routes())
        .route_layer(middleware::from_fn_with_state(state.clone(), first_visit))
        .merge(forms::routes())
        .layer(middleware::from_fn_with_state(state.clone(), same_origin))
        .with_state(state)
}

/// Every SCS page loaded scs_header.php, which sends a visit that begins on it to the visitor's home page first.
async fn first_visit(State(state): State<Arc<Orders>>, req: Request, next: Next) -> Response {
    let (mut parts, body) = req.into_parts();
    let visitor = Visitor::from_request_parts(&mut parts, &state).await.unwrap_or_else(|e| match e {});
    match crate::visitor::landing(&state.core, &visitor, parts.uri.path(), parts.uri.query(), &parts.headers).await {
        Ok(Some(home)) => home,
        Ok(None) => next.run(Request::from_parts(parts, body)).await,
        Err(e) => ApiError::from(e).into_response(),
    }
}

/// A form posted from another site carries that site's Origin. Refuse it; the SameSite session cookie is the second
/// wall. Requests without an Origin (old clients, server-to-server punch-out) pass to the route's own checks.
async fn same_origin(State(state): State<Arc<Orders>>, req: Request, next: Next) -> Response {
    let reads = matches!(*req.method(), Method::GET | Method::HEAD);
    let foreign = req.headers().get(header::ORIGIN).is_some_and(|o| o.as_bytes() != state.core.public_origin.as_bytes());
    if !reads && foreign {
        return (StatusCode::FORBIDDEN, "Forbidden").into_response();
    }
    next.run(req).await
}

impl FromRequestParts<Arc<Orders>> for Visitor {
    type Rejection = Infallible;

    async fn from_request_parts(parts: &mut Parts, state: &Arc<Orders>) -> Result<Self, Infallible> {
        Visitor::from_request_parts(parts, &state.core).await
    }
}

/// PHP's `$_REQUEST` for a urlencoded request: query fields, then body fields over them; the last repeat wins.
pub fn form_params(query: Option<&str>, body: &[u8]) -> HashMap<String, String> {
    let decode = |s: &str| percent_decode_str(&s.replace('+', " ")).decode_utf8_lossy().into_owned();
    let mut out = HashMap::new();
    let body = String::from_utf8_lossy(body);
    for part in query.unwrap_or("").split('&').chain(body.split('&')).filter(|p| !p.is_empty()) {
        let (k, v) = part.split_once('=').unwrap_or((part, ""));
        out.insert(decode(k), decode(v));
    }
    out
}

pub struct Executive;
pub struct SuperUser;
impl MinRole for Executive {
    const ROLE: Role = Role::Executive;
}
impl MinRole for SuperUser {
    const ROLE: Role = Role::SuperUser;
}

/// The gate of a staff page, PHP's `$database->user->access()`: the visitor's account, signed in by session or by
/// company address, at role R or above. Refused, it redirects home (`/` inside the WordPress frame, `/scs_index.php`
/// outside it). Unlike the PHP, which read the role alone, the account must also be Active.
pub struct Staff<R: MinRole> {
    pub user: User,
    role: PhantomData<R>,
}

impl<R: MinRole> FromRequestParts<Arc<Orders>> for Staff<R> {
    type Rejection = Response;

    async fn from_request_parts(parts: &mut Parts, state: &Arc<Orders>) -> Result<Self, Response> {
        let visitor = Visitor::from_request_parts(parts, state).await.unwrap_or_else(|e| match e {});
        match visitor.user().filter(|u| u.is_active() && u.role >= R::ROLE) {
            Some(user) => Ok(Staff { user: user.clone(), role: PhantomData }),
            None => Err(html::redirect(if html::framed(parts.uri.query(), &parts.headers) { "/" } else { "/scs_index.php" })),
        }
    }
}

impl FromRequestParts<Arc<Orders>> for ClientIp {
    type Rejection = Infallible;

    async fn from_request_parts(parts: &mut Parts, state: &Arc<Orders>) -> Result<Self, Infallible> {
        ClientIp::from_request_parts(parts, &state.core).await
    }
}
