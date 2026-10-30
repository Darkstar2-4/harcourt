//! language.php: the site's languages, their flag and the words of the menus, catalog and cart in each.

use std::path::PathBuf;

use base64::{engine::general_purpose::STANDARD, Engine};
use sqlx::SqlitePool;

use crate::{
    accounts::html::json_encode,
    api::Administrator,
    staff::{
        check,
        csrf::Posted,
        html::{button, esc, hidden, href, js, text, Blank, Form, Notes, Opts, Tabs},
        maintain::{Column, Saved, Table, Widget},
    },
};

/// classes/language.php language_constant_class::menu: each translatable word, its key and its English text.
const MENU: &[(&str, &str)] = &[
    ("menu_home", "Home"),
    ("menu_products", "Products"),
    ("menu_quality", "Quality"),
    ("menu_contact_us", "Contact"),
    ("menu_dashboard", "Dashboard"),
    ("menu_solutions", "Solutions"),
    ("menu_myaccount", "My Account"),
    ("menu_newuser", "Request Access"),
    ("menu_history", "History & Values"),
    ("menu_privacy", "Privacy"),
    ("menu_legal", "Legal Notice"),
    ("menu_company", "Company"),
    ("menu_search", "Search"),
    ("menu_search_placeholder", "Product Search"),
    ("menu_cart", "Items"),
    ("menu_cart_title", "View Items"),
    ("menu_portal", "Portal"),
    ("catalog_overview", "Overview"),
    ("catalog_products", "Product Details"),
    ("catalog_sku", "Part#"),
    ("catalog_cad", "Configure"),
    ("catalog_search", "Search"),
    ("catalog_download", "Download"),
    ("catalog_competitors", "Competitors"),
    ("catalog_tabs", "Tabs"),
    ("catalog_specials", "Specials"),
    ("catalog_customs", "Customs"),
    ("catalog_profile", "Profile"),
    ("cart_cart", "Shopping Cart"),
    ("cart_add", "Add to Cart"),
    ("cart_clear", "Clear Cart"),
    ("cart_update", "Update Cart"),
    ("cart_checkout", "Check Out"),
];

/// The form field of a menu word: base64 of "menu", a tab and its key, as the PHP named it.
fn menu_field(key: &str) -> String {
    STANDARD.encode(format!("menu\t{key}"))
}

pub struct Language {
    code: String,
    country_code: String,
    name: String,
    local_name: String,
    ps_name: String,
    /// Every menu key in MENU order, with the stored word or the English default.
    menu: Vec<(String, String)>,
    priority: i64,
    image_url: String,
    active: bool,
    /// A new flag posted with the form: a JPEG, written beside the others when the row is saved.
    flag: Option<Vec<u8>>,
}

/// language_data_class: a new language comes first in priority 1, active.
impl Default for Language {
    fn default() -> Self {
        Language {
            code: String::new(),
            country_code: String::new(),
            name: String::new(),
            local_name: String::new(),
            ps_name: String::new(),
            menu: Vec::new(),
            priority: 1,
            image_url: String::new(),
            active: true,
            flag: None,
        }
    }
}

const SCRIPT: &str = "
<script>
function fn_action(obj,action,id,name) {
\tif ( (action=='delete') && (!confirm('Delete: #' + id + ' ' + name)) ) {
\t    obj.checked=false;
\t    return;
    }
\tdocument.scs_form.action.value=action;
\tdocument.scs_form.record_id.value=id;
\tdocument.scs_form.submit();
}
</script>
";

fn set_name(l: &mut Language, v: &str, n: &mut Notes) {
    l.name = check::posted_text(v);
    if l.name.is_empty() {
        n.error("name", None);
    }
}

fn set_local_name(l: &mut Language, v: &str, n: &mut Notes) {
    l.local_name = check::posted_text(v);
    if l.local_name.is_empty() {
        n.error("local_name", None);
    }
}

fn set_code(l: &mut Language, v: &str, n: &mut Notes) {
    l.code = check::posted_text(v).to_uppercase();
    if l.code.is_empty() {
        n.error("code", None);
    }
}

