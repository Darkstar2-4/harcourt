//! The shared request state (`Auth`), the session extractor every signed-in page goes through (`Authed<Role>`), the
//! client address rule, and the one JSON route: redeeming a remote grant (POST /api/remote/redeem).

use std::{convert::Infallible, marker::PhantomData, net::{IpAddr, SocketAddr}, sync::Arc};

use axum::{
    extract::{connect_info::MockConnectInfo, ConnectInfo, DefaultBodyLimit, FromRequestParts, Request, State},
    http::{header, request::Parts, HeaderMap, HeaderValue, Method, StatusCode},
    middleware::{self, Next},
    response::{IntoResponse, Response},
    routing::post,
    Json, Router,
};
use serde::Deserialize;
use sqlx::SqlitePool;

use crate::{
    audit::{self, Entry},
    config::Secret,
    db::unix_now,
    identity::{self, Role, User},
    remote_grant, session,
    token::Token,
};

pub struct Auth {
    pub pool: SqlitePool,
    /// Signs the emailed sign-in codes (newuser.php); without it no code is sent.
    pub secret: Option<Secret>,
    pub public_origin: String,
    pub trusted_proxies: Vec<IpAddr>,
    pub accounts: crate::accounts::Services,
}

pub fn router(auth: Arc<Auth>) -> Router {
    Router::new()
        .route("/remote/redeem", post(redeem))
        .layer(DefaultBodyLimit::max(4096))
        .layer(middleware::from_fn_with_state(auth.clone(), same_origin))
        .with_state(auth)
}

/// What /api answers while the database or settings are missing: the site stays up, the API says so.
pub fn unavailable() -> Router {
    Router::new().fallback(|| async { ApiError::Unavailable })
}

#[derive(Debug)]
pub enum ApiError {
    Unauthenticated,
    Forbidden,
    BadOrigin,
    Unavailable,
    Internal,
}

impl IntoResponse for ApiError {
    fn into_response(self) -> Response {
        let (status, error) = match self {
            ApiError::Unauthenticated => (StatusCode::UNAUTHORIZED, "unauthenticated"),
            ApiError::Forbidden => (StatusCode::FORBIDDEN, "forbidden"),
            ApiError::BadOrigin => (StatusCode::FORBIDDEN, "bad_origin"),
            ApiError::Unavailable => (StatusCode::SERVICE_UNAVAILABLE, "unavailable"),
            ApiError::Internal => (StatusCode::INTERNAL_SERVER_ERROR, "internal"),
        };
        let mut response = (status, Json(serde_json::json!({ "error": error }))).into_response();
        response.headers_mut().insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
        response
    }
}

impl From<sqlx::Error> for ApiError {
    fn from(e: sqlx::Error) -> Self {
        tracing::error!("database error: {e}");
        ApiError::Internal
    }
}

/// A browser POST from another site carries its own Origin. Refuse it; the SameSite cookie is the second wall.
async fn same_origin(State(auth): State<Arc<Auth>>, req: Request, next: Next) -> Response {
    let reads = matches!(*req.method(), Method::GET | Method::HEAD);
    if !reads {
        if let Some(origin) = req.headers().get(header::ORIGIN) {
            if origin.as_bytes() != auth.public_origin.as_bytes() {
                return ApiError::BadOrigin.into_response();
            }
        }
    }
    next.run(req).await
}

/// The visitor's address for the log. The socket peer, unless the peer is one of our own proxies, in which case the
/// X-Real-IP it set. A header from anyone else is ignored.
pub struct ClientIp(pub String);

impl FromRequestParts<Arc<Auth>> for ClientIp {
    type Rejection = Infallible;

    async fn from_request_parts(parts: &mut Parts, auth: &Arc<Auth>) -> Result<Self, Infallible> {
        Ok(ClientIp(client_addr(parts, &auth.trusted_proxies).map_or_else(|| "unknown".into(), |ip| ip.to_string())))
    }
}

