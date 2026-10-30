//! company.php: the company page, a CMS document (`$menu->tab_viewer()`). The document body is HTML staff wrote in
//! the CMS and is printed as stored, as the PHP did.
//!
//! The PHP asked for a Customer, which every visit with a PHP session was (its session user starts as a Customer), so
//! the page is open to every visitor; the visitor layer sends a visit's first request to its landing page as
//! scs_header.php did.

use std::sync::Arc;

use axum::{
    extract::{RawQuery, State},
    response::Response,
    routing::get,
    Router,
};
use sqlx::SqlitePool;

use super::{shell, shell::View};
use crate::{
    api::{Auth, ClientIp},
    db::unix_now,
    identity::Role,
    visitor::Visitor,
};

const PATH: &str = "/company.php";
const TITLE: &str = "Harcourt | Engineering Productivity Solutions | Company";
const DESCRIPTION: &str = "Meet Harcourt Engineering Productivity Solutions for Engineers in Aerospace & Heavy Industry.";

/// The document `pages` links to this path, in the account's language before English.
async fn tab_viewer(pool: &SqlitePool, visitor: &Visitor, ip: &str) -> sqlx::Result<String> {
    let language = visitor.account().map_or_else(|| "EN".to_string(), |a| a.language_code.clone());
    let doc = sqlx::query!(
        r#"SELECT p.record_type AS "pages_record_type?", p.name AS "pages_name?", c.filetype, c.content, c.html_content
           FROM content c JOIN pages p ON p.url = ?1 AND c.record_id = p.document_id
           WHERE c.record_type = 'document' AND c.language_code IN ('EN', ?2)
           ORDER BY CASE WHEN c.language_code = 'EN' THEN 2 ELSE 1 END LIMIT 1"#,
        PATH,
        language
    )
    .fetch_optional(pool)
    .await?;
    let Some(doc) = doc else { return Ok("\n<!-- Body Content -->\n\n<!-- End Body Content -->\n".into()) };
    let subtype = match doc.pages_record_type.as_deref() {
        Some("general") => Some("General"),
        Some("solutions") => Some("Solutions"),
        _ => None,
    };
    if let Some(subtype) = subtype {
        let now = unix_now();
        let comment = doc.pages_name.unwrap_or_default();
        let user_id = visitor.user().map_or(0, |u| u.id.0);
        let email = visitor.signed_in().and_then(|s| s.email.as_ref()).map(|e| e.as_str().to_string()).unwrap_or_default();
        sqlx::query!(
            "INSERT INTO log (ip, date_time, user_id, email, type, subtype, comment) VALUES (?1, ?2, ?3, ?4, 'Page', ?5, ?6)",
            ip,
            now,
            user_id,
            email,
            subtype,
            comment
        )
        .execute(pool)
        .await?;
    }
    let body = if matches!(doc.filetype.as_str(), "html" | "hyperlink") {
        doc.html_content.unwrap_or_default()
    } else {
        doc.content.map(|b| String::from_utf8_lossy(&b).into_owned()).unwrap_or_default()
    };
    Ok(format!("\n<!-- Body Content -->\n{body}\n<!-- End Body Content -->\n"))
}

async fn show(State(auth): State<Arc<Auth>>, ClientIp(ip): ClientIp, visitor: Visitor, RawQuery(q): RawQuery) -> Response {
    let asked = !matches!(super::csrf::Fields::from_query(q.as_deref()).get("scscpq_wp"), "" | "0");
    let view = View { role: visitor.user().map_or(Role::Customer, |u| u.role), wp: visitor.framed || asked };
    match tab_viewer(&auth.pool, &visitor, &ip).await {
        Ok(body) => shell::described(&auth.pool, view, TITLE, Some(DESCRIPTION), &body).await,
        Err(e) => shell::internal(e),
    }
}

pub fn routes() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(show))
}
