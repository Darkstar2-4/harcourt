//! bot_impersonate.php: an Administrator views the site as one of the known bots, through the impersonation on
//! their own session, until "Stop Impersonate" or this page ends it.

use std::sync::Arc;

use axum::{
    extract::State,
    http::{header, HeaderMap, StatusCode},
    response::{IntoResponse, Response},
    routing::MethodRouter,
};
use sqlx::SqlitePool;

use crate::{
    api::{self, Administrator, Auth},
    db::unix_now,
    staff::{
        csrf::{Fields, Posted},
        html::{close, esc, hidden, open, submit, Form, Notes, OptGroup, Opts},
        shell, Staff,
    },
    visitor::{Visitor, PHP_CONTENT_TYPE},
};

const PATH: &str = "/bot_impersonate.php";
const TITLE: &str = "BOT Impersonate";

/// The bots grouped by status, groups in the order their first bot comes by name.
async fn bots(pool: &SqlitePool) -> sqlx::Result<Vec<OptGroup>> {
    let rows = sqlx::query!(r#"SELECT id AS "id!: i64", code, name, status FROM bot ORDER BY name, code"#).fetch_all(pool).await?;
    let mut groups: Vec<OptGroup> = Vec::new();
    for r in rows {
        let option = (r.id.to_string(), esc(&format!("{} ({})", r.name, r.code)));
        match groups.iter_mut().find(|(status, _)| *status == r.status) {
            Some((_, options)) => options.push(option),
            None => groups.push((r.status, vec![option])),
        }
    }
    Ok(groups)
}

async fn page(pool: &SqlitePool, staff: &Staff<Administrator>, notes: &Notes) -> sqlx::Result<Response> {
    let form = Form { notes };
    let mut body = notes.render();
    body.push_str(&open(PATH, staff.csrf.as_str(), false));
    body.push_str(&hidden("action", "update"));
    let choice = form.optgroup("bot_id", &bots(pool).await?, "", true, Opts::new());
    body.push_str(&format!("<p class=standad>Impersonate BOT: {choice}<br>{}</p>\n", submit("Go!", Opts::new())));
    body.push_str(close());
    Ok(shell::page(pool, staff.view(), TITLE, &body).await)
}

/// An impersonation already running ends here, and the page is drawn for the Administrator again.
async fn stop(pool: &SqlitePool, headers: &HeaderMap) -> sqlx::Result<()> {
    if let Some(token) = api::session_cookie(headers) {
        let hash = token.hash();
        let hash = hash.as_slice();
        sqlx::query!("DELETE FROM impersonation WHERE session_hash = ?1", hash).execute(pool).await?;
    }
    Ok(())
}

async fn respond(pool: &SqlitePool, staff: &Staff<Administrator>, visitor: &Visitor, headers: &HeaderMap, fields: &Fields) -> sqlx::Result<Response> {
    let mut notes = Notes::new(TITLE, "");
    if visitor.impersonator.is_some() {
        stop(pool, headers).await?;
        notes.say("Impersonating Stopped");
        return page(pool, staff, &notes).await;
    }
    if fields.get("action").is_empty() {
        return page(pool, staff, &notes).await;
    }
    let bot_id = fields.int("bot_id");
    let known = sqlx::query_scalar!(r#"SELECT id AS "id!: i64" FROM bot WHERE id = ?1"#, bot_id).fetch_optional(pool).await?;
    let (Some(bot_id), Some(token)) = (known, api::session_cookie(headers)) else {
        notes.error("bot_id", None);
        return page(pool, staff, &notes).await;
    };
    let hash = token.hash();
    let hash = hash.as_slice();
    let now = unix_now();
    sqlx::query!(
        r#"INSERT INTO impersonation (session_hash, bot_id, created_at) VALUES (?1, ?2, ?3)
           ON CONFLICT (session_hash) DO UPDATE SET bot_id = excluded.bot_id, user_id = NULL, created_at = excluded.created_at"#,
        hash,
        bot_id,
        now
    )
    .execute(pool)
    .await?;
    Ok((StatusCode::FOUND, [(header::LOCATION, "/index.php"), (header::CONTENT_TYPE, PHP_CONTENT_TYPE)]).into_response())
}

async fn show(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, visitor: Visitor, headers: HeaderMap) -> Response {
    respond(&auth.pool, &staff, &visitor, &headers, &Fields::default()).await.unwrap_or_else(shell::internal)
}

async fn post(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, visitor: Visitor, headers: HeaderMap, posted: Posted) -> Response {
    respond(&auth.pool, &staff, &visitor, &headers, &posted.fields).await.unwrap_or_else(shell::internal)
}

pub fn route() -> MethodRouter<Arc<Auth>> {
    axum::routing::get(show).post(post)
}
