//! scs_track_log.php: the Tracking Log. `Track` rows of the event log by date, subtype and address, a page at a
//! time, with the company at that address; ticking "Clear Activity?" deletes the rows the filter selects.
//!
//! The PHP page checked no role, so anyone could read the rows (PHP session ids among them) and delete them. Here
//! it needs an Administrator, the role the dashboard shows its link to.

use std::sync::Arc;

use axum::{extract::State, response::Response, routing::get, Router};
use sqlx::SqlitePool;

use super::{
    csrf::{Fields, Posted},
    html::{close, hidden, number, open, page_items, submit, text, Blank, Form, Notes, Opts, Tabs, PAGE_ROWS},
    shell, time, Staff,
};
use crate::{
    api::{Administrator, Auth},
    db::unix_now,
};

const PATH: &str = "/scs_track_log.php";
const TITLE: &str = "Tracking Log";
const KIND: &str = "Track";

/// The posted filter.
struct Filter {
    listed: bool,
    from: i64,
    thru: i64,
    subtype: String,
    ip: String,
    page: i64,
}

/// strtotime() for the forms the page shows and people type: `m/d/Y h:i A`, `m/d/Y H:i`, `m/d/Y`, `Y-m-d`.
fn strtotime(s: &str) -> i64 {
    let s = s.trim();
    let formats = ["%m/%d/%Y %I:%M %p", "%m/%d/%Y %H:%M", "%m/%d/%Y %H:%M:%S", "%Y-%m-%d %H:%M:%S", "%Y-%m-%d %H:%M", "%Y/%m/%d %H:%M"];
    for f in formats {
        if let Ok(t) = chrono::NaiveDateTime::parse_from_str(s, f) {
            return local(t);
        }
    }
    for f in ["%m/%d/%Y", "%Y-%m-%d", "%Y/%m/%d"] {
        if let Ok(d) = chrono::NaiveDate::parse_from_str(s, f) {
            return local(d.and_hms_opt(0, 0, 0).unwrap_or_default());
        }
    }
    0
}

fn local(t: chrono::NaiveDateTime) -> i64 {
    use chrono::{Datelike, Timelike};
    time::from_local(i64::from(t.year()), t.month(), t.day(), t.hour(), t.minute(), t.second())
}

async fn rows(pool: &SqlitePool, f: &Filter) -> sqlx::Result<(Vec<(String, String, String, i64, String)>, i64)> {
    let offset = (f.page.max(1) - 1) * PAGE_ROWS;
    let total = sqlx::query_scalar!(
        r#"SELECT count(*) AS "n!: i64" FROM log
           WHERE type = ?1 AND (?2 = 0 OR date_time >= ?2) AND (?3 = 0 OR date_time <= ?3) AND (?4 = '' OR subtype = ?4) AND (?5 = '' OR ip = ?5)"#,
        KIND,
        f.from,
        f.thru,
        f.subtype,
        f.ip
    )
    .fetch_one(pool)
    .await?;
    let rows = sqlx::query!(
        r#"SELECT l.ip AS "ip!: String", COALESCE((SELECT company_name FROM "user" u WHERE u.ip = l.ip LIMIT 1), 'Unknown') AS "company!: String",
                  l.subtype AS "subtype!: String", l.date_time AS "date_time!: i64", COALESCE(l.comment, '') AS "comment!: String"
           FROM log l
           WHERE l.type = ?1 AND (?2 = 0 OR l.date_time >= ?2) AND (?3 = 0 OR l.date_time <= ?3) AND (?4 = '' OR l.subtype = ?4) AND (?5 = '' OR l.ip = ?5)
           ORDER BY l.ip, l.subtype, l.date_time LIMIT ?6 OFFSET ?7"#,
        KIND,
        f.from,
        f.thru,
        f.subtype,
        f.ip,
        PAGE_ROWS,
        offset
    )
    .fetch_all(pool)
    .await?;
    Ok((rows.into_iter().map(|r| (r.ip, r.company, r.subtype, r.date_time, r.comment)).collect(), total))
}

fn shown(t: i64) -> String {
    if t == 0 {
        String::new()
    } else {
        time::date("m/d/Y h:i A", t)
    }
}

