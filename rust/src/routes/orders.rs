//! Owner: the orders agent. Routes for cart, checkout, the contact page and the site's forms; the mail sender that drains the outbox starts in main.rs.
//! Register every route here and nowhere else; mod.rs only merges this router.

use std::sync::Arc;

use axum::Router;

use crate::{
    api::Auth,
    orders::{self, Orders},
};

pub fn router(core: Arc<Auth>) -> Router {
    orders::router(Arc::new(Orders::from_env(core)))
}
