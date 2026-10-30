//! newuser.php: the sign-up page the /request-access page loads, and its three AJAX actions.
//!
//! An address at a domain an Active company owns gets an emailed code; the right code signs the person in to the
//! company for remote_days. Any other address asks for access: a Pending account is recorded and staff are told.
//! The code and its tries live in `access_code`, never in the browser, and the browser holds only a session cookie.

use std::sync::Arc;

use axum::{
    extract::{Form, State},
    http::{header, HeaderMap, HeaderValue, StatusCode},
    response::{IntoResponse, Response},
};
use serde::Serialize;

use crate::{
    access_code::{self, Attempt},
    accounts::{
        account::Account,
        admission::{self, Admission, Context, Registration},
        cookies,
        html::{self, escape, Opts},
        log::{self, Kind, Subject},
        oauth::{self, Outcome},
        profile::{self, Layout, Profile},
        recaptcha::{self, Recaptcha, Score},
        registry,
    },
    api::Auth,
    db::unix_now,
    identity::{Email, Status},
    visitor::{Identity, Visitor},
};

/// newuser.php allowed two wrong codes; the third try is refused until a new code is sent.
const CODE_TRIES: i64 = 2;

const UNABLE: &str = "<p style='color:red;'>We were unable to verify your submission. Please try again, or contact us directly at <a href='mailto:sales@harcourt.co'>sales@harcourt.co</a> if the issue continues.</p>";
const NOT_AUTHORIZED: &str = "<p style='color:red;'>Your domain is not authorized to access this site. Please contact us for more information.</p>";
const PENDING: &str = "<p>You access request is pending approval. Your request will be approved as soon as possible.</p>";

/// Everything a handler reads from the request besides the form.
struct Request {
    visitor: Visitor,
    headers: HeaderMap,
    ip: String,
    now: i64,
}

impl Request {
    fn context(&self) -> Context<'_> {
        Context {
            ip: &self.ip,
            user_agent: self.headers.get(header::USER_AGENT).and_then(|v| v.to_str().ok()).unwrap_or(""),
            dns_name: None,
            now: self.now,
        }
    }

    fn remembered_email(&self) -> Option<Email> {
        cookies::get(&self.headers, cookies::EMAIL).as_deref().and_then(Email::parse)
    }

    fn profile(&self) -> Profile {
        let mut p = Profile::from_cookie(cookies::get(&self.headers, profile::COOKIE).as_deref());
        if p.email.is_empty() {
            p.email = cookies::get(&self.headers, cookies::EMAIL).unwrap_or_default();
        }
        p
    }
}

fn request(visitor: Visitor, headers: HeaderMap) -> Request {
    let ip = visitor.client.map_or_else(String::new, |ip| ip.to_string());
    Request { visitor, headers, ip, now: unix_now() }
}

pub async fn page(State(core): State<Arc<Auth>>, visitor: Visitor, headers: HeaderMap) -> Response {
    let req = request(visitor, headers);
    match render_page(&core, &req).await {
        Ok((body, set)) => html_response(body, set),
        Err(e) => internal(e),
    }
}

