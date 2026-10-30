//! inventory_info.php: the features, applications or specials page a part's tab framed in, or a part's price.
//! `sku` names the part (else `prj` its family); `action` the page. The content is the first of: the part's own
//! content for that page, its family's, then the documents the part and its family list for it, in the visitor's
//! language before English.

use std::collections::HashMap;

use axum::{body::Bytes, extract::{Query, State}, response::Response};
use sqlx::SqlitePool;
use std::sync::Arc;

use super::{chrome, failed, html_response};
use crate::{
    api::Auth,
    catalog::{
        html,
        php::{self, Value},
        registry, strings,
        viewer::{self, Lang},
    },
    identity::Role,
    visitor::Visitor,
};

/// The parent page's tab each page lights (its title shows only when there is something to show).
fn menu_tab(action: &str) -> &'static str {
    match action {
        "features" => "myF_tab_item_0",
        "applications" => "myF_tab_item_1",
        "specials" => "myF_tab_item_2",
        _ => "",
    }
}

/// One content row as the page shows it.
struct Shown {
    id: i64,
    filetype: String,
    mime: String,
    text: String,
}

/// A record (part or family) the page looks content up for: its id and the document its `documents` list names
/// for the page.
struct Owner {
    id: i64,
    document: Option<i64>,
}

fn listed_document(documents: Option<&str>, action: &str) -> Option<i64> {
    let id = php::unserialize(documents?)?.get(action).map(Value::int).unwrap_or(0);
    (id != 0).then_some(id)
}

