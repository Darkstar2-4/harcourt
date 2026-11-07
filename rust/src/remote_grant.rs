//! Remote grants replace scs_remote.php.
//!
//! The PHP endpoint built a code from a user id, an IP and a timestamp with no secret in it, and its `access`
//! action put the named user into the session without checking the code's result. It needed no sign-in at either
//! step. Here:
//!
//!  * minting is for an Active Administrator (`may_grant`) and may not reach above the Administrator's own role;
//!  * the credential is 256 random bits, valid for ten minutes, redeemable once;
//!  * redeeming is one atomic UPDATE, so two requests with the same token cannot both succeed;
//!  * redeeming, and every use of the session it starts, asks `may_grant` again of both accounts as they are then.

use sqlx::SqlitePool;

use crate::{
    identity::{load_user, Role, User, UserId},
    token::Token,
};

pub const TTL_SECS: i64 = 600;

/// Administrators and above may grant, and never to an account with a higher role than their own.
/// (The PHP impersonation page copied the target's role from POST data, so an Administrator could pick Super User.)
pub fn may_grant(actor: &User, target: &User) -> bool {
    actor.is_active() && actor.role >= Role::Administrator && target.is_active() && target.role <= actor.role
}

pub async fn mint(pool: &SqlitePool, target: UserId, granted_by: UserId, now: i64) -> sqlx::Result<(Token, i64)> {
    let token = Token::generate();
    let hash = token.hash();
    let hash = hash.as_slice();
    let expires_at = now + TTL_SECS;
    sqlx::query!(
        "INSERT INTO remote_grant (token_hash, user_id, granted_by, created_at, expires_at) VALUES (?1, ?2, ?3, ?4, ?5)",
        hash,
        target.0,
        granted_by.0,
        now,
        expires_at
    )
    .execute(pool)
    .await?;
    Ok((token, expires_at))
}

pub struct Redeemed {
    pub target: UserId,
    pub granted_by: UserId,
}

/// The grant, once. None for anything unknown, expired or already used. Whether the grantor may still grant is
/// `allowed`'s question.
pub async fn redeem(pool: &SqlitePool, token: &Token, now: i64) -> sqlx::Result<Option<Redeemed>> {
    let hash = token.hash();
    let hash = hash.as_slice();
    let row = sqlx::query!(
        r#"UPDATE remote_grant SET used_at = ?2
           WHERE token_hash = ?1 AND used_at IS NULL AND expires_at > ?2
           RETURNING user_id AS "user_id!: i64", granted_by AS "granted_by!: i64""#,
        hash,
        now
    )
    .fetch_optional(pool)
    .await?;
    Ok(row.map(|r| Redeemed { target: UserId(r.user_id), granted_by: UserId(r.granted_by) }))
}

/// The target, when `by` may grant it (`may_grant`) with both accounts as they stand now.
pub async fn allowed(pool: &SqlitePool, by: UserId, target: UserId) -> sqlx::Result<Option<User>> {
    let (Some(actor), Some(target)) = (load_user(pool, by).await?, load_user(pool, target).await?) else { return Ok(None) };
    Ok(may_grant(&actor, &target).then_some(target))
}

/// A grant that can still be redeemed, for the staff list. `id` is the row id; the token is never shown again.
pub struct LiveGrant {
    pub id: i64,
    pub user_id: UserId,
    pub company_name: String,
    pub granted_by: UserId,
    pub granted_by_name: String,
    pub created_at: i64,
    pub expires_at: i64,
}

pub async fn live(pool: &SqlitePool, now: i64) -> sqlx::Result<Vec<LiveGrant>> {
    let rows = sqlx::query!(
        r#"SELECT g.rowid AS "id!: i64", g.user_id AS "user_id!: i64", u.company_name AS "company_name?",
                  g.granted_by AS "granted_by!: i64", b.company_name AS "granted_by_name?", g.created_at, g.expires_at
           FROM remote_grant g LEFT JOIN "user" u ON u.id = g.user_id LEFT JOIN "user" b ON b.id = g.granted_by
           WHERE g.used_at IS NULL AND g.expires_at > ?1 ORDER BY g.expires_at"#,
        now
    )
    .fetch_all(pool)
    .await?;
    Ok(rows
        .into_iter()
        .map(|r| LiveGrant {
            id: r.id,
            user_id: UserId(r.user_id),
            company_name: r.company_name.unwrap_or_default(),
            granted_by: UserId(r.granted_by),
            granted_by_name: r.granted_by_name.unwrap_or_default(),
            created_at: r.created_at,
            expires_at: r.expires_at,
        })
        .collect())
}

/// The account a live grant is for, so the caller can check it may act on it.
pub async fn live_target(pool: &SqlitePool, id: i64, now: i64) -> sqlx::Result<Option<UserId>> {
    let user = sqlx::query_scalar!(
        r#"SELECT user_id AS "user_id!: i64" FROM remote_grant WHERE rowid = ?1 AND used_at IS NULL AND expires_at > ?2"#,
        id,
        now
    )
    .fetch_optional(pool)
    .await?;
    Ok(user.map(UserId))
}

/// Ends a live grant now. False when it was already used, expired or unknown.
pub async fn revoke(pool: &SqlitePool, id: i64, now: i64) -> sqlx::Result<bool> {
    let done = sqlx::query!("UPDATE remote_grant SET expires_at = ?2 WHERE rowid = ?1 AND used_at IS NULL AND expires_at > ?2", id, now)
        .execute(pool)
        .await?;
    Ok(done.rows_affected() == 1)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::identity::Status;

    fn user(role: Role, status: Status) -> User {
        User { id: UserId(1), role, status, company_name: None }
    }

    #[test]
    fn only_administrators_grant_and_not_upwards() {
        let admin = user(Role::Administrator, Status::Active);
        assert!(may_grant(&admin, &user(Role::Customer, Status::Active)));
        assert!(may_grant(&admin, &user(Role::Administrator, Status::Active)));
        assert!(!may_grant(&admin, &user(Role::SuperUser, Status::Active)));
        assert!(!may_grant(&admin, &user(Role::Customer, Status::Pending)));
        assert!(!may_grant(&user(Role::Executive, Status::Active), &user(Role::Customer, Status::Active)));
        assert!(!may_grant(&user(Role::Administrator, Status::Denied), &user(Role::Customer, Status::Active)));
        assert!(may_grant(&user(Role::SuperUser, Status::Active), &user(Role::Administrator, Status::Active)));
    }
}
