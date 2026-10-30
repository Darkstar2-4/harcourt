//! portal_preview.php: how a customer portal reads in a language. An Administrator picks a portal and a language
//! and sees its logo, headline and category summary as portaloutput_class drew them; a category's link asks by AJAX
//! for its detail (a subcategory's or category's names, or a portal category's items).
//!
//! The Super User "Debug?" box printed the SQL the portal ran; there is no such text here, so it prints nothing.

use std::{collections::HashMap, sync::Arc};

use axum::{
    body::Bytes,
    extract::{RawQuery, State},
    http::{HeaderMap, StatusCode},
    response::Response,
    routing::get,
    Router,
};
use serde_json::Value;
use sqlx::SqlitePool;

use super::{
    choices,
    csrf::Fields,
    dashboard::content_key,
    html::{href, js, open, submit, text, url_query, Blank, Form, Notes, Opts},
    shell, Staff,
};
use crate::{
    api::{Administrator, Auth},
    identity::Role,
};

const PATH: &str = "/portal_preview.php";
const TITLE: &str = "Portal Preview";
const VERSION: &str = "11/18/2025";

const CSS_JS: &str = "
<style>
.preview_content {
    border-top: 1px solid black;
    margin-top: 5px;
    margin-bottom: 5px;
}
.preview_content:empty  {
    border: none;
    margin: 0px;
}
</style>

