//! Order-side pages that are not the cart: carrier codes, the contact page, the Design Partner Agreement and the
//! Email Output Tester.

use std::sync::Arc;

use axum::{
    routing::{any, get},
    Router,
};

use super::Orders;

mod carrier;
pub mod contact;
mod design_partner;
mod mailer_output;

pub fn routes() -> Router<Arc<Orders>> {
    Router::new()
        .route("/carrier.php", get(carrier::page).post(carrier::page))
        .route("/contact.php", get(contact::page))
        .route("/design_partner_agreement.php", get(design_partner::page).post(design_partner::page))
        .route("/mailer_output.php", get(mailer_output::get).post(mailer_output::post))
        .route("/scs_mail.php", any(mailer_output::old_tester))
        .route("/scs_mailform.php", any(mailer_output::old_editor))
}
