//! JSON routes under /api. Every route that acts for a signed-in person takes `Authed<Role>` as an argument, so a
//! handler cannot be written that reaches a session without going through the extractor that checks it.

use std::{convert::Infallible, marker::PhantomData, net::{IpAddr, SocketAddr}, sync::Arc};

use axum::{
    extract::{ConnectInfo, DefaultBodyLimit, FromRequestParts, Request, State},
    http::{header, request::Parts, HeaderMap, HeaderValue, Method, StatusCode},
    middleware::{self, Next},
    response::{IntoResponse, Response},
    routing::{get, post},
    Json, Router,
};
use serde::{Deserialize, Serialize};
use sqlx::SqlitePool;

use crate::{
    access_code,
    audit::{self, Entry},
    config::Secret,
    db::unix_now,
    identity::{self, Email, Role, User, UserId},
    remote_grant, session,
    token::Token,
};

pub struct Auth {
    pub pool: SqlitePool,
    pub secret: Option<Secret>,
    pub public_origin: String,
    pub trusted_proxies: Vec<IpAddr>,
}

pub fn router(auth: Arc<Auth>) -> Router {
    Router::new()
        .route("/auth/code", post(request_code))
        .route("/auth/verify", post(verify_code))
        .route("/auth/logout", post(logout))
        .route("/me", get(me))
        .route("/remote/grants", post(grant))
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
    InvalidCode,
    BadOrigin,
    Unavailable,
    Internal,
}

