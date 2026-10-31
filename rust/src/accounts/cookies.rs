//! The cookies the accounts area sets. Only the session cookie is a credential; the rest are preferences and are
//! checked against the database each time they are read.

use axum::http::{header, HeaderMap, HeaderValue};
use percent_encoding::{percent_decode_str, utf8_percent_encode, AsciiSet, NON_ALPHANUMERIC};

use crate::{session, token::Token};

/// The address the browser last signed in or registered with (PHP's `harcourt`). It fills in forms and says
/// which address an emailed code is for. It grants nothing.
pub const EMAIL: &str = "harcourt";

const YEAR_SECS: i64 = 365 * 24 * 3600;

/// PHP urlencode keeps letters, digits and `-_.`.
const PHP_URLENCODE: &AsciiSet = &NON_ALPHANUMERIC.remove(b'-').remove(b'_').remove(b'.');

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Life {
    Year,
    /// Delete it.
    Gone,
}

/// The value of the first cookie called `name`, URL-decoded as PHP's $_COOKIE does.
pub fn get(headers: &HeaderMap, name: &str) -> Option<String> {
    headers
        .get_all(header::COOKIE)
        .iter()
        .filter_map(|v| v.to_str().ok())
        .flat_map(|v| v.split(';'))
        .filter_map(|pair| pair.trim().split_once('='))
        .find(|(n, _)| *n == name)
        .map(|(_, v)| percent_decode_str(&v.replace('+', " ")).decode_utf8_lossy().into_owned())
}

pub fn set(name: &str, value: &str, life: Life) -> Option<HeaderValue> {
    let value = if life == Life::Gone { String::new() } else { utf8_percent_encode(value, PHP_URLENCODE).to_string() };
    let age = match life {
        Life::Year => format!("; Max-Age={YEAR_SECS}"),
        Life::Gone => "; Max-Age=0".into(),
    };
    HeaderValue::from_str(&format!("{name}={value}; Path=/{age}; Secure; HttpOnly; SameSite=Lax")).ok()
}

/// PHP rawurlencode keeps letters, digits and `-_.~`.
const PHP_RAWURLENCODE: &AsciiSet = &PHP_URLENCODE.remove(b'~');

/// A cookie as scs_header.php menu_class::cookie() wrote it through PHP's setcookie(): kept for a year from `now`,
/// HTTPS only, hidden from scripts, with no SameSite.
pub fn php_year(name: &str, value: &str, now: i64) -> Option<HeaderValue> {
    let start = chrono::DateTime::from_timestamp(now, 0)?;
    let expires = start.checked_add_months(chrono::Months::new(12))?;
    let value = utf8_percent_encode(value, PHP_RAWURLENCODE);
    let (date, age) = (expires.format("%a, %d %b %Y %H:%M:%S GMT"), expires.timestamp() - now);
    HeaderValue::from_str(&format!("{name}={value}; expires={date}; Max-Age={age}; path=/; secure; HttpOnly")).ok()
}

/// The session cookie. A remembered sign-in keeps it for a year and lets the server's sliding expiry decide, as
/// PHP's `remote` cookie did.
pub fn session(token: &Token, remembered: bool) -> Option<HeaderValue> {
    let age = if remembered { YEAR_SECS } else { session::TTL_SECS };
    HeaderValue::from_str(&format!("{}={}; Path=/; Max-Age={age}; Secure; HttpOnly; SameSite=Lax", session::COOKIE, token.as_str())).ok()
}

pub fn session_gone() -> Option<HeaderValue> {
    HeaderValue::from_str(&format!("{}=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Lax", session::COOKIE)).ok()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn values_round_trip_through_php_url_encoding() {
        let v = set(EMAIL, "pat+tag@example.com", Life::Year).unwrap();
        let v = v.to_str().unwrap();
        assert!(v.starts_with("harcourt=pat%2Btag%40example.com; Path=/; Max-Age=31536000;"), "{v}");
        let mut headers = HeaderMap::new();
        headers.insert(header::COOKIE, HeaderValue::from_static("a=1; harcourt=pat%2Btag%40example.com; b=2"));
        assert_eq!(get(&headers, EMAIL).as_deref(), Some("pat+tag@example.com"));
        assert_eq!(get(&headers, "missing"), None);
    }

    #[test]
    fn php_year_is_setcookie_with_raw_url_encoding() {
        let v = php_year("harcourt_profile", r#"{"name":"A B~"}"#, 1_793_327_258).unwrap();
        assert_eq!(
            v.to_str().unwrap(),
            "harcourt_profile=%7B%22name%22%3A%22A%20B~%22%7D; expires=Sat, 30 Oct 2027 02:27:38 GMT; Max-Age=31536000; path=/; secure; HttpOnly"
        );
    }

    #[test]
    fn gone_clears_the_value() {
        let v = set(EMAIL, "pat@example.com", Life::Gone).unwrap();
        assert_eq!(v.to_str().unwrap(), "harcourt=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Lax");
    }
}
