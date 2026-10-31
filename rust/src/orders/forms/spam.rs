//! The spam checks the WordPress plugins made: gf-zero-spam's signed token (only a browser running the page's
//! script sends it), Akismet's comment check, and the registry's reCAPTCHA v3 threshold on the contact forms
//! (mu-plugins/registry-recaptcha-contact-forms.php). The outside calls sit behind traits; tests pass doubles.

use std::{future::Future, pin::Pin};

use hmac::{Hmac, Mac};
use sha2::Sha256;
use subtle::ConstantTimeEq;

/// How long a rendered form's token is accepted. Gravity Forms refuses form state older than two days.
pub const TOKEN_TTL_SECS: i64 = 2 * 86400;

/// Signs `form.issued_at`; the token is `issued_at.hex(mac)`.
pub struct TokenKey(Vec<u8>);

impl TokenKey {
    pub fn new(secret: &[u8]) -> Self {
        let mut m = Hmac::<Sha256>::new_from_slice(secret).expect("HMAC takes any key length");
        m.update(b"hxweb gravity forms token");
        TokenKey(m.finalize().into_bytes().to_vec())
    }

    fn mac(&self, form_id: i64, issued_at: i64) -> [u8; 32] {
        let mut m = Hmac::<Sha256>::new_from_slice(&self.0).expect("HMAC takes any key length");
        m.update(format!("{form_id}.{issued_at}").as_bytes());
        m.finalize().into_bytes().into()
    }

    pub fn issue(&self, form_id: i64, now: i64) -> String {
        format!("{now}.{}", hex::encode(self.mac(form_id, now)))
    }

    pub fn valid(&self, token: &str, form_id: i64, now: i64) -> bool {
        let Some((issued, mac)) = token.split_once('.') else { return false };
        let (Ok(issued), Ok(mac)) = (issued.parse::<i64>(), hex::decode(mac)) else { return false };
        let fresh = issued <= now + 60 && now - issued <= TOKEN_TTL_SECS;
        fresh && bool::from(self.mac(form_id, issued).as_slice().ct_eq(&mac))
    }
}

pub type CheckFuture<'a, T> = Pin<Box<dyn Future<Output = T> + Send + 'a>>;

/// What the Gravity Forms Akismet add-on told Akismet about a submission (class-akismet-fields-filter.php).
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Submission {
    pub ip: String,
    pub user_agent: String,
    pub referrer: String,
    pub permalink: String,
    pub author: String,
    pub email: String,
    pub content: String,
    /// The form's title.
    pub subject: String,
    /// `en_US` or `fr_FR`.
    pub lang: String,
    /// Akismet's own hidden fields: the honeypot textarea and the time its script wrote.
    pub ak_hp_textarea: String,
    pub ak_js: String,
}

pub trait Akismet: Send + Sync {
    /// True for spam. An unreachable service answers false, as the add-on did.
    fn is_spam<'a>(&'a self, s: &'a Submission) -> CheckFuture<'a, bool>;
}

pub trait Recaptcha: Send + Sync {
    /// The v3 score for this token and action, or None when it cannot be verified.
    fn score<'a>(&'a self, token: &'a str, ip: &'a str, action: &'a str) -> CheckFuture<'a, Option<f64>>;
}

pub struct AkismetHttp {
    pub key: String,
    pub blog: String,
    pub http: reqwest::Client,
}

impl Akismet for AkismetHttp {
    fn is_spam<'a>(&'a self, s: &'a Submission) -> CheckFuture<'a, bool> {
        Box::pin(async move {
            let url = format!("https://{}.rest.akismet.com/1.1/comment-check", self.key);
            let form = [
                ("blog", self.blog.as_str()),
                ("user_ip", s.ip.as_str()),
                ("user_agent", s.user_agent.as_str()),
                ("referrer", s.referrer.as_str()),
                ("permalink", s.permalink.as_str()),
                ("comment_type", "gravity_form"),
                ("comment_author", s.author.as_str()),
                ("comment_author_email", s.email.as_str()),
                ("comment_content", s.content.as_str()),
                ("comment_author_IP", s.ip.as_str()),
                ("contact_form_subject", s.subject.as_str()),
                ("blog_lang", s.lang.as_str()),
                ("blog_charset", "UTF-8"),
                ("POST_ak_hp_textarea", s.ak_hp_textarea.as_str()),
                ("POST_ak_js", s.ak_js.as_str()),
            ];
            match self.http.post(url).form(&form).send().await {
                Ok(r) => r.text().await.map(|t| t.trim() == "true").unwrap_or(false),
                Err(e) => {
                    tracing::warn!("akismet: {e}");
                    false
                }
            }
        })
    }
}

pub struct RecaptchaHttp {
    pub secret: String,
    pub http: reqwest::Client,
}

#[derive(serde::Deserialize)]
struct Verdict {
    success: bool,
    score: Option<f64>,
    action: Option<String>,
}

impl Recaptcha for RecaptchaHttp {
    fn score<'a>(&'a self, token: &'a str, ip: &'a str, action: &'a str) -> CheckFuture<'a, Option<f64>> {
        Box::pin(async move {
            if token.is_empty() {
                return None;
            }
            let form = [("secret", self.secret.as_str()), ("response", token), ("remoteip", ip)];
            let r = self.http.post("https://www.google.com/recaptcha/api/siteverify").form(&form).send().await.ok()?;
            let v: Verdict = r.json().await.ok()?;
            let action_ok = v.action.as_deref().is_none_or(|a| a == action);
            (v.success && action_ok).then_some(v.score).flatten()
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn tokens_are_bound_to_the_form_and_expire() {
        let k = TokenKey::new(&[7; 32]);
        let t = k.issue(3, 1000);
        assert!(k.valid(&t, 3, 1000 + 3600));
        assert!(!k.valid(&t, 4, 1000), "another form");
        assert!(!k.valid(&t, 3, 1000 + TOKEN_TTL_SECS + 1), "expired");
        assert!(!k.valid(&t, 3, 900), "issued in the future");
        assert!(!k.valid("1000.00", 3, 1000));
        assert!(!k.valid("", 3, 1000));
        assert!(!TokenKey::new(&[8; 32]).valid(&t, 3, 1000), "another key");
    }
}
