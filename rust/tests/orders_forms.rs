//! The Gravity Forms replacement through the orders router, with a synthetic form definition and in-memory spam
//! services. Nothing here reaches Akismet, Google or a mail server.

use std::{
    net::SocketAddr,
    path::PathBuf,
    sync::{Arc, Mutex},
};

use axum::{
    body::Body,
    extract::connect_info::MockConnectInfo,
    http::{header, Method, Request, StatusCode},
    Router,
};
use http_body_util::BodyExt;
use hxweb::{
    api::Auth,
    db,
    identity::Email,
    orders::{
        forms::{
            spam::{Akismet, CheckFuture, Recaptcha, Submission, TokenKey},
            Services,
        },
        pricing::Unpriced,
        Orders,
    },
};
use sqlx::SqlitePool;
use tower::ServiceExt;

const META: &str = r#"{"id":3,"title":"Contact","button":{"text":"Submit"},"enableHoneypot":true,"honeypotAction":"spam","requiredIndicator":"asterisk",
    "fields":[{"type":"text","id":1,"label":"Name","isRequired":true,"size":"large"},
              {"type":"email","id":10,"label":"Email","isRequired":true,"size":"medium"},
              {"type":"textarea","id":4,"label":"Message","isRequired":true,"size":"large"}]}"#;
const NOTIFICATIONS: &str = r#"{"a":{"name":"Admin Notification","isActive":true,"event":"form_submission","toType":"email","to":"{admin_email}, desk@example.com",
    "bcc":"","fromName":"{Name:1}","replyTo":"{Email:10}","subject":"New submission from {form_title}","message":"{all_fields}"}}"#;
const CONFIRMATIONS: &str = r#"{"c":{"name":"Default","isDefault":true,"type":"message","message":"<strong>Thank you!</strong>"}}"#;

struct FixedAkismet(bool, Mutex<Vec<Submission>>);
impl Akismet for FixedAkismet {
    fn is_spam<'a>(&'a self, s: &'a Submission) -> CheckFuture<'a, bool> {
        self.1.lock().unwrap().push(s.clone());
        Box::pin(async move { self.0 })
    }
}

struct FixedScore(Option<f64>);
impl Recaptcha for FixedScore {
    fn score<'a>(&'a self, _token: &'a str, _ip: &'a str, _action: &'a str) -> CheckFuture<'a, Option<f64>> {
        Box::pin(async move { self.0 })
    }
}

const KEY: [u8; 32] = [9; 32];

struct Harness {
    app: Router,
    pool: SqlitePool,
}

async fn harness(akismet: Option<Arc<dyn Akismet>>, recaptcha: Option<Arc<dyn Recaptcha>>) -> Harness {
    let pool = db::open_memory().await.unwrap();
    sqlx::query("INSERT INTO wp_gf_form (id, title, date_created, is_active, is_trash) VALUES (1, 'Footer', '2020-01-01 00:00:00', 1, 0), (3, 'Contact', '2020-01-01 00:00:00', 1, 0), (7, 'Old', '2020-01-01 00:00:00', 1, 1)")
        .execute(&pool)
        .await
        .unwrap();
    for id in [1, 3, 7] {
        sqlx::query("INSERT INTO wp_gf_form_meta (form_id, display_meta, notifications, confirmations) VALUES (?1, ?2, ?3, ?4)")
            .bind(id)
            .bind(META.replace("\"id\":3,", &format!("\"id\":{id},")))
            .bind(NOTIFICATIONS)
            .bind(CONFIRMATIONS)
            .execute(&pool)
            .await
            .unwrap();
    }
    sqlx::query("INSERT INTO registry (domain, module, field, item, data) VALUES ('', 'recaptcha', 'score_spam', '', '0.5')").execute(&pool).await.unwrap();
    let core = Arc::new(Auth { pool: pool.clone(), secret: None, public_origin: "https://www.harcourt.co".into(), trusted_proxies: vec![], accounts: hxweb::accounts::Services::offline(hxweb::accounts::bot::Dns::fixed(&[], &[])) });
    let forms = Services { admin_email: Email::parse("web@example.com"), token_key: Some(TokenKey::new(&KEY)), akismet, recaptcha, manifest: None };
    let state = Orders { core, pricing: Arc::new(Unpriced), docroot: PathBuf::from("/nonexistent"), forms };
    let app = hxweb::orders::router(Arc::new(state)).layer(MockConnectInfo(SocketAddr::from(([192, 0, 2, 20], 40000))));
    Harness { app, pool }
}

