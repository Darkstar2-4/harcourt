//! cookie_reset.php, the PHP's sign-out: end this browser's sign-in, forget its visit, and say so.

use std::sync::Arc;

use axum::{
    extract::State,
    http::{header, HeaderMap, StatusCode},
    response::{IntoResponse, Response},
};

use crate::{
    accounts::{
        cookies,
        visit::{self, VisitId},
    },
    api::{self, Auth},
    db::unix_now,
    session,
};

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

/// The PHP always cleared `harcourt` and destroyed the session.
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
