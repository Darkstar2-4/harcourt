//! Order-side pages that are not the cart: the contact page.

use std::sync::Arc;

use axum::{routing::get, Router};

use super::Orders;

pub mod contact;

pub fn routes() -> Router<Arc<Orders>> {
    Router::new().route("/contact.php", get(contact::page))
}
