//! company.php: the company page, a CMS document shown to signed-in accounts (`$menu->tab_viewer()`).
//! The document body is HTML staff wrote in the CMS and is printed as stored, as the PHP did.

use std::sync::Arc;

use axum::{extract::State, response::Response, routing::get, Router};
use sqlx::SqlitePool;

use super::{shell, Staff};
use crate::{
    api::{AnyUser, Auth, ClientIp},
    db::unix_now,
};

const PATH: &str = "/company.php";
const TITLE: &str = "Harcourt | Engineering Productivity Solutions | Company";
const DESCRIPTION: &str = "Meet Harcourt Engineering Productivity Solutions for Engineers in Aerospace & Heavy Industry.";

/// The document `pages` links to this path, in the account's language before English.
async fn tab_viewer(pool: &SqlitePool, staff: &Staff<AnyUser>, ip: &str) -> sqlx::Result<String> {
    let language = sqlx::query_scalar!(r#"SELECT language_code FROM "user" WHERE id = ?1"#, staff.who.user.id.0)
        .fetch_optional(pool)
        .await?
        .unwrap_or_else(|| "EN".into());
    let doc = sqlx::query!(
        r#"SELECT p.record_type AS "pages_record_type?", p.name AS "pages_name?", c.content
           FROM content c JOIN pages p ON p.url = ?1 AND c.record_id = p.document_id
           WHERE c.record_type = 'document' AND c.language_code IN ('EN', ?2)
           ORDER BY CASE WHEN c.language_code = 'EN' THEN 2 ELSE 1 END LIMIT 1"#,
        PATH,
        language
    )
    .fetch_optional(pool)
    .await?;
    let Some(doc) = doc else { return Ok("\n<!-- Body Content -->\n\n<!-- End Body Content -->\n".into()) };
    let kind = doc.pages_record_type.unwrap_or_default();
    let subtype = match kind.as_str() {
        "general" => Some("General"),
        "solutions" => Some("Solutions"),
        _ => None,
    };
    if let Some(subtype) = subtype {
        let now = unix_now();
        let comment = doc.pages_name.unwrap_or_default();
        sqlx::query!(
            "INSERT INTO log (ip, date_time, user_id, email, type, subtype, comment) VALUES (?1, ?2, ?3, ?4, 'Page', ?5, ?6)",
            ip,
            now,
            staff.who.user.id.0,
            staff.who.email,
            subtype,
            comment
        )
        .execute(pool)
        .await?;
    }
    let body = doc.content.map(|b| String::from_utf8_lossy(&b).into_owned()).unwrap_or_default();
    Ok(format!("\n<!-- Body Content -->\n{body}\n<!-- End Body Content -->\n"))
}

async fn show(State(auth): State<Arc<Auth>>, ClientIp(ip): ClientIp, staff: Staff<AnyUser>) -> Response {
    match tab_viewer(&auth.pool, &staff, &ip).await {
        Ok(body) => shell::described(&auth.pool, staff.who.user.role, TITLE, Some(DESCRIPTION), &body).await,
        Err(e) => shell::internal(e),
    }
}

pub fn routes() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(show))
}
