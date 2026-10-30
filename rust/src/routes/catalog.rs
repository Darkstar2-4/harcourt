//! Owner: the catalog agent. Routes for the catalog and everything a visitor reads: catalog.php, scscpq_wp.php, scscpq_api.php, scs_file.php, scs_index.php, search, documents, hero, portal pages.
//! Register every route here and nowhere else; mod.rs only merges this router. The routes themselves live with the
//! code that answers them, in `crate::catalog`.

use std::sync::Arc;

use axum::Router;

use crate::api::Auth;

pub fn router(core: Arc<Auth>) -> Router {
    crate::catalog::router().with_state(core)
}
