//! portalcategory.php: the Customer Portal Category Manager. Each portal's categories, with a name and headline
//! per language (`content` rows of type `portalcategory`), a subcategory, an output order and the portal content
//! shown as the category's headline and image.
//!
//! The PHP held the per-language text in the session between the form and the save; a save here reads the stored
//! rows and applies what was posted. Deleting a category removes its own portal items; the PHP removed the items of
//! the portal whose id equalled the category's.

use std::sync::Arc;

use axum::{
    extract::State,
    response::{IntoResponse, Response},
    routing::get,
    Router,
};
use base64::{engine::general_purpose::STANDARD, Engine};
use sqlx::SqlitePool;

use super::{
    catalog,
    csrf::{php_intval, Fields, Unchecked},
    html::{button, close, hidden, href, js, list_options, open, text, Blank, Form, Notes, Opts, Tabs},
    inventory::languages,
    shell, Staff,
};
use crate::api::{Administrator, Auth};

const PATH: &str = "/portalcategory.php";
const TITLE: &str = "Customer Portal Category Manager";

#[derive(Default)]
struct Category {
    id: i64,
    portal_id: i64,
    output_order: i64,
    headline_content: i64,
    image_content: i64,
    subcategory_id: i64,
    active: bool,
}

/// One language's name and headline, and the `content` row they are stored in.
#[derive(Default, Clone)]
struct Text {
    content_id: i64,
    language: String,
    name: String,
    description: String,
}

pub(super) fn field(part: &str, language: &str) -> String {
    STANDARD.encode(format!("{part}\t{language}"))
}

async fn read(pool: &SqlitePool, id: i64) -> sqlx::Result<Option<(Category, String)>> {
    let r = sqlx::query!(
        r#"SELECT id AS "id!: i64", portal_id, name, output_order, headline_portalcontent_id, image_portalcontent_id, subcategory_id, active
           FROM portalcategory WHERE id = ?1"#,
        id
    )
    .fetch_optional(pool)
    .await?;
    Ok(r.map(|r| {
        let c = Category {
            id: r.id,
            portal_id: r.portal_id.unwrap_or(0),
            output_order: r.output_order.unwrap_or(0),
            headline_content: r.headline_portalcontent_id.unwrap_or(0),
            image_content: r.image_portalcontent_id.unwrap_or(0),
            subcategory_id: r.subcategory_id.unwrap_or(0),
            active: r.active.unwrap_or(0) != 0,
        };
        (c, r.name.unwrap_or_default())
    }))
}