async fn call(app: &Router, method: Method, uri: &str, form: &[(&str, &str)]) -> (StatusCode, String) {
    let body: Vec<String> = form.iter().map(|(k, v)| format!("{}={}", enc(k), enc(v))).collect();
    let req = Request::builder().method(method).uri(uri).header(header::CONTENT_TYPE, "application/x-www-form-urlencoded").header(header::USER_AGENT, "test-agent").body(Body::from(body.join("&"))).unwrap();
    let res = app.clone().oneshot(req).await.unwrap();
    let status = res.status();
    (status, String::from_utf8_lossy(&res.into_body().collect().await.unwrap().to_bytes()).into_owned())
}

fn enc(s: &str) -> String {
    percent_encoding::utf8_percent_encode(s, percent_encoding::NON_ALPHANUMERIC).to_string()
}

fn token() -> String {
    TokenKey::new(&KEY).issue(3, db::unix_now())
}

async fn post(h: &Harness, extra: &[(&str, &str)]) -> (StatusCode, String) {
    let t = token();
    let mut form = vec![("gform_submit", "3"), ("is_submit_3", "1"), ("input_1", "Pat Buyer"), ("input_10", "pat@example.com"), ("input_4", "Please quote 10 units."), ("state_3", t.as_str())];
    for (k, v) in extra {
        form.retain(|(name, _)| name != k);
        form.push((k, v));
    }
    call(&h.app, Method::POST, "/hx/forms/submit?hx_lang=en&hx_page=%2Fcontact-us%2F", &form).await
}

async fn count(pool: &SqlitePool, sql: &str) -> i64 {
    sqlx::query_scalar(sql).fetch_one(pool).await.unwrap()
}

#[tokio::test]
async fn the_island_renders_active_forms_only() {
    let h = harness(None, None).await;
    let (status, html) = call(&h.app, Method::GET, "/hx/forms/island?hx_form=3&hx_lang=fr&hx_page=%2Fcontact-us%2F", &[]).await;
    assert_eq!(status, StatusCode::OK);
    assert!(html.contains("id='gform_wrapper_3'") && html.contains("action='/contact-us/'") && html.contains(">Nom<"), "{html}");
    assert!(html.contains(">Envoyer</button>") && html.contains("name='state_3' value='"));
    let (status, _) = call(&h.app, Method::GET, "/hx/forms/island?hx_form=7&hx_lang=en&hx_page=%2F", &[]).await;
    assert_eq!(status, StatusCode::NOT_FOUND, "trashed");
    let (status, _) = call(&h.app, Method::GET, "/hx/forms/island?hx_form=3&hx_lang=en&hx_page=https%3A%2F%2Fexample.com%2F", &[]).await;
    assert_eq!(status, StatusCode::NOT_FOUND, "the page must be a path here");
}

#[tokio::test]
async fn a_valid_post_is_stored_and_notifies() {
    let akismet = Arc::new(FixedAkismet(false, Mutex::new(Vec::new())));
    let h = harness(Some(akismet.clone()), Some(Arc::new(FixedScore(Some(0.9))))).await;
    let (status, html) = post(&h, &[]).await;
    assert_eq!(status, StatusCode::OK);
    assert!(html.contains("<div id='gform_confirmation_wrapper_3' data-form-theme='legacy' class='gform_confirmation_wrapper gform_legacy_markup_wrapper gform_legacy_confirmation_markup_wrapper gform-theme--no-framework '><div id='gform_confirmation_message_3' class='gform_confirmation_message_3 gform_confirmation_message'><strong>Thank you!</strong></div></div>"), "{html}");
    let (status_text, source, agent): (String, String, String) = sqlx::query_as("SELECT status, source_url, user_agent FROM wp_gf_entry").fetch_one(&h.pool).await.unwrap();
    assert_eq!((status_text.as_str(), source.as_str(), agent.as_str()), ("active", "https://www.harcourt.co/contact-us/", "test-agent"));
    let name: String = sqlx::query_scalar("SELECT meta_value FROM wp_gf_entry_meta WHERE meta_key = '1'").fetch_one(&h.pool).await.unwrap();
    assert_eq!(name, "Pat Buyer");
    let (to, subject, from_name, reply_to, body): (String, String, String, String, String) =
        sqlx::query_as("SELECT to_email, subject, from_name, reply_to, body FROM outbox").fetch_one(&h.pool).await.unwrap();
    assert_eq!((to.as_str(), subject.as_str(), from_name.as_str(), reply_to.as_str()), ("web@example.com", "New submission from Contact", "Pat Buyer", "pat@example.com"));
    assert!(body.contains("<strong>Message</strong>") && body.contains("Please quote 10 units."));
    assert_eq!(count(&h.pool, "SELECT count(*) FROM outbox_recipient WHERE kind = 'to' AND email = 'desk@example.com'").await, 1);
    assert_eq!(akismet.1.lock().unwrap()[0].email, "pat@example.com");
}

