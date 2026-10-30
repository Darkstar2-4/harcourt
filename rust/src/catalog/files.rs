//! Files the catalog links to and the bare SCS pages around them. scs_file.php serves a `content` row (an overview
//! image, a datasheet) by its encoded id; scs_index.php and beta.php are the SCS home, a loader the catalog script
//! fills; customer_portal.php frames the customer portal.

use std::{collections::HashMap, sync::Arc};

use axum::{
    extract::{Query, State},
    http::{header, HeaderValue, StatusCode},
    response::{IntoResponse, Response},
    routing::get,
    Router,
};
use sqlx::SqlitePool;

use super::{
    html,
    model::Status,
    viewer::{self, Lang, Viewer},
};
use crate::{
    api::Auth,
    identity::Role,
    orders::html::{self as chrome, Chrome, Head},
    visitor::{Visitor, PHP_CONTENT_TYPE},
};

pub fn router() -> Router<Arc<Auth>> {
    Router::new()
        .route("/scs_file.php", get(scs_file))
        .route("/customer_portal.php", get(customer_portal).post(customer_portal))
        .route("/scs_index.php", get(scs_index).post(scs_index))
        .route("/beta.php", get(scs_index).post(scs_index))
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
        Owner::Portal(id) => viewer.may_browse() && (viewer.portal_id == Some(id) || viewer.at_least(Role::Internal)),
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

fn php_page(status: StatusCode, body: String) -> Response {
    (status, [(header::CONTENT_TYPE, PHP_CONTENT_TYPE), (header::CACHE_CONTROL, "no-store, no-cache, must-revalidate")], body).into_response()
}

/// What scs_file.php died with when the id did not decode to content this visitor may fetch. The id is echoed, as
/// the PHP did, but escaped.
fn missing(encoded: &str) -> Response {
    php_page(StatusCode::OK, format!("Content missing: {}", html::esc(encoded)))
}

/// An image, resized when a smaller width or height is asked for, else the stored bytes.
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
        if let Ok(v) = HeaderValue::from_bytes(format!("attachment; filename={name}").as_bytes()) {
            headers.insert(header::CONTENT_DISPOSITION, v);
        }
    }
    resp
}

/// A download a family page's link asked scs_file.php to log (`local_log`: base64 of subtype, file name, part
/// number and family id, tab-separated). The staff dashboard's lead score counts these rows. Only the Download
/// subtype the links carry is written.
#[derive(Debug, PartialEq, Eq)]
struct Download {
    file: String,
    sku: String,
    family_id: i64,
}

impl Download {
    fn parse(encoded: &str) -> Option<Download> {
        let mut fields = html::base64_fields_decode(encoded)?.into_iter();
        if !fields.next()?.trim().eq_ignore_ascii_case("download") {
            return None;
        }
        let mut next = || fields.next().unwrap_or_default();
        Some(Download { file: next(), sku: next(), family_id: super::php::Value::Str(next()).int() })
    }

    async fn record(&self, pool: &SqlitePool, visitor: &Visitor) -> sqlx::Result<()> {
        let ip = visitor.client.map(|ip| ip.to_string()).unwrap_or_default();
        let (now, user_id, bot_id) = (crate::db::unix_now(), visitor.user().map_or(0, |u| u.id.0), visitor.bot().map_or(0, |b| b.id));
        let email = visitor.signed_in().and_then(|s| s.email.as_ref()).map(|e| e.as_str());
        sqlx::query!(
            "INSERT INTO log (ip, date_time, user_id, bot_id, email, type, subtype, sku, subcategory_id, comment) VALUES (?1, ?2, ?3, ?4, ?5, 'User', 'Download', ?6, ?7, ?8)",
            ip,
            now,
            user_id,
            bot_id,
            email,
            self.sku,
            self.family_id,
            self.file
        )
        .execute(pool)
        .await?;
        Ok(())
    }
}

