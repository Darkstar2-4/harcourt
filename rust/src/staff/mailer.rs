//! mailer_maintain.php (mailer_toolbox::maintain): the email documents in `mailform`.

use sqlx::SqlitePool;

use super::{
    check,
    html::{button, esc, href, js, Form, Notes, Opts},
    maintain::{trimmed, Column, Saved, Table, Widget},
};
use crate::api::Administrator;

#[derive(Default)]
pub struct Mailform {
    code: String,
    name: String,
    subject: String,
    recipient: String,
    bcc: Vec<String>,
    css: String,
    header_html: String,
    footer_html: String,
    active: bool,
}

const SCRIPT: &str = "
<script>
function fn_action(action, record_id, text) {
\tif (text.length > 0) {
        jQuery(\"#delete\" + record_id).prop(\"checked\", false);
\t    if (!confirm(text)) return;
\t}
\tjQuery('#action').val(action);
\tjQuery('#record_id').val(record_id);
\tjQuery('#scs_form').submit();
}
</script>";

fn set_code(m: &mut Mailform, v: &str, n: &mut Notes) {
    m.code = v.trim().to_lowercase();
    if m.code.is_empty() {
        n.error("code", Some("Cannot be blank"));
    }
}

fn set_name(m: &mut Mailform, v: &str, n: &mut Notes) {
    m.name = trimmed(v);
    if m.name.is_empty() {
        n.error("name", Some("Cannot be blank"));
    }
}

fn set_subject(m: &mut Mailform, v: &str, n: &mut Notes) {
    m.subject = trimmed(v);
    if m.subject.is_empty() {
        n.error("subject", None);
    }
}

fn set_recipient(m: &mut Mailform, v: &str, n: &mut Notes) {
    let (address, error) = check::email(v, false);
    m.recipient = address;
    if let Some(e) = error {
        n.error("recipient", Some(e));
    }
}

/// One address per line; blanks and repeats dropped, each checked, none the same as the recipient.
fn set_bcc(m: &mut Mailform, v: &str, n: &mut Notes) {
    m.bcc.clear();
    let mut errors = Vec::new();
    for line in v.split('\n') {
        let (address, error) = check::email(line, false);
        if address.is_empty() || m.bcc.contains(&address) {
            continue;
        }
        match error {
            Some(e) => errors.push(format!("{address}: {e}")),
            None if address == m.recipient => errors.push(format!("{address}: Recipient")),
            None => {}
        }
        m.bcc.push(address);
    }
    if !errors.is_empty() {
        n.error_html("bcc", errors.iter().map(|e| esc(e)).collect::<Vec<_>>().join("<br>"));
    }
}

fn set_css(m: &mut Mailform, v: &str, n: &mut Notes) {
    m.css = trimmed(v);
    if let Some(e) = check::site_file(&m.css, "css") {
        n.error("css", Some(&e));
    }
}

impl Table for Mailform {
    type Role = Administrator;
    const PATH: &'static str = "/mailer_maintain.php";
    const TITLE: &'static str = "Email Documents";
    const VERSION: &'static str = "08/07/2025";
    const FORM_CLASS: &'static str = "standard noborder";
    const LABEL_WIDTHS: (&'static str, &'static str) = ("20%", "80%");
    const COLUMNS: &'static [Column<Self>] = &[
        Column { name: "code", label: "Code", widget: Widget::Text { size: 20, max: 20 }, get: |m| m.code.clone(), set: set_code },
        Column { name: "name", label: "Name", widget: Widget::Text { size: 60, max: 60 }, get: |m| m.name.clone(), set: set_name },
        Column { name: "subject", label: "Subject", widget: Widget::Text { size: 60, max: 60 }, get: |m| m.subject.clone(), set: set_subject },
        Column { name: "recipient", label: "Recipient", widget: Widget::Text { size: 80, max: 0 }, get: |m| m.recipient.clone(), set: set_recipient },
        Column { name: "bcc", label: "BCC", widget: Widget::TextArea { rows: 3, cols: 80 }, get: |m| m.bcc.join("\n"), set: set_bcc },
        Column { name: "css", label: "CSS (optional)", widget: Widget::Text { size: 60, max: 0 }, get: |m| m.css.clone(), set: set_css },
        Column {
            name: "header_html",
            label: "Header HTML",
            widget: Widget::TextArea { rows: 20, cols: 80 },
            get: |m| m.header_html.clone(),
            set: |m, v, _| m.header_html = trimmed(v),
        },
        Column {
            name: "footer_html",
            label: "Footer HTML",
            widget: Widget::TextArea { rows: 20, cols: 80 },
            get: |m| m.footer_html.clone(),
            set: |m, v, _| m.footer_html = trimmed(v),
        },
        Column {
            name: "active",
            label: "Active",
            widget: Widget::Checkbox,
            get: |m| if m.active { "1".into() } else { "0".into() },
            set: |m, v, _| m.active = !matches!(v.trim(), "" | "0"),
        },
    ];

    fn script() -> &'static str {
        SCRIPT
    }

    fn buttons(id: i64) -> String {
        let update = button("Update", Opts::new().onclick(format!("fn_action('update',{},'');", js(&id.to_string()))));
        let cancel = button("Cancel", Opts::new().onclick("fn_action('cancel','0','');"));
        format!("<p>{update} {cancel}</p>")
    }

    fn deleted(&self, notes: &mut Notes) {
        notes.say_html(format!("Document deleted: <b>{} </b>", esc(&self.code)));
    }

    async fn read(pool: &SqlitePool, id: i64) -> sqlx::Result<Option<Self>> {
        let row = sqlx::query!(
            r#"SELECT code, name, subject, recipient, bcc, css, header_html, footer_html, active FROM mailform WHERE id = ?1"#,
            id
        )
        .fetch_optional(pool)
        .await?;
        Ok(row.map(|r| Mailform {
            code: r.code.unwrap_or_default(),
            name: r.name.unwrap_or_default(),
            subject: r.subject.unwrap_or_default(),
            recipient: r.recipient,
            bcc: r.bcc.as_deref().and_then(|b| serde_json::from_str(b).ok()).unwrap_or_default(),
            css: r.css.unwrap_or_default(),
            header_html: r.header_html.unwrap_or_default(),
            footer_html: r.footer_html.unwrap_or_default(),
            active: r.active.unwrap_or(0) != 0,
        }))
    }

    async fn save(&self, pool: &SqlitePool, id: i64) -> sqlx::Result<Saved> {
        let taken = sqlx::query!(
            r#"SELECT code = ?2 AS "same_code!: bool" FROM mailform WHERE id <> ?1 AND domain = '' AND (code = ?2 OR name = ?3) LIMIT 1"#,
            id,
            self.code,
            self.name
        )
        .fetch_optional(pool)
        .await?;
        if let Some(t) = taken {
            return Ok(if t.same_code { Saved::Conflict { field: "code", text: "Duplicate code" } } else { Saved::Conflict { field: "name", text: "Duplicate name" } });
        }
        let bcc = (!self.bcc.is_empty()).then(|| serde_json::to_string(&self.bcc).unwrap_or_default());
        let active = i64::from(self.active);
        if id == 0 {
            sqlx::query!(
                "INSERT INTO mailform (domain, code, name, recipient, bcc, css, subject, header_html, footer_html, active) VALUES ('', ?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9)",
                self.code, self.name, self.recipient, bcc, self.css, self.subject, self.header_html, self.footer_html, active
            )
            .execute(pool)
            .await?;
        } else {
            sqlx::query!(
                "UPDATE mailform SET code = ?2, name = ?3, recipient = ?4, bcc = ?5, css = ?6, subject = ?7, header_html = ?8, footer_html = ?9, active = ?10 WHERE id = ?1",
                id, self.code, self.name, self.recipient, bcc, self.css, self.subject, self.header_html, self.footer_html, active
            )
            .execute(pool)
            .await?;
        }
        Ok(Saved::Done)
    }

    async fn delete(pool: &SqlitePool, id: i64) -> sqlx::Result<()> {
        sqlx::query!("DELETE FROM mailform WHERE id = ?1", id).execute(pool).await?;
        Ok(())
    }

    async fn list(pool: &SqlitePool, form: &Form<'_>) -> sqlx::Result<String> {
        let rows = sqlx::query!(r#"SELECT id AS "id!: i64", code, name, subject, recipient, bcc, active FROM mailform ORDER BY name"#)
            .fetch_all(pool)
            .await?;
        let mut out = String::from("<table class='standard border tablesorter'><thead><tr><th>Code</th><th>Name</th><th>Subject</th><th>Recipient</th><th>BCC</th><th width='5%'>Del?</th></tr></thead>");
        out.push_str(&format!("<tr><td colspan=6>{}</td></tr>", href("Add new", "javascript:fn_action('maintain','0','');", Opts::new())));
        out.push_str("<tbody>");
        for r in rows {
            let code = r.code.unwrap_or_default();
            let active = r.active.unwrap_or(0) != 0;
            let bcc: Vec<String> = r.bcc.as_deref().and_then(|b| serde_json::from_str(b).ok()).unwrap_or_default();
            let recipient = if r.recipient.is_empty() { "Visitor".to_string() } else { esc(&r.recipient) };
            let bg = if active { "" } else { "style='background-color: #ffff00;'" };
            let del = if active { "&nbsp".to_string() } else { form.checkbox_delete(r.id, &code, "fn_action") };
            out.push_str(&format!(
                "<tr {bg}><td>{}</td><td>{}</td><td>{}</td><td>{recipient}</td><td>{}</td><td class=center>{del}</td></tr>",
                href(&esc(&code), &format!("javascript:fn_action('maintain',{},'');", js(&r.id.to_string())), Opts::new()),
                esc(&r.name.unwrap_or_default()),
                esc(&r.subject.unwrap_or_default()),
                bcc.iter().map(|b| esc(b)).collect::<Vec<_>>().join("<br>"),
            ));
        }
        out.push_str("</tbody></table>");
        Ok(out)
    }
}
