//! Order-side pages that are not the cart: carrier codes, the contact page and the Design Partner Agreement.

use std::sync::Arc;

use axum::{routing::get, Router};

use super::Orders;

mod carrier;
pub mod contact;
mod design_partner;

pub fn routes() -> Router<Arc<Orders>> {
    Router::new().route("/carrier.php", get(carrier::page).post(carrier::page)).route("/contact.php", get(contact::page))
        .route("/design_partner_agreement.php", get(design_partner::page).post(design_partner::page))
}
