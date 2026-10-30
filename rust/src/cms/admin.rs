//! The staff screens for page text and banners: list the pages, edit a page's text and its SEO title and
//! description, edit each language's site-wide texts (the header notice banner, the call-to-action block, footer
//! texts), see every revision and restore one. Administrators only. The staff area mounts it:
//!
//! ```ignore
//! Router::new().nest("/dashboard/cms", hxweb::cms::admin::router(core.clone()))
//! ```
//!
//! Links are relative to the mount point, so any prefix works. Saves are form posts; a post whose Origin (or,
//! failing that, Referer) is not this site is refused.

use std::sync::Arc;

use axum::{
    extract::{OriginalUri, Path, Request, State},
    http::{header, HeaderMap, StatusCode},
    middleware::{self, Next},
    response::{Html, IntoResponse, Redirect, Response},
    routing::{get, post},
    Form, Router,
};

use super::{
    edit::{self, Change, EditError},
    html::esc,
    Lang, PageId,
};
use crate::{
    api::{Administrator, Auth, Authed},
    db::unix_now,
};

pub fn router(core: Arc<Auth>) -> Router {
    Router::new()
        .route("/", get(to_pages))
        .route("/pages", get(index))
        .route("/page/{id}", get(page_form).post(page_save))
        .route("/options/{lang}", get(options_form).post(options_save))
        .route("/revision/{id}/restore", post(restore))
        .layer(middleware::from_fn_with_state(core.clone(), same_site_posts))
        .with_state(core)
}

/// Form posts must come from this site's own pages.
async fn same_site_posts(State(core): State<Arc<Auth>>, req: Request, next: Next) -> Response {
    if req.method() != axum::http::Method::POST || from_this_site(req.headers(), &core.public_origin) {
        return next.run(req).await;
    }
    (StatusCode::FORBIDDEN, "Forbidden").into_response()
}

