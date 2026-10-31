//! Warm sessions. PHP sends a visitor's first request of a session to a landing page, so a case that means "a
//! visitor already browsing" needs a PHP session that has seen one request. Each (persona, language) gets one,
//! primed once per run by a GET to the oracle and shared by every warm case of that pair, on both sides. The
//! language is part of the key because PHP fixes an anonymous visitor's language when the session starts.

use std::{
    collections::BTreeMap,
    future::Future,
    sync::{Arc, Mutex},
};

use tokio::sync::OnceCell;

use crate::persona::Lang;

/// The page that starts a session: the catalog fragment, a read with no effect beyond PHP's own visit log.
pub const PRIME_PATH: &str = "/catalog.php?scscpq_wp=1";

type Cell = Arc<OnceCell<Result<String, String>>>;

#[derive(Default)]
pub struct Sessions(Mutex<BTreeMap<(String, &'static str), Cell>>);

impl Sessions {
    /// The PHPSESSID for this persona and language, priming it with `prime` the first time it is asked for.
    /// Concurrent callers for the same pair wait for the one priming request; a failed priming is remembered.
    pub async fn sid<F, Fut>(&self, persona: &str, lang: Lang, prime: F) -> Result<String, String>
    where
        F: FnOnce() -> Fut,
        Fut: Future<Output = Result<String, String>>,
    {
        let cell = {
            let mut map = self.0.lock().map_err(|_| "warm session table poisoned".to_string())?;
            map.entry((persona.to_string(), lang.code())).or_default().clone()
        };
        cell.get_or_init(prime).await.clone()
    }
}

/// The PHPSESSID a response set, if it set one with an ordinary PHP session id.
pub fn phpsessid<'a>(set_cookies: impl IntoIterator<Item = &'a str>) -> Option<String> {
    set_cookies
        .into_iter()
        .filter_map(|c| c.split(';').next()?.trim().strip_prefix("PHPSESSID="))
        .find(|v| !v.is_empty() && v.len() <= 128 && v.bytes().all(|b| b.is_ascii_alphanumeric() || b == b',' || b == b'-'))
        .map(str::to_string)
}

/// The Cookie header for one side: the warm PHP session and, on the Rust side with --rust-session, the persona's
/// hxweb session. None when there is neither.
pub fn cookie_header(sid: Option<&str>, hx_session: Option<&str>) -> Option<String> {
    let parts: Vec<String> = sid
        .map(|s| format!("PHPSESSID={s}"))
        .into_iter()
        .chain(hx_session.map(|t| format!("{}={t}", hxweb::session::COOKIE)))
        .collect();
    (!parts.is_empty()).then(|| parts.join("; "))
}

#[cfg(test)]
mod tests {
    use std::sync::atomic::{AtomicUsize, Ordering};

    use super::*;

    #[tokio::test]
    async fn one_priming_per_persona_and_language() {
        let sessions = Sessions::default();
        let primed = AtomicUsize::new(0);
        let prime = |tag: &'static str| {
            let primed = &primed;
            move || async move {
                primed.fetch_add(1, Ordering::SeqCst);
                Ok::<_, String>(tag.to_string())
            }
        };
        let (a, b) = tokio::join!(
            sessions.sid("customer-active", Lang::En, prime("one")),
            sessions.sid("customer-active", Lang::En, prime("two"))
        );
        assert_eq!(a, b);
        assert_eq!(primed.load(Ordering::SeqCst), 1);
        assert_eq!(sessions.sid("customer-active", Lang::En, prime("three")).await, a);
        assert_eq!(sessions.sid("customer-active", Lang::Fr, prime("fr")).await, Ok("fr".to_string()));
        assert_eq!(sessions.sid("anonymous", Lang::En, prime("anon")).await, Ok("anon".to_string()));
        assert_eq!(primed.load(Ordering::SeqCst), 3);
    }

    #[tokio::test]
    async fn a_failed_priming_is_reported_to_every_case_and_not_retried() {
        let sessions = Sessions::default();
        let tries = AtomicUsize::new(0);
        for _ in 0..3 {
            let got = sessions
                .sid("anonymous", Lang::En, || async {
                    tries.fetch_add(1, Ordering::SeqCst);
                    Err::<String, _>("no session".to_string())
                })
                .await;
            assert_eq!(got, Err("no session".to_string()));
        }
        assert_eq!(tries.load(Ordering::SeqCst), 1);
    }

    #[test]
    fn only_a_php_session_id_is_taken_from_set_cookie() {
        assert_eq!(phpsessid(["PHPSESSID=abc123def; path=/; HttpOnly"]), Some("abc123def".into()));
        assert_eq!(phpsessid(["harcourt=1; path=/", "PHPSESSID=a-b,c; secure"]), Some("a-b,c".into()));
        assert_eq!(phpsessid(["harcourt=PHPSESSID=x"]), None);
        assert_eq!(phpsessid(["PHPSESSID=; path=/"]), None);
        assert_eq!(phpsessid(["PHPSESSID=a\"b"]), None);
        assert_eq!(phpsessid(Vec::<&str>::new()), None);
    }

    #[test]
    fn cookie_headers_combine_both_sessions() {
        assert_eq!(cookie_header(None, None), None);
        assert_eq!(cookie_header(Some("s1"), None).as_deref(), Some("PHPSESSID=s1"));
        assert_eq!(cookie_header(None, Some("t")).as_deref(), Some("__Host-hx_session=t"));
        assert_eq!(cookie_header(Some("s1"), Some("t")).as_deref(), Some("PHPSESSID=s1; __Host-hx_session=t"));
    }
}
