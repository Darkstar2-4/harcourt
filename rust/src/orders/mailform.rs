//! Mail templates. A `mailform` row (classes/mailform.php) names a mail the site sends: who gets it, who is copied,
//! the subject and the HTML around the body. scsmail_rev1.php fills `%name%` tags in the subject and the header
//! and footer HTML. Here a value placed into HTML is escaped, and a tag is filled once, so text a visitor typed
//! cannot turn into markup or into another tag.

use std::{collections::HashMap, path::Path};

use chrono::DateTime;
use chrono_tz::America::Detroit;
use sqlx::SqlitePool;

use super::{
    html::esc,
    mail::{Body, Kind, Mail, Mailbox},
};
use crate::identity::Email;

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Template {
    pub code: String,
    pub name: String,
    pub recipient: Option<Email>,
    pub bcc: Vec<Email>,
    pub css: String,
    pub subject: String,
    pub header_html: String,
    pub footer_html: String,
}

/// The first template with this code, as `mailform->read($code, "code")` does. Inactive rows still send, as in PHP.
pub async fn load(pool: &SqlitePool, code: &str) -> sqlx::Result<Option<Template>> {
    let row = sqlx::query!(
        r#"SELECT coalesce(code, '') AS "code!: String", coalesce(name, '') AS "name!: String", recipient, bcc,
                  coalesce(css, '') AS "css!: String", coalesce(subject, '') AS "subject!: String",
                  coalesce(header_html, '') AS "header_html!: String", coalesce(footer_html, '') AS "footer_html!: String"
           FROM mailform WHERE code = ?1 ORDER BY id LIMIT 1"#,
        code
    )
    .fetch_optional(pool)
    .await?;
    Ok(row.map(|r| Template {
        code: r.code,
        name: r.name,
        recipient: Email::parse(&r.recipient),
        bcc: parse_bcc(r.bcc.as_deref().unwrap_or("")),
        css: r.css,
        subject: r.subject,
        header_html: r.header_html,
        footer_html: r.footer_html,
    }))
}

/// `bcc` is a JSON array of addresses. Anything else, or an entry that is not an address, contributes nothing.
fn parse_bcc(json: &str) -> Vec<Email> {
    serde_json::from_str::<Vec<String>>(json).unwrap_or_default().iter().filter_map(|s| Email::parse(s)).collect()
}

/// Values for `%tag%` placeholders, matched without regard to case.
#[derive(Debug, Clone, Default)]
pub struct Merge(HashMap<String, String>);

impl Merge {
    pub fn set(&mut self, tag: &str, value: impl Into<String>) -> &mut Self {
        self.0.insert(tag.to_ascii_lowercase(), value.into());
        self
    }

    /// The `scs_*` tags scsmail_rev1.php defines, in the site's time zone.
    pub fn standard(now: i64, ip: &str, host: &str, user_id: i64, user_name: &str, company: &Registry) -> Self {
        let t = DateTime::from_timestamp(now, 0).unwrap_or_default().with_timezone(&Detroit);
        let mut m = Merge::default();
        m.set("scs_date", t.format("%B %-d, %Y").to_string())
            .set("scs_time", t.format("%-I:%M %p").to_string())
            .set("scs_datetime", t.format("%B %-d, %Y %-I:%M %p").to_string())
            .set("scs_mdy", t.format("%m/%d/%Y").to_string())
            .set("scs_hhmm", t.format("%H:%M").to_string())
            .set("scs_ip4", ip)
            .set("scs_host", host)
            .set("scs_user_id", user_id.to_string())
            .set("scs_user_name", user_name);
        for field in ["name", "address", "city_state_zip", "phone", "fax"] {
            m.set(&format!("scs_company_{field}"), company.get(field));
        }
        m
    }

