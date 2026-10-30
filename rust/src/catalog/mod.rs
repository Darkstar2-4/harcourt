//! The catalog, rendered from SQLite: categories, subcategories (product families), parts, their content, the
//! search box and the files they link to. PHP rendered all of this in catalog.php, scscpq_wp.php and friends; the
//! markup, classes and ids here are the ones the site's CSS and `web/` scripts expect.
//!
//! `viewer` decides what a request may see, `html` writes markup and links, `php` reads the values the PHP stored
//! with serialize(), `registry` the site settings, `strings` the interface text per language. Each other module
//! owns one group of PHP entry points and contributes its routes to `router`.

use std::sync::Arc;

use axum::Router;

use crate::api::Auth;

pub mod files;
pub mod html;
pub mod loader;
pub mod pages;
pub mod php;
pub mod registry;
pub mod search;
pub mod strings;
pub mod viewer;

pub fn router() -> Router<Arc<Auth>> {
    Router::new().merge(pages::router()).merge(search::router()).merge(loader::router()).merge(files::router())
}