impl IntoResponse for ApiError {
    fn into_response(self) -> Response {
        let (status, error) = match self {
            ApiError::Unauthenticated => (StatusCode::UNAUTHORIZED, "unauthenticated"),
            ApiError::Forbidden => (StatusCode::FORBIDDEN, "forbidden"),
            ApiError::InvalidCode => (StatusCode::UNAUTHORIZED, "invalid_code"),
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
        let Some(peer) = parts.extensions.get::<ConnectInfo<SocketAddr>>().map(|c| c.0.ip()) else {
            return Ok(ClientIp("unknown".into()));
        };
        let ip = if auth.trusted_proxies.contains(&peer) {
            parts.headers.get("x-real-ip").and_then(|v| v.to_str().ok()).and_then(|s| s.trim().parse::<IpAddr>().ok()).unwrap_or(peer)
        } else {
            peer
        };
        Ok(ClientIp(ip.to_string()))
    }
}

pub trait MinRole {
    const ROLE: Role;
}
pub struct AnyUser;
pub struct Administrator;
impl MinRole for AnyUser {
    const ROLE: Role = Role::Customer;
}
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
pub struct SuperUser;
impl MinRole for SuperUser {
    const ROLE: Role = Role::SuperUser;
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

fn cookie_header(value: &str, max_age: i64) -> Result<HeaderValue, ApiError> {
    HeaderValue::from_str(&format!("{}={value}; Path=/; Max-Age={max_age}; Secure; HttpOnly; SameSite=Lax", session::COOKIE))
        .map_err(|_| ApiError::Internal)
}

fn signed_in(token: &Token) -> Result<Response, ApiError> {
    let mut response = StatusCode::NO_CONTENT.into_response();
    response.headers_mut().insert(header::SET_COOKIE, cookie_header(token.as_str(), session::TTL_SECS)?);
    response.headers_mut().insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
    Ok(response)
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct CodeRequest {
    email: String,
}

/// Always 202, whether or not the address belongs to a company we know, so the route cannot be used to find out.
async fn request_code(State(auth): State<Arc<Auth>>, ClientIp(ip): ClientIp, Json(body): Json<CodeRequest>) -> Result<StatusCode, ApiError> {
    let secret = auth.secret.as_ref().ok_or(ApiError::Unavailable)?;
    if let Some(email) = Email::parse(&body.email) {
        if let Some(company) = identity::find_active_company_by_domain(&auth.pool, email.domain()).await? {
            if access_code::issue(&auth.pool, secret, &email, unix_now()).await? {
                audit::record(&auth.pool, Entry { subtype: "code_sent", user_id: company.0, email: Some(email.as_str()), ip: &ip, comment: "" }).await;
            }
        }
    }
    Ok(StatusCode::ACCEPTED)
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct VerifyRequest {
    email: String,
    code: String,
}

async fn verify_code(State(auth): State<Arc<Auth>>, ClientIp(ip): ClientIp, Json(body): Json<VerifyRequest>) -> Result<Response, ApiError> {
    let secret = auth.secret.as_ref().ok_or(ApiError::Unavailable)?;
    let now = unix_now();
    let email = Email::parse(&body.email);
    let verified = match &email {
        Some(e) => access_code::verify(&auth.pool, secret, e, &body.code, now).await?,
        None => false,
    };
    let company = match (&email, verified) {
        (Some(e), true) => identity::find_active_company_by_domain(&auth.pool, e.domain()).await?,
        _ => None,
    };
    let (Some(email), Some(company)) = (email, company) else {
        audit::record(&auth.pool, Entry { subtype: "code_failed", user_id: 0, email: None, ip: &ip, comment: "" }).await;
        return Err(ApiError::InvalidCode);
    };
    let token = session::create(&auth.pool, company, Some(email.as_str()), now).await?;
    audit::record(&auth.pool, Entry { subtype: "login", user_id: company.0, email: Some(email.as_str()), ip: &ip, comment: "emailed code" }).await;
    signed_in(&token)
}

async fn logout(State(auth): State<Arc<Auth>>, headers: HeaderMap) -> Result<Response, ApiError> {
    if let Some(token) = session_cookie(&headers) {
        session::destroy(&auth.pool, &token).await?;
    }
    let mut response = StatusCode::NO_CONTENT.into_response();
    response.headers_mut().insert(header::SET_COOKIE, cookie_header("", 0)?);
    Ok(response)
}

#[derive(Serialize)]
struct MeResponse {
    user_id: i64,
    role: &'static str,
    company: Option<String>,
    email: Option<String>,
}

async fn me(who: Authed<AnyUser>) -> Json<MeResponse> {
    Json(MeResponse { user_id: who.user.id.0, role: who.user.role.as_str(), company: who.user.company_name, email: who.email })
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct GrantRequest {
    user_id: i64,
}

#[derive(Serialize)]
struct GrantResponse {
    token: String,
    expires_at: i64,
}

/// Missing and out-of-reach targets get the same answer, so ids cannot be probed through this route.
async fn grant(
    State(auth): State<Arc<Auth>>,
    ClientIp(ip): ClientIp,
    actor: Authed<Administrator>,
    Json(body): Json<GrantRequest>,
) -> Result<(StatusCode, Json<GrantResponse>), ApiError> {
    let target = identity::load_user(&auth.pool, UserId(body.user_id)).await?;
    let Some(target) = target.filter(|t| remote_grant::may_grant(&actor.user, t)) else {
        audit::record(&auth.pool, Entry { subtype: "remote_grant_denied", user_id: actor.user.id.0, email: actor.email.as_deref(), ip: &ip, comment: "" }).await;
        return Err(ApiError::Forbidden);
    };
    let (token, expires_at) = remote_grant::mint(&auth.pool, target.id, actor.user.id, unix_now()).await?;
    let comment = format!("for user {}", target.id.0);
    audit::record(&auth.pool, Entry { subtype: "remote_granted", user_id: actor.user.id.0, email: actor.email.as_deref(), ip: &ip, comment: &comment }).await;
    Ok((StatusCode::CREATED, Json(GrantResponse { token: token.as_str().to_string(), expires_at })))
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct RedeemRequest {
    token: String,
}

/// The grant token is the credential here: only a signed-in Administrator can mint one, it is random, and it works once.
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
