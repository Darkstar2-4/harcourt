//! The form definitions out of the WordPress dump that `hxweb-cms import` reads: `wp_gf_form` and
//! `wp_gf_form_meta`, copied as they are. Entries are not imported; they stay in WordPress.

use sqlx::SqlitePool;

use crate::cms::import::dump::{Table, Tables, Value};

/// Replaces every form definition with the dump's, in one transaction, so a second run over the same dump changes
/// nothing. None, and nothing written, when the dump has no forms.
pub async fn write(pool: &SqlitePool, tables: &Tables) -> Result<Option<usize>, String> {
    let (Some(forms), Some(meta)) = (tables.get("wp_gf_form"), tables.get("wp_gf_form_meta")) else { return Ok(None) };
    let forms = rows(forms, &["id", "title", "date_created", "date_updated", "is_active", "is_trash"])?;
    let meta = rows(meta, &["form_id", "display_meta", "entries_grid_meta", "confirmations", "notifications"])?;
    let db = |e: sqlx::Error| e.to_string();
    let mut tx = pool.begin().await.map_err(db)?;
    sqlx::query!("DELETE FROM wp_gf_form_meta").execute(&mut *tx).await.map_err(db)?;
    sqlx::query!("DELETE FROM wp_gf_form").execute(&mut *tx).await.map_err(db)?;
    for r in &forms {
        let (id, title, created, updated, active, trash) = (r[0].int(), r[1].str(), r[2].str(), text(r[3]), r[4].int(), r[5].int());
        sqlx::query!(
            "INSERT INTO wp_gf_form (id, title, date_created, date_updated, is_active, is_trash) VALUES (?1, ?2, ?3, ?4, ?5, ?6)",
            id,
            title,
            created,
            updated,
            active,
            trash
        )
        .execute(&mut *tx)
        .await
        .map_err(db)?;
    }
    for r in &meta {
        let (id, display, grid, confirmations, notifications) = (r[0].int(), text(r[1]), text(r[2]), text(r[3]), text(r[4]));
        sqlx::query!(
            "INSERT INTO wp_gf_form_meta (form_id, display_meta, entries_grid_meta, confirmations, notifications) VALUES (?1, ?2, ?3, ?4, ?5)",
            id,
            display,
            grid,
            confirmations,
            notifications
        )
        .execute(&mut *tx)
        .await
        .map_err(db)?;
    }
    tx.commit().await.map_err(db)?;
    Ok(Some(forms.len()))
}

fn text(v: &Value) -> Option<&str> {
    (*v != Value::Null).then(|| v.str())
}

fn rows<'a>(table: &'a Table, names: &[&str]) -> Result<Vec<Vec<&'a Value>>, String> {
    let at: Vec<usize> = names.iter().map(|n| table.col(n).ok_or_else(|| format!("the dump's form table has no column {n}"))).collect::<Result<_, _>>()?;
    Ok(table.rows.iter().map(|row| at.iter().map(|&i| &row[i]).collect()).collect())
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::cms::import::dump::parse;

    const DUMP: &str = "INSERT INTO `wp_gf_form` (`id`, `title`, `date_created`, `date_updated`, `is_active`, `is_trash`) VALUES (3,'Contact','2020-01-01 00:00:00',NULL,1,0),(4,'Old','2020-01-01 00:00:00',NULL,0,1);\n\
INSERT INTO `wp_gf_form_meta` (`form_id`, `display_meta`, `entries_grid_meta`, `confirmations`, `notifications`) VALUES (3,'{\\\"id\\\":3}',NULL,'{}','{}'),(4,'{}',NULL,NULL,NULL);\n";

    #[tokio::test]
    async fn the_dump_replaces_the_definitions_and_a_rerun_converges() {
        let pool = crate::db::open_memory().await.unwrap();
        sqlx::query("INSERT INTO wp_gf_form (id, title, date_created) VALUES (9, 'Gone', '2019-01-01 00:00:00')").execute(&pool).await.unwrap();
        let tables = parse(DUMP).unwrap();
        for _ in 0..2 {
            assert_eq!(write(&pool, &tables).await.unwrap(), Some(2));
            let forms: Vec<(i64, String, i64)> = sqlx::query_as("SELECT id, title, is_active FROM wp_gf_form ORDER BY id").fetch_all(&pool).await.unwrap();
            assert_eq!(forms, vec![(3, "Contact".to_string(), 1), (4, "Old".to_string(), 0)]);
            let meta: (String, Option<String>) = sqlx::query_as("SELECT display_meta, entries_grid_meta FROM wp_gf_form_meta WHERE form_id = 3").fetch_one(&pool).await.unwrap();
            assert_eq!(meta, ("{\"id\":3}".to_string(), None));
        }
    }

    #[tokio::test]
    async fn a_dump_without_forms_leaves_them_alone() {
        let pool = crate::db::open_memory().await.unwrap();
        sqlx::query("INSERT INTO wp_gf_form (id, title, date_created) VALUES (9, 'Kept', '2019-01-01 00:00:00')").execute(&pool).await.unwrap();
        assert_eq!(write(&pool, &parse("INSERT INTO `wp_posts` (`ID`) VALUES (1);\n").unwrap()).await.unwrap(), None);
        let n: i64 = sqlx::query_scalar("SELECT count(*) FROM wp_gf_form").fetch_one(&pool).await.unwrap();
        assert_eq!(n, 1);
    }
}
