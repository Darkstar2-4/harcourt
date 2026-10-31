//! Outgoing mail. A request only queues a message in `outbox`; one worker drains the table through SMTP (Microsoft 365
//! in production), so a mail outage never fails a request. Delivery is at least once: a crash after the server
//! accepted a message and before `sent_at` is written sends it again once its lease runs out.
//!
//! The worker takes a `Transport` trait object. Production passes `Smtp`; tests pass `MemoryTransport`, so the test
//! suite cannot reach a mail server.

use std::{
    collections::VecDeque,
    future::Future,
    pin::Pin,
    sync::{Arc, Mutex},
    time::Duration,
};

use lettre::{
    message::{header::ContentType, Mailbox as LettreMailbox, MultiPart, SinglePart},
    transport::smtp::authentication::Credentials,
    AsyncSmtpTransport, AsyncTransport, Message, Tokio1Executor,
};
use sqlx::SqlitePool;

use crate::{db::unix_now, identity::Email};

/// How long a claimed message stays invisible to other workers while it is being sent.
pub const LEASE_SECS: i64 = 300;
/// A message that failed this many times is marked failed and left for an operator.
pub const MAX_ATTEMPTS: i64 = 8;
const POLL: Duration = Duration::from_secs(15);
/// PHPMailer's AltBody in scsmail_rev1.php, the plain part of every HTML mail the site sends.
pub const ALT_BODY: &str = "To view the message, please use an HTML compatible email viewer!";

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Mailbox {
    pub email: Email,
    pub name: String,
}

impl Mailbox {
    pub fn new(email: Email, name: &str) -> Self {
        Mailbox { email, name: one_line(name) }
    }

