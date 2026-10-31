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

/// classes/campaign.php active(), on the dates as its fetch() read them through `fn_date($date, 'mysql')`: the
/// zero date is no date, and `YYYY-MM-DD` becomes `YYYY/MM/DD`, compared as a string with `date("Y/m/d")`.
fn is_active(active: bool, from: &str, thru: &str, today: Date) -> bool {
    let today = today.slash();
    let (from, thru) = (mysql_date(from), mysql_date(thru));
    active && from <= today && (thru.is_empty() || thru >= today)
}

/// `fn_date($stored, 'mysql')`.
fn mysql_date(stored: &str) -> String {
    if matches!(stored, "" | "0" | "0000-00-00" | "0000/00/00") {
        return String::new();
    }
    let mut parts = stored.split([' ', '/', '.', '-']);
    let mut next = || parts.next().unwrap_or("");
    format!("{}/{}/{}", next(), next(), next())
}

#[cfg(test)]
mod tests {
    use super::*;

    const TODAY: Date = Date { year: 2026, month: 10, day: 29 };

    #[test]
    fn dates_compare_as_fn_date_read_them() {
        assert!(is_active(true, "", "", TODAY));
        assert!(!is_active(false, "", "", TODAY));
        assert!(!is_active(true, "2026-12-01", "", TODAY), "starts later this year");
        assert!(is_active(true, "2026-10-29", "", TODAY), "starts today");
        assert!(is_active(true, "0000-00-00", "2027-06-30", TODAY));
        assert!(is_active(true, "0000-00-00", "0000-00-00", TODAY), "the zero date is no date");
        assert!(is_active(true, "", "2026-12-31", TODAY), "ends later this year");
        assert!(is_active(true, "", "2026-10-29", TODAY), "ends today");
        assert!(!is_active(true, "", "2026-10-28", TODAY), "ended yesterday");
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