    fn fill(&self, template: &str, escape: bool) -> String {
        let mut out = String::with_capacity(template.len());
        let mut rest = template;
        while let Some(start) = rest.find('%') {
            out.push_str(&rest[..start]);
            let after = &rest[start + 1..];
            let value = after.find('%').and_then(|end| {
                let tag = &after[..end];
                let ok = !tag.is_empty() && tag.bytes().all(|c| c.is_ascii_alphanumeric() || c == b'_');
                ok.then(|| self.0.get(&tag.to_ascii_lowercase()).map(|v| (v, end))).flatten()
            });
            match value {
                Some((v, end)) => {
                    out.push_str(&if escape { esc(v) } else { v.clone() });
                    rest = &after[end + 1..];
                }
                None => {
                    out.push('%');
                    rest = after;
                }
            }
        }
        out.push_str(rest);
        out
    }

    pub fn text(&self, template: &str) -> String {
        self.fill(template, false)
    }

    pub fn html(&self, template: &str) -> String {
        self.fill(template, true)
    }
}

/// Site-wide settings from the `registry` table for one module (`company`, `email`, ...).
#[derive(Debug, Clone, Default)]
pub struct Registry(HashMap<String, String>);

impl Registry {
    pub async fn load(pool: &SqlitePool, module: &str) -> sqlx::Result<Self> {
        let rows = sqlx::query!(
            r#"SELECT field AS "field!", coalesce(data, '') AS "data!: String" FROM registry WHERE domain = '' AND module = ?1 AND item = ''"#,
            module
        )
        .fetch_all(pool)
        .await?;
        Ok(Registry(rows.into_iter().map(|r| (r.field, r.data)).collect()))
    }

    pub fn get(&self, field: &str) -> &str {
        self.0.get(field).map_or("", String::as_str)
    }

    pub fn from_pairs(pairs: &[(&str, &str)]) -> Self {
        Registry(pairs.iter().map(|(k, v)| (k.to_string(), v.to_string())).collect())
    }
}

/// What every HTML mail carries around its body: the base href and the stylesheet from the registry's `email`
/// module, or the template's own stylesheet.
#[derive(Debug, Clone, Default)]
pub struct Letterhead {
    pub base_href: String,
    pub css: String,
}

impl Letterhead {
    /// The stylesheet is a file under the document root, read when the mail is composed, as PHP did.
    pub async fn load(pool: &SqlitePool, docroot: &Path, template: &Template) -> sqlx::Result<Self> {
        let email = Registry::load(pool, "email").await?;
        let css_path = if template.css.is_empty() { email.get("css") } else { template.css.as_str() };
        let css = match safe_relative(css_path) {
            Some(rel) => tokio::fs::read_to_string(docroot.join(rel)).await.unwrap_or_default(),
            None => String::new(),
        };
        Ok(Letterhead { base_href: email.get("base_href").to_string(), css })
    }
}

fn safe_relative(path: &str) -> Option<&str> {
    let rel = path.trim_start_matches('/');
    let ok = !rel.is_empty() && rel.split('/').all(|part| !part.is_empty() && part != "." && part != "..");
    ok.then_some(rel)
}

impl Template {
    /// The mail scsmail_rev1.php would send: the template's subject (or `subject`), the body inside the letterhead,
    /// To the given address or else the template's recipient, Bcc the template's list. None when there is nobody
    /// to send it to.
    pub fn compose(&self, letterhead: &Letterhead, merge: &Merge, subject: Option<&str>, to: Option<Mailbox>, body_html: &str) -> Option<Mail> {
        let to = to.or_else(|| self.recipient.clone().map(Mailbox::bare))?;
        let subject = merge.text(subject.unwrap_or(&self.subject));
        let mut mail = Mail::new(to, &subject, Body::Html(self.document(letterhead, merge, body_html)));
        for b in &self.bcc {
            mail.add(Kind::Bcc, Mailbox::bare(b.clone()));
        }
        Some(mail)
    }

