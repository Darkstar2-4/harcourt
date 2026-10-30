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

pub struct Session {
    pub user: User,
    pub email: Option<String>,
}

pub async fn create(pool: &SqlitePool, user: UserId, email: Option<&str>, now: i64) -> sqlx::Result<Token> {
    let token = Token::generate();
    let hash = token.hash();
    let hash = hash.as_slice();
    let expires_at = now + TTL_SECS;
    sqlx::query!("DELETE FROM session WHERE expires_at <= ?1", now).execute(pool).await?;
    sqlx::query!(
        "INSERT INTO session (token_hash, user_id, email, created_at, expires_at) VALUES (?1, ?2, ?3, ?4, ?5)",
        hash,
        user.0,
        email,
        now,
        expires_at
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
        r#"SELECT u.id AS "id!: i64", u.status AS "status!", u."type" AS "ty!", u.company_name AS company_name, s.email AS email
           FROM session s JOIN "user" u ON u.id = s.user_id
           WHERE s.token_hash = ?1 AND s.expires_at > ?2"#,
        hash,
        now
    )
    .fetch_optional(pool)
    .await?;
    Ok(row.and_then(|r| {
        User::from_row(r.id, &r.status, &r.ty, r.company_name).map(|user| Session { user, email: r.email })
    }))
}

pub async fn destroy(pool: &SqlitePool, token: &Token) -> sqlx::Result<()> {
    let hash = token.hash();
    let hash = hash.as_slice();
    sqlx::query!("DELETE FROM session WHERE token_hash = ?1", hash).execute(pool).await?;
    Ok(())
}
