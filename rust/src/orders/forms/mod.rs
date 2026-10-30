//! The site's Gravity Forms: their markup, validation, spam checks, notifications and stored entries.

use std::sync::Arc;

use axum::Router;

use super::Orders;

/// Outside services the forms use (spam checks). Tests replace them with doubles.
#[derive(Default)]
pub struct Services {}

impl Services {
    pub fn from_env(get: impl Fn(&str) -> Option<String>) -> Self {
        let _ = get;
        Services {}
    }
}

pub fn routes() -> Router<Arc<Orders>> {
    Router::new()
}
