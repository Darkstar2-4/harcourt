//! Order-side pages that are not the cart: carrier codes and the contact page.

use std::sync::Arc;

use axum::{routing::get, Router};

use super::Orders;

mod carrier;
pub mod contact;

pub fn routes() -> Router<Arc<Orders>> {
    Router::new().route("/carrier.php", get(carrier::page).post(carrier::page)).route("/contact.php", get(contact::page))
}
