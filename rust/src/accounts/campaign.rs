//! Marketing campaigns (classes/campaign.php). A visitor who arrives with `?campaign=CODE` belongs to that campaign
//! while it is active; Invite campaigns are only seen by visitors who came in that way.

use sqlx::SqlitePool;

use crate::accounts::clock::Date;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Kind {
    Invite,
    Public,
    Remote,
}

impl Kind {
    /// NULL or unknown text is the column default, Invite.
    fn parse(s: Option<&str>) -> Kind {
        match s {
            Some("Public") => Kind::Public,
            Some("Remote") => Kind::Remote,
            _ => Kind::Invite,
        }
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Campaign {
    pub id: i64,
    pub code: String,
    pub name: String,
    pub kind: Kind,
}

/// The campaign with this code (any case, as MySQL compared it) when it is active on `today`.
pub async fn active(pool: &SqlitePool, code: &str, today: Date) -> sqlx::Result<Option<Campaign>> {
    let row = sqlx::query!(
        r#"SELECT id AS "id!: i64", code AS "code!", name, "type" AS ty, active, from_date, thru_date
           FROM campaign WHERE code IS NOT NULL AND lower(code) = lower(?1) LIMIT 1"#,
        code
    )
    .fetch_optional(pool)
    .await?;
    let Some(r) = row else { return Ok(None) };
    let on = is_active(r.active.unwrap_or(0) != 0, r.from_date.as_deref().unwrap_or(""), r.thru_date.as_deref().unwrap_or(""), today);
    Ok(on.then(|| Campaign { id: r.id, code: r.code, name: r.name.unwrap_or_default(), kind: Kind::parse(r.ty.as_deref()) }))
}

/// classes/campaign.php active(). This reproduces the PHP's string ordering on purpose: it compared the stored
/// `YYYY-MM-DD` against `date("Y/m/d")`, and the zero date '0000-00-00' counts as an end date that has passed.
fn is_active(active: bool, from: &str, thru: &str, today: Date) -> bool {
    let today = today.slash();
    let thru_set = !thru.is_empty() && thru != "0";
    active && from.as_bytes() <= today.as_bytes() && !(thru_set && thru.as_bytes() < today.as_bytes())
}

#[cfg(test)]
mod tests {
    use super::*;

    const TODAY: Date = Date { year: 2026, month: 10, day: 29 };

    #[test]
    fn dates_compare_as_the_php_strings_did() {
        assert!(is_active(true, "", "", TODAY));
        assert!(!is_active(false, "", "", TODAY));
        assert!(is_active(true, "2026-12-01", "", TODAY), "a later date this year sorts before 2026/");
        assert!(!is_active(true, "2027-01-01", "", TODAY), "next year sorts after");
        assert!(is_active(true, "0000-00-00", "2027-06-30", TODAY));
        assert!(!is_active(true, "0000-00-00", "0000-00-00", TODAY), "the zero end date has passed");
        assert!(!is_active(true, "", "2026-12-31", TODAY), "an end date this year sorts before 2026/");
        assert!(is_active(true, "", "2027-01-01", TODAY));
    }

    #[tokio::test]
    async fn codes_match_in_any_case_and_inactive_rows_do_not() {
        let pool = crate::db::open_memory().await.unwrap();
        sqlx::query("INSERT INTO campaign (id, code, name, type, active, from_date, thru_date) VALUES (1, 'SPRING', 'Spring', 'Public', 1, NULL, NULL), (2, 'OFF', 'Off', NULL, 0, NULL, NULL)")
            .execute(&pool)
            .await
            .unwrap();
        let found = active(&pool, "spring", TODAY).await.unwrap().unwrap();
        assert_eq!((found.id, found.kind), (1, Kind::Public));
        assert!(active(&pool, "OFF", TODAY).await.unwrap().is_none());
        assert!(active(&pool, "nope", TODAY).await.unwrap().is_none());
    }
}
