//! industry.php: the industries and the catalog subcategories each one sees (`subcategory.industry`, a `^id^id^`
//! list). Executives and above read it; Administrators add, change and delete (only an inactive industry offers
//! delete).

use std::sync::Arc;

use axum::{extract::State, response::Response, routing::get, Router};
use sqlx::SqlitePool;

use crate::{
    api::{Auth, Executive},
    identity::Role,
    staff::{
        csrf::{php_intval, Fields, Posted},
        html::{button, close, hidden, href, js, open, text, Form, Notes, Opts, Tabs},
        shell, Staff,
    },
};

const PATH: &str = "/industry.php";
const TITLE: &str = "Industry";

#[derive(Default)]
struct Industry {
    id: i64,
    name: String,
    active: bool,
}

async fn read(pool: &SqlitePool, id: i64) -> sqlx::Result<Option<Industry>> {
    let r = sqlx::query!(r#"SELECT id AS "id!: i64", name, active FROM industry WHERE id = ?1"#, id).fetch_optional(pool).await?;
    Ok(r.map(|r| Industry { id: r.id, name: r.name, active: r.active != 0 }))
}

/// The subcategories whose `industry` list names the industry.
async fn members(pool: &SqlitePool, id: i64) -> sqlx::Result<Vec<i64>> {
    let pattern = format!("%^{id}^%");
    sqlx::query_scalar!(r#"SELECT id AS "id!: i64" FROM subcategory WHERE industry LIKE ?1"#, pattern).fetch_all(pool).await
}

fn name_tab(form: &Form<'_>, i: &Industry) -> Vec<String> {
    vec![
        "<table class='standard noborder'>".to_string(),
        "<tr>".into(),
        "<td width='30%'>Name</td>".into(),
        format!("<td width='70%'>{}</td>", form.text("name", &i.name, 60, 60, Opts::new())),
        "</tr>".into(),
        "<tr>".into(),
        "<td>Active?</td>".into(),
        format!("<td>{}</td>", form.checkbox("active", "1", i.active, Opts::new())),
        "</tr>".into(),
        "</table>".into(),
    ]
}

/// A category of the subcategory tab: id, name and its (id, name) subcategories.
type Group = (i64, String, Vec<(i64, String)>);

/// subcategory_tab(): every visible subcategory by category, ticked when the industry has it (all of them for a
/// new industry).
async fn subcategory_tab(pool: &SqlitePool, form: &Form<'_>, ticked: &(dyn Fn(i64) -> bool + Sync)) -> sqlx::Result<Vec<String>> {
    let rows = sqlx::query!(
        r#"SELECT s.id AS "id!: i64", COALESCE(s.name, '') AS "name!: String", s.category_id AS "category_id!: i64", c.name AS "category!: String"
           FROM subcategory s LEFT JOIN category c ON c.id = s.category_id
           WHERE c.active = 1 AND s.status <> 'Inactive' AND c.name IS NOT NULL
           ORDER BY c.sort_order, c.name, s.sort_order, s.name"#
    )
    .fetch_all(pool)
    .await?;
    let mut groups: Vec<Group> = Vec::new();
    for r in rows {
        match groups.iter_mut().find(|(id, _, _)| *id == r.category_id) {
            Some((_, _, items)) => items.push((r.id, r.name)),
            None => groups.push((r.category_id, r.category, vec![(r.id, r.name)])),
        }
    }
    let mut out = vec![
        "<table class='standard border'>".to_string(),
        format!("<tr><th style='text-align: left;'>{} All subcategories</th></tr>", form.checkbox("all", "1", false, Opts::new().onclick("fn_subcategory(this);"))),
        "<tr><td>&nbsp;</td></tr>".into(),
    ];
    for (id, name, items) in groups {
        let all = items.iter().all(|(s, _)| ticked(*s));
        let group = form.checkbox(&format!("category_{id}"), "1", all, Opts::new().onclick("fn_subcategory(this);").class("subcategory"));
        out.push(format!("<tr><th style='text-align: left;'>{group} {name}</th></tr>"));
        let ticks: Vec<String> = items
            .iter()
            .map(|(s, label)| {
                let opts = Opts::new().onclick("fn_subcategory(this);").class(format!("subcategory category_{id}"));
                format!("{} {label}", form.checkbox(&format!("subcategory_{id}_{s}"), &s.to_string(), ticked(*s), opts))
            })
            .collect();
        out.push(format!("<tr><td><div class=newspaper2>{}</div></td></tr>", ticks.join("<br>\n")));
    }
    out.push("</table>".into());
    Ok(out)
}

/// The industry, and its `^id^` mark moved onto exactly the given subcategories. None when the name is taken.
async fn save(pool: &SqlitePool, i: &Industry, subcategories: &[i64]) -> sqlx::Result<Option<i64>> {
    let mut tx = pool.begin().await?;
    let taken = sqlx::query_scalar!(r#"SELECT id AS "id!: i64" FROM industry WHERE name = ?1 COLLATE NOCASE AND id <> ?2"#, i.name, i.id).fetch_optional(&mut *tx).await?;
    if taken.is_some() {
        return Ok(None);
    }
    let active = i64::from(i.active);
    let id = if i.id == 0 {
        sqlx::query_scalar!(r#"INSERT INTO industry (name, active) VALUES (?1, ?2) RETURNING id AS "id!: i64""#, i.name, active).fetch_one(&mut *tx).await?
    } else {
        sqlx::query!("UPDATE industry SET name = ?2, active = ?3 WHERE id = ?1", i.id, i.name, active).execute(&mut *tx).await?;
        i.id
    };
    let (mark, pattern, append) = (format!("^{id}^"), format!("%^{id}^%"), format!("{id}^"));
    sqlx::query!("UPDATE subcategory SET industry = replace(industry, ?1, '^') WHERE industry LIKE ?2", mark, pattern).execute(&mut *tx).await?;
    for s in subcategories {
        sqlx::query!(
            "UPDATE subcategory SET industry = CASE WHEN length(industry) > 2 THEN industry || ?1 ELSE ?2 END WHERE id = ?3",
            append,
            mark,
            s
        )
        .execute(&mut *tx)
        .await?;
    }
    tx.commit().await?;
    Ok(Some(id))
}

async fn delete(pool: &SqlitePool, id: i64) -> sqlx::Result<()> {
    let mut tx = pool.begin().await?;
    let (mark, pattern) = (format!("^{id}^"), format!("%^{id}^%"));
    sqlx::query!("UPDATE subcategory SET industry = replace(industry, ?1, '^') WHERE industry LIKE ?2", mark, pattern).execute(&mut *tx).await?;
    sqlx::query!("DELETE FROM industry WHERE id = ?1", id).execute(&mut *tx).await?;
    tx.commit().await
}

async fn list(pool: &SqlitePool, form: &Form<'_>, admin: bool) -> sqlx::Result<String> {
    let rows = sqlx::query!(r#"SELECT id AS "id!: i64", name, active FROM industry ORDER BY name"#).fetch_all(pool).await?;
    let mut out = vec!["<table class='standard border'>".to_string(), "<caption>&nbsp</caption>".into(), "<tr>".into(), "<th>Name</th>".into()];
    if admin {
        out.push("<th width='10%'>Del?</th>".into());
    }
    out.push("</tr>".into());
    if admin {
        out.extend(["<tr>".to_string(), format!("<td colspan=2>{}</td>", href("Add new", "javascript:fn_action(this,'maintain','','');", Opts::new())), "</tr>".into()]);
    }
    for r in rows {
        let background = if r.active != 0 { "" } else { "style='background-color: #ffff00;'" };
        out.push(format!("<tr {background}>"));
        out.push(format!("<td>{}</td>", href(&text(&r.name), &format!("javascript:fn_action(this,'maintain',{},'');", js(&r.id.to_string())), Opts::new())));
        if admin {
            let del = if r.active != 0 {
                "&nbsp;".to_string()
            } else {
                form.radio("delete", "1", false, Opts::new().onclick(format!("fn_action(this,'delete',{},{});", js(&r.id.to_string()), js(&r.name))))
            };
            out.push(format!("<td class=center>{del}</td>"));
        }
        out.push("</tr>".into());
    }
    out.push("</table>".into());
    Ok(out.join("\n") + "\n")
}

const SCRIPT: &str = "<script type='text/javascript'>
var category=[];
var subcategory=[];
function fn_action(obj,action,id,name) {
\tif (action=='delete') {
\t    if (!confirm(\"Delete: \" + name)) {
\t        obj.checked=false;
            return;
\t    }
    }
\tdocument.scs_form.action.value=action;
\tdocument.scs_form.record_id.value=id;
\tdocument.scs_form.submit();
}
function fn_subcategory(obj) {
\tfield=obj.id.split(\"_\");
    switch (field[0]) {
\t  case \"all\":
\t\t$('input.subcategory').prop('checked',obj.checked);
        break;
      case \"category\":
\t\t$('input.' + obj.id).prop('checked',obj.checked);
        break;
      case \"subcategory\":
// reserved
        break;
\t}
}
$(document).ready(function() {
// reserved
});
function indeterminate_checkbox(field) {
/* this works!!!
\t$('#' + field).prop('indeterminate', true);
*/
}
</script>
";

async fn run(pool: &SqlitePool, staff: &Staff<Executive>, f: &Fields) -> sqlx::Result<Response> {
    let admin = staff.who.user.role >= Role::Administrator;
    let mut notes = Notes::new(TITLE, "");
    let (action, record_id) = (f.get("action"), f.get("record_id"));
    let mut industry = match php_intval(record_id) {
        0 => Industry::default(),
        id => read(pool, id).await?.unwrap_or_default(),
    };
    let mut chosen: Option<Vec<i64>> = None;
    let mut edit = false;
    match action {
        "cancel" => notes.say("Request cancelled"),
        "maintain" => edit = true,
        "delete" if admin => {
            delete(pool, industry.id).await?;
            notes.say(&format!("Industry {} deleted", industry.name));
        }
        "update" if admin => {
            industry.name = crate::staff::check::posted_text(f.get("name"));
            if industry.name.is_empty() {
                notes.error("name", None);
            }
            industry.active = f.checked("active");
            let picked: Vec<i64> = f.iter().filter(|(k, _)| k.split('_').next() == Some("subcategory")).map(|(_, v)| php_intval(v)).collect();
            if picked.is_empty() {
                notes.error("all", Some("No subcategories selected"));
            }
            if !notes.has_errors() {
                match save(pool, &industry, &picked).await? {
                    Some(_) => notes.say("Record maintained"),
                    None => notes.error("name", Some(&format!("Duplicate entry '{}' for key 'name'", industry.name))),
                }
            }
            edit = notes.has_errors();
            chosen = Some(picked);
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
        let members = if industry.id != 0 { members(pool, industry.id).await? } else { Vec::new() };
        let new = php_intval(record_id) == 0;
        let ticked = |s: i64| match &chosen {
            Some(c) => c.contains(&s),
            None => new || members.contains(&s),
        };
        let mut tabs = Tabs::new();
        tabs.in_wordpress(staff.wp);
        tabs.lines("Name", &name_tab(&form, &industry));
        tabs.lines("Subcategory", &subcategory_tab(pool, &form, &ticked).await?);
        body.push_str(&tabs.render());
        let mut buttons = Vec::new();
        if admin {
            buttons.push(button("Update", Opts::new().onclick(format!("fn_action(this,'update',{},'');", js(record_id)))));
        }
        buttons.push(button("Cancel", Opts::new().onclick("fn_action(this,'cancel','','');")));
        body.push_str(&format!("<p>{}</p>\n", buttons.join(" ")));
    } else {
        body.push_str(&list(pool, &form, admin).await?);
    }
    body.push_str(close());
    Ok(shell::page(pool, staff.view(), TITLE, &body).await)
}

async fn show(State(auth): State<Arc<Auth>>, staff: Staff<Executive>) -> Response {
    run(&auth.pool, &staff, &Fields::default()).await.unwrap_or_else(shell::internal)
}

async fn post(State(auth): State<Arc<Auth>>, staff: Staff<Executive>, posted: Posted) -> Response {
    run(&auth.pool, &staff, &posted.fields).await.unwrap_or_else(shell::internal)
}

pub fn router() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(show).post(post))
}
