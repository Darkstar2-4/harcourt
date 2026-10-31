//! scs_log.php: the event log, filtered by date, type and account, shown 50 rows a page or exported as TXT or CSV.

use std::sync::Arc;

use axum::{
    extract::{RawQuery, State},
    http::{header, HeaderMap},
    response::{IntoResponse, Response},
    routing::get,
    Router,
};
use base64::{engine::general_purpose::STANDARD_NO_PAD, Engine};
use sqlx::SqlitePool;

use super::{
    csrf::{Fields, Posted},
    html::{button, close, hidden, open, page_break, Blank, Form, Notes, Opts, Tabs, PAGE_ROWS, text},
    shell, time, Staff,
};
use crate::{
    api::{Auth, Executive},
    db::unix_now,
};

const PATH: &str = "/scs_log.php";
const TITLE: &str = "Event Log";
const VERSION: &str = "09/25/2025";

/// log_constant_class::type: each type with its subtypes.
const TYPES: &[(&str, &[&str])] = &[
    ("User", &["Login", "Remote Login", "Download", "Signup", "Email", "PO", "Click Through"]),
    ("Page", &["General", "Solutions", "Products", "Portal"]),
    ("Catalog", &["Unit Filter", "Unit Default"]),
    ("Search", &["General", "Category", "Subcategory", "SKU", "Competitor SKU", "Lookup"]),
    ("PARTSolutions", &["Reverse Lookup Error", "CAD", "Quick CAD", "Quick CAD Error", "PDF Datasheet", "Configurator", "Security"]),
    ("Initial", &["Campaign", "Referer"]),
    ("IPStack", &["Success", "Error"]),
    ("CRON", &[]),
    ("Export Table", &[]),
    ("Import Table", &[]),
    ("MySQL", &[]),
    ("Email", &[]),
    ("Email Error", &[]),
    ("Error", &[]),
    ("BOT", &[]),
    ("Ajax", &[]),
    ("Blacklist", &["Denied", "Add", "Remove"]),
];

type Date = (i64, u32, u32);

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Output {
    Screen,
    Txt,
    Csv,
}

impl Output {
    fn parse(s: &str) -> Output {
        match s {
            "txt" => Output::Txt,
            "csv" => Output::Csv,
            _ => Output::Screen,
        }
    }

