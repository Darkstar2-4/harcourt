//! What a visitor chooses for this browser: the language (language_change.php, and the flag click the catalog
//! loader reports as hxlang), and starting over (cookie_reset.php, the PHP's sign-out).

use std::sync::Arc;

use axum::{
    extract::{Query, State},
    http::{header, HeaderMap, StatusCode},
    response::{IntoResponse, Response},
};
use serde::Deserialize;
use sqlx::SqlitePool;

use crate::{
    accounts::{
        account, cookies,
        locale::{LanguageCode, Languages},
        visit::{self, VisitId},
    },
    api::{self, Auth},
    db::unix_now,
    session,
    visitor::{self, Visitor},
};

/// Make `code` this visit's language. `save` also stores it on the signed-in account, unless an Administrator is
/// only viewing as that account.
pub async fn choose_language(pool: &SqlitePool, visitor: &Visitor, code: &LanguageCode, save: bool) -> sqlx::Result<()> {
    if save && visitor.impersonator.is_none() {
        if let Some(a) = visitor.account().filter(|a| a.language_code != code.as_str()) {
            account::set_language(pool, a.id(), code.as_str()).await?;
        }
    }
    if let Some(id) = &visitor.visit {
        visit::set_language(pool, id, code.as_str(), unix_now()).await?;
    }
    Ok(())
}

#[derive(Deserialize, Default)]
#[serde(default)]
pub struct LanguageChange {
    language_code: String,
}

/// The page on this site the browser came from, as it sent it.
fn our_referer<'a>(origin: &str, headers: &'a HeaderMap) -> Option<&'a str> {
    headers
        .get(header::REFERER)
        .and_then(|v| v.to_str().ok())
        .filter(|r| r.strip_prefix(origin).is_some_and(|rest| rest.starts_with('/') && !rest.starts_with("//")))
}

/// Where language_change.php went back to: the Referer. Here only one on this site, else the home page.
fn back(origin: &str, headers: &HeaderMap) -> String {
    our_referer(origin, headers).unwrap_or("/").to_string()
}

fn found(to: &str) -> Response {
    (StatusCode::FOUND, [(header::LOCATION, to), (header::CONTENT_TYPE, visitor::PHP_CONTENT_TYPE)]).into_response()
}

pub async fn language_change(State(core): State<Arc<Auth>>, visitor: Visitor, headers: HeaderMap, Query(q): Query<LanguageChange>) -> Response {
    let to = back(&core.public_origin, &headers);
    let has_referer = headers.contains_key(header::REFERER);
    let languages = match Languages::load(&core.pool).await {
        Ok(l) => l,
        Err(e) => {
            tracing::error!("language_change: {e}");
            return found(&to);
        }
    };
    let Some(code) = languages.get(&q.language_code).filter(|c| has_referer && c.as_str() == q.language_code) else { return found(&to) };
    let from_our_page = our_referer(&core.public_origin, &headers).is_some();
    if let Err(e) = choose_language(&core.pool, &visitor, &code, from_our_page).await {
        tracing::error!("language_change: {e}");
    }
    found(&to)
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

/// cookie_reset.php: end this browser's sign-in and forget its visit, then say so. The PHP always cleared
/// `harcourt` and destroyed the session.
pub async fn cookie_reset(State(core): State<Arc<Auth>>, headers: HeaderMap) -> Response {
    let mut gone = vec![cookies::set(cookies::EMAIL, "", cookies::Life::Gone)];
    if let Some(sid) = cookies::get(&headers, "PHPSESSID") {
        if let Err(e) = visit::destroy(&core.pool, &VisitId::of(&sid), unix_now()).await {
            tracing::error!("cookie_reset: {e}");
        }
    }
    if let Some(token) = api::session_cookie(&headers) {
        if let Err(e) = session::destroy(&core.pool, &token).await {
            tracing::error!("cookie_reset: {e}");
            return (StatusCode::INTERNAL_SERVER_ERROR, "Internal error").into_response();
        }
        gone.push(cookies::session_gone());
    }
    let mut r = ([(header::CONTENT_TYPE, "text/html; charset=UTF-8"), (header::CACHE_CONTROL, "no-store")], RESET_PAGE).into_response();
    for c in gone.into_iter().flatten() {
        r.headers_mut().append(header::SET_COOKIE, c);
    }
    r
}

#[cfg(test)]
mod tests {
    use axum::http::HeaderValue;

    use super::*;

    #[test]
    fn only_a_page_on_this_site_is_gone_back_to() {
        let o = "https://www.harcourt.co";
        let h = |r: &str| {
            let mut m = HeaderMap::new();
            m.insert(header::REFERER, HeaderValue::from_str(r).unwrap());
            back(o, &m)
        };
        assert_eq!(h("https://www.harcourt.co/catalog/?lang=fr"), "https://www.harcourt.co/catalog/?lang=fr");
        assert_eq!(h("https://evil.example/"), "/");
        assert_eq!(h("https://www.harcourt.co.evil.example/x"), "/");
        assert_eq!(h("https://www.harcourt.co//evil.example/x"), "/");
        assert_eq!(back(o, &HeaderMap::new()), "/");
    }
}
