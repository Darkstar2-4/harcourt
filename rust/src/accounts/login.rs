//! login.php. It sent a bot home, then looked the visitor up by `user.email`, a column the table no longer has, and
//! MySQL's error ended every request there: what every visitor sees is that one line. Sign-in and sign-up live on
//! newuser.php. An impersonating Administrator skipped the lookup and saw the old forms; that view is not kept.

use axum::{
    http::{header, StatusCode},
    response::{IntoResponse, Response},
};

use crate::visitor::{Visitor, PHP_CONTENT_TYPE};

/// The PHP page failed on every request and printed the database's error to the visitor. Nothing here prints one.
const ANSWER: &str = "<p>Sign-in is by emailed code on the Request Access page.</p>\n";

pub async fn page(visitor: Visitor) -> Response {
    if visitor.bot().is_some() {
        let home = if visitor.framed { "/" } else { "/scs_index.php" };
        return (StatusCode::FOUND, [(header::LOCATION, home), (header::CONTENT_TYPE, PHP_CONTENT_TYPE)]).into_response();
    }
    ([(header::CONTENT_TYPE, PHP_CONTENT_TYPE), (header::CACHE_CONTROL, "no-store")], ANSWER).into_response()
}
