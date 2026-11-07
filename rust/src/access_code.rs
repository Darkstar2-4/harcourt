//! The emailed sign-in code. The PHP version was `dechex(time() + 900)` (classes/remote.php), so anyone who knew
//! when the mail went out knew the code, and its three-try counter lived in the browser session.
//!
//! Here the code is 8 characters drawn from the system CSPRNG (about 40 bits), valid for 15 minutes, stored only
//! as an HMAC, allowed a few wrong tries counted in the database, and consumed by a correct one.

use hmac::{Hmac, Mac};
use rand::{rngs::OsRng, Rng};
use sha2::Sha256;
use sqlx::SqlitePool;
use subtle::ConstantTimeEq;

use crate::{config::Secret, identity::Email};

pub const TTL_SECS: i64 = 900;
pub const RESEND_AFTER_SECS: i64 = 60;
const LEN: usize = 8;
/// No 0/O, 1/I/L: a code is read off a screen and typed.
const ALPHABET: &[u8] = b"23456789ABCDEFGHJKMNPQRSTUVWXYZ";

fn generate() -> String {
    (0..LEN).map(|_| ALPHABET[OsRng.gen_range(0..ALPHABET.len())] as char).collect()
}

/// Upper-case, spaces and hyphens removed. None when what is left cannot be a code, so junk costs no attempt.
pub fn normalise(typed: &str) -> Option<String> {
    let code: String = typed.chars().filter(|c| !c.is_whitespace() && *c != '-').collect::<String>().to_ascii_uppercase();
    (code.len() == LEN && code.bytes().all(|c| ALPHABET.contains(&c))).then_some(code)
}

fn mac(secret: &Secret, email: &Email, code: &str) -> [u8; 32] {
    let mut m = Hmac::<Sha256>::new_from_slice(secret.as_bytes()).expect("HMAC takes any key length");
    m.update(email.as_str().as_bytes());
    m.update(&[0]);
    m.update(code.as_bytes());
    m.finalize().into_bytes().into()
}

/// Make a code for `email` and queue the mail. False when one was sent less than a minute ago; the caller answers
/// the same either way.
pub async fn issue(pool: &SqlitePool, secret: &Secret, email: &Email, now: i64) -> sqlx::Result<bool> {
    let code = generate();
    let code_mac = mac(secret, email, &code);
    let code_mac = code_mac.as_slice();
    let email = email.as_str();
    let expires_at = now + TTL_SECS;
    let mut tx = pool.begin().await?;
    let resend_floor = now - RESEND_AFTER_SECS;
    let upsert = sqlx::query!(
        "INSERT INTO access_code (email, code_mac, sent_at, expires_at, attempts) VALUES (?1, ?2, ?3, ?4, 0)
         ON CONFLICT (email) DO UPDATE SET code_mac = excluded.code_mac, sent_at = excluded.sent_at,
             expires_at = excluded.expires_at, attempts = 0
         WHERE access_code.sent_at <= ?5",
        email,
        code_mac,
        now,
        expires_at,
        resend_floor
    )
    .execute(&mut *tx)
    .await?;
    if upsert.rows_affected() == 0 {
        return Ok(false);
    }
    let body = format!("Your Harcourt sign-in code is {code}. It works for 15 minutes. If you did not ask for it, ignore this mail.");
    sqlx::query!(
        "INSERT INTO outbox (to_email, subject, body, created_at) VALUES (?1, 'Your Harcourt sign-in code', ?2, ?3)",
        email,
        body,
        now
    )
    .execute(&mut *tx)
    .await?;
    tx.commit().await?;
    Ok(true)
}

/// What one try at the sign-up page's code box came to (newuser.php json_verify).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Attempt {
    Verified,
    /// Wrong, and this many tries are now used.
    Wrong(i64),
    /// `limit` tries are used up; only a new code helps.
    Locked,
    /// No code is waiting for this address, or it ran out of time.
    Expired,
}

