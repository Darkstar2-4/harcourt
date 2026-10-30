//! Rows in `log` with the type and subtype names classes/log.php writes, so staff reports read Rust and PHP rows
//! alike. Like audit.rs, a failed write is reported and never fails the request.

use sqlx::SqlitePool;

use crate::db::unix_now;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Kind {
    UserLogin,
    UserRemoteLogin,
    UserSignup,
    InitialReferer,
    InitialCampaign,
    BlacklistDenied,
    RecaptchaBlocked,
    BotActive,
    BotDenied,
}

impl Kind {
    fn names(self) -> (&'static str, &'static str) {
        match self {
            Kind::UserLogin => ("User", "Login"),
            Kind::UserRemoteLogin => ("User", "Remote Login"),
            Kind::UserSignup => ("User", "Signup"),
            Kind::InitialReferer => ("Initial", "Referer"),
            Kind::InitialCampaign => ("Initial", "Campaign"),
            Kind::BlacklistDenied => ("Blacklist", "Denied"),
            Kind::RecaptchaBlocked => ("Recaptcha", "Blocked"),
            Kind::BotActive => ("BOT", "Active"),
            Kind::BotDenied => ("BOT", "Denied"),
        }
    }
}

/// Who the row is about. The PHP filled these from the session; here the caller says.
#[derive(Debug, Default)]
pub struct Subject<'a> {
    pub ip: &'a str,
    pub user_id: i64,
    pub bot_id: i64,
    pub email: Option<&'a str>,
}

pub async fn record(pool: &SqlitePool, kind: Kind, who: &Subject<'_>, comment: &str) {
    let (ty, subtype) = kind.names();
    let now = unix_now();
    let comment = (!comment.is_empty()).then_some(comment);
    let result = sqlx::query!(
        "INSERT INTO log (ip, date_time, user_id, bot_id, email, type, subtype, comment) VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8)",
        who.ip,
        now,
        who.user_id,
        who.bot_id,
        who.email,
        ty,
        subtype,
        comment
    )
    .execute(pool)
    .await;
    if let Err(err) = result {
        tracing::warn!(ty, subtype, "log write failed: {err}");
    }
}
