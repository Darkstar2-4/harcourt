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