/// One try against the live code for `email`, counted in the database. `typed` None spends a try without looking
/// at the code (the PHP counted a failed reCAPTCHA that way); junk costs a try, as it did there.
pub async fn attempt(pool: &SqlitePool, secret: &Secret, email: &Email, typed: Option<&str>, now: i64, limit: i64) -> sqlx::Result<Attempt> {
    let email_str = email.as_str();
    let spent = sqlx::query!(
        r#"UPDATE access_code SET attempts = attempts + 1
           WHERE email = ?1 AND expires_at > ?2 AND attempts < ?3
           RETURNING code_mac AS "code_mac!", attempts AS "attempts!: i64""#,
        email_str,
        now,
        limit
    )
    .fetch_optional(pool)
    .await?;
    let Some(spent) = spent else {
        let live = sqlx::query_scalar!(r#"SELECT 1 AS "x!: i64" FROM access_code WHERE email = ?1 AND expires_at > ?2"#, email_str, now)
            .fetch_optional(pool)
            .await?;
        return Ok(if live.is_some() { Attempt::Locked } else { Attempt::Expired });
    };
    let Some(code) = typed.and_then(normalise) else { return Ok(Attempt::Wrong(spent.attempts)) };
    let expected = mac(secret, email, &code);
    let expected = expected.as_slice();
    if !bool::from(spent.code_mac.as_slice().ct_eq(expected)) {
        return Ok(Attempt::Wrong(spent.attempts));
    }
    let consumed = sqlx::query!("DELETE FROM access_code WHERE email = ?1 AND code_mac = ?2", email_str, expected).execute(pool).await?;
    Ok(if consumed.rows_affected() == 1 { Attempt::Verified } else { Attempt::Expired })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn generated_codes_use_the_alphabet_and_vary() {
        let a = generate();
        assert_eq!(a.len(), LEN);
        assert!(a.bytes().all(|c| ALPHABET.contains(&c)));
        assert_ne!(a, generate());
    }

    #[test]
    fn typed_codes_normalise() {
        assert_eq!(normalise(" ab3d-efgh ").as_deref(), Some("AB3DEFGH"));
        for bad in ["", "ABC", "AB3DEFG0", "AB3DEFGHJ", "AB3DEFG."] {
            assert!(normalise(bad).is_none(), "{bad:?}");
        }
    }

    const T: i64 = 1_700_000_000;

    async fn setup() -> (SqlitePool, Secret, Email) {
        (crate::db::open_memory().await.unwrap(), Secret::from_bytes(vec![7; 32]), Email::parse("pat@example.com").unwrap())
    }

    async fn mailed_code(pool: &SqlitePool) -> String {
        let body: String = sqlx::query_scalar("SELECT body FROM outbox ORDER BY id DESC LIMIT 1").fetch_one(pool).await.unwrap();
        body.split("code is ").nth(1).unwrap().chars().take(LEN).collect()
    }

    fn wrong(code: &str) -> &'static str {
        if code.starts_with('2') { "3AAAAAAA" } else { "2AAAAAAA" }
    }

    #[tokio::test]
    async fn a_code_works_once() {
        let (pool, secret, email) = setup().await;
        assert!(issue(&pool, &secret, &email, T).await.unwrap());
        let code = mailed_code(&pool).await;
        assert_eq!(attempt(&pool, &secret, &email, Some(&code.to_lowercase()), T + 5, 2).await.unwrap(), Attempt::Verified);
        assert_eq!(attempt(&pool, &secret, &email, Some(&code), T + 6, 2).await.unwrap(), Attempt::Expired, "used");
        let other = Email::parse("lee@example.com").unwrap();
        assert_eq!(attempt(&pool, &secret, &other, Some(&code), T + 6, 2).await.unwrap(), Attempt::Expired, "another address's code");
    }

    #[tokio::test]
    async fn a_code_ends_after_fifteen_minutes() {
        let (pool, secret, email) = setup().await;
        issue(&pool, &secret, &email, T).await.unwrap();
        let code = mailed_code(&pool).await;
        assert_eq!(attempt(&pool, &secret, &email, Some(&code), T + TTL_SECS, 2).await.unwrap(), Attempt::Expired);
    }

    #[tokio::test]
    async fn wrong_codes_use_up_the_tries_and_then_the_right_one_is_refused() {
        let (pool, secret, email) = setup().await;
        issue(&pool, &secret, &email, T).await.unwrap();
        let code = mailed_code(&pool).await;
        assert_eq!(attempt(&pool, &secret, &email, Some(wrong(&code)), T + 1, 2).await.unwrap(), Attempt::Wrong(1));
        assert_eq!(attempt(&pool, &secret, &email, None, T + 2, 2).await.unwrap(), Attempt::Wrong(2));
        assert_eq!(attempt(&pool, &secret, &email, Some(&code), T + 3, 2).await.unwrap(), Attempt::Locked);
        assert!(issue(&pool, &secret, &email, T + RESEND_AFTER_SECS).await.unwrap(), "a new code starts over");
        let code = mailed_code(&pool).await;
        assert_eq!(attempt(&pool, &secret, &email, Some(&code), T + RESEND_AFTER_SECS + 1, 2).await.unwrap(), Attempt::Verified);
    }

    #[tokio::test]
    async fn a_second_code_within_a_minute_is_not_sent() {
        let (pool, secret, email) = setup().await;
        assert!(issue(&pool, &secret, &email, T).await.unwrap());
        assert!(!issue(&pool, &secret, &email, T + RESEND_AFTER_SECS - 1).await.unwrap());
        let mails: i64 = sqlx::query_scalar("SELECT count(*) FROM outbox").fetch_one(&pool).await.unwrap();
        assert_eq!(mails, 1);
        let code = mailed_code(&pool).await;
        assert_eq!(attempt(&pool, &secret, &email, Some(&code), T + 2, 2).await.unwrap(), Attempt::Verified, "the first code still stands");
    }
}
