//! Orders: the cart, checkout and the order viewer (`shop`), the site's forms (`forms`), the other order-side pages
//! (`pages`), and outgoing mail (`mail`, `mailform`). Every route takes `Arc<Orders>` as its state; the session
//! extractors reach the shared `Auth` through it.

use std::{convert::Infallible, path::PathBuf, sync::Arc};

use axum::{
    extract::{FromRequestParts, Request, State},
    http::{header, request::Parts, Method, StatusCode},
    middleware::{self, Next},
    response::{IntoResponse, Response},
    Router,
};

use crate::{
    api::{ApiError, Auth, Authed, ClientIp, MinRole},
    identity::Role,
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
        .merge(forms::routes())
        .merge(pages::routes())
        .layer(middleware::from_fn_with_state(state.clone(), same_origin))
        .with_state(state)
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

impl<R: MinRole> FromRequestParts<Arc<Orders>> for Authed<R> {
    type Rejection = ApiError;

    async fn from_request_parts(parts: &mut Parts, state: &Arc<Orders>) -> Result<Self, ApiError> {
        Authed::<R>::from_request_parts(parts, &state.core).await
    }
}

pub struct Executive;
pub struct SuperUser;
impl MinRole for Executive {
    const ROLE: Role = Role::Executive;
}
impl MinRole for SuperUser {
    const ROLE: Role = Role::SuperUser;
}

/// The gate of a staff page: `Authed<R>`, answered on refusal the way PHP's `$database->user->access()` does, with
/// a redirect to the home page.
pub struct Staff<R: MinRole>(pub Authed<R>);

impl<R: MinRole> FromRequestParts<Arc<Orders>> for Staff<R> {
    type Rejection = Response;

    async fn from_request_parts(parts: &mut Parts, state: &Arc<Orders>) -> Result<Self, Response> {
        match Authed::<R>::from_request_parts(parts, &state.core).await {
            Ok(a) => Ok(Staff(a)),
            Err(ApiError::Unauthenticated | ApiError::Forbidden) => Err(html::redirect("/")),
            Err(e) => Err(e.into_response()),
        }
    }
}

impl FromRequestParts<Arc<Orders>> for ClientIp {
    type Rejection = Infallible;

    async fn from_request_parts(parts: &mut Parts, state: &Arc<Orders>) -> Result<Self, Infallible> {
        ClientIp::from_request_parts(parts, &state.core).await
    }
}
