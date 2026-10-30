//! portalitem.php: the Portal Item Manager. Each portal's items under their categories, with a name per language,
//! and either a catalog subcategory or links to portal content (PDF, CAD, hyperlink, other).
//!
//! As in portalcategory.rs, a save reads the stored names instead of the PHP session. A new item belongs to the
//! portal its "Add New" link was under; the PHP took the portal of the record being edited, which for a new item
//! is none, so its form came up without a portal.

use std::sync::Arc;

use axum::{extract::State, response::Response, routing::get, Router};
use sqlx::SqlitePool;

use super::{
    catalog,
    csrf::{php_intval, Fields, Posted},
    dashboard::content_key,
    html::{button, close, hidden, href, js, list_options, open, text, url_query, Blank, Form, Notes, Opts, Tabs},
    inventory::languages,
    portalcategory::{field, portal_content},
    shell, Staff,
};
use crate::api::{Administrator, Auth};

const PATH: &str = "/portalitem.php";
const TITLE: &str = "Portal Item Manager";

#[derive(Default)]
struct Item {
    id: i64,
    portal_id: i64,
    name: String,
    portalcategory_id: i64,
    output_order: i64,
    pdf: i64,
    cad: i64,
    hyperlink: i64,
    other: i64,
    other_name: String,
    subcategory_id: i64,
    active: bool,
}

async fn read(pool: &SqlitePool, id: i64) -> sqlx::Result<Option<Item>> {
    let r = sqlx::query!(
        r#"SELECT id AS "id!: i64", portal_id, name, portalcategory_id, output_order, pdf_portalcontent_id, cad_portalcontent_id,
                  hyperlink_portalcontent_id, other_portalcontent_id, other_name, subcategory_id, active FROM portalitem WHERE id = ?1"#,
        id
    )
    .fetch_optional(pool)
    .await?;
    Ok(r.map(|r| Item {
        id: r.id,
        portal_id: r.portal_id.unwrap_or(0),
        name: r.name.unwrap_or_default(),
        portalcategory_id: r.portalcategory_id.unwrap_or(0),
        output_order: r.output_order.unwrap_or(0),
        pdf: r.pdf_portalcontent_id.unwrap_or(0),
        cad: r.cad_portalcontent_id.unwrap_or(0),
        hyperlink: r.hyperlink_portalcontent_id.unwrap_or(0),
        other: r.other_portalcontent_id.unwrap_or(0),
        other_name: r.other_name.unwrap_or_default(),
        subcategory_id: r.subcategory_id.unwrap_or(0),
        active: r.active.unwrap_or(0) != 0,
    }))
}

/// A language's item name and the `content` row it is kept in.
#[derive(Default, Clone)]
struct Name {
    content_id: i64,
    language: String,
    name: String,
}

