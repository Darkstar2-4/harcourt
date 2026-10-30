//! The emailed sign-in code. The PHP version was `dechex(time() + 900)` (classes/remote.php), so anyone who knew
//! when the mail went out knew the code, and its three-try counter lived in the browser session.
//!
//! Here the code is 8 characters drawn from the system CSPRNG (about 40 bits), valid for 15 minutes, stored only
//! as an HMAC, allowed 5 wrong tries counted in the database, and consumed by a correct one.

use hmac::{Hmac, Mac};
use rand::{rngs::OsRng, Rng};
use sha2::Sha256;
use sqlx::SqlitePool;
use subtle::ConstantTimeEq;

use crate::{config::Secret, identity::Email};

pub const TTL_SECS: i64 = 900;
pub const MAX_ATTEMPTS: i64 = 5;
pub const RESEND_AFTER_SECS: i64 = 60;
const LEN: usize = 8;
/// No 0/O, 1/I/L: a code is read off a screen and typed.
const ALPHABET: &[u8] = b"23456789ABCDEFGHJKMNPQRSTUVWXYZ";

fn generate() -> String {
    (0..LEN).map(|_| ALPHABET[OsRng.gen_range(0..ALPHABET.len())] as char).collect()
}

/// Upper-case, spaces and hyphens removed. None when what is left cannot be a code, so junk costs no attempt.
fn normalise(typed: &str) -> Option<String> {
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

/// True once, for the right code, within time and attempts. Every other outcome is the same false.
pub async fn verify(pool: &SqlitePool, secret: &Secret, email: &Email, typed: &str, now: i64) -> sqlx::Result<bool> {
    let Some(code) = normalise(typed) else { return Ok(false) };
    let email_str = email.as_str();
    let stored = sqlx::query_scalar!(
        r#"UPDATE access_code SET attempts = attempts + 1
           WHERE email = ?1 AND expires_at > ?2 AND attempts < ?3
           RETURNING code_mac AS "code_mac!""#,
        email_str,
        now,
        MAX_ATTEMPTS
    )
    .fetch_optional(pool)
    .await?;
    let Some(stored) = stored else { return Ok(false) };
    let expected = mac(secret, email, &code);
    let expected = expected.as_slice();
    if !bool::from(stored.as_slice().ct_eq(expected)) {
        return Ok(false);
    }
    let consumed = sqlx::query!("DELETE FROM access_code WHERE email = ?1 AND code_mac = ?2", email_str, expected)
        .execute(pool)
        .await?;
    Ok(consumed.rows_affected() == 1)
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
        for bad in ["", "ABC", "AB3DEFG0", "AB3DEFGHJ", "AB3DEFG'"] {
            assert!(normalise(bad).is_none(), "{bad:?}");
        }
    }
}