/// The rule behind `ClientIp`, for callers that need the address itself. IPv4 peers seen through an IPv6 socket
/// come back as plain IPv4, the form the `user.ip` column holds.
pub fn client_addr(parts: &Parts, trusted_proxies: &[IpAddr]) -> Option<IpAddr> {
    let peer = parts
        .extensions
        .get::<ConnectInfo<SocketAddr>>()
        .map(|c| c.0)
        .or_else(|| parts.extensions.get::<MockConnectInfo<SocketAddr>>().map(|m| m.0))?
        .ip()
        .to_canonical();
    let ip = if trusted_proxies.contains(&peer) {
        parts.headers.get("x-real-ip").and_then(|v| v.to_str().ok()).and_then(|s| s.trim().parse::<IpAddr>().ok()).unwrap_or(peer)
    } else {
        peer
    };
    Some(ip.to_canonical())
}

pub trait MinRole {
    const ROLE: Role;
}
pub struct Administrator;
impl MinRole for Administrator {
    const ROLE: Role = Role::Administrator;
}
pub struct Internal;
impl MinRole for Internal {
    const ROLE: Role = Role::Internal;
}
pub struct Executive;
impl MinRole for Executive {
    const ROLE: Role = Role::Executive;
}

/// A request that carries a live session of an Active account whose role is at least `R`.
pub struct Authed<R: MinRole> {
    pub user: User,
    pub email: Option<String>,
    role: PhantomData<R>,
}

impl<R: MinRole> FromRequestParts<Arc<Auth>> for Authed<R> {
    type Rejection = ApiError;

    async fn from_request_parts(parts: &mut Parts, auth: &Arc<Auth>) -> Result<Self, ApiError> {
        let token = session_cookie(&parts.headers).ok_or(ApiError::Unauthenticated)?;
        let found = session::lookup(&auth.pool, &token, unix_now()).await?.ok_or(ApiError::Unauthenticated)?;
        if !found.user.is_active() {
            return Err(ApiError::Unauthenticated);
        }
        if found.user.role < R::ROLE {
            return Err(ApiError::Forbidden);
        }
        Ok(Authed { user: found.user, email: found.email, role: PhantomData })
    }
}

pub fn session_cookie(headers: &HeaderMap) -> Option<Token> {
    headers
        .get_all(header::COOKIE)
        .iter()
        .filter_map(|v| v.to_str().ok())
        .flat_map(|v| v.split(';'))
        .filter_map(|pair| pair.trim().split_once('='))
        .find_map(|(name, value)| (name == session::COOKIE).then(|| Token::parse(value)).flatten())
}

fn signed_in(token: &Token) -> Result<Response, ApiError> {
    let cookie = format!("{}={}; Path=/; Max-Age={}; Secure; HttpOnly; SameSite=Lax", session::COOKIE, token.as_str(), session::TTL_SECS);
    let mut response = StatusCode::NO_CONTENT.into_response();
    response.headers_mut().insert(header::SET_COOKIE, HeaderValue::from_str(&cookie).map_err(|_| ApiError::Internal)?);
    response.headers_mut().insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
    Ok(response)
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct RedeemRequest {
    token: String,
}

/// The grant token is the credential here: only staff can mint one, it is random, and it works once.
async fn redeem(State(auth): State<Arc<Auth>>, ClientIp(ip): ClientIp, Json(body): Json<RedeemRequest>) -> Result<Response, ApiError> {
    let now = unix_now();
    let redeemed = match Token::parse(&body.token) {
        Some(token) => remote_grant::redeem(&auth.pool, &token, now).await?,
        None => None,
    };
    let account = match redeemed {
        Some(id) => identity::load_user(&auth.pool, id).await?.filter(User::is_active),
        None => None,
    };
    let Some(account) = account else {
        audit::record(&auth.pool, Entry { subtype: "remote_redeem_failed", user_id: 0, email: None, ip: &ip, comment: "" }).await;
        return Err(ApiError::Unauthenticated);
    };
    let token = session::create(&auth.pool, account.id, None, now).await?;
    audit::record(&auth.pool, Entry { subtype: "remote_redeemed", user_id: account.id.0, email: None, ip: &ip, comment: "" }).await;
    signed_in(&token)
}
