//! Remote grants for staff: list the live ones, mint one for an account, revoke one. The page over
//! remote_grant.rs, which replaced scs_remote.php. A minted token is shown once, on the page that minted it.

use std::sync::Arc;

use axum::{extract::State, response::Response, routing::get, Router};
use sqlx::SqlitePool;

use super::{
    csrf::{Fields, Posted},
    html::{button, close, esc, hidden, js, open, Form, Notes, Opts},
    shell, time, Staff,
};
use crate::{
    api::{Administrator, Auth, ClientIp},
    audit::{self, Entry},
    db::unix_now,
    identity::{self, UserId},
    remote_grant,
};

const PATH: &str = "/staff/remote-grants";
const TITLE: &str = "Remote Grants";

#[derive(Debug, PartialEq, Eq)]
enum Action {
    List,
    Mint(i64),
    Revoke(i64),
}

fn parse(fields: &Fields) -> Action {
    match fields.get("action") {
        "mint" => Action::Mint(fields.int("user_id")),
        "revoke" => Action::Revoke(fields.int("record_id")),
        _ => Action::List,
    }
}

const SCRIPT: &str = "
<script>
function fn_action(obj, action, id, text) {
\tif ((action == 'revoke') && (!confirm(text))) {
\t\tobj.checked=false;
\t\treturn;
\t}
\tjQuery('#action').val(action);
\tjQuery('#record_id').val(id);
\tjQuery('#scs_form').submit();
}
</script>";

fn when(unix: i64) -> String {
    time::date("m/d/Y h:i:s A", unix)
}

async fn render(pool: &SqlitePool, staff: &Staff<Administrator>, ip: &str, fields: &Fields) -> sqlx::Result<Response> {
    let actor = &staff.who;
    let now = unix_now();
    let mut notes = Notes::new(TITLE, "");
    let mut minted = String::new();
    match parse(fields) {
        Action::List => {}
        Action::Mint(target_id) => {
            let target = identity::load_user(pool, UserId(target_id)).await?;
            match target.filter(|t| remote_grant::may_grant(&actor.user, t)) {
                Some(target) => {
                    let (token, expires_at) = remote_grant::mint(pool, target.id, actor.user.id, now).await?;
                    let comment = format!("for user {}", target.id.0);
                    audit::record(pool, Entry { subtype: "remote_granted", user_id: actor.user.id.0, email: actor.email.as_deref(), ip, comment: &comment }).await;
                    notes.say(&format!("Grant created for user {}", target.id.0));
                    minted = format!(
                        "<p class='standard'>Give this code to the person who will use it. It works once, until {}, and is not shown again.<br><b><code>{}</code></b></p>",
                        when(expires_at),
                        esc(token.as_str())
                    );
                }
                None => {
                    audit::record(pool, Entry { subtype: "remote_grant_denied", user_id: actor.user.id.0, email: actor.email.as_deref(), ip, comment: "" }).await;
                    notes.error("user_id", Some("No account you can grant"));
                }
            }
        }
        Action::Revoke(id) => {
            let target = match remote_grant::live_target(pool, id, now).await? {
                Some(user) => identity::load_user(pool, user).await?,
                None => None,
            };
            let allowed = target.is_some_and(|t| t.role <= actor.user.role);
            if allowed && remote_grant::revoke(pool, id, now).await? {
                let comment = format!("grant {id}");
                audit::record(pool, Entry { subtype: "remote_revoked", user_id: actor.user.id.0, email: actor.email.as_deref(), ip, comment: &comment }).await;
                notes.say("Grant revoked");
            } else {
                notes.say("Grant not found");
            }
        }
    }
    let grants = remote_grant::live(pool, now).await?;
    let form = Form { notes: &notes };
    let mut out = vec![
        notes.render(),
        SCRIPT.to_string(),
        open(PATH, staff.csrf.as_str(), false),
        hidden("action", ""),
        hidden("record_id", ""),
        minted,
        "<table class='standard noborder'>".into(),
        "<tr>".into(),
        "<td width='30%'>User ID</td>".into(),
        format!("<td width='70%'>{}</td>", notes.with_error("user_id", form.text("user_id", "", 12, 12, Opts::new()))),
        "</tr>".into(),
        "</table>".into(),
        format!("<p>{}</p>", button("Grant", Opts::new().onclick("fn_action(this,'mint','0','');"))),
        "<table class='standard border tablesorter'>".into(),
        "<caption>&nbsp;</caption>".into(),
        "<thead>".into(),
        "<tr>".into(),
        "<th>Account</th>".into(),
        "<th>Company Name</th>".into(),
        "<th>Granted By</th>".into(),
        "<th>Created</th>".into(),
        "<th>Expires</th>".into(),
        "<th width='5%'>Revoke?</th>".into(),
        "</tr>".into(),
        "</thead>".into(),
        "<tbody>".into(),
    ];
    if grants.is_empty() {
        out.push("<tr><td colspan=6>No live grants</td></tr>".into());
    }
    for g in &grants {
        let confirm = format!("Revoke the grant for user {}?", g.user_id.0);
        let revoke = form.radio("revoke", &g.id.to_string(), false, Opts::new().onclick(format!("fn_action(this,'revoke',{},{});", js(&g.id.to_string()), js(&confirm))));
        out.push("<tr>".into());
        out.push(format!("<td class=center>{}</td>", g.user_id.0));
        out.push(format!("<td>{}</td>", esc(&g.company_name)));
        out.push(format!("<td>{} {}</td>", g.granted_by.0, esc(&g.granted_by_name)));
        out.push(format!("<td class='center nobr'>{}</td>", when(g.created_at)));
        out.push(format!("<td class='center nobr'>{}</td>", when(g.expires_at)));
        out.push(format!("<td class=center>{revoke}</td>"));
        out.push("</tr>".into());
    }
    out.push("</tbody>".into());
    out.push("</table>".into());
    out.push(close().into());
    Ok(shell::page(pool, staff.view(), TITLE, &out.join("\n")).await)
}

async fn show(State(auth): State<Arc<Auth>>, ClientIp(ip): ClientIp, staff: Staff<Administrator>) -> Response {
    render(&auth.pool, &staff, &ip, &Fields::default()).await.unwrap_or_else(shell::internal)
}

async fn post(State(auth): State<Arc<Auth>>, ClientIp(ip): ClientIp, staff: Staff<Administrator>, posted: Posted) -> Response {
    render(&auth.pool, &staff, &ip, &posted.fields).await.unwrap_or_else(shell::internal)
}

pub fn routes() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(show).post(post))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn actions_parse_to_one_variant() {
        assert_eq!(parse(&Fields::of(&[("action", "mint"), ("user_id", "12")])), Action::Mint(12));
        assert_eq!(parse(&Fields::of(&[("action", "revoke"), ("record_id", "3")])), Action::Revoke(3));
        assert_eq!(parse(&Fields::of(&[("action", "delete")])), Action::List);
    }
}
