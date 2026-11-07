//! Fills the cms tables from WordPress: `dump` and `crate::serialized` read a mysqldump of the WordPress tables,
//! `wp` turns them into the content model, `export` takes from the static export what only the rendered pages show
//! (the text of each field as printed, Yoast's head lines, the scripts each page's plugins added) and checks that
//! the Rust templates reproduce every ported page. `write` stores the result; running it again converges.

pub mod capture;
pub mod dump;
pub mod export;
pub mod heads;
pub mod wp;

use std::collections::{BTreeMap, HashSet};

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
    // Kept pages may trade paths; each first takes a placeholder no page path can be (every one starts with '/').
    sqlx::query!("UPDATE cms_page SET path = '#' || id").execute(&mut *tx).await?;
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
    for (m, parent) in parents_first(&import.menus) {
        let (menu, lang) = (m.menu.as_str(), m.lang.code());
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

/// The menu items in an order that stores every parent before its children, each with the parent it is stored
/// under. A parent that is not among the items is stored as none, and so is the first item of a loop of parents.
fn parents_first(items: &[MenuItem]) -> Vec<(&MenuItem, Option<i64>)> {
    let ids: HashSet<i64> = items.iter().map(|m| m.id).collect();
    let mut out: Vec<(&MenuItem, Option<i64>)> = items.iter().filter(|m| !m.parent_id.is_some_and(|p| ids.contains(&p))).map(|m| (m, None)).collect();
    let mut placed: HashSet<i64> = out.iter().map(|(m, _)| m.id).collect();
    let mut next = 0;
    loop {
        while let Some(&(parent, _)) = out.get(next) {
            out.extend(items.iter().filter(|m| m.parent_id == Some(parent.id) && placed.insert(m.id)).map(|m| (m, Some(parent.id))));
            next += 1;
        }
        let Some(root) = items.iter().find(|m| placed.insert(m.id)) else { return out };
        out.push((root, None));
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::cms::model::{Menu, PostType, Status, Template};

    fn item(id: i64, parent_id: Option<i64>, position: i64) -> MenuItem {
        MenuItem {
            id,
            menu: Menu::Main,
            lang: Lang::En,
            parent_id,
            position,
            label: format!("Item {id}"),
            url: format!("https://example.com/{id}/"),
            page_id: None,
            classes: String::new(),
            target: String::new(),
        }
    }

    #[tokio::test]
    async fn a_three_level_menu_is_stored_whatever_order_its_items_sort_in() {
        let pool = crate::db::open_memory().await.unwrap();
        let import = Import { menus: vec![item(10, None, 1), item(15, Some(20), 2), item(20, Some(10), 3), item(30, Some(99), 4)], ..Default::default() };
        write(&pool, &import, 1).await.unwrap();
        let site = Site::load(&pool).await.unwrap();
        let parents: Vec<(i64, Option<i64>)> = site.menus.iter().map(|m| (m.id, m.parent_id)).collect();
        assert_eq!(parents, [(10, None), (15, Some(20)), (20, Some(10)), (30, None)]);
    }

    #[tokio::test]
    async fn a_reimport_where_two_pages_trade_paths_is_stored() {
        let page = |id: i64, path: &str| Page {
            id: PageId(id),
            kind: PostType::Page,
            name: path.trim_matches('/').into(),
            group_id: id,
            lang: Lang::En,
            path: path.into(),
            template: Template::Page,
            status: Status::Publish,
            title: format!("Page {id}"),
            parent_id: None,
            menu_order: 0,
            published_at: "2024-01-01 00:00:00".into(),
            modified_at: "2024-01-01 00:00:00".into(),
            author_id: None,
        };
        let pool = crate::db::open_memory().await.unwrap();
        write(&pool, &Import { pages: vec![page(1, "/pins/"), page(2, "/clamps/")], ..Default::default() }, 1).await.unwrap();
        write(&pool, &Import { pages: vec![page(1, "/clamps/"), page(2, "/pins/"), page(3, "/vises/")], ..Default::default() }, 2).await.unwrap();
        let site = Site::load(&pool).await.unwrap();
        let mut paths: Vec<(i64, &str)> = site.pages.iter().map(|p| (p.id.0, p.path.as_str())).collect();
        paths.sort();
        assert_eq!(paths, [(1, "/clamps/"), (2, "/pins/"), (3, "/vises/")]);
    }

    #[test]
    fn a_loop_of_parents_is_broken_at_its_first_item() {
        let items = [item(1, Some(2), 1), item(2, Some(1), 2), item(3, Some(1), 3), item(4, None, 4)];
        let order: Vec<(i64, Option<i64>)> = parents_first(&items).iter().map(|(m, p)| (m.id, *p)).collect();
        assert_eq!(order, [(4, None), (1, None), (2, Some(1)), (3, Some(1))]);
    }
}
