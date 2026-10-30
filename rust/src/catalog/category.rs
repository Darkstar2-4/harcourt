//! category.php: the Category Manager, for Administrators. Each category has a name and HTML text per language
//! (`content` rows of type `category`), a sort order and an active flag; deleting one (only an inactive one offers
//! it) removes its content and its subcategories, as the PHP did.
//!
//! The PHP held the per-language text in the session between the form and the save; a save here reads the stored
//! rows and applies what was posted.

use std::sync::Arc;

use axum::{extract::State, response::Response, routing::get, Router};
use base64::{engine::general_purpose::STANDARD, Engine};
use sqlx::SqlitePool;

use crate::{
    api::{Administrator, Auth},
    staff::{
        csrf::{php_intval, Fields, Posted},
        html::{button, close, hidden, href, js, list_options, open, Blank, Form, Notes, Opts, Tabs},
        shell, Staff,
    },
};

const PATH: &str = "/category.php";
const TITLE: &str = "Category Manager";

#[derive(Default)]
struct Category {
    id: i64,
    name: String,
    sort_order: i64,
    active: bool,
}

/// One language's name and text, and the `content` row they are stored in.
#[derive(Default, Clone)]
struct Text {
    content_id: i64,
    language: String,
    name: String,
    description: String,
}

fn field(part: &str, language: &str) -> String {
    STANDARD.encode(format!("{part}\t{language}"))
}

