//! Every staff form carries a token bound to the browser's session, and every POST that changes something must
//! return it. The PHP pages had none, so any site a signed-in staff member visited could post to them.
//!
//! The token is a SHA-256 of the session cookie under a fixed label. Only the browser holding the cookie can
//! compute it, it changes with every session, and nothing needs storing. It differs from the hash the `session`
//! table keeps, so reading that table yields no tokens.

use std::sync::Arc;

use axum::{
    body::Bytes,
    extract::{FromRequest, Multipart, Request},
    http::{header, HeaderMap, StatusCode},
    response::{IntoResponse, Response},
};
use base64::{engine::general_purpose::URL_SAFE_NO_PAD, Engine};
use sha2::{Digest, Sha256};
use subtle::ConstantTimeEq;

use crate::api::{session_cookie, Auth};

pub const FIELD: &str = "csrf";

pub struct CsrfToken(String);

impl CsrfToken {
    pub fn for_request(headers: &HeaderMap) -> Option<CsrfToken> {
        let session = session_cookie(headers)?;
        let mut h = Sha256::new();
        h.update(b"hxweb staff form\0");
        h.update(session.as_str().as_bytes());
        Some(CsrfToken(URL_SAFE_NO_PAD.encode(h.finalize())))
    }

    pub fn as_str(&self) -> &str {
        &self.0
    }

    fn matches(&self, given: &str) -> bool {
        self.0.as_bytes().ct_eq(given.as_bytes()).into()
    }
}

/// The fields of a request, in the order sent, as PHP's `$_REQUEST` saw them. A missing field reads as "".
#[derive(Default, Debug, Clone)]
pub struct Fields(Vec<(String, String)>);

impl Fields {
    pub fn from_query(query: Option<&str>) -> Fields {
        Fields(query.map(|q| form_urlencoded::parse(q.as_bytes()).into_owned().collect()).unwrap_or_default())
    }

    /// The last value sent under the name, as parse_str() kept it.
    pub fn get(&self, name: &str) -> &str {
        self.0.iter().rev().find(|(k, _)| k == name).map_or("", |(_, v)| v.as_str())
    }

    pub fn has(&self, name: &str) -> bool {
        self.0.iter().any(|(k, _)| k == name)
    }

    pub fn all<'a>(&'a self, name: &'a str) -> impl Iterator<Item = &'a str> + 'a {
        self.0.iter().filter(move |(k, _)| k == name).map(|(_, v)| v.as_str())
    }

    pub fn iter(&self) -> impl Iterator<Item = (&str, &str)> {
        self.0.iter().map(|(k, v)| (k.as_str(), v.as_str()))
    }

    /// `intval()`: leading digits, 0 otherwise.
    pub fn int(&self, name: &str) -> i64 {
        php_intval(self.get(name))
    }

    /// A checkbox: PHP stored whatever was posted, and the boxes post "1".
    pub fn checked(&self, name: &str) -> bool {
        !matches!(self.get(name).trim(), "" | "0")
    }

    /// Fields from the form, then the query string for names the form did not send (`$_REQUEST` order).
    pub fn with_query(mut self, query: &Fields) -> Fields {
        let missing: Vec<(String, String)> = query.0.iter().filter(|(k, _)| !self.has(k)).cloned().collect();
        self.0.extend(missing);
        self
    }

    pub fn posted(pairs: Vec<(String, String)>) -> Fields {
        Fields(pairs)
    }

    #[cfg(test)]
    pub fn of(pairs: &[(&str, &str)]) -> Fields {
        Fields(pairs.iter().map(|(k, v)| (k.to_string(), v.to_string())).collect())
    }
}

pub fn php_intval(s: &str) -> i64 {
    let s = s.trim_start();
    let (sign, digits) = match s.strip_prefix('-') {
        Some(rest) => (-1, rest),
        None => (1, s.strip_prefix('+').unwrap_or(s)),
    };
    let end = digits.bytes().take_while(u8::is_ascii_digit).count();
    digits[..end].parse::<i64>().map_or(0, |n| sign * n)
}

pub struct Upload {
    pub field: String,
    pub file_name: String,
    pub bytes: Bytes,
}

/// A staff form POST whose CSRF token matched. URL-encoded or multipart; files arrive in `uploads`.
pub struct Posted {
    pub fields: Fields,
    pub uploads: Vec<Upload>,
}

impl Posted {
    pub fn upload(&self, field: &str) -> Option<&Upload> {
        self.uploads.iter().find(|u| u.field == field && !u.bytes.is_empty())
    }
}

pub enum Rejected {
    Token,
    Body,
}

impl IntoResponse for Rejected {
    fn into_response(self) -> Response {
        let (status, text) = match self {
            Rejected::Token => (StatusCode::FORBIDDEN, "This form has expired. Reload the page and try again.\n"),
            Rejected::Body => (StatusCode::BAD_REQUEST, "The form could not be read.\n"),
        };
        (status, [(header::CACHE_CONTROL, "no-store"), (header::CONTENT_TYPE, "text/plain; charset=utf-8")], text).into_response()
    }
}

impl FromRequest<Arc<Auth>> for Posted {
    type Rejection = Rejected;