    fn as_str(self) -> &'static str {
        match self {
            Output::Screen => "",
            Output::Txt => "txt",
            Output::Csv => "csv",
        }
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
struct Input {
    action: String,
    page: i64,
    from: Option<Date>,
    thru: Option<Date>,
    type_subtype: String,
    kind: String,
    subtype: String,
    user_id: i64,
    output: Output,
}

/// `fn_base64(array($type, $subtype))`: the value of an option in the type select.
fn type_code(kind: &str, subtype: &str) -> String {
    STANDARD_NO_PAD.encode(format!("{kind}\t{subtype}"))
}

fn decode_type(code: &str) -> (String, String) {
    let bytes = STANDARD_NO_PAD.decode(code.trim_end_matches('=')).unwrap_or_default();
    let text = String::from_utf8_lossy(&bytes).into_owned();
    match text.split_once('\t') {
        Some((k, s)) => (k.to_string(), s.to_string()),
        None => (text, String::new()),
    }
}

/// local_class::input(): defaults on first view and for `?action=login`, the posted filters otherwise.
fn parse(fields: &Fields, now: i64, notes: &mut Notes) -> Input {
    let action = fields.get("action").to_string();
    let today = time::today(now);
    let week_ago = time::add_days(today, -7);
    let mut input = Input {
        action: action.clone(),
        page: fields.int("scscpq_page").max(0),
        from: Some(week_ago),
        thru: Some(today),
        type_subtype: String::new(),
        kind: String::new(),
        subtype: String::new(),
        user_id: 0,
        output: Output::Screen,
    };
    match action.as_str() {
        "" => {}
        "login" => input.user_id = fields.int("user_id"),
        _ => {
            input.from = date_field(fields, "from_date", notes);
            input.thru = date_field(fields, "thru_date", notes);
            if let (Some(f), Some(t)) = (input.from, input.thru) {
                if f > t {
                    notes.error("thru_date", None);
                }
            }
            input.type_subtype = fields.get("type_subtype").to_string();
            (input.kind, input.subtype) = decode_type(&input.type_subtype);
            input.user_id = fields.int("ajax_user_id").max(0);
            input.output = Output::parse(fields.get("output"));
        }
    }
    input
}

fn date_field(fields: &Fields, name: &str, notes: &mut Notes) -> Option<Date> {
    let text = fields.get(name).trim();
    if text.is_empty() {
        return None;
    }
    let date = time::parse_date(text);
    if date.is_none() {
        notes.error(name, None);
    }
    date
}

fn show_date(d: Option<Date>) -> String {
    d.map_or(String::new(), |(y, m, dd)| format!("{m:02}/{dd:02}/{y}"))
}

struct Row {
    ip: String,
    email: String,
    company_name: String,
    date_time: i64,
    kind: String,
    subtype: String,
    sku: String,
    comment: String,
}

async fn rows(pool: &SqlitePool, input: &Input, limit: i64, offset: i64) -> sqlx::Result<(Vec<Row>, i64)> {
    let from = input.from.map(|(y, m, d)| time::from_local(y, m, d, 0, 0, 0));
    let thru = input.thru.map(|(y, m, d)| {
        let (y, m, d) = time::add_days((y, m, d), 1);
        time::from_local(y, m, d, 0, 0, 0) - 1
    });
    let found = sqlx::query_scalar!(
        r#"SELECT count(*) AS "n!: i64" FROM log
           WHERE bot_id = 0 AND (?1 IS NULL OR date_time >= ?1) AND (?2 IS NULL OR date_time <= ?2)
             AND (?3 = '' OR type = ?3) AND (?4 = '' OR subtype = ?4) AND (?5 = 0 OR user_id = ?5)"#,
        from,
        thru,
        input.kind,
        input.subtype,
        input.user_id
    )
    .fetch_one(pool)
    .await?;
    let rows = sqlx::query!(
        r#"SELECT l.ip AS "ip!", l.email, u.company_name AS "company_name?", l.date_time AS "date_time!: i64", l.type AS "kind!",
                  l.subtype, l.sku AS "sku!", l.comment
           FROM log l LEFT JOIN "user" u ON u.id = l.user_id
           WHERE l.bot_id = 0 AND (?1 IS NULL OR l.date_time >= ?1) AND (?2 IS NULL OR l.date_time <= ?2)
             AND (?3 = '' OR l.type = ?3) AND (?4 = '' OR l.subtype = ?4) AND (?5 = 0 OR l.user_id = ?5)
           ORDER BY l.date_time DESC, l.id DESC LIMIT ?6 OFFSET ?7"#,
        from,
        thru,
        input.kind,
        input.subtype,
        input.user_id,
        limit,
        offset
    )
    .fetch_all(pool)
    .await?;
    let rows = rows
        .into_iter()
        .map(|r| Row {
            ip: r.ip,
            email: r.email.unwrap_or_default(),
            company_name: r.company_name.unwrap_or_default(),
            date_time: r.date_time,
            kind: r.kind,
            subtype: r.subtype.unwrap_or_default(),
            sku: r.sku,
            comment: r.comment.unwrap_or_default(),
        })
        .collect();
    Ok((rows, found))
}

/// PHP fputcsv(): a field is quoted when it holds the delimiter, a quote, a backslash or white space.
fn csv_line(fields: &[String], delimiter: char) -> String {
    let cells: Vec<String> = fields
        .iter()
        .map(|f| {
            if f.chars().any(|c| c == delimiter || matches!(c, '"' | '\\' | '\n' | '\r' | '\t' | ' ')) {
                format!("\"{}\"", f.replace('"', "\"\""))
            } else {
                f.clone()
            }
        })
        .collect();
    cells.join(&delimiter.to_string()) + "\n"
}

fn export(rows: &[Row], output: Output) -> Response {
    let delimiter = if output == Output::Txt { '\t' } else { ',' };
    let header = ["IP Address", "Email", "Company Name", "Date/Time", "Type", "Subtype", "SKU", "Comment"].map(String::from);
    let mut body = csv_line(&header, delimiter);
    for r in rows {
        let line = [
            r.ip.clone(),
            r.email.clone(),
            r.company_name.clone(),
            time::date("m/d/Y h:i:s A", r.date_time),
            r.kind.clone(),
            r.subtype.clone(),
            r.sku.clone(),
            r.comment.replace('\n', " "),
        ];
        body.push_str(&csv_line(&line, delimiter));
    }
    (
        [
            (header::CONTENT_TYPE, "text/plain".to_string()),
            (header::CONTENT_DISPOSITION, format!("attachment; filename=log.{}", output.as_str())),
            (header::PRAGMA, "no-cache".to_string()),
            (header::EXPIRES, "0".to_string()),
            (header::CACHE_CONTROL, "no-store".to_string()),
        ],
        body,
    )
        .into_response()
}

const SCRIPT: &str = "
<script>
function toolbox_page_item(action, page, item) {
\tjQuery('#action').val(action)
\tjQuery('#scscpq_page').val(page)
\tjQuery('#scscpq_item').val(item)
\tjQuery('#scs_form').submit();
}
</script>";

fn input_tab(form: &Form<'_>, input: &Input, company: &str) -> String {
    let mut types: Vec<(String, String)> = Vec::new();
    for (kind, subtypes) in TYPES {
        types.push((type_code(kind, ""), format!("{} (All)", text(kind))));
        for s in *subtypes {
            types.push((type_code(kind, s), format!("&nbsp;{}", text(s))));
        }
    }
    let user = if input.user_id == 0 { String::new() } else { input.user_id.to_string() };
    let outputs = super::html::list_options(&["txt", "csv"]);
    [
        "<table class='standard noborder'>".to_string(),
        "<tr>".into(),
        "<td width='30%'>From Date</td>".into(),
        format!("<td width='70%'>{}</td>", form.date("from_date", &show_date(input.from), Opts::new())),
        "</tr>".into(),
        "<tr>".into(),
        "<td>Thru Date</td>".into(),
        format!("<td>{}</td>", form.date("thru_date", &show_date(input.thru), Opts::new())),
        "</tr>".into(),
        "<tr>".into(),
        "<td>Record type</td>".into(),
        format!("<td>{}</td>", form.select("type_subtype", &types, &input.type_subtype, Blank::Empty, Opts::new())),
        "</tr>".into(),
        "<tr>".into(),
        "<td>User ID</td>".into(),
        format!("<td>{} <b><span id=ajax_user_label>{}</span></b></td>", form.text("ajax_user_id", &user, 20, 0, Opts::new()), text(company)),
        "</tr>".into(),
        "<tr>".into(),
        "<td>Export?</td>".into(),
        format!("<td>{}</td>", form.select("output", &outputs, input.output.as_str(), Blank::Empty, Opts::new())),
        "</tr>".into(),
        "</table>".into(),
        format!("<p>{}</p>", button("Go!", Opts::new().onclick("toolbox_page_item('list',0,0);"))),
    ]
    .join("\n")
}

fn results_tab(rows: &[Row], found: i64, input: &Input) -> String {
    if rows.is_empty() {
        return "<p>No records found</p>".into();
    }
    let mut out = vec![
        "<table class='small tablesorter border'>".to_string(),
        "<caption>&nbsp;</caption>".into(),
        "<thead>".into(),
        "<tr>".into(),
        "<th>Email</th>".into(),
        "<th>Company Name</th>".into(),
        "<th>Date/Time</th>".into(),
    ];
    if input.kind.is_empty() {
        out.push("<th>Type</th>".into());
    }
    if input.subtype.is_empty() {
        out.push("<th>Subtype</th>".into());
    }
    out.extend(["<th>SKU</th>", "<th>Comment</th>", "</tr>", "</thead>", "<tbody>"].map(String::from));
    for r in rows {
        out.push("<tr>".into());
        out.push(format!("<td>{}</td>", text(&r.email)));
        out.push(format!("<td>{}</td>", text(&r.company_name)));
        out.push(format!("<td class='center nobr'>{}</td>", time::date("m/d/Y h:i A", r.date_time)));
        if input.kind.is_empty() {
            out.push(format!("<td>{}</td>", text(&r.kind)));
        }
        if input.subtype.is_empty() {
            out.push(format!("<td>{}</td>", text(&r.subtype)));
        }
        out.push(format!("<td class=nobr>{}</td>", text(&r.sku)));
        out.push(format!("<td>{}</td>", text(&r.comment).replace('\n', "<br>")));
        out.push("</tr>".into());
    }
    out.push("</tbody>".into());
    out.push("</table>".into());
    out.push(page_break(found, input.page, "toolbox_page_item('list',%page%,0);"));
    out.join("\n")
}

async fn run(pool: &SqlitePool, staff: &Staff<Executive>, fields: &Fields, headers: &HeaderMap) -> sqlx::Result<Response> {
    if headers.contains_key("x-requested-with") {
        return Ok(([(header::CONTENT_TYPE, "application/json"), (header::CACHE_CONTROL, "no-store")], "{}").into_response());
    }
    let mut notes = Notes::new(TITLE, VERSION);
    let input = parse(fields, unix_now(), &mut notes);
    let mut company = String::new();
    if input.user_id != 0 && !matches!(input.action.as_str(), "" | "login") {
        match sqlx::query_scalar!(r#"SELECT company_name FROM "user" WHERE id = ?1"#, input.user_id).fetch_optional(pool).await? {
            Some(name) => company = name.unwrap_or_default(),
            None => notes.error("ajax_user_id", None),
        }
    }
    if input.output != Output::Screen && !notes.has_errors() {
        let (rows, _) = rows(pool, &input, -1, 0).await?;
        if !rows.is_empty() {
            return Ok(export(&rows, input.output));
        }
        notes.say("No records found");
        notes.error("null", None);
    }
    let mut tabs = Tabs::new();
    tabs.in_wordpress(staff.wp);
    let form = Form { notes: &notes };
    tabs.item("Input", input_tab(&form, &input, &company));
    if !input.action.is_empty() && !notes.has_errors() {
        tabs.land_next();
        let (rows, found) = rows(pool, &input, PAGE_ROWS, input.page * PAGE_ROWS).await?;
        tabs.item("Results", results_tab(&rows, found, &input));
    }
    let body = [
        notes.render(),
        SCRIPT.to_string(),
        open(PATH, staff.csrf.as_str(), false),
        hidden("action", ""),
        hidden("scscpq_page", ""),
        hidden("scscpq_item", ""),
        tabs.render(),
        close().to_string(),
    ]
    .concat();
    Ok(shell::page(pool, staff.view(), TITLE, &body).await)
}

async fn show(State(auth): State<Arc<Auth>>, staff: Staff<Executive>, RawQuery(q): RawQuery, headers: HeaderMap) -> Response {
    let mut fields = Fields::from_query(q.as_deref());
    if fields.get("action") != "login" {
        fields = Fields::default();
    }
    run(&auth.pool, &staff, &fields, &headers).await.unwrap_or_else(shell::internal)
}

async fn post(State(auth): State<Arc<Auth>>, staff: Staff<Executive>, RawQuery(q): RawQuery, headers: HeaderMap, posted: Posted) -> Response {
    let fields = posted.fields.with_query(&Fields::from_query(q.as_deref()));
    run(&auth.pool, &staff, &fields, &headers).await.unwrap_or_else(shell::internal)
}

pub fn routes() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(show).post(post))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn type_codes_round_trip_like_fn_base64() {
        assert_eq!(type_code("User", "Login"), "VXNlcglMb2dpbg");
        assert_eq!(decode_type("VXNlcglMb2dpbg"), ("User".into(), "Login".into()));
        assert_eq!(decode_type(&type_code("CRON", "")), ("CRON".into(), String::new()));
        assert_eq!(decode_type(""), (String::new(), String::new()));
    }

    #[test]
    fn first_view_covers_the_last_seven_days() {
        let mut notes = Notes::new(TITLE, "");
        let now = time::from_local(2025, 3, 5, 12, 0, 0);
        let input = parse(&Fields::default(), now, &mut notes);
        assert_eq!((input.from, input.thru), (Some((2025, 2, 26)), Some((2025, 3, 5))));
        assert!(input.action.is_empty());
    }

    #[test]
    fn posted_filters_are_checked() {
        let mut notes = Notes::new(TITLE, "");
        let f = Fields::of(&[("action", "list"), ("from_date", "03/05/2025"), ("thru_date", "03/01/2025"), ("output", "csv"), ("type_subtype", &type_code("Search", "SKU"))]);
        let input = parse(&f, 0, &mut notes);
        assert!(notes.is_error("thru_date"));
        assert_eq!((input.kind.as_str(), input.subtype.as_str(), input.output), ("Search", "SKU", Output::Csv));
    }

    #[test]
    fn csv_quotes_like_fputcsv() {
        let line = csv_line(&["a".into(), "b c".into(), "say \"hi\"".into(), "x,y".into(), String::new()], ',');
        assert_eq!(line, "a,\"b c\",\"say \"\"hi\"\"\",\"x,y\",\n");
        assert_eq!(csv_line(&["a b".into(), "c".into()], '\t'), "\"a b\"\tc\n");
    }
}
