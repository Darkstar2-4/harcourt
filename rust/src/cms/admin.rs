//! The staff screens for page text and banners under `/staff/cms`: list the pages, edit a page's text and its SEO
//! title and description, edit each language's site-wide texts (the header notice banner, the call-to-action block,
//! footer texts), see every revision and restore one.
//!
//! Behind the staff gate like every staff page: `Staff<Administrator>` (others are sent home, as the PHP pages did)
//! and a `Posted` form whose CSRF token matches the session (staff/csrf.rs).

use std::sync::Arc;

use axum::{
    extract::{Path, State},
    http::StatusCode,
    response::{Html, IntoResponse, Redirect, Response},
    routing::{get, post},
    Router,
};

use super::{
    edit::{self, Change, EditError},
    html::esc,
    Lang, PageId,
};
use crate::{
    api::{Administrator, Auth},
    db::unix_now,
    staff::{
        csrf::{self, Posted},
        Staff,
    },
};

pub const PATH: &str = "/staff/cms";

pub fn routes() -> Router<Arc<Auth>> {
    Router::new()
        .route(PATH, get(index))
        .route("/staff/cms/page/{id}", get(page_form).post(page_save))
        .route("/staff/cms/options/{lang}", get(options_form).post(options_save))
        .route("/staff/cms/revision/{id}/restore", post(restore))
}

fn page_html(title: &str, body: &str) -> Html<String> {
    Html(format!(
        "<!DOCTYPE html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><title>{} - Site content</title>\
<style>body{{font-family:sans-serif;margin:2rem;max-width:70rem}}table{{border-collapse:collapse}}td,th{{padding:.25rem .5rem;border-bottom:1px solid #ddd;text-align:left}}\
textarea{{width:100%;font-family:monospace}}label{{display:block;margin-top:1rem;font-weight:bold}}</style></head><body>{body}</body></html>",
        esc(title)
    ))
}

fn failed(e: EditError) -> Response {
    match e {
        EditError::NotFound => (StatusCode::NOT_FOUND, "Not found").into_response(),
        EditError::NotEditable(name) => (StatusCode::UNPROCESSABLE_ENTITY, format!("{name} cannot be edited here")).into_response(),
        EditError::Db => (StatusCode::INTERNAL_SERVER_ERROR, "Internal error").into_response(),
    }
}

/// A form posting to `action` with the session's CSRF token.
fn form_open(action: &str, csrf: &str) -> String {
    format!("<form method=\"post\" action=\"{}\">{}", esc(action), crate::staff::html::hidden(csrf::FIELD, csrf))
}

async fn index(State(core): State<Arc<Auth>>, _staff: Staff<Administrator>) -> Response {
    let pages = match edit::list_pages(&core.pool).await {
        Ok(p) => p,
        Err(e) => return failed(e.into()),
    };
    let mut body = format!(
        "<h1>Site content</h1><p>Banners and footer texts: <a href=\"{PATH}/options/en\">English</a> · <a href=\"{PATH}/options/fr\">French</a></p><table><tr><th>Page</th><th>Language</th><th>Title</th><th>Template</th></tr>"
    );
    for p in pages {
        body.push_str(&format!(
            "<tr><td><a href=\"{PATH}/page/{}\">{}</a></td><td>{}</td><td>{}</td><td>{}</td></tr>",
            p.id,
            esc(&p.path),
            esc(&p.lang),
            esc(&p.title),
            esc(&p.template)
        ));
    }
    body.push_str("</table>");
    page_html("Pages", &body).into_response()
}

fn revisions_table(revs: &[edit::RevisionSummary], csrf: &str) -> String {
    let mut out = String::from("<h2>Revisions</h2><table><tr><th>#</th><th>Saved</th><th>By user</th><th>Note</th><th></th></tr>");
    for r in revs {
        out.push_str(&format!(
            "<tr><td>{}</td><td>{}</td><td>{}</td><td>{}</td><td>{}<button>Restore</button></form></td></tr>",
            r.id,
            r.saved_at,
            r.saved_by,
            esc(&r.note),
            form_open(&format!("{PATH}/revision/{}/restore", r.id), csrf)
        ));
    }
    out.push_str("</table>");
    out
}

fn textarea(name: &str, label: &str, value: &str) -> String {
    let rows = (value.lines().count() + 1).clamp(2, 20);
    format!("<label for=\"{0}\">{1}</label><textarea id=\"{0}\" name=\"{0}\" rows=\"{rows}\">{2}</textarea>", esc(name), esc(label), esc(value))
}

