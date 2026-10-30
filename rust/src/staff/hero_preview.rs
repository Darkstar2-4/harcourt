//! hero_preview.php: which hero headline the catalog would show for a date, portal, industry, language and role,
//! and the hero itself inside a green box. The page posts its inputs by AJAX and fills in the answer.
//!
//! The PHP's "Debug Query?" box printed the SQL it ran; there is no such text here, so the debug answer stays
//! empty.

use std::sync::Arc;

use axum::{
    body::Bytes,
    extract::{RawQuery, State},
    http::{HeaderMap, StatusCode},
    response::Response,
    routing::get,
    Router,
};
use serde_json::Value;
use sqlx::SqlitePool;

use super::{
    choices,
    csrf::Fields,
    filesort,
    html::{button, close, list_options, open, Blank, Form, Notes, Opts},
    shell, time, Staff,
};
use crate::{
    api::{Administrator, Auth},
    db::unix_now,
    identity::Role,
};

const PATH: &str = "/hero_preview.php";
const TITLE: &str = "Hero Preview";
const VERSION: &str = "11/25/2025";
const ROLES: [Role; 6] = [Role::Customer, Role::External, Role::Internal, Role::Executive, Role::Administrator, Role::SuperUser];

/// What the form asks: hero_class::load()'s options.
struct Ask {
    /// `Y-m-d`; empty when the date did not read.
    date: String,
    portal_id: i64,
    industry_id: i64,
    language: String,
    role: Option<Role>,
    pending: bool,
}

/// One hero with the names load() joined.
struct Hero {
    id: i64,
    effective_date: String,
    portal_id: i64,
    industry_id: i64,
    language: String,
    access: String,
    status: String,
    html: String,
    css: String,
    js: String,
    portal_name: Option<String>,
    industry_name: Option<String>,
    language_name: Option<String>,
}

/// hero_class::load(): the hero for this portal (or all), industry (or all), language (or English) and role, by
/// portal, then industry, then language match, newest first.
async fn load(pool: &SqlitePool, ask: &Ask) -> sqlx::Result<Option<Hero>> {
    let rows = sqlx::query!(
        r#"SELECT h.id AS "id!: i64", COALESCE(h.effective_date, '') AS "effective_date!: String", COALESCE(h.portal_id, 0) AS "portal_id!: i64",
                  COALESCE(h.industry_id, 0) AS "industry_id!: i64", COALESCE(h.language_code, '') AS "language!: String", h.access AS "access!: String",
                  h.status AS "status!: String", COALESCE(h.html, '') AS "html!: String", COALESCE(h.css, '') AS "css!: String",
                  COALESCE(h.js, '') AS "js!: String", p.name AS portal_name, i.name AS industry_name, l.name AS language_name
           FROM hero h LEFT JOIN portal p ON p.id = h.portal_id LEFT JOIN industry i ON i.id = h.industry_id LEFT JOIN language l ON l.code = h.language_code
           WHERE COALESCE(h.portal_id, 0) IN (0, ?1) AND COALESCE(h.industry_id, 0) IN (0, ?2) AND h.language_code IN ('EN', ?3)
             AND (h.status = 'Active' OR (?4 AND h.status = 'Pending')) AND h.effective_date <= ?5
           ORDER BY h.id"#,
        ask.portal_id,
        ask.industry_id,
        ask.language,
        ask.pending,
        ask.date
    )
    .fetch_all(pool)
    .await?;
    let roles: Vec<&str> = ROLES.iter().filter(|r| ask.role.is_some_and(|asked| asked >= **r)).map(|r| r.as_str()).collect();
    let mut heroes: Vec<Hero> = rows
        .into_iter()
        .filter(|r| r.access.is_empty() || roles.contains(&r.access.as_str()))
        .map(|r| Hero {
            id: r.id,
            effective_date: r.effective_date,
            portal_id: r.portal_id,
            industry_id: r.industry_id,
            language: r.language,
            access: r.access,
            status: r.status,
            html: r.html,
            css: r.css,
            js: r.js,
            portal_name: r.portal_name,
            industry_name: r.industry_name,
            language_name: r.language_name,
        })
        .collect();
    let rank = |h: &Hero| {
        let place = if ask.portal_id != 0 && h.portal_id == ask.portal_id {
            0
        } else if h.industry_id == ask.industry_id {
            1
        } else {
            2
        };
        (place, i32::from(h.language != ask.language))
    };
    filesort::sort(&mut heroes, |a, b| rank(a).cmp(&rank(b)).then_with(|| b.effective_date.cmp(&a.effective_date)));
    Ok(heroes.into_iter().next())
}

