//! Security-relevant events go to the `log` table (same shape as PHP's) with type `Auth`.
//! A failure to write the log is reported and never fails the request that caused it.

use sqlx::SqlitePool;

use crate::db::unix_now;

pub struct Entry<'a> {
    pub subtype: &'a str,
    pub user_id: i64,
    pub email: Option<&'a str>,
    pub ip: &'a str,
    pub comment: &'a str,
}

pub async fn record(pool: &SqlitePool, e: Entry<'_>) {
    let now = unix_now();
    let result = sqlx::query!(
        "INSERT INTO log (ip, date_time, user_id, email, type, subtype, comment) VALUES (?1, ?2, ?3, ?4, 'Auth', ?5, ?6)",
        e.ip,
        now,
        e.user_id,
        e.email,
        e.subtype,
        e.comment
    )
    .execute(pool)
    .await;
    if let Err(err) = result {
        tracing::warn!(subtype = e.subtype, "audit log write failed: {err}");
    }
}
