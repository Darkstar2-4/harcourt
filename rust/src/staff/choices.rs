//! The pick lists the staff forms share, the `select()` of the PHP table classes. Each returns its options as
//! (value, label markup) for `Form::select`, or as groups for `Form::optgroup`, in the PHP's order. Names sort
//! without regard to case, as MySQL's collation sorted them.

use sqlx::SqlitePool;

use super::html::{text, OptGroup};

pub type Options = Vec<(String, String)>;

/// language->select() with `local` off: English names, the country in brackets.
pub async fn languages(pool: &SqlitePool) -> sqlx::Result<Options> {
    let rows = sqlx::query!(r#"SELECT code, name, country_code FROM language ORDER BY priority, name COLLATE NOCASE"#).fetch_all(pool).await?;
    Ok(rows
        .into_iter()
        .map(|r| {
            let label = if r.country_code.is_empty() { r.name } else { format!("{} ({})", r.name, r.country_code) };
            (r.code, text(&label))
        })
        .collect())
}

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

/// document->select(): documents grouped under their top-level document, labelled "id. name". The site root and
/// its direct children sort last. Groups with the same name merge, as PHP array keys did.
pub async fn documents(pool: &SqlitePool) -> sqlx::Result<Vec<OptGroup>> {
    let rows = sqlx::query!(
        r#"SELECT id AS "id!: i64", name,
                  CASE WHEN parent_id = 1 THEN 9999 WHEN id = 1 THEN 9999 WHEN parent_id = 0 THEN id ELSE parent_id END AS "sort_key!: i64"
           FROM document ORDER BY 3, parent_id, name COLLATE NOCASE, id DESC"#
    )
    .fetch_all(pool)
    .await?;
    let mut groups: Vec<OptGroup> = Vec::new();
    let mut run: Option<(i64, String)> = None;
    for r in rows {
        let name = r.name.unwrap_or_default();
        if run.as_ref().is_none_or(|(key, _)| *key != r.sort_key) {
            run = Some((r.sort_key, name.clone()));
        }
        let group = run.as_ref().map_or("", |(_, g)| g.as_str());
        let option = (r.id.to_string(), text(&format!("{}. {name}", r.id)));
        match groups.iter_mut().find(|(g, _)| g == group) {
            Some((_, options)) => options.push(option),
            None => groups.push((group.to_string(), vec![option])),
        }
    }
    Ok(groups)
}

/// campaign->select(): campaigns by sort order and name, keyed by code, with their date range in the label.
pub async fn campaigns(pool: &SqlitePool, active_only: bool, salesforce_only: bool) -> sqlx::Result<Options> {
    let rows = sqlx::query!(
        r#"SELECT code, name, from_date, thru_date FROM campaign
           WHERE (?1 = 0 OR active = 1) AND (?2 = 0 OR COALESCE(sf_campaign_id, '') <> '')
           ORDER BY sort_order, name COLLATE NOCASE"#,
        active_only,
        salesforce_only
    )
    .fetch_all(pool)
    .await?;
    Ok(rows
        .into_iter()
        .map(|r| {
            let mut label = r.name.unwrap_or_default();
            if let Some(d) = r.from_date.as_deref().and_then(mdy) {
                label.push_str(&format!(" From: {d}"));
            }
            if let Some(d) = r.thru_date.as_deref().and_then(mdy) {
                label.push_str(&format!(" Thru: {d}"));
            }
            (r.code.unwrap_or_default(), text(&label))
        })
        .collect())
}

/// fn_date(..., "ymd") of a stored `YYYY-MM-DD`: `MM/DD/YYYY`, nothing for the zero date.
fn mdy(date: &str) -> Option<String> {
    let mut parts = date.trim().splitn(3, ['-', '/', ' ', '.']);
    let (y, m, d) = (parts.next()?, parts.next()?, parts.next()?);
    let d = d.get(..2).unwrap_or(d);
    (!matches!(date.trim(), "" | "0" | "0000-00-00")).then(|| format!("{m}/{d}/{y}"))
}

/// country->select(""): active countries, the United States and Canada first.
pub async fn countries(pool: &SqlitePool) -> sqlx::Result<Options> {
    let rows = sqlx::query!(
        r#"SELECT country, name FROM country WHERE state = '' AND active = 1
           ORDER BY CASE country WHEN 'US' THEN 1 WHEN 'CA' THEN 2 ELSE 3 END, name COLLATE NOCASE"#
    )
    .fetch_all(pool)
    .await?;
    Ok(rows.into_iter().map(|r| (r.country, text(&r.name.unwrap_or_default()))).collect())
}

/// country->select($country): the states or provinces of one country by name.
pub async fn states(pool: &SqlitePool, country: &str) -> sqlx::Result<Options> {
    let rows = sqlx::query!(r#"SELECT state, name FROM country WHERE country = ?1 AND state <> '' ORDER BY name COLLATE NOCASE"#, country)
        .fetch_all(pool)
        .await?;
    Ok(rows.into_iter().map(|r| (r.state, text(&r.name.unwrap_or_default()))).collect())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn campaign_dates_read_month_first_and_skip_the_zero_date() {
        assert_eq!(mdy("2017-10-09").as_deref(), Some("10/09/2017"));
        assert_eq!(mdy("0000-00-00"), None);
        assert_eq!(mdy(""), None);
    }
}
