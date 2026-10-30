//! Files the catalog links to. scs_file.php serves a `content` row (an overview image, a datasheet) by its
//! encoded id, or a site image resized; customer_portal.php frames the customer portal; scs_index.php was the SCS
//! home, now the site's own.

use std::{collections::HashMap, path::PathBuf, sync::Arc};

use axum::{
    extract::{Query, State},
    http::{header, HeaderValue, StatusCode},
    response::{IntoResponse, Redirect, Response},
    routing::get,
    Router,
};
use sqlx::SqlitePool;

use super::{
    html,
    model::Status,
    registry,
    viewer::{self, Lang, Viewer},
};
use crate::{api::Auth, visitor::Visitor};

pub fn router() -> Router<Arc<Auth>> {
    Router::new()
        .route("/scs_file.php", get(scs_file))
        .route("/customer_portal.php", get(customer_portal))
        .route("/scs_index.php", get(|| async { Redirect::permanent("/") }))
}

/// What a content row is attached to, which decides who may fetch it.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Owner {
    /// A product family's overview, specials, customs or download: as visible as the family.
    Family { status: Status, category_active: bool, category_id: i64 },
    /// A sign-in or landing document, a page or a hero: shown to visitors who are not signed in too.
    Public,
    /// A portal's content: for the accounts of that portal.
    Portal(i64),
    Unknown,
}

/// Whether this viewer may fetch content owned by `owner`.
pub fn may_fetch(viewer: &Viewer, owner: Owner) -> bool {
    match owner {
        Owner::Public => true,
        Owner::Family { status, category_active, category_id } => {
            viewer.may_browse() && status.visible(viewer.sees_pending()) && category_active && !viewer.blocked.contains(category_id)
        }
        Owner::Portal(id) => viewer.may_browse() && viewer.portal_id == Some(id),
        Owner::Unknown => false,
    }
}

struct Content {
    meta: serde_json::Value,
    bytes: Vec<u8>,
    owner: Owner,
}

async fn family_owner(pool: &SqlitePool, family_id: i64) -> sqlx::Result<Owner> {
    let row = sqlx::query!(
        r#"SELECT s.status AS "status!", s.category_id AS "category_id: i64", c.active AS "active: i64"
           FROM subcategory s LEFT JOIN category c ON c.id = s.category_id WHERE s.id = ?1"#,
        family_id
    )
    .fetch_optional(pool)
    .await?;
    Ok(row.map_or(Owner::Unknown, |r| {
        let status = match r.status.as_str() {
            "Active" => Status::Active,
            "Pending" => Status::Pending,
            _ => Status::Inactive,
        };
        Owner::Family { status, category_active: r.active.unwrap_or(0) != 0, category_id: r.category_id.unwrap_or(0) }
    }))
}