async fn page_form(State(core): State<Arc<Auth>>, staff: Staff<Administrator>, Path(id): Path<i64>) -> Response {
    let page = match edit::load_page(&core.pool, PageId(id)).await {
        Ok(Some(p)) => p,
        Ok(None) => return failed(EditError::NotFound),
        Err(e) => return failed(e.into()),
    };
    let revs = edit::page_revisions(&core.pool, PageId(id)).await.unwrap_or_default();
    let csrf = staff.csrf.as_str();
    let mut body = format!("<p><a href=\"{PATH}\">All pages</a></p><h1>{} ({})</h1>{}", esc(&page.page.path), esc(&page.page.lang), form_open(&format!("{PATH}/page/{id}"), csrf));
    for (name, value) in &page.meta {
        body.push_str(&textarea(&format!("meta:{name}"), &format!("SEO {name}"), value));
    }
    for (name, value) in &page.fields {
        body.push_str(&textarea(name, name, value));
    }
    body.push_str("<label for=\"note\">Note</label><input id=\"note\" name=\"note\" size=\"60\"><p><button>Save</button></p></form>");
    body.push_str(&revisions_table(&revs, csrf));
    page_html(&page.page.path, &body).into_response()
}

/// The posted fields other than the token and the note, as changes. Unchanged values are saved too; the revision
/// records the state.
fn changes<'a>(fields: impl Iterator<Item = (&'a str, &'a str)>) -> (Vec<Change>, String) {
    let mut note = String::new();
    let mut out = Vec::new();
    for (name, value) in fields {
        match name {
            "note" => note = value.to_string(),
            csrf::FIELD => {}
            _ => out.push(Change { name: name.to_string(), value: value.replace("\r\n", "\n") }),
        }
    }
    (out, note)
}

async fn page_save(State(core): State<Arc<Auth>>, staff: Staff<Administrator>, Path(id): Path<i64>, posted: Posted) -> Response {
    let (changes, note) = changes(posted.fields.iter());
    match edit::save_page(&core.pool, PageId(id), &changes, staff.who.user.id, &note, unix_now()).await {
        Ok(_) => Redirect::to(&format!("{PATH}/page/{id}")).into_response(),
        Err(e) => failed(e),
    }
}

async fn options_form(State(core): State<Arc<Auth>>, staff: Staff<Administrator>, Path(code): Path<String>) -> Response {
    let Some(l) = Lang::parse(&code) else { return failed(EditError::NotFound) };
    let options = match edit::load_options(&core.pool, l).await {
        Ok(o) => o,
        Err(e) => return failed(e.into()),
    };
    let revs = edit::option_revisions(&core.pool, l).await.unwrap_or_default();
    let csrf = staff.csrf.as_str();
    let mut body = format!(
        "<p><a href=\"{PATH}\">All pages</a></p><h1>Site-wide texts ({})</h1><p>website_notification is the banner under the header; leave it empty to hide it.</p>{}",
        l.code(),
        form_open(&format!("{PATH}/options/{}", l.code()), csrf)
    );
    for (name, value) in options.iter().filter(|(n, _)| !n.starts_with('@')) {
        body.push_str(&textarea(name, name, value));
    }
    body.push_str("<label for=\"note\">Note</label><input id=\"note\" name=\"note\" size=\"60\"><p><button>Save</button></p></form>");
    body.push_str(&revisions_table(&revs, csrf));
    page_html("Site-wide texts", &body).into_response()
}

async fn options_save(State(core): State<Arc<Auth>>, staff: Staff<Administrator>, Path(code): Path<String>, posted: Posted) -> Response {
    let Some(l) = Lang::parse(&code) else { return failed(EditError::NotFound) };
    let (changes, note) = changes(posted.fields.iter());
    match edit::save_options(&core.pool, l, &changes, staff.who.user.id, &note, unix_now()).await {
        Ok(_) => Redirect::to(&format!("{PATH}/options/{}", l.code())).into_response(),
        Err(e) => failed(e),
    }
}

async fn restore(State(core): State<Arc<Auth>>, staff: Staff<Administrator>, Path(id): Path<i64>, _posted: Posted) -> Response {
    match edit::restore(&core.pool, id, staff.who.user.id, unix_now()).await {
        Ok(_) => Redirect::to(PATH).into_response(),
        Err(e) => failed(e),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn form_fields_become_changes_and_a_note() {
        let fields = [("hero_title", "a\r\nb"), ("note", "typo"), (csrf::FIELD, "token"), ("meta:title", "T")];
        let (c, note) = changes(fields.into_iter());
        assert_eq!(note, "typo");
        assert_eq!(c, vec![Change { name: "hero_title".into(), value: "a\nb".into() }, Change { name: "meta:title".into(), value: "T".into() }]);
    }
}
