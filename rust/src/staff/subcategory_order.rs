//! subcategory_order.php: put a category's subcategories in order by dragging them, and save the order as sort
//! orders 5, 10, 15, … in the order posted.

use std::sync::Arc;

use axum::{extract::State, response::Response, routing::get, Router};
use sqlx::SqlitePool;

use super::{
    choices,
    csrf::{Fields, Posted},
    html::{button, close, hidden, number, open, text, Blank, Form, Notes, Opts},
    shell, Staff,
};
use crate::api::{Administrator, Auth};

const PATH: &str = "/subcategory_order.php";
const TITLE: &str = "Subcategory Order";

const SCRIPT: &str = "<script type='text/javascript'>
function fn_action(action) {
\tif (action=='cancel') {
    \tif (!confirm('Cancel update?')) return;
\t}
\tdocument.scs_form.action.value=action;
\tdocument.scs_form.submit();
}
</script>
";

async fn run(pool: &SqlitePool, staff: &Staff<Administrator>, f: &Fields) -> sqlx::Result<Response> {
    let mut notes = Notes::new(TITLE, "");
    let action = f.get("action");
    let category_id = f.get("report_category_id");
    let category = sqlx::query_scalar!(r#"SELECT COALESCE(name, '') AS "name!: String" FROM category WHERE id = ?1"#, category_id).fetch_optional(pool).await?;
    let mut order: Option<Vec<(i64, String)>> = None;
    match (action, &category) {
        ("", _) => {}
        (_, None) => notes.error("report_category_id", None),
        ("cancel", _) => notes.say("Request cancelled"),
        ("maintain", Some(_)) => {
            let rows = sqlx::query!(r#"SELECT id AS "id!: i64", name FROM subcategory WHERE category_id = ?1 ORDER BY sort_order, name COLLATE NOCASE"#, category_id)
                .fetch_all(pool)
                .await?;
            if rows.is_empty() {
                notes.error("report_category_id", None);
                notes.say("No subcategories found");
            } else {
                order = Some(rows.into_iter().map(|r| (r.id, r.name.unwrap_or_default())).collect());
            }
        }
        ("update", Some(name)) => {
            let mut count: i64 = 0;
            let mut tx = pool.begin().await?;
            for (_, id) in f.iter().filter(|(k, _)| k.contains("subcategory_")) {
                count += 1;
                let sort_order = count * 5;
                sqlx::query!("UPDATE subcategory SET sort_order = ?1 WHERE id = ?2 AND category_id = ?3", sort_order, id, category_id).execute(&mut *tx).await?;
            }
            tx.commit().await?;
            notes.say(&format!("{} records updated in category {name}", number(count)));
        }
        _ => {}
    }
    let form = Form { notes: &notes };
    let mut body = notes.render();
    body.push_str(SCRIPT);
    body.push_str(&open(PATH, staff.csrf.as_str(), false));
    body.push_str(&hidden("action", ""));
    body.push_str(&hidden("record_id", ""));
    match (order, category) {
        (Some(rows), Some(name)) => {
            body.push_str(&hidden("report_category_id", category_id));
            body.push_str(&format!("<p>Category: <b>{}</b><br>Click and drag to desired order</p>\n", text(&name)));
            let items: Vec<String> = rows.iter().map(|(id, name)| format!("<li class='ui-state-default'>{}{}</li>", hidden(&format!("subcategory_{id}"), &id.to_string()), text(name))).collect();
            body.push_str(&format!("<ol class='sortable large'>\n{}\n</ol>\n", items.join("\n")));
            let update = button("Update", Opts::new().onclick("fn_action('update');"));
            let cancel = button("Cancel", Opts::new().onclick("fn_action('cancel');"));
            body.push_str(&format!("<p>{update} {cancel}</p>"));
        }
        _ => {
            let select = form.select("report_category_id", &choices::categories(pool).await?, category_id, Blank::Empty, Opts::new().onchange("fn_action('maintain');"));
            body.push_str(&format!("<p>Category: {select} {}</p>", button("Go", Opts::new().onclick("fn_action('maintain');"))));
        }
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

pub fn routes() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(show).post(post))
}
