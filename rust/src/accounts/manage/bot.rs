//! bot.php: the search engines the site recognises by the reverse DNS name of their address, whether each may
//! browse the catalog, and through which portal.

use sqlx::SqlitePool;

use crate::{
    api::Administrator,
    staff::{
        choices,
        html::{button, esc, href, js, list_options, text, Blank, Form, Notes, Opts},
        maintain::{Column, Saved, Table, Widget},
    },
};

const STATUSES: [&str; 2] = ["Active", "Denied"];

#[derive(Default)]
pub struct Bot {
    code: String,
    name: String,
    comment: String,
    status: String,
    catalog: bool,
    portal_id: i64,
    /// The portal choices, read with the row for the edit form.
    portals: Vec<(String, String)>,
}

const SCRIPT: &str = "
<script type='text/javascript'>
function fn_action(obj,action,id,name) {
\tif (action=='delete') {
\t    if (!confirm('Delete: #' + id + ' ' + name)) {
\t        obj.checked=false;
            return;
\t    }
    }
\tdocument.scs_form.action.value=action;
\tdocument.scs_form.record_id.value=id;
\tdocument.scs_form.submit();
}
</script>
";

/// A DNS suffix such as `googlebot.com`: at least two labels, the first not empty, and nothing parse_url() would
/// read as anything but a path.
fn code_error(code: &str) -> bool {
    let labels: Vec<&str> = code.split('.').collect();
    code.is_empty() || code.contains(['?', '#', ':']) || code.starts_with("//") || labels.len() < 2 || labels[0].is_empty()
}

/// The pattern classes/bot.php matched names with: `/` + preg_quote("." + code, "/") + `$/i`.
fn pattern(code: &str) -> String {
    let mut out = String::from("/");
    for c in format!(".{code}").chars() {
        if ".\\+*?[^]$(){}=!<>|:-#/".contains(c) {
            out.push('\\');
        }
        out.push(c);
    }
    out.push_str("$/i");
    out
}

fn set_code(b: &mut Bot, v: &str, n: &mut Notes) {
    b.code = v.to_lowercase().trim().to_string();
    if code_error(&b.code) {
        n.error("code", None);
    }
}

fn set_name(b: &mut Bot, v: &str, n: &mut Notes) {
    b.name = v.to_string();
    if b.name.is_empty() {
        n.error("name", None);
    }
}

fn set_status(b: &mut Bot, v: &str, n: &mut Notes) {
    b.status = v.to_string();
    if b.status.is_empty() {
        n.error("status", None);
    }
}

