//! The order viewer: saved orders, newest first, searched by PO number, company and order date, and one order with
//! its lines. Read only, for Executives and above; what orderhd.php showed, without its status form.

use std::sync::Arc;

use axum::{
    extract::{Path, RawQuery, State},
    http::StatusCode,
    response::{IntoResponse, Response},
    routing::get,
    Router,
};
use sqlx::SqlitePool;

use super::{
    admin::{contains, pager, ROWS},
    csrf::Fields,
    html::{esc, number_dp, submit, text, Form, Notes, Opts},
    shell, time, Staff,
};
use crate::api::{Auth, Executive};

pub const PATH: &str = "/staff/orders";
const TITLE: &str = "Orders";

fn when(unix: Option<i64>) -> String {
    unix.filter(|t| *t > 0).map(|t| time::date("m/d/Y h:i A", t)).unwrap_or_default()
}

/// The filters of the list, as typed.
struct Filter {
    po: String,
    company: String,
    from: String,
    thru: String,
    page: i64,
}

impl Filter {
    fn read(f: &Fields) -> Filter {
        let get = |k: &str| f.get(k).trim().to_string();
        Filter { po: get("po"), company: get("company"), from: get("from"), thru: get("thru"), page: f.int("page").clamp(0, i64::MAX / ROWS - 1) }
    }

    /// The order date bounds in unix seconds: from the start of the first Detroit day to the start of the day after
    /// the last. A date that does not read is an error on its field.
    fn dates(&self, notes: &mut Notes) -> (Option<i64>, Option<i64>) {
        let mut day = |field: &str, text: &str, after: i64| {
            if text.is_empty() {
                return None;
            }
            let Some(d) = time::parse_date(text) else {
                notes.error(field, Some("Not a date (mm/dd/yyyy)"));
                return None;
            };
            let (y, m, d) = time::add_days(d, after);
            Some(time::from_local(y, m, d, 0, 0, 0))
        };
        (day("from", &self.from, 0), day("thru", &self.thru, 1))
    }
}

struct Listed {
    id: i64,
    po: Option<String>,
    company_name: Option<String>,
    name: Option<String>,
    email: Option<String>,
    order_date: Option<i64>,
    status: String,
}