/// What getimagesize() said about an upload, in the PHP's words when it is not a JPEG.
fn flag_error(bytes: &[u8]) -> Option<&'static str> {
    const OTHER_IMAGES: [&[u8]; 6] = [b"\x89PNG", b"GIF8", b"BM", b"II*\0", b"MM\0*", b"RIFF"];
    match () {
        _ if bytes.starts_with(b"\xff\xd8\xff") => None,
        _ if OTHER_IMAGES.iter().any(|m| bytes.starts_with(m)) => Some("Image size must be .jpg"),
        _ => Some("Not a valid image"),
    }
}

fn docroot() -> PathBuf {
    PathBuf::from(std::env::var_os("HXWEB_DOCROOT").unwrap_or_else(|| "/home/harcou6/public_html".into()))
}

/// The page's form image: `<img src=…>` when the file is in the document root, as forms_class::img() checked.
fn flag(url: &str) -> String {
    crate::catalog::forms::img(url, &[]).unwrap_or_default()
}

impl Language {
    fn tab(&self, form: &Form<'_>, prefix: &str) -> Vec<String> {
        let mut lines = vec!["<table class='standard noborder'>".to_string()];
        for (key, label) in MENU.iter().filter(|(k, _)| k.starts_with(prefix)) {
            let value = self.menu.iter().find(|(k, _)| k == key).map_or(*label, |(_, v)| v.as_str());
            lines.push("<tr>".into());
            lines.push(format!("<td width='30%'>{}</td>", text(label)));
            lines.push(format!("<td>{}</td>", form.text(&menu_field(key), value, 30, 30, Opts::new())));
            lines.push("</tr>".into());
        }
        lines.push("</table>".into());
        lines
    }

    fn status_tab(&self, form: &Form<'_>) -> Vec<String> {
        let priorities: Vec<(String, String)> = (0..=9).map(|n| (n.to_string(), n.to_string())).collect();
        let mut image = format!("<td>{} {}", flag(&self.image_url), form.file("image_url", 40, Opts::new()));
        if let Some(e) = form.notes.error_text("image_url") {
            image.push_str(&format!(" <br>{e}"));
        }
        vec![
            "<table class='standard noborder'>".into(),
            "<tr>".into(),
            "<td width=30%>Code (ISO 639-1)</td>".into(),
            format!("<td width=70%>{}</td>", form.text("code", &self.code, 2, 2, Opts::new())),
            "</tr>".into(),
            "<tr>".into(),
            "<td>Name</td>".into(),
            format!("<td>{}</td>", form.text("name", &self.name, 45, 45, Opts::new())),
            "</tr>".into(),
            "<tr>".into(),
            "<td>Local Language Name</td>".into(),
            format!("<td>{}</td>", form.text("local_name", &self.local_name, 45, 45, Opts::new())),
            "</tr>".into(),
            "<tr>".into(),
            "<td>PARTsolutions code</td>".into(),
            format!("<td>{}</td>", form.text("ps_name", &self.ps_name, 45, 45, Opts::new())),
            "</tr>".into(),
            "<tr>".into(),
            "<td>Priority</td>".into(),
            format!("<td>{}</td>", form.select("priority", &priorities, &self.priority.to_string(), Blank::None, Opts::new())),
            "</tr>".into(),
            "<tr>".into(),
            format!("<td>{}</td>", if self.image_url.is_empty() { "Add image" } else { "Replace image" }),
            "</td>".into(),
            image,
            "</td>".into(),
            "<tr>".into(),
            "<td>Active?</td>".into(),
            format!("<td>{}</td>", form.checkbox("active", "1", self.active, Opts::new())),
            "</tr>".into(),
            "</table>".into(),
        ]
    }

    /// The flag goes beside the others as `language_<id>.jpg`, replacing the one the row had.
    async fn store_flag(&self, pool: &SqlitePool, id: i64) -> sqlx::Result<()> {
        let Some(bytes) = &self.flag else { return Ok(()) };
        let url = format!("/scs_images/language_{id}.jpg");
        if let Err(e) = tokio::fs::write(docroot().join(url.trim_start_matches('/')), bytes).await {
            tracing::error!("language flag {url}: {e}");
            return Ok(());
        }
        sqlx::query!("UPDATE language SET image_url = ?2 WHERE id = ?1", id, url).execute(pool).await?;
        Ok(())
    }
}