impl Table for Bot {
    type Role = Administrator;
    const PATH: &'static str = "/bot.php";
    const TITLE: &'static str = "BOT Manager";
    const QUERY: bool = true;
    const COLUMNS: &'static [Column<Self>] = &[
        Column { name: "code", label: "DNS Name", widget: Widget::Text { size: 60, max: 60 }, get: |b| b.code.clone(), set: set_code },
        Column { name: "name", label: "Search Engine Name", widget: Widget::Text { size: 60, max: 60 }, get: |b| b.name.clone(), set: set_name },
        Column { name: "comment", label: "Comment", widget: Widget::TextArea { rows: 3, cols: 60 }, get: |b| b.comment.clone(), set: |b, v, _| b.comment = v.to_string() },
        Column { name: "status", label: "Status", widget: Widget::Text { size: 10, max: 10 }, get: |b| b.status.clone(), set: set_status },
        Column { name: "catalog", label: "Catalog?", widget: Widget::Checkbox, get: |b| u8::from(b.catalog).to_string(), set: |b, v, _| b.catalog = !matches!(v.trim(), "" | "0") },
        Column {
            name: "portal_id",
            label: "Portal",
            widget: Widget::Text { size: 10, max: 10 },
            get: |b| b.portal_id.to_string(),
            set: |b, v, _| b.portal_id = crate::staff::csrf::php_intval(v),
        },
    ];

    fn script() -> &'static str {
        SCRIPT
    }

    fn buttons(id: i64) -> String {
        let update = button("Update", Opts::new().onclick(format!("fn_action(this,'update',{},'');", js(&id.to_string()))));
        let cancel = button("Cancel", Opts::new().onclick("fn_action(this,'cancel','','');"));
        format!("<p class='standard'>{update} {cancel}</p>\n")
    }

    fn deleted(&self, notes: &mut Notes) {
        notes.say(&format!("BOT {} deleted", self.code));
    }

    /// A bot that is not Active gets neither the catalog nor a portal.
    fn validate(&mut self, notes: &mut Notes) {
        if !notes.has_errors() && self.status != "Active" {
            self.catalog = false;
            self.portal_id = 0;
        }
    }

    fn edit(&self, form: &Form<'_>, _wp: bool) -> String {
        let row = |label: &str, widget: String| format!("<tr>\n<td>{label}</td>\n<td>{widget}</td>\n</tr>\n");
        let mut out = String::from("<table class='standard noborder'>\n");
        out.push_str(&format!("<tr>\n<td width='30%'>DNS Name</td>\n<td width='70%'>{}</td>\n</tr>\n<tr>\n", form.text("code", &self.code, 60, 60, Opts::new())));
        out.push_str(&row("Search Engine Name", form.text("name", &self.name, 60, 60, Opts::new())));
        out.push_str(&row("Comment", form.textarea("comment", &self.comment, 3, 60, Opts::new())));
        out.push_str(&row("Status", form.select("status", &list_options(&STATUSES), &self.status, Blank::Empty, Opts::new())));
        out.push_str(&row("Catalog?", form.checkbox("catalog", "1", self.catalog, Opts::new())));
        out.push_str(&row("Portal", form.select("portal_id", &self.portals, &self.portal_id.to_string(), Blank::Empty, Opts::new())));
        out.push_str("</table>\n");
        out
    }

    async fn read(pool: &SqlitePool, id: i64) -> sqlx::Result<Option<Self>> {
        let row = sqlx::query!("SELECT code, name, comment, status, catalog, portal_id FROM bot WHERE id = ?1", id).fetch_optional(pool).await?;
        let portals = choices::portals(pool).await?;
        Ok(Some(match row {
            Some(r) => Bot {
                code: r.code,
                name: r.name,
                comment: r.comment.unwrap_or_default(),
                status: r.status,
                catalog: r.catalog != 0,
                portal_id: r.portal_id,
                portals,
            },
            None => Bot { portals, catalog: true, ..Bot::default() },
        }))
    }

    /// Saved over the row it was read from, else added: an id that names no bot adds one, as bot.php did.
    async fn save(&self, pool: &SqlitePool, id: i64) -> sqlx::Result<Saved> {
        let pattern = pattern(&self.code);
        let catalog = i64::from(self.catalog);
        let updated = sqlx::query!(
            "UPDATE bot SET code = ?2, pattern = ?3, name = ?4, comment = ?5, status = ?6, catalog = ?7, portal_id = ?8 WHERE id = ?1",
            id, self.code, pattern, self.name, self.comment, self.status, catalog, self.portal_id
        )
        .execute(pool)
        .await?;
        if updated.rows_affected() == 0 {
            sqlx::query!(
                "INSERT INTO bot (code, pattern, name, comment, status, catalog, portal_id) VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7)",
                self.code, pattern, self.name, self.comment, self.status, catalog, self.portal_id
            )
            .execute(pool)
            .await?;
        }
        Ok(Saved::Done)
    }

    async fn delete(pool: &SqlitePool, id: i64) -> sqlx::Result<()> {
        sqlx::query!("DELETE FROM bot WHERE id = ?1", id).execute(pool).await?;
        Ok(())
    }

    async fn list(pool: &SqlitePool, form: &Form<'_>) -> sqlx::Result<String> {
        let rows = sqlx::query!(
            r#"SELECT bot.id AS "id!: i64", bot.code, bot.name, bot.status, portal.name AS portal_name
               FROM bot LEFT JOIN portal ON portal.id = bot.portal_id ORDER BY bot.name, bot.code"#
        )
        .fetch_all(pool)
        .await?;
        let mut out = String::from("<table class='standard border tablesorter'>\n<thead>\n<tr>\n<th>DNS</th>\n<th>Search Engine</th>\n<th>Portal</th>\n<th>Status</th>\n<th>Del?</th>\n</tr>\n</thead>\n<tbody>\n");
        out.push_str(&format!("<tr>\n<td colspan=5>{}</td></tr>\n", href("Add New", "javascript:fn_action(this,'maintain','0','');", Opts::new())));
        for r in rows {
            let id = r.id.to_string();
            let maintain = format!("javascript:fn_action(this,'maintain',{},'');", js(&id));
            let delete = if r.status == "Active" {
                "&nbsp;".to_string()
            } else {
                form.radio("delete", &id, false, Opts::new().onclick(format!("fn_action(this,'delete',{},{});", js(&id), js(&r.code))))
            };
            out.push_str(&format!(
                "<tr>\n<td>{}</td>\n<td>{}</td>\n<td>{}</td>\n<td>{}</td>\n<td class=center>{delete}</td>\n</td>\n</tr>\n",
                href(&esc(&r.code), &maintain, Opts::new()),
                text(&r.name),
                text(&r.portal_name.unwrap_or_default()),
                text(&r.status)
            ));
        }
        out.push_str("</tbody>\n</table>\n");
        Ok(out)
    }
}
