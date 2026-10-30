//! The site's Gravity Forms: their markup, validation, spam checks, notifications and stored entries.
//!
//! The definitions live in `wp_gf_form`/`wp_gf_form_meta` (`hxweb-cms import` copies them from the WordPress dump,
//! `import`), so the recipients stay data. The pages embed a form through `island`, and hand a post back through `handle`:
//!
//! - `GET /hx/forms/island?hx_form=N&hx_lang=en|fr&hx_page=/path/` answers the markup, as
//!   mu-plugins/hx-form-island.php did for the static pages.
//! - The anti-spam token (gf-zero-spam's job) is the form's signed `state_N`: only a form this server rendered
//!   in the last two days carries one.
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

mod ajax_script;
mod countries;
pub mod import;
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
    /// The static export's manifest.json, which lists each page's forms and how they are embedded.
    pub manifest: Option<std::path::PathBuf>,
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
        let manifest = get("HXWEB_EXPORT").map(|d| std::path::Path::new(&d).join("manifest.json"));
        Services { admin_email: get("HXWEB_ADMIN_EMAIL").and_then(|e| Email::parse(&e)), token_key, akismet, recaptcha, manifest }
    }
}

pub fn routes() -> Router<Arc<Orders>> {
    Router::new()
        .route("/hx/forms/island", get(island_route))
        .route("/hx/forms/island/", get(island_route))
        .route("/hx/forms/submit", post(submit_route))
}

/// How the export says `page_uri` embeds `form_id` in this language. With no manifest configured every form is
/// embedded with the defaults; with one, a form the page does not list is not served, as hx-form-island.php did.
/// A page answers a post at its path without the slash too.
pub fn embed(services: &Services, form_id: i64, lang: model::Lang, page_uri: &str) -> Option<render::Embed> {
    let Some(path) = &services.manifest else { return Some(render::Embed::default_for(form_id)) };
    let manifest: serde_json::Value = serde_json::from_str(&std::fs::read_to_string(path).ok()?).ok()?;
    let lang = if lang == model::Lang::Fr { "fr" } else { "en" };
    let page_path = page_uri.split('?').next().unwrap_or(page_uri).trim_end_matches('/');
    let entry = manifest.get("pages")?.as_array()?.iter().find_map(|p| {
        let e = p.get(lang)?;
        let url = e.get("url")?.as_str()?;
        let path = url.split_once("://").map_or(url, |(_, rest)| rest.find('/').map_or("/", |i| &rest[i..]));
        (path.split('?').next().map(|p| p.trim_end_matches('/')) == Some(page_path)).then_some(e)
    })?;
    let islands = entry.get("islands")?.as_array()?;
    let at = islands.iter().position(|i| i.get("form").and_then(serde_json::Value::as_i64) == Some(form_id))?;
    let i = &islands[at];
    let flag = |k: &str| i.get(k).and_then(serde_json::Value::as_bool).unwrap_or(false);
    Some(render::Embed {
        ajax: flag("ajax"),
        styles: i.get("styles").and_then(serde_json::Value::as_str).unwrap_or("").to_string(),
        texturize: flag("texturize"),
        in_content: flag("in_content"),
        position: at + 1,
    })
}

/// The form's markup for this page and language, or None for a form that is not active or not on that page.
pub async fn island(state: &Orders, form_id: i64, lang: model::Lang, page_uri: &str, user_agent: &str) -> sqlx::Result<Option<String>> {
    let Some(embed) = embed(&state.forms, form_id, lang, page_uri) else { return Ok(None) };
    let Some(form) = model::load(&state.core.pool, form_id).await? else { return Ok(None) };
    let form = model::localize(form, lang);
    let token = state.forms.token_key.as_ref().map(|k| k.issue(form.id, unix_now()));
    let values = render::Values::new();
    let errors = render::FieldErrors::new();
    Ok(Some(render::form(&render::Render {
        form: &form,
        page_uri,
        embed: &embed,
        values: &values,
        errors: &errors,
        summary: None,
        token: token.as_deref(),
        recaptcha: RECAPTCHA_FORMS.contains(&form.id),
        browser: &render::browser_class(user_agent),
    })))
}

/// A post carrying `gform_submit`, from the page at `page_uri`. None when it names no active form. The flag says the
/// answer is the form's markup alone: Gravity Forms' own ajax post (with `gform_ajax`) got nothing else. Any other
/// post gets the page with that markup in the form's place; a form the page embeds with ajax answers there with
/// the iframe postback document, as get_form() did.
pub async fn handle(state: &Orders, params: &HashMap<String, String>, lang: model::Lang, page_uri: &str, ip: &str, user_agent: &str, page_title: Option<String>) -> sqlx::Result<Option<(submit::Outcome, bool)>> {
    let Some(form_id) = params.get("gform_submit").and_then(|v| v.trim().parse::<i64>().ok()) else { return Ok(None) };
    let Some(form) = model::load(&state.core.pool, form_id).await? else { return Ok(None) };
    let form = model::localize(form, lang);
    let mut embed = embed(&state.forms, form_id, lang, page_uri).unwrap_or_else(|| render::Embed::default_for(form_id));
    let posted_ajax = params.get("gform_ajax");
    if posted_ajax.is_some_and(|v| !render::ajax_args_valid(v, &form)) {
        return Ok(Some((submit::Outcome::Confirmed { html: render::ajax_postback(&render::tampered(&form), false), entry: None, spam: true }, true)));
    }
    let bare = posted_ajax.is_some();
    embed.ajax |= bare;
    let ajax = embed.ajax;
    let ctx = submit::Context {
        page_uri,
        page_url: format!("{}{page_uri}", state.core.public_origin),
        page_title,
        ip,
        user_agent,
        embed,
        now: unix_now(),
    };
    let outcome = submit::submit(&state.core.pool, &state.forms, &form, params, &ctx).await?;
    Ok(Some((if ajax { outcome.map_html(|h| render::ajax_postback(&h, !bare)) } else { outcome }, bare)))
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

async fn island_route(State(state): State<Arc<Orders>>, headers: HeaderMap, uri: Uri) -> Response {
    let params = form_params(uri.query(), &[]);
    let agent = headers.get(header::USER_AGENT).and_then(|v| v.to_str().ok()).unwrap_or("");
    let found = match (params.get("hx_form").and_then(|f| f.parse::<i64>().ok()), page_of(&params)) {
        (Some(id), Some((lang, page))) => island(&state, id, lang, &page, agent).await,
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
        Ok(Some((submit::Outcome::Invalid(h) | submit::Outcome::Confirmed { html: h, .. }, _))) => markup(h),
        Ok(None) => text(StatusCode::NOT_FOUND, "no such form\n"),
        Err(e) => {
            tracing::error!("form submit: {e}");
            text(StatusCode::INTERNAL_SERVER_ERROR, "form submit failed\n")
        }
    }
}
