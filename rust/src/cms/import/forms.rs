//! Gravity Forms definitions (`wp_gf_form`, `wp_gf_form_meta`) out of the same WordPress dump, copied as they are
//! into the orders area's tables of the same names (migration 0330), so the forms every page embeds render without
//! WordPress. Entries are not read: they stay where they are.

use std::collections::HashMap;

use sqlx::SqliteConnection;

use super::dump::{Tables, Value};

#[derive(Debug, Clone, PartialEq)]
pub struct GravityForm {
    pub id: i64,
    pub title: String,
    pub date_created: String,
    pub date_updated: Option<String>,
    pub is_active: i64,
    pub is_trash: i64,
    pub display_meta: Option<String>,
    pub entries_grid_meta: Option<String>,
    pub confirmations: Option<String>,
    pub notifications: Option<String>,
}

fn text(v: &Value) -> Option<String> {
    (*v != Value::Null).then(|| v.str().to_string())
}

/// The forms in the dump, by id; empty when the dump has no `wp_gf_form` rows.
pub fn read(tables: &Tables) -> Result<Vec<GravityForm>, String> {
    let Some(forms) = tables.get("wp_gf_form") else { return Ok(Vec::new()) };
    let c = |name: &str| forms.col(name).ok_or_else(|| format!("wp_gf_form has no column {name}"));
    let (id, title, created, updated, active, trash) = (c("id")?, c("title")?, c("date_created")?, c("date_updated")?, c("is_active")?, c("is_trash")?);
    let mut meta: HashMap<i64, [Option<String>; 4]> = HashMap::new();
    if let Some(m) = tables.get("wp_gf_form_meta") {
        let c = |name: &str| m.col(name).ok_or_else(|| format!("wp_gf_form_meta has no column {name}"));
        let (form, parts) = (c("form_id")?, [c("display_meta")?, c("entries_grid_meta")?, c("confirmations")?, c("notifications")?]);
        for r in &m.rows {
            meta.insert(r[form].int(), parts.map(|i| text(&r[i])));
        }
    }
    let mut out: Vec<GravityForm> = forms
        .rows
        .iter()
        .map(|r| {
            let [display_meta, entries_grid_meta, confirmations, notifications] = meta.remove(&r[id].int()).unwrap_or_default();
            GravityForm {
                id: r[id].int(),
                title: r[title].str().to_string(),
                date_created: r[created].str().to_string(),
                date_updated: text(&r[updated]),
                is_active: r[active].int(),
                is_trash: r[trash].int(),
                display_meta,
                entries_grid_meta,
                confirmations,
                notifications,
            }
        })
        .collect();
    out.sort_by_key(|f| f.id);
    Ok(out)
}

/// Upserts every form, leaving forms the dump lacks and all entries alone, so a rerun converges on the dump.
pub async fn write(tx: &mut SqliteConnection, forms: &[GravityForm]) -> sqlx::Result<()> {
    for f in forms {
        sqlx::query!(
            "INSERT INTO wp_gf_form (id, title, date_created, date_updated, is_active, is_trash) VALUES (?1, ?2, ?3, ?4, ?5, ?6)
             ON CONFLICT (id) DO UPDATE SET title = excluded.title, date_created = excluded.date_created,
                 date_updated = excluded.date_updated, is_active = excluded.is_active, is_trash = excluded.is_trash",
            f.id,
            f.title,
            f.date_created,
            f.date_updated,
            f.is_active,
            f.is_trash
        )
        .execute(&mut *tx)
        .await?;
        sqlx::query!(
            "INSERT INTO wp_gf_form_meta (form_id, display_meta, entries_grid_meta, confirmations, notifications) VALUES (?1, ?2, ?3, ?4, ?5)
             ON CONFLICT (form_id) DO UPDATE SET display_meta = excluded.display_meta, entries_grid_meta = excluded.entries_grid_meta,
                 confirmations = excluded.confirmations, notifications = excluded.notifications",
            f.id,
            f.display_meta,
            f.entries_grid_meta,
            f.confirmations,
            f.notifications
        )
        .execute(&mut *tx)
        .await?;
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reads_forms_with_their_meta() {
        let dump = "INSERT INTO `wp_gf_form` (`id`, `title`, `date_created`, `date_updated`, `is_active`, `is_trash`) VALUES (2,'Two','2020-01-01 00:00:00',NULL,1,0),(1,'One','2019-01-01 00:00:00','2019-02-01 00:00:00',0,1);\n\
                    INSERT INTO `wp_gf_form_meta` (`form_id`, `display_meta`, `entries_grid_meta`, `confirmations`, `notifications`) VALUES (1,'{\\\"a\\\":1}',NULL,'{}','{}');\n";
        let forms = read(&super::super::dump::parse(dump).unwrap()).unwrap();
        assert_eq!(forms.len(), 2);
        assert_eq!((forms[0].id, forms[0].title.as_str(), forms[0].is_active, forms[0].is_trash), (1, "One", 0, 1));
        assert_eq!(forms[0].display_meta.as_deref(), Some("{\"a\":1}"));
        assert_eq!(forms[0].entries_grid_meta, None);
        assert_eq!((forms[1].date_updated.as_deref(), forms[1].display_meta.as_deref()), (None, None));
        assert!(read(&super::super::dump::parse("").unwrap()).unwrap().is_empty());
    }
}
