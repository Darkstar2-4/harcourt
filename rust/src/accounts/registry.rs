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

pub async fn number(pool: &SqlitePool, module: &str, field: &str) -> sqlx::Result<Option<f64>> {
    Ok(get(pool, module, field).await?.and_then(|d| d.trim().parse().ok()))
}