impl Table for Language {
    type Role = Administrator;
    const PATH: &'static str = "/language.php";
    const TITLE: &'static str = "Language Manager";
    const MULTIPART: bool = true;
    const COLUMNS: &'static [Column<Self>] = &[
        Column { name: "code", label: "Code (ISO 639-1)", widget: Widget::Text { size: 2, max: 2 }, get: |l| l.code.clone(), set: set_code },
        Column {
            name: "country_code",
            label: "Country",
            widget: Widget::Text { size: 2, max: 2 },
            get: |l| l.country_code.clone(),
            set: |l, v, _| l.country_code = check::posted_text(v).to_uppercase(),
        },
        Column { name: "name", label: "Name", widget: Widget::Text { size: 45, max: 45 }, get: |l| l.name.clone(), set: set_name },
        Column {
            name: "local_name",
            label: "Local Language Name",
            widget: Widget::Text { size: 45, max: 45 },
            get: |l| l.local_name.clone(),
            set: set_local_name,
        },
        Column { name: "ps_name", label: "PARTsolutions code", widget: Widget::Text { size: 45, max: 45 }, get: |l| l.ps_name.clone(), set: |l, v, _| l.ps_name = check::posted_text(v) },
        Column {
            name: "priority",
            label: "Priority",
            widget: Widget::Text { size: 1, max: 1 },
            get: |l| l.priority.to_string(),
            set: |l, v, _| l.priority = crate::staff::csrf::php_intval(v),
        },
        Column { name: "active", label: "Active?", widget: Widget::Checkbox, get: |l| u8::from(l.active).to_string(), set: |l, v, _| l.active = !matches!(v.trim(), "" | "0") },
    ];

    fn script() -> &'static str {
        SCRIPT
    }

    fn buttons(id: i64) -> String {
        let update = button("Update", Opts::new().onclick(format!("fn_action(this,'update',{},'');", js(&id.to_string()))));
        let cancel = button("Cancel", Opts::new().onclick("fn_action(this,'cancel','','');"));
        format!("<p>{update} {cancel}</p>")
    }

    fn deleted(&self, notes: &mut Notes) {
        notes.say(&format!("{} deleted", self.name));
    }

    /// The menu words (one field per key) and a new flag.
    fn take(&mut self, posted: &Posted, notes: &mut Notes) {
        self.menu = MENU
            .iter()
            .map(|(key, _)| {
                let field = menu_field(key);
                let word = check::posted_text(posted.fields.get(&field));
                if word.is_empty() {
                    notes.error(&field, None);
                }
                (key.to_string(), word)
            })
            .collect();
        if let Some(upload) = posted.upload("image_url").filter(|u| !u.file_name.is_empty()) {
            match flag_error(&upload.bytes) {
                Some(e) => notes.error("image_url", Some(e)),
                None => self.flag = Some(upload.bytes.to_vec()),
            }
        }
    }

    fn edit(&self, form: &Form<'_>, wp: bool) -> String {
        let mut out = String::new();
        for field in ["report_status", "report_type", "report_company_name"] {
            out.push_str(&hidden(field, ""));
        }
        let mut tabs = Tabs::new();
        tabs.in_wordpress(wp);
        tabs.lines("Status", &self.status_tab(form));
        tabs.lines("Menu", &self.tab(form, "menu"));
        tabs.lines("Catalog", &self.tab(form, "catalog"));
        tabs.lines("Cart", &self.tab(form, "cart"));
        out.push_str(&tabs.render());
        out
    }

    async fn read(pool: &SqlitePool, id: i64) -> sqlx::Result<Option<Self>> {
        let row = sqlx::query!(
            r#"SELECT code, country_code, name, local_name, ps_name, menu, priority, image_url, active FROM language WHERE id = ?1"#,
            id
        )
        .fetch_optional(pool)
        .await?;
        Ok(row.map(|r| {
            let stored: serde_json::Map<String, serde_json::Value> = r.menu.as_deref().and_then(|m| serde_json::from_str(m).ok()).unwrap_or_default();
            let menu = MENU
                .iter()
                .map(|(key, label)| (key.to_string(), stored.get(*key).and_then(|v| v.as_str()).unwrap_or(label).to_string()))
                .collect();
            Language {
                code: r.code,
                country_code: r.country_code,
                name: r.name,
                local_name: r.local_name,
                ps_name: r.ps_name,
                menu,
                priority: r.priority,
                image_url: r.image_url,
                active: r.active != 0,
                flag: None,
            }
        }))
    }

    async fn save(&self, pool: &SqlitePool, id: i64) -> sqlx::Result<Saved> {
        let taken = sqlx::query_scalar!(r#"SELECT 1 AS "x!: i64" FROM language WHERE id <> ?1 AND code = ?2 AND country_code = ?3 LIMIT 1"#, id, self.code, self.country_code)
            .fetch_optional(pool)
            .await?;
        if taken.is_some() {
            return Ok(Saved::Conflict { field: "code", text: "Duplicate code" });
        }
        let menu = format!(
            "{{{}}}",
            self.menu.iter().map(|(k, v)| format!("{}:{}", json_encode(k), json_encode(v))).collect::<Vec<_>>().join(",")
        );
        let active = i64::from(self.active);
        let id = if id == 0 {
            sqlx::query_scalar!(
                r#"INSERT INTO language (code, country_code, name, local_name, ps_name, image_url, priority, menu, active)
                   VALUES (?1, ?2, ?3, ?4, ?5, '', ?6, ?7, ?8) RETURNING id AS "id!: i64""#,
                self.code, self.country_code, self.name, self.local_name, self.ps_name, self.priority, menu, active
            )
            .fetch_one(pool)
            .await?
        } else {
            sqlx::query!(
                "UPDATE language SET code = ?2, country_code = ?3, name = ?4, local_name = ?5, ps_name = ?6, priority = ?7, menu = ?8, active = ?9 WHERE id = ?1",
                id, self.code, self.country_code, self.name, self.local_name, self.ps_name, self.priority, menu, active
            )
            .execute(pool)
            .await?;
            id
        };
        self.store_flag(pool, id).await?;
        Ok(Saved::Done)
    }

    async fn delete(pool: &SqlitePool, id: i64) -> sqlx::Result<()> {
        sqlx::query!("DELETE FROM language WHERE id = ?1", id).execute(pool).await?;
        Ok(())
    }

    /// The active languages, English first, as scs_header.php loaded them for the menu. An inactive language is
    /// not listed, so the delete box the PHP drew for one never shows.
    async fn list(pool: &SqlitePool, _form: &Form<'_>) -> sqlx::Result<String> {
        let rows = sqlx::query!(
            r#"SELECT id AS "id!: i64", code, country_code, name, local_name, priority, image_url FROM language
               WHERE active = 1 ORDER BY code <> 'EN', priority, name"#
        )
        .fetch_all(pool)
        .await?;
        let mut out = String::from("<table class='standard border'><tr><th>Code</th><th>Flag</th><th>Name</th><th>Local Language</th><th>Priority</th><th>Del?</th></tr>");
        out.push_str(&format!("<tr><td colspan=6>{}</td></tr>", href("Add new", "javascript:fn_action(this,'maintain',0,'');", Opts::new())));
        for r in rows {
            let code = if r.country_code.is_empty() { r.code } else { format!("{}-{}", r.code, r.country_code) };
            let maintain = format!("javascript:fn_action(this,'maintain',{},'');", js(&r.id.to_string()));
            out.push_str(&format!(
                "<tr><td class=center>{}</td><td class=center>{}</td><td>{}</td><td>{}</td><td class=center>{}</td><td class=center>&nbsp;</td></td></tr>",
                href(&esc(&code), &maintain, Opts::new()),
                flag(&r.image_url),
                text(&r.name),
                text(&r.local_name),
                r.priority
            ));
        }
        out.push_str("</table>");
        Ok(out)
    }
}
