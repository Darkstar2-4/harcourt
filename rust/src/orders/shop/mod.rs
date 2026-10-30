//! The cart (cart.php), checkout (checkout.php) and the order viewer (orderhd.php).

use std::sync::Arc;

use axum::Router;

use super::Orders;

pub fn routes() -> Router<Arc<Orders>> {
    Router::new()
}
