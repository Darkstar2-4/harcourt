//! carrier.php: the shipping carriers checkout offers. Administrators only.

use std::sync::Arc;

use axum::{
    body::Bytes,
    extract::State,
    http::{HeaderMap, Uri},
    response::Response,
};

use crate::{
    api::{Administrator, ApiError},
    orders::{
        form_params,
        html::{self, esc, js_str},
        Orders, Staff,
    },
};

struct Carrier {
    id: i64,
    name: String,
    active: bool,
}

const SCRIPT: &str = "<script type='text/javascript'>\nfunction fn_action(obj,action,id,name) {\n\tif (action=='delete') {\n\t    if (!confirm(\"Delete: #\" + id + \" \" + name)) {\n\t        obj.checked=false;\n            return;\n\t    }\n    }\n\tdocument.scs_form.action.value=action;\n\tdocument.scs_form.record_id.value=id;\n\tdocument.scs_form.submit();\n}\n</script>\n";

pub async fn page(State(state): State<Arc<Orders>>, _staff: Staff<Administrator>, headers: HeaderMap, uri: Uri, body: Bytes) -> Result<Response, ApiError> {
    let pool = &state.core.pool;
    let p = form_params(None, &body);
    let get = |k: &str| p.get(k).map_or("", String::as_str);
    let action = get("action");
    let record_id: i64 = get("record_id").trim().parse().unwrap_or(0);
    let found = sqlx::query!(r#"SELECT id AS "id!: i64", coalesce(name, '') AS "name!: String", coalesce(active, 0) AS "active!: i64" FROM carrier WHERE id = ?1"#, record_id)
        .fetch_optional(pool)
        .await?;
    let mut carrier = found.map_or(Carrier { id: 0, name: String::new(), active: true }, |r| Carrier { id: r.id, name: r.name, active: r.active != 0 });
    let mut messages = vec!["Carrier Codes".to_string()];
    let mut name_error = false;
    match action {
        "cancel" => messages.push("Request cancelled".into()),
        "delete" if carrier.id != 0 && !carrier.active => {
            sqlx::query!("DELETE FROM carrier WHERE id = ?1", carrier.id).execute(pool).await?;
            messages.push(format!("Carrier {} deleted", esc(&carrier.name)));
        }
        "update" => {
            carrier.name = get("name").trim().to_string();
            carrier.active = p.contains_key("active");
            name_error = carrier.name.is_empty();
            if !name_error {
                let active = i64::from(carrier.active);
                let saved = if carrier.id != 0 {
                    sqlx::query!("UPDATE carrier SET name = ?1, active = ?2 WHERE id = ?3", carrier.name, active, carrier.id).execute(pool).await.map(|_| ())
                } else {
                    sqlx::query!("INSERT INTO carrier (name, active) VALUES (?1, ?2)", carrier.name, active).execute(pool).await.map(|_| ())
                };
                match saved {
                    Ok(()) => messages.push("Record maintained".into()),
                    Err(sqlx::Error::Database(e)) if e.is_unique_violation() => {
                        name_error = true;
                        messages.push("Duplicate entry".into());
                    }
                    Err(e) => return Err(e.into()),
                }
            }
        }
        _ => {}
    }
    let chrome = html::Chrome::load(pool).await?;
    let head = html::Head { title: "Carrier Codes", description: None, js: &[], framed: html::framed(uri.query(), &headers), dashboard: true, catalog_url: "/catalog.php" };
    let mut out = html::open(&chrome, &head);
    out.push_str(&html::message(&messages, usize::from(name_error)));
    out.push_str(SCRIPT);
    out.push_str(&html::form_open("/carrier.php").replace(" autocomplete=on>", " enctype='multipart/form-data' autocomplete=on>"));
    out.push_str(&html::hidden("action", ""));
    out.push_str(&html::hidden("record_id", ""));
    if action == "maintain" || (action == "update" && name_error) {
        let checked = if carrier.active { " checked" } else { "" };
        out.push_str("<table class='large noborder'><tr><td width='30%'>Name</td>");
        out.push_str(&format!("<td width='70%'>{}</td>", html::text_input("text", "name", &carrier.name, 45, 45, "", name_error)));
        out.push_str(&format!("</tr><tr><td>Active?</td><td><input type=checkbox name='active' id='active' value='1'{checked}></td></tr></table>"));
        let id = if carrier.id == 0 { String::new() } else { carrier.id.to_string() };
        let buttons = [html::button("Update", &format!("fn_action(this,'update',{},'');", js_str(&id)), "nav_button"), html::button("Cancel", "fn_action(this,'cancel','','');", "nav_button")];
        out.push_str(&format!("<p class='standard'>{}</p>\n", buttons.join("\n")));
    } else {
        out.push_str("<table class='standard border'>\n<caption>&nbsp;</caption>\n<tr>\n<th width='90%'>Name</th>\n<th width='10%'>Del?</th>\n</tr>\n<tr>\n");
        out.push_str("<td colspan=3><a href=\"javascript:fn_action(this,'maintain','0','');\" >Add new</a></td>\n</tr>\n");
        let rows = sqlx::query!(r#"SELECT id AS "id!: i64", coalesce(name, '') AS "name!: String", coalesce(active, 0) AS "active!: i64" FROM carrier ORDER BY name"#).fetch_all(pool).await?;
        for r in rows {
            let id = js_str(&r.id.to_string());
            out.push_str(&format!("<tr>\n<td><a href=\"javascript:fn_action(this,'maintain',{},'');\" >{}</a></td>\n", html::esc_dq(&id), html::esc_text(&r.name)));
            let delete = if r.active != 0 {
                String::new()
            } else {
                let onclick = format!("fn_action(this,'delete',{id},{});", js_str(&r.name));
                format!("<input type=radio value='{0}' name='delete' id='delete_{0}' onclick=\"javascript:{1}\">\n", r.id, html::esc_dq(&onclick))
            };
            out.push_str(&format!("<td class=center>{delete}</td>\n</tr>\n"));
        }
        out.push_str("</table>\n");
    }
    out.push_str(html::FORM_CLOSE);
    out.push_str(html::close());
    Ok(html::page_response(out))
}