async fn run(pool: &SqlitePool, staff: &Staff<Administrator>, fields: &Fields) -> sqlx::Result<Response> {
    let mut notes = Notes::new(TITLE, "");
    let action = fields.get("action");
    let f = if action.is_empty() {
        Filter { listed: false, from: unix_now() - 7 * 86_400, thru: 0, subtype: String::new(), ip: String::new(), page: 0 }
    } else {
        Filter {
            listed: true,
            from: strtotime(fields.get("from_timestamp")),
            thru: strtotime(fields.get("thru_timestamp")),
            subtype: fields.get("subtype").to_string(),
            ip: fields.get("ip").trim().to_string(),
            page: fields.int("page"),
        }
    };
    if f.listed && f.thru != 0 && f.from > f.thru {
        notes.error("thru_timestamp", None);
    }
    if f.listed && !notes.has_errors() && fields.checked("clear") {
        let gone = sqlx::query!(
            "DELETE FROM log WHERE type = ?1 AND (?2 = '' OR subtype = ?2) AND (?3 = '' OR ip = ?3) AND (?4 = 0 OR date_time >= ?4) AND (?5 = 0 OR date_time <= ?5)",
            KIND,
            f.subtype,
            f.ip,
            f.from,
            f.thru
        )
        .execute(pool)
        .await?
        .rows_affected();
        notes.say(&format!("{} records deleted", number(gone as i64)));
    }
    let form = Form { notes: &notes };
    let input = [
        "<table class='standard noborder'>".to_string(),
        "<tr>".into(),
        "<td width='30%'>From Date</td>".into(),
        format!("<td width='70%'>{}</td>", form.text("from_timestamp", &shown(f.from), 25, 0, Opts::new())),
        "</tr>".into(),
        "<tr>".into(),
        "<td>Thru Date</td>".into(),
        format!("<td>{}</td>", form.text("thru_timestamp", &shown(f.thru), 25, 0, Opts::new())),
        "</tr>".into(),
        "<tr>".into(),
        "<td>Track type</td>".into(),
        format!("<td>{}</td>", form.select("subtype", &[], &f.subtype, Blank::Empty, Opts::new())),
        "</tr>".into(),
        "<tr>".into(),
        "<td>IP address</td>".into(),
        format!("<td>{}</td>", form.text("ip", &f.ip, 20, 0, Opts::new())),
        "</tr>".into(),
        "<tr>".into(),
        "<td>Clear Activity?</td>".into(),
        format!("<td>{}</td>", form.checkbox("clear", "1", false, Opts::new())),
        "</tr>".into(),
        "</table>".into(),
        format!("<p>{}</p>", submit("Go!", Opts::new())),
    ];
    let mut tabs = Tabs::new();
    tabs.in_wordpress(staff.wp);
    tabs.lines("Input", &input);
    if f.listed && !notes.has_errors() {
        tabs.land_next();
        let (rows, total) = rows(pool, &f).await?;
        if rows.is_empty() {
            tabs.item("Results", "<p>No records found</p>".into());
        } else {
            let mut out: Vec<String> = ["<table class='small tablesorter border'>", "<caption>&nbsp;</caption>", "<thead>", "<tr>", "<th>IP Address</th>", "<th>Company Name</th>"].map(String::from).to_vec();
            if f.subtype.is_empty() {
                out.push("<th>Subtype</th>".into());
            }
            out.extend(["<th>Date/Time</th>", "<th>Comment</th>", "</tr>", "</thead>", "<tbody>"].map(String::from));
            for (ip, company, subtype, at, comment) in rows {
                out.push("<tr>".into());
                out.push(format!("<td>{}</td>", text(&ip)));
                out.push(format!("<td>{}</td>", text(&company)));
                if f.subtype.is_empty() {
                    out.push(format!("<td>{}</td>", text(&subtype)));
                }
                out.push(format!("<td class='center nobr'>{}</td>", time::date("m/d/Y h:i A", at)));
                out.push(format!("<td>{}</td>", text(&comment).replace('\n', "<br>")));
                out.push("</tr>".into());
            }
            out.extend(["</tbody>", "</table>"].map(String::from));
            out.push(page_items(total, f.page, "('list',%page%,0);"));
            tabs.lines("Results", &out);
        }
    }
    let mut body = notes.render();
    body.push_str(&open(PATH, staff.csrf.as_str(), false));
    body.push_str(&hidden("action", "list"));
    body.push_str(&hidden("page", ""));
    body.push_str(&tabs.render());
    body.push_str(close());
    Ok(shell::page(pool, staff.view(), TITLE, &body).await)
}

async fn show(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>) -> Response {
    run(&auth.pool, &staff, &Fields::default()).await.unwrap_or_else(shell::internal)
}

async fn post(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, posted: Posted) -> Response {
    run(&auth.pool, &staff, &posted.fields).await.unwrap_or_else(shell::internal)
}

pub fn routes() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(show).post(post))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn dates_read_as_the_page_shows_them() {
        assert_eq!(strtotime("01/02/2026 03:04 PM"), time::from_local(2026, 1, 2, 15, 4, 0));
        assert_eq!(strtotime("1/2/2026"), time::from_local(2026, 1, 2, 0, 0, 0));
        assert_eq!(strtotime("soon"), 0);
    }
}
