//! The page around every staff screen: `$menu->head()` and `$menu->copyright()` from scs_header.php. The stylesheets,
//! scripts and title prefix come from the `registry` table (module `www`), as forms_class::html_load() read them.

use axum::{
    http::{header, StatusCode},
    response::{IntoResponse, Response},
};
use sqlx::SqlitePool;

use super::html::{esc, href};
use crate::identity::Role;

#[derive(Default)]
pub struct Chrome {
    title_prefix: String,
    icon: String,
    css: Vec<String>,
    js: Vec<String>,
}

/// registry_class::load() for one module as (field, item, data): rows of the site domain ('') win over 'global',
/// and list items keep the order of their numeric item keys. A plain setting has an empty item.
pub async fn registry(pool: &SqlitePool, module: &str) -> Vec<(String, String, String)> {
    let rows = sqlx::query!(
        r#"SELECT domain AS "domain!", field AS "field!", item AS "item!", data FROM registry WHERE module = ?1 AND domain IN ('', 'global')"#,
        module
    )
    .fetch_all(pool)
    .await
    .unwrap_or_else(|e| {
        tracing::warn!("registry {module}: {e}");
        Vec::new()
    });
    let mut rows: Vec<_> = rows.into_iter().map(|r| (r.field, r.domain == "global", r.item, r.data.unwrap_or_default())).collect();
    rows.sort_by(|a, b| (&a.0, a.1, sort_key(&a.2)).cmp(&(&b.0, b.1, sort_key(&b.2))));
    let mut out: Vec<(String, String, String)> = Vec::new();
    for (field, _, item, data) in rows {
        if !out.iter().any(|(f, i, _)| *f == field && *i == item) {
            out.push((field, item, data));
        }
    }
    out
}

fn sort_key(item: &str) -> String {
    match item.parse::<u32>() {
        Ok(n) if n != 0 => {
            let padded = format!("0000{item}");
            padded[padded.len() - 4..].to_string()
        }
        _ => item.to_string(),
    }
}

impl Chrome {
    pub async fn load(pool: &SqlitePool) -> Chrome {
        let mut c = Chrome::default();
        for (field, item, data) in registry(pool, "www").await {
            let data = data.trim().to_string();
            match (field.as_str(), item.is_empty()) {
                ("title_prefix", true) => c.title_prefix = data,
                ("icon", true) => c.icon = data,
                ("css", _) if !data.is_empty() && !c.css.contains(&data) => c.css.push(data),
                ("js", _) if !data.is_empty() && !c.js.contains(&data) => c.js.push(data),
                _ => {}
            }
        }
        c
    }

    fn head(&self, title: &str, description: Option<&str>) -> String {
        let full = if self.title_prefix.is_empty() { title.to_string() } else { format!("{} {title}", self.title_prefix) };
        let mut out = vec!["<!DOCTYPE html>".to_string(), "<html>".into(), "<head>".into()];
        out.push(format!("<title>{}</title>", esc(&full)));
        out.push(format!("<meta name=\"description\" content=\"{}\">", esc(description.unwrap_or(&full))));
        out.push("<meta name=\"robots\" content=\"noarchive, noimageindex\">".into());
        out.push("<meta charset=\"utf-8\">".into());
        if !self.icon.is_empty() {
            out.push(format!("<link rel='icon' href='{}' type='image/x-icon'>", esc(&self.icon)));
        }
        for css in &self.css {
            out.push(format!("<link rel='stylesheet' type='text/css' href='{}'>", esc(css)));
        }
        let mut scripts = self.js.clone();
        scripts.push("/scs_scripts/scscpq_wp.js?v=LOCAL".into());
        for js in &scripts {
            out.push(format!("<script type='text/javascript' src='{}'></script>", esc(js)));
        }
        out.push("</head>".into());
        out.push("<body>".into());
        out.join("\n") + "\n"
    }
}

/// menu_class::ribbon(): the site's top bar outside WordPress.
fn ribbon(role: Role) -> String {
    let mut out = String::from(concat!(
        "\n<script>\nfunction menu_search() {\n",
        "\tsearch_code=jQuery('#scscpq_search_text').val();\n",
        "\tsearch_code=search_code.trim()\n",
        "\tif (search_code.length < 2) {\n",
        "\t\talert('Product Search must be at least 2 characters')\n",
        "\t\treturn;\n",
        "\t}\n",
        "\tlocation.href='/catalog.php?search_source=term&search_code=' + encodeURIComponent(search_code);\n",
        "}\n</script>\n",
    ));
    out.push_str("<div id='scs_menu' >");
    let logo = "<img style='transform: translate(0%, 10px);' src='/scs_images/logo.png' height='70' title='Harcourt Industrial' border='0'>";
    out.push_str(&format!("<div class='scs_menu_left' style='padding-top: 10px;')>{}</div>", href(logo, "/", Default::default())));
    if role >= Role::Executive {
        out.push_str(&format!("<div class=scs_menu_left>{}</div>", href("Dashboard", "/dashboard.php", Default::default())));
    }
    out.push_str(&format!("<div class='scs_menu_left scscpq_impersonate'>{}</div>", href("Stop Impersonate", "/user_impersonate.php", Default::default())));
    out.push_str(&format!("<div class='scs_menu_left scscpq_newuser'>{}</div>", href("New user", "/newuser.php", Default::default())));
    out.push_str("<div class='scs_menu_right scscpq_cart'></div>");
    out.push_str("<div class='scs_menu_right scscpq_search'></div>");
    out.push_str(&format!(
        "<div id='menu-item-141' class='scs_menu_right scscpq_catalog'>{}</div>",
        href("Catalog", "/catalog.php", super::html::Opts::new().class("scs_menu_button"))
    ));
    out.push_str("</div> <!-- #scs_menu -->");
    out
}

/// A whole staff page: head, ribbon, the body the page built, and the closing divs.
pub async fn page(pool: &SqlitePool, role: Role, title: &str, body: &str) -> Response {
    described(pool, role, title, None, body).await
}

/// `page` with the meta description a page sets itself (`$forms->html->meta("description", …)`).
pub async fn described(pool: &SqlitePool, role: Role, title: &str, description: Option<&str>, body: &str) -> Response {
    let chrome = Chrome::load(pool).await;
    let mut html = chrome.head(title, description);
    html.push_str(&["<div id='scs_body' >".to_string(), ribbon(role), "<div id='scs_content' >".to_string()].join("\n"));
    html.push_str(body);
    html.push_str("</div> <!-- #scs_content -->\n</div> <!-- #scs_body -->");
    html.push_str("</body>\n</html>\n");
    html_response(StatusCode::OK, html)
}

pub fn html_response(status: StatusCode, html: String) -> Response {
    (status, [(header::CONTENT_TYPE, "text/html; charset=utf-8"), (header::CACHE_CONTROL, "no-store")], html).into_response()
}

/// A failure the visitor sees as a plain page; the cause goes to the log.
pub fn internal(e: impl std::fmt::Display) -> Response {
    tracing::error!("staff page: {e}");
    (StatusCode::INTERNAL_SERVER_ERROR, [(header::CONTENT_TYPE, "text/plain; charset=utf-8")], "Internal error\n").into_response()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn numeric_items_sort_as_numbers() {
        let mut items = vec!["10", "2", "1", "css"];
        items.sort_by_key(|i| sort_key(i));
        assert_eq!(items, ["1", "2", "10", "css"]);
    }
}
