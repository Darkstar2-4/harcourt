//! mailer_output.php (mailer_toolbox::output): the Email Output Tester. An Administrator writes a message, reviews
//! the mail a document (`mailform`) would make of it, and sends it; a Super User may pick any document and change
//! the letterhead settings. Sending queues the mail in `outbox` like every other mail the site sends.
//!
//! The PHP page also printed the SMTP server settings, the password included, into the form, and let a Super User
//! send through a server they typed in. Here the sender is configured from the environment only, so those settings
//! are neither shown nor taken. Attachments are not sent: the PHP saved them into the document root.

use std::sync::Arc;

use axum::{
    extract::State,
    http::{header, HeaderMap},
    response::{IntoResponse, Response},
};
use sqlx::SqlitePool;

use crate::{
    api::{Administrator, ClientIp},
    db::unix_now,
    identity::{Email, Role},
    orders::{
        mail::{self, Kind, Mailbox},
        mailform::{self, Letterhead, Merge, Registry, Template},
        Orders, Posted, Staff, SuperUser,
    },
    staff::{
        check,
        csrf::Fields,
        html::{button, close, esc, hidden, open, Blank, Form, Notes, Opts},
        shell,
    },
};

const PATH: &str = "/mailer_output.php";
const TITLE: &str = "Email Output Tester";
const VERSION: &str = "08/07/2025";