<script>
function fn_preview(action, category_id, subcategory_id) {
\tjQuery.ajax({
        type: \"post\",
\t    dataType: \"json\",
\t\tdata: { action: action, category_id: category_id, subcategory_id: subcategory_id, portal_id: jQuery(\"#portal_id\").val(), language_code: jQuery(\"#language_code\").val() },
\t    success: function(response) { scscpq_fill(response) }
    });
}
</script>


";

/// A row of `content` as portaloutput_class kept it.
#[derive(Clone)]
struct Content {
    id: i64,
    record_id: i64,
    name: String,
    description: String,
    filetype: String,
    content: String,
    html_content: String,
}

struct Category {
    name: String,
    subcategory_id: i64,
    image_id: i64,
    count: i64,
}

struct Item {
    name: String,
    subcategory_id: i64,
    links: [(i64, &'static str); 4],
    other_name: String,
}

/// What portaloutput_class::load() gathered.
struct Portal {
    name: String,
    header: Content,
    images: Vec<Content>,
    /// The portal's categories in output order with what each lists (subcategories or items).
    output: Vec<(i64, Vec<i64>)>,
    categories: HashMap<i64, Category>,
    /// A subcategory-backed category's subcategory: its category and thumbnail.
    inventory: HashMap<i64, (i64, i64, String)>,
    items: HashMap<i64, Item>,
    /// portalcontent rows by id, drawn.
    drawn: HashMap<i64, String>,
    portalcontent: HashMap<i64, Content>,
    /// The categories that list items rather than a subcategory (`category_list`).
    item_categories: usize,
    viewer_last_login: i64,
}

/// content(): an image through scs_file.php, HTML in a viewer frame, anything else as stored.
fn draw(c: &Content, last_login: i64) -> String {
    let key = content_key(c.id, last_login);
    match c.filetype.as_str() {
        "image" => format!("<img src='/scs_file.php{}'>", url_query(&[("content_id", &key)])),
        "html" => iframe(&format!("viewer{}", c.id), &format!("/content_viewer.php{}", url_query(&[("viewer", &key)]))),
        _ => c.content.clone(),
    }
}

/// forms_class::iframe() with its default resizing.
fn iframe(field: &str, src: &str) -> String {
    format!(
        "<iframe name='{field}' id='{field}' style='height: 0px; scrolling: no; width: 100%; border-width: 0px; padding: 0px;' src='{src}' onload=\"javascript:this.height=(this.contentWindow.document.body.scrollHeight + 30);\">IFrame support required to view content</iframe>\n"
    )
}

/// The first row of each record in EN or the language, the language's preferred; with `whole`, only rows about the
/// record itself (no `record_item`).
async fn contents(pool: &SqlitePool, record_type: &str, ids: &[i64], language: &str, whole: bool) -> sqlx::Result<HashMap<i64, Content>> {
    let mut out = HashMap::new();
    for id in ids {
        let row = sqlx::query!(
            r#"SELECT id AS "id!: i64", record_id AS "record_id!: i64", name AS "name!: String", COALESCE(description, '') AS "description!: String",
                      filetype AS "filetype!: String", content, COALESCE(html_content, '') AS "html_content!: String"
               FROM content WHERE record_type = ?1 AND record_id = ?2 AND language_code IN ('EN', ?3) AND (?4 = 0 OR record_item = '')
               ORDER BY language_code = 'EN', id LIMIT 1"#,
            record_type,
            id,
            language,
            whole
        )
        .fetch_optional(pool)
        .await?;
        if let Some(r) = row {
            let content = String::from_utf8_lossy(r.content.as_deref().unwrap_or_default()).into_owned();
            out.insert(*id, Content { id: r.id, record_id: r.record_id, name: r.name, description: r.description, filetype: r.filetype, content, html_content: r.html_content });
        }
    }
    Ok(out)
}

/// portaloutput_class::load(): the portal or why it cannot be shown.
async fn load(pool: &SqlitePool, portal_id: i64, language: &str, viewer: Role, viewer_last_login: i64) -> sqlx::Result<Result<Portal, &'static str>> {
    let Some(portal) = sqlx::query!(r#"SELECT name, COALESCE(active, 0) AS "active!: i64" FROM portal WHERE id = ?1"#, portal_id).fetch_optional(pool).await? else {
        return Ok(Err("Missing or inactive portal"));
    };
    if portal.active == 0 {
        return Ok(Err("Missing or inactive portal"));
    }
    let Some(header) = contents(pool, "portal", &[portal_id], language, false).await?.remove(&portal_id) else { return Ok(Err("Missing portal name")) };
    let image_ids = sqlx::query_scalar!(r#"SELECT id AS "id!: i64" FROM portalcontent WHERE portal_id = ?1 AND form = 'Portal Image' AND active = 1"#, portal_id).fetch_all(pool).await?;
    let mut images: Vec<Content> = contents(pool, "portalcontent", &image_ids, language, false).await?.into_values().collect();
    images.sort_by(|a, b| a.name.cmp(&b.name).then(a.record_id.cmp(&b.record_id)));
    let rows = sqlx::query!(
        r#"SELECT id AS "id!: i64", COALESCE(name, '') AS "name!: String", COALESCE(headline_portalcontent_id, 0) AS "headline!: i64",
                  COALESCE(image_portalcontent_id, 0) AS "image!: i64", COALESCE(subcategory_id, 0) AS "subcategory!: i64"
           FROM portalcategory WHERE portal_id = ?1 AND active = 1 ORDER BY output_order, name"#,
        portal_id
    )
    .fetch_all(pool)
    .await?;
    let mut content_list: Vec<i64> = Vec::new();
    let add = |list: &mut Vec<i64>, id: i64| {
        if id != 0 && !list.contains(&id) {
            list.push(id);
        }
    };
    let mut output: Vec<(i64, Vec<i64>)> = Vec::new();
    let mut categories = HashMap::new();
    let (mut by_subcategory, mut category_list): (HashMap<i64, i64>, Vec<i64>) = (HashMap::new(), Vec::new());
    for r in &rows {
        output.push((r.id, Vec::new()));
        categories.insert(r.id, Category { name: r.name.clone(), subcategory_id: r.subcategory, image_id: r.image, count: 0 });
        add(&mut content_list, r.headline);
        add(&mut content_list, r.image);
        if r.subcategory != 0 {
            by_subcategory.insert(r.subcategory, r.id);
        } else {
            category_list.push(r.id);
        }
    }
    let mut inventory = HashMap::new();
    for (subcategory, category) in &by_subcategory {
        let pending = viewer >= Role::Administrator;
        let sub = sqlx::query!(
            r#"SELECT s.id AS "id!: i64", COALESCE(s.category_id, 0) AS "category_id!: i64", COALESCE(s.thumbnail_url, '') AS "thumbnail!: String"
               FROM subcategory s JOIN inventory i ON i.subcategory_id = s.id LEFT JOIN category c ON c.id = s.category_id
               WHERE s.id = ?1 AND i.active = 1 AND c.active = 1 AND (s.status = 'Active' OR (?2 AND s.status = 'Pending')) LIMIT 1"#,
            subcategory,
            pending
        )
        .fetch_optional(pool)
        .await?;
        if let Some(s) = sub {
            if let Some(c) = categories.get_mut(category) {
                c.count = 1;
            }
            if let Some((_, list)) = output.iter_mut().find(|(id, _)| id == category) {
                list.push(s.id);
            }
            inventory.insert(*category, (s.category_id, s.id, s.thumbnail));
        }
    }
    let mut items = HashMap::new();
    let mut item_list = Vec::new();
    if !category_list.is_empty() {
        let rows = sqlx::query!(
            r#"SELECT id AS "id!: i64", COALESCE(portalcategory_id, 0) AS "category!: i64", COALESCE(name, '') AS "name!: String",
                      COALESCE(pdf_portalcontent_id, 0) AS "pdf!: i64", COALESCE(cad_portalcontent_id, 0) AS "cad!: i64",
                      COALESCE(hyperlink_portalcontent_id, 0) AS "hyperlink!: i64", COALESCE(other_portalcontent_id, 0) AS "other!: i64",
                      COALESCE(other_name, '') AS "other_name!: String", COALESCE(subcategory_id, 0) AS "subcategory!: i64"
               FROM portalitem WHERE portal_id = ?1 AND active = 1 ORDER BY output_order, name"#,
            portal_id
        )
        .fetch_all(pool)
        .await?;
        for r in rows.into_iter().filter(|r| category_list.contains(&r.category)) {
            if let Some(c) = categories.get_mut(&r.category) {
                c.count += 1;
            }
            if let Some((_, list)) = output.iter_mut().find(|(id, _)| *id == r.category) {
                list.push(r.id);
            }
            for id in [r.pdf, r.cad, r.hyperlink, r.other] {
                add(&mut content_list, id);
            }
            item_list.push(r.id);
            items.insert(r.id, Item { name: r.name, subcategory_id: r.subcategory, links: [(r.pdf, "PDF"), (r.cad, "CAD"), (r.hyperlink, ""), (r.other, "")], other_name: r.other_name });
        }
    }
    categories.retain(|_, c| c.count > 0);
    if category_list.is_empty() && item_list.is_empty() && content_list.is_empty() {
        return Ok(Err("No portal content"));
    }
    for (id, c) in contents(pool, "portalcategory", &category_list, language, false).await? {
        if let Some(cat) = categories.get_mut(&id) {
            cat.name = c.name;
        }
    }
    for (id, c) in contents(pool, "portalitem", &item_list, language, false).await? {
        if let Some(item) = items.get_mut(&id) {
            item.name = c.name;
        }
    }
    let portalcontent = contents(pool, "portalcontent", &content_list, language, false).await?;
    let drawn = portalcontent.iter().map(|(id, c)| (*id, draw(c, viewer_last_login))).collect();
    Ok(Ok(Portal {
        name: portal.name,
        header,
        images,
        output,
        categories,
        inventory,
        items,
        drawn,
        portalcontent,
        item_categories: category_list.len(),
        viewer_last_login,
    }))
}

impl Portal {
    /// image(array("class"=>"left")).
    fn image(&self) -> String {
        if self.images.is_empty() {
            return String::new();
        }
        let inner: String = self.images.iter().map(|c| format!("<div class=left>{}</div>", draw(c, self.viewer_last_login))).collect();
        format!("<div>{inner}</div>")
    }

    fn headline(&self) -> String {
        if self.header.description.is_empty() {
            return String::new();
        }
        format!("<div class=portal_headline>{}</div>", self.header.description)
    }

    /// summary(): each category with a picture, linked to its detail.
    fn summary(&self) -> String {
        if self.item_categories == 0 {
            return String::new();
        }
        let mut out = String::from("<div><h1>Custom Products</h1></div><div class=catalog_subcategory_list><ul>");
        for (id, _) in &self.output {
            let Some(c) = self.categories.get(id) else { continue };
            let (picture, call) = match self.inventory.get(id) {
                Some((category_id, sub_id, thumbnail)) if c.subcategory_id != 0 => {
                    let Some(img) = crate::catalog::forms::img(thumbnail, &[]) else { continue };
                    (img, format!("javascript:fn_preview({},{},{});", js("subcategory"), js(&category_id.to_string()), js(&sub_id.to_string())))
                }
                None if c.subcategory_id == 0 && c.image_id != 0 => {
                    (self.drawn.get(&c.image_id).cloned().unwrap_or_default(), format!("javascript:fn_preview({},{},{});", js("portal"), js(""), js(&id.to_string())))
                }
                _ => continue,
            };
            out.push_str(&format!("<li>{}</li>", href(&format!("{picture}<br>{}", c.name), &call, Opts::new())));
        }
        out.push_str("</ul></div>");
        out
    }

    /// portal_content(): a link to one of an item's files.
    fn link(&self, id: i64, label: &str) -> String {
        let Some(c) = self.portalcontent.get(&id) else { return format!("Content Missing: {id}") };
        let key = content_key(c.id, self.viewer_last_login);
        match c.filetype.as_str() {
            "" | "image" => String::new(),
            "hyperlink" => href(&c.name, &c.html_content, Opts::new().set("target", "_blank")),
            "html" => iframe(&format!("{}_{id}", c.filetype), &format!("/scs_file.php{}", url_query(&[("content_id", &key)]))),
            _ => href(label, &format!("/scs_file.php{}", url_query(&[("content_id", &key)])), Opts::new().set("target", "_blank")),
        }
    }

    /// detail(): a portal category's heading and its items with their files.
    fn detail(&self, category_id: i64) -> String {
        let Some(list) = self.output.iter().find(|(id, _)| *id == category_id).map(|(_, l)| l).filter(|l| !l.is_empty()) else { return String::new() };
        let c = self.categories.get(&category_id);
        let mut out = String::from("<div>");
        if let Some(image) = c.filter(|c| c.image_id != 0).and_then(|c| self.drawn.get(&c.image_id)) {
            out.push_str(&format!("<span class=portal_detail_headline>{image}</span>"));
        }
        out.push_str(&format!("<span class=portal_detail_headline>{}</span></div><div class='portal_detail'>", c.map_or("", |c| c.name.as_str())));
        for id in list {
            let Some(item) = self.items.get(id).filter(|i| i.subcategory_id == 0) else { continue };
            let links: Vec<String> = item
                .links
                .iter()
                .enumerate()
                .filter(|(_, (cid, _))| *cid != 0)
                .map(|(i, (cid, label))| self.link(*cid, if i == 3 { item.other_name.as_str() } else { label }))
                .collect();
            out.push_str(&format!("<div class='portal_detail'>{}: {}</div>", item.name, links.join(" | ")));
        }
        out.push_str("</div>");
        out
    }
}

async fn viewer_last_login(pool: &SqlitePool, staff: &Staff<Administrator>) -> sqlx::Result<i64> {
    Ok(sqlx::query_scalar!(r#"SELECT last_login FROM "user" WHERE id = ?1"#, staff.who.user.id.0).fetch_optional(pool).await?.unwrap_or(0))
}

/// language->select() in the languages' own names.
async fn languages(pool: &SqlitePool) -> sqlx::Result<Vec<(String, String)>> {
    let rows = sqlx::query!(r#"SELECT code, COALESCE(local_name, '') AS "local!: String", country_code FROM language ORDER BY priority, local_name"#).fetch_all(pool).await?;
    Ok(rows.into_iter().map(|r| (r.code, text(&if r.country_code.is_empty() { r.local } else { format!("{} ({})", r.local, r.country_code) }))).collect())
}

async fn page(pool: &SqlitePool, staff: &Staff<Administrator>, f: &Fields) -> sqlx::Result<Response> {
    let mut notes = Notes::new(TITLE, VERSION);
    let (action, portal_id, language) = (f.get("action"), f.int("portal_id"), f.get("language_code"));
    let debug = f.int("debug") != 0;
    let shown = if action.is_empty() {
        None
    } else {
        match load(pool, portal_id, language, staff.who.user.role, viewer_last_login(pool, staff).await?).await? {
            Ok(p) => Some(p),
            Err(e) => {
                notes.error("portal_id", Some(e));
                None
            }
        }
    };
    let form = Form { notes: &notes };
    let mut body = notes.render();
    body.push_str(CSS_JS);
    body.push_str(&open(PATH, staff.csrf.as_str(), false));
    body.push_str(&super::html::hidden("action", "load"));
    let select = form.select("portal_id", &choices::portals(pool).await?, f.get("portal_id"), Blank::Empty, Opts::new());
    let select = match notes.error_text("portal_id") {
        Some(t) => format!("{select} {t}"),
        None => select,
    };
    body.push_str(&format!("<table class='noborder'><tr><td width='20%'>Portal</td><td width='80%'>{select}</td></tr>"));
    body.push_str(&format!("<tr><td>Language</td><td>{}</td></tr>", form.select("language_code", &languages(pool).await?, language, Blank::None, Opts::new())));
    if staff.who.user.role >= Role::SuperUser {
        body.push_str(&format!("<tr><td>Debug?</td><td>{}</td></tr>", form.checkbox("debug", "1", debug, Opts::new())));
    }
    body.push_str("</table>");
    body.push_str(&format!("<p>{}</p>", submit("Go", Opts::new())));
    if let Some(p) = &shown {
        body.push_str(&format!("<div class=preview_content><div><b>Logo/Image:</b> ({})</div>{}</div>", p.images.len(), p.image()));
        let none = if p.header.description.is_empty() { "None" } else { "" };
        body.push_str(&format!("<div class=preview_content><b>Headline:</b> {none}{}</div>", p.headline()));
        body.push_str(&format!("<div class=preview_content><b>Summary:</b> ({}){}</div><div id=preview_detail class=preview_content></div>", p.item_categories, p.summary()));
        if debug {
            body.push_str("<div class=preview_content><b>Queries</b></div>");
        }
    }
    body.push_str(super::html::close());
    let stamp = crate::accounts::registry::get(pool, "local", "filmstrip_timestamp").await?.unwrap_or_default();
    let css = [format!("/scs_scripts/catalog.css?v={stamp}")];
    Ok(shell::document(pool, staff.view(), &shell::Head { title: TITLE, description: None, css: &css, dashboard: None }, &body).await)
}

/// One category's or subcategory's name and description, or "Not on file".
async fn names(pool: &SqlitePool, record_type: &str, id: i64, language: &str, label: &str, name: Option<String>) -> sqlx::Result<String> {
    let mut out = format!("<p><b>{label}: {} (#{id})</b></p>", name.unwrap_or_default());
    match contents(pool, record_type, &[id], language, true).await?.remove(&id) {
        Some(c) => {
            out.push_str(&format!("<div>{}</div>", c.name));
            if !c.description.is_empty() {
                out.push_str(&format!("<div>{}</div>", c.description));
            }
        }
        None => out.push_str("<div>Not on file"),
    }
    Ok(out)
}

async fn ajax(pool: &SqlitePool, staff: &Staff<Administrator>, f: &Fields) -> sqlx::Result<Response> {
    let language = f.get("language_code");
    let detail = match f.get("action") {
        "subcategory" => {
            let (category_id, subcategory_id) = (f.int("category_id"), f.int("subcategory_id"));
            let category = sqlx::query_scalar!("SELECT name FROM category WHERE id = ?1", category_id).fetch_optional(pool).await?.flatten();
            let subcategory = sqlx::query_scalar!("SELECT name FROM subcategory WHERE id = ?1", subcategory_id).fetch_optional(pool).await?.flatten();
            let mut out = names(pool, "category", category_id, language, "Category", category).await?;
            out.push_str(&names(pool, "subcategory", subcategory_id, language, "Subcategory", subcategory).await?);
            out
        }
        "portal" => {
            let (portal_id, category_id) = (f.int("portal_id"), f.int("subcategory_id"));
            match load(pool, portal_id, language, staff.who.user.role, viewer_last_login(pool, staff).await?).await? {
                Ok(p) => {
                    let content = p.detail(category_id);
                    let category = p.categories.get(&category_id).map_or("", |c| c.name.as_str());
                    let head = format!("<p><b>Portal: {} (#{portal_id})</b><br><b>Portal Category: {category} (#{category_id})</b></p>", p.name);
                    format!("{head}{}", if content.is_empty() { "<p><b>No items found</b></p>".to_string() } else { content })
                }
                Err(_) => format!("<p><b>Portal:  (#{portal_id})</b><br><b>Portal Category:  (#{category_id})</b></p><p><b>No items found</b></p>"),
            }
        }
        other => format!("Method not found: json_{other}"),
    };
    let json = Value::Object([("preview_detail".to_string(), Value::from(detail))].into_iter().collect()).to_string();
    Ok(shell::html_response(StatusCode::OK, crate::orders::shop::cart::php_json(&json)))
}

/// The page shows and previews; nothing it posts writes, so it takes no form token.
async fn handle(auth: &Auth, staff: &Staff<Administrator>, headers: &HeaderMap, f: Fields) -> Response {
    let answer = if headers.contains_key("x-requested-with") { ajax(&auth.pool, staff, &f).await } else { page(&auth.pool, staff, &f).await };
    answer.unwrap_or_else(shell::internal)
}

async fn show(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, headers: HeaderMap, RawQuery(_q): RawQuery) -> Response {
    handle(&auth, &staff, &headers, Fields::default()).await
}

async fn post(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, headers: HeaderMap, body: Bytes) -> Response {
    handle(&auth, &staff, &headers, Fields::from_query(std::str::from_utf8(&body).ok())).await
}

pub fn routes() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(show).post(post))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn frames_resize_to_their_content() {
        assert_eq!(
            iframe("viewer7", "/content_viewer.php?viewer=x"),
            "<iframe name='viewer7' id='viewer7' style='height: 0px; scrolling: no; width: 100%; border-width: 0px; padding: 0px;' src='/content_viewer.php?viewer=x' onload=\"javascript:this.height=(this.contentWindow.document.body.scrollHeight + 30);\">IFrame support required to view content</iframe>\n"
        );
    }
}