async fn part(pool: &SqlitePool, sku: &str, action: &str) -> sqlx::Result<Option<(Owner, i64)>> {
    let row = sqlx::query!(r#"SELECT id AS "id!: i64", subcategory_id, documents FROM inventory WHERE sku = ?1 COLLATE NOCASE ORDER BY id LIMIT 1"#, sku)
        .fetch_optional(pool)
        .await?;
    Ok(row.map(|r| (Owner { id: r.id, document: listed_document(r.documents.as_deref(), action) }, r.subcategory_id.unwrap_or(0))))
}

async fn family(pool: &SqlitePool, id: Option<i64>, prj: &str, action: &str) -> sqlx::Result<Option<Owner>> {
    let row = match id {
        Some(id) => sqlx::query!(r#"SELECT id AS "id!: i64", documents FROM subcategory WHERE id = ?1"#, id).fetch_optional(pool).await?.map(|r| (r.id, r.documents)),
        None if !prj.is_empty() => sqlx::query!(r#"SELECT id AS "id!: i64", documents FROM subcategory WHERE prj = ?1 COLLATE NOCASE ORDER BY id LIMIT 1"#, prj)
            .fetch_optional(pool)
            .await?
            .map(|r| (r.id, r.documents)),
        None => None,
    };
    Ok(row.map(|(id, documents)| Owner { id, document: listed_document(documents.as_deref(), action) }))
}

/// The first content row for the page: the part's own (1), the family's (2), then a listed document's (3); within
/// each, the visitor's language before English.
async fn content(pool: &SqlitePool, part: Option<&Owner>, family: Option<&Owner>, action: &str, lang: Lang) -> sqlx::Result<Option<Shown>> {
    let (part_id, part_doc) = (part.map(|o| o.id), part.and_then(|o| o.document));
    let (family_id, family_doc) = (family.map(|o| o.id), family.and_then(|o| o.document));
    let code = lang.code();
    let row = sqlx::query!(
        r#"SELECT id AS "id!: i64", filetype, meta, html_content, content FROM content
           WHERE language_code IN ('EN', ?1)
             AND ((record_type = 'inventory' AND record_id = ?2 AND record_item = ?3 COLLATE NOCASE)
               OR (record_type = 'document' AND record_id = ?4)
               OR (record_type = 'subcategory' AND record_id = ?5 AND record_item = ?3 COLLATE NOCASE)
               OR (record_type = 'document' AND record_id = ?6))
           ORDER BY CASE record_type WHEN 'inventory' THEN 1 WHEN 'subcategory' THEN 2 ELSE 3 END,
                    CASE WHEN language_code = 'EN' THEN 2 ELSE 1 END, id
           LIMIT 1"#,
        code,
        part_id,
        action,
        part_doc,
        family_id,
        family_doc
    )
    .fetch_optional(pool)
    .await?;
    Ok(row.map(|r| {
        let mime = r.meta.as_deref().and_then(|m| serde_json::from_str::<serde_json::Value>(m).ok()).and_then(|m| m.get("mime").and_then(|v| v.as_str()).map(str::to_string));
        Shown {
            id: r.id,
            filetype: r.filetype,
            mime: mime.unwrap_or_default(),
            text: r.html_content.unwrap_or_else(|| r.content.map(|b| String::from_utf8_lossy(&b).into_owned()).unwrap_or_default()),
        }
    }))
}

fn file_url(id: i64) -> String {
    html::url("/scs_file.php", &html::Query::new().with("content_id", html::base64_fields(&[&id.to_string(), "0"])))
}

/// The script that shows or hides the page's tab title in the part's page around it.
fn tab_script(action: &str, visible: bool) -> Vec<String> {
    let mut out: Vec<String> = [
        "<script language=javascript>\n",
        "function menu_title(id,visible) {",
        "obj=parent.document.getElementById(id);",
        "if (!obj) return;",
        "if (visible) {",
        "obj.style.display = 'inline';",
        "} else {",
        "obj.style.display = 'none';",
        "}",
        "}",
    ]
    .map(str::to_string)
    .to_vec();
    out.push(format!("menu_title('{}','{}');", menu_tab(action), if visible { "1" } else { "" }));
    out.push("</script>".into());
    out
}

pub async fn page(State(core): State<Arc<Auth>>, visitor: Visitor, Query(query): Query<HashMap<String, String>>, body: Bytes) -> Response {
    let mut params = query;
    params.extend(form_urlencoded::parse(&body).into_owned());
    let get = |k: &str| params.get(k).map(|v| v.trim().to_string()).unwrap_or_default();
    let (action, prj, sku) = (params.get("action").map(|a| a.to_lowercase()).unwrap_or_default(), get("prj"), get("sku"));
    let result = async {
        let pool = &core.pool;
        let lang = viewer::lang_for(&visitor);
        let reg = registry::load(pool).await?;
        let viewer = viewer::load(pool, &visitor, lang).await?;
        let dashboard = match viewer.at_least(Role::Executive) {
            true => Some(strings::load(pool, lang).await?.get("menu_dashboard").to_string()),
            false => None,
        };
        let ribbon = (!visitor.framed).then(|| chrome::ribbon(dashboard.as_deref()));
        let open = chrome::open(&reg, &chrome::Head::titled(""), ribbon.as_deref());
        let found = part(pool, &sku, &action).await?;
        if action == "price" {
            let price = if found.is_some() { "<p class=large></p>\n" } else { "" };
            return Ok::<_, sqlx::Error>(format!("{open}{price}{}", chrome::CLOSE));
        }
        let (part, family_id) = match found {
            Some((p, family_id)) => (Some(p), Some(family_id)),
            None => (None, None),
        };
        let family = family(pool, family_id, &prj, &action).await?;
        let shown = match (&part, &family) {
            (None, None) => None,
            _ => content(pool, part.as_ref(), family.as_ref(), &action, lang).await?,
        };
        let mut before = String::new();
        let mut results = Vec::new();
        match &shown {
            Some(s) if s.filetype == "html" => before = s.text.clone(),
            Some(s) if s.filetype == "image" => results.push(format!("<img src={}'>\n", file_url(s.id))),
            Some(s) if s.mime == "application/x-shockwave-flash" => results.extend([
                "<object>".to_string(),
                "<param name='movie' value='harcourt.swf'>".into(),
                "<embed ".into(),
                format!("src='{}'", file_url(s.id)),
                "type='application/x-shockwave-flash'".into(),
                "pluginspace='http://www.macromedia.com/go/getflashplayer'>".into(),
                "</embed>".into(),
                "</object>".into(),
            ]),
            _ => {}
        }
        results.extend(tab_script(&action, shown.is_some()));
        Ok(format!("{before}{open}{}{}", results.join("\n"), chrome::CLOSE))
    }
    .await;
    match result {
        Ok(body) => html_response(body),
        Err(e) => failed(e),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_part_lists_a_document_per_page() {
        let docs = r#"a:3:{s:8:"overview";s:0:"";s:8:"features";s:3:"203";s:8:"specials";s:3:"424";}"#;
        assert_eq!(listed_document(Some(docs), "specials"), Some(424));
        assert_eq!(listed_document(Some(docs), "overview"), None);
        assert_eq!(listed_document(Some(docs), "price"), None);
        assert_eq!(listed_document(None, "specials"), None);
    }

    #[test]
    fn the_tab_script_names_the_page_tab() {
        let s = tab_script("specials", true).join("\n");
        assert!(s.starts_with("<script language=javascript>\n\nfunction menu_title(id,visible) {"));
        assert!(s.ends_with("menu_title('myF_tab_item_2','1');\n</script>"));
        assert!(tab_script("bogus", false).join("\n").contains("menu_title('','');"));
    }
}