/// The summary table and the hero as output(preview) drew it.
fn answer(hero: Option<Hero>) -> Vec<(String, Value)> {
    let mut out: Vec<(String, Value)> = vec![("preview_detail".into(), Value::from("")), ("preview_debug".into(), Value::from(""))];
    let Some(h) = hero else {
        out.push(("preview_summary".into(), Value::from("No matches found")));
        return out;
    };
    let mut rows = vec!["<table class='noborder'>".to_string(), "<tr>".into(), "<td width='20%'>Hero ID</td>".into(), format!("<td width='80%'>{}</td>", h.id), "</tr>".into()];
    let stored = h.effective_date.replace('-', "/");
    if !matches!(stored.as_str(), "" | "0000/00/00") {
        let p: Vec<&str> = stored.split('/').collect();
        if let [y, m, d] = p[..] {
            rows.extend(["<tr>".to_string(), "<td>Effective Date</td>".into(), format!("<td>{m}/{d}/{y}</td>"), "</tr>".into()]);
        }
    }
    for (label, value) in [
        ("Portal", h.portal_name.unwrap_or_else(|| "All".into())),
        ("Industry", h.industry_name.unwrap_or_else(|| "All".into())),
        ("Language", h.language_name.unwrap_or_default()),
        ("Access", if h.access.is_empty() { "All".into() } else { h.access }),
        ("Status", h.status),
    ] {
        rows.extend(["<tr>".to_string(), format!("<td>{label}</td>"), format!("<td>{value}</td>"), "</tr>".into()]);
    }
    rows.push("</table>".into());
    let mut output = String::new();
    output.push_str(&h.js);
    output.push_str(&h.css);
    output.push_str("<p class=center>Hero content enclosed in <b><span class=scscpq_hero_preview>&nbsp;&nbsp;Box&nbsp;&nbsp;</span></b></p>");
    output.push_str("<div id=scscpq_hero class=scscpq_hero_preview>");
    output.push_str(&h.html);
    output.push_str("</div>");
    out.push(("preview_output".into(), Value::from(output)));
    out.push(("preview_summary".into(), Value::from(rows.concat())));
    out
}

/// fn_date($text, "mdy") as `Y-m-d`.
fn mdy(s: &str) -> String {
    let parts: Vec<i64> = s.split([' ', '/', '.', '-']).map(super::csrf::php_intval).collect();
    if s.trim().is_empty() || parts.len() < 3 {
        return String::new();
    }
    let (m, d, mut y) = (parts[0], parts[1], parts[2]);
    y += match y {
        0..=49 => 2000,
        50..=99 => 1900,
        _ => 0,
    };
    let ok = (1800..=2200).contains(&y) && chrono::NaiveDate::from_ymd_opt(y as i32, m as u32, d as u32).is_some();
    if ok {
        format!("{y:04}-{m:02}-{d:02}")
    } else {
        String::new()
    }
}

async fn ajax(pool: &SqlitePool, f: &Fields) -> sqlx::Result<Response> {
    let ask = Ask {
        date: mdy(f.get("date")),
        portal_id: f.int("portal_id"),
        industry_id: f.int("industry_id"),
        language: f.get("language_code").to_string(),
        role: Role::parse(f.get("role")),
        pending: !matches!(f.get("pending").trim(), "" | "0" | "false" | "no"),
    };
    let out = answer(load(pool, &ask).await?);
    let body: Vec<String> = out.iter().map(|(k, v)| format!("{}:{v}", Value::from(k.as_str()))).collect();
    Ok(shell::html_response(StatusCode::OK, crate::orders::shop::cart::php_json(&format!("{{{}}}", body.join(",")))))
}

