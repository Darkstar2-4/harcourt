//! SQLite through sqlx. The schema is `migrations/`, applied on open; queries elsewhere are checked against it at
//! compile time (`cargo sqlx prepare` keeps `.sqlx/` current for builds that have no database).

use std::{path::Path, str::FromStr, time::Duration};

use sqlx::{
    migrate::Migrator,
    sqlite::{SqliteConnectOptions, SqliteJournalMode, SqlitePoolOptions, SqliteSynchronous},
    SqlitePool,
};

pub static MIGRATOR: Migrator = sqlx::migrate!("./migrations");

pub async fn open(path: &Path) -> Result<SqlitePool, sqlx::Error> {
    let options = SqliteConnectOptions::new()
        .filename(path)
        .create_if_missing(true)
        .journal_mode(SqliteJournalMode::Wal)
        .synchronous(SqliteSynchronous::Normal)
        .foreign_keys(true)
        .busy_timeout(Duration::from_secs(5))
        // Pages are read from the mapped file instead of copied by a system call each; each connection keeps 16 MB.
        .pragma("mmap_size", "1073741824")
        .pragma("cache_size", "-16384");
    let pool = SqlitePoolOptions::new().max_connections(8).connect_with(options).await?;
    MIGRATOR.run(&pool).await?;
    if crate::catalog::stored::unconverted(&pool).await? {
        return Err(sqlx::Error::Configuration("PHP-serialized columns: run `hx-import --convert` on this file first (docs/rust-migration.md)".into()));
    }
    Ok(pool)
}

/// A private in-memory database for tests: one connection, because every SQLite memory connection is its own database.
pub async fn open_memory() -> Result<SqlitePool, sqlx::Error> {
    let options = SqliteConnectOptions::from_str("sqlite::memory:")?.foreign_keys(true);
    let pool = SqlitePoolOptions::new()
        .max_connections(1)
        .idle_timeout(None)
        .max_lifetime(None)
        .connect_with(options)
        .await?;
    MIGRATOR.run(&pool).await?;
    Ok(pool)
}

pub fn unix_now() -> i64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_secs() as i64)
        .unwrap_or(0)
}

#[cfg(test)]
mod tests {
    use std::borrow::Cow;

    use super::*;

    #[tokio::test]
    async fn rebuilding_for_stable_ids_keeps_every_row_and_counts_past_the_ids_in_use() {
        let options = SqliteConnectOptions::from_str("sqlite::memory:").unwrap().foreign_keys(true);
        let pool = SqlitePoolOptions::new().max_connections(1).idle_timeout(None).max_lifetime(None).connect_with(options).await.unwrap();
        let earlier = Migrator { migrations: Cow::Owned(MIGRATOR.iter().filter(|m| m.version < 902).cloned().collect()), ..Migrator::DEFAULT };
        earlier.run(&pool).await.unwrap();
        sqlx::raw_sql(
            r#"INSERT INTO "user" (id, company_name, status) VALUES (1, 'Works', 'Active'), (7, 'Shop', 'Active');
               INSERT INTO session (token_hash, user_id, via, email, created_at, expires_at) VALUES (zeroblob(32), 7, 'registration', 'pat@shop.example', 0, 1);
               INSERT INTO cart_item (session_hash, sku, quantity, added_at) VALUES (zeroblob(32), 'TC-100', 2, 0);
               INSERT INTO log (user_id, subcategory_id) VALUES (12, 30);
               INSERT INTO subcategory (id, name) VALUES (4, 'Pins');
               INSERT INTO content (record_type, record_id, record_item, language_code) VALUES ('subcategory', 4, 'overview', 'EN');"#,
        )
        .execute(&pool)
        .await
        .unwrap();
        MIGRATOR.run(&pool).await.unwrap();
        let kept: (i64, i64, i64, i64) = sqlx::query_as(
            r#"SELECT (SELECT count(*) FROM "user"), (SELECT count(*) FROM session WHERE user_id = 7), (SELECT count(*) FROM cart_item), (SELECT count(*) FROM content)"#,
        )
        .fetch_one(&pool)
        .await
        .unwrap();
        assert_eq!(kept, (2, 1, 1, 1));
        let fk: bool = sqlx::query_scalar("PRAGMA foreign_keys").fetch_one(&pool).await.unwrap();
        assert!(fk, "foreign keys are on again");
        let user = sqlx::query(r#"INSERT INTO "user" (company_name) VALUES ('New')"#).execute(&pool).await.unwrap().last_insert_rowid();
        let family = sqlx::query("INSERT INTO subcategory (name) VALUES ('Clamps')").execute(&pool).await.unwrap().last_insert_rowid();
        assert_eq!((user, family), (13, 31), "past the ids the log names");
        sqlx::query(r#"DELETE FROM "user" WHERE id = 7"#).execute(&pool).await.unwrap();
        let sessions: i64 = sqlx::query_scalar("SELECT count(*) FROM session").fetch_one(&pool).await.unwrap();
        assert_eq!(sessions, 0, "foreign keys still cascade");
    }
}
