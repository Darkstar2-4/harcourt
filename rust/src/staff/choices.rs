//! The pick lists the staff forms share, the `select()` of the PHP table classes. Each returns its options as
//! (value, label markup) for `Form::select`, or as groups for `Form::optgroup`, in the PHP's order. Names sort
//! without regard to case, as MySQL's collation sorted them.

use sqlx::SqlitePool;

use super::html::{text, OptGroup};

pub type Options = Vec<(String, String)>;

/// currency->select(): currencies with an exchange rate under "Priced", the rest under "No Price".
pub async fn currencies(pool: &SqlitePool) -> sqlx::Result<Vec<OptGroup>> {
    let rows = sqlx::query!(r#"SELECT code AS "code!", name, COALESCE(exchange_rate, 0) > 0 AS "priced!: bool" FROM currency WHERE code IS NOT NULL ORDER BY name COLLATE NOCASE"#)
        .fetch_all(pool)
        .await?;
    let mut priced = Vec::new();
    let mut unpriced = Vec::new();
    for r in rows {
        let option = (r.code, text(&r.name.unwrap_or_default()));
        if r.priced {
            priced.push(option);
        } else {
            unpriced.push(option);
        }
    }
    Ok(vec![("Priced".into(), priced), ("No Price".into(), unpriced)])
}

/// industry->select(): active industries by name.
pub async fn industries(pool: &SqlitePool) -> sqlx::Result<Options> {
    let rows = sqlx::query!(r#"SELECT id AS "id!: i64", name FROM industry WHERE active = 1 ORDER BY name COLLATE NOCASE"#).fetch_all(pool).await?;
    Ok(rows.into_iter().map(|r| (r.id.to_string(), text(&r.name))).collect())
}

/// portal->select(): active portals by name.
pub async fn portals(pool: &SqlitePool) -> sqlx::Result<Options> {
    let rows = sqlx::query!(r#"SELECT id AS "id!: i64", name FROM portal WHERE active = 1 ORDER BY name COLLATE NOCASE"#).fetch_all(pool).await?;
    Ok(rows.into_iter().map(|r| (r.id.to_string(), text(&r.name))).collect())
}
