//! Browser sessions. The cookie is the only thing the browser holds; who the visitor is lives in this table.
//! The PHP app signed visitors in by IP address and never expired or ended a session. Here a session is an
//! explicit grant with an end time, and signing out deletes it.

use sqlx::SqlitePool;

use crate::{
    identity::{User, UserId},
    token::Token,
};

pub const COOKIE: &str = "__Host-hx_session";
pub const TTL_SECS: i64 = 12 * 3600;
/// A remembered sign-in is pushed forward at most this often, so a busy visitor costs one write an hour.
const RENEW_STEP_SECS: i64 = 3600;

pub struct Session {
    pub user: User,
    pub email: Option<String>,
}

pub async fn create(pool: &SqlitePool, user: UserId, email: Option<&str>, now: i64) -> sqlx::Result<Token> {
    insert(pool, user, email, now, TTL_SECS, 0).await
}

/// A sign-in that lasts `renew_secs` past its last use (an emailed code or OAuth at a company's domain).
pub async fn create_remembered(pool: &SqlitePool, user: UserId, email: Option<&str>, now: i64, renew_secs: i64) -> sqlx::Result<Token> {
    insert(pool, user, email, now, renew_secs, renew_secs).await
}

async fn insert(pool: &SqlitePool, user: UserId, email: Option<&str>, now: i64, ttl: i64, renew_secs: i64) -> sqlx::Result<Token> {
    let token = Token::generate();
    let hash = token.hash();
    let hash = hash.as_slice();
    let expires_at = now + ttl;
    sqlx::query!("DELETE FROM session WHERE expires_at <= ?1", now).execute(pool).await?;
    sqlx::query!(
        "INSERT INTO session (token_hash, user_id, email, created_at, expires_at, renew_secs) VALUES (?1, ?2, ?3, ?4, ?5, ?6)",
        hash,
        user.0,
        email,
        now,
        expires_at,
        renew_secs
    )
    .execute(pool)
    .await?;
    Ok(token)
}

/// None for an unknown, expired or unparseable-account session. Activity of the account is the caller's check.
pub async fn lookup(pool: &SqlitePool, token: &Token, now: i64) -> sqlx::Result<Option<Session>> {
    let hash = token.hash();
    let hash = hash.as_slice();
    let row = sqlx::query!(
        r#"SELECT u.id AS "id!: i64", u.status AS "status!", u."type" AS "ty!", u.company_name AS company_name, s.email AS email,
                  s.expires_at AS "expires_at!: i64", s.renew_secs AS "renew_secs!: i64"
           FROM session s JOIN "user" u ON u.id = s.user_id
           WHERE s.token_hash = ?1 AND s.expires_at > ?2"#,
        hash,
        now
    )
    .fetch_optional(pool)
    .await?;
    let Some(r) = row else { return Ok(None) };
    if r.renew_secs > 0 && r.expires_at < now + r.renew_secs - RENEW_STEP_SECS {
        let expires_at = now + r.renew_secs;
        sqlx::query!("UPDATE session SET expires_at = ?2 WHERE token_hash = ?1", hash, expires_at).execute(pool).await?;
    }
    Ok(User::from_row(r.id, &r.status, &r.ty, r.company_name).map(|user| Session { user, email: r.email }))
}

pub async fn destroy(pool: &SqlitePool, token: &Token) -> sqlx::Result<()> {
    let hash = token.hash();
    let hash = hash.as_slice();
    sqlx::query!("DELETE FROM session WHERE token_hash = ?1", hash).execute(pool).await?;
    Ok(())
}
