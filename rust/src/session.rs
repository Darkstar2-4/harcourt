//! Browser sessions. The cookie is the only thing the browser holds; who the visitor is lives in this table.
//! The PHP app signed visitors in by IP address and never expired or ended a session. Here a session is an
//! explicit grant with an end time, signing out deletes it, and every use asks again whether what let the browser
//! in still holds (`Basis`, `admission::still_admits`).

use sqlx::SqlitePool;

use crate::{
    accounts::admission,
    identity::{Email, User, UserId},
    token::Token,
};

pub const COOKIE: &str = "__Host-hx_session";
pub const TTL_SECS: i64 = 12 * 3600;
/// A remembered sign-in is pushed forward at most this often, so a busy visitor costs one write an hour.
const RENEW_STEP_SECS: i64 = 3600;
/// However often it is used, a session ends this long after it began. Three of the registry's 30-day remote_days
/// (`renew_secs`): someone who signs in every day proves their mailbox again each quarter.
pub const MAX_AGE_SECS: i64 = 90 * 86400;

/// What let a browser in, kept with its session so each use can ask again.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Basis {
    /// An address at the domain of the Active company signed in to (an emailed code, or OAuth).
    Domain(Email),
    /// The address that asked for the account, signed in as that account while staff decide.
    Registration(Email),
    /// A remote grant, and the Administrator who minted it.
    Grant { by: UserId },
}

impl Basis {
    fn via(&self) -> &'static str {
        match self {
            Basis::Domain(_) => "domain",
            Basis::Registration(_) => "registration",
            Basis::Grant { .. } => "grant",
        }
    }

    pub fn email(&self) -> Option<&Email> {
        match self {
            Basis::Domain(e) | Basis::Registration(e) => Some(e),
            Basis::Grant { .. } => None,
        }
    }

    fn granted_by(&self) -> Option<i64> {
        match self {
            Basis::Grant { by } => Some(by.0),
            _ => None,
        }
    }

    fn from_row(via: &str, email: Option<&str>, granted_by: Option<i64>) -> Option<Basis> {
        Some(match (via, granted_by) {
            ("domain", None) => Basis::Domain(Email::parse(email?)?),
            ("registration", None) => Basis::Registration(Email::parse(email?)?),
            ("grant", Some(by)) => Basis::Grant { by: UserId(by) },
            _ => return None,
        })
    }
}

pub struct Session {
    pub user: User,
    pub email: Option<String>,
}

pub async fn create(pool: &SqlitePool, user: UserId, basis: &Basis, now: i64) -> sqlx::Result<Token> {
    insert(pool, user, basis, now, TTL_SECS, 0).await
}

/// A sign-in that lasts `renew_secs` past its last use (an emailed code or OAuth at a company's domain), and never
/// past `MAX_AGE_SECS`.
pub async fn create_remembered(pool: &SqlitePool, user: UserId, basis: &Basis, now: i64, renew_secs: i64) -> sqlx::Result<Token> {
    insert(pool, user, basis, now, renew_secs, renew_secs).await
}

async fn insert(pool: &SqlitePool, user: UserId, basis: &Basis, now: i64, ttl: i64, renew_secs: i64) -> sqlx::Result<Token> {
    let token = Token::generate();
    let hash = token.hash();
    let hash = hash.as_slice();
    let expires_at = now + ttl.min(MAX_AGE_SECS);
    let (via, email, granted_by) = (basis.via(), basis.email().map(Email::as_str), basis.granted_by());
    sqlx::query!("DELETE FROM session WHERE expires_at <= ?1", now).execute(pool).await?;
    sqlx::query!(
        "INSERT INTO session (token_hash, user_id, via, email, granted_by, created_at, expires_at, renew_secs) VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8)",
        hash,
        user.0,
        via,
        email,
        granted_by,
        now,
        expires_at,
        renew_secs
    )
    .execute(pool)
    .await?;
    Ok(token)
}

/// None for an unknown, expired or unparseable-account session, and for one whose basis no longer admits it, which
/// is ended. Activity of the account is the caller's check.
pub async fn lookup(pool: &SqlitePool, token: &Token, now: i64) -> sqlx::Result<Option<Session>> {
    let hash = token.hash();
    let hash = hash.as_slice();
    let oldest = now - MAX_AGE_SECS;
    let row = sqlx::query!(
        r#"SELECT u.id AS "id!: i64", u.status AS "status!", u."type" AS "ty!", u.company_name AS company_name, s.via AS "via!",
                  s.email AS email, s.granted_by AS "granted_by?: i64", s.created_at AS "created_at!: i64",
                  s.expires_at AS "expires_at!: i64", s.renew_secs AS "renew_secs!: i64"
           FROM session s JOIN "user" u ON u.id = s.user_id
           WHERE s.token_hash = ?1 AND s.expires_at > ?2 AND s.created_at > ?3"#,
        hash,
        now,
        oldest
    )
    .fetch_optional(pool)
    .await?;
    let Some(r) = row else { return Ok(None) };
    let basis = Basis::from_row(&r.via, r.email.as_deref(), r.granted_by);
    let (Some(user), Some(basis)) = (User::from_row(r.id, &r.status, &r.ty, r.company_name), basis) else { return Ok(None) };
    if !admission::still_admits(pool, &basis, user.id).await? {
        sqlx::query!("DELETE FROM session WHERE token_hash = ?1", hash).execute(pool).await?;
        return Ok(None);
    }
    if r.renew_secs > 0 {
        let renewed = (now + r.renew_secs).min(r.created_at + MAX_AGE_SECS);
        if renewed > r.expires_at + RENEW_STEP_SECS {
            sqlx::query!("UPDATE session SET expires_at = ?2 WHERE token_hash = ?1", hash, renewed).execute(pool).await?;
        }
    }
    Ok(Some(Session { user, email: basis.email().map(|e| e.as_str().to_string()) }))
}

pub async fn destroy(pool: &SqlitePool, token: &Token) -> sqlx::Result<()> {
    let hash = token.hash();
    let hash = hash.as_slice();
    sqlx::query!("DELETE FROM session WHERE token_hash = ?1", hash).execute(pool).await?;
    Ok(())
}

/// An account's live sessions under one address, for staff. A remote grant's sessions have no address.
pub struct Live {
    pub email: Option<String>,
    pub count: i64,
    pub expires_at: i64,
}

pub async fn live(pool: &SqlitePool, user: UserId, now: i64) -> sqlx::Result<Vec<Live>> {
    let rows = sqlx::query!(
        r#"SELECT email, count(*) AS "count!: i64", max(expires_at) AS "expires_at!: i64" FROM session
           WHERE user_id = ?1 AND expires_at > ?2 GROUP BY email ORDER BY email"#,
        user.0,
        now
    )
    .fetch_all(pool)
    .await?;
    Ok(rows.into_iter().map(|r| Live { email: r.email, count: r.count, expires_at: r.expires_at }).collect())
}

/// Ends every session of the account, or only those under `email`. How many ended.
pub async fn end(pool: &SqlitePool, user: UserId, email: Option<&str>) -> sqlx::Result<u64> {
    let done = match email {
        None => sqlx::query!("DELETE FROM session WHERE user_id = ?1", user.0).execute(pool).await?,
        Some(e) => sqlx::query!("DELETE FROM session WHERE user_id = ?1 AND email = ?2", user.0, e).execute(pool).await?,
    };
    Ok(done.rows_affected())
}
