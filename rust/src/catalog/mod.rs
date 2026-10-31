//! The catalog, rendered from SQLite: categories, subcategories (product families), parts, their content, the
//! search box and the files they link to. PHP rendered all of this in catalog.php, scscpq_wp.php and friends; the
//! markup, classes and ids here are the ones the site's CSS and `web/` scripts expect.
//!
//! `viewer` decides what a request may see, `html` writes markup and links, `php` reads the values the PHP stored
//! with serialize(), `registry` the site settings, `strings` the interface text per language. Each other module
//! owns one group of PHP entry points and contributes its routes to `router`.

use std::sync::Arc;

use axum::{Extension, Router};

use crate::api::Auth;

pub mod ajax;
pub mod api;
pub mod files;
pub mod forms;
pub mod html;
pub mod keywords;
pub mod learn;
pub mod loader;
pub mod model;
pub mod pages;
pub mod php;
pub mod profile;
pub mod registry;
pub mod search;
pub mod strings;
pub mod tabs;
pub mod viewer;
pub mod wp_search;

/// The WordPress catalog pages hxweb composes around the catalog (compose.rs): /catalog/ in English (no `lang`, or
/// `lang=en`) and /catalogue/ with `lang=fr`. Any other request for those paths is WordPress's to answer.
pub fn composed_page(path: &str, query: &str) -> Option<viewer::Lang> {
    match (path, query.split('&').find_map(|kv| kv.strip_prefix("lang="))) {
        ("/catalog/", None | Some("en")) => Some(viewer::Lang::En),
        ("/catalogue/", Some("fr")) => Some(viewer::Lang::Fr),
        _ => None,
    }
}

pub fn router(core: Arc<Auth>) -> Router<Arc<Auth>> {
    Router::new()
        .merge(pages::router())
        .merge(wp_search::router(core))
        .merge(search::router())
        .merge(loader::router())
        .merge(files::router())
        .merge(api::router())
        .merge(ajax::router())
        .layer(Extension(search::Cache::new()))
        .layer(Extension(Arc::new(pages::Models::default())))
}