pub async fn action(State(core): State<Arc<Auth>>, visitor: Visitor, headers: HeaderMap, Form(posted): Form<Vec<(String, String)>>) -> Response {
    if headers.get(header::ORIGIN).is_some_and(|o| o.as_bytes() != core.public_origin.as_bytes()) {
        return StatusCode::FORBIDDEN.into_response();
    }
    let xhr = headers.contains_key("x-requested-with");
    let req = request(visitor, headers);
    if !xhr {
        return match render_page(&core, &req).await {
            Ok((body, set)) => html_response(body, set),
            Err(e) => internal(e),
        };
    }
    let field = |name: &str| posted.iter().find(|(k, _)| k == name).map_or("", |(_, v)| v.as_str());
    let result = match field("action") {
        "microsoft_oauth_init" => {
            return json_response(r#"{"redirect":"/oauth/init.php"}"#.to_string(), Vec::new());
        }
        "register" => register(&core, &req, &posted, field("g-recaptcha-response")).await,
        "remote" => verify(&core, &req, field("unlock_code"), field("g-recaptcha-response")).await,
        "token" => resend(&core, &req, field("g-recaptcha-response")).await,
        _ => Ok(Reply::new()),
    };
    match result {
        Ok(reply) => {
            let set = reply.cookies.clone();
            json_response(serde_json::to_string(&reply).unwrap_or_default(), set)
        }
        Err(e) => internal(e),
    }
}

fn internal(e: sqlx::Error) -> Response {
    tracing::error!("newuser: {e}");
    (StatusCode::INTERNAL_SERVER_ERROR, "Internal error").into_response()
}

fn html_response(body: String, set: Vec<HeaderValue>) -> Response {
    let mut r = ([(header::CONTENT_TYPE, "text/html; charset=UTF-8"), (header::CACHE_CONTROL, "no-store")], body).into_response();
    for c in set {
        r.headers_mut().append(header::SET_COOKIE, c);
    }
    r
}

fn json_response(body: String, set: Vec<HeaderValue>) -> Response {
    let mut r = ([(header::CONTENT_TYPE, "application/json"), (header::CACHE_CONTROL, "no-store")], body).into_response();
    for c in set {
        r.headers_mut().append(header::SET_COOKIE, c);
    }
    r
}

/// An object whose keys keep the order they were added in, as PHP's json_encode wrote them.
struct Pairs<T>(Vec<(String, T)>);

impl<T: Serialize> Serialize for Pairs<T> {
    fn serialize<S: serde::Serializer>(&self, s: S) -> Result<S::Ok, S::Error> {
        s.collect_map(self.0.iter().map(|(k, v)| (k, v)))
    }
}

impl<T> Pairs<T> {
    fn set(&mut self, key: &str, value: T) {
        match self.0.iter_mut().find(|(k, _)| k == key) {
            Some(slot) => slot.1 = value,
            None => self.0.push((key.to_string(), value)),
        }
    }
}

/// newuser.php's `$this->response`: fields to mark, menu entries to show or hide, and element contents to set.
#[derive(Serialize)]
struct Reply {
    error: Pairs<u8>,
    menu: Pairs<u8>,
    html: Pairs<String>,
    #[serde(skip)]
    cookies: Vec<HeaderValue>,
}

impl Reply {
    fn new() -> Reply {
        Reply { error: Pairs(Vec::new()), menu: Pairs(Vec::new()), html: Pairs(vec![("scscpq_input_status".into(), String::new())]), cookies: Vec::new() }
    }
}

async fn spam(core: &Auth, req: &Request, rc: Option<&Recaptcha>, score: &Score, context: &str, email: &str) -> sqlx::Result<bool> {
    let Some(rc) = rc else { return Ok(false) };
    if !rc.is_spam(score) {
        return Ok(false);
    }
    if let Some(e) = Email::parse(email) {
        if admission::captcha_bypassed(&core.pool, &e).await? {
            return Ok(false);
        }
    }
    let who = Subject { ip: &req.ip, email: (!email.is_empty()).then_some(email), ..Default::default() };
    let comment = format!("reCAPTCHA score {} at or below spam threshold {} ({context})", score.text, rc.spam_threshold());
    log::record(&core.pool, Kind::RecaptchaBlocked, &who, &comment).await;
    Ok(true)
}

async fn score(core: &Auth, req: &Request, rc: Option<&Recaptcha>, token: &str) -> Score {
    match rc {
        Some(rc) => rc.score(&core.accounts.http, token, recaptcha::NEWUSER, &req.ip).await,
        None => Score { value: 1.0, text: "1".into() },
    }
}

/// Send a code to an address at an Active company. The answer to the browser never depends on whether it went.
async fn send_code(core: &Auth, email: &Email, now: i64) -> sqlx::Result<()> {
    let Some(secret) = core.secret.as_ref() else {
        tracing::warn!("HXWEB_SECRET is not set: no sign-in code sent");
        return Ok(());
    };
    access_code::issue(&core.pool, secret, email, now).await?;
    Ok(())
}

/// newuser.php json_register().
async fn register(core: &Auth, req: &Request, posted: &[(String, String)], token: &str) -> sqlx::Result<Reply> {
    let pool = &core.pool;
    let mut reply = Reply::new();
    let rc = Recaptcha::load(pool).await?;
    let score = score(core, req, rc.as_ref(), token).await;
    let layout = Layout::load(pool).await?;
    let (p, errors) = profile::verify(&layout, posted, req.profile());
    if spam(core, req, rc.as_ref(), &score, "registration form", &p.email).await? {
        reply.html.set("profile_register", UNABLE.into());
        return Ok(reply);
    }
    let email = Email::parse(&p.email);
    if let Some(email) = &email {
        match admission::classify(pool, email).await? {
            Admission::Blacklisted => {
                admission::log_blacklisted(pool, email, &req.context(), "registration form").await;
                reply.html.set("profile_register", NOT_AUTHORIZED.into());
                return Ok(reply);
            }
            Admission::Company { company, known } => {
                if !known {
                    admission::notify_auto_approved(pool, &company, email, "Registration form (access token emailed for verification)", &req.context()).await?;
                }
                admission::remember_address(pool, email, req.now).await?;
                reply.cookies.extend(cookies::set(cookies::EMAIL, email.as_str(), cookies::Life::Year));
                send_code(core, email, req.now).await?;
                reply.html.set("scscpq_content", remote_form(pool, &company, rc.as_ref()).await?);
                return Ok(reply);
            }
            Admission::Pending | Admission::Unknown => {}
        }
    }
    for (key, _) in posted {
        reply.error.set(key, u8::from(errors.iter().any(|(k, _)| k == key)));
    }
    let (Some(email), true) = (email, errors.is_empty()) else {
        reply.html.set("profile_message", format!("Correct {} error(s)", errors.len().max(1)));
        return Ok(reply);
    };
    let registration = Registration {
        company_name: p.company_name.clone(),
        address: p.address.clone(),
        city: p.city.clone(),
        state: p.state.clone(),
        zip: p.zip.clone(),
        country_code: p.country_code.clone(),
        domain: None,
        recaptcha_score: score.value,
    };
    let ctx = req.context();
    let (id, signin) = admission::register(pool, &registration, &email, false, &ctx).await?;
    reply.cookies.extend(signin.cookies());
    reply.cookies.extend(cookies::set(profile::COOKIE, &p.to_cookie(), cookies::Life::Year));
    let mut lines = profile::summary(pool, &layout, &p).await?;
    lines.push(format!("reCAPTCHA Score: {}", score.text));
    admission::log_signup(pool, id, &ctx, &lines.join("<br>")).await;
    lines.push(String::new());
    lines.extend(admission::request_lines(&ctx));
    admission::notify_staff(pool, None, &lines, req.now).await?;
    reply.html.set(
        "profile_register",
        "<h2 class='page-subtitle editable h2-login'>Congratulations, your access request has been submitted!</h2><p>Your request is pending approval. You will be able to enter our catalog as soon as it has been approved.</p>".into(),
    );
    Ok(reply)
}

/// The company a remembered address belongs to, when it is Active.
async fn company_for(core: &Auth, email: Option<&Email>) -> sqlx::Result<Option<(Account, bool)>> {
    let Some(email) = email else { return Ok(None) };
    Ok(match admission::classify(&core.pool, email).await? {
        Admission::Company { company, known } => Some((*company, known)),
        _ => None,
    })
}

/// newuser.php json_verify().
async fn verify(core: &Auth, req: &Request, typed: &str, token: &str) -> sqlx::Result<Reply> {
    let pool = &core.pool;
    let mut reply = Reply::new();
    let rc = Recaptcha::load(pool).await?;
    let score = score(core, req, rc.as_ref(), token).await;
    let email = req.remembered_email();
    let company = company_for(core, email.as_ref()).await?;
    let typed = typed.trim();
    let spam = spam(core, req, rc.as_ref(), &score, "access token verify", email.as_ref().map_or("", Email::as_str)).await?;
    let outcome = match (&email, &company, core.secret.as_ref()) {
        (Some(e), Some(_), Some(secret)) if spam => Some(access_code::attempt(pool, secret, e, None, req.now, CODE_TRIES).await?),
        _ if typed.is_empty() => None,
        (Some(e), Some(_), Some(secret)) => Some(access_code::attempt(pool, secret, e, Some(typed), req.now, CODE_TRIES).await?),
        _ => Some(Attempt::Expired),
    };
    let error = match outcome {
        None => "Cannot be blank".to_string(),
        Some(Attempt::Wrong(n)) => format!("Invalid access token ({n} of 3)"),
        Some(Attempt::Locked) => "Excess failures. A new token must be resent".into(),
        Some(Attempt::Expired) => "Your access token has expired. A new token must be resent".into(),
        Some(Attempt::Verified) => String::new(),
    };
    let (Some(email), Some((company, _)), true) = (email, company, error.is_empty()) else {
        reply.html.set("unlock_code_message", String::new());
        reply.html.set("unlock_code_error", error);
        return Ok(reply);
    };
    let signin = admission::grant(pool, &company, &email, &req.context()).await?;
    reply.cookies.extend(signin.cookies());
    reply.html.set("scscpq_content", access_message(&company));
    reply.menu.set("scscpq_newuser", 0);
    if company.catalog() {
        reply.menu.set("scscpq_catalog", 1);
    }
    Ok(reply)
}

/// newuser.php json_token(): send another code.
async fn resend(core: &Auth, req: &Request, token: &str) -> sqlx::Result<Reply> {
    let mut reply = Reply::new();
    let rc = Recaptcha::load(&core.pool).await?;
    let score = score(core, req, rc.as_ref(), token).await;
    let email = req.remembered_email();
    if !spam(core, req, rc.as_ref(), &score, "resend access token", email.as_ref().map_or("", Email::as_str)).await? {
        if let (Some(e), Some(_)) = (&email, company_for(core, email.as_ref()).await?) {
            send_code(core, e, req.now).await?;
        }
    }
    reply.html.set("unlock_code_error", String::new());
    reply.html.set("unlock_code_message", "Access token has been sent".into());
    Ok(reply)
}

/// newuser.php html(): what the page shows this visitor, with any cookies it sets.
async fn render_page(core: &Auth, req: &Request) -> sqlx::Result<(String, Vec<HeaderValue>)> {
    let pool = &core.pool;
    let rc = Recaptcha::load(pool).await?;
    if let Some(outcome) = oauth::take_outcome(pool, &req.headers).await? {
        let body = match outcome {
            Outcome::Error(message) => format!("<p style='color:red;'>{}</p>{}", escape(&message), register_form(core, req, rc.as_ref()).await?),
            Outcome::NoEmail(kind) => format!(
                "<p style='color:red;'>{} did not provide an email address. Please use the registration form.</p>{}",
                kind.name(),
                register_form(core, req, rc.as_ref()).await?
            ),
            Outcome::Blacklisted => NOT_AUTHORIZED.into(),
            Outcome::Granted => match req.visitor.account() {
                Some(company) => format!("{}{GRANTED_SCRIPT}", access_message(company)),
                None => format!("{ACCESS_HEADING}{GRANTED_SCRIPT}"),
            },
            Outcome::Pending => PENDING.into(),
            Outcome::Submitted => "<h2 class='page-subtitle editable h2-login'>Congratulations, your access request has been submitted!<h2>".into(),
            Outcome::Failed => "<p style='color:red;'>Internal error. Please try again.</p>".into(),
        };
        let page = [CSS, JS, &form_open(), "<div id=scscpq_content>", &body, "</div>", html::FORM_CLOSE].concat();
        return Ok((page, Vec::new()));
    }
    let content = match &req.visitor.identity {
        Identity::Bot(_) => String::new(),
        Identity::Account(s) if s.account.status() == Status::Pending => PENDING.into(),
        Identity::Account(_) => String::new(),
        Identity::Anonymous => match company_for(core, req.remembered_email().as_ref()).await? {
            Some((company, known)) if req.visitor.impersonator.is_none() => {
                if known {
                    if let Some(e) = req.remembered_email() {
                        send_code(core, &e, req.now).await?;
                    }
                }
                remote_form(pool, &company, rc.as_ref()).await?
            }
            _ => register_form(core, req, rc.as_ref()).await?,
        },
    };
    let page = [CSS, JS, &form_open(), "<div id=scscpq_content>", "<div id=scscpq_input_status></div>", &content, html::FORM_CLOSE, "</div>"].concat();
    Ok((page, Vec::new()))
}

fn form_open() -> String {
    html::form_open("/newuser.php", Opts::new().event("onsubmit", "return false;"))
}

const OAUTH_BUTTONS: &str = concat!(
    "<div style='margin: 20px 0; text-align: left;'>",
    "<div style='margin-bottom: 10px; font-weight: bold;'>Sign in with your work account:</div>",
    "<div style='display:flex;gap:12px;flex-wrap:wrap;'>",
    "<a href='/oauth/init.php' style='display:inline-flex;align-items:center;background:#2f2f2f;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;font-family:\"Segoe UI\",sans-serif;font-size:15px;'>",
    "<svg xmlns='http://www.w3.org/2000/svg' width='21' height='21' viewBox='0 0 21 21' style='margin-right:12px;'><rect x='1' y='1' width='9' height='9' fill='#f25022'/><rect x='11' y='1' width='9' height='9' fill='#7fba00'/><rect x='1' y='11' width='9' height='9' fill='#00a4ef'/><rect x='11' y='11' width='9' height='9' fill='#ffb900'/></svg>",
    "Sign in with Microsoft",
    "</a>",
    "<a href='/oauth/google_init.php' style='display:inline-flex;align-items:center;background:#fff;color:#3c4043;padding:10px 20px;text-decoration:none;border-radius:4px;font-family:\"Roboto\",\"Segoe UI\",sans-serif;font-size:15px;border:1px solid #dadce0;'>",
    "<svg xmlns='http://www.w3.org/2000/svg' width='21' height='21' viewBox='0 0 48 48' style='margin-right:12px;'><path fill='#EA4335' d='M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z'/><path fill='#4285F4' d='M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z'/><path fill='#34A853' d='M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z'/><path fill='#FBBC05' d='M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z'/></svg>",
    "Sign in with Google",
    "</a>",
    "</div>",
    "</div>",
);

/// newuser.php html_register().
async fn register_form(core: &Auth, req: &Request, rc: Option<&Recaptcha>) -> sqlx::Result<String> {
    let pool = &core.pool;
    let layout = Layout::load(pool).await?;
    let heading = if req.visitor.impersonator.is_some() { "User impersonating / no update allowed." } else { "" };
    let intro = [
        "If your company email is already registered with Harcourt &reg;, click \"Sign in with Microsoft\" or \"Sign in with Google\" below for instant access.",
        "Otherwise, please complete the form below and click \"Submit\" to become an approved Harcourt &reg; user. Once you have completed the form, our team will be in touch to confirm your access.",
        "Only email address is required if your company is already registered and you are working remotely.",
        "Thank you!",
    ]
    .join("<br>");
    Ok([
        recaptcha::js(rc).to_string(),
        format!("<h2 class='page-subtitle editable h2-login'>{heading}</h2>"),
        "<div id=profile_register>".into(),
        format!("<p class='company_registered'>{intro}</p>"),
        OAUTH_BUTTONS.into(),
        "<hr style='margin: 20px 0; border: 0; border-top: 1px solid #ccc;'>".into(),
        "<p style='font-weight:bold;'>Or complete the form below:</p>".into(),
        profile::form(pool, &layout, &req.profile()).await?,
        recaptcha::button(rc, "Submit", "red-button", None, None),
        "<div id=recaptcha_status></div>".into(),
        "</div>".into(),
    ]
    .concat())
}

/// newuser.php html_remote(): the code box for an address at an Active company.
async fn remote_form(pool: &sqlx::SqlitePool, company: &Account, rc: Option<&Recaptcha>) -> sqlx::Result<String> {
    let webmaster = registry::get(pool, "email", "webmaster").await?.unwrap_or_default();
    let code_box = [
        "Enter Access Token:".to_string(),
        html::text("unlock_code", "", 10, Some(10), Opts::new().attr("class", "scscpq_input")),
        recaptcha::button(rc, "Submit", "red-button", Some("recaptcha_button_verify"), Some("recaptcha_script_verify")),
    ]
    .join(" ");
    let resend = recaptcha::button(rc, "Resend Access Token", "red-button", Some("recaptcha_button_resend"), Some("recaptcha_script_resend"));
    Ok([
        recaptcha::js(rc).to_string(),
        format!("<h2 class='page-subtitle editable h2-login'>Welcome {} Employee</h2>", escape(company.user.company_name.as_deref().unwrap_or(""))),
        format!("<p>Access token has been mailed from {}. Please check your inbox or spam folder.</p>", escape(&webmaster)),
        "<div id=scscpq_remote></div>".into(),
        format!("<div style='font-size: 20px;'>{code_box}</div>"),
        "<div id=unlock_code_error></div>".into(),
        "<div id=recaptcha_status></div>".into(),
        format!("<div>{resend} <span id=unlock_code_message></span></div>"),
        OAUTH_BUTTONS.into(),
    ]
    .concat())
}

const ACCESS_HEADING: &str = "<h2 class='page-subtitle editable h2-login'>Congratulations, you now have access!<h2>";

/// newuser.php json_remote_message(): the catalog button goes to the company's language.
fn access_message(company: &Account) -> String {
    if !company.catalog() {
        return ACCESS_HEADING.into();
    }
    let go = if company.language_code.eq_ignore_ascii_case("FR") {
        "window.location.replace('/catalogue/?lang=fr');"
    } else {
        "window.location.replace('/catalog');"
    };
    let button = html::button("Click Here To Enter Our Catalog", Opts::new().attr("class", "red-button").event("onclick", go));
    format!("{ACCESS_HEADING}<p>{button}</p>")
}

const GRANTED_SCRIPT: &str = concat!(
    "<script>",
    "if (typeof jQuery === \"function\" && typeof scscpq_url !== \"undefined\") {",
    "jQuery.ajax({",
    "url: scscpq_url,",
    "data: {action: \"initial\", page: \"\", document: \"\", token: sessionStorage[\"scscpq_token\"], url: window.location.href},",
    "type: \"post\",",
    "dataType: \"json\",",
    "success: function(response) {",
    "for (var key in response) {",
    "var value = response[key];",
    "if (key === \"token\") { sessionStorage[\"scscpq_token\"] = value; continue; }",
    "if (key.substring(0,6) !== \"scscpq\") continue;",
    "if (key === \"scscpq_document\" || key === \"scscpq_page\" || key === \"scscpq_link\" || key === \"scscpq_iframe\") continue;",
    "if (value == 0) { jQuery(\".\" + key).hide(); } else { jQuery(\".\" + key).show(); if (value != 1) jQuery(\".\" + key).html(value); }",
    "}",
    "}",
    "});",
    "}",
    "</script>",
);

const CSS: &str = r#"<style>
#scscpq_content {
    color: #000;
	background-color: #fff;
	font-family: "Open Sans",sans-serif;
    width: 100%;
    padding: 0px;
    margin: 0px;
}
#scs_form {display: flex;flex-wrap:wrap; background: transparent;}
form h2{flex-basis:100%; margin-top: 0; }
.scscpq_label {font-weight: 700; padding: 10px !important;}
.scscpq_input {padding: 10px;}
table.scscpq_table { width: 100%; border: 0px; }
table.scscpq_table th { padding: 2px; vertical-align: bottom; text-align: center; }
table.scscpq_table td { padding: 2px; vertical-align: top; }
.newuser_ok {border: 1px solid black; }
.newuser_error { border: 2px solid red; }
// .red-button { width: 220px !important; border-radius: 4px; text-align: center; text-transform: uppercase; padding: 20px 5px; display: inline-block; position: relative; z-index: 1; margin-top: 3.125rem; font-family: 'Rajdhani'; font-size: 1.25rem !important; font-weight: 600; border: none; background: linear-gradient(0deg, rgba(115,14,28,1) 0%, rgba(229,28,56,1) 100%);color:#fff; cursor: pointer}
.red-button { border-radius: 4px; text-align: center; text-transform: uppercase; padding: 20px 30px; display: inline-block; position: relative; z-index: 1; margin-top: 3.125rem; font-family: 'Rajdhani'; font-size: 1.25rem !important; font-weight: 600; border: none; background: linear-gradient(0deg, rgba(115,14,28,1) 0%, rgba(229,28,56,1) 100%);color:#fff; cursor: pointer}
</style>"#;

const JS: &str = r##"<script>
jQuery(document).keypress(function(event) {
    if(event.key == "Enter") alert("The ENTER key is not available on this page. Please click the SUBMIT button.");
})
function recaptcha_script(token) {
	jQuery("#recaptcha_button").hide();
	jQuery("#recaptcha_status").html("Working ...");
	jQuery("#recaptcha_status").show();
	fn_newuser('register');
}
function recaptcha_script_verify(token) {
	jQuery("#recaptcha_button_verify").hide();
	jQuery("#recaptcha_status").html("Working ...");
	jQuery("#recaptcha_status").show();
	fn_newuser('remote');
}
function recaptcha_script_resend(token) {
	jQuery("#recaptcha_button_resend").hide();
	jQuery("#recaptcha_status").html("Working ...");
	jQuery("#recaptcha_status").show();
	fn_newuser('token');
}
function fn_newuser(action) {
	var data=new Object();
	var obj=jQuery("#scs_form :input");
    obj.each(function() {
        if (this.name.length) data[this.name] = jQuery(this).val();
    });
    data['action']=action;
	jQuery.ajax({
        url: "/newuser.php",
        type: "post",
	    dataType: "json",
		data: data ,
	    success: function(response) {
            if (response['redirect']) {
                window.location.href = response['redirect'];
                return;
            }
            fields=response['html'];
            for (var field in fields) {
                jQuery("#" + field).html(fields[field]);
            }
            fields=response['error'];
            for (var field in fields) {
                if (fields[field] == 1) {
                    jQuery("#" + field).removeClass('profile_error').addClass('profile_error');
                } else {
                    jQuery("#" + field).removeClass('profile_error');
                }
            }
            fields=response['menu'];
            for (var field in fields) {
                if (fields[field] == 1) {
                    jQuery("." + field).show();
                } else {
                    jQuery("." + field).hide();
                }
            }
    	    jQuery("#recaptcha_button, #recaptcha_button_verify, #recaptcha_button_resend").show();
	        jQuery("#recaptcha_status").hide();
        },
        error: function(xhr, response) {
        	console.log(xhr);
			jQuery("#scscpq_input_status").html("Internal Error");
        }
	});
}
</script>"##;
