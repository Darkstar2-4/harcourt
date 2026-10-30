//! Fills the cms tables from WordPress: `dump` and `php` read a mysqldump of the WordPress tables, `wp` turns
//! them into the content model, `export` takes from the static export what only the rendered pages show (the
//! text of each field as printed, Yoast's head lines, the scripts each page's plugins added) and checks that the
//! Rust templates reproduce every ported page. `write` stores the result; running it again converges.

pub mod capture;
pub mod dump;
pub mod export;
pub mod heads;
pub mod php;
pub mod wp;

use std::collections::BTreeMap;

use sqlx::SqlitePool;

use super::model::{Author, Fields, Lang, Media, MenuItem, Page, PageId, Site, Term};

#[derive(Debug, Default)]
pub struct Import {
    pub pages: Vec<Page>,
    pub fields: BTreeMap<PageId, Fields>,
    pub meta: BTreeMap<PageId, BTreeMap<String, String>>,
    pub options: BTreeMap<Lang, Fields>,
    pub menus: Vec<MenuItem>,
    pub media: Vec<Media>,
    pub terms: Vec<Term>,
    pub authors: Vec<Author>,
}

impl Import {
    /// The snapshot these rows would load as, for rendering before anything is written.
    pub fn site(&self) -> Site {
        let mut site = Site::new(self.pages.clone());
        site.fields = self.fields.iter().map(|(k, v)| (*k, v.clone())).collect();
        site.meta = self.meta.iter().map(|(k, v)| (*k, v.clone())).collect();
        site.options = self.options.iter().map(|(k, v)| (*k, v.clone())).collect();
        site.menus = self.menus.clone();
        site.media = self.media.iter().map(|m| (m.id, m.clone())).collect();
        site.terms = self.terms.clone();
        site.authors = self.authors.iter().map(|a| (a.id, a.clone())).collect();
        site
    }
}