const SCRIPT: &str = "
<script>
function fn_action(action) {
    data=scscpq_class_data('input');
    data['action']=action;
\tjQuery.ajax({
        type: 'post',
\t    dataType: 'json',
\t    data: data,
\t    dataType: 'json',
\t    success:function(response) { scscpq_fill(response) }
    });
}
</script>

<style>
.preview_content {
    border-top: 1px solid black;
    margin-top: 5px;
    margin-bottom: 5px;
}
.preview_content:empty  {
    border: none;
    margin: 0px;
}
.scscpq_hero_preview { border: 2px solid green; }
</style>
";

async fn page(pool: &SqlitePool, staff: &Staff<Administrator>) -> sqlx::Result<Response> {
    let notes = Notes::new(TITLE, VERSION);
    let form = Form { notes: &notes };
    let input = || Opts::new().class("input");
    let languages: Vec<String> = super::inventory::languages(pool).await?.into_iter().map(|(c, _)| c).collect();
    let roles: Vec<&str> = ROLES.iter().map(|r| r.as_str()).collect();
    let mut rows = vec![
        ("Date", form.date("date", &time::date("m/d/Y", unix_now()), input())),
        ("Portal", form.select("portal_id", &choices::portals(pool).await?, "", Blank::Empty, input())),
        ("Industry", form.select("industry_id", &choices::industries(pool).await?, "", Blank::Empty, input())),
        ("Language", form.select("language_code", &list_options(&languages), "", Blank::None, input())),
        ("User Role", form.select("role", &list_options(&roles), "", Blank::None, input())),
        ("Include pending?", form.checkbox("pending", "1", false, input())),
    ];
    if staff.who.user.role >= Role::SuperUser {
        rows.push(("Debug Query?", form.checkbox("debug", "1", false, input())));
    }
    let mut body = notes.render();
    body.push_str(SCRIPT);
    body.push_str(&open(PATH, staff.csrf.as_str(), false));
    body.push_str("<table class='noborder'><tr><td width='50%'><table class='noborder'>");
    for (label, widget) in rows {
        body.push_str(&format!("<tr><td width='20%'>{label}</td><td width='80%'>{widget}</td></tr>"));
    }
    body.push_str("</table></td><td width='50%' id=preview_summary></td></table>");
    body.push_str(&format!("<p>{}</p>", button("Load", Opts::new().onclick("fn_action('load');"))));
    body.push_str(close());
    body.push_str("<div id=preview_output class=preview_content></div><div id=preview_debug class=preview_content></div>");
    Ok(shell::page(pool, staff.view(), TITLE, &body).await)
}

/// The page's script posts by AJAX without a form token, as it always did; `X-Requested-With` marks those posts,
/// and they only read.
async fn handle(auth: &Auth, staff: &Staff<Administrator>, headers: &HeaderMap, fields: Option<Fields>) -> Response {
    let answer = if headers.contains_key("x-requested-with") { ajax(&auth.pool, &fields.unwrap_or_default()).await } else { page(&auth.pool, staff).await };
    answer.unwrap_or_else(shell::internal)
}

async fn show(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, headers: HeaderMap, RawQuery(q): RawQuery) -> Response {
    handle(&auth, &staff, &headers, Some(Fields::from_query(q.as_deref()))).await
}

async fn post(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, headers: HeaderMap, body: Bytes) -> Response {
    handle(&auth, &staff, &headers, Some(Fields::from_query(std::str::from_utf8(&body).ok()))).await
}

pub fn routes() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(show).post(post))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn no_hero_says_so() {
        let out = answer(None);
        assert_eq!(out.last().map(|(k, v)| (k.as_str(), v.as_str())), Some(("preview_summary", Some("No matches found"))));
    }

    #[test]
    fn dates_read_month_first() {
        assert_eq!(mdy("10/30/2026"), "2026-10-30");
        assert_eq!(mdy("2/30/2026"), "");
    }
}
