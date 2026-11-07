//! What PHP kept in its session for a visit that pages here read or change: whether the visit is inside the
//! WordPress frame, a language chosen this visit, and the campaign it arrived from. The browser's PHPSESSID names
//! the visit, as it named the PHP session; only its SHA-256 is stored. A visit idle for PHP's session lifetime is
//! forgotten, as PHP's session files were.

use sha2::{Digest, Sha256};
use sqlx::SqlitePool;

use crate::identity::UserId;

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
    /// The language a flag click chose this visit.
    pub flag_language: Option<String>,
}

/// The visit as stored, or a new one. Reading it keeps it alive, at most one write a minute.
pub async fn load(pool: &SqlitePool, id: &VisitId, now: i64) -> sqlx::Result<Visit> {
    let key = id.0.as_slice();
    let row = sqlx::query!(
        r#"UPDATE visit SET seen_at = ?2 WHERE id = ?1 AND seen_at >= ?2 - ?3
           RETURNING framed AS "framed!: i64", language_code, campaign_code, flag_language"#,
        key,
        now,
        IDLE_SECS
    )
    .fetch_optional(pool)
    .await?;
    Ok(row.map_or_else(Visit::default, |r| {
        Visit { framed: r.framed != 0, language_code: r.language_code, campaign_code: r.campaign_code, flag_language: r.flag_language }
    }))
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

/// A flag click chose `code`: it is the visit's language and stays its flag language until another flag click.
pub async fn choose_flag_language(pool: &SqlitePool, id: &VisitId, code: &str, now: i64) -> sqlx::Result<()> {
    forget_idle(pool, now).await?;
    let key = id.0.as_slice();
    sqlx::query!(
        "INSERT INTO visit (id, language_code, flag_language, seen_at) VALUES (?1, ?2, ?2, ?3)
         ON CONFLICT (id) DO UPDATE SET language_code = excluded.language_code, flag_language = excluded.flag_language,
         seen_at = excluded.seen_at",
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

/// The visit takes `code` as its campaign unless it already has one; returns the one it has.
pub async fn adopt_campaign(pool: &SqlitePool, id: &VisitId, code: &str, now: i64) -> sqlx::Result<Option<String>> {
    forget_idle(pool, now).await?;
    let key = id.0.as_slice();
    sqlx::query_scalar!(
        "INSERT INTO visit (id, campaign_code, seen_at) VALUES (?1, ?2, ?3)
         ON CONFLICT (id) DO UPDATE SET campaign_code = coalesce(campaign_code, excluded.campaign_code), seen_at = excluded.seen_at
         RETURNING campaign_code",
        key,
        code,
        now
    )
    .fetch_one(pool)
    .await
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

/// The OAuth message waiting for the sign-up page, Microsoft's first, taken so it shows once. It is cleared only
/// while it is still the one read, so a message left in between is not lost.
pub async fn take_oauth_error(pool: &SqlitePool, id: &VisitId, now: i64) -> sqlx::Result<Option<String>> {
    let key = id.0.as_slice();
    loop {
        let row = sqlx::query!(
            r#"SELECT microsoft_error, google_error FROM visit WHERE id = ?1 AND seen_at >= ?2 - ?3"#,
            key,
            now,
            IDLE_SECS
        )
        .fetch_optional(pool)
        .await?;
        let Some(row) = row else { return Ok(None) };
        let cleared = match (&row.microsoft_error, &row.google_error) {
            (Some(m), _) => sqlx::query!("UPDATE visit SET microsoft_error = NULL WHERE id = ?1 AND microsoft_error = ?2", key, m).execute(pool).await?,
            (None, Some(g)) => sqlx::query!("UPDATE visit SET google_error = NULL WHERE id = ?1 AND google_error = ?2", key, g).execute(pool).await?,
            (None, None) => return Ok(None),
        };
        if cleared.rows_affected() > 0 {
            return Ok(row.microsoft_error.or(row.google_error));
        }
    }
}

/// One more access code turned down this visit; returns the count.
pub async fn count_code_try(pool: &SqlitePool, id: &VisitId, now: i64) -> sqlx::Result<i64> {
    forget_idle(pool, now).await?;
    let key = id.0.as_slice();
    sqlx::query_scalar!(
        r#"INSERT INTO visit (id, code_tries, seen_at) VALUES (?1, 1, ?2)
           ON CONFLICT (id) DO UPDATE SET code_tries = code_tries + 1, seen_at = excluded.seen_at
           RETURNING code_tries AS "code_tries!: i64""#,
        key,
        now
    )
    .fetch_one(pool)
    .await
}

/// A code was sent: the count starts again.
pub async fn reset_code_tries(pool: &SqlitePool, id: &VisitId) -> sqlx::Result<()> {
    let key = id.0.as_slice();
    sqlx::query!("UPDATE visit SET code_tries = 0 WHERE id = ?1", key).execute(pool).await?;
    Ok(())
}

/// The visit's profile, as the JSON of its cookie, and the account it was admitted or kept for (None: no account).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct KeptProfile {
    pub json: String,
    pub owner: Option<UserId>,
}

/// The profile the visit last loaded or admitted someone with.
pub async fn profile(pool: &SqlitePool, id: &VisitId, now: i64) -> sqlx::Result<Option<KeptProfile>> {
    let key = id.0.as_slice();
    let row = sqlx::query!("SELECT profile, profile_for FROM visit WHERE id = ?1 AND seen_at >= ?2 - ?3", key, now, IDLE_SECS).fetch_optional(pool).await?;
    Ok(row.and_then(|r| Some(KeptProfile { json: r.profile?, owner: r.profile_for.map(UserId) })))
}

/// Someone was admitted as `owner`: the visit's profile is theirs, whatever it was.
pub async fn set_profile(pool: &SqlitePool, id: &VisitId, owner: UserId, json: &str, now: i64) -> sqlx::Result<()> {
    forget_idle(pool, now).await?;
    let key = id.0.as_slice();
    sqlx::query!(
        "INSERT INTO visit (id, profile, profile_for, seen_at) VALUES (?1, ?2, ?3, ?4)
         ON CONFLICT (id) DO UPDATE SET profile = excluded.profile, profile_for = excluded.profile_for, seen_at = excluded.seen_at",
        key,
        json,
        owner.0,
        now
    )
    .execute(pool)
    .await?;
    Ok(())
}

/// A profile for `owner` built from the visit's profile as `read` (None: it had none) replaces it only while the
/// visit still has that one and was not destroyed by a sign-out since; false when the write was dropped.
pub async fn replace_profile(pool: &SqlitePool, id: &VisitId, read: Option<&KeptProfile>, owner: Option<UserId>, json: &str, now: i64) -> sqlx::Result<bool> {
    forget_idle(pool, now).await?;
    let key = id.0.as_slice();
    let owner = owner.map(|o| o.0);
    let (read_json, read_owner) = (read.map(|r| r.json.as_str()), read.and_then(|r| r.owner).map(|o| o.0));
    let done = sqlx::query!(
        "INSERT INTO visit (id, profile, profile_for, seen_at) VALUES (?1, ?2, ?3, ?4)
         ON CONFLICT (id) DO UPDATE SET profile = excluded.profile, profile_for = excluded.profile_for, seen_at = excluded.seen_at
         WHERE profile IS ?5 AND profile_for IS ?6 AND NOT coalesce(destroyed, 0)",
        key,
        json,
        owner,
        now,
        read_json,
        read_owner
    )
    .execute(pool)
    .await?;
    Ok(done.rows_affected() > 0)
}

/// What the visitor last typed into a sign-up form, as the JSON of a profile cookie.
pub async fn typed_profile(pool: &SqlitePool, id: &VisitId, now: i64) -> sqlx::Result<Option<String>> {
    let key = id.0.as_slice();
    Ok(sqlx::query_scalar!("SELECT typed_profile FROM visit WHERE id = ?1 AND seen_at >= ?2 - ?3", key, now, IDLE_SECS).fetch_optional(pool).await?.flatten())
}

pub async fn set_typed_profile(pool: &SqlitePool, id: &VisitId, json: &str, now: i64) -> sqlx::Result<()> {
    forget_idle(pool, now).await?;
    let key = id.0.as_slice();
    sqlx::query!(
        "INSERT INTO visit (id, typed_profile, seen_at) VALUES (?1, ?2, ?3)
         ON CONFLICT (id) DO UPDATE SET typed_profile = excluded.typed_profile, seen_at = excluded.seen_at",
        key,
        json,
        now
    )
    .execute(pool)
    .await?;
    Ok(())
}

/// What was typed, taken when `serves` says it is for the person just admitted: it has served. Typed for someone
/// else, it stays for their admission. It is cleared only while it is still what was read, so a form typed in
/// between is not lost.
pub async fn take_typed_profile(pool: &SqlitePool, id: &VisitId, now: i64, serves: impl Fn(&str) -> bool) -> sqlx::Result<Option<String>> {
    let key = id.0.as_slice();
    loop {
        let Some(typed) = typed_profile(pool, id, now).await?.filter(|t| serves(t)) else { return Ok(None) };
        if sqlx::query!("UPDATE visit SET typed_profile = NULL WHERE id = ?1 AND typed_profile = ?2", key, typed).execute(pool).await?.rows_affected() > 0 {
            return Ok(Some(typed));
        }
    }
}

/// session_destroy(): everything the visit held is gone, and the next request with its id begins a visit again.
pub async fn destroy(pool: &SqlitePool, id: &VisitId, now: i64) -> sqlx::Result<()> {
    forget_idle(pool, now).await?;
    let key = id.0.as_slice();
    sqlx::query!("REPLACE INTO visit (id, destroyed, seen_at) VALUES (?1, 1, ?2)", key, now).execute(pool).await?;
    Ok(())
}

/// The visit was destroyed and this request comes back with its id: PHP began a new session under it. True once.
pub async fn begins_again(pool: &SqlitePool, id: &VisitId) -> sqlx::Result<bool> {
    let key = id.0.as_slice();
    Ok(sqlx::query!("DELETE FROM visit WHERE id = ?1 AND destroyed = 1", key).execute(pool).await?.rows_affected() > 0)
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
        assert_eq!(load(&pool, &a, 1000 + IDLE_SECS).await.unwrap(), Visit { framed: true, language_code: Some("FR".into()), campaign_code: None, flag_language: None });
        assert_eq!(load(&pool, &b, 1002).await.unwrap().campaign_code.as_deref(), Some("SPRING"));
        assert!(load(&pool, &a, 1001 + 3 * IDLE_SECS).await.unwrap() == Visit::default(), "idle too long");
        leave_oauth_error(&pool, &b, true, "Invalid OAuth state. Please try again.", 1002).await.unwrap();
        leave_oauth_error(&pool, &b, false, "Cancelled", 1002).await.unwrap();
        assert_eq!(take_oauth_error(&pool, &b, 1003).await.unwrap().as_deref(), Some("Cancelled"), "Microsoft's first");
        assert_eq!(take_oauth_error(&pool, &b, 1003).await.unwrap().as_deref(), Some("Invalid OAuth state. Please try again."));
        assert_eq!(take_oauth_error(&pool, &b, 1003).await.unwrap(), None, "each shows once");
        assert_eq!(count_code_try(&pool, &b, 1003).await.unwrap(), 1);
        assert_eq!(count_code_try(&pool, &b, 1003).await.unwrap(), 2);
        reset_code_tries(&pool, &b).await.unwrap();
        assert_eq!(count_code_try(&pool, &b, 1003).await.unwrap(), 1);
        set_typed_profile(&pool, &b, r#"{"email":"pat@example.com"}"#, 1003).await.unwrap();
        assert_eq!(typed_profile(&pool, &b, 1003).await.unwrap().as_deref(), Some(r#"{"email":"pat@example.com"}"#));
        assert_eq!(take_typed_profile(&pool, &b, 1003, |t| t.contains("lee@")).await.unwrap(), None, "not Lee's");
        assert_eq!(typed_profile(&pool, &b, 1003).await.unwrap().as_deref(), Some(r#"{"email":"pat@example.com"}"#), "kept for Pat");
        assert_eq!(take_typed_profile(&pool, &b, 1003, |_| true).await.unwrap().as_deref(), Some(r#"{"email":"pat@example.com"}"#));
        assert_eq!(take_typed_profile(&pool, &b, 1003, |_| true).await.unwrap(), None, "taken once");
        destroy(&pool, &b, 1003).await.unwrap();
        assert!(!replace_profile(&pool, &b, None, None, r#"{"email":"pat@example.com"}"#, 1003).await.unwrap(), "not into a signed-out visit");
        assert_eq!(load(&pool, &b, 1003).await.unwrap(), Visit::default());
        assert!(begins_again(&pool, &b).await.unwrap());
        assert!(!begins_again(&pool, &b).await.unwrap(), "only the first request after");
    }

    #[tokio::test]
    async fn a_flag_click_sets_the_visits_language_and_its_flag_language() {
        let pool = crate::db::open_memory().await.unwrap();
        let a = VisitId::of("a");
        set_language(&pool, &a, "EN", 1000).await.unwrap();
        choose_flag_language(&pool, &a, "FR", 1001).await.unwrap();
        let v = load(&pool, &a, 1002).await.unwrap();
        assert_eq!((v.language_code.as_deref(), v.flag_language.as_deref()), (Some("FR"), Some("FR")));
        set_language(&pool, &a, "EN", 1003).await.unwrap();
        assert_eq!(load(&pool, &a, 1004).await.unwrap().flag_language.as_deref(), Some("FR"), "a page's language is not a flag click");
        choose_flag_language(&pool, &a, "EN", 1005).await.unwrap();
        assert_eq!(load(&pool, &a, 1006).await.unwrap().flag_language.as_deref(), Some("EN"));
    }

    #[tokio::test]
    async fn a_profile_built_from_one_since_replaced_is_dropped() {
        let pool = crate::db::open_memory().await.unwrap();
        let v = VisitId::of("v");
        let (pat, lee) = (r#"{"email":"pat@example.com"}"#, r#"{"email":"lee@example.com"}"#);
        let kept = |json: &str, owner: Option<i64>| KeptProfile { json: json.into(), owner: owner.map(UserId) };
        assert!(replace_profile(&pool, &v, None, None, pat, 1000).await.unwrap(), "a visit that had none");
        assert_eq!(profile(&pool, &v, 1000).await.unwrap(), Some(kept(pat, None)));
        set_profile(&pool, &v, UserId(8), lee, 1001).await.unwrap();
        assert!(!replace_profile(&pool, &v, Some(&kept(pat, None)), None, r#"{"email":"pat@example.com","name":"Pat Lee"}"#, 1002).await.unwrap());
        assert!(!replace_profile(&pool, &v, None, None, pat, 1002).await.unwrap());
        assert!(!replace_profile(&pool, &v, Some(&kept(lee, None)), None, pat, 1002).await.unwrap(), "the same profile for another owner");
        assert_eq!(profile(&pool, &v, 1002).await.unwrap(), Some(kept(lee, Some(8))));
        assert!(replace_profile(&pool, &v, Some(&kept(lee, Some(8))), Some(UserId(8)), r#"{"email":"lee@example.com","name":"Lee "}"#, 1003).await.unwrap());
        assert_eq!(profile(&pool, &v, 1003).await.unwrap(), Some(kept(r#"{"email":"lee@example.com","name":"Lee "}"#, Some(8))));
        frame(&pool, &VisitId::of("w"), 1003).await.unwrap();
        assert!(replace_profile(&pool, &VisitId::of("w"), None, Some(UserId(8)), pat, 1003).await.unwrap(), "a visit row without a profile");
    }

    #[tokio::test]
    async fn a_visit_keeps_the_first_campaign_it_adopts() {
        let pool = crate::db::open_memory().await.unwrap();
        let v = VisitId::of("v");
        assert_eq!(adopt_campaign(&pool, &v, "SPRING", 1000).await.unwrap().as_deref(), Some("SPRING"));
        assert_eq!(adopt_campaign(&pool, &v, "FALL", 1001).await.unwrap().as_deref(), Some("SPRING"));
        set_campaign(&pool, &v, None, 1002).await.unwrap();
        assert_eq!(adopt_campaign(&pool, &v, "FALL", 1003).await.unwrap().as_deref(), Some("FALL"));
        assert_eq!(load(&pool, &v, 1003).await.unwrap().campaign_code.as_deref(), Some("FALL"));
    }
}