async fn texts(pool: &SqlitePool, id: i64) -> sqlx::Result<Vec<Text>> {
    let rows = sqlx::query!(r#"SELECT id AS "id!: i64", language_code, name, description FROM content WHERE record_type = 'portalcategory' AND record_id = ?1 ORDER BY id"#, id)
        .fetch_all(pool)
        .await?;
    Ok(rows.into_iter().map(|r| Text { content_id: r.id, language: r.language_code, name: r.name, description: r.description.unwrap_or_default() }).collect())
}

/// portalcontent->select() for one form: the portal's own content and the generic content (portal 0).
pub(super) async fn portal_content(pool: &SqlitePool, form: &Form<'_>, field: &str, portal_id: i64, kind: &str, selected: i64) -> sqlx::Result<String> {
    let rows = sqlx::query!(r#"SELECT id AS "id!: i64", portal_id, name FROM portalcontent WHERE form = ?1 AND portal_id IN (0, ?2) ORDER BY form, name COLLATE NOCASE"#, kind, portal_id)
        .fetch_all(pool)
        .await?;
    if rows.is_empty() {
        return Ok(String::new());
    }
    let options: Vec<(String, String)> =
        rows.into_iter().map(|r| (r.id.to_string(), text(&if r.portal_id == 0 { format!("{} (Generic)", r.name) } else { r.name }))).collect();
    Ok(form.select(field, &options, &selected.to_string(), Blank::Empty, Opts::new()))
}

async fn language_tab(pool: &SqlitePool, form: &Form<'_>, c: &Category, t: &Text, portal_name: &str) -> sqlx::Result<Vec<String>> {
    let (name_field, description_field) = (field("name", &t.language), field("description", &t.language));
    let mut out = vec!["<table class='standard noborder'>".to_string()];
    let mut row = |label: &str, widget: String, first: bool| {
        let (l, w) = if first { (" width='30%'", " width='70%'") } else { ("", "") };
        out.extend(["<tr>".to_string(), format!("<td{l}>{label}</td>"), format!("<td{w}>{widget}</td>"), "</tr>".into()]);
    };
    row("Portal name", format!("<b>{}</b>", text(portal_name)), true);
    row("Category name", form.text(&name_field, &t.name, 60, 0, Opts::new()), false);
    row("Headline", form.textarea(&description_field, &t.description, 4, 60, Opts::new()), false);
    if t.language == "EN" {
        row("Subcategory", form.optgroup("subcategory_id", &catalog::groups(pool).await?, &c.subcategory_id.to_string(), true, Opts::new()), false);
        let orders: Vec<String> = (0..=99).map(|n: i64| n.to_string()).collect();
        row("Output order", form.select("output_order", &list_options(&orders), &c.output_order.to_string(), Blank::None, Opts::new()), false);
        row("Category headline", portal_content(pool, form, "headline_portalcontent_id", c.portal_id, "Category HTML", c.headline_content).await?, false);
        row("Category image", portal_content(pool, form, "image_portalcontent_id", c.portal_id, "Category Image", c.image_content).await?, false);
        row("Active?", form.checkbox("active", "1", c.active, Opts::new()), false);
    }
    out.push("</table>".into());
    Ok(out)
}

async fn save(pool: &SqlitePool, c: &Category, name: &str, headline: &str, texts: &[Text]) -> sqlx::Result<()> {
    let mut tx = pool.begin().await?;
    let active = i64::from(c.active);
    let id = if c.id == 0 {
        sqlx::query_scalar!(r#"INSERT INTO portalcategory (name) VALUES (?1) RETURNING id AS "id!: i64""#, name).fetch_one(&mut *tx).await?
    } else {
        c.id
    };
    sqlx::query!(
        "UPDATE portalcategory SET portal_id = ?2, name = ?3, output_order = ?4, headline = ?5, headline_portalcontent_id = ?6, image_portalcontent_id = ?7,
                subcategory_id = ?8, active = ?9 WHERE id = ?1",
        id, c.portal_id, name, c.output_order, headline, c.headline_content, c.image_content, c.subcategory_id, active
    )
    .execute(&mut *tx)
    .await?;
    for t in texts {
        match (t.name.is_empty(), t.content_id) {
            (true, 0) => {}
            (true, existing) => {
                sqlx::query!("DELETE FROM content WHERE id = ?1", existing).execute(&mut *tx).await?;
            }
            (false, 0) => {
                sqlx::query!(
                    "INSERT INTO content (record_type, record_id, language_code, name, description) VALUES ('portalcategory', ?1, ?2, ?3, ?4)",
                    id,
                    t.language,
                    t.name,
                    t.description
                )
                .execute(&mut *tx)
                .await?;
            }
            (false, existing) => {
                sqlx::query!("UPDATE content SET record_id = ?2, name = ?3, description = ?4 WHERE id = ?1", existing, id, t.name, t.description).execute(&mut *tx).await?;
            }
        }
    }
    tx.commit().await
}

async fn delete(pool: &SqlitePool, id: i64) -> sqlx::Result<()> {
    let mut tx = pool.begin().await?;
    sqlx::query!("DELETE FROM portalcategory WHERE id = ?1", id).execute(&mut *tx).await?;
    sqlx::query!("DELETE FROM portalitem WHERE portalcategory_id = ?1", id).execute(&mut *tx).await?;
    sqlx::query!("DELETE FROM content WHERE record_type = 'portalcategory' AND record_id = ?1", id).execute(&mut *tx).await?;
    tx.commit().await
}

async fn list(pool: &SqlitePool, form: &Form<'_>) -> sqlx::Result<String> {
    let categories = sqlx::query!(
        r#"SELECT pc.id AS "id!: i64", pc.portal_id, pc.name, pc.active, s.name AS "subcategory?: String"
           FROM portalcategory pc LEFT JOIN subcategory s ON s.id = pc.subcategory_id
           ORDER BY pc.portal_id, pc.output_order, pc.name COLLATE NOCASE"#
    )
    .fetch_all(pool)
    .await?;
    let portals = sqlx::query!(r#"SELECT id AS "id!: i64", name FROM portal ORDER BY name COLLATE NOCASE"#).fetch_all(pool).await?;
    let mut out = String::from("<div class='accordion'>\n");
    for p in portals {
        let pid = js(&p.id.to_string());
        let mut rows = vec![
            "<table class='standard border'>".to_string(),
            "<tr>".into(),
            "<th width='45%'>Category Name</th>".into(),
            "<th width='45%'>Subcategory Name</th>".into(),
            "<th width='10%'>Del?</th>".into(),
            "</tr>".into(),
            format!("<tr><td colspan=2>{}</td></tr>", href("Add New", &format!("javascript:fn_action(this,'maintain','0',{pid});"), Opts::new())),
        ];
        for c in categories.iter().filter(|c| c.portal_id == Some(p.id)) {
            let name = c.name.clone().unwrap_or_default();
            let id = js(&c.id.to_string());
            let del = if c.active.unwrap_or(0) != 0 {
                "&nbsp;".to_string()
            } else {
                form.radio("delete", &c.id.to_string(), false, Opts::new().onclick(format!("fn_action(this,'delete',{id},{});", js(&name))))
            };
            rows.push("<tr>".into());
            rows.push(format!("<td>{}</td>\n", href(&text(&name), &format!("javascript:fn_action(this,'maintain',{id},{pid});"), Opts::new())));
            rows.push(format!("<td>{}</td>\n", text(c.subcategory.as_deref().unwrap_or(""))));
            rows.push(format!("<td class=center>{del}</td>"));
            rows.push("</tr>".into());
        }
        rows.push("</table>".into());
        out.push_str(&format!("<h3>{}</h3>\n<div>{}</div>", text(&p.name), rows.join("\n")));
    }
    out.push_str("</div>\n");
    Ok(out)
}

const SCRIPT: &str = "<script type='text/javascript'>
function fn_action(obj,action,id,name) {
\tif (action == 'delete') {
\t    if (!confirm('Delete? ' + name)) {
\t        obj.checked=false;
            return;
\t    }
\t  } else {
\t\tdocument.scs_form.report_portal_id.value=name;
    }
\tdocument.scs_form.action.value=action;
\tdocument.scs_form.record_id.value=id;
\tdocument.scs_form.submit();
}
</script>
";

async fn run(pool: &SqlitePool, staff: &Staff<Administrator>, f: &Fields) -> sqlx::Result<Response> {
    let mut notes = Notes::new(TITLE, "");
    let action = f.get("action");
    let record_id = f.get("record_id");
    let report_portal = f.get("report_portal_id");
    let (mut category, stored_name) = match php_intval(record_id) {
        0 => (Category { active: true, ..Category::default() }, String::new()),
        id => read(pool, id).await?.unwrap_or_else(|| (Category { active: true, ..Category::default() }, String::new())),
    };
    let langs = languages(pool).await?;
    let stored = if category.id != 0 { texts(pool, category.id).await? } else { Vec::new() };
    let text_for = |code: &str, rows: &[Text]| rows.iter().find(|t| t.language == code).cloned().unwrap_or_else(|| Text { language: code.to_string(), ..Text::default() });
    let mut shown: Vec<Text> = langs.iter().map(|(code, _)| text_for(code, &stored)).collect();
    let mut edit = false;
    match action {
        "cancel" => notes.say("Request cancelled"),
        "delete" => {
            delete(pool, category.id).await?;
            notes.say(&format!("Portal Category {stored_name} deleted"));
        }
        "maintain" => {
            if category.id == 0 {
                category.portal_id = php_intval(report_portal);
            }
            edit = true;
        }
        "update" => {
            let (mut name, mut headline) = (String::new(), String::new());
            for t in &mut shown {
                let (name_field, description_field) = (field("name", &t.language), field("description", &t.language));
                t.name = f.get(&name_field).trim().to_string();
                t.description = f.get(&description_field).trim().to_string();
                if t.language == "EN" {
                    name = t.name.clone();
                    headline = t.description.clone();
                    if name.is_empty() {
                        notes.error(&name_field, None);
                    }
                } else if t.name.is_empty() && !t.description.is_empty() {
                    notes.error(&description_field, Some("Name required when headline populated"));
                }
            }
            category.portal_id = php_intval(report_portal);
            category.output_order = f.int("output_order");
            category.headline_content = f.int("headline_portalcontent_id");
            category.image_content = f.int("image_portalcontent_id");
            category.subcategory_id = f.int("subcategory_id");
            category.active = f.checked("active");
            if notes.has_errors() {
                edit = true;
            } else {
                save(pool, &category, &name, &headline, &shown).await?;
                notes.say("Record maintained");
            }
        }
        _ => {}
    }
    let form = Form { notes: &notes };
    let mut body = notes.render();
    body.push_str(SCRIPT);
    body.push_str(&open(PATH, staff.csrf.as_str(), true));
    body.push_str(&hidden("action", ""));
    body.push_str(&hidden("record_id", ""));
    body.push_str(&hidden("report_portal_id", report_portal));
    if edit {
        let portal_name = sqlx::query_scalar!(r#"SELECT name AS "name!: String" FROM portal WHERE id = ?1"#, report_portal).fetch_optional(pool).await?.unwrap_or_default();
        let mut tabs = Tabs::new();
        tabs.in_wordpress(staff.wp);
        for ((_, language), t) in langs.iter().zip(&shown) {
            tabs.lines(language, &language_tab(pool, &form, &category, t, &portal_name).await?);
        }
        body.push_str(&tabs.render());
        let update = button("Update", Opts::new().onclick(format!("fn_action(this,'update',{},{});", js(record_id), js(report_portal))));
        let cancel = button("Cancel", Opts::new().onclick("fn_action(this,'cancel','','');"));
        body.push_str(&format!("<p class='standard'>\n{update}{cancel}</p>\n"));
    } else {
        body.push_str(&list(pool, &form).await?);
    }
    body.push_str(close());
    Ok(shell::page(pool, staff.view(), TITLE, &body).await)
}

async fn show(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>) -> Response {
    run(&auth.pool, &staff, &Fields::default()).await.unwrap_or_else(shell::internal)
}

async fn post(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, form: Unchecked) -> Response {
    match form.accept_by_action() {
        Ok(posted) => run(&auth.pool, &staff, &posted.fields).await.unwrap_or_else(shell::internal),
        Err(rejected) => rejected.into_response(),
    }
}

pub fn routes() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(show).post(post))
}