    async fn from_request(req: Request, state: &Arc<Auth>) -> Result<Self, Rejected> {
        Unchecked::from_request(req, state).await?.accept(true)
    }
}

/// A staff form POST before its token is checked, for a page that also posts to only show something (a list, a
/// record's form, a cancel), as the PHP pages did. A post that changes nothing needs no token; `accept` hands the
/// post on, and refuses it without the token when the action it carries writes.
pub struct Unchecked {
    posted: Posted,
    token_ok: bool,
}

impl Unchecked {
    pub fn fields(&self) -> &Fields {
        &self.posted.fields
    }

    pub fn accept(self, writes: bool) -> Result<Posted, Rejected> {
        if writes && !self.token_ok {
            return Err(Rejected::Token);
        }
        Ok(self.posted)
    }

    /// A page whose posts never write takes them as they come.
    pub fn read_only(self) -> Posted {
        self.posted
    }

    /// The maintenance pages' rule: a post whose `action` is `update` or `delete` writes.
    pub fn accept_by_action(self) -> Result<Posted, Rejected> {
        let writes = matches!(self.fields().get("action"), "update" | "delete");
        self.accept(writes)
    }
}

impl FromRequest<Arc<Auth>> for Unchecked {
    type Rejection = Rejected;

    async fn from_request(req: Request, state: &Arc<Auth>) -> Result<Self, Rejected> {
        let expected = CsrfToken::for_request(req.headers());
        let is_multipart = req
            .headers()
            .get(header::CONTENT_TYPE)
            .and_then(|v| v.to_str().ok())
            .is_some_and(|v| v.to_ascii_lowercase().starts_with("multipart/form-data"));
        let (fields, uploads) = if is_multipart {
            let mut multipart = Multipart::from_request(req, state).await.map_err(|_| Rejected::Body)?;
            let mut fields = Vec::new();
            let mut uploads = Vec::new();
            while let Some(part) = multipart.next_field().await.map_err(|_| Rejected::Body)? {
                let name = part.name().unwrap_or("").to_string();
                match part.file_name().map(str::to_string) {
                    Some(file_name) => {
                        let bytes = part.bytes().await.map_err(|_| Rejected::Body)?;
                        uploads.push(Upload { field: name, file_name, bytes });
                    }
                    None => fields.push((name, part.text().await.map_err(|_| Rejected::Body)?)),
                }
            }
            (Fields(fields), uploads)
        } else {
            let bytes = Bytes::from_request(req, state).await.map_err(|_| Rejected::Body)?;
            (Fields(form_urlencoded::parse(&bytes).into_owned().collect()), Vec::new())
        };
        let token_ok = expected.is_some_and(|t| t.matches(fields.get(FIELD)));
        Ok(Unchecked { posted: Posted { fields, uploads }, token_ok })
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use axum::http::HeaderValue;

    fn headers(cookie: &str) -> HeaderMap {
        let mut h = HeaderMap::new();
        h.insert(header::COOKIE, HeaderValue::from_str(cookie).unwrap());
        h
    }

    #[test]
    fn a_repeated_field_reads_as_its_last_value() {
        let f = Fields::from_query(Some("campaign=SLIDE&campaign=KIT&x=1"));
        assert_eq!((f.get("campaign"), f.get("x"), f.get("y")), ("KIT", "1", ""));
        let posted = Fields::of(&[("visible", "0"), ("visible", "1")]).with_query(&Fields::from_query(Some("visible=2&page=3")));
        assert_eq!((posted.get("visible"), posted.get("page")), ("1", "3"));
    }

    #[test]
    fn token_follows_the_session_and_only_the_session() {
        let a = crate::token::Token::generate();
        let b = crate::token::Token::generate();
        let cookie = |t: &crate::token::Token| format!("{}={}", crate::session::COOKIE, t.as_str());
        let ta = CsrfToken::for_request(&headers(&cookie(&a))).unwrap();
        let ta2 = CsrfToken::for_request(&headers(&format!("other=1; {}", cookie(&a)))).unwrap();
        let tb = CsrfToken::for_request(&headers(&cookie(&b))).unwrap();
        assert_eq!(ta.as_str(), ta2.as_str());
        assert_ne!(ta.as_str(), tb.as_str());
        assert!(ta.matches(ta2.as_str()));
        assert!(!ta.matches(""));
        assert!(!ta.matches(&ta.as_str()[1..]));
        assert_ne!(ta.as_str().as_bytes(), a.hash().as_slice());
        assert!(CsrfToken::for_request(&HeaderMap::new()).is_none());
    }

    #[test]
    fn fields_read_like_php_request_arrays() {
        let q = Fields::from_query(Some("tab=Orders&action=login&user_id=12x"));
        let f = Fields::of(&[("action", "update"), ("ids[]", "1"), ("ids[]", "2")]).with_query(&q);
        assert_eq!(f.get("action"), "update");
        assert_eq!(f.get("tab"), "Orders");
        assert_eq!(f.int("user_id"), 12);
        assert_eq!(f.get("missing"), "");
        assert_eq!(f.all("ids[]").collect::<Vec<_>>(), ["1", "2"]);
        assert_eq!(php_intval(" -7abc"), -7);
        assert_eq!(php_intval("abc"), 0);
    }
}
