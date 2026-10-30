//! Site settings in the `registry` table (includes/classes/registry.php). The site's own rows (domain '') win over
//! the shared 'global' ones.

use sqlx::SqlitePool;

pub async fn get(pool: &SqlitePool, module: &str, field: &str) -> sqlx::Result<Option<String>> {
    let data = sqlx::query_scalar!(
        r#"SELECT data FROM registry WHERE domain IN ('', 'global') AND module = ?1 AND field = ?2 AND item = ''
           ORDER BY domain = 'global' LIMIT 1"#,
        module,
        field
    )
    .fetch_optional(pool)
    .await?;
    Ok(data.flatten().filter(|d| !d.is_empty()))
}

/// The items of a list setting (registry rows with an `item`), each item from the site's row if it has one.
pub async fn items(pool: &SqlitePool, module: &str, field: &str) -> sqlx::Result<Vec<(String, String)>> {
    let rows = sqlx::query!(
        r#"SELECT item AS "item!", data, domain AS "domain!" FROM registry
           WHERE domain IN ('', 'global') AND module = ?1 AND field = ?2 AND item <> ''
           ORDER BY item, domain = 'global'"#,
        module,
        field
    )
    .fetch_all(pool)
    .await?;
    let mut out: Vec<(String, String)> = Vec::new();
    for r in rows {
        if out.last().is_some_and(|(item, _)| *item == r.item) {
            continue;
        }
        if let Some(data) = r.data {
            out.push((r.item, data));
        }
    }
    Ok(out)
}

pub async fn number(pool: &SqlitePool, module: &str, field: &str) -> sqlx::Result<Option<f64>> {
    Ok(get(pool, module, field).await?.and_then(|d| d.trim().parse().ok()))
}
