//! Remote grants replace scs_remote.php.
//!
//! The PHP endpoint built a code from a user id, an IP and a timestamp with no secret in it, and its `access`
//! action put the named user into the session without checking the code's result. It needed no sign-in at either
//! step. Here:
//!
//!  * minting needs a signed-in Administrator (`Authed<Administrator>` in api.rs) and may not reach above the
//!    Administrator's own role;
//!  * the credential is 256 random bits, valid for ten minutes, redeemable once;
//!  * redeeming is one atomic UPDATE, so two requests with the same token cannot both succeed.

use sqlx::SqlitePool;

use crate::{
    identity::{Role, User, UserId},
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

/// The account the grant is for, once. None for anything unknown, expired or already used.
pub async fn redeem(pool: &SqlitePool, token: &Token, now: i64) -> sqlx::Result<Option<UserId>> {
    let hash = token.hash();
    let hash = hash.as_slice();
    let id = sqlx::query_scalar!(
        r#"UPDATE remote_grant SET used_at = ?2
           WHERE token_hash = ?1 AND used_at IS NULL AND expires_at > ?2
           RETURNING user_id AS "user_id!: i64""#,
        hash,
        now
    )
    .fetch_optional(pool)
    .await?;
    Ok(id.map(UserId))
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
