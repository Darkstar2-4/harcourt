//! `hxweb job <name>`: the work cron.php used to start over HTTP, run as a command by a systemd timer.

use std::sync::Arc;

use axum::Router;

use crate::api::Auth;

pub fn routes() -> Router<Arc<Auth>> {
    Router::new()
}

pub async fn cli(args: &[String]) -> i32 {
    eprintln!("unknown job: {}", args.first().map_or("", String::as_str));
    2
}
