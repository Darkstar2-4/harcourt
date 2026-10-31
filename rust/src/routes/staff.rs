//! Owner: the staff agent. Routes for the staff dashboard and tools, imports, cron jobs, and the database console replacement.
//! Register every route here and nowhere else; mod.rs only merges this router.

use std::sync::Arc;

use axum::Router;

use crate::api::Auth;

pub fn router(core: Arc<Auth>) -> Router {
    crate::staff::router().with_state(core)
}
