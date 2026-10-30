//! A Gravity Forms definition, parsed once from `wp_gf_form_meta` JSON into the shape the forms on this site use.
//! A field type this module does not know is kept as `Kind::Unsupported` and renders nothing.

use serde_json::Value;
use sqlx::SqlitePool;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Lang {
    En,
    Fr,
}

impl Lang {
    pub fn parse(s: &str) -> Lang {
        if s.eq_ignore_ascii_case("fr") { Lang::Fr } else { Lang::En }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Honeypot {
    Off,
    /// Show the confirmation and keep nothing.
    Abort,
    /// Keep the entry as spam.
    Spam,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum PhoneFormat {
    Standard,
    International,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct SubInput {
    pub id: String,
    pub label: String,
    pub hidden: bool,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Kind {
    Text,
    Email,
    Phone(PhoneFormat),
    Textarea,
    Date,
    Address(Vec<SubInput>),
    Html(String),
    Unsupported(String),
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Field {
    pub id: i64,
    pub label: String,
    pub kind: Kind,
    pub required: bool,
    pub css_class: String,
    pub size: String,
    pub placeholder: String,
}

impl Field {
    pub fn type_name(&self) -> &str {
        match &self.kind {
            Kind::Text => "text",
            Kind::Email => "email",
            Kind::Phone(_) => "phone",
            Kind::Textarea => "textarea",
            Kind::Date => "date",
            Kind::Address(_) => "address",
            Kind::Html(_) => "html",
            Kind::Unsupported(t) => t,
        }
    }

    /// Whether a visitor types into it (an HTML block does not).
    pub fn takes_input(&self) -> bool {
        !matches!(self.kind, Kind::Html(_) | Kind::Unsupported(_))
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Recipient {
    /// Addresses as written in the definition; `{admin_email}` is resolved when sending.
    Listed(String),
    /// The value the visitor typed into this field.
    Field(i64),
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Notification {
    pub name: String,
    pub to: Recipient,
    pub bcc: String,
    pub from_name: String,
    pub reply_to: String,
    pub subject: String,
    pub message: String,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Confirmation {
    Message(String),
    Redirect(String),
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Form {
    pub id: i64,
    pub title: String,
    pub button: String,
    pub css_class: String,
    /// `requiredIndicator` is `asterisk`; otherwise Gravity Forms writes "(Required)".
    pub asterisk: bool,
    pub honeypot: Honeypot,
    pub fields: Vec<Field>,
    pub notifications: Vec<Notification>,
    pub confirmation: Confirmation,
}

fn text(v: &Value, key: &str) -> String {
    match v.get(key) {
        Some(Value::String(s)) => s.clone(),
        Some(Value::Number(n)) => n.to_string(),
        _ => String::new(),
    }
}

fn flag(v: &Value, key: &str) -> bool {
    match v.get(key) {
        Some(Value::Bool(b)) => *b,
        Some(Value::Number(n)) => n.as_i64().unwrap_or(0) != 0,
        Some(Value::String(s)) => s == "1" || s == "true",
        _ => false,
    }
}

fn id(v: &Value) -> Option<i64> {
    match v.get("id") {
        Some(Value::Number(n)) => n.as_i64(),
        Some(Value::String(s)) => s.parse().ok(),
        _ => None,
    }
}

fn field(v: &Value) -> Option<Field> {
    let kind = match text(v, "type").as_str() {
        "text" => Kind::Text,
        "email" => Kind::Email,
        "phone" => Kind::Phone(if text(v, "phoneFormat") == "standard" { PhoneFormat::Standard } else { PhoneFormat::International }),
        "textarea" => Kind::Textarea,
        "date" => Kind::Date,
        "html" => Kind::Html(text(v, "content")),
        "address" => Kind::Address(
            v.get("inputs")
                .and_then(Value::as_array)
                .map(|inputs| inputs.iter().map(|i| SubInput { id: text(i, "id"), label: text(i, "label"), hidden: flag(i, "isHidden") }).collect())
                .unwrap_or_default(),
        ),
        other => Kind::Unsupported(other.to_string()),
    };
    Some(Field {
        id: id(v)?,
        label: text(v, "label"),
        kind,
        required: flag(v, "isRequired"),
        css_class: text(v, "cssClass"),
        size: text(v, "size"),
        placeholder: text(v, "placeholder"),
    })
}

/// The values of a JSON object keyed by id, or of an array, in order.
fn members(v: &Value) -> Vec<&Value> {
    match v {
        Value::Object(m) => m.values().collect(),
        Value::Array(a) => a.iter().collect(),
        _ => Vec::new(),
    }
}

/// Parses the three JSON columns. None when `display_meta` is not a form.
pub fn parse(display_meta: &str, notifications: &str, confirmations: &str) -> Option<Form> {
    let d: Value = serde_json::from_str(display_meta).ok()?;
    let fields = d.get("fields")?.as_array()?.iter().filter_map(field).collect();
    let honeypot = match (flag(&d, "enableHoneypot"), text(&d, "honeypotAction").as_str()) {
        (false, _) => Honeypot::Off,
        (true, "spam") => Honeypot::Spam,
        (true, _) => Honeypot::Abort,
    };
    let n: Value = serde_json::from_str(notifications).unwrap_or(Value::Null);
    let notifications = members(&n)
        .into_iter()
        .filter(|v| v.get("isActive").is_none_or(|a| a.as_bool().unwrap_or(true)) && text(v, "event") == "form_submission")
        .map(|v| Notification {
            name: text(v, "name"),
            to: match text(v, "toType").as_str() {
                "field" => Recipient::Field(text(v, "to").parse().unwrap_or(0)),
                _ => Recipient::Listed(text(v, "to")),
            },
            bcc: text(v, "bcc"),
            from_name: text(v, "fromName"),
            reply_to: text(v, "replyTo"),
            subject: text(v, "subject"),
            message: text(v, "message"),
        })
        .collect();
    let c: Value = serde_json::from_str(confirmations).unwrap_or(Value::Null);
    let all = members(&c);
    let chosen = all.iter().find(|v| flag(v, "isDefault")).or(all.first());
    let confirmation = match chosen {
        Some(v) if text(v, "type") == "redirect" => Confirmation::Redirect(text(v, "url")),
        Some(v) => Confirmation::Message(text(v, "message")),
        None => Confirmation::Message("Thanks for contacting us! We will get in touch with you shortly.".into()),
    };
    let button = d.get("button").map(|b| text(b, "text")).filter(|t| !t.is_empty()).unwrap_or_else(|| "Submit".into());
    let asterisk = text(&d, "requiredIndicator") == "asterisk";
    Some(Form { id: id(&d)?, title: text(&d, "title"), button, css_class: text(&d, "cssClass"), asterisk, honeypot, fields, notifications, confirmation })
}

/// An active, untrashed form by id.
pub async fn load(pool: &SqlitePool, form_id: i64) -> sqlx::Result<Option<Form>> {
    let row = sqlx::query!(
        r#"SELECT coalesce(m.display_meta, '') AS "d!: String", coalesce(m.notifications, '') AS "n!: String",
                  coalesce(m.confirmations, '') AS "c!: String", f.title AS "title!: String"
           FROM wp_gf_form f JOIN wp_gf_form_meta m ON m.form_id = f.id
           WHERE f.id = ?1 AND f.is_active = 1 AND f.is_trash = 0"#,
        form_id
    )
    .fetch_optional(pool)
    .await?;
    Ok(row.and_then(|r| parse(&r.d, &r.n, &r.c).map(|mut f| {
        f.title = r.title;
        f
    })))
}

const RECAPTCHA_FR: &str = "Ce site est protégé par reCAPTCHA. Les <a href=\"https://policies.google.com/privacy?hl=fr\" target=\"_blank\">Règles de confidentialité</a> et les <a href=\"https://policies.google.com/terms?hl=fr\" target=\"_blank\">Conditions d'utilisation</a> de Google s'appliquent.";

/// The theme's `functions/gf-fr.php`: French labels, button and reCAPTCHA notice, international phone numbers.
pub fn localize(mut form: Form, lang: Lang) -> Form {
    if lang == Lang::En {
        return form;
    }
    const MAP: [(&str, &str); 9] = [
        ("Name", "Nom"),
        ("Email", "E-mail"),
        ("Company", "Société"),
        ("Phone", "Téléphone"),
        ("Phone Number", "Téléphone"),
        ("Message", "Message"),
        ("Title", "Fonction"),
        ("Suggested Topic", "Sujet proposé"),
        ("Suggested Date", "Date proposée"),
    ];
    if form.button == "Submit" {
        form.button = "Envoyer".into();
    }
    for f in &mut form.fields {
        if let Some((_, fr)) = MAP.iter().find(|(en, _)| *en == f.label) {
            f.label = fr.to_string();
        }
        match &mut f.kind {
            Kind::Html(content) if content.contains("protected by reCAPTCHA") => *content = RECAPTCHA_FR.into(),
            Kind::Phone(format) => *format = PhoneFormat::International,
            _ => {}
        }
    }
    form
}

#[cfg(test)]
pub mod tests {
    use super::*;

    pub const META: &str = r#"{"id":3,"title":"Contact","button":{"type":"text","text":"Submit"},"cssClass":"","enableHoneypot":true,"honeypotAction":"spam",
        "fields":[{"type":"text","id":1,"label":"Name","isRequired":true,"cssClass":"gf_left_half","size":"large","placeholder":""},
                  {"type":"email","id":10,"label":"Email","isRequired":true,"size":"medium"},
                  {"type":"phone","id":9,"label":"Phone Number","isRequired":true,"phoneFormat":"standard","size":"large"},
                  {"type":"textarea","id":4,"label":"Message","isRequired":true,"size":"large"},
                  {"type":"html","id":13,"label":"HTML Block","content":"This site is protected by reCAPTCHA and the Google policies apply."},
                  {"type":"list","id":20,"label":"Odd"}]}"#;
    pub const NOTIFICATIONS: &str = r#"{"a1":{"id":"a1","name":"Admin Notification","isActive":true,"event":"form_submission","toType":"email",
        "to":"{admin_email}, desk@example.com","bcc":"","fromName":"{Name:1}","replyTo":"{Email:10}","subject":"New submission from {form_title}","message":"{all_fields}"},
        "b2":{"id":"b2","name":"Thank You","isActive":false,"event":"form_submission","toType":"field","to":"10"}}"#;
    pub const CONFIRMATIONS: &str = r#"{"c1":{"id":"c1","name":"Default Confirmation","isDefault":true,"type":"message","message":"<strong>Thank you!</strong> We will reply."}}"#;

    #[test]
    fn a_definition_parses_into_typed_fields() {
        let f = parse(META, NOTIFICATIONS, CONFIRMATIONS).unwrap();
        assert_eq!((f.id, f.honeypot, f.fields.len()), (3, Honeypot::Spam, 6));
        assert_eq!(f.fields[2].kind, Kind::Phone(PhoneFormat::Standard));
        assert_eq!(f.fields[5].kind, Kind::Unsupported("list".into()));
        assert_eq!(f.notifications.len(), 1, "inactive notifications are dropped");
        assert_eq!(f.notifications[0].to, Recipient::Listed("{admin_email}, desk@example.com".into()));
        assert_eq!(f.confirmation, Confirmation::Message("<strong>Thank you!</strong> We will reply.".into()));
        assert!(parse("not json", "", "").is_none());
    }

    #[test]
    fn french_follows_the_theme_hook() {
        let f = localize(parse(META, NOTIFICATIONS, CONFIRMATIONS).unwrap(), Lang::Fr);
        assert_eq!((f.button.as_str(), f.fields[0].label.as_str(), f.fields[2].label.as_str()), ("Envoyer", "Nom", "Téléphone"));
        assert_eq!(f.fields[2].kind, Kind::Phone(PhoneFormat::International));
        assert!(matches!(&f.fields[4].kind, Kind::Html(c) if c.starts_with("Ce site est protégé")));
    }
}
