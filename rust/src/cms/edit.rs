//! Changing page text and site-wide texts (the header notice banner, the call-to-action block, footer texts) from
//! the staff dashboard. Only `html` fields and the SEO title and description are editable; structure (which
//! blocks show, how many rows, which image) stays as imported. Every save records the full set of values after
//! it as a revision, so any earlier state can be restored, and moves the content version so pages re-render.
//!
//! The staff area mounts the screens in `cms::admin`; these functions are what they call.

use std::collections::BTreeMap;

use serde::Serialize;
use sqlx::SqlitePool;

use super::model::{Field, Fields, Kind, Lang, PageId};
use crate::identity::UserId;

/// Page meta an editor may change: Yoast's title and the descriptions.
pub const EDITABLE_META: [&str; 3] = ["title", "description", "og_description"];

#[derive(Debug, Serialize)]
struct Snapshot<'a> {
    fields: BTreeMap<&'a str, &'a str>,
    #[serde(skip_serializing_if = "BTreeMap::is_empty")]
    meta: BTreeMap<&'a str, &'a str>,
}

/// The JSON a revision stores: every html field, and the editable meta.
pub fn snapshot(fields: Option<&Fields>, meta: Option<&BTreeMap<String, String>>) -> String {
    let s = Snapshot {
        fields: fields.into_iter().flatten().filter(|(_, f)| f.kind == Kind::Html).map(|(k, f)| (k.as_str(), f.value.as_str())).collect(),
        meta: meta.into_iter().flatten().filter(|(k, _)| EDITABLE_META.contains(&k.as_str())).map(|(k, v)| (k.as_str(), v.as_str())).collect(),
    };
    serde_json::to_string(&s).unwrap_or_else(|_| "{}".into())
}

#[derive(Debug, PartialEq, Eq)]
pub enum EditError {
    NotFound,
    /// The name is not an editable value of this page or language.
    NotEditable(String),
    Db,
}

impl From<sqlx::Error> for EditError {
    fn from(e: sqlx::Error) -> Self {
        tracing::error!("cms edit: {e}");
        EditError::Db
    }
}

#[derive(Debug, Clone, Serialize, PartialEq, Eq)]
pub struct PageSummary {
    pub id: i64,
    pub lang: String,
    pub path: String,
    pub title: String,
    pub template: String,
    pub status: String,
}