    pub fn bare(email: Email) -> Self {
        Mailbox { email, name: String::new() }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Kind {
    To,
    Cc,
    Bcc,
}

impl Kind {
    fn as_str(self) -> &'static str {
        match self {
            Kind::To => "to",
            Kind::Cc => "cc",
            Kind::Bcc => "bcc",
        }
    }

    fn parse(s: &str) -> Option<Kind> {
        Some(match s {
            "to" => Kind::To,
            "cc" => Kind::Cc,
            "bcc" => Kind::Bcc,
            _ => return None,
        })
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Body {
    Text(String),
    Html(String),
}

/// One message. `to` is the first To address; `also` holds further To, Cc and Bcc addresses.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Mail {
    pub to: Mailbox,
    pub also: Vec<(Kind, Mailbox)>,
    pub reply_to: Option<Email>,
    /// Shown on From in place of the sender's configured name.
    pub from_name: Option<String>,
    pub subject: String,
    pub body: Body,
}

impl Mail {
    pub fn new(to: Mailbox, subject: &str, body: Body) -> Self {
        Mail { to, also: Vec::new(), reply_to: None, from_name: None, subject: one_line(subject), body }
    }

    /// Adds a recipient unless that address already receives this mail.
    pub fn add(&mut self, kind: Kind, mailbox: Mailbox) {
        let known = self.to.email == mailbox.email || self.also.iter().any(|(_, m)| m.email == mailbox.email);
        if !known {
            self.also.push((kind, mailbox));
        }
    }
}

/// Header values are single lines. A CR or LF typed into a form field must not become a second header.
fn one_line(s: &str) -> String {
    s.chars().map(|c| if c.is_control() { ' ' } else { c }).collect::<String>().trim().to_string()
}

pub async fn enqueue(pool: &SqlitePool, mail: &Mail, now: i64) -> sqlx::Result<i64> {
    let (body, content_type) = match &mail.body {
        Body::Text(t) => (t.as_str(), "text"),
        Body::Html(h) => (h.as_str(), "html"),
    };
    let reply_to = mail.reply_to.as_ref().map(Email::as_str);
    let from_name = mail.from_name.as_deref().map(one_line).filter(|n| !n.is_empty());
    let to = mail.to.email.as_str();
    let mut tx = pool.begin().await?;
    let id = sqlx::query_scalar!(
        r#"INSERT INTO outbox (to_email, to_name, reply_to, from_name, subject, body, content_type, created_at, next_attempt_at)
           VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?8) RETURNING id AS "id!: i64""#,
        to,
        mail.to.name,
        reply_to,
        from_name,
        mail.subject,
        body,
        content_type,
        now
    )
    .fetch_one(&mut *tx)
    .await?;
    for (kind, m) in &mail.also {
        let kind = kind.as_str();
        let email = m.email.as_str();
        sqlx::query!(
            "INSERT OR IGNORE INTO outbox_recipient (outbox_id, kind, email, name) VALUES (?1, ?2, ?3, ?4)",
            id,
            kind,
            email,
            m.name
        )
        .execute(&mut *tx)
        .await?;
    }
    tx.commit().await?;
    Ok(id)
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum SendError {
    /// Worth another try later: the server was unreachable, busy, or answered 4xx.
    Retry(String),
    /// Will never succeed as queued: a malformed address, or a 5xx refusal.
    Reject(String),
}

pub type SendFuture<'a> = Pin<Box<dyn Future<Output = Result<(), SendError>> + Send + 'a>>;

pub trait Transport: Send + Sync {
    fn send<'a>(&'a self, mail: &'a Mail) -> SendFuture<'a>;
}

/// Settings for the production transport, read from the environment and never from source.
pub struct SmtpConfig {
    pub host: String,
    pub port: u16,
    pub user: String,
    password: String,
    pub from: Email,
    pub from_name: String,
}

impl SmtpConfig {
    /// None while HXWEB_SMTP_USER is unset: mail stays queued until an operator configures the sender.
    pub fn from_env(get: impl Fn(&str) -> Option<String>) -> Result<Option<Self>, String> {
        let Some(user) = get("HXWEB_SMTP_USER").filter(|u| !u.trim().is_empty()) else { return Ok(None) };
        let password = get("HXWEB_SMTP_PASSWORD").filter(|p| !p.is_empty()).ok_or("HXWEB_SMTP_PASSWORD is required with HXWEB_SMTP_USER")?;
        let host = get("HXWEB_SMTP_HOST").unwrap_or_else(|| "smtp.office365.com".into());
        let port = match get("HXWEB_SMTP_PORT") {
            None => 587,
            Some(p) => p.trim().parse().map_err(|e| format!("HXWEB_SMTP_PORT {p:?}: {e}"))?,
        };
        let from = get("HXWEB_SMTP_FROM").unwrap_or_else(|| user.clone());
        let from = Email::parse(&from).ok_or_else(|| format!("HXWEB_SMTP_FROM is not a mail address: {from:?}"))?;
        let from_name = get("HXWEB_SMTP_FROM_NAME").unwrap_or_else(|| "Harcourt".into());
        Ok(Some(SmtpConfig { host, port, user: user.trim().to_string(), password, from, from_name }))
    }
}

pub struct Smtp {
    transport: AsyncSmtpTransport<Tokio1Executor>,
    from: LettreMailbox,
}

impl Smtp {
    /// STARTTLS on the submission port, as Microsoft 365 expects.
    pub fn new(config: SmtpConfig) -> Result<Self, String> {
        let transport = AsyncSmtpTransport::<Tokio1Executor>::starttls_relay(&config.host)
            .map_err(|e| format!("SMTP host {:?}: {e}", config.host))?
            .port(config.port)
            .credentials(Credentials::new(config.user, config.password))
            .timeout(Some(Duration::from_secs(60)))
            .build();
        let from = lettre_mailbox(&Mailbox::new(config.from, &config.from_name)).map_err(|e| format!("HXWEB_SMTP_FROM: {e:?}"))?;
        Ok(Smtp { transport, from })
    }

    pub fn message(&self, mail: &Mail) -> Result<Message, SendError> {
        let from = match &mail.from_name {
            Some(name) => LettreMailbox::new(Some(name.clone()), self.from.email.clone()),
            None => self.from.clone(),
        };
        let mut b = Message::builder().from(from).subject(mail.subject.clone()).to(lettre_mailbox(&mail.to)?);
        for (kind, m) in &mail.also {
            let m = lettre_mailbox(m)?;
            b = match kind {
                Kind::To => b.to(m),
                Kind::Cc => b.cc(m),
                Kind::Bcc => b.bcc(m),
            };
        }
        if let Some(r) = &mail.reply_to {
            b = b.reply_to(lettre_mailbox(&Mailbox::bare(r.clone()))?);
        }
        let built = match &mail.body {
            Body::Text(t) => b.singlepart(SinglePart::builder().header(ContentType::TEXT_PLAIN).body(t.clone())),
            Body::Html(h) => b.multipart(MultiPart::alternative_plain_html(ALT_BODY.to_string(), h.clone())),
        };
        built.map_err(|e| SendError::Reject(format!("message: {e}")))
    }
}

fn lettre_mailbox(m: &Mailbox) -> Result<LettreMailbox, SendError> {
    let address = m.email.as_str().parse().map_err(|e| SendError::Reject(format!("address {:?}: {e}", m.email.as_str())))?;
    Ok(LettreMailbox::new((!m.name.is_empty()).then(|| m.name.clone()), address))
}

impl Transport for Smtp {
    fn send<'a>(&'a self, mail: &'a Mail) -> SendFuture<'a> {
        Box::pin(async move {
            let message = self.message(mail)?;
            match self.transport.send(message).await {
                Ok(_) => Ok(()),
                Err(e) if e.is_permanent() => Err(SendError::Reject(e.to_string())),
                Err(e) => Err(SendError::Retry(e.to_string())),
            }
        })
    }
}

/// Records what would have been sent. Failures queued with `fail_next` are returned first, in order.
#[derive(Default)]
pub struct MemoryTransport {
    sent: Mutex<Vec<Mail>>,
    failures: Mutex<VecDeque<SendError>>,
}

impl MemoryTransport {
    pub fn sent(&self) -> Vec<Mail> {
        self.sent.lock().map(|s| s.clone()).unwrap_or_default()
    }

    pub fn fail_next(&self, error: SendError) {
        if let Ok(mut f) = self.failures.lock() {
            f.push_back(error);
        }
    }
}

impl Transport for MemoryTransport {
    fn send<'a>(&'a self, mail: &'a Mail) -> SendFuture<'a> {
        Box::pin(async move {
            if let Some(e) = self.failures.lock().ok().and_then(|mut f| f.pop_front()) {
                return Err(e);
            }
            if let Ok(mut s) = self.sent.lock() {
                s.push(mail.clone());
            }
            Ok(())
        })
    }
}

/// Seconds to wait after the `attempts`-th failure: one minute, doubling, at most six hours.
pub fn backoff(attempts: i64) -> i64 {
    let doublings = attempts.clamp(1, 30) - 1;
    (60_i64 << doublings).min(6 * 3600)
}

struct Claimed {
    id: i64,
    attempts: i64,
    mail: Result<Mail, String>,
}

/// Takes the next due message and leases it, in one statement, so two workers never send the same row at once.
async fn claim(pool: &SqlitePool, now: i64) -> sqlx::Result<Option<Claimed>> {
    let lease = now + LEASE_SECS;
    let Some(row) = sqlx::query!(
        r#"UPDATE outbox SET next_attempt_at = ?1
           WHERE id = (SELECT id FROM outbox WHERE sent_at IS NULL AND failed_at IS NULL AND next_attempt_at <= ?2
                       ORDER BY next_attempt_at, id LIMIT 1)
           RETURNING id AS "id!: i64", to_email AS "to_email!", to_name AS "to_name!", reply_to, from_name, subject AS "subject!",
                     body AS "body!", content_type AS "content_type!", attempts AS "attempts!: i64""#,
        lease,
        now
    )
    .fetch_optional(pool)
    .await?
    else {
        return Ok(None);
    };
    let others = sqlx::query!("SELECT kind, email, name FROM outbox_recipient WHERE outbox_id = ?1 ORDER BY rowid", row.id)
        .fetch_all(pool)
        .await?;
    let mail = (|| {
        let to = Email::parse(&row.to_email).ok_or_else(|| format!("bad address {:?}", row.to_email))?;
        let body = match row.content_type.as_str() {
            "html" => Body::Html(row.body),
            _ => Body::Text(row.body),
        };
        let mut mail = Mail::new(Mailbox::new(to, &row.to_name), &row.subject, body);
        mail.reply_to = row.reply_to.as_deref().and_then(Email::parse);
        mail.from_name = row.from_name;
        for o in others {
            let kind = Kind::parse(&o.kind).ok_or_else(|| format!("bad kind {:?}", o.kind))?;
            let email = Email::parse(&o.email).ok_or_else(|| format!("bad address {:?}", o.email))?;
            mail.add(kind, Mailbox::new(email, &o.name));
        }
        Ok(mail)
    })();
    Ok(Some(Claimed { id: row.id, attempts: row.attempts, mail }))
}

/// Sent: the body is cleared, because the queue is not an archive of what customers wrote.
async fn mark_sent(pool: &SqlitePool, id: i64, now: i64) -> sqlx::Result<()> {
    sqlx::query!("UPDATE outbox SET sent_at = ?1, body = '', attempts = attempts + 1, last_error = NULL WHERE id = ?2", now, id)
        .execute(pool)
        .await?;
    Ok(())
}

async fn mark_failed(pool: &SqlitePool, claimed: &Claimed, error: &SendError, now: i64) -> sqlx::Result<()> {
    let attempts = claimed.attempts + 1;
    let (message, give_up) = match error {
        SendError::Retry(m) => (m, attempts >= MAX_ATTEMPTS),
        SendError::Reject(m) => (m, true),
    };
    let failed_at = give_up.then_some(now);
    let next = now + backoff(attempts);
    sqlx::query!(
        "UPDATE outbox SET attempts = ?1, last_error = ?2, failed_at = ?3, next_attempt_at = ?4 WHERE id = ?5",
        attempts,
        message,
        failed_at,
        next,
        claimed.id
    )
    .execute(pool)
    .await?;
    Ok(())
}

/// Sends every message that is due at `now`, one at a time. Returns how many were attempted.
pub async fn deliver_due(pool: &SqlitePool, transport: &dyn Transport, now: i64) -> sqlx::Result<usize> {
    let mut attempted = 0;
    while let Some(claimed) = claim(pool, now).await? {
        attempted += 1;
        let result = match &claimed.mail {
            Ok(mail) => transport.send(mail).await,
            Err(e) => Err(SendError::Reject(e.clone())),
        };
        match result {
            Ok(()) => mark_sent(pool, claimed.id, now).await?,
            Err(e) => {
                tracing::warn!(outbox_id = claimed.id, "mail not sent: {e:?}");
                mark_failed(pool, &claimed, &e, now).await?;
            }
        }
    }
    Ok(attempted)
}

/// The sender task. It lives as long as the program.
pub fn spawn(pool: SqlitePool, transport: Arc<dyn Transport>) -> tokio::task::JoinHandle<()> {
    tokio::spawn(async move {
        loop {
            if let Err(e) = deliver_due(&pool, &*transport, unix_now()).await {
                tracing::error!("outbox: {e}");
            }
            tokio::time::sleep(POLL).await;
        }
    })
}

/// Starts the SMTP sender when the environment configures one. Without it, mail waits in the outbox.
pub fn start_from_env(pool: SqlitePool) {
    match SmtpConfig::from_env(|k| std::env::var(k).ok()).and_then(|c| c.map(Smtp::new).transpose()) {
        Ok(Some(smtp)) => {
            spawn(pool, Arc::new(smtp));
        }
        Ok(None) => tracing::warn!("HXWEB_SMTP_USER is not set: mail is queued in outbox and not sent"),
        Err(e) => tracing::error!("outbox sender disabled: {e}"),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn env<'a>(pairs: &'a [(&'a str, &'a str)]) -> impl Fn(&str) -> Option<String> + 'a {
        move |k| pairs.iter().find(|(n, _)| *n == k).map(|(_, v)| v.to_string())
    }

    #[test]
    fn backoff_doubles_from_a_minute_and_stops_at_six_hours() {
        assert_eq!(backoff(1), 60);
        assert_eq!(backoff(2), 120);
        assert_eq!(backoff(5), 960);
        assert_eq!(backoff(12), 6 * 3600);
        assert_eq!(backoff(1000), 6 * 3600);
    }

    #[test]
    fn smtp_settings_come_from_the_environment() {
        assert!(SmtpConfig::from_env(env(&[])).unwrap().is_none());
        assert!(SmtpConfig::from_env(env(&[("HXWEB_SMTP_USER", "web@example.com")])).is_err());
        let c = SmtpConfig::from_env(env(&[("HXWEB_SMTP_USER", "web@example.com"), ("HXWEB_SMTP_PASSWORD", "pw")])).unwrap().unwrap();
        assert_eq!((c.host.as_str(), c.port, c.from.as_str()), ("smtp.office365.com", 587, "web@example.com"));
        assert!(SmtpConfig::from_env(env(&[("HXWEB_SMTP_USER", "u@example.com"), ("HXWEB_SMTP_PASSWORD", "pw"), ("HXWEB_SMTP_PORT", "x")])).is_err());
    }

    #[test]
    fn header_values_lose_line_breaks() {
        let to = Mailbox::new(Email::parse("a@example.com").unwrap(), "Ann\r\nBcc: b@example.com");
        let mail = Mail::new(to, "Hello\nBcc: c@example.com", Body::Text(String::new()));
        assert!(!mail.subject.contains('\n') && !mail.to.name.contains('\n'));
    }

    #[tokio::test]
    async fn the_smtp_message_carries_copies_but_hides_bcc() {
        let config = SmtpConfig::from_env(env(&[("HXWEB_SMTP_USER", "web@example.com"), ("HXWEB_SMTP_PASSWORD", "pw"), ("HXWEB_SMTP_HOST", "smtp.example.com")]))
            .unwrap()
            .unwrap();
        let smtp = Smtp::new(config).unwrap();
        let mut mail = Mail::new(Mailbox::new(Email::parse("buyer@example.com").unwrap(), "Pat"), "PO #7", Body::Html("<p>x</p>".into()));
        mail.add(Kind::Cc, Mailbox::bare(Email::parse("cc@example.com").unwrap()));
        mail.add(Kind::Bcc, Mailbox::bare(Email::parse("hidden@example.com").unwrap()));
        mail.reply_to = Email::parse("desk@example.com");
        let message = smtp.message(&mail).unwrap();
        let envelope: Vec<String> = message.envelope().to().iter().map(|a| a.to_string()).collect();
        assert_eq!(envelope, ["buyer@example.com", "cc@example.com", "hidden@example.com"]);
        let text = String::from_utf8_lossy(&message.formatted()).to_string();
        for header in ["From: Harcourt <web@example.com>", "To: Pat <buyer@example.com>", "Cc: cc@example.com", "Reply-To: desk@example.com", "Subject: PO #7"] {
            assert!(text.contains(header), "{header} in {text}");
        }
        assert!(!text.contains("hidden@example.com"));
        assert!(text.contains(ALT_BODY) && text.contains("<p>x</p>"));
    }

    #[test]
    fn duplicate_recipients_are_dropped() {
        let a = || Mailbox::bare(Email::parse("a@example.com").unwrap());
        let mut mail = Mail::new(a(), "s", Body::Text(String::new()));
        mail.add(Kind::Bcc, a());
        mail.add(Kind::Bcc, Mailbox::bare(Email::parse("b@example.com").unwrap()));
        mail.add(Kind::Cc, Mailbox::bare(Email::parse("b@example.com").unwrap()));
        assert_eq!(mail.also.len(), 1);
    }
}