#[tokio::test]
async fn invalid_input_comes_back_with_errors_and_nothing_is_kept() {
    let h = harness(None, None).await;
    let (_, html) = post(&h, &[("input_10", "nope"), ("input_4", "")]).await;
    assert!(html.contains("There was a problem with your submission."));
    assert!(html.contains("gfield_validation_message'>This field is required."));
    assert!(html.contains("value='Pat Buyer'"), "input is kept");
    assert_eq!(count(&h.pool, "SELECT count(*) FROM wp_gf_entry").await, 0);
    assert_eq!(count(&h.pool, "SELECT count(*) FROM outbox").await, 0);
}

#[tokio::test]
async fn spam_is_kept_as_spam_and_sends_nothing() {
    let h = harness(None, None).await;
    post(&h, &[("input_11", "filled by a robot")]).await;
    post(&h, &[("state_3", "")]).await;
    post(&h, &[("state_3", "1.00")]).await;
    assert_eq!(count(&h.pool, "SELECT count(*) FROM wp_gf_entry WHERE status = 'spam'").await, 3);
    assert_eq!(count(&h.pool, "SELECT count(*) FROM outbox").await, 0);
    let h = harness(Some(Arc::new(FixedAkismet(true, Mutex::new(Vec::new())))), None).await;
    let (_, html) = post(&h, &[]).await;
    assert!(html.contains("gform_confirmation_message'>Thanks for contacting us! We will get in touch with you shortly.</div>"), "spam sees Gravity Forms' default confirmation: {html}");
    assert!(!html.contains("<strong>Thank you!</strong>"));
    assert_eq!(count(&h.pool, "SELECT count(*) FROM wp_gf_entry WHERE status = 'spam'").await, 1);
    assert_eq!(count(&h.pool, "SELECT count(*) FROM outbox").await, 0);
}

#[tokio::test]
async fn a_low_recaptcha_score_blocks_the_contact_form_unless_bypassed() {
    let h = harness(None, Some(Arc::new(FixedScore(Some(0.3))))).await;
    let (_, html) = post(&h, &[]).await;
    assert!(html.contains("We were unable to verify your submission."), "{html}");
    assert_eq!(count(&h.pool, "SELECT count(*) FROM wp_gf_entry").await, 0);
    assert_eq!(count(&h.pool, "SELECT count(*) FROM log WHERE type = 'Recaptcha' AND subtype = 'Blocked' AND email = 'pat@example.com'").await, 1);
    sqlx::query("INSERT INTO captcha_bypass (email) VALUES ('pat@example.com')").execute(&h.pool).await.unwrap();
    let (_, html) = post(&h, &[]).await;
    assert!(html.contains("gform_confirmation_message_3"));
    assert_eq!(count(&h.pool, "SELECT count(*) FROM wp_gf_entry WHERE status = 'active'").await, 1);
}