pub async fn list_pages(pool: &SqlitePool) -> sqlx::Result<Vec<PageSummary>> {
    sqlx::query_as!(PageSummary, r#"SELECT id AS "id!", lang, path, title, template, status FROM cms_page ORDER BY path, lang"#)
        .fetch_all(pool)
        .await
}

/// What an editor sees for one page: its editable values in a stable order.
#[derive(Debug, Clone, Serialize, PartialEq, Eq)]
pub struct EditablePage {
    pub page: PageSummary,
    pub fields: Vec<(String, String)>,
    pub meta: Vec<(String, String)>,
}

pub async fn load_page(pool: &SqlitePool, id: PageId) -> sqlx::Result<Option<EditablePage>> {
    let Some(page) = sqlx::query_as!(PageSummary, r#"SELECT id AS "id!", lang, path, title, template, status FROM cms_page WHERE id = ?1"#, id.0)
        .fetch_optional(pool)
        .await?
    else {
        return Ok(None);
    };
    let fields = sqlx::query!("SELECT name, value FROM cms_field WHERE page_id = ?1 AND kind = 'html' ORDER BY name", id.0)
        .fetch_all(pool)
        .await?
        .into_iter()
        .map(|r| (r.name, r.value))
        .collect();
    let mut meta = Vec::new();
    for name in EDITABLE_META {
        let value = sqlx::query_scalar!("SELECT value FROM cms_page_meta WHERE page_id = ?1 AND name = ?2", id.0, name).fetch_optional(pool).await?;
        meta.push((name.to_string(), value.unwrap_or_default()));
    }
    Ok(Some(EditablePage { page, fields, meta }))
}

/// One change. A `meta:` prefix names page meta (`meta:title`); anything else is a field.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Change {
    pub name: String,
    pub value: String,
}

/// Applies `changes` to a page's html fields and editable meta, records a revision, and returns its id.
pub async fn save_page(pool: &SqlitePool, id: PageId, changes: &[Change], by: UserId, note: &str, now: i64) -> Result<i64, EditError> {
    let mut tx = pool.begin().await?;
    if sqlx::query_scalar!("SELECT 1 AS \"one!: i64\" FROM cms_page WHERE id = ?1", id.0).fetch_optional(&mut *tx).await?.is_none() {
        return Err(EditError::NotFound);
    }
    for ch in changes {
        if let Some(name) = ch.name.strip_prefix("meta:") {
            if !EDITABLE_META.contains(&name) {
                return Err(EditError::NotEditable(ch.name.clone()));
            }
            sqlx::query!(
                "INSERT INTO cms_page_meta (page_id, name, value) VALUES (?1, ?2, ?3) ON CONFLICT (page_id, name) DO UPDATE SET value = excluded.value",
                id.0,
                name,
                ch.value
            )
            .execute(&mut *tx)
            .await?;
        } else {
            let done = sqlx::query!("UPDATE cms_field SET value = ?3 WHERE page_id = ?1 AND name = ?2 AND kind = 'html'", id.0, ch.name, ch.value)
                .execute(&mut *tx)
                .await?;
            if done.rows_affected() == 0 {
                return Err(EditError::NotEditable(ch.name.clone()));
            }
        }
    }
    let fields: Fields = sqlx::query!("SELECT name, value FROM cms_field WHERE page_id = ?1 AND kind = 'html'", id.0)
        .fetch_all(&mut *tx)
        .await?
        .into_iter()
        .map(|r| (r.name, Field { kind: Kind::Html, value: r.value }))
        .collect();
    let meta: BTreeMap<String, String> = sqlx::query!("SELECT name, value FROM cms_page_meta WHERE page_id = ?1", id.0)
        .fetch_all(&mut *tx)
        .await?
        .into_iter()
        .map(|r| (r.name, r.value))
        .collect();
    let snap = snapshot(Some(&fields), Some(&meta));
    let rev = sqlx::query_scalar!(
        r#"INSERT INTO cms_revision (page_id, saved_at, saved_by, note, fields) VALUES (?1, ?2, ?3, ?4, ?5) RETURNING id AS "id!""#,
        id.0,
        now,
        by.0,
        note,
        snap
    )
    .fetch_one(&mut *tx)
    .await?;
    tx.commit().await?;
    Ok(rev)
}

pub async fn load_options(pool: &SqlitePool, lang: Lang) -> sqlx::Result<Vec<(String, String)>> {
    let code = lang.code();
    Ok(sqlx::query!("SELECT name, value FROM cms_option WHERE lang = ?1 AND kind = 'html' ORDER BY name", code)
        .fetch_all(pool)
        .await?
        .into_iter()
        .map(|r| (r.name, r.value))
        .collect())
}

/// Applies `changes` to a language's html options (website_notification is the header banner) and records a
/// revision.
pub async fn save_options(pool: &SqlitePool, lang: Lang, changes: &[Change], by: UserId, note: &str, now: i64) -> Result<i64, EditError> {
    let code = lang.code();
    let mut tx = pool.begin().await?;
    for ch in changes {
        let done = sqlx::query!("UPDATE cms_option SET value = ?3 WHERE lang = ?1 AND name = ?2 AND kind = 'html'", code, ch.name, ch.value)
            .execute(&mut *tx)
            .await?;
        if done.rows_affected() == 0 {
            return Err(EditError::NotEditable(ch.name.clone()));
        }
    }
    let fields: Fields = sqlx::query!("SELECT name, value FROM cms_option WHERE lang = ?1 AND kind = 'html'", code)
        .fetch_all(&mut *tx)
        .await?
        .into_iter()
        .map(|r| (r.name, Field { kind: Kind::Html, value: r.value }))
        .collect();
    let snap = snapshot(Some(&fields), None);
    let rev = sqlx::query_scalar!(
        r#"INSERT INTO cms_revision (lang, saved_at, saved_by, note, fields) VALUES (?1, ?2, ?3, ?4, ?5) RETURNING id AS "id!""#,
        code,
        now,
        by.0,
        note,
        snap
    )
    .fetch_one(&mut *tx)
    .await?;
    tx.commit().await?;
    Ok(rev)
}

#[derive(Debug, Clone, Serialize, PartialEq, Eq)]
pub struct RevisionSummary {
    pub id: i64,
    pub saved_at: i64,
    pub saved_by: i64,
    pub note: String,
}

pub async fn page_revisions(pool: &SqlitePool, id: PageId) -> sqlx::Result<Vec<RevisionSummary>> {
    sqlx::query_as!(
        RevisionSummary,
        r#"SELECT id AS "id!", saved_at, saved_by, note FROM cms_revision WHERE page_id = ?1 ORDER BY id DESC"#,
        id.0
    )
    .fetch_all(pool)
    .await
}

pub async fn option_revisions(pool: &SqlitePool, lang: Lang) -> sqlx::Result<Vec<RevisionSummary>> {
    let code = lang.code();
    sqlx::query_as!(
        RevisionSummary,
        r#"SELECT id AS "id!", saved_at, saved_by, note FROM cms_revision WHERE lang = ?1 ORDER BY id DESC"#,
        code
    )
    .fetch_all(pool)
    .await
}

#[derive(serde::Deserialize)]
struct StoredSnapshot {
    #[serde(default)]
    fields: BTreeMap<String, String>,
    #[serde(default)]
    meta: BTreeMap<String, String>,
}

/// Saves the values a revision recorded as a new revision. Values whose field no longer exists are skipped.
pub async fn restore(pool: &SqlitePool, revision: i64, by: UserId, now: i64) -> Result<i64, EditError> {
    let r = sqlx::query!("SELECT page_id, lang, fields FROM cms_revision WHERE id = ?1", revision).fetch_optional(pool).await?.ok_or(EditError::NotFound)?;
    let snap: StoredSnapshot = serde_json::from_str(&r.fields).map_err(|_| EditError::NotFound)?;
    let note = format!("restore revision {revision}");
    match (r.page_id, r.lang.as_deref().and_then(Lang::parse)) {
        (Some(page), _) => {
            let id = PageId(page);
            let existing: Vec<String> = load_page(pool, id).await?.ok_or(EditError::NotFound)?.fields.into_iter().map(|(n, _)| n).collect();
            let mut changes: Vec<Change> =
                snap.fields.into_iter().filter(|(n, _)| existing.contains(n)).map(|(name, value)| Change { name, value }).collect();
            changes.extend(snap.meta.into_iter().map(|(n, value)| Change { name: format!("meta:{n}"), value }));
            save_page(pool, id, &changes, by, &note, now).await
        }
        (None, Some(lang)) => {
            let existing: Vec<String> = load_options(pool, lang).await?.into_iter().map(|(n, _)| n).collect();
            let changes: Vec<Change> = snap.fields.into_iter().filter(|(n, _)| existing.contains(n)).map(|(name, value)| Change { name, value }).collect();
            save_options(pool, lang, &changes, by, &note, now).await
        }
        _ => Err(EditError::NotFound),
    }
}
