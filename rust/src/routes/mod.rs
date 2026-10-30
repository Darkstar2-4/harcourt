//! One module per area of the site, each owned by one agent. A route that no module claims falls through to the
//! legacy Apache/PHP stack (proxy.rs). Porting a PHP page means claiming its path here and, once the page has
//! no traffic for 14 days, deleting the PHP file. When the last file is gone, so is the proxy.

use std::sync::Arc;

use axum::Router;

use crate::api::{self, Auth};

pub mod accounts;
pub mod catalog;
pub mod orders;
pub mod staff;
pub mod wordpress;

pub fn all(core: Arc<Auth>) -> Router {
    Router::new()
        .nest_service("/api", api::router(core.clone()))
        .merge(accounts::router(core.clone()))
        .merge(catalog::router(core.clone()))
        .merge(orders::router(core.clone()))
        .merge(staff::router(core.clone()))
        .merge(wordpress::router(core))
}
