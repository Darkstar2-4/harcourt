//! The site's Gravity Forms: their markup, validation, spam checks, notifications and stored entries.
//!
//! The definitions live in `wp_gf_form`/`wp_gf_form_meta` (imported with tools/gf_dump_to_sqlite.py), so the
//! recipients stay data. The pages embed a form through `island`, and hand a post back through `handle`:
//!
//! - `GET /hx/forms/island?hx_form=N&hx_lang=en|fr&hx_page=/path/` answers the markup, as
//!   mu-plugins/hx-form-island.php did for the static pages.
//! - A form posts to its page, as Gravity Forms does. The page's POST handler passes the request to `handle`
//!   when it carries `gform_submit`, and puts the answer where the form was. `POST /hx/forms/submit` with the same
//!   query does that for a client that posts here directly; an ajax form gets the iframe postback document.

use std::{collections::HashMap, sync::Arc};

use axum::{
    body::Bytes,
    extract::State,
    http::{header, HeaderMap, HeaderValue, StatusCode, Uri},
    response::{IntoResponse, Response},
    routing::{get, post},
    Router,
};

use super::{form_params, html, Orders};
use crate::{api::ClientIp, config::Secret, db::unix_now, identity::Email};

pub mod model;
pub mod render;
pub mod spam;
pub mod submit;

/// The contact forms mu-plugins/registry-recaptcha-contact-forms.php guards.
pub const RECAPTCHA_FORMS: [i64; 7] = [3, 4, 5, 6, 12, 13, 14];

/// Outside services and settings the forms use. Tests build it by hand with doubles.
#[derive(Default)]
pub struct Services {
    /// WordPress's `admin_email`, which the notifications send to as `{admin_email}`.
    pub admin_email: Option<Email>,
    pub token_key: Option<spam::TokenKey>,
    pub akismet: Option<Arc<dyn spam::Akismet>>,
    pub recaptcha: Option<Arc<dyn spam::Recaptcha>>,
}

impl Services {
    /// HXWEB_ADMIN_EMAIL, HXWEB_SECRET (signs the anti-spam token), HXWEB_AKISMET_KEY, HXWEB_RECAPTCHA_SECRET. A check
    /// whose setting is missing is skipped and says so in the log.
    pub fn from_env(get: impl Fn(&str) -> Option<String>) -> Self {
        let http = || reqwest::Client::builder().timeout(std::time::Duration::from_secs(10)).build().unwrap_or_default();
        let blog = get("HXWEB_PUBLIC_ORIGIN").unwrap_or_else(|| "https://www.harcourt.co".into());
        let token_key = get("HXWEB_SECRET").and_then(|s| Secret::parse(&s).ok()).map(|s| spam::TokenKey::new(s.as_bytes()));
        let akismet = get("HXWEB_AKISMET_KEY").filter(|k| !k.trim().is_empty()).map(|key| Arc::new(spam::AkismetHttp { key: key.trim().to_string(), blog, http: http() }) as Arc<dyn spam::Akismet>);
        let recaptcha = get("HXWEB_RECAPTCHA_SECRET").filter(|k| !k.trim().is_empty()).map(|secret| Arc::new(spam::RecaptchaHttp { secret: secret.trim().to_string(), http: http() }) as Arc<dyn spam::Recaptcha>);
        for (name, missing) in [("HXWEB_SECRET (zero-spam token)", token_key.is_none()), ("HXWEB_AKISMET_KEY", akismet.is_none()), ("HXWEB_RECAPTCHA_SECRET", recaptcha.is_none())] {
            if missing {
                tracing::warn!("forms: {name} is not set; that spam check is off");
            }
        }
        Services { admin_email: get("HXWEB_ADMIN_EMAIL").and_then(|e| Email::parse(&e)), token_key, akismet, recaptcha }
    }
}

pub fn routes() -> Router<Arc<Orders>> {
    Router::new().route("/hx/forms/island", get(island_route)).route("/hx/forms/submit", post(submit_route))
}

