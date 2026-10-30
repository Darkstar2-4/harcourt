//! A posted form: Gravity Forms' validation, the reCAPTCHA gate, the spam checks, the stored entry, the
//! notifications, and the confirmation.

use std::collections::HashMap;

use chrono::DateTime;
use sqlx::SqlitePool;

use super::{
    model::{Confirmation, Field, Form, Honeypot, Kind, Notification, Recipient},
    render::{self, FieldErrors, Render, Values},
    spam::Submission,
    Services, RECAPTCHA_FORMS,
};
use crate::{
    identity::Email,
    orders::{
        html::esc,
        mail::{self, Body, Kind as Copy, Mail, Mailbox},
        mailform::Registry,
    },
};

pub const REQUIRED: &str = "This field is required.";
pub const BAD_EMAIL: &str = "The email address entered is invalid, please check the formatting (e.g. email@domain.com).";
pub const BAD_PHONE: &str = "Phone format: (###) ###-####";
pub const BAD_DATE: &str = "Please enter a valid date.";
pub const BLOCKED: &str = "<div class='validation_error'>We were unable to verify your submission. Please try again, or contact us directly at <a href='mailto:sales@harcourt.co'>sales@harcourt.co</a> if the issue continues.</div>";

/// Where the post came from and who sent it.
pub struct Context<'a> {
    pub page_uri: &'a str,
    pub page_url: String,
    pub page_title: Option<String>,
    pub ip: &'a str,
    pub user_agent: &'a str,
    pub embed: super::render::Embed,
    pub now: i64,
}

#[derive(Debug, PartialEq, Eq)]
pub enum Outcome {
    /// The form again, with the visitor's input and the errors.
    Invalid(String),
    /// The confirmation. `entry` is None when nothing was kept (honeypot abort).
    Confirmed { html: String, entry: Option<i64>, spam: bool },
    Redirect(String),
}

/// The values a field posts, by input name.
fn read(form: &Form, params: &HashMap<String, String>) -> Values {
    let mut v = Values::new();
    for f in form.fields.iter().filter(|f| f.takes_input()) {
        match &f.kind {
            Kind::Address { inputs, .. } => {
                for sub in inputs.iter().filter(|s| !s.hidden) {
                    let name = format!("input_{}", sub.id);
                    let typed = params.get(&name).or_else(|| params.get(&name.replace('.', "_")));
                    v.insert(name, typed.map_or(String::new(), |s| s.trim().to_string()));
                }
            }
            _ => {
                let name = format!("input_{}", f.id);
                v.insert(name.clone(), params.get(&name).map_or(String::new(), |s| s.trim().to_string()));
            }
        }
    }
    v
}

fn value(values: &Values, f: &Field) -> String {
    match &f.kind {
        Kind::Address { inputs, .. } => inputs.iter().filter_map(|s| values.get(&format!("input_{}", s.id))).filter(|s| !s.is_empty()).cloned().collect::<Vec<_>>().join(", "),
        _ => values.get(&format!("input_{}", f.id)).cloned().unwrap_or_default(),
    }
}

fn valid_date(s: &str, dmy: bool) -> bool {
    chrono::NaiveDate::parse_from_str(s, if dmy { "%d/%m/%Y" } else { "%m/%d/%Y" }).is_ok()
}

/// GFFormDisplay::validate for the field types these forms use.
pub fn validate(form: &Form, values: &Values) -> FieldErrors {
    let mut errors = FieldErrors::new();
    for f in form.fields.iter().filter(|f| f.takes_input()) {
        let v = value(values, f);
        let message = match &f.kind {
            _ if v.is_empty() && f.required => Some(REQUIRED),
            _ if v.is_empty() => None,
            Kind::Email if Email::parse(&v).is_none() => Some(BAD_EMAIL),
            Kind::Phone(format) if !render::phone_ok(*format, &v) => Some(BAD_PHONE),
            Kind::Date { dmy } if !valid_date(&v, *dmy) => Some(BAD_DATE),
            _ => None,
        };
        if let Some(m) = message {
            errors.insert(f.id, m.to_string());
        }
    }
    errors
}

