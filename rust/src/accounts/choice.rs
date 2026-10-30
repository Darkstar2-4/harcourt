//! What a visitor chooses for this browser: the language (language_change.php, and the flag click the catalog
//! loader reports as hxlang), and starting over (cookie_reset.php, the PHP's sign-out).

use std::sync::Arc;

use axum::{
    extract::{Query, State},
    http::{header, HeaderMap, HeaderValue, StatusCode},
    response::{IntoResponse, Response},
};
use serde::Deserialize;
use sqlx::SqlitePool;

use crate::{
    accounts::{account, cookies, locale::LanguageCode, locale::Languages},
    api::{self, Auth},
    session,
    visitor::{self, Visitor},
};

/// Make `code` this browser's language. `save` (a flag click, or language_change.php) also stores it on the
/// signed-in account, unless an Administrator is only viewing as that account. Returns the cookie to set.
pub async fn choose_language(pool: &SqlitePool, visitor: &Visitor, code: &LanguageCode, save: bool) -> sqlx::Result<Option<HeaderValue>> {
    if save && visitor.impersonator.is_none() {
        if let Some(a) = visitor.account().filter(|a| a.language_code != code.as_str()) {
            account::set_language(pool, a.id(), code.as_str()).await?;
        }
    }
    Ok(cookies::set(cookies::LANGUAGE, code.as_str(), cookies::Life::Browser))
}

#[derive(Deserialize, Default)]
#[serde(default)]
pub struct LanguageChange {
    language_code: String,
}

/// The page on this site the browser came from.
fn our_referer(origin: &str, headers: &HeaderMap) -> Option<String> {
    headers
        .get(header::REFERER)
        .and_then(|v| v.to_str().ok())
        .and_then(|r| r.strip_prefix(origin))
        .filter(|rest| rest.starts_with('/') && !rest.starts_with("//"))
        .map(str::to_string)
}

fn back(origin: &str, headers: &HeaderMap) -> String {
    our_referer(origin, headers).unwrap_or_else(|| "/".into())
}

fn found(to: &str, set: Vec<HeaderValue>) -> Response {
    let mut r = (StatusCode::FOUND, [(header::LOCATION, to), (header::CONTENT_TYPE, visitor::PHP_CONTENT_TYPE)]).into_response();
    for c in set {
        r.headers_mut().append(header::SET_COOKIE, c);
    }
    r
}

pub async fn language_change(State(core): State<Arc<Auth>>, visitor: Visitor, headers: HeaderMap, Query(q): Query<LanguageChange>) -> Response {
    let to = back(&core.public_origin, &headers);
    let has_referer = headers.contains_key(header::REFERER);
    let languages = match Languages::load(&core.pool).await {
        Ok(l) => l,
        Err(e) => {
            tracing::error!("language_change: {e}");
            return found(&to, Vec::new());
        }
    };
    let Some(code) = languages.get(&q.language_code).filter(|_| has_referer) else { return found(&to, Vec::new()) };
    let from_our_page = our_referer(&core.public_origin, &headers).is_some();
    match choose_language(&core.pool, &visitor, &code, from_our_page).await {
        Ok(cookie) => found(&to, cookie.into_iter().collect()),
        Err(e) => {
            tracing::error!("language_change: {e}");
            found(&to, Vec::new())
        }
    }
}

const RESET_PAGE: &str = r#"<!DOCTYPE html>
<html>
<head>
<title>Harcourt Cookie Reset</title>
<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
<meta name="description" content="Harcourt Cookie Reset">
<link rel="stylesheet" type="text/css" href="//fonts.googleapis.com/css?family=Rajdhani:400,600,700|Open+Sans:400italic,600italic,100,200,300,400,600,700|Oswald:400,700">
<link rel="stylesheet" type="text/css" href="//ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css">
<link rel="stylesheet" type="text/css" href="/scs_scripts/scs.css">
<script type="text/javascript" src="//ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
<script type="text/javascript" src="//ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js"></script>
<script type="text/javascript" src="/scs_scripts/scscpq.js?ver=1.03"></script>
<script type="text/javascript" src="/scs_scripts/scscpq_wp.js?ver=1.03"></script>
</head>
<body>
<script>
/*
if (!sessionStorage['scscpq_cookie']) {
	sessionStorage['scscpq_cookie']="11/30/2020";
	window.location.reload(true);
}
*/
</script>
<p>Your cookies and cache have been reset</p>
<p><a href='/'>Click here to return</a></p>
</body>
</html>
"#;

/// cookie_reset.php: end this browser's sign-in and forget what it chose, then say so. The PHP always cleared
/// `harcourt` and destroyed the session; what the session held lives in cookies here, cleared when the browser has them.
pub async fn cookie_reset(State(core): State<Arc<Auth>>, headers: HeaderMap) -> Response {
    let mut gone = vec![cookies::set(cookies::EMAIL, "", cookies::Life::Gone)];
    if let Some(token) = api::session_cookie(&headers) {
        if let Err(e) = session::destroy(&core.pool, &token).await {
            tracing::error!("cookie_reset: {e}");
            return (StatusCode::INTERNAL_SERVER_ERROR, "Internal error").into_response();
        }
        gone.push(cookies::session_gone());
    }
    for name in [cookies::LANGUAGE, cookies::CAMPAIGN] {
        if cookies::get(&headers, name).is_some() {
            gone.push(cookies::set(name, "", cookies::Life::Gone));
        }
    }
    let mut r = ([(header::CONTENT_TYPE, "text/html; charset=UTF-8"), (header::CACHE_CONTROL, "no-store")], RESET_PAGE).into_response();
    for c in gone.into_iter().flatten() {
        r.headers_mut().append(header::SET_COOKIE, c);
    }
    r
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_a_page_on_this_site_is_gone_back_to() {
        let o = "https://www.harcourt.co";
        let h = |r: &str| {
            let mut m = HeaderMap::new();
            m.insert(header::REFERER, HeaderValue::from_str(r).unwrap());
            back(o, &m)
        };
        assert_eq!(h("https://www.harcourt.co/catalog/?lang=fr"), "/catalog/?lang=fr");
        assert_eq!(h("https://evil.example/"), "/");
        assert_eq!(h("https://www.harcourt.co.evil.example/x"), "/");
        assert_eq!(h("https://www.harcourt.co//evil.example/x"), "/");
        assert_eq!(back(o, &HeaderMap::new()), "/");
    }
}
