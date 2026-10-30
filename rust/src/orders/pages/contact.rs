//! contact.php: the page's document from `content`, in the visitor's language with English behind it
//! (`$menu->tab_viewer()`), and the `page:general` view in the log.

use std::sync::Arc;

use axum::{
    extract::State,
    http::{header, HeaderMap, Uri},
    response::Response,
};
use sqlx::SqlitePool;

use crate::{
    api::{ApiError, ClientIp},
    db::unix_now,
    identity::Role,
    orders::{html, Orders},
    visitor::Visitor,
};

pub const TITLE: &str = "Harcourt | Engineering Productivity Solutions | Contact Us";
pub const DESCRIPTION: &str = "For questions or inquiries, please Contact Harcourt today, via phone, mail, email, or online form. Global locations; Contact us Today!";

/// The session language: the account's, or for a visitor the first Accept-Language tag the site offers.
pub async fn language(pool: &SqlitePool, visitor: &Visitor, headers: &HeaderMap) -> sqlx::Result<String> {
    if let Some(user) = visitor.user() {
        if let Some(code) = sqlx::query_scalar!(r#"SELECT language_code AS "c!: String" FROM "user" WHERE id = ?1"#, user.id.0).fetch_optional(pool).await? {
            return Ok(code);
        }
    }
    let active = sqlx::query_scalar!(r#"SELECT code AS "c!: String" FROM language WHERE active = 1 ORDER BY code <> 'EN', code"#).fetch_all(pool).await?;
    let accept = headers.get(header::ACCEPT_LANGUAGE).and_then(|v| v.to_str().ok()).unwrap_or("");
    let chosen = accept
        .split([';', '|', ','])
        .map(|t| t.trim().to_ascii_uppercase())
        .find_map(|t| active.iter().find(|code| !code.is_empty() && t.starts_with(code.as_str())).cloned());
    Ok(chosen.unwrap_or_else(|| "EN".into()))
}

/// What `tab_viewer()` prints for the page at `url`, and the page's type and name for the log.
pub async fn tab_viewer(pool: &SqlitePool, url: &str, language: &str) -> sqlx::Result<(String, Option<(String, String)>)> {
    let row = sqlx::query!(
        r#"SELECT p.record_type AS "kind!: String", p.name AS "name!: String", CASE WHEN c.filetype IN ('html', 'hyperlink') THEN coalesce(c.html_content, '') ELSE coalesce(CAST(c.content AS TEXT), '') END AS "content!: String"
           FROM pages p JOIN content c ON c.record_type = 'document' AND c.record_id = p.document_id AND c.language_code IN ('EN', ?2)
           WHERE p.url = ?1
           ORDER BY CASE WHEN c.language_code = 'EN' THEN 2 ELSE 1 END LIMIT 1"#,
        url,
        language
    )
    .fetch_optional(pool)
    .await?;
    let content = row.as_ref().map_or("", |r| r.content.as_str());
    let html = format!("\n<!-- Body Content -->\n{content}\n<!-- End Body Content -->\n");
    Ok((html, row.map(|r| (r.kind, r.name))))
}

pub async fn page(State(state): State<Arc<Orders>>, visitor: Visitor, ClientIp(ip): ClientIp, headers: HeaderMap, uri: Uri) -> Result<Response, ApiError> {
    let pool = &state.core.pool;
    let lang = language(pool, &visitor, &headers).await?;
    let (body, page) = tab_viewer(pool, "/contact.php", &lang).await?;
    if let Some((kind, name)) = page.filter(|(k, _)| k == "general" || k == "solutions") {
        let subtype = if kind == "general" { "General" } else { "Solutions" };
        let user_id = visitor.user().map_or(0, |u| u.id.0);
        let now = unix_now();
        if let Err(e) = sqlx::query!("INSERT INTO log (ip, date_time, user_id, type, subtype, comment) VALUES (?1, ?2, ?3, 'Page', ?4, ?5)", ip, now, user_id, subtype, name).execute(pool).await {
            tracing::warn!("page view log: {e}");
        }
    }
    let chrome = html::Chrome::load(pool).await?;
    let dashboard = visitor.user().is_some_and(|u| u.role >= Role::Executive);
    let head = html::Head { title: TITLE, description: Some(DESCRIPTION), js: &[], framed: html::framed(uri.query(), &headers), dashboard, catalog_url: "/catalog.php" };
    let mut out = html::open(&chrome, &head);
    out.push_str(&body);
    out.push_str(html::close());
    Ok(html::page_response(out))
}