async fn names(pool: &SqlitePool, id: i64, langs: &[(String, String)]) -> sqlx::Result<Vec<Name>> {
    let rows = sqlx::query!(r#"SELECT id AS "id!: i64", language_code, name FROM content WHERE record_type = 'portalitem' AND record_id = ?1 ORDER BY id"#, id)
        .fetch_all(pool)
        .await?;
    Ok(langs
        .iter()
        .map(|(code, _)| rows.iter().find(|r| r.language_code == *code).map_or_else(|| Name { language: code.clone(), ..Name::default() }, |r| Name { content_id: r.id, language: code.clone(), name: r.name.clone() }))
        .collect())
}

/// portalcategory->select() for items: the portal's active categories that are not a catalog subcategory.
async fn categories(pool: &SqlitePool, portal_id: i64) -> sqlx::Result<Vec<(String, String)>> {
    let rows = sqlx::query!(
        r#"SELECT id AS "id!: i64", name FROM portalcategory WHERE active = 1 AND portal_id = ?1 AND subcategory_id = 0 ORDER BY output_order, name COLLATE NOCASE"#,
        portal_id
    )
    .fetch_all(pool)
    .await?;
    Ok(rows.into_iter().map(|r| (r.id.to_string(), text(&r.name.unwrap_or_default()))).collect())
}

const SUBCATEGORY_SCRIPT: &str = "<script type='text/javascript'>
function fn_subcategory() {
\tif ($('#subcategory_id').val()) {
\t\t$('#pdf_row').hide();
\t\t$('#cad_row').hide();
\t\t$('#other_row').hide();
\t  } else {
\t\t$('#pdf_row').show();
\t\t$('#cad_row').show();
\t\t$('#other_row').show();
    }
}
$(document).ready(function() {
\tfn_subcategory();
});
</script>
";

async fn language_tab(pool: &SqlitePool, form: &Form<'_>, item: &Item, n: &Name, portal_name: &str) -> sqlx::Result<Vec<String>> {
    let mut out = vec!["<table class='standard noborder'>".to_string()];
    out.extend(["<tr>".to_string(), "<td width='20%'>Portal name</td>".into(), format!("<td width='80%'><b>{}</b></td>", text(portal_name)), "</tr>".into()]);
    out.extend(["<tr>".to_string(), "<td>Name</td>".into(), format!("<td>{}</td>", form.text(&field("name", &n.language), &n.name, 40, 40, Opts::new())), "</tr>".into()]);
    if n.language == "EN" {
        let p = item.portal_id;
        let category = form.select("portalcategory_id", &categories(pool, p).await?, &item.portalcategory_id.to_string(), Blank::Empty, Opts::new());
        out.extend(["<tr>".to_string(), "<td width='20%'>Category</td>".into(), format!("<td width='80%'>{category}</td>"), "</tr>".into()]);
        let subcategory = form.optgroup("subcategory_id", &catalog::groups(pool).await?, &item.subcategory_id.to_string(), true, Opts::new().onchange("fn_subcategory();"));
        out.extend(["<tr>".to_string(), "<td>Subcategory</td>".into(), format!("<td>{subcategory}</td>"), "</tr>".into()]);
        let links = [("pdf_row", "PDF", "pdf_portalcontent_id", "Link", item.pdf), ("cad_row", "CAD", "cad_portalcontent_id", "Link", item.cad), ("", "Hyperlink", "hyperlink_portalcontent_id", "Hyperlink", item.hyperlink)];
        for (row_id, label, name, kind, selected) in links {
            let open_row = if row_id.is_empty() { "<tr>".to_string() } else { format!("<tr id={row_id}>") };
            out.extend([open_row, format!("<td>{label}</td>"), format!("<td>{}</td>", portal_content(pool, form, name, p, kind, selected).await?), "</tr>".into()]);
        }
        out.extend([
            "<tr id=other_row>".to_string(),
            format!("<td>Other: {}</td>", form.text("other_name", &item.other_name, 15, 30, Opts::new())),
            format!("<td>{}</td>", portal_content(pool, form, "other_portalcontent_id", p, "Link", item.other).await?),
            "</tr>".into(),
        ]);
        let orders: Vec<String> = (0..=99).map(|n: i64| n.to_string()).collect();
        out.extend(["<tr>".to_string(), "<td>Output order</td>".into(), format!("<td>{}</td>", form.select("output_order", &list_options(&orders), &item.output_order.to_string(), Blank::None, Opts::new())), "</tr>".into()]);
        out.extend(["<tr>".to_string(), "<td>Active?</td>".into(), format!("<td>{}</td>", form.checkbox("active", "1", item.active, Opts::new())), "</tr>".into()]);
    }
    out.push("</table>".into());
    Ok(out)
}

async fn save(pool: &SqlitePool, item: &Item, names: &[Name]) -> sqlx::Result<()> {
    let mut tx = pool.begin().await?;
    let id = if item.id == 0 {
        sqlx::query_scalar!(r#"INSERT INTO portalitem (name) VALUES (?1) RETURNING id AS "id!: i64""#, item.name).fetch_one(&mut *tx).await?
    } else {
        item.id
    };
    let active = i64::from(item.active);
    sqlx::query!(
        "UPDATE portalitem SET portal_id = ?2, portalcategory_id = ?3, name = ?4, output_order = ?5, pdf_portalcontent_id = ?6, cad_portalcontent_id = ?7,
                hyperlink_portalcontent_id = ?8, other_portalcontent_id = ?9, other_name = ?10, subcategory_id = ?11, active = ?12 WHERE id = ?1",
        id, item.portal_id, item.portalcategory_id, item.name, item.output_order, item.pdf, item.cad, item.hyperlink, item.other, item.other_name,
        item.subcategory_id, active
    )
    .execute(&mut *tx)
    .await?;
    for n in names {
        match (n.name.is_empty(), n.content_id) {
            (true, 0) => {}
            (true, existing) => {
                sqlx::query!("DELETE FROM content WHERE id = ?1", existing).execute(&mut *tx).await?;
            }
            (false, 0) => {
                sqlx::query!("INSERT INTO content (record_type, record_id, language_code, name) VALUES ('portalitem', ?1, ?2, ?3)", id, n.language, n.name).execute(&mut *tx).await?;
            }
            (false, existing) => {
                sqlx::query!("UPDATE content SET record_id = ?2, name = ?3 WHERE id = ?1", existing, id, n.name).execute(&mut *tx).await?;
            }
        }
    }
    tx.commit().await
}

/// portalitem_view(): a link to the content behind a portal content id, keyed for the viewer.
async fn content_link(pool: &SqlitePool, portalcontent_id: i64, language: &str, last_login: i64) -> sqlx::Result<String> {
    let row = sqlx::query!(
        r#"SELECT id AS "id!: i64", name FROM content WHERE record_type = 'portalcontent' AND record_id = ?1 AND language_code IN ('EN', ?2)
           ORDER BY CASE WHEN language_code = 'EN' THEN 2 ELSE 1 END LIMIT 1"#,
        portalcontent_id,
        language
    )
    .fetch_optional(pool)
    .await?;
    Ok(match row {
        Some(r) => href(&text(&r.name), &format!("/scs_file.php{}", url_query(&[("content_id", &content_key(r.id, last_login))])), Opts::new().set("target", "portal_preview")),
        None => "&nbsp;".into(),
    })
}

async fn list(pool: &SqlitePool, form: &Form<'_>, staff: &Staff<Administrator>) -> sqlx::Result<String> {
    let viewer = sqlx::query!(r#"SELECT last_login, language_code FROM "user" WHERE id = ?1"#, staff.who.user.id.0).fetch_optional(pool).await?;
    let (last_login, language) = viewer.map_or((0, "EN".to_string()), |v| (v.last_login, v.language_code));
    let items = sqlx::query!(
        r#"SELECT pi.id AS "id!: i64", pi.portal_id, pi.name, pi.pdf_portalcontent_id AS pdf, pi.cad_portalcontent_id AS cad,
                  pi.other_portalcontent_id AS other, pi.subcategory_id, pi.active,
                  pc.name AS "category?: String", s.name AS "subcategory?: String"
           FROM portalitem pi LEFT JOIN portalcategory pc ON pc.id = pi.portalcategory_id LEFT JOIN subcategory s ON s.id = pi.subcategory_id
           ORDER BY pi.output_order, pi.name COLLATE NOCASE"#
    )
    .fetch_all(pool)
    .await?;
    let portals = sqlx::query!(r#"SELECT id AS "id!: i64", name FROM portal ORDER BY name COLLATE NOCASE"#).fetch_all(pool).await?;
    let mut out = String::from("<div class='accordion'>\n");
    for p in portals {
        let pid = js(&p.id.to_string());
        out.push_str(&format!("<h3>{}</h3>\n<div>\n<table class='standard border'>\n<tr>\n<th>Item Name</th>\n<th>PDF</th>\n<th>CAD</th>\n<th>Subcategory</th>\n<th>Other</th>\n<th>Del?</th>\n</tr>\n", text(&p.name)));
        out.push_str(&format!("<tr><td colspan=6>{}</td></tr>\n", href("Add New", &format!("javascript:fn_action(this,'maintain','0',{pid});"), Opts::new())));
        let mine: Vec<_> = items.iter().filter(|i| i.portal_id == Some(p.id)).collect();
        let mut groups: Vec<&str> = Vec::new();
        for i in &mine {
            let g = i.category.as_deref().unwrap_or("");
            if !groups.contains(&g) {
                groups.push(g);
            }
        }
        for g in groups {
            out.push_str(&format!("<tr><td colspan=6><b>{}</b></td></tr>\n", text(g)));
            for i in mine.iter().filter(|i| i.category.as_deref().unwrap_or("") == g) {
                let (pdf, cad, other, subcategory) = (i.pdf.unwrap_or(0), i.cad.unwrap_or(0), i.other.unwrap_or(0), i.subcategory_id.unwrap_or(0));
                let active = i.active.unwrap_or(0) != 0;
                let bg = match () {
                    _ if !active => "style='background-color: #ffff00;'",
                    _ if pdf != 0 || cad != 0 || subcategory != 0 || other != 0 => "",
                    _ => "style='background-color: #ff0000;'",
                };
                let name = i.name.clone().unwrap_or_default();
                let id = js(&i.id.to_string());
                let del = if active { "&nbsp;".to_string() } else { form.radio("delete", &i.id.to_string(), false, Opts::new().onclick(format!("fn_action(this,'delete',{id},{});", js(&name)))) };
                out.push_str(&format!(
                    "<tr {bg}>\n<td>{}</td>\n<td>{}</td>\n<td>{}</td>\n<td>{}</td>\n<td>{}</td>\n<td class=center>{del}</td>\n</tr>\n",
                    href(&text(&name), &format!("javascript:fn_action(this,'maintain',{id},{pid});"), Opts::new()),
                    content_link(pool, pdf, &language, last_login).await?,
                    content_link(pool, cad, &language, last_login).await?,
                    text(i.subcategory.as_deref().unwrap_or("")),
                    content_link(pool, other, &language, last_login).await?,
                ));
            }
        }
        out.push_str("</table>\n</div>");
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
    let mut item = match php_intval(record_id) {
        0 => None,
        id => read(pool, id).await?,
    }
    .unwrap_or_else(|| Item { portal_id: f.int("report_portal_id"), active: true, ..Item::default() });
    let report_portal = item.portal_id;
    let langs = languages(pool).await?;
    let mut shown = names(pool, item.id, &langs).await?;
    let mut edit = false;
    match action {
        "cancel" => notes.say("Request cancelled"),
        "delete" => {
            sqlx::query!("DELETE FROM portalitem WHERE id = ?1", item.id).execute(pool).await?;
            notes.say(&format!("Portal Item {} deleted", item.name));
        }
        "maintain" => edit = true,
        "update" => {
            for n in &mut shown {
                let name_field = field("name", &n.language);
                n.name = f.get(&name_field).trim().to_string();
                if n.language == "EN" {
                    item.name = n.name.clone();
                    if item.name.is_empty() {
                        notes.error(&name_field, None);
                    }
                }
            }
            item.portal_id = f.int("report_portal_id");
            item.portalcategory_id = f.int("portalcategory_id");
            if item.portalcategory_id == 0 {
                notes.error("portalcategory_id", None);
            }
            item.output_order = f.int("output_order");
            item.subcategory_id = f.int("subcategory_id");
            item.active = f.checked("active");
            if item.subcategory_id != 0 {
                (item.pdf, item.cad, item.hyperlink, item.other, item.other_name) = (0, 0, 0, 0, String::new());
            } else {
                (item.pdf, item.cad, item.hyperlink, item.other) = (f.int("pdf_portalcontent_id"), f.int("cad_portalcontent_id"), f.int("hyperlink_portalcontent_id"), f.int("other_portalcontent_id"));
                item.other_name = f.get("other_name").trim().to_string();
                if item.other != 0 && item.other_name.is_empty() {
                    notes.error("other_name", None);
                }
            }
            if notes.has_errors() {
                edit = true;
            } else {
                save(pool, &item, &shown).await?;
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
    body.push_str(&hidden("report_portal_id", &report_portal.to_string()));
    if edit {
        let portal_name = sqlx::query_scalar!(r#"SELECT name AS "name!: String" FROM portal WHERE id = ?1"#, item.portal_id).fetch_optional(pool).await?.unwrap_or_default();
        if langs.iter().any(|(code, _)| code == "EN") {
            body.push_str(SUBCATEGORY_SCRIPT);
        }
        let mut tabs = Tabs::new();
        tabs.in_wordpress(staff.wp);
        for ((_, language), n) in langs.iter().zip(&shown) {
            tabs.lines(language, &language_tab(pool, &form, &item, n, &portal_name).await?);
        }
        body.push_str(&tabs.render());
        let update = button("Update", Opts::new().onclick(format!("fn_action(this,'update',{},{});", js(record_id), js(&report_portal.to_string()))));
        let cancel = button("Cancel", Opts::new().onclick("fn_action(this,'cancel','','');"));
        body.push_str(&format!("<p class='standard'>\n{update}{cancel}</p>\n"));
    } else {
        body.push_str(&list(pool, &form, staff).await?);
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