async fn content(pool: &SqlitePool, id: i64) -> sqlx::Result<Option<Content>> {
    let Some(r) = sqlx::query!(
        r#"SELECT record_type AS "record_type!", record_id AS "record_id!: i64", filetype AS "filetype!", meta, content, html_content FROM content WHERE id = ?1"#,
        id
    )
    .fetch_optional(pool)
    .await?
    else {
        return Ok(None);
    };
    let owner = match r.record_type.as_str() {
        "subcategory" => family_owner(pool, r.record_id).await?,
        "inventory" => {
            let family = sqlx::query_scalar!(r#"SELECT subcategory_id AS "id: i64" FROM inventory WHERE id = ?1"#, r.record_id).fetch_optional(pool).await?.flatten();
            match family {
                Some(f) => family_owner(pool, f).await?,
                None => Owner::Unknown,
            }
        }
        "document" | "pages" | "hero" => Owner::Public,
        "portalcontent" => {
            let portal = sqlx::query_scalar!(r#"SELECT portal_id AS "portal_id!: i64" FROM portalcontent WHERE id = ?1"#, r.record_id).fetch_optional(pool).await?;
            portal.map_or(Owner::Unknown, Owner::Portal)
        }
        _ => Owner::Unknown,
    };
    let bytes = if matches!(r.filetype.as_str(), "html" | "hyperlink") { r.html_content.unwrap_or_default().into_bytes() } else { r.content.unwrap_or_default() };
    let meta = r.meta.as_deref().and_then(|m| serde_json::from_str(m).ok()).unwrap_or_default();
    Ok(Some(Content { meta, bytes, owner }))
}

/// The display size file_class::image() picks: a requested width or height only when smaller than the image, the
/// other side kept in proportion.
pub fn display_size(image: (u32, u32), width: u32, height: u32) -> (u32, u32) {
    let (iw, ih) = image;
    let w = if width > iw { 0 } else { width };
    let h = if height > ih { 0 } else { height };
    let (dw, dh) = match (w, h) {
        (0, 0) => (iw, ih),
        (w, 0) => (w, (f64::from(ih) * f64::from(w) / f64::from(iw.max(1))).round() as u32),
        (0, h) => ((f64::from(iw) * f64::from(h) / f64::from(ih.max(1))).round() as u32, h),
        (w, h) => (w, h),
    };
    (dw.max(1), dh.max(1))
}

fn param_u32(q: &HashMap<String, String>, k: &str) -> u32 {
    q.get(k).map_or(0, |v| super::php::Value::Str(v.clone()).int().clamp(0, 10_000) as u32)
}

fn not_found() -> Response {
    (StatusCode::NOT_FOUND, [(header::CACHE_CONTROL, "no-store")], "Content missing").into_response()
}

/// An image, resized when a smaller width or height is asked for; anything else as stored.
fn image_response(bytes: Vec<u8>, mime: &str, name: &str, width: u32, height: u32, attachment: bool) -> Response {
    let mut body = bytes;
    let mut mime = mime.to_string();
    if width > 0 || height > 0 {
        if let Ok(img) = image::load_from_memory(&body) {
            let (dw, dh) = display_size((img.width(), img.height()), width, height);
            if (dw, dh) != (img.width(), img.height()) {
                let format = image::guess_format(&body).unwrap_or(image::ImageFormat::Png);
                let resized = img.resize_exact(dw, dh, image::imageops::FilterType::Triangle);
                let mut out = std::io::Cursor::new(Vec::new());
                if resized.write_to(&mut out, format).is_ok() {
                    body = out.into_inner();
                    mime = format.to_mime_type().to_string();
                }
            }
        }
    }
    file_response(body, &mime, name, attachment)
}

fn file_response(body: Vec<u8>, mime: &str, name: &str, attachment: bool) -> Response {
    let mut resp = (StatusCode::OK, body).into_response();
    let headers = resp.headers_mut();
    headers.insert(header::CONTENT_TYPE, HeaderValue::from_str(mime).unwrap_or(HeaderValue::from_static("application/octet-stream")));
    headers.insert(header::PRAGMA, HeaderValue::from_static("no-cache"));
    headers.insert(header::EXPIRES, HeaderValue::from_static("0"));
    headers.insert(header::X_CONTENT_TYPE_OPTIONS, HeaderValue::from_static("nosniff"));
    if attachment {
        let safe: String = name.chars().map(|c| if c.is_ascii_alphanumeric() || "._-".contains(c) { c } else { '_' }).collect();
        if let Ok(v) = HeaderValue::from_str(&format!("attachment; filename={safe}")) {
            headers.insert(header::CONTENT_DISPOSITION, v);
        }
    }
    resp
}

/// A site image named by `code`: a jpg, png or gif under the document root, never above it.
fn site_image(code: &str) -> Option<(Vec<u8>, String)> {
    let root = PathBuf::from(std::env::var_os("HXWEB_DOCROOT")?);
    let rel = code.trim_start_matches('/');
    if rel.split('/').any(|seg| seg == ".." || seg.starts_with('.')) {
        return None;
    }
    let lower = rel.to_ascii_lowercase();
    let mime = [(".jpg", "image/jpeg"), (".jpeg", "image/jpeg"), (".png", "image/png"), (".gif", "image/gif")].into_iter().find(|(ext, _)| lower.ends_with(ext))?.1;
    let bytes = std::fs::read(root.join(rel)).ok()?;
    Some((bytes, mime.to_string()))
}

async fn scs_file(State(core): State<Arc<Auth>>, visitor: Visitor, Query(q): Query<HashMap<String, String>>) -> Response {
    let (width, height) = (param_u32(&q, "width"), param_u32(&q, "height"));
    let override_ = q.get("override").is_some_and(|v| v != "0" && !v.is_empty());
    let Some(encoded) = q.get("content_id") else {
        return match q.get("code").and_then(|c| site_image(c)) {
            Some((bytes, mime)) => image_response(bytes, &mime, "", width, height, false),
            None => not_found(),
        };
    };
    let id = html::base64_fields_decode(encoded).and_then(|f| f.first().map(|s| super::php::Value::Str(s.clone()).int())).unwrap_or(0);
    let result = async {
        let viewer = viewer::load(&core.pool, &visitor, Lang::En).await?;
        Ok::<_, sqlx::Error>(content(&core.pool, id).await?.filter(|c| may_fetch(&viewer, c.owner)))
    }
    .await;
    let found = match result {
        Ok(Some(c)) => c,
        Ok(None) => return not_found(),
        Err(e) => {
            tracing::error!("scs_file: {e}");
            return (StatusCode::INTERNAL_SERVER_ERROR, "Internal error").into_response();
        }
    };
    let text = |k: &str| found.meta.get(k).and_then(|v| v.as_str()).unwrap_or("").to_string();
    let (mime, name) = (text("mime"), text("name"));
    let is_image = found.meta.get("type").is_some_and(|t| t.as_i64().unwrap_or(0) != 0 || t.as_str().is_some_and(|s| !s.is_empty() && s != "0"));
    if is_image {
        return image_response(found.bytes, &mime, &name, width, height, override_);
    }
    let inline = mime == "text/plain" || mime == "text/html";
    file_response(found.bytes, if mime.is_empty() { "application/octet-stream" } else { &mime }, &name, override_ == inline)
}

/// customer_portal.php in the site's page: the portal in a frame.
async fn customer_portal(State(core): State<Arc<Auth>>) -> Response {
    match registry::load(&core.pool).await {
        Ok(reg) => {
            let body = "<iframe src='https://portal.harcourt.co' width='100%' frameBorder='0' style='height: 1000px;'></iframe>";
            let html = super::pages::chrome_document(&reg, "", body);
            (StatusCode::OK, [(header::CONTENT_TYPE, "text/html; charset=UTF-8"), (header::CACHE_CONTROL, "no-store, no-cache, must-revalidate")], html).into_response()
        }
        Err(e) => {
            tracing::error!("customer_portal: {e}");
            (StatusCode::INTERNAL_SERVER_ERROR, "Internal error").into_response()
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::{catalog::viewer::Kind, identity::UserId};

    #[test]
    fn display_sizes_follow_file_class() {
        assert_eq!(display_size((800, 600), 0, 0), (800, 600));
        assert_eq!(display_size((800, 600), 400, 0), (400, 300));
        assert_eq!(display_size((800, 600), 0, 150), (200, 150));
        assert_eq!(display_size((800, 600), 1600, 0), (800, 600), "never enlarged");
        assert_eq!(display_size((800, 600), 100, 100), (100, 100));
    }

    #[test]
    fn content_follows_the_visibility_of_what_owns_it() {
        let anon = Viewer::anonymous(Lang::En);
        let customer = Viewer { kind: Kind::Account(UserId(1)), active: true, catalog: true, ..Viewer::anonymous(Lang::En) };
        let family = |status| Owner::Family { status, category_active: true, category_id: 3 };
        assert!(may_fetch(&anon, Owner::Public));
        assert!(!may_fetch(&anon, family(Status::Active)));
        assert!(may_fetch(&customer, family(Status::Active)));
        assert!(!may_fetch(&customer, family(Status::Pending)));
        assert!(!may_fetch(&customer, Owner::Family { status: Status::Active, category_active: false, category_id: 3 }));
        let mut blocked = customer.clone();
        blocked.blocked.by_user = vec![3];
        assert!(!may_fetch(&blocked, family(Status::Active)));
        assert!(!may_fetch(&customer, Owner::Portal(2)));
        assert!(may_fetch(&Viewer { portal_id: Some(2), ..customer.clone() }, Owner::Portal(2)));
        assert!(!may_fetch(&customer, Owner::Unknown));
    }
}
