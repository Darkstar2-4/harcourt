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
    /// Whole `<meta>` tags from the registry, printed as stored (operator configuration, not request data).
    meta: Vec<String>,
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
                ("meta", _) if !data.is_empty() => c.meta.push(data),
                ("js", _) if !data.is_empty() && !c.js.contains(&data) => c.js.push(data),
                _ => {}
            }
        }
        c
    }

    fn head(&self, head: &Head<'_>, wp: bool) -> String {
        let title = head.title;
        let full = if self.title_prefix.is_empty() { title.to_string() } else { format!("{} {title}", self.title_prefix) };
        let emptied = head.description.is_some_and(|d| d.trim().is_empty());
        let description = format!("<meta name=\"description\" content=\"{}\">", esc(head.description.map(str::trim).filter(|d| !d.is_empty()).unwrap_or(&full)));
        let mut out = vec!["<!DOCTYPE html>".to_string(), "<html>".into(), "<head>".into()];
        out.push(format!("<title>{}</title>", esc(&full)));
        if !emptied {
            out.push(description.clone());
        }
        out.extend(self.meta.iter().cloned());
        out.push("<meta name=\"robots\" content=\"noarchive, noimageindex\">".into());
        if emptied {
            out.push(description);
        }
        out.push("<meta charset=\"utf-8\">".into());
        if !self.icon.is_empty() {
            out.push(format!("<link rel='icon' href='{}' type='image/x-icon'>", esc(&self.icon)));
        }
        let mut css: Vec<&str> = self.css.iter().map(String::as_str).collect();
        for c in head.css {
            if !css.contains(&c.as_str()) {
                css.push(c);
            }
        }
        for css in css {
            out.push(format!("<link rel='stylesheet' type='text/css' href='{}'>", esc(css)));
        }
        let mut scripts = self.js.clone();
        if !wp {
            scripts.push("/scs_scripts/scscpq_wp.js?v=LOCAL".into());
        }
        for js in &scripts {
            out.push(format!("<script type='text/javascript' src='{}'></script>", esc(js)));
        }
        out.push("</head>".into());
        out.push("<body>".into());
        out.join("\n") + "\n"
    }
}

/// menu_class::ribbon(): the site's top bar outside WordPress.
fn ribbon(role: Role, dashboard: &str) -> String {
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
        out.push_str(&format!("<div class=scs_menu_left>{}</div>", href(dashboard, "/dashboard.php", Default::default())));
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

/// Who the page is drawn for: the role decides the ribbon's dashboard link, and inside WordPress there is no
/// ribbon and no scscpq_wp.js, because the WordPress page around it has its own.
#[derive(Clone, Copy)]
pub struct View {
    pub role: Role,
    pub wp: bool,
}

/// A whole staff page: head, ribbon, the body the page built, and the closing divs.
pub async fn page(pool: &SqlitePool, view: View, title: &str, body: &str) -> Response {
    described(pool, view, title, None, body).await
}

/// `page` with the meta description a page sets itself (`$forms->html->meta("description", …)`).
pub async fn described(pool: &SqlitePool, view: View, title: &str, description: Option<&str>, body: &str) -> Response {
    document(pool, view, &Head { title, description, css: &[], dashboard: None }, body).await
}

/// What a page sets in its head besides the registry's lines.
pub struct Head<'a> {
    pub title: &'a str,
    /// The page's own description. `Some("")` is a page that set it to nothing: meta() dropped the line and
    /// html_open() put the title back after the registry's meta lines.
    pub description: Option<&'a str>,
    /// Stylesheets the page adds after the registry's (`html_load_script("css", …)`).
    pub css: &'a [String],
    /// The ribbon's dashboard link in the reader's language (`menu_dashboard`); English when None.
    pub dashboard: Option<&'a str>,
}

pub async fn document(pool: &SqlitePool, view: View, head: &Head<'_>, body: &str) -> Response {
    let mut html = opened(pool, view, head).await;
    html.push_str(body);
    html.push_str("</div> <!-- #scs_content -->\n</div> <!-- #scs_body -->");
    html.push_str("</body>\n</html>\n");
    html_response(StatusCode::OK, html)
}

/// A page that never called `$menu->copyright()`: its body is the last thing sent, nothing closed after it.
pub async fn unclosed(pool: &SqlitePool, view: View, title: &str, body: &str) -> Response {
    let mut html = opened(pool, view, &Head { title, description: None, css: &[], dashboard: None }).await;
    html.push_str(body);
    html_response(StatusCode::OK, html)
}

async fn opened(pool: &SqlitePool, view: View, head: &Head<'_>) -> String {
    let chrome = Chrome::load(pool).await;
    let mut html = chrome.head(head, view.wp);
    let mut top = vec!["<div id='scs_body' >".to_string()];
    if !view.wp {
        top.push(ribbon(view.role, head.dashboard.unwrap_or("Dashboard")));
    }
    top.push("<div id='scs_content' >".to_string());
    html.push_str(&top.join("\n"));
    html
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