const SCRIPT: &str = "
<script>
function fn_action(action, obj) {
\tif (action=='send') obj.style.display ='none';
    jQuery(\"#action\").val(action);
    jQuery(\"#scs_form\").submit();
}
function fn_ajax(action) {
    request=scscpq_class_data(\"input\");
    request['action']=action;
    request['csrf']=jQuery('#csrf').val();
    jQuery.ajax({
        dataType: 'json',
        method: 'post',
        data: request,
        success:function(response) { scscpq_fill(response) }
    });
}
</script>";

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Step {
    Enter,
    Review,
    Send,
}

impl Step {
    fn parse(action: &str) -> Step {
        match action {
            "" | "enter" | "mailform" => Step::Enter,
            "send" => Step::Send,
            _ => Step::Review,
        }
    }
}

/// The letterhead settings: the registry's `email` module, or what a Super User typed once the form was posted.
struct Settings {
    base_href: String,
    css: String,
}

struct Input {
    step: Step,
    mailform: String,
    recipient: String,
    cc: Vec<String>,
    bcc: Vec<String>,
    message: String,
    attachment: bool,
}

/// One address per line, as email_class read them: each checked, a repeat refused. The errors are `line: text`.
fn address_lines(text: &str) -> (Vec<String>, Vec<String>) {
    let (mut list, mut errors) = (Vec::new(), Vec::new());
    for item in text.split('\n').map(str::trim).filter(|l| !l.is_empty()) {
        let (address, error) = check::email(item, false);
        if list.contains(&address) {
            errors.push(format!("{}: Duplicate entry", esc(item)));
            continue;
        }
        if let Some(e) = error {
            errors.push(format!("{}: {e}", esc(item)));
        }
        list.push(address);
    }
    (list, errors)
}

async fn default_document(pool: &SqlitePool) -> sqlx::Result<String> {
    let code = sqlx::query_scalar!(r#"SELECT coalesce(code, '') AS "code!: String" FROM mailform WHERE recipient = '' ORDER BY id LIMIT 1"#).fetch_optional(pool).await?;
    Ok(code.unwrap_or_default())
}

/// output_input(): what the form asks for. `None` fields is the first visit to the page.
async fn read(pool: &SqlitePool, fields: Option<&Fields>, debug: bool, session_email: &str, upload: bool, notes: &mut Notes) -> sqlx::Result<(Input, Settings)> {
    let empty = Fields::default();
    let f = fields.unwrap_or(&empty);
    let action = f.get("action");
    let mailform = if !debug && f.get("mailform").is_empty() { default_document(pool).await? } else { f.get("mailform").to_string() };
    let email = Registry::load(pool, "email").await?;
    let settings = if action.is_empty() || !debug {
        Settings { base_href: email.get("base_href").to_string(), css: email.get("css").to_string() }
    } else {
        Settings { base_href: f.get("base_href").trim().to_string(), css: f.get("css").trim().to_string() }
    };
    let mut input = Input { step: Step::parse(action), mailform, recipient: String::new(), cc: Vec::new(), bcc: Vec::new(), message: String::new(), attachment: false };
    if action.is_empty() {
        input.recipient = session_email.to_string();
        return Ok((input, settings));
    }
    input.recipient = f.get("recipient").to_string();
    if let (_, Some(e)) = check::email(&input.recipient, false) {
        notes.error("recipient", Some(e));
    }
    for (field, list) in [("cc", &mut input.cc), ("bcc", &mut input.bcc)] {
        let (addresses, errors) = address_lines(f.get(field));
        *list = addresses;
        if !errors.is_empty() {
            notes.error_html(field, errors.join("<br>"));
        }
    }
    input.message = f.get("message").trim().to_string();
    if input.message.is_empty() {
        notes.error("message", Some("Cannot be blank"));
    }
    input.attachment = f.checked("attachment");
    if input.attachment || upload {
        notes.error("upload", Some("Attachments are not sent from this page"));
    }
    Ok((input, settings))
}

/// mailform_class::select(): every document by name, marked by direction and copies.
async fn documents(pool: &SqlitePool) -> sqlx::Result<Vec<(String, String)>> {
    let rows = sqlx::query!(
        r#"SELECT coalesce(code, '') AS "code!: String", coalesce(name, '') AS "name!: String", recipient AS "recipient!: String", coalesce(bcc, '') AS "bcc!: String" FROM mailform ORDER BY name"#
    )
    .fetch_all(pool)
    .await?;
    Ok(rows
        .into_iter()
        .map(|r| {
            let mut label = vec![r.name, if r.recipient.is_empty() { "(To Visitor)" } else { "(To Us)" }.to_string()];
            if serde_json::from_str::<Vec<String>>(&r.bcc).is_ok_and(|b| !b.is_empty()) {
                label.push("(w/BCC)".into());
            }
            (r.code, esc(&label.join(" ")))
        })
        .collect())
}

async fn enter(pool: &SqlitePool, input: &Input, settings: &Settings, template: Option<&Template>, debug: bool, notes: &Notes) -> sqlx::Result<String> {
    let form = Form { notes };
    let (name, subject) = template.map_or(("", ""), |t| (t.name.as_str(), t.subject.as_str()));
    let mut out = vec!["<table class='standard noborder'>".to_string(), "<tr>".into(), "<td width='20%'>Document</td>".into()];
    let document = if debug {
        form.select("mailform", &documents(pool).await?, &input.mailform, Blank::Label("Select Document"), Opts::new().class("input").onchange("fn_ajax('mailform');"))
    } else {
        out.push(hidden("mailform", &input.mailform));
        format!("<b>{}</b>", esc(name))
    };
    out.push(format!("<td width='80%'>{document}</td>"));
    out.push("</tr>".into());
    out.push(format!("<tr><td>Subject</td><td><b><span id=subject>{}</span></b></td></tr>", esc(subject)));
    out.push("<tr>".into());
    out.push("<td>Recipient</td>".into());
    let recipient = if debug {
        notes.with_error("recipient", form.text("recipient", &input.recipient, 60, 0, Opts::new()))
    } else {
        out.push(hidden("recipient", &input.recipient));
        format!("<b>{}</b>", esc(&input.recipient))
    };
    out.push(format!("<td>{recipient}</td>"));
    out.push("</tr>".into());
    for (field, label, list) in [("cc", "CC", &input.cc), ("bcc", "BCC", &input.bcc)] {
        let area = form.textarea_lines(field, list, 3, 60, Opts::new());
        out.push(format!("<tr><td>{label}</td><td>{}</td></tr>", notes.with_error(field, area)));
    }
    let message = form.textarea("message", &input.message, 20, 60, Opts::new());
    let message = match notes.error_text("message") {
        Some(e) => format!("{e}<br>{message}"),
        None => message,
    };
    out.push(format!("<tr><td>Message</td><td>{message}</td></tr>"));
    let upload = notes.with_error("upload", form.file("upload", 40, Opts::new()));
    out.push(format!("<tr><td>Attach file? {}</td><td>{upload}</td></tr>", form.checkbox("attachment", "1", input.attachment, Opts::new())));
    out.push("</table>".into());
    if debug {
        out.push(format!(
            "<div>{} <b>Email Settings</b></div>",
            form.checkbox("email_setting_check", "1", false, Opts::new().onclick("div_show('email_setting_div',this.checked);"))
        ));
        out.push("<div id='email_setting_div' style='display:none;'><table class='standard noborder'>".into());
        out.push(format!("<tr><td>Base href</td><td>{}</td></tr>", form.text("base_href", &settings.base_href, 60, 60, Opts::new())));
        out.push(format!("<tr><td>CSS</td><td>{}</td></tr>", form.text("css", &settings.css, 60, 60, Opts::new())));
        out.push("</table></div>".into());
    } else {
        out.push(settings_hidden(settings));
    }
    out.push(format!("<p class=left>{}</p>", button("Review >>", Opts::new().onclick("fn_action('review',this);"))));
    Ok(out.concat())
}

fn settings_hidden(settings: &Settings) -> String {
    let field = |name: &str, value: &str| {
        let value = if value.is_empty() { String::new() } else { format!(" value='{}'", esc(value)) };
        format!("<input type=hidden name='{name}' class='input' id='{name}'{value}>\n")
    };
    field("base_href", &settings.base_href) + &field("css", &settings.css)
}

/// The mail the form describes, or why there is none: scsmail_class with the tester's options.
async fn compose(state: &Orders, staff: &Staff<Administrator>, ip: &str, host: &str, input: &Input, settings: &Settings, template: Option<&Template>) -> sqlx::Result<Result<mail::Mail, String>> {
    let Some(template) = template else { return Ok(Err(format!("Missing mailform: {}", esc(&input.mailform)))) };
    let pool = &state.core.pool;
    let company = Registry::load(pool, "company").await?;
    let user = &staff.who.user;
    let merge = Merge::standard(unix_now(), ip, host, user.id.0, user.company_name.as_deref().unwrap_or(""), &company);
    let letterhead = Letterhead::read(&state.docroot, &settings.base_href, &settings.css).await;
    let to = Email::parse(&input.recipient).or_else(|| template.recipient.clone()).or_else(|| staff.who.email.as_deref().and_then(Email::parse));
    let Some(mut mail) = template.compose(&letterhead, &merge, None, to.map(Mailbox::bare), &input.message) else { return Ok(Err("To address missing".into())) };
    for (kind, list) in [(Kind::Cc, &input.cc), (Kind::Bcc, &input.bcc)] {
        for address in list.iter().filter_map(|a| Email::parse(a)) {
            mail.add(kind, Mailbox::bare(address));
        }
    }
    Ok(Ok(mail))
}

async fn review(state: &Orders, staff: &Staff<Administrator>, ip: &str, host: &str, input: &Input, settings: &Settings, template: Option<&Template>) -> sqlx::Result<String> {
    let pool = &state.core.pool;
    let webmaster = Registry::load(pool, "email").await?.get("webmaster").to_string();
    let (code, name, subject) = template.map_or(("", "", ""), |t| (t.code.as_str(), t.name.as_str(), t.subject.as_str()));
    let mut out = vec![
        "<table class='standard noborder'>".to_string(),
        format!("<tr><td width='20%'>Document code</td><td width='80%'>{}</td></tr>", esc(code)),
        format!("<tr><td>Document name</td><td>{}</td></tr>", esc(name)),
        format!("<tr><td>Subject</td><td>{}</td></tr>", esc(subject)),
        format!("<tr><td>Sender</td><td>{}</td></tr>", esc(&webmaster)),
        format!("<tr><td>Recipient</td><td>{}</td></tr>", esc(&input.recipient)),
    ];
    for (label, list) in [("CC", &input.cc), ("BCC", &input.bcc)] {
        if !list.is_empty() {
            out.push(format!("<tr><td>{label}</td><td>{}</td></tr>", list.iter().map(|a| esc(a)).collect::<Vec<_>>().join("<br>")));
        }
    }
    out.push("</table>".into());
    let mail = compose(state, staff, ip, host, input, settings, template).await?;
    match (input.step, mail) {
        (Step::Send, Ok(m)) => {
            mail::enqueue(pool, &m, unix_now()).await?;
            out.push(format!("<p>Sending message ...<br></p><p>Results: Email queued for delivery to {}</p>", esc(m.to.email.as_str())));
        }
        (Step::Send, Err(e)) => out.push(format!("<p>Sending message ...<br></p><p>Results: {e}</p>")),
        (_, Ok(m)) => out.push(format!("<p>Results: <b>Deliver email to {}</b></p>", esc(m.to.email.as_str()))),
        (_, Err(e)) => out.push(format!("<p>Results: <b>{e}</b></p>")),
    }
    let buttons = [button("<< Back", Opts::new().onclick("fn_action('enter',this);")), button("Send >>", Opts::new().onclick("fn_action('send',this);"))];
    out.push(format!("<p>{}</p>", buttons.join(" ")));
    out.push(hidden("mailform", &input.mailform));
    out.push(hidden("recipient", &input.recipient));
    out.push(hidden("cc", &input.cc.join("\n")));
    out.push(hidden("bcc", &input.bcc.join("\n")));
    out.push(hidden("message", &input.message));
    out.push(settings_hidden(settings));
    Ok(out.concat())
}

async fn run(state: &Orders, staff: &Staff<Administrator>, ip: &str, headers: &HeaderMap, posted: Option<&Posted>) -> Response {
    let pool = &state.core.pool;
    let debug = staff.who.user.role >= Role::SuperUser;
    let mut notes = Notes::new(TITLE, VERSION);
    let session_email = staff.who.email.clone().unwrap_or_default();
    let upload = posted.is_some_and(|p| p.upload("upload").is_some());
    let result = async {
        let (input, settings) = read(pool, posted.map(|p| &p.fields), debug, &session_email, upload, &mut notes).await?;
        let template = mailform::load(pool, &input.mailform).await?;
        let host = headers.get(header::HOST).and_then(|v| v.to_str().ok()).unwrap_or("");
        let form = if input.step == Step::Enter || notes.has_errors() {
            enter(pool, &input, &settings, template.as_ref(), debug, &notes).await?
        } else {
            review(state, staff, ip, host, &input, &settings, template.as_ref()).await?
        };
        Ok::<_, sqlx::Error>(format!("{}{SCRIPT}{}{}{form}{}", notes.render(), open(PATH, staff.csrf.as_str(), true), hidden("action", ""), close()))
    }
    .await;
    match result {
        Ok(body) => shell::page(pool, staff.view(), TITLE, &body).await,
        Err(e) => shell::internal(e),
    }
}

pub async fn get(State(state): State<Arc<Orders>>, staff: Staff<Administrator>, ClientIp(ip): ClientIp, headers: HeaderMap) -> Response {
    if headers.contains_key("x-requested-with") {
        return json(&serde_json::json!({}));
    }
    run(&state, &staff, &ip, &headers, None).await
}

fn json(v: &serde_json::Value) -> Response {
    ([(header::CONTENT_TYPE, "application/json"), (header::CACHE_CONTROL, "no-store")], v.to_string()).into_response()
}

/// ajax_output_mailform(): the document's recipient (the signed-in address for a mail to the visitor), subject and
/// copies, for the form to fill in.
async fn document_json(pool: &SqlitePool, code: &str, session_email: &str) -> sqlx::Result<serde_json::Value> {
    Ok(match mailform::load(pool, code).await? {
        Some(t) => serde_json::json!({
            "recipient": t.recipient.as_ref().map_or(session_email, Email::as_str),
            "subject": t.subject,
            "bcc": t.bcc.iter().map(Email::as_str).collect::<Vec<_>>().join("\n"),
        }),
        None => serde_json::json!({}),
    })
}

pub async fn post(State(state): State<Arc<Orders>>, staff: Staff<Administrator>, ClientIp(ip): ClientIp, headers: HeaderMap, posted: Posted) -> Response {
    if !headers.contains_key("x-requested-with") {
        return run(&state, &staff, &ip, &headers, Some(&posted)).await;
    }
    let answer = match posted.fields.get("action") {
        "mailform" => document_json(&state.core.pool, posted.fields.get("mailform"), staff.who.email.as_deref().unwrap_or("")).await,
        _ => Ok(serde_json::json!({})),
    };
    match answer {
        Ok(v) => json(&v),
        Err(e) => shell::internal(e),
    }
}

/// scs_mail.php and scs_mailform.php, the 2013 tester and document editor. Both read columns `mailform` lost in 2021,
/// so they had failed for everyone since; a Super User, the only role they admitted, goes to the page that replaced
/// each: this tester, and mailer_maintain.php.
pub async fn old_tester(_staff: Staff<SuperUser>) -> Response {
    crate::orders::html::redirect(PATH)
}

pub async fn old_editor(_staff: Staff<SuperUser>) -> Response {
    crate::orders::html::redirect("/mailer_maintain.php")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn steps_follow_the_action() {
        assert_eq!(Step::parse(""), Step::Enter);
        assert_eq!(Step::parse("mailform"), Step::Enter);
        assert_eq!(Step::parse("send"), Step::Send);
        assert_eq!(Step::parse("review"), Step::Review);
        assert_eq!(Step::parse("anything"), Step::Review);
    }

    #[test]
    fn address_lines_are_checked_and_deduplicated() {
        let (list, errors) = address_lines(" a@example.com\n\nA@example.com\nnot an address\nb@example.com ");
        assert_eq!(list, vec!["a@example.com", "not an address", "b@example.com"]);
        assert_eq!(errors, vec!["A@example.com: Duplicate entry".to_string(), "not an address: Address contains invalid characters".to_string()]);
    }
}
