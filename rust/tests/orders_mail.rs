//! The outbox sender against an in-memory database and an in-memory transport. No test here can reach a mail server.

use hxweb::{
    db,
    identity::Email,
    orders::mail::{self, Body, Kind, Mail, Mailbox, MemoryTransport, SendError},
};
use sqlx::SqlitePool;

fn addr(s: &str) -> Email {
    Email::parse(s).unwrap()
}

fn sample() -> Mail {
    let mut m = Mail::new(Mailbox::new(addr("buyer@example.com"), "Pat Buyer"), "PO #1", Body::Html("<p>hi</p>".into()));
    m.add(Kind::Bcc, Mailbox::bare(addr("orders@example.com")));
    m.add(Kind::Cc, Mailbox::new(addr("boss@example.com"), "Boss"));
    m.reply_to = Some(addr("desk@example.com"));
    m
}

struct Row {
    sent_at: Option<i64>,
    failed_at: Option<i64>,
    attempts: i64,
    next_attempt_at: i64,
    body: String,
    last_error: Option<String>,
}

async fn row(pool: &SqlitePool, id: i64) -> Row {
    let (sent_at, failed_at, attempts, next_attempt_at, body, last_error) = sqlx::query_as::<_, (Option<i64>, Option<i64>, i64, i64, String, Option<String>)>(
        "SELECT sent_at, failed_at, attempts, next_attempt_at, body, last_error FROM outbox WHERE id = ?1",
    )
    .bind(id)
    .fetch_one(pool)
    .await
    .unwrap();
    Row { sent_at, failed_at, attempts, next_attempt_at, body, last_error }
}

#[tokio::test]
async fn a_queued_mail_is_sent_once_and_its_body_cleared() {
    let pool = db::open_memory().await.unwrap();
    let id = mail::enqueue(&pool, &sample(), 1000).await.unwrap();
    let t = MemoryTransport::default();
    assert_eq!(mail::deliver_due(&pool, &t, 1000).await.unwrap(), 1);
    assert_eq!(t.sent(), vec![sample()]);
    let r = row(&pool, id).await;
    assert_eq!((r.sent_at, r.attempts, r.body.as_str()), (Some(1000), 1, ""));
    assert_eq!(mail::deliver_due(&pool, &t, 5000).await.unwrap(), 0);
    assert_eq!(t.sent().len(), 1);
}

#[tokio::test]
async fn a_transient_failure_backs_off_then_succeeds() {
    let pool = db::open_memory().await.unwrap();
    let id = mail::enqueue(&pool, &sample(), 1000).await.unwrap();
    let t = MemoryTransport::default();
    t.fail_next(SendError::Retry("421 busy".into()));
    t.fail_next(SendError::Retry("421 busy".into()));
    mail::deliver_due(&pool, &t, 1000).await.unwrap();
    let r = row(&pool, id).await;
    assert_eq!((r.attempts, r.next_attempt_at, r.sent_at, r.last_error.as_deref()), (1, 1060, None, Some("421 busy")));
    assert_eq!(mail::deliver_due(&pool, &t, 1059).await.unwrap(), 0, "not due before its backoff");
    mail::deliver_due(&pool, &t, 1060).await.unwrap();
    assert_eq!(row(&pool, id).await.next_attempt_at, 1060 + 120);
    mail::deliver_due(&pool, &t, 1180).await.unwrap();
    let r = row(&pool, id).await;
    assert_eq!((r.sent_at, r.attempts, r.last_error), (Some(1180), 3, None));
    assert_eq!(t.sent().len(), 1);
}

#[tokio::test]
async fn a_rejected_mail_fails_at_once_and_keeps_its_body() {
    let pool = db::open_memory().await.unwrap();
    let id = mail::enqueue(&pool, &sample(), 1000).await.unwrap();
    let t = MemoryTransport::default();
    t.fail_next(SendError::Reject("550 no such user".into()));
    mail::deliver_due(&pool, &t, 1000).await.unwrap();
    let r = row(&pool, id).await;
    assert_eq!((r.failed_at, r.sent_at, r.body.as_str()), (Some(1000), None, "<p>hi</p>"));
    assert_eq!(mail::deliver_due(&pool, &t, 1_000_000).await.unwrap(), 0);
}

#[tokio::test]
async fn retries_stop_after_the_last_attempt() {
    let pool = db::open_memory().await.unwrap();
    let id = mail::enqueue(&pool, &sample(), 0).await.unwrap();
    let t = MemoryTransport::default();
    let mut now = 0;
    for _ in 0..mail::MAX_ATTEMPTS {
        t.fail_next(SendError::Retry("timeout".into()));
        assert_eq!(mail::deliver_due(&pool, &t, now).await.unwrap(), 1);
        now = row(&pool, id).await.next_attempt_at;
    }
    let r = row(&pool, id).await;
    assert_eq!((r.attempts, r.failed_at.is_some()), (mail::MAX_ATTEMPTS, true));
    assert_eq!(mail::deliver_due(&pool, &t, now + 1_000_000).await.unwrap(), 0);
}

#[tokio::test]
async fn a_message_queued_by_sign_in_still_sends_as_plain_text() {
    let pool = db::open_memory().await.unwrap();
    sqlx::query("INSERT INTO outbox (to_email, subject, body, created_at) VALUES ('a@example.com', 'Code', 'ABC', 5)")
        .execute(&pool)
        .await
        .unwrap();
    let t = MemoryTransport::default();
    mail::deliver_due(&pool, &t, 5).await.unwrap();
    assert_eq!(t.sent(), vec![Mail::new(Mailbox::bare(addr("a@example.com")), "Code", Body::Text("ABC".into()))]);
}

#[tokio::test]
async fn a_leased_message_is_not_sent_by_a_second_worker() {
    let pool = db::open_memory().await.unwrap();
    let id = mail::enqueue(&pool, &sample(), 1000).await.unwrap();
    sqlx::query("UPDATE outbox SET next_attempt_at = ?1 WHERE id = ?2").bind(1000 + mail::LEASE_SECS).bind(id).execute(&pool).await.unwrap();
    let t = MemoryTransport::default();
    assert_eq!(mail::deliver_due(&pool, &t, 1001).await.unwrap(), 0);
    assert_eq!(mail::deliver_due(&pool, &t, 1000 + mail::LEASE_SECS).await.unwrap(), 1, "a crashed send comes back after its lease");
}