    fn document(&self, letterhead: &Letterhead, merge: &Merge, body_html: &str) -> String {
        let mut lines = vec!["<html>".to_string(), "<head>".to_string()];
        if !letterhead.base_href.is_empty() {
            lines.push(format!("<base href='{}'>", esc(&letterhead.base_href)));
        }
        if !letterhead.css.is_empty() {
            lines.push("<style>".into());
            lines.push(letterhead.css.split_inclusive('\n').collect::<Vec<_>>().join("\n"));
            lines.push("</style>".into());
        }
        lines.extend(["</head>".into(), "<body>".into(), "<div id='content'>".into()]);
        if !self.header_html.is_empty() {
            lines.push(merge.html(&self.header_html));
        }
        lines.push(body_html.trim().to_string());
        if !self.footer_html.is_empty() {
            lines.push(merge.html(&self.footer_html));
        }
        lines.extend(["</div>".into(), "</body>".into(), "</html>".into()]);
        lines.join("\n")
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn template() -> Template {
        Template {
            code: "checkout".into(),
            name: "Check Out".into(),
            recipient: Email::parse("orders@example.com"),
            bcc: vec![Email::parse("copy@example.com").unwrap()],
            css: String::new(),
            subject: "PO #%po%".into(),
            header_html: "<p>%Name% ordered</p>".into(),
            footer_html: String::new(),
        }
    }

    #[test]
    fn tags_fill_once_and_escape_in_html() {
        let mut m = Merge::default();
        m.set("po", "<b>%name%</b>").set("name", "A & B");
        assert_eq!(m.text("PO #%PO%"), "PO #<b>%name%</b>");
        assert_eq!(m.html("%po% / %NAME% / %missing% / 100%"), "&lt;b&gt;%name%&lt;/b&gt; / A &amp; B / %missing% / 100%");
    }

    #[test]
    fn compose_wraps_the_body_like_scsmail() {
        let mut m = Merge::default();
        m.set("po", "123").set("name", "Pat");
        let lh = Letterhead { base_href: "https://www.example.com/".into(), css: "a{}\nb{}\n".into() };
        let mail = template().compose(&lh, &m, None, None, "  <table></table> ").unwrap();
        assert_eq!(mail.subject, "PO #123");
        assert_eq!(mail.to.email.as_str(), "orders@example.com");
        assert_eq!(mail.also, vec![(Kind::Bcc, Mailbox::bare(Email::parse("copy@example.com").unwrap()))]);
        let Body::Html(html) = mail.body else { panic!("html body") };
        assert_eq!(
            html,
            "<html>\n<head>\n<base href='https://www.example.com/'>\n<style>\na{}\n\nb{}\n\n</style>\n</head>\n<body>\n<div id='content'>\n<p>Pat ordered</p>\n<table></table>\n</div>\n</body>\n</html>"
        );
    }

    #[test]
    fn an_explicit_recipient_and_subject_win() {
        let to = Mailbox::new(Email::parse("buyer@example.com").unwrap(), "Pat");
        let mail = template().compose(&Letterhead::default(), &Merge::default(), Some("Order Confirmation"), Some(to), "x").unwrap();
        assert_eq!((mail.to.email.as_str(), mail.subject.as_str()), ("buyer@example.com", "Order Confirmation"));
        let mut nobody = template();
        nobody.recipient = None;
        assert!(nobody.compose(&Letterhead::default(), &Merge::default(), None, None, "x").is_none());
    }

    #[test]
    fn bcc_lists_and_css_paths_are_parsed_defensively() {
        assert_eq!(parse_bcc(r#"["a@example.com","junk"]"#).len(), 1);
        assert!(parse_bcc("null").is_empty() && parse_bcc("").is_empty());
        assert_eq!(safe_relative("/css/mail.css"), Some("css/mail.css"));
        assert_eq!(safe_relative("/css/../../etc/passwd"), None);
        assert_eq!(safe_relative(""), None);
    }

    #[test]
    fn standard_tags_use_detroit_time() {
        let m = Merge::standard(1_700_000_000, "192.0.2.1", "www.example.com", 7, "Test Co", &Registry::from_pairs(&[("name", "Example Inc")]));
        assert_eq!(m.text("%scs_date% %scs_time% %scs_mdy% %scs_hhmm%"), "November 14, 2023 5:13 PM 11/14/2023 17:13");
        assert_eq!(m.text("%scs_company_name%|%scs_company_fax%"), "Example Inc|");
    }
}
