//! Order-side pages that are not the cart: punch-out (punchout.php), the design partner agreement, contact, and the
//! mail template tools.

use std::sync::Arc;

use axum::Router;

use super::Orders;

pub fn routes() -> Router<Arc<Orders>> {
    Router::new()
}