#[tokio::test]
async fn a_form_the_page_embeds_with_ajax_answers_the_iframe_postback() {
    let h = harness(None, None).await;
    let t = TokenKey::new(&KEY).issue(1, db::unix_now());
    let form = [("gform_submit", "1"), ("is_submit_1", "1"), ("input_1", "Pat Buyer"), ("input_10", "pat@example.com"), ("input_4", "Hello"), ("state_1", t.as_str())];
    let (_, html) = call(&h.app, Method::POST, "/hx/forms/submit?hx_lang=en&hx_page=%2Fcontact-us%2F", &form).await;
    assert!(html.starts_with("<!DOCTYPE html><html><head><meta charset='UTF-8' /></head><body data-cmplz=1 class='GF_AJAX_POSTBACK'>"), "{html}");
    assert!(html.contains("gform_confirmation_wrapper_1"));
    let (_, html) = post(&h, &[]).await;
    assert!(!html.contains("GF_AJAX_POSTBACK"), "a form embedded without ajax answers with its markup");
    let ajax = hxweb::orders::forms::render::gform_ajax(3, "legacy");
    let (_, html) = post(&h, &[("gform_ajax", ajax.as_str())]).await;
    assert!(html.starts_with("<!DOCTYPE html><html><head><meta charset='UTF-8' /></head><body class='GF_AJAX_POSTBACK'><div id='gf_3' class='gform_anchor' tabindex='-1'></div><div id='gform_confirmation_wrapper_3'"), "Gravity Forms' own ajax post: {html}");
    let entries = count(&h.pool, "SELECT count(*) FROM wp_gf_entry").await;
    let (_, html) = post(&h, &[("gform_ajax", "form_id=3&hash=0")]).await;
    assert!(html.contains("<div id='gform_confirmation_message_0' class='gform_confirmation_message_0 gform_confirmation_message'>Thanks for contacting us!"), "a tampered ajax post: {html}");
    assert_eq!(count(&h.pool, "SELECT count(*) FROM wp_gf_entry").await, entries, "keeps nothing");
}

#[tokio::test]
async fn a_refused_post_shows_why_in_the_forms_language() {
    let h = harness(None, Some(Arc::new(FixedScore(Some(0.9))))).await;
    let (_, html) = post(&h, &[("input_1", ""), ("input_10", "nope")]).await;
    assert!(html.contains("<div class=\"gform_validation_errors validation_error\" id=\"gform_3_validation_container\" data-js=\"gform-focus-validation-error\" autofocus><h2 class='gform_submission_error hide_summary'><span class='gform-icon gform-icon--circle-error'></span>There was a problem with your submission. Please review the fields below.</h2></div>"), "{html}");
    assert!(html.contains("aria-describedby=\"validation_message_3_1\"") && html.contains("<div id='validation_message_3_10' class='gfield_description validation_message gfield_validation_message'>The email address entered is invalid"));
    let (_, html) = call(&h.app, Method::POST, "/hx/forms/submit?hx_lang=fr&hx_page=%2Fnous-contacter%2F", &[("gform_submit", "3"), ("input_1", "")]).await;
    assert!(html.contains("Une erreur s\u{2019}est produite lors de votre envoi.") && html.contains(">Ce champ est nécessaire.</div>"), "{html}");
    let blocked = harness(None, Some(Arc::new(FixedScore(Some(0.1))))).await;
    let (_, html) = post(&blocked, &[("input_1", "")]).await;
    assert!(html.contains("We were unable to verify your submission.") && html.contains("id='validation_message_3_1'"), "the reCAPTCHA gate replaces the summary, the field errors stay");
    assert_eq!(count(&blocked.pool, "SELECT count(*) FROM log WHERE type = 'Recaptcha'").await, 1);
}

/// Run by hand over the output of tools/gf_dump_to_sqlite.py for the production tables:
/// `HX_GF_SQL=/tmp/gf.sql cargo test --test orders_forms -- --ignored`.
#[tokio::test]
#[ignore]
async fn every_imported_form_parses() {
    let Ok(path) = std::env::var("HX_GF_SQL") else { return };
    let pool = db::open_memory().await.unwrap();
    sqlx::raw_sql(&std::fs::read_to_string(path).unwrap()).execute(&pool).await.unwrap();
    let ids: Vec<i64> = sqlx::query_scalar("SELECT id FROM wp_gf_form WHERE is_active = 1 AND is_trash = 0").fetch_all(&pool).await.unwrap();
    assert!(!ids.is_empty());
    for id in ids {
        let form = hxweb::orders::forms::model::load(&pool, id).await.unwrap().unwrap_or_else(|| panic!("form {id} does not parse"));
        for f in &form.fields {
            assert!(!matches!(f.kind, hxweb::orders::forms::model::Kind::Unsupported(_)), "form {id} field {} has an unsupported type", f.id);
        }
        assert!(!form.notifications.is_empty(), "form {id} has no active notification");
    }
}
