//! One module per area of the site, each owned by one agent. A route that no module claims falls through to the
//! legacy Apache/PHP stack (proxy.rs). Porting a PHP page means claiming its path here and, once the page has
//! no traffic for 14 days, deleting the PHP file. When the last file is gone, so is the proxy.

use std::sync::Arc;

use axum::{middleware, Router};

use crate::{
    api::{self, Auth},
    visitor,
};

pub mod accounts;
pub mod catalog;
pub mod orders;
pub mod staff;
pub mod wordpress;

pub fn all(core: Arc<Auth>) -> Router {
    let pages = Router::new()
        .merge(accounts::router(core.clone()))
        .merge(catalog::router(core.clone()))
        .merge(orders::router(core.clone()))
        .merge(staff::router(core.clone()))
        .merge(wordpress::router(core.clone()))
        .layer(middleware::from_fn_with_state(core.clone(), visitor::layer));
    Router::new().nest_service("/api", api::router(core)).merge(pages)
}