fn first_of(form: &Form, values: &Values, pick: impl Fn(&Field) -> bool) -> String {
    form.fields.iter().find(|f| pick(f)).map(|f| value(values, f)).unwrap_or_default()
}

/// The Akismet fields the add-on sent: the name, email and the free text.
fn submission(form: &Form, values: &Values, ctx: &Context<'_>) -> Submission {
    let content: Vec<String> = form.fields.iter().filter(|f| matches!(f.kind, Kind::Textarea)).map(|f| value(values, f)).filter(|v| !v.is_empty()).collect();
    Submission {
        ip: ctx.ip.to_string(),
        user_agent: ctx.user_agent.to_string(),
        referrer: ctx.page_url.clone(),
        permalink: ctx.page_url.clone(),
        author: first_of(form, values, |f| matches!(f.kind, Kind::Text) && f.label == "Name"),
        email: first_of(form, values, |f| matches!(f.kind, Kind::Email)),
        content: content.join("\n"),
    }
}

/// The registry reCAPTCHA gate on the contact forms. True when the post may go on.
async fn recaptcha_passes(pool: &SqlitePool, services: &Services, form: &Form, values: &Values, params: &HashMap<String, String>, ip: &str, now: i64) -> sqlx::Result<bool> {
    let Some(verifier) = services.recaptcha.as_ref().filter(|_| RECAPTCHA_FORMS.contains(&form.id)) else { return Ok(true) };
    let threshold = Registry::load(pool, "recaptcha").await?.get("score_spam").trim().parse::<f64>().ok();
    let token = params.get("registry_recaptcha_response").map_or("", String::as_str);
    let score = verifier.score(token, ip, "contact").await;
    let low = match (score, threshold) {
        (None, _) => true,
        (Some(s), Some(t)) => s <= t,
        (Some(_), None) => false,
    };
    if !low {
        return Ok(true);
    }
    let email = first_of(form, values, |f| matches!(f.kind, Kind::Email)).trim().to_lowercase();
    let bypassed = !email.is_empty() && sqlx::query_scalar!(r#"SELECT id AS "id!: i64" FROM captcha_bypass WHERE email = ?1 LIMIT 1"#, email).fetch_optional(pool).await?.is_some();
    if bypassed {
        return Ok(true);
    }
    let shown = score.map_or("unavailable".to_string(), |s| s.to_string());
    let comment = format!("reCAPTCHA score {shown} at or below spam threshold {} ({})", threshold.map_or(String::new(), |t| t.to_string()), form.title);
    sqlx::query!(
        "INSERT INTO log (ip, date_time, user_id, bot_id, email, type, subtype, sku, subcategory_id, comment) VALUES (?1, ?2, 0, 0, ?3, 'Recaptcha', 'Blocked', '', 0, ?4)",
        ip,
        now,
        email,
        comment
    )
    .execute(pool)
    .await?;
    Ok(false)
}

fn utc(now: i64) -> String {
    DateTime::from_timestamp(now, 0).unwrap_or_default().format("%Y-%m-%d %H:%M:%S").to_string()
}

async fn store(pool: &SqlitePool, form: &Form, values: &Values, ctx: &Context<'_>, spam: bool) -> sqlx::Result<i64> {
    let created = utc(ctx.now);
    let status = if spam { "spam" } else { "active" };
    let source: String = ctx.page_url.chars().take(200).collect();
    let agent: String = ctx.user_agent.chars().take(250).collect();
    let mut tx = pool.begin().await?;
    let id = sqlx::query_scalar!(
        r#"INSERT INTO wp_gf_entry (form_id, date_created, date_updated, is_starred, is_read, ip, source_url, user_agent, currency, status)
           VALUES (?1, ?2, ?2, 0, 0, ?3, ?4, ?5, 'USD', ?6) RETURNING id AS "id!: i64""#,
        form.id,
        created,
        ctx.ip,
        source,
        agent,
        status
    )
    .fetch_one(&mut *tx)
    .await?;
    for (name, v) in values.iter().filter(|(_, v)| !v.is_empty()) {
        let key = name.trim_start_matches("input_");
        sqlx::query!("INSERT INTO wp_gf_entry_meta (form_id, entry_id, meta_key, meta_value, item_index) VALUES (?1, ?2, ?3, ?4, '')", form.id, id, key, v)
            .execute(&mut *tx)
            .await?;
    }
    tx.commit().await?;
    Ok(id)
}

/// `{all_fields}` as Gravity Forms writes it in an HTML notification.
fn all_fields(form: &Form, values: &Values) -> String {
    let font = "<font style=\"font-family: sans-serif; font-size:12px;\">";
    let mut out = String::from("<table width=\"99%\" border=\"0\" cellpadding=\"1\" cellspacing=\"0\" bgcolor=\"#EAEAEA\"><tr><td>\n<table width=\"100%\" border=\"0\" cellpadding=\"5\" cellspacing=\"0\" bgcolor=\"#FFFFFF\">\n");
    for f in form.fields.iter().filter(|f| f.takes_input()) {
        let v = value(values, f);
        if v.is_empty() {
            continue;
        }
        let shown = esc(&v).replace('\n', "<br />\n");
        out.push_str(&format!(
            "<tr bgcolor=\"#EAF2FA\">\n<td colspan=\"2\">\n{font}<strong>{}</strong></font>\n</td>\n</tr>\n<tr bgcolor=\"#FFFFFF\">\n<td width=\"20\">&nbsp;</td>\n<td>\n{font}{shown}</font>\n</td>\n</tr>\n",
            esc(&f.label)
        ));
    }
    out.push_str("</table>\n</td>\n</tr>\n</table>");
    out
}

/// Fills `{Label:ID}`, `{:ID}`, `{form_title}`, `{admin_email}`, `{entry_id}`, `{embed_post:post_title}`,
/// `{embed_url}`, `{ip}`, `{date_mdy}` and `{all_fields}`. In HTML every value is escaped.
fn merge(template: &str, html: bool, form: &Form, values: &Values, ctx: &Context<'_>, entry: i64, admin: &str) -> String {
    let mut out = String::new();
    let mut rest = template;
    while let Some(start) = rest.find('{') {
        out.push_str(&rest[..start]);
        let after = &rest[start + 1..];
        let Some(end) = after.find('}') else {
            out.push('{');
            rest = after;
            continue;
        };
        let tag = &after[..end];
        let filled = match tag {
            "all_fields" if html => Some(all_fields(form, values)),
            "all_fields" => Some(form.fields.iter().filter(|f| f.takes_input()).map(|f| format!("{}: {}", f.label, value(values, f))).collect::<Vec<_>>().join("\n")),
            "form_title" => Some(form.title.clone()),
            "admin_email" => Some(admin.to_string()),
            "entry_id" => Some(entry.to_string()),
            "embed_post:post_title" => Some(ctx.page_title.clone().unwrap_or_else(|| ctx.page_uri.to_string())),
            "embed_url" => Some(ctx.page_url.clone()),
            "ip" => Some(ctx.ip.to_string()),
            "date_mdy" => Some(DateTime::from_timestamp(ctx.now, 0).unwrap_or_default().with_timezone(&chrono_tz::America::Detroit).format("%m/%d/%Y").to_string()),
            _ => tag.rsplit_once(':').and_then(|(_, id)| id.parse::<i64>().ok()).and_then(|id| form.fields.iter().find(|f| f.id == id)).map(|f| value(values, f)),
        };
        match filled {
            Some(v) if html && tag != "all_fields" => out.push_str(&esc(&v)),
            Some(v) => out.push_str(&v),
            None => {
                out.push('{');
                out.push_str(tag);
                out.push('}');
            }
        }
        rest = &after[end + 1..];
    }
    out.push_str(rest);
    out
}

fn addresses(list: &str, admin: Option<&Email>) -> Vec<Email> {
    list.split([',', ';'])
        .map(str::trim)
        .filter_map(|a| if a == "{admin_email}" { admin.cloned() } else { Email::parse(a) })
        .collect()
}

fn notification(n: &Notification, form: &Form, values: &Values, ctx: &Context<'_>, entry: i64, admin: Option<&Email>) -> Option<Mail> {
    let admin_text = admin.map_or("", Email::as_str);
    let to = match &n.to {
        Recipient::Listed(list) => addresses(list, admin),
        Recipient::Field(id) => form.fields.iter().find(|f| f.id == *id).and_then(|f| Email::parse(&value(values, f))).into_iter().collect(),
    };
    let mut to = to.into_iter();
    let first = to.next()?;
    let subject = merge(&n.subject, false, form, values, ctx, entry, admin_text);
    let template = n.message.replace("\r\n", "\n").replace('\n', "<br />\n");
    let template = template.replace("{all_fields}<br />", "{all_fields}");
    let body = merge(&template, true, form, values, ctx, entry, admin_text);
    let mut mail = Mail::new(Mailbox::bare(first), &subject, Body::Html(body));
    for more in to {
        mail.add(Copy::To, Mailbox::bare(more));
    }
    for b in addresses(&n.bcc, admin) {
        mail.add(Copy::Bcc, Mailbox::bare(b));
    }
    mail.reply_to = Email::parse(&merge(&n.reply_to, false, form, values, ctx, entry, admin_text));
    let from_name = merge(&n.from_name, false, form, values, ctx, entry, admin_text);
    mail.from_name = (!from_name.trim().is_empty()).then_some(from_name);
    Some(mail)
}

/// Handles one post of `form`.
pub async fn submit(pool: &SqlitePool, services: &Services, form: &Form, params: &HashMap<String, String>, ctx: &Context<'_>) -> sqlx::Result<Outcome> {
    let values = read(form, params);
    let errors = validate(form, &values);
    let render_invalid = |errors: &FieldErrors, summary: &str| {
        let token = services.token_key.as_ref().map(|k| k.issue(form.id, ctx.now));
        render::form(&Render {
            form,
            page_uri: ctx.page_uri,
            embed: &ctx.embed,
            values: &values,
            errors,
            summary: Some(summary),
            token: token.as_deref(),
            recaptcha: RECAPTCHA_FORMS.contains(&form.id),
            browser: &render::browser_class(ctx.user_agent),
        })
    };
    if !errors.is_empty() {
        return Ok(Outcome::Invalid(render_invalid(&errors, &render::summary(form.id))));
    }
    if !recaptcha_passes(pool, services, form, &values, params, ctx.ip, ctx.now).await? {
        return Ok(Outcome::Invalid(render_invalid(&FieldErrors::new(), BLOCKED)));
    }
    let confirmation = match &form.confirmation {
        Confirmation::Message(m) => render::confirmation(form.id, m),
        Confirmation::Redirect(url) => String::from(url.as_str()),
    };
    let honeypot_filled = params.get(&format!("input_{}", render::honeypot_id(form))).is_some_and(|v| !v.is_empty());
    if honeypot_filled && form.honeypot == Honeypot::Abort {
        return Ok(Outcome::Confirmed { html: confirmation, entry: None, spam: true });
    }
    let mut spam = honeypot_filled && form.honeypot == Honeypot::Spam;
    if !spam {
        if let Some(key) = &services.token_key {
            spam = !params.get(&format!("state_{}", form.id)).is_some_and(|t| key.valid(t, form.id, ctx.now));
        }
    }
    if !spam {
        if let Some(akismet) = &services.akismet {
            spam = akismet.is_spam(&submission(form, &values, ctx)).await;
        }
    }
    let entry = store(pool, form, &values, ctx, spam).await?;
    if !spam {
        for n in &form.notifications {
            if let Some(m) = notification(n, form, &values, ctx, entry, services.admin_email.as_ref()) {
                mail::enqueue(pool, &m, ctx.now).await?;
            }
        }
    }
    Ok(match &form.confirmation {
        Confirmation::Redirect(url) => Outcome::Redirect(url.clone()),
        Confirmation::Message(_) => Outcome::Confirmed { html: confirmation, entry: Some(entry), spam },
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::orders::forms::model::{parse, tests::*};

    fn ctx() -> Context<'static> {
        Context { page_uri: "/contact-us/", page_url: "https://www.example.com/contact-us/".into(), page_title: Some("Contact Us".into()), ip: "192.0.2.1", user_agent: "test", embed: super::super::render::Embed::default_for(3), now: 1_700_000_000 }
    }

    fn values(pairs: &[(&str, &str)]) -> Values {
        pairs.iter().map(|(k, v)| (k.to_string(), v.to_string())).collect()
    }

    #[test]
    fn validation_follows_gravity_forms() {
        let f = parse(META, NOTIFICATIONS, CONFIRMATIONS).unwrap();
        let e = validate(&f, &values(&[("input_1", "Pat"), ("input_10", "not-an-address"), ("input_9", "123"), ("input_4", "")]));
        assert_eq!(e.get(&10).map(String::as_str), Some(BAD_EMAIL));
        assert_eq!(e.get(&9).map(String::as_str), Some(BAD_PHONE));
        assert_eq!(e.get(&4).map(String::as_str), Some(REQUIRED));
        assert!(!e.contains_key(&1));
        let ok = validate(&f, &values(&[("input_1", "Pat"), ("input_10", "pat@example.com"), ("input_9", "(555) 010-0100"), ("input_4", "Hi")]));
        assert!(ok.is_empty());
    }

    #[test]
    fn merge_tags_fill_and_escape() {
        let f = parse(META, NOTIFICATIONS, CONFIRMATIONS).unwrap();
        let v = values(&[("input_1", "Pat <Buyer>"), ("input_10", "pat@example.com"), ("input_4", "line 1\nline 2")]);
        let c = ctx();
        assert_eq!(merge("New submission from {form_title} by {Name:1} {unknown}", false, &f, &v, &c, 5, ""), "New submission from Contact by Pat <Buyer> {unknown}");
        let html = merge("Doc: {embed_post:post_title} {Name:1}\n{all_fields}", true, &f, &v, &c, 5, "");
        assert!(html.starts_with("Doc: Contact Us Pat &lt;Buyer&gt;\n<table"));
        assert!(html.contains("<strong>Message</strong>") && html.contains("line 1<br />\nline 2"));
        assert!(!html.contains("<strong>Phone Number</strong>"), "empty fields are left out");
    }

    #[test]
    fn notifications_resolve_recipients() {
        let f = parse(META, NOTIFICATIONS, CONFIRMATIONS).unwrap();
        let v = values(&[("input_1", "Pat"), ("input_10", "pat@example.com")]);
        let admin = Email::parse("web@example.com");
        let m = notification(&f.notifications[0], &f, &v, &ctx(), 5, admin.as_ref()).unwrap();
        assert_eq!(m.to.email.as_str(), "web@example.com");
        assert_eq!(m.also, vec![(Copy::To, Mailbox::bare(Email::parse("desk@example.com").unwrap()))]);
        assert_eq!((m.subject.as_str(), m.from_name.as_deref(), m.reply_to.as_ref().map(Email::as_str)), ("New submission from Contact", Some("Pat"), Some("pat@example.com")));
        let without_admin = notification(&f.notifications[0], &f, &v, &ctx(), 5, None).unwrap();
        assert_eq!(without_admin.to.email.as_str(), "desk@example.com");
    }
}
