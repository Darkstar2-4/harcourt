//! Mail built from what visitors typed: names, subjects and bodies of any text go through the outbox and come out
//! as SMTP messages whose headers are the ones the site writes, one line each.

use std::sync::{Arc, Mutex, OnceLock};

use hxweb::{
    db,
    identity::Email,
    orders::mail::{self, Body, Kind, Mail, Mailbox, SendFuture, Smtp, SmtpConfig, Transport},
};
use hxweb_fuzz::{gen, heavy};
use proptest::prelude::*;
use sqlx::SqlitePool;

const HEADERS: &[&str] = &["From", "Reply-To", "To", "Cc", "Bcc", "Subject", "Date", "MIME-Version", "Content-Type", "Content-Transfer-Encoding", "Message-ID"];

fn smtp() -> &'static Smtp {
    static SMTP: OnceLock<Smtp> = OnceLock::new();
    SMTP.get_or_init(|| {
        let config = SmtpConfig::from_env(|k| match k {
            "HXWEB_SMTP_USER" => Some("sender@example.com".into()),
            "HXWEB_SMTP_PASSWORD" => Some("unused".into()),
            "HXWEB_SMTP_HOST" => Some("localhost".into()),
            _ => None,
        });
        Smtp::new(config.unwrap().unwrap()).unwrap()
    })
}

/// Builds each message the worker hands it, as the production transport would before sending.
#[derive(Default)]
struct Build(Mutex<Vec<Result<Vec<u8>, String>>>);

impl Transport for Build {
    fn send<'a>(&'a self, m: &'a Mail) -> SendFuture<'a> {
        let built = smtp().message(m).map(|msg| msg.formatted()).map_err(|e| match e {
            mail::SendError::Retry(s) | mail::SendError::Reject(s) => s,
        });
        self.0.lock().unwrap().push(built);
        Box::pin(async { Ok(()) })
    }
}

/// This thread's runtime and outbox, kept across cases: the worker sends every due message, so each case sees its own.
fn outbox() -> &'static (tokio::runtime::Runtime, SqlitePool) {
    thread_local! {
        static OUTBOX: &'static (tokio::runtime::Runtime, SqlitePool) = {
            let rt = tokio::runtime::Builder::new_current_thread().enable_all().build().unwrap();
            let pool = rt.block_on(db::open_memory()).unwrap();
            Box::leak(Box::new((rt, pool)))
        };
    }
    OUTBOX.with(|o| *o)
}

fn address() -> impl Strategy<Value = Email> {
    "[a-z0-9]{1,6}([._%+-][a-z0-9]{1,4}){0,2}@[a-z0-9]([a-z0-9-]{0,8}[a-z0-9])?\\.(com|org|co|fr)".prop_map(|s| Email::parse(&s).expect("a well-formed address"))
}

fn kind() -> impl Strategy<Value = Kind> {
    prop_oneof![Just(Kind::To), Just(Kind::Cc), Just(Kind::Bcc)]
}

fn header_problem(message: &[u8]) -> Option<String> {
    let text = String::from_utf8_lossy(message);
    let Some((head, _)) = text.split_once("\r\n\r\n") else { return Some("no blank line after the headers".into()) };
    let mut seen = Vec::new();
    for line in head.split("\r\n") {
        if line.contains(['\r', '\n']) {
            return Some(format!("a bare line break in {line:?}"));
        }
        if line.starts_with([' ', '\t']) {
            continue;
        }
        let Some((name, _)) = line.split_once(':') else { return Some(format!("no colon in {line:?}")) };
        if !HEADERS.contains(&name) {
            return Some(format!("header {name:?}"));
        }
        seen.push(name.to_string());
    }
    for once in ["From", "Subject", "To"] {
        if seen.iter().filter(|n| *n == once).count() != 1 {
            return Some(format!("{once} {} times", seen.iter().filter(|n| *n == once).count()));
        }
    }
    None
}

proptest! {
    #![proptest_config(heavy(1024, 5))]

    #[test]
    fn queued_mail_builds_messages_with_one_line_headers(
        to in address(), name in gen::text(40), subject in gen::text(80), from_name in proptest::option::of(gen::text(40)),
        reply in proptest::option::of(address()), also in proptest::collection::vec((kind(), address(), gen::text(20)), 0..4),
        body in gen::text(300), html in any::<bool>(),
    ) {
        let (rt, pool) = outbox();
        let built = rt.block_on(async {
            let mut m = Mail::new(Mailbox::new(to, &name), &subject, if html { Body::Html(body) } else { Body::Text(body) });
            m.reply_to = reply;
            m.from_name = from_name;
            for (k, e, n) in also {
                m.add(k, Mailbox::new(e, &n));
            }
            prop_assert!(!m.subject.chars().any(char::is_control) && !m.to.name.chars().any(char::is_control));
            mail::enqueue(pool, &m, 1_700_000_000).await.unwrap();
            let build = Arc::new(Build::default());
            mail::deliver_due(pool, build.as_ref(), 1_700_000_000).await.unwrap();
            sqlx::query("DELETE FROM outbox_recipient").execute(pool).await.unwrap();
            sqlx::query("DELETE FROM outbox").execute(pool).await.unwrap();
            let built = std::mem::take(&mut *build.0.lock().unwrap());
            Ok(built)
        })?;
        prop_assert_eq!(built.len(), 1);
        for b in built {
            let message = b.map_err(TestCaseError::fail)?;
            prop_assert!(header_problem(&message).is_none(), "{:?}\n{}", header_problem(&message), String::from_utf8_lossy(&message));
        }
    }
}