/// Replaces the cms content with `import` in one transaction, recording a revision for every page and language,
/// so the content version moves and the history shows the import.
pub async fn write(pool: &SqlitePool, import: &Import, now: i64) -> sqlx::Result<()> {
    let mut tx = pool.begin().await?;
    sqlx::query!("DELETE FROM cms_field").execute(&mut *tx).await?;
    sqlx::query!("DELETE FROM cms_page_meta").execute(&mut *tx).await?;
    sqlx::query!("DELETE FROM cms_option").execute(&mut *tx).await?;
    sqlx::query!("DELETE FROM cms_menu_item").execute(&mut *tx).await?;
    sqlx::query!("DELETE FROM cms_media").execute(&mut *tx).await?;
    sqlx::query!("DELETE FROM cms_term_page").execute(&mut *tx).await?;
    sqlx::query!("DELETE FROM cms_term").execute(&mut *tx).await?;
    sqlx::query!("DELETE FROM cms_author").execute(&mut *tx).await?;
    let keep: Vec<i64> = import.pages.iter().map(|p| p.id.0).collect();
    let keep = serde_json::to_string(&keep).unwrap_or_else(|_| "[]".into());
    sqlx::query!("DELETE FROM cms_page WHERE id NOT IN (SELECT value FROM json_each(?1))", keep).execute(&mut *tx).await?;
    for p in &import.pages {
        let (lang, template, status) = (p.lang.code(), p.template.file(), p.status.as_str());
        let parent = p.parent_id.map(|id| id.0);
        let kind = p.kind.as_str();
        sqlx::query!(
            "INSERT INTO cms_page (id, group_id, lang, path, template, status, title, parent_id, menu_order, published_at, modified_at, post_type, name, author_id)
             VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, ?12, ?13, ?14)
             ON CONFLICT (id) DO UPDATE SET group_id = excluded.group_id, lang = excluded.lang, path = excluded.path,
                 template = excluded.template, status = excluded.status, title = excluded.title, parent_id = excluded.parent_id,
                 menu_order = excluded.menu_order, published_at = excluded.published_at, modified_at = excluded.modified_at,
                 post_type = excluded.post_type, name = excluded.name, author_id = excluded.author_id",
            p.id.0,
            p.group_id,
            lang,
            p.path,
            template,
            status,
            p.title,
            parent,
            p.menu_order,
            p.published_at,
            p.modified_at,
            kind,
            p.name,
            p.author_id
        )
        .execute(&mut *tx)
        .await?;
    }
    for (page, fields) in &import.fields {
        for (name, f) in fields {
            let kind = f.kind.as_str();
            sqlx::query!("INSERT INTO cms_field (page_id, name, kind, value) VALUES (?1, ?2, ?3, ?4)", page.0, name, kind, f.value)
                .execute(&mut *tx)
                .await?;
        }
    }
    for (page, meta) in &import.meta {
        for (name, value) in meta {
            sqlx::query!("INSERT INTO cms_page_meta (page_id, name, value) VALUES (?1, ?2, ?3)", page.0, name, value).execute(&mut *tx).await?;
        }
    }
    for (lang, fields) in &import.options {
        let code = lang.code();
        for (name, f) in fields {
            let kind = f.kind.as_str();
            sqlx::query!("INSERT INTO cms_option (lang, name, kind, value) VALUES (?1, ?2, ?3, ?4)", code, name, kind, f.value).execute(&mut *tx).await?;
        }
    }
    let ids: std::collections::HashSet<i64> = import.menus.iter().map(|m| m.id).collect();
    let mut menus: Vec<&MenuItem> = import.menus.iter().collect();
    menus.sort_by_key(|m| m.parent_id.is_some());
    for m in menus {
        let (menu, lang) = (m.menu.as_str(), m.lang.code());
        let parent = m.parent_id.filter(|p| ids.contains(p));
        let page = m.page_id.map(|p| p.0);
        sqlx::query!(
            "INSERT INTO cms_menu_item (id, menu, lang, parent_id, position, label, url, page_id, classes, target) VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10)",
            m.id,
            menu,
            lang,
            parent,
            m.position,
            m.label,
            m.url,
            page,
            m.classes,
            m.target
        )
        .execute(&mut *tx)
        .await?;
    }
    for m in &import.media {
        let sizes = serde_json::to_string(&m.sizes).unwrap_or_else(|_| "[]".into());
        let (parent, lang) = (m.parent_id.map(|p| p.0), m.lang.map(Lang::code));
        sqlx::query!(
            "INSERT INTO cms_media (id, file, mime, width, height, alt, sizes, name, parent_id, lang, group_id) VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11)",
            m.id.0,
            m.file,
            m.mime,
            m.width,
            m.height,
            m.alt,
            sizes,
            m.name,
            parent,
            lang,
            m.group_id
        )
        .execute(&mut *tx)
        .await?;
    }
    for p in &import.pages {
        let snapshot = super::edit::snapshot(import.fields.get(&p.id), import.meta.get(&p.id));
        sqlx::query!("INSERT INTO cms_revision (page_id, saved_at, saved_by, note, fields) VALUES (?1, ?2, 0, 'import', ?3)", p.id.0, now, snapshot)
            .execute(&mut *tx)
            .await?;
    }
    for (lang, fields) in &import.options {
        let code = lang.code();
        let snapshot = super::edit::snapshot(Some(fields), None);
        sqlx::query!("INSERT INTO cms_revision (lang, saved_at, saved_by, note, fields) VALUES (?1, ?2, 0, 'import', ?3)", code, now, snapshot)
            .execute(&mut *tx)
            .await?;
    }
    for t in &import.terms {
        let (taxonomy, lang) = (t.taxonomy.as_str(), t.lang.map(Lang::code));
        sqlx::query!(
            "INSERT INTO cms_term (id, taxonomy, slug, name, lang, group_id, modified_at) VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7)",
            t.id,
            taxonomy,
            t.slug,
            t.name,
            lang,
            t.group_id,
            t.modified_at
        )
        .execute(&mut *tx)
        .await?;
        for page in &t.pages {
            sqlx::query!("INSERT INTO cms_term_page (term_id, page_id) VALUES (?1, ?2)", t.id, page.0).execute(&mut *tx).await?;
        }
    }
    for a in &import.authors {
        sqlx::query!("INSERT INTO cms_author (id, nicename, display_name, profile_updated) VALUES (?1, ?2, ?3, ?4)", a.id, a.nicename, a.display_name, a.profile_updated)
            .execute(&mut *tx)
            .await?;
    }
    tx.commit().await
}
