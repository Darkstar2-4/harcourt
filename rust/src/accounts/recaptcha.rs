//! reCAPTCHA v3 (includes/recaptcha.php). Off when the registry has no `recaptcha.version`, as in the PHP. When on,
//! a token Google does not vouch for scores 0: a failed check, a missing score or a different action is not a pass.

use serde::Deserialize;
use sqlx::SqlitePool;

use crate::accounts::{
    html::{self, Opts},
    registry,
};

pub struct Recaptcha {
    site_key: String,
    secret: String,
    /// The registry's `score_spam`, as text for the log line and as a number for the test.
    spam_text: String,
    spam: f64,
}

/// A score with the text the PHP printed for it: Google's score with one decimal, or a bare 0 or 1.
#[derive(Debug, Clone, PartialEq)]
pub struct Score {
    pub value: f64,
    pub text: String,
}

impl Score {
    fn whole(value: f64) -> Score {
        Score { value, text: format!("{value:.0}") }
    }

    fn google(value: f64) -> Score {
        let value = (value * 10.0).round() / 10.0;
        Score { value, text: format!("{value:.1}") }
    }
}

pub const NEWUSER: &str = "newuser";

#[derive(Deserialize)]
struct Verdict {
    #[serde(default)]
    success: bool,
    score: Option<f64>,
    action: Option<String>,
}

impl Recaptcha {
    pub async fn load(pool: &SqlitePool) -> sqlx::Result<Option<Recaptcha>> {
        if registry::get(pool, "recaptcha", "version").await?.is_none() {
            return Ok(None);
        }
        let spam_text = registry::get(pool, "recaptcha", "score_spam").await?.unwrap_or_default();
        Ok(Some(Recaptcha {
            site_key: registry::get(pool, "recaptcha", "site_key").await?.unwrap_or_default(),
            secret: registry::get(pool, "recaptcha", "private_key").await?.unwrap_or_default(),
            spam: spam_text.trim().parse().unwrap_or(0.0),
            spam_text,
        }))
    }

    pub async fn score(&self, http: &reqwest::Client, token: &str, action: &str, remote_ip: &str) -> Score {
        if token.is_empty() {
            return Score::whole(0.0);
        }
        let form = [("secret", self.secret.as_str()), ("response", token), ("remoteip", remote_ip)];
        let verdict = async {
            let response = http.post("https://www.google.com/recaptcha/api/siteverify").form(&form).send().await.ok()?;
            response.json::<Verdict>().await.ok()
        }
        .await;
        match verdict {
            Some(Verdict { success: true, score: Some(s), action: Some(a) }) if a == action => Score::google(s),
            _ => Score::whole(0.0),
        }
    }

    /// The registry's threshold as the PHP logged it.
    pub fn spam_threshold(&self) -> &str {
        &self.spam_text
    }

    /// newuser.php recaptcha_spam(): at or below the threshold. The caller checks the bypass list.
    pub fn is_spam(&self, score: &Score) -> bool {
        score.value <= self.spam
    }
}

/// The script tag the page needs, when reCAPTCHA is on (recaptcha_class js() with `local`).
pub fn js(rc: Option<&Recaptcha>) -> &'static str {
    if rc.is_some() {
        "<script src='https://www.google.com/recaptcha/api.js'></script>"
    } else {
        ""
    }
}

/// recaptcha_class button(): a reCAPTCHA-bound button, or a plain submit when reCAPTCHA is off.
/// `id` and `callback` default to recaptcha_button and recaptcha_script.
pub fn button(rc: Option<&Recaptcha>, label: &str, class: &str, id: Option<&str>, callback: Option<&str>) -> String {
    let Some(rc) = rc else {
        let mut o = Opts::new().attr("class", class);
        if let Some(id) = id {
            o = o.attr("id", id);
        }
        if let Some(cb) = callback {
            o = o.attr("data-callback", cb);
        }
        return html::submit(label, o);
    };
    let mut o = Opts::new().attr("class", format!("g-recaptcha {class}")).attr("id", id.unwrap_or("recaptcha_button"));
    if let Some(cb) = callback {
        o = o.attr("data-callback", cb);
    }
    o = o.attr("data-sitekey", rc.site_key.as_str());
    if callback.is_none() {
        o = o.attr("data-callback", "recaptcha_script");
    }
    html::button(label, o.attr("data-action", NEWUSER))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn rc() -> Recaptcha {
        Recaptcha { site_key: "SITE".into(), secret: "s".into(), spam_text: "0.5".into(), spam: 0.5 }
    }

    #[test]
    fn scores_print_as_the_php_did_and_the_threshold_counts_as_spam() {
        assert_eq!(Score::google(0.9).text, "0.9");
        assert_eq!(Score::google(1.0).text, "1.0");
        assert_eq!(Score::whole(0.0).text, "0");
        assert!(rc().is_spam(&Score::google(0.5)));
        assert!(!rc().is_spam(&Score::google(0.6)));
    }

    #[tokio::test]
    async fn a_missing_token_scores_zero_without_asking_google() {
        assert_eq!(rc().score(&reqwest::Client::new(), "", NEWUSER, "192.0.2.1").await, Score::whole(0.0));
    }

    #[test]
    fn buttons_carry_the_site_key_callback_and_action_in_the_php_order() {
        assert_eq!(
            button(Some(&rc()), "Submit", "red-button", None, None),
            "<input type=button class='g-recaptcha red-button' id='recaptcha_button' value='Submit' data-sitekey='SITE' data-callback='recaptcha_script' data-action='newuser'>\n"
        );
        assert_eq!(
            button(Some(&rc()), "Submit", "red-button", Some("recaptcha_button_verify"), Some("recaptcha_script_verify")),
            "<input type=button class='g-recaptcha red-button' id='recaptcha_button_verify' value='Submit' data-callback='recaptcha_script_verify' data-sitekey='SITE' data-action='newuser'>\n"
        );
        assert_eq!(button(None, "Submit", "red-button", None, None), "<input type=submit class='red-button' value='Submit'>\n");
    }
}
