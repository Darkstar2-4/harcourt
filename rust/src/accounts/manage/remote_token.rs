//! remote_token.php: the addresses of a company's domain that have signed in from outside, and when that lapses.
//! Staff end one (Remove: the address is forgotten and every sign-in it holds ends) or make it sign in again
//! (Reset: its sign-ins end and its remote access runs another `remote_days`).
//!
//! The tokens and unlock codes the PHP kept in `remote` no longer sign anyone in; the columns show what an old
//! row still holds. Every call needs an Administrator.

use std::sync::Arc;

use axum::{
    body::Bytes,
    extract::State,
    http::{HeaderMap, StatusCode},
    response::{IntoResponse, Response},
    routing::MethodRouter,
};
use sqlx::SqlitePool;

use super::listed::{from_page_script, json};
use crate::{
    accounts::admission,
    api::{Administrator, Auth},
    db::unix_now,
    session,
    staff::{
        csrf::Fields,
        html::{close, esc, hidden, list_options, open, submit, text, Blank, Form as Widgets, Notes, Opts},
        shell, time, Staff,
    },
};

const PATH: &str = "/remote_token.php";
const TITLE: &str = "Company Remote Access Tokens";
const YELLOW: &str = "style='background-color: #ffff00;'";
const LIMEGREEN: &str = "style='background-color: #32cd32;'";

const SCRIPT: &str = r##"<script>
function remote_token(row) {
	var action=jQuery("#action" + row).val();
	var email=jQuery("#email" + row).val();
	if (!action) return;
    if (!confirm(action + " " + email + "?")) {
        jQuery("#action" + row).val("");
    	return;
	}
	jQuery.ajax({
        type: "post",
	    dataType: "json",
		data: { action: action, domain: jQuery("#domain").val(), email: email },
	    success: function(response) {
        	jQuery("#remote_message").html(response['remote_message']);
        	jQuery("#remote_content").html(response['remote_content']);
        },
        error: function(xhr, response) {
        	console.log(xhr);
        }
	});

}
</script>
"##;

/// remote_output(): the addresses at `domain`, latest expiry first.
async fn output(pool: &SqlitePool, domain: &str, now: i64) -> sqlx::Result<String> {
    let like = format!("%@{domain}");
    let rows = sqlx::query!(r#"SELECT email AS "email!", expiry AS "expiry!: i64", token AS "token!", unlock_code AS "unlock_code!" FROM remote WHERE email LIKE ?1 ORDER BY expiry DESC"#, like)
        .fetch_all(pool)
        .await?;
    let notes = Notes::new(TITLE, "");
    let w = Widgets { notes: &notes };
    let mut out = String::from("<table class='standard border'><thead><tr><th width='10%'>Action</th><th width='30%'>Email</th><th width='20%'>Expires</th><th width='30%'>Token</th><th width='10%'>Unlock Code</th></thead><tbody>");
    if rows.is_empty() {
        out.push_str("<tr><td colspan=5>No records found</td></tr>");
    }
    for (row, r) in rows.iter().enumerate() {
        let expired = r.expiry < now;
        out.push_str(&hidden(&format!("email{row}"), &r.email));
        out.push_str(&format!("<tr {}>", if expired { YELLOW } else { LIMEGREEN }));
        let action = w.select(&format!("action{row}"), &list_options(&["Remove", "Reset"]), "", Blank::Empty, Opts::new().onchange(format!("remote_token({row});")));
        out.push_str(&format!("<td class=center>{action}</td><td>{}</td><td class=center>{}</td>", esc(&r.email), time::date("m/d/Y H:i", r.expiry)));
        if expired {
            out.push_str("<td class=center colspan=2>Expired</td>");
        } else {
            out.push_str(&format!("<td>{}</td>", esc(&r.token)));
            let unlock_until = i64::from_str_radix(&r.unlock_code, 16).unwrap_or(0);
            if unlock_until < now {
                out.push_str(&format!("<td class=center {YELLOW}>Expired</td>"));
            } else {
                out.push_str(&format!("<td class=center>{}</td>", esc(&r.unlock_code)));
            }
        }
        out.push_str("</tr>");
    }
    out.push_str("</tbody></table>");
    Ok(out)
}

/// The page, listing `domain` when the form asked for it.
async fn page(pool: &SqlitePool, staff: &Staff<Administrator>, fields: &Fields) -> sqlx::Result<Response> {
    let action = fields.get("action").trim();
    let domain = fields.get("domain").trim();
    let mut notes = Notes::new(TITLE, "");
    if !action.is_empty() && domain.is_empty() {
        notes.error("domain", None);
    }
    let rows = sqlx::query!(r#"SELECT domain AS "domain!", company_name FROM "user" WHERE domain <> '' ORDER BY company_name COLLATE NOCASE"#).fetch_all(pool).await?;
    let mut items: Vec<(String, String)> = Vec::new();
    for r in rows {
        let label = text(&r.company_name.unwrap_or_default());
        match items.iter_mut().find(|(d, _)| *d == r.domain) {
            Some(slot) => slot.1 = label,
            None => items.push((r.domain, label)),
        }
    }
    let w = Widgets { notes: &notes };
    let mut body = notes.render();
    body.push_str(SCRIPT);
    body.push_str(&open(PATH, staff.csrf.as_str(), false));
    body.push_str(&hidden("action", "list"));
    let picker = if items.is_empty() {
        "No records found".to_string()
    } else {
        format!("Domain: {} {}", w.select("domain", &items, domain, Blank::Empty, Opts::new()), submit("List", Opts::new()))
    };
    body.push_str(&format!("<p>{picker}</p><div id=remote_message></div>"));
    if !action.is_empty() && !notes.has_errors() {
        body.push_str(&format!("<div id=remote_content>{}</div>", output(pool, domain, unix_now()).await?));
    }
    body.push_str(close());
    Ok(shell::page(pool, staff.view(), TITLE, &body).await)
}

/// Remove or Reset one address, then the list again.
async fn act(pool: &SqlitePool, fields: &Fields) -> sqlx::Result<Response> {
    let email = fields.get("email");
    let now = unix_now();
    let message = match fields.get("action") {
        "Remove" => {
            sqlx::query!("DELETE FROM remote WHERE email = ?1", email).execute(pool).await?;
            session::destroy_for_email(pool, email).await?;
            format!("{} Removed", esc(email))
        }
        "Reset" => {
            let expiry = now + admission::remote_days(pool).await? * 86400;
            let reset = sqlx::query!("UPDATE remote SET expiry = ?2, token = '', unlock_code = '' WHERE email = ?1", email, expiry).execute(pool).await?;
            if reset.rows_affected() > 0 {
                session::destroy_for_email(pool, email).await?;
            }
            format!("{} Reset", esc(email))
        }
        _ => String::new(),
    };
    let content = output(pool, fields.get("domain"), now).await?;
    Ok(json(&[("remote_message".into(), message), ("remote_content".into(), content)]))
}

/// A form post only lists; a change comes from the page's own script.
async fn handle(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, headers: HeaderMap, body: Bytes) -> Response {
    let fields = Fields::posted(form_urlencoded::parse(&body).into_owned().collect());
    let result = if !headers.contains_key("x-requested-with") {
        page(&auth.pool, &staff, &fields).await
    } else if from_page_script(&headers, &auth.public_origin) {
        act(&auth.pool, &fields).await
    } else {
        return StatusCode::FORBIDDEN.into_response();
    };
    result.unwrap_or_else(shell::internal)
}

pub fn route() -> MethodRouter<Arc<Auth>> {
    axum::routing::get(handle).post(handle)
}