async fn orders(pool: &SqlitePool, f: &Filter, from: Option<i64>, thru: Option<i64>) -> sqlx::Result<Vec<Listed>> {
    let po = if f.po.is_empty() { String::new() } else { contains(&f.po) };
    let company = if f.company.is_empty() { String::new() } else { contains(&f.company) };
    let (limit, offset) = (ROWS + 1, f.page * ROWS);
    sqlx::query_as!(
        Listed,
        r#"SELECT id AS "id!: i64", po, company_name, name, email, order_date, status FROM orderhd
           WHERE (?1 = '' OR po LIKE ?1 ESCAPE '\') AND (?2 = '' OR company_name LIKE ?2 ESCAPE '\')
             AND (?3 IS NULL OR order_date >= ?3) AND (?4 IS NULL OR order_date < ?4)
           ORDER BY order_date DESC, id DESC LIMIT ?5 OFFSET ?6"#,
        po,
        company,
        from,
        thru,
        limit,
        offset
    )
    .fetch_all(pool)
    .await
}

async fn list(State(auth): State<Arc<Auth>>, staff: Staff<Executive>, RawQuery(raw): RawQuery) -> Response {
    let f = Filter::read(&Fields::from_query(raw.as_deref()));
    let mut notes = Notes::new(TITLE, "");
    let (from, thru) = f.dates(&mut notes);
    let rows = if notes.has_errors() {
        Vec::new()
    } else {
        match orders(&auth.pool, &f, from, thru).await {
            Ok(rows) => rows,
            Err(e) => return shell::internal(e),
        }
    };
    let form = Form { notes: &notes };
    let field = |label: &str, widget: String| format!("<tr><td width='30%'>{label}</td><td width='70%'>{widget}</td></tr>");
    let dates = format!("{} to {}", notes.with_error("from", form.date("from", &f.from, Opts::new())), notes.with_error("thru", form.date("thru", &f.thru, Opts::new())));
    let mut body = vec![
        notes.render(),
        format!("<form method=get action='{PATH}'>"),
        "<table class='standard noborder'>".to_string(),
        field("PO#", form.text("po", &f.po, 20, 0, Opts::new())),
        field("Company", form.text("company", &f.company, 40, 0, Opts::new())),
        field("Order date", dates),
        "</table>".into(),
        format!("<p>{}</p>\n</form>", submit("List", Opts::new())),
        "<table class='small border'>".into(),
        "<thead><tr><th>Order #</th><th>PO#</th><th>Company</th><th>Name</th><th>Email</th><th>Order Date/Time</th><th>Status</th></tr></thead>".into(),
        "<tbody>".into(),
    ];
    for o in rows.iter().take(ROWS as usize) {
        let cell = |v: &Option<String>| format!("<td>{}</td>", text(v.as_deref().unwrap_or("")));
        body.push(format!(
            "<tr><td class=center><a href=\"{PATH}/{0}\">{0}</a></td>{1}{2}{3}{4}<td class=center>{5}</td><td>{6}</td></tr>",
            o.id,
            cell(&o.po),
            cell(&o.company_name),
            cell(&o.name),
            cell(&o.email),
            when(o.order_date),
            text(&o.status)
        ));
    }
    body.push("</tbody>\n</table>".into());
    let kept = [("po", f.po.as_str()), ("company", f.company.as_str()), ("from", f.from.as_str()), ("thru", f.thru.as_str())];
    body.push(pager(PATH, &kept, f.page, rows.len() as i64 > ROWS));
    shell::page(&auth.pool, staff.view(), TITLE, &body.join("\n")).await
}

async fn detail(State(auth): State<Arc<Auth>>, staff: Staff<Executive>, Path(id): Path<i64>) -> Response {
    let pool = &auth.pool;
    let order = match sqlx::query!(
        r#"SELECT id AS "id!: i64", order_date, status, status_date, po, comment, "type" AS kind, email, name, company_name, phone,
                  address, city, state, zip, country_code, billing_address, billing_city, billing_state, billing_zip, billing_country_code,
                  carrier_name, carrier_account, currency_code, user_id
           FROM orderhd WHERE id = ?1"#,
        id
    )
    .fetch_optional(pool)
    .await
    {
        Ok(Some(o)) => o,
        Ok(None) => return (StatusCode::NOT_FOUND, "No such order\n").into_response(),
        Err(e) => return shell::internal(e),
    };
    let lines = match sqlx::query!(
        r#"SELECT line_no AS "line_no!: i64", sku, description, quantity AS "quantity!: i64", price AS "price!: f64", extended AS "extended!: f64", comment
           FROM orderln WHERE order_id = ?1 ORDER BY line_no, id"#,
        id
    )
    .fetch_all(pool)
    .await
    {
        Ok(lines) => lines,
        Err(e) => return shell::internal(e),
    };
    let t = |v: &Option<String>| text(v.as_deref().unwrap_or(""));
    let joined = |parts: &[&Option<String>]| text(&parts.iter().filter_map(|p| p.as_deref()).map(str::trim).filter(|p| !p.is_empty()).collect::<Vec<_>>().join(", "));
    let fields = [
        ("Order #", order.id.to_string()),
        ("Order Date/Time", when(order.order_date)),
        ("Status", text(&order.status)),
        ("Status Date/Time", when(order.status_date)),
        ("PO#", t(&order.po)),
        ("Type", t(&order.kind)),
        ("Company", t(&order.company_name)),
        ("Name", t(&order.name)),
        ("Email", t(&order.email)),
        ("Phone", t(&order.phone)),
        ("Account", order.user_id.filter(|u| *u > 0).map(|u| u.to_string()).unwrap_or_default()),
        ("Ship to", joined(&[&order.address, &order.city, &order.state, &order.zip, &order.country_code])),
        ("Bill to", joined(&[&order.billing_address, &order.billing_city, &order.billing_state, &order.billing_zip, &order.billing_country_code])),
        ("Carrier", joined(&[&order.carrier_name, &order.carrier_account])),
        ("Currency", t(&order.currency_code)),
        ("Comment", t(&order.comment).replace('\n', "<br>")),
    ];
    let mut body = vec![Notes::new(&format!("Order {}", order.id), "").render(), "<table class='standard noborder'>".to_string()];
    for (label, value) in fields.iter().filter(|(_, v)| !v.is_empty()) {
        body.push(format!("<tr><td width='30%'>{label}</td><td width='70%'>{value}</td></tr>"));
    }
    body.push("</table>".into());
    body.push("<table class='small border'>\n<thead><tr><th>Line</th><th>Part#</th><th>Description</th><th>Qty</th><th>Price</th><th>Extended</th><th>Comment</th></tr></thead>\n<tbody>".into());
    for l in &lines {
        body.push(format!(
            "<tr><td class=center>{}</td><td>{}</td><td>{}</td><td class=right>{}</td><td class=right>{}</td><td class=right>{}</td><td>{}</td></tr>",
            l.line_no,
            text(&l.sku),
            text(&l.description),
            l.quantity,
            number_dp(l.price, 2),
            number_dp(l.extended, 2),
            t(&l.comment)
        ));
    }
    let total: f64 = lines.iter().map(|l| l.extended).sum();
    body.push(format!("</tbody>\n<tfoot><tr><td colspan=5><b>Total</b></td><td class=right><b>{}</b></td><td></td></tr></tfoot>\n</table>", number_dp(total, 2)));
    body.push(format!("<p><a href=\"{}\">Return</a></p>", esc(PATH)));
    shell::page(pool, staff.view(), TITLE, &body.join("\n")).await
}

pub fn routes() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(list)).route("/staff/orders/{id}", get(detail))
}
