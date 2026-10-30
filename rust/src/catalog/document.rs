//! document.php, the Document Manager, as a GET shows it to Administrators: every document under its parent, with
//! links to view and download each language's content. Editing (its posts, with file uploads, and the working
//! copy the PHP kept in the session) still goes to PHP. A GET changes nothing: the PHP also took `action` from the
//! query, so a link could delete.

use std::{collections::BTreeMap, sync::Arc};

use axum::{extract::State, response::Response, routing::get, Router};

use crate::{
    api::{Administrator, Auth},
    catalog::html as links,
    staff::{
        html::{button, close, hidden, href, js, open, Form, Notes, Opts},
        shell, Staff,
    },
};

const PATH: &str = "/document.php";
const TITLE: &str = "Document Manager";

const SCRIPT: &str = "<script type='text/javascript'>
function fn_action(obj,action,id,parent_id,name) {
\tif (action=='delete') {
\t    if (!confirm(\"Delete: Document#\" + id + \" \" + name)) {
\t        obj.checked=false;
            return;
\t    }
    }
\tdocument.scs_form.action.value=action;
\tdocument.scs_form.record_id.value=id;
\tdocument.scs_form.record_parent_id.value=parent_id;
\tdocument.scs_form.submit();
}
function fn_ajax_select() {
\tobj=document.scs_form.ajax_document_id;
    if (!obj.value) {
\t\talert('Please select a document #');
        obj.focus();
        return;
    }
\tdocument.scs_form.action.value='ajax';
\tdocument.scs_form.submit();
}
</script>
";

struct Document {
    id: i64,
    parent_id: i64,
    name: String,
    description: String,
    active: bool,
    /// Content row per language code, English first.
    contents: Vec<(String, i64)>,
}

/// content->encode(): the content id with the viewer's sign-in time, as scs_file.php links carry it for staff.
fn encoded(id: i64, last_login: i64) -> String {
    links::base64_fields(&[&id.to_string(), &format!("{last_login:x}")])
}

async fn list(pool: &sqlx::SqlitePool, form: &Form<'_>, last_login: i64) -> sqlx::Result<String> {
    let rows = sqlx::query!(
        r#"SELECT id AS "id!: i64", COALESCE(parent_id, 0) AS "parent_id!: i64", COALESCE(name, '') AS "name!: String",
                  COALESCE(description, '') AS "description!: String", COALESCE(active, 0) AS "active!: i64"
           FROM document ORDER BY (CASE WHEN parent_id = 0 THEN '0' || name ELSE '1' || name END) COLLATE NOCASE, id"#
    )
    .fetch_all(pool)
    .await?;
    let contents = sqlx::query!(
        r#"SELECT id AS "id!: i64", record_id, language_code FROM content WHERE record_type = 'document'
           ORDER BY CASE WHEN language_code = 'EN' THEN 1 ELSE 2 END, record_id"#
    )
    .fetch_all(pool)
    .await?;
    let languages: BTreeMap<String, String> = sqlx::query!("SELECT code, name FROM language").fetch_all(pool).await?.into_iter().map(|r| (r.code, r.name)).collect();
    let mut documents: BTreeMap<i64, Document> = BTreeMap::new();
    let mut parents: Vec<(i64, Vec<i64>)> = Vec::new();
    for r in rows {
        let parent = if r.parent_id != 0 { r.parent_id } else { r.id };
        match parents.iter_mut().find(|(p, _)| *p == parent) {
            Some((_, children)) => children.push(r.id),
            None => parents.push((parent, vec![r.id])),
        }
        documents.insert(r.id, Document { id: r.id, parent_id: r.parent_id, name: r.name, description: r.description, active: r.active != 0, contents: Vec::new() });
    }
    for c in contents {
        if let Some(d) = documents.get_mut(&c.record_id) {
            match d.contents.iter_mut().find(|(code, _)| *code == c.language_code) {
                Some(slot) => slot.1 = c.id,
                None => d.contents.push((c.language_code, c.id)),
            }
        }
    }
    let mut out = format!(
        "<p class=left>Document# {} {}</p>\n",
        form.text("ajax_document_id", "", 6, 0, Opts::new()),
        button("Select Document", Opts::new().onclick("fn_ajax_select();"))
    );
    out.push_str("<div class='accordion'>\n<h3>New Parent</h3>\n");
    out.push_str(&format!("<div>{}</div>\n", href("Add new parent", "javascript:fn_action(this,'maintain','0','0','');", Opts::new())));
    for (parent, children) in &parents {
        let name = documents.get(parent).map_or("", |d| d.name.as_str());
        out.push_str(&format!("<h3>{parent}. {name} ({})</h3>\n<div>\n<table class='standard border'>\n", children.len()));
        out.push_str("<tr>\n<th>ID</th>\n<th>Name</th>\n<th>Description</th>\n<th>View</th>\n<th>Download</th>\n<th>Del?</th>\n</tr>\n");
        out.push_str(&format!("<tr>\n<td colspan=6>{}</td>\n</tr>\n", href("New child", &format!("javascript:fn_action(this,'maintain','0','{parent}','');"), Opts::new())));
        for child in children {
            let Some(d) = documents.get(child) else { continue };
            let label = if d.parent_id == 0 { format!("{} (Parent document)", d.name) } else { d.name.clone() };
            let language = |code: &str| languages.get(code).cloned().unwrap_or_default();
            let view: Vec<String> = d
                .contents
                .iter()
                .map(|(code, id)| format!("<a href=\"/content_viewer.php?viewer={}\" target='document_viewer' title='View {}'>{code}</a>", encoded(*id, last_login), language(code)))
                .collect();
            let download: Vec<String> = d
                .contents
                .iter()
                .map(|(code, id)| format!("<a href=\"/content_viewer.php?download={}\" title='Download {}'>{code}</a>", encoded(*id, last_login), language(code)))
                .collect();
            let del = if (d.parent_id == 0 && !children.is_empty()) || d.active {
                String::new()
            } else {
                form.radio("delete", &parent.to_string(), false, Opts::new().onclick(format!("fn_action(this,'delete',{},{},{});", js(&d.id.to_string()), js(&d.parent_id.to_string()), js(&d.name))))
            };
            out.push_str("<tr>\n");
            out.push_str(&format!("<td class=center>{}</td>\n", href(&child.to_string(), &format!("javascript:fn_action(this,'maintain','{child}','{parent}','');"), Opts::new())));
            out.push_str(&format!("<td>{label}</td>\n<td>{}</td>\n<td>{}</td>\n<td>{}</td>\n<td class=center>{del}</td>\n</tr>\n", d.description, view.join("\n "), download.join("\n ")));
        }
        out.push_str("</table>\n</div>");
    }
    out.push_str("</div>\n");
    Ok(out)
}

async fn page(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>) -> Response {
    let pool = &auth.pool;
    let notes = Notes::new(TITLE, "");
    let form = Form { notes: &notes };
    let listed = async {
        let last_login = sqlx::query_scalar!(r#"SELECT last_login FROM "user" WHERE id = ?1"#, staff.who.user.id.0).fetch_optional(pool).await?.unwrap_or(0);
        list(pool, &form, last_login).await
    };
    match listed.await {
        Ok(listing) => {
            let mut body = notes.render();
            body.push_str(SCRIPT);
            body.push_str(&open(PATH, staff.csrf.as_str(), true));
            body.push_str(&hidden("action", ""));
            body.push_str(&hidden("record_id", ""));
            body.push_str(&hidden("record_parent_id", "47"));
            body.push_str(&listing);
            body.push_str(close());
            shell::page(pool, staff.view(), TITLE, &body).await
        }
        Err(e) => shell::internal(e),
    }
}

pub fn router() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(page))
}