fn from_this_site(h: &HeaderMap, origin: &str) -> bool {
    if let Some(o) = h.get(header::ORIGIN) {
        return o.as_bytes() == origin.as_bytes();
    }
    h.get(header::REFERER).and_then(|r| r.to_str().ok()).is_some_and(|r| r == origin || r.starts_with(&format!("{origin}/")))
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

/// The mount point itself: links are relative, so send the browser to a path below it.
async fn to_pages(OriginalUri(uri): OriginalUri) -> Redirect {
    Redirect::to(&format!("{}/pages", uri.path().trim_end_matches('/')))
}

async fn index(State(core): State<Arc<Auth>>, _who: Authed<Administrator>) -> Response {
    let pages = match edit::list_pages(&core.pool).await {
        Ok(p) => p,
        Err(e) => return failed(e.into()),
    };
    let mut body = String::from("<h1>Site content</h1><p>Banners and footer texts: <a href=\"options/en\">English</a> · <a href=\"options/fr\">French</a></p><table><tr><th>Page</th><th>Language</th><th>Title</th><th>Template</th></tr>");
    for p in pages {
        body.push_str(&format!(
            "<tr><td><a href=\"page/{}\">{}</a></td><td>{}</td><td>{}</td><td>{}</td></tr>",
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

fn revisions_table(revs: &[edit::RevisionSummary], back: &str) -> String {
    let mut out = String::from("<h2>Revisions</h2><table><tr><th>#</th><th>Saved</th><th>By user</th><th>Note</th><th></th></tr>");
    for r in revs {
        out.push_str(&format!(
            "<tr><td>{}</td><td>{}</td><td>{}</td><td>{}</td><td><form method=\"post\" action=\"{back}revision/{}/restore\"><button>Restore</button></form></td></tr>",
            r.id,
            r.saved_at,
            r.saved_by,
            esc(&r.note),
            r.id
        ));
    }
    out.push_str("</table>");
    out
}

fn textarea(name: &str, label: &str, value: &str) -> String {
    let rows = (value.lines().count() + 1).clamp(2, 20);
    format!("<label for=\"{0}\">{1}</label><textarea id=\"{0}\" name=\"{0}\" rows=\"{rows}\">{2}</textarea>", esc(name), esc(label), esc(value))
}

async fn page_form(State(core): State<Arc<Auth>>, _who: Authed<Administrator>, Path(id): Path<i64>) -> Response {
    let page = match edit::load_page(&core.pool, PageId(id)).await {
        Ok(Some(p)) => p,
        Ok(None) => return failed(EditError::NotFound),
        Err(e) => return failed(e.into()),
    };
    let revs = edit::page_revisions(&core.pool, PageId(id)).await.unwrap_or_default();
    let mut body = format!("<p><a href=\"../pages\">All pages</a></p><h1>{} ({})</h1><form method=\"post\">", esc(&page.page.path), esc(&page.page.lang));
    for (name, value) in &page.meta {
        body.push_str(&textarea(&format!("meta:{name}"), &format!("SEO {name}"), value));
    }
    for (name, value) in &page.fields {
        body.push_str(&textarea(name, name, value));
    }
    body.push_str("<label for=\"note\">Note</label><input id=\"note\" name=\"note\" size=\"60\"><p><button>Save</button></p></form>");
    body.push_str(&revisions_table(&revs, "../"));
    page_html(&page.page.path, &body).into_response()
}

/// Form fields other than `note`, as changes. Unchanged values are saved too; the revision records the state.
fn changes(form: Vec<(String, String)>) -> (Vec<Change>, String) {
    let mut note = String::new();
    let mut out = Vec::new();
    for (name, value) in form {
        if name == "note" {
            note = value;
        } else {
            out.push(Change { name, value: value.replace("\r\n", "\n") });
        }
    }
    (out, note)
}

async fn page_save(State(core): State<Arc<Auth>>, who: Authed<Administrator>, Path(id): Path<i64>, Form(form): Form<Vec<(String, String)>>) -> Response {
    let (changes, note) = changes(form);
    match edit::save_page(&core.pool, PageId(id), &changes, who.user.id, &note, unix_now()).await {
        Ok(_) => Redirect::to(&format!("../page/{id}")).into_response(),
        Err(e) => failed(e),
    }
}

async fn options_form(State(core): State<Arc<Auth>>, _who: Authed<Administrator>, Path(code): Path<String>) -> Response {
    let Some(l) = Lang::parse(&code) else { return failed(EditError::NotFound) };
    let options = match edit::load_options(&core.pool, l).await {
        Ok(o) => o,
        Err(e) => return failed(e.into()),
    };
    let revs = edit::option_revisions(&core.pool, l).await.unwrap_or_default();
    let mut body = format!("<p><a href=\"../pages\">All pages</a></p><h1>Site-wide texts ({})</h1><p>website_notification is the banner under the header; leave it empty to hide it.</p><form method=\"post\">", l.code());
    for (name, value) in options.iter().filter(|(n, _)| !n.starts_with('@')) {
        body.push_str(&textarea(name, name, value));
    }
    body.push_str("<label for=\"note\">Note</label><input id=\"note\" name=\"note\" size=\"60\"><p><button>Save</button></p></form>");
    body.push_str(&revisions_table(&revs, "../"));
    page_html("Site-wide texts", &body).into_response()
}

async fn options_save(State(core): State<Arc<Auth>>, who: Authed<Administrator>, Path(code): Path<String>, Form(form): Form<Vec<(String, String)>>) -> Response {
    let Some(l) = Lang::parse(&code) else { return failed(EditError::NotFound) };
    let (changes, note) = changes(form);
    match edit::save_options(&core.pool, l, &changes, who.user.id, &note, unix_now()).await {
        Ok(_) => Redirect::to(&format!("../options/{}", l.code())).into_response(),
        Err(e) => failed(e),
    }
}

async fn restore(State(core): State<Arc<Auth>>, who: Authed<Administrator>, Path(id): Path<i64>) -> Response {
    match edit::restore(&core.pool, id, who.user.id, unix_now()).await {
        Ok(_) => Redirect::to("../../pages").into_response(),
        Err(e) => failed(e),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn posts_must_name_this_site() {
        let origin = "https://www.harcourt.co";
        let h = |k: header::HeaderName, v: &str| {
            let mut m = HeaderMap::new();
            m.insert(k, v.parse().unwrap());
            m
        };
        assert!(from_this_site(&h(header::ORIGIN, origin), origin));
        assert!(!from_this_site(&h(header::ORIGIN, "https://evil.example"), origin));
        assert!(from_this_site(&h(header::REFERER, "https://www.harcourt.co/dashboard/cms/page/1"), origin));
        assert!(!from_this_site(&h(header::REFERER, "https://www.harcourt.co.evil.example/"), origin));
        assert!(!from_this_site(&HeaderMap::new(), origin));
    }

    #[test]
    fn form_fields_become_changes_and_a_note() {
        let (c, note) = changes(vec![("hero_title".into(), "a\r\nb".into()), ("note".into(), "typo".into()), ("meta:title".into(), "T".into())]);
        assert_eq!(note, "typo");
        assert_eq!(c, vec![Change { name: "hero_title".into(), value: "a\nb".into() }, Change { name: "meta:title".into(), value: "T".into() }]);
    }
}
