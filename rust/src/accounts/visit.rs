//! What PHP kept in its session for a visit that pages here read or change: whether the visit is inside the
//! WordPress frame, a language chosen this visit, and the campaign it arrived from. The browser's PHPSESSID names
//! the visit, as it named the PHP session; only its SHA-256 is stored. A visit idle for PHP's session lifetime is
//! forgotten, as PHP's session files were.

use sha2::{Digest, Sha256};
use sqlx::SqlitePool;

/// PHP's session.gc_maxlifetime.
pub const IDLE_SECS: i64 = 1440;

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct VisitId([u8; 32]);

impl VisitId {
    pub fn of(php_session_id: &str) -> VisitId {
        VisitId(Sha256::digest(php_session_id.as_bytes()).into())
    }
}

#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Visit {
    pub framed: bool,
    pub language_code: Option<String>,
    pub campaign_code: Option<String>,
}

/// The visit as stored, or a new one. Reading it keeps it alive, at most one write a minute.
pub async fn load(pool: &SqlitePool, id: &VisitId, now: i64) -> sqlx::Result<Visit> {
    let key = id.0.as_slice();
    let row = sqlx::query!(
        r#"UPDATE visit SET seen_at = ?2 WHERE id = ?1 AND seen_at >= ?2 - ?3
           RETURNING framed AS "framed!: i64", language_code, campaign_code"#,
        key,
        now,
        IDLE_SECS
    )
    .fetch_optional(pool)
    .await?;
    Ok(row.map_or_else(Visit::default, |r| Visit { framed: r.framed != 0, language_code: r.language_code, campaign_code: r.campaign_code }))
}

/// Make the visit framed: a page was asked for with `scscpq_wp`.
pub async fn frame(pool: &SqlitePool, id: &VisitId, now: i64) -> sqlx::Result<()> {
    forget_idle(pool, now).await?;
    let key = id.0.as_slice();
    sqlx::query!(
        "INSERT INTO visit (id, framed, seen_at) VALUES (?1, 1, ?2)
         ON CONFLICT (id) DO UPDATE SET framed = 1, seen_at = excluded.seen_at",
        key,
        now
    )
    .execute(pool)
    .await?;
    Ok(())
}

pub async fn set_language(pool: &SqlitePool, id: &VisitId, code: &str, now: i64) -> sqlx::Result<()> {
    forget_idle(pool, now).await?;
    let key = id.0.as_slice();
    sqlx::query!(
        "INSERT INTO visit (id, language_code, seen_at) VALUES (?1, ?2, ?3)
         ON CONFLICT (id) DO UPDATE SET language_code = excluded.language_code, seen_at = excluded.seen_at",
        key,
        code,
        now
    )
    .execute(pool)
    .await?;
    Ok(())
}

/// The campaign a visit began with; None when the link named one that is not running.
pub async fn set_campaign(pool: &SqlitePool, id: &VisitId, code: Option<&str>, now: i64) -> sqlx::Result<()> {
    forget_idle(pool, now).await?;
    let key = id.0.as_slice();
    sqlx::query!(
        "INSERT INTO visit (id, campaign_code, seen_at) VALUES (?1, ?2, ?3)
         ON CONFLICT (id) DO UPDATE SET campaign_code = excluded.campaign_code, seen_at = excluded.seen_at",
        key,
        code,
        now
    )
    .execute(pool)
    .await?;
    Ok(())
}

/// The message an OAuth callback leaves for the sign-up page, as `{provider}_oauth_error` in the PHP session.
pub async fn leave_oauth_error(pool: &SqlitePool, id: &VisitId, google: bool, message: &str, now: i64) -> sqlx::Result<()> {
    forget_idle(pool, now).await?;
    let key = id.0.as_slice();
    let (microsoft, google) = if google { (None, Some(message)) } else { (Some(message), None) };
    sqlx::query!(
        "INSERT INTO visit (id, microsoft_error, google_error, seen_at) VALUES (?1, ?2, ?3, ?4)
         ON CONFLICT (id) DO UPDATE SET microsoft_error = coalesce(excluded.microsoft_error, microsoft_error),
             google_error = coalesce(excluded.google_error, google_error), seen_at = excluded.seen_at",
        key,
        microsoft,
        google,
        now
    )
    .execute(pool)
    .await?;
    Ok(())
}

/// The OAuth message waiting for the sign-up page, Microsoft's first, taken so it shows once.
pub async fn take_oauth_error(pool: &SqlitePool, id: &VisitId, now: i64) -> sqlx::Result<Option<String>> {
    let key = id.0.as_slice();
    let row = sqlx::query!(
        r#"SELECT microsoft_error, google_error FROM visit WHERE id = ?1 AND seen_at >= ?2 - ?3"#,
        key,
        now,
        IDLE_SECS
    )
    .fetch_optional(pool)
    .await?;
    let Some(row) = row else { return Ok(None) };
    let taken = if row.microsoft_error.is_some() { "microsoft" } else { "google" };
    let message = row.microsoft_error.or(row.google_error);
    if message.is_some() {
        if taken == "microsoft" {
            sqlx::query!("UPDATE visit SET microsoft_error = NULL WHERE id = ?1", key).execute(pool).await?;
        } else {
            sqlx::query!("UPDATE visit SET google_error = NULL WHERE id = ?1", key).execute(pool).await?;
        }
    }
    Ok(message)
}

/// session_destroy().
pub async fn forget(pool: &SqlitePool, id: &VisitId) -> sqlx::Result<()> {
    let key = id.0.as_slice();
    sqlx::query!("DELETE FROM visit WHERE id = ?1", key).execute(pool).await?;
    Ok(())
}

async fn forget_idle(pool: &SqlitePool, now: i64) -> sqlx::Result<()> {
    sqlx::query!("DELETE FROM visit WHERE seen_at < ?1 - ?2", now, IDLE_SECS).execute(pool).await?;
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[tokio::test]
    async fn a_visit_keeps_what_was_chosen_until_it_goes_idle() {
        let pool = crate::db::open_memory().await.unwrap();
        let (a, b) = (VisitId::of("a"), VisitId::of("b"));
        assert_eq!(load(&pool, &a, 1000).await.unwrap(), Visit::default());
        frame(&pool, &a, 1000).await.unwrap();
        set_language(&pool, &a, "FR", 1001).await.unwrap();
        set_campaign(&pool, &b, Some("SPRING"), 1002).await.unwrap();
        assert_eq!(load(&pool, &a, 1000 + IDLE_SECS).await.unwrap(), Visit { framed: true, language_code: Some("FR".into()), campaign_code: None });
        assert_eq!(load(&pool, &b, 1002).await.unwrap().campaign_code.as_deref(), Some("SPRING"));
        assert!(load(&pool, &a, 1001 + 3 * IDLE_SECS).await.unwrap() == Visit::default(), "idle too long");
        leave_oauth_error(&pool, &b, true, "Invalid OAuth state. Please try again.", 1002).await.unwrap();
        leave_oauth_error(&pool, &b, false, "Cancelled", 1002).await.unwrap();
        assert_eq!(take_oauth_error(&pool, &b, 1003).await.unwrap().as_deref(), Some("Cancelled"), "Microsoft's first");
        assert_eq!(take_oauth_error(&pool, &b, 1003).await.unwrap().as_deref(), Some("Invalid OAuth state. Please try again."));
        assert_eq!(take_oauth_error(&pool, &b, 1003).await.unwrap(), None, "each shows once");
        forget(&pool, &b).await.unwrap();
        assert_eq!(load(&pool, &b, 1003).await.unwrap(), Visit::default());
    }
}