/// The form's markup for this page and language, or None for a form that is not active.
pub async fn island(state: &Orders, form_id: i64, lang: model::Lang, page_uri: &str) -> sqlx::Result<Option<String>> {
    let Some(form) = model::load(&state.core.pool, form_id).await? else { return Ok(None) };
    let form = model::localize(form, lang);
    let token = state.forms.token_key.as_ref().map(|k| k.issue(form.id, unix_now()));
    let values = render::Values::new();
    let errors = render::FieldErrors::new();
    Ok(Some(render::form(&render::Render {
        form: &form,
        page_uri,
        ajax: form.id == 1,
        values: &values,
        errors: &errors,
        summary: None,
        token: token.as_deref(),
        recaptcha: state.forms.recaptcha.is_some() && RECAPTCHA_FORMS.contains(&form.id),
    })))
}

/// A post carrying `gform_submit`, from the page at `page_uri`. None when it names no active form.
pub async fn handle(state: &Orders, params: &HashMap<String, String>, lang: model::Lang, page_uri: &str, ip: &str, user_agent: &str, page_title: Option<String>) -> sqlx::Result<Option<(submit::Outcome, bool)>> {
    let Some(form_id) = params.get("gform_submit").and_then(|v| v.trim().parse::<i64>().ok()) else { return Ok(None) };
    let Some(form) = model::load(&state.core.pool, form_id).await? else { return Ok(None) };
    let form = model::localize(form, lang);
    let ajax = params.contains_key("gform_ajax");
    let ctx = submit::Context {
        page_uri,
        page_url: format!("{}{page_uri}", state.core.public_origin),
        page_title,
        ip,
        user_agent,
        ajax,
        now: unix_now(),
    };
    Ok(Some((submit::submit(&state.core.pool, &state.forms, &form, params, &ctx).await?, ajax)))
}

fn page_of(params: &HashMap<String, String>) -> Option<(model::Lang, String)> {
    let page = params.get("hx_page").filter(|p| p.starts_with('/') && !p.starts_with("//"))?;
    Some((model::Lang::parse(params.get("hx_lang").map_or("en", String::as_str)), page.clone()))
}

fn text(status: StatusCode, body: &'static str) -> Response {
    let mut r = (status, body).into_response();
    r.headers_mut().insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
    r
}

fn markup(body: String) -> Response {
    let mut r = html::page_response(body);
    r.headers_mut().insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
    r
}

async fn island_route(State(state): State<Arc<Orders>>, uri: Uri) -> Response {
    let params = form_params(uri.query(), &[]);
    let found = match (params.get("hx_form").and_then(|f| f.parse::<i64>().ok()), page_of(&params)) {
        (Some(id), Some((lang, page))) => island(&state, id, lang, &page).await,
        _ => Ok(None),
    };
    match found {
        Ok(Some(html)) => markup(html),
        Ok(None) => text(StatusCode::NOT_FOUND, "no such form island\n"),
        Err(e) => {
            tracing::error!("form island: {e}");
            text(StatusCode::INTERNAL_SERVER_ERROR, "form island failed\n")
        }
    }
}

async fn submit_route(State(state): State<Arc<Orders>>, ClientIp(ip): ClientIp, headers: HeaderMap, uri: Uri, body: Bytes) -> Response {
    let query = form_params(uri.query(), &[]);
    let Some((lang, page)) = page_of(&query) else { return text(StatusCode::BAD_REQUEST, "missing hx_page\n") };
    let params = form_params(None, &body);
    let agent = headers.get(header::USER_AGENT).and_then(|v| v.to_str().ok()).unwrap_or("");
    match handle(&state, &params, lang, &page, &ip, agent, None).await {
        Ok(Some((submit::Outcome::Redirect(url), _))) => html::redirect(&url),
        Ok(Some((submit::Outcome::Invalid(h) | submit::Outcome::Confirmed { html: h, .. }, ajax))) => markup(if ajax { render::ajax_postback(&h) } else { h }),
        Ok(None) => text(StatusCode::NOT_FOUND, "no such form\n"),
        Err(e) => {
            tracing::error!("form submit: {e}");
            text(StatusCode::INTERNAL_SERVER_ERROR, "form submit failed\n")
        }
    }
}