async fn read(pool: &SqlitePool, id: i64) -> sqlx::Result<Option<Category>> {
    let r = sqlx::query!(r#"SELECT id AS "id!: i64", name, sort_order, active FROM category WHERE id = ?1"#, id).fetch_optional(pool).await?;
    Ok(r.map(|r| Category { id: r.id, name: r.name.unwrap_or_default(), sort_order: r.sort_order, active: r.active.unwrap_or(0) != 0 }))
}

async fn texts(pool: &SqlitePool, id: i64) -> sqlx::Result<Vec<Text>> {
    let rows = sqlx::query!(
        r#"SELECT id AS "id!: i64", language_code, name, description FROM content WHERE record_type = 'category' AND record_id = ?1 ORDER BY id"#,
        id
    )
    .fetch_all(pool)
    .await?;
    Ok(rows.into_iter().map(|r| Text { content_id: r.id, language: r.language_code, name: r.name, description: r.description.unwrap_or_default() }).collect())
}

/// $menu->language: the active languages, code and name.
async fn languages(pool: &SqlitePool) -> sqlx::Result<Vec<(String, String)>> {
    let rows = sqlx::query!("SELECT code, name FROM language WHERE active = 1 ORDER BY priority, name COLLATE NOCASE").fetch_all(pool).await?;
    Ok(rows.into_iter().map(|r| (r.code, r.name)).collect())
}

/// document_tab(): the language's name and text, and for English the sort order and the active flag.
fn language_tab(form: &Form<'_>, notes: &Notes, c: &Category, t: &Text) -> Vec<String> {
    let (name_field, description_field) = (field("name", &t.language), field("description", &t.language));
    let error = |f: &str| notes.error_text(f).filter(|e| !e.is_empty()).map(|e| format!("<br>{e}")).unwrap_or_default();
    let mut out = vec![
        "<table class='large noborder'>".to_string(),
        "<tr>".into(),
        "<td width=30%>Name</td>".into(),
        format!("<td width=70%>{}{}", form.text(&name_field, &t.name, 60, 60, Opts::new()), error(&name_field)),
        "</td>".into(),
        "</tr>".into(),
        "<tr>".into(),
        "<td>HTML Text</td>".into(),
        format!("<td>{}{}", form.textarea(&description_field, &t.description, 6, 60, Opts::new()), error(&description_field)),
        "</td>".into(),
        "</tr>".into(),
    ];
    if t.language == "EN" {
        let orders: Vec<String> = (0..=99).map(|n: i64| n.to_string()).collect();
        out.extend([
            "<tr>".to_string(),
            "<td>Sort Order</td>".into(),
            format!("<td>{}</td>", form.select("sort_order", &list_options(&orders), &c.sort_order.to_string(), Blank::None, Opts::new())),
            "</tr>".into(),
            "<tr>".into(),
            "<td>Active?</td>".into(),
            format!("<td>{}</td>", form.checkbox("active", "1", c.active, Opts::new())),
            "</tr>".into(),
        ]);
    }
    out.push("</table>".into());
    out
}

/// The category and its texts: a text with a name is written, one emptied is deleted. None when the name is
/// already another category's.
async fn save(pool: &SqlitePool, c: &Category, texts: &[Text]) -> sqlx::Result<Option<i64>> {
    let mut tx = pool.begin().await?;
    let taken = sqlx::query_scalar!(r#"SELECT id AS "id!: i64" FROM category WHERE name = ?1 COLLATE NOCASE AND id <> ?2"#, c.name, c.id).fetch_optional(&mut *tx).await?;
    if taken.is_some() {
        return Ok(None);
    }
    let active = i64::from(c.active);
    let id = if c.id == 0 {
        sqlx::query_scalar!(r#"INSERT INTO category (name, sort_order, active) VALUES (?1, ?2, ?3) RETURNING id AS "id!: i64""#, c.name, c.sort_order, active)
            .fetch_one(&mut *tx)
            .await?
    } else {
        sqlx::query!("UPDATE category SET name = ?2, sort_order = ?3, active = ?4 WHERE id = ?1", c.id, c.name, c.sort_order, active).execute(&mut *tx).await?;
        c.id
    };
    let now = crate::db::unix_now();
    for t in texts {
        match (t.name.is_empty(), t.content_id) {
            (true, 0) => {}
            (true, existing) => {
                sqlx::query!("DELETE FROM content WHERE id = ?1", existing).execute(&mut *tx).await?;
            }
            (false, 0) => {
                sqlx::query!(
                    "INSERT INTO content (record_type, record_id, language_code, name, description, revised) VALUES ('category', ?1, ?2, ?3, ?4, ?5)",
                    id,
                    t.language,
                    t.name,
                    t.description,
                    now
                )
                .execute(&mut *tx)
                .await?;
            }
            (false, existing) => {
                sqlx::query!("UPDATE content SET record_id = ?2, name = ?3, description = ?4, revised = ?5 WHERE id = ?1", existing, id, t.name, t.description, now)
                    .execute(&mut *tx)
                    .await?;
            }
        }
    }
    tx.commit().await?;
    Ok(Some(id))
}

async fn delete(pool: &SqlitePool, id: i64) -> sqlx::Result<()> {
    let mut tx = pool.begin().await?;
    sqlx::query!("DELETE FROM category WHERE id = ?1", id).execute(&mut *tx).await?;
    sqlx::query!("DELETE FROM content WHERE record_type = 'category' AND record_id = ?1", id).execute(&mut *tx).await?;
    sqlx::query!("DELETE FROM subcategory WHERE category_id = ?1", id).execute(&mut *tx).await?;
    tx.commit().await
}

async fn list(pool: &SqlitePool, form: &Form<'_>) -> sqlx::Result<String> {
    let rows = sqlx::query!(
        r#"SELECT c.id AS "id!: i64", c.name, c.sort_order, c.active, t.description AS "description?: String"
           FROM category c
           LEFT JOIN content t ON t.record_type = 'category' AND t.record_id = c.id AND t.record_item = '' AND t.language_code = 'EN' COLLATE NOCASE
           ORDER BY c.sort_order, c.name"#
    )
    .fetch_all(pool)
    .await?;
    let mut out = vec![
        "<table class='standard border'>".to_string(),
        "<caption>&nbsp;</caption>".into(),
        "<tr>".into(),
        "<th>Name</th>".into(),
        "<th>Order</th>".into(),
        "<th>Description</th>".into(),
        "<th>Del?</th>".into(),
        "</tr>".into(),
        "<tr>".into(),
        format!("<td colspan=4>{}</td>", href("Add new", "javascript:fn_action(this,'maintain','0','');", Opts::new())),
        "</tr>".into(),
    ];
    for r in rows {
        let name = r.name.unwrap_or_default();
        let id = js(&r.id.to_string());
        let del = if r.active.unwrap_or(0) != 0 {
            String::new()
        } else {
            form.radio("delete", &r.id.to_string(), false, Opts::new().onclick(format!("fn_action(this,'delete',{id},{});", js(&name))))
        };
        out.push("<tr>".into());
        out.push(format!("<td>{}</td>", href(&format!("{}. {name}", r.id), &format!("javascript:fn_action(this,'maintain',{id},'');"), Opts::new())));
        out.push(format!("<td class=center>{}</td>", r.sort_order));
        out.push(format!("<td>{}</td>", r.description.unwrap_or_default()));
        out.push(format!("<td class=center>{del}</td>"));
        out.push("</tr>".into());
    }
    out.push("</table>".into());
    Ok(out.join("\n") + "\n")
}

const SCRIPT: &str = "<script type='text/javascript'>
function fn_action(obj,action,id,name) {
\tif (action=='delete') {
\t    if (!confirm(\"Delete: #\" + id + \" \" + name)) {
\t        obj.checked=false;
            return;
\t    }
    }
\tdocument.scs_form.action.value=action;
\tdocument.scs_form.record_id.value=id;
\tdocument.scs_form.submit();
}
</script>
";

async fn run(pool: &SqlitePool, staff: &Staff<Administrator>, f: &Fields) -> sqlx::Result<Response> {
    let mut notes = Notes::new(TITLE, "");
    let (action, record_id) = (f.get("action"), f.get("record_id"));
    let mut category = match php_intval(record_id) {
        0 => Category::default(),
        id => read(pool, id).await?.unwrap_or_default(),
    };
    let langs = languages(pool).await?;
    let stored = if category.id != 0 { texts(pool, category.id).await? } else { Vec::new() };
    let mut shown: Vec<Text> =
        langs.iter().map(|(code, _)| stored.iter().find(|t| t.language == *code).cloned().unwrap_or_else(|| Text { language: code.clone(), ..Text::default() })).collect();
    let mut edit = false;
    match action {
        "cancel" => notes.say("Request cancelled"),
        "maintain" => edit = true,
        "delete" => {
            delete(pool, category.id).await?;
            notes.say(&format!("Category {} deleted", category.name));
        }
        "update" => {
            for t in &mut shown {
                let (name_field, description_field) = (field("name", &t.language), field("description", &t.language));
                t.name = f.get(&name_field).trim().to_string();
                t.description = f.get(&description_field).trim().to_string();
                if t.language == "EN" {
                    category.name = t.name.clone();
                    if category.name.is_empty() {
                        notes.error(&name_field, None);
                    }
                } else if t.name.is_empty() && !t.description.is_empty() {
                    notes.error(&name_field, Some("Name cannot be blank when description provided"));
                }
            }
            category.sort_order = f.int("sort_order");
            category.active = f.checked("active");
            if !notes.has_errors() {
                match save(pool, &category, &shown).await? {
                    Some(_) => notes.say("Record maintained"),
                    None => notes.error("name", Some(&format!("Duplicate entry '{}' for key 'name'", category.name))),
                }
            }
            edit = notes.has_errors();
        }
        _ => {}
    }
    let form = Form { notes: &notes };
    let mut body = notes.render();
    body.push_str(SCRIPT);
    body.push_str(&open(PATH, staff.csrf.as_str(), true));
    body.push_str(&hidden("action", ""));
    body.push_str(&hidden("record_id", ""));
    if edit {
        let mut tabs = Tabs::new();
        tabs.in_wordpress(staff.wp);
        for ((_, language), t) in langs.iter().zip(&shown) {
            tabs.lines(language, &language_tab(&form, &notes, &category, t));
        }
        body.push_str(&tabs.render());
        let update = button("Update", Opts::new().onclick(format!("fn_action(this,'update',{},'');", js(&category.id.to_string()))));
        let cancel = button("Cancel", Opts::new().onclick("fn_action(this,'cancel','','');"));
        body.push_str(&format!("<p class='standard center'>{update} {cancel}</p>\n"));
    } else {
        body.push_str(&list(pool, &form).await?);
    }
    body.push_str(close());
    Ok(shell::page(pool, staff.view(), TITLE, &body).await)
}

async fn show(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>) -> Response {
    run(&auth.pool, &staff, &Fields::default()).await.unwrap_or_else(shell::internal)
}

async fn post(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, posted: Posted) -> Response {
    run(&auth.pool, &staff, &posted.fields).await.unwrap_or_else(shell::internal)
}

pub fn router() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(show).post(post))
}
