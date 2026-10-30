//! Files the catalog links to: scs_file.php (documents and resized images by content id), image_viewer.php, inventory_info.php, scs_index.php, customer_portal.php and portal_ajax.php.

use std::sync::Arc;

use axum::Router;

use crate::api::Auth;

pub fn router() -> Router<Arc<Auth>> {
    Router::new()
}
