//! catalog_metric_filter.php: the Administrators' form for downloading the catalog's metric/imperial filter log. The
//! form opens on the last thirty days. Its download (an Excel file) still comes from PHP.

use std::sync::Arc;

use axum::{extract::State, response::Response, routing::get, Router};

use crate::{
    api::{Administrator, Auth},
    db::unix_now,
    staff::{
        html::{close, hidden, open, submit, Blank, Form, Notes, Opts},
        shell,
        time::{add_days, today},
        Staff,
    },
    visitor::Visitor,
};

const PATH: &str = "/catalog_metric_filter.php";

fn ymd((y, m, d): (i64, u32, u32)) -> String {
    format!("{y:04}/{m:02}/{d:02}")
}

fn options(pairs: &[(&str, &str)]) -> Vec<(String, String)> {
    pairs.iter().map(|(v, l)| (v.to_string(), l.to_string())).collect()
}

async fn show(State(core): State<Arc<Auth>>, staff: Staff<Administrator>, visitor: Visitor) -> Response {
    let notes = Notes::new("Metric Filter", "10/16/2026");
    let form = Form { notes: &notes };
    let day = today(unix_now());
    let subtypes = options(&[("", "All"), ("Unit Filter", "Filter choices"), ("Unit Default", "Country default applied")]);
    let regions = options(&[("", "All"), ("us", "US users"), ("non_us", "Non-US users")]);
    let body = [
        notes.render(),
        open(PATH, staff.csrf.as_str(), false),
        hidden("action", "download"),
        "<p>Downloads the catalog Metric/Imperial filter usage log as an Excel file.</p>".into(),
        "<table class='standard noborder'><tr><td width='30%'>From Date</td>".into(),
        format!("<td width='70%'>{}</td>", form.date("from_date", &ymd(add_days(day, -30)), Opts::new())),
        "</tr><tr><td>Thru Date</td>".into(),
        format!("<td>{}</td>", form.date("thru_date", &ymd(day), Opts::new())),
        "</tr><tr><td>Record type</td>".into(),
        format!("<td>{}</td>", form.select("subtype", &subtypes, "", Blank::None, Opts::new())),
        "</tr><tr><td>Users</td>".into(),
        format!("<td>{}</td>", form.select("region", &regions, "", Blank::None, Opts::new())),
        "</tr></table>".into(),
        format!("<p>{}</p>", submit("Download", Opts::new())),
        close().into(),
    ]
    .concat();
    let view = shell::View { role: staff.who.user.role, wp: staff.wp || visitor.framed };
    shell::page(&core.pool, view, "Metric Filter", &body).await
}

pub fn router() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(show))
}
