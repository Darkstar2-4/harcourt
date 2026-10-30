//! login.php. It sent a bot home, then looked the visitor up by `user.email`, a column the table no longer has, and
//! MySQL's error ended every request there: what every visitor sees is that one line. Sign-in and sign-up live on
//! newuser.php. An impersonating Administrator skipped the lookup and saw the old forms; that view is not kept.

use axum::{
    http::{header, StatusCode},
    response::{IntoResponse, Response},
};

use crate::visitor::{Visitor, PHP_CONTENT_TYPE};

const ANSWER: &str = "<p>Database error #1054</b><br>Unknown column 'email' in 'WHERE'</p>\n";

pub async fn page(visitor: Visitor) -> Response {
    if visitor.bot().is_some() {
        let home = if visitor.framed { "/" } else { "/scs_index.php" };
        return (StatusCode::FOUND, [(header::LOCATION, home), (header::CONTENT_TYPE, PHP_CONTENT_TYPE)]).into_response();
    }
    ([(header::CONTENT_TYPE, PHP_CONTENT_TYPE), (header::CACHE_CONTROL, "no-store")], ANSWER).into_response()
}