/// scs_file.php. Only content by id is served. Without one the PHP never got past loading its file class (500),
/// except with `watermark`, where a missing file died empty.
async fn scs_file(State(core): State<Arc<Auth>>, visitor: Visitor, Query(q): Query<HashMap<String, String>>) -> Response {
    let Some(encoded) = q.get("content_id") else {
        let status = if q.contains_key("watermark") { StatusCode::OK } else { StatusCode::INTERNAL_SERVER_ERROR };
        return php_page(status, String::new());
    };
    let id = html::base64_fields_decode(encoded).and_then(|f| f.first().map(|s| super::php::Value::Str(s.clone()).int())).unwrap_or(0);
    let result = async {
        let viewer = viewer::load(&core.pool, &visitor, Lang::En).await?;
        Ok::<_, sqlx::Error>(content(&core.pool, id).await?.filter(|c| may_fetch(&viewer, c.owner)))
    }
    .await;
    let found = match result {
        Ok(Some(c)) => c,
        Ok(None) => return missing(encoded),
        Err(e) => {
            tracing::error!("scs_file: {e}");
            return (StatusCode::INTERNAL_SERVER_ERROR, "Internal error").into_response();
        }
    };
    if let Some(log) = q.get("local_log").and_then(|l| Download::parse(l)) {
        if let Err(e) = log.record(&core.pool, &visitor).await {
            tracing::warn!("scs_file: download log: {e}");
        }
    }
    if found.bytes.is_empty() {
        return php_page(StatusCode::OK, String::new());
    }
    let text = |k: &str| found.meta.get(k).and_then(|v| v.as_str()).unwrap_or("").to_string();
    let (mime, name) = (text("mime"), text("name"));
    let override_ = q.get("override").map_or("", String::as_str);
    let is_image = found.meta.get("type").is_some_and(|t| t.as_i64().unwrap_or(0) != 0 || t.as_str().is_some_and(|s| !s.is_empty() && s != "0"));
    if is_image {
        let (width, height) = (param_u32(&q, "width"), param_u32(&q, "height"));
        return image_response(found.bytes, &mime, &name, width, height, !override_.is_empty() && override_ != "0");
    }
    let inline = mime == "text/plain" || mime == "text/html";
    let asked_inline = super::php::Value::Str(override_.to_string()).int() != 0;
    file_response(found.bytes, if mime.is_empty() { "application/octet-stream" } else { &mime }, &name, asked_inline == inline)
}

/// The script scs_index.php and beta.php print: a spinner until the catalog script replaces #scscpq_page.
const LOADER: &str = r#"<script>
content=new Array();
content.push("<style>");
content.push(".scscpq_loader {display: inline-block; border: 10px solid #f3f3f3; border-radius: 50%; border-top: 10px solid #DC2536; width: 40px; height: 40px; -webkit-animation: spin 2s linear infinite; animation: spin 2s linear infinite; }");
content.push(".scscpq_loader_text { display: inline-block; vertical-align: top; margin-left: 10px; margin-top: 18px; }");
content.push("@-webkit-keyframes spin { 0% { -webkit-transform: rotate(0deg); } 100% { -webkit-transform: rotate(360deg); } }");
content.push("@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }");
content.push("</style>");
content.push("<div style='margin-top: 50px;text-align: center;'><div class=scscpq_loader></div><div class=scscpq_loader_text>Loading ...</div></div>");
jQuery(document).ready(function() {
	jQuery('#scscpq_page').html(content.join('\n'));
});
</script>
<div id=scscpq_page></div>"#;

/// An SCS page: scs_header.php's landing redirect when the request begins a visit, else `body` in the SCS chrome.
async fn scs_page(core: &Auth, visitor: &Visitor, title: &str, description: Option<&str>, body: &str) -> Response {
    let result = async {
        let chrome_data = Chrome::load(&core.pool).await?;
        let head = Head {
            title,
            description,
            js: &[],
            framed: visitor.framed,
            dashboard: visitor.user().is_some_and(|u| u.role >= Role::Executive),
            catalog_url: "/catalog.php",
        };
        Ok::<_, sqlx::Error>(php_page(StatusCode::OK, format!("{}{body}{}", chrome::open(&chrome_data, &head), chrome::close())))
    }
    .await;
    result.unwrap_or_else(|e| {
        tracing::error!("scs page {title:?}: {e}");
        (StatusCode::INTERNAL_SERVER_ERROR, "Internal error").into_response()
    })
}

/// scs_index.php and beta.php. Both set the description to an undefined variable, which moves the default after
/// the registry's meta lines.
async fn scs_index(State(core): State<Arc<Auth>>, visitor: Visitor) -> Response {
    scs_page(&core, &visitor, "Harcourt", Some(""), LOADER).await
}

/// customer_portal.php: the portal in a frame, in an untitled SCS page.
async fn customer_portal(State(core): State<Arc<Auth>>, visitor: Visitor) -> Response {
    let body = "<iframe src='https://portal.harcourt.co' width='100%' frameBorder='0' style='height: 1000px;'></iframe>";
    scs_page(&core, &visitor, "", None, body).await
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::{catalog::viewer::Kind, identity::UserId};

    #[test]
    fn only_download_logs_are_read() {
        let enc = |s: &str| html::base64_fields(&[s]);
        assert_eq!(Download::parse(&enc("download\tsheet.pdf\tLS-1\t416")), Some(Download { file: "sheet.pdf".into(), sku: "LS-1".into(), family_id: 416 }));
        assert_eq!(Download::parse(&format!("{}==", enc("Download"))), Some(Download { file: String::new(), sku: String::new(), family_id: 0 }));
        assert_eq!(Download::parse(&enc("login\tx\ty\t1")), None);
        assert_eq!(Download::parse("%%%"), None);
    }

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
        let customer = Viewer { kind: Kind::Account(UserId(1)), status: crate::identity::Status::Active, catalog: true, ..Viewer::anonymous(Lang::En) };
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
        assert!(may_fetch(&Viewer { role: Role::Internal, ..customer.clone() }, Owner::Portal(2)), "the dashboard shows portal thumbnails to staff");
        assert!(!may_fetch(&customer, Owner::Unknown));
    }
}
