//! The staff table admin: one mechanism that lists (search, sort, pages), creates, edits and deletes the rows of
//! every table in `tables::SPECS`, as plain forms. A spec is static: the SQL text is built only from its
//! identifiers, every value is bound, and a test checks each spec against the migrated schema.
//!
//! Every page takes `Staff<Executive>` and then the spec's role for what it does. Every write is a POST carrying the
//! session's CSRF token (`Posted`); a GET only reads.

use std::sync::Arc;

use axum::{
    extract::{Path, RawQuery, State},
    http::{header, StatusCode},
    response::{IntoResponse, Response},
    routing::{get, post},
    Router,
};
use sqlx::{error::ErrorKind, sqlite::SqliteArguments, query::Query, Row, Sqlite, SqlitePool};

use super::{
    csrf::{Fields, Posted},
    html::{close, esc, number, open, submit, url_query, Blank, Form, Notes, Opts},
    shell, tables, time, Staff,
};
use crate::{
    api::{Auth, Executive},
    audit::{self, Entry},
    db::unix_now,
    identity::Role,
};

pub const PATH: &str = "/staff/admin";
pub(super) const ROWS: i64 = 50;

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Kind {
    Text,
    LongText,
    Integer,
    Decimal,
    /// `YYYY-MM-DD` text; MySQL's `0000-00-00` reads as no date.
    Date,
    Flag,
    /// (stored value, label).
    Choice(&'static [(&'static str, &'static str)]),
    /// Unix seconds, shown as a date and time and never typed in.
    Time(Touch),
}

/// When the program sets a `Time` column to now.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Touch {
    Never,
    Created,
    Saved,
}

impl Kind {
    pub fn sql_type(self) -> &'static str {
        match self {
            Kind::Integer | Kind::Flag | Kind::Time(_) => "INTEGER",
            Kind::Decimal => "REAL",
            _ => "TEXT",
        }
    }
}

#[derive(Clone, Copy, Debug)]
pub struct Col {
    pub name: &'static str,
    pub label: &'static str,
    pub kind: Kind,
    pub listed: bool,
    pub editable: bool,
    pub required: bool,
    /// As the schema has it: whether a cleared field stores NULL or the zero of its kind.
    pub nullable: bool,
}

pub const fn col(name: &'static str, label: &'static str, kind: Kind) -> Col {
    Col { name, label, kind, listed: false, editable: !matches!(kind, Kind::Time(_)), required: false, nullable: true }
}

impl Col {
    pub const fn list(self) -> Col {
        Col { listed: true, ..self }
    }

    pub const fn req(self) -> Col {
        Col { required: true, ..self }
    }

    pub const fn fixed(self) -> Col {
        Col { editable: false, ..self }
    }

    pub const fn not_null(self) -> Col {
        Col { nullable: false, ..self }
    }
}

pub enum Search {
    /// A small table: any of these columns contains the text.
    Contains(&'static [&'static str]),
    /// A large table: the column, which leads an index, starts with the text in capitals, and pages follow that
    /// index, so no request reads the whole table.
    Code(&'static str),
}

/// One table. Its primary key is the integer `id`. A role of None means no one does that here.
pub struct Spec {
    pub table: &'static str,
    pub title: &'static str,
    pub columns: &'static [Col],
    pub search: Search,
    pub order: &'static str,
    pub list: Role,
    pub edit: Option<Role>,
    pub create: Option<Role>,
    pub delete: Option<Role>,
    /// A listed Choice column holding an account role: a row above the editor's own role is listed but cannot be
    /// opened, and the column offers no role above the editor's.
    pub rank: Option<&'static str>,
}

impl Spec {
    fn path(&self) -> String {
        format!("{PATH}/{}", self.table)
    }

    fn sort_column(&self, name: &str) -> &'static str {
        match self.search {
            Search::Code(c) => c,
            Search::Contains(_) => self.columns.iter().find(|c| c.listed && c.name == name).map_or(self.order, |c| c.name),
        }
    }

    /// Whether a row, given as the values of the named columns, holds a role above `role`.
    fn outranks<'a>(&self, names: impl IntoIterator<Item = &'a str>, values: &[Option<String>], role: Role) -> bool {
        let Some(rank) = self.rank else { return false };
        let at = names.into_iter().position(|n| n == rank);
        at.and_then(|i| values.get(i)).and_then(|v| v.as_deref()).and_then(Role::parse).is_none_or(|r| r > role)
    }
}

/// A quoted identifier. Only the static specs reach here; the schema test holds them to `[a-z_][a-z0-9_]*`.
fn q(name: &'static str) -> String {
    format!("\"{name}\"")
}

fn cast(name: &'static str) -> String {
    format!("CAST({} AS TEXT)", q(name))
}

/// `%text%` for LIKE, with LIKE's own characters taken literally.
pub(super) fn contains(text: &str) -> String {
    let mut out = String::from("%");
    for c in text.chars() {
        if matches!(c, '%' | '_' | '\\') {
            out.push('\\');
        }
        out.push(c);
    }
    out.push('%');
    out
}

fn list_sql(spec: &'static Spec, sort: &'static str, desc: bool, searching: bool) -> String {
    let columns: Vec<String> = spec.columns.iter().filter(|c| c.listed).map(|c| cast(c.name)).collect();
    let filter = match spec.search {
        _ if !searching => String::new(),
        Search::Contains(cols) => format!(" WHERE {}", cols.iter().map(|&c| format!("{} LIKE ?1 ESCAPE '\\'", cast(c))).collect::<Vec<_>>().join(" OR ")),
        Search::Code(c) => format!(" WHERE {0} >= ?1 AND {0} < ?1 || char(1114111)", q(c)),
    };
    let dir = if desc { "DESC" } else { "ASC" };
    format!("SELECT \"id\", {} FROM {}{filter} ORDER BY {} {dir}, \"id\" {dir} LIMIT ?2 OFFSET ?3", columns.join(", "), q(spec.table), q(sort))
}

fn row_sql(spec: &'static Spec) -> String {
    let columns: Vec<String> = spec.columns.iter().map(|c| cast(c.name)).collect();
    format!("SELECT {} FROM {} WHERE \"id\" = ?1", columns.join(", "), q(spec.table))
}

/// A value to bind.
#[derive(Debug, Clone, PartialEq)]
enum Value {
    Null,
    Int(i64),
    Real(f64),
    Text(String),
}

fn bind<'q>(query: Query<'q, Sqlite, SqliteArguments<'q>>, v: &Value) -> Query<'q, Sqlite, SqliteArguments<'q>> {
    match v {
        Value::Null => query.bind(None::<String>),
        Value::Int(i) => query.bind(*i),
        Value::Real(f) => query.bind(*f),
        Value::Text(s) => query.bind(s.clone()),
    }
}

const ZERO_DATE: &str = "0000-00-00";

fn zero(kind: Kind) -> Value {
    match kind {
        Kind::Integer | Kind::Flag | Kind::Time(_) => Value::Int(0),
        Kind::Decimal => Value::Real(0.0),
        Kind::Date => Value::Text(ZERO_DATE.into()),
        _ => Value::Text(String::new()),
    }
}

/// The columns a save writes, with their values: the edited ones, and the times it sets.
type Writes = Vec<(&'static str, Value)>;

fn writes(spec: &'static Spec, edited: Writes, creating: bool) -> Writes {
    let now = unix_now();
    let touched = spec.columns.iter().filter(|c| c.kind == Kind::Time(Touch::Saved) || (creating && c.kind == Kind::Time(Touch::Created)));
    edited.into_iter().chain(touched.map(|c| (c.name, Value::Int(now)))).collect()
}

async fn insert(pool: &SqlitePool, spec: &'static Spec, w: &Writes) -> sqlx::Result<i64> {
    let names: Vec<String> = w.iter().map(|(n, _)| q(n)).collect();
    let marks: Vec<String> = (1..=w.len()).map(|i| format!("?{i}")).collect();
    let sql = format!("INSERT INTO {} ({}) VALUES ({})", q(spec.table), names.join(", "), marks.join(", "));
    let query = w.iter().fold(sqlx::query(&sql), |query, (_, v)| bind(query, v));
    Ok(query.execute(pool).await?.last_insert_rowid())
}

async fn update(pool: &SqlitePool, spec: &'static Spec, id: i64, w: &Writes) -> sqlx::Result<()> {
    let sets: Vec<String> = w.iter().enumerate().map(|(i, (n, _))| format!("{} = ?{}", q(n), i + 1)).collect();
    let sql = format!("UPDATE {} SET {} WHERE \"id\" = ?{}", q(spec.table), sets.join(", "), w.len() + 1);
    let query = w.iter().fold(sqlx::query(&sql), |query, (_, v)| bind(query, v));
    query.bind(id).execute(pool).await?;
    Ok(())
}

async fn delete_row(pool: &SqlitePool, spec: &'static Spec, id: i64) -> sqlx::Result<()> {
    sqlx::query(&format!("DELETE FROM {} WHERE \"id\" = ?1", q(spec.table))).bind(id).execute(pool).await?;
    Ok(())
}

/// The options a Choice column offers this editor: its list, without roles above the editor's on the rank column,
/// plus a stored value the list lacks, so an edit elsewhere keeps it.
fn options(spec: &Spec, col: &Col, role: Role, current: Option<&str>) -> Vec<(String, String)> {
    let Kind::Choice(list) = col.kind else { return Vec::new() };
    let ranked = spec.rank == Some(col.name);
    let mut out: Vec<(String, String)> =
        list.iter().filter(|(v, _)| !ranked || Role::parse(v).is_some_and(|r| r <= role)).map(|(v, l)| (v.to_string(), esc(l))).collect();
    if let Some(c) = current.filter(|c| !ranked && !c.is_empty() && !out.iter().any(|(v, _)| v == c)) {
        out.push((c.to_string(), esc(c)));
    }
    out
}

/// One posted field as the value to store, or what is wrong with it. An empty field leaves a stored value that
/// shows as empty as it was (the legacy tables hold NULL, '' and the zero date side by side); a cleared one is
/// NULL in a nullable column and the zero of its kind otherwise.
fn parse(col: &Col, f: &Fields, current: Option<&str>, allowed: &[(String, String)]) -> Result<Value, String> {
    if col.kind == Kind::Flag {
        return Ok(Value::Int(i64::from(f.checked(col.name))));
    }
    let raw = f.get(col.name);
    let text = if col.kind == Kind::LongText { raw.replace("\r\n", "\n") } else { raw.trim().to_string() };
    if text.trim().is_empty() {
        return match current {
            _ if col.required => Err(format!("{} is required", col.label)),
            Some(c) if c.is_empty() || (col.kind == Kind::Date && c == ZERO_DATE) => Ok(Value::Text(c.to_string())),
            _ if col.nullable => Ok(Value::Null),
            _ => Ok(zero(col.kind)),
        };
    }
    let wrong = |what: &str| format!("{} must be {what}", col.label);
    match col.kind {
        Kind::Integer => text.parse().map(Value::Int).map_err(|_| wrong("a whole number")),
        Kind::Decimal => text.parse::<f64>().ok().filter(|v| v.is_finite()).map(Value::Real).ok_or_else(|| wrong("a number")),
        Kind::Date => time::parse_date(&text).map(|(y, m, d)| Value::Text(format!("{y:04}-{m:02}-{d:02}"))).ok_or_else(|| wrong("a date (mm/dd/yyyy)")),
        Kind::Choice(_) if !allowed.iter().any(|(v, _)| *v == text) => Err(wrong("one of the listed values")),
        _ => Ok(Value::Text(text)),
    }
}

/// The posted form as the edited columns' values, each error noted on its field. `stored` is the row being edited.
fn read_form(spec: &'static Spec, f: &Fields, role: Role, stored: Option<&[Option<String>]>, notes: &mut Notes) -> Writes {
    let mut out = Vec::new();
    for (i, col) in spec.columns.iter().enumerate().filter(|(_, c)| c.editable) {
        let current = stored.and_then(|s| s.get(i)).and_then(|v| v.as_deref());
        match parse(col, f, current, &options(spec, col, role, current)) {
            Ok(v) => out.push((col.name, v)),
            Err(e) => notes.error(col.name, Some(&e)),
        }
    }
    out
}

/// A stored value as a list cell or a read-only field: HTML.
fn shown(col: &Col, v: Option<&str>) -> String {
    let v = v.unwrap_or("");
    match col.kind {
        Kind::Flag => if v == "1" { "Yes" } else { "" }.into(),
        Kind::Time(_) => v.parse::<i64>().ok().filter(|t| *t > 0).map(|t| time::date("m/d/Y h:i A", t)).unwrap_or_default(),
        Kind::Date if v == ZERO_DATE => String::new(),
        Kind::Choice(list) => esc(list.iter().find(|(s, _)| *s == v).map_or(v, |(_, l)| l)),
        _ => esc(v),
    }
}

fn widget(form: &Form, spec: &Spec, col: &Col, value: &str, role: Role) -> String {
    let w = match col.kind {
        Kind::LongText => form.textarea(col.name, value, 4, 80, Opts::new()),
        Kind::Flag => form.checkbox(col.name, "1", value == "1", Opts::new()),
        Kind::Date => form.date(col.name, if value == ZERO_DATE { "" } else { value }, Opts::new()),
        Kind::Integer | Kind::Decimal => form.text(col.name, value, 12, 0, Opts::new()),
        Kind::Choice(_) => {
            let opts = options(spec, col, role, Some(value));
            let blank = if col.required || opts.iter().any(|(v, _)| v.is_empty()) { Blank::None } else { Blank::Empty };
            form.select(col.name, &opts, value, blank, Opts::new())
        }
        _ => form.text(col.name, value, 60, 0, Opts::new()),
    };
    form.notes.with_error(col.name, w)
}

fn found(to: &str) -> Response {
    (StatusCode::FOUND, [(header::LOCATION, to.to_string()), (header::CACHE_CONTROL, "no-store".to_string())]).into_response()
}

fn not_found() -> Response {
    (StatusCode::NOT_FOUND, [(header::CACHE_CONTROL, "no-store")], "No such row\n").into_response()
}

/// The answer a page stops early with.
struct Stop(Box<Response>);

impl From<Response> for Stop {
    fn from(r: Response) -> Stop {
        Stop(Box::new(r))
    }
}

impl IntoResponse for Stop {
    fn into_response(self) -> Response {
        *self.0
    }
}

type Page = Result<Response, Stop>;

/// The spec for the path and the staff member's right to do this with it. A table without the action is not
/// found; a role below it is sent home as `Staff<R>` sends it.
fn gate(staff: &Staff<Executive>, table: &str, need: fn(&Spec) -> Option<Role>) -> Result<&'static Spec, Stop> {
    let spec = tables::find(table).ok_or_else(not_found)?;
    match need(spec) {
        None => Err(not_found().into()),
        Some(r) if staff.who.user.role < r => Err(super::go_home(staff.wp).into()),
        Some(_) => Ok(spec),
    }
}

/// The row as stored text in spec order. Missing, or above the editor's rank: not found.
async fn stored(pool: &SqlitePool, spec: &'static Spec, id: i64, role: Role) -> Result<Vec<Option<String>>, Stop> {
    let row = sqlx::query(&row_sql(spec)).bind(id).fetch_optional(pool).await.map_err(shell::internal)?.ok_or_else(not_found)?;
    let values = (0..spec.columns.len()).map(|i| row.try_get::<Option<String>, _>(i)).collect::<Result<Vec<_>, _>>().map_err(shell::internal)?;
    if spec.outranks(spec.columns.iter().map(|c| c.name), &values, role) {
        return Err(not_found().into());
    }
    Ok(values)
}

fn title(spec: &Spec, id: Option<i64>) -> String {
    id.map_or_else(|| format!("{}: New", spec.title), |id| format!("{} #{id}", spec.title))
}

fn allows(role: Role, need: Option<Role>) -> bool {
    need.is_some_and(|r| role >= r)
}

/// The edit or new form. `values` are in spec order, as stored or as posted.
async fn form_page(pool: &SqlitePool, staff: &Staff<Executive>, spec: &'static Spec, id: Option<i64>, values: &[Option<String>], notes: &Notes) -> Response {
    let role = staff.who.user.role;
    let form = Form { notes };
    let action = id.map_or_else(|| format!("{}/new", spec.path()), |id| format!("{}/{id}", spec.path()));
    let mut body = vec![notes.render(), open(&action, staff.csrf.as_str(), false), "<table class='standard noborder'>".to_string()];
    for (col, value) in spec.columns.iter().zip(values) {
        let value = value.as_deref().unwrap_or("");
        let cell = match () {
            _ if col.editable => widget(&form, spec, col, value, role),
            _ if id.is_some() => shown(col, Some(value)),
            _ => continue,
        };
        let mark = if col.required && col.editable { " *" } else { "" };
        body.push(format!("<tr><td width='30%'>{}{mark}</td><td width='70%'>{cell}</td></tr>", esc(col.label)));
    }
    body.push("</table>".into());
    let mut buttons = vec![submit("Save", Opts::new())];
    if let Some(id) = id.filter(|_| allows(role, spec.delete)) {
        let opts = Opts::new().set("formaction", esc(&format!("{}/{id}/delete", spec.path()))).onclick("return confirm('Delete this row?');");
        buttons.push(submit("Delete", opts));
    }
    buttons.push(format!("<a href=\"{}\">Return</a>", esc(&spec.path())));
    body.push(format!("<p>{}</p>", buttons.join(" ")));
    body.push(close().into());
    shell::page(pool, staff.view(), &title(spec, id), &body.join("\n")).await
}

/// Previous and next links that keep the page's other query values.
pub(super) fn pager(path: &str, keep: &[(&str, &str)], page: i64, more: bool) -> String {
    let link = |to: i64, label: &str| {
        let to = to.to_string();
        let mut pairs = keep.to_vec();
        if to != "0" {
            pairs.push(("page", &to));
        }
        format!("<a href=\"{}\">{label}</a>", esc(&format!("{path}{}", url_query(&pairs))))
    };
    let mut parts = Vec::new();
    if page > 0 {
        parts.push(link(page - 1, "&lt; Previous"));
    }
    parts.push(format!("Page {}", number(page.saturating_add(1))));
    if more {
        parts.push(link(page + 1, "Next &gt;"));
    }
    format!("<p class='standard center'>{}</p>", parts.join(" | "))
}

/// The query text a list was asked for, as the spec searches it.
fn search_value(spec: &Spec, text: &str) -> String {
    match spec.search {
        Search::Contains(_) => contains(text),
        Search::Code(_) => text.to_uppercase(),
    }
}

async fn list(State(auth): State<Arc<Auth>>, staff: Staff<Executive>, Path(table): Path<String>, RawQuery(raw): RawQuery) -> Page {
    let spec = gate(&staff, &table, |s| Some(s.list))?;
    let role = staff.who.user.role;
    let f = Fields::from_query(raw.as_deref());
    let text = f.get("q").trim();
    let page = f.int("page").clamp(0, i64::MAX / ROWS - 1);
    let sort = spec.sort_column(f.get("sort"));
    let desc = f.get("dir") == "desc";
    let sql = list_sql(spec, sort, desc, !text.is_empty());
    let rows = sqlx::query(&sql).bind(search_value(spec, text)).bind(ROWS + 1).bind(page * ROWS).fetch_all(&auth.pool).await.map_err(shell::internal)?;
    let listed: Vec<&Col> = spec.columns.iter().filter(|c| c.listed).collect();

    let mut notes = Notes::new(spec.title, "");
    if f.has("deleted") {
        notes.say(&format!("Deleted #{}", f.int("deleted")));
    }
    let what = match spec.search {
        Search::Contains(cols) => cols.iter().filter_map(|n| spec.columns.iter().find(|c| c.name == *n)).map(|c| c.label).collect::<Vec<_>>().join(", "),
        Search::Code(c) => format!("{} starts with", spec.columns.iter().find(|x| x.name == c).map_or(c, |x| x.label)),
    };
    let quiet = Notes::new("", "");
    let search = Form { notes: &quiet }.text("q", text, 40, 0, Opts::new());
    let mut body = vec![
        notes.render(),
        format!("<form method=get action='{}'>\n<p class=standard>{} {search}{}</p>\n</form>", esc(&spec.path()), esc(&what), submit("Search", Opts::new())),
    ];
    if allows(role, spec.create) {
        body.push(format!("<p><a href=\"{}/new\">Add new</a></p>", esc(&spec.path())));
    }
    let mut head = vec!["<th>#</th>".to_string()];
    for c in &listed {
        let label = esc(c.label);
        head.push(match spec.search {
            Search::Contains(_) => {
                let dir = if c.name == sort && !desc { "desc" } else { "" };
                format!("<th><a href=\"{}\">{label}</a></th>", esc(&format!("{}{}", spec.path(), url_query(&[("q", text), ("sort", c.name), ("dir", dir)]))))
            }
            Search::Code(_) => format!("<th>{label}</th>"),
        });
    }
    let editable = allows(role, spec.edit);
    let mut out = vec!["<table class='small border'>".to_string(), format!("<thead><tr>{}</tr></thead>", head.concat()), "<tbody>".into()];
    for r in rows.iter().take(ROWS as usize) {
        let id: i64 = r.try_get(0).map_err(shell::internal)?;
        let values = (1..=listed.len()).map(|i| r.try_get::<Option<String>, _>(i)).collect::<Result<Vec<_>, _>>().map_err(shell::internal)?;
        let opens = editable && !spec.outranks(listed.iter().map(|c| c.name), &values, role);
        let key = if opens { format!("<a href=\"{}/{id}\">{id}</a>", esc(&spec.path())) } else { id.to_string() };
        let cells: String = listed.iter().zip(&values).map(|(c, v)| format!("<td>{}</td>", shown(c, v.as_deref()))).collect();
        out.push(format!("<tr><td class=center>{key}</td>{cells}</tr>"));
    }
    out.push("</tbody>\n</table>".into());
    body.push(out.join("\n"));
    let kept = [("q", text), ("sort", if sort == spec.order { "" } else { sort }), ("dir", if desc { "desc" } else { "" })];
    body.push(pager(&spec.path(), &kept, page, rows.len() as i64 > ROWS));
    Ok(shell::page(&auth.pool, staff.view(), spec.title, &body.join("\n")).await)
}

async fn new_form(State(auth): State<Arc<Auth>>, staff: Staff<Executive>, Path(table): Path<String>) -> Page {
    let spec = gate(&staff, &table, |s| s.create)?;
    let blank = vec![None; spec.columns.len()];
    Ok(form_page(&auth.pool, &staff, spec, None, &blank, &Notes::new(&title(spec, None), "")).await)
}

async fn edit_form(State(auth): State<Arc<Auth>>, staff: Staff<Executive>, Path((table, id)): Path<(String, i64)>, RawQuery(raw): RawQuery) -> Page {
    let spec = gate(&staff, &table, |s| s.edit)?;
    let values = stored(&auth.pool, spec, id, staff.who.user.role).await?;
    let mut notes = Notes::new(&title(spec, Some(id)), "");
    if Fields::from_query(raw.as_deref()).has("saved") {
        notes.say("Saved");
    }
    Ok(form_page(&auth.pool, &staff, spec, Some(id), &values, &notes).await)
}

/// The form as posted, for showing it again with its errors; fields that are not edited keep `stored`.
fn as_posted(spec: &Spec, f: &Fields, stored: Option<&[Option<String>]>) -> Vec<Option<String>> {
    let kept = |i: usize| stored.and_then(|s| s.get(i).cloned()).flatten();
    spec.columns
        .iter()
        .enumerate()
        .map(|(i, c)| match c.kind {
            _ if !c.editable => kept(i),
            Kind::Flag => Some(if f.checked(c.name) { "1" } else { "0" }.into()),
            _ => Some(f.get(c.name).to_string()),
        })
        .collect()
}

/// A write the database refused for its data (a duplicate, a broken reference) goes back to the form; anything
/// else is an internal error.
fn refused(spec: &Spec, e: sqlx::Error, notes: &mut Notes) -> Option<Response> {
    let sqlx::Error::Database(db) = &e else { return Some(shell::internal(e)) };
    match db.kind() {
        ErrorKind::UniqueViolation => {
            let failed: Vec<&str> = db.message().rsplit_once(": ").map_or_else(Vec::new, |(_, cols)| cols.split(", ").collect());
            let hit: Vec<&Col> = spec.columns.iter().filter(|c| failed.contains(&format!("{}.{}", spec.table, c.name).as_str())).collect();
            for c in &hit {
                notes.error(c.name, Some(&format!("Another row already has this {}", c.label)));
            }
            if hit.is_empty() {
                notes.say("Another row already has these values");
            }
            None
        }
        ErrorKind::ForeignKeyViolation | ErrorKind::NotNullViolation | ErrorKind::CheckViolation => {
            notes.say(&format!("Not saved: {}", db.message()));
            None
        }
        _ => Some(shell::internal(e)),
    }
}

async fn record(pool: &SqlitePool, staff: &Staff<Executive>, subtype: &str, spec: &Spec, id: i64) {
    let ip = staff.client.map_or_else(|| "unknown".to_string(), |ip| ip.to_string());
    let comment = format!("{} {id}", spec.table);
    audit::record(pool, Entry { subtype, user_id: staff.who.user.id.0, email: staff.who.email.as_deref(), ip: &ip, comment: &comment }).await;
}

async fn create(State(auth): State<Arc<Auth>>, staff: Staff<Executive>, Path(table): Path<String>, posted: Posted) -> Page {
    let spec = gate(&staff, &table, |s| s.create)?;
    let mut notes = Notes::new(&title(spec, None), "");
    let edited = read_form(spec, &posted.fields, staff.who.user.role, None, &mut notes);
    if !notes.has_errors() {
        match insert(&auth.pool, spec, &writes(spec, edited, true)).await {
            Ok(id) => {
                record(&auth.pool, &staff, "admin_create", spec, id).await;
                return Ok(found(&format!("{}/{id}?saved=1", spec.path())));
            }
            Err(e) => {
                if let Some(fail) = refused(spec, e, &mut notes) {
                    return Ok(fail);
                }
            }
        }
    }
    Ok(form_page(&auth.pool, &staff, spec, None, &as_posted(spec, &posted.fields, None), &notes).await)
}

async fn save(State(auth): State<Arc<Auth>>, staff: Staff<Executive>, Path((table, id)): Path<(String, i64)>, posted: Posted) -> Page {
    let spec = gate(&staff, &table, |s| s.edit)?;
    let role = staff.who.user.role;
    let before = stored(&auth.pool, spec, id, role).await?;
    let mut notes = Notes::new(&title(spec, Some(id)), "");
    let edited = read_form(spec, &posted.fields, role, Some(&before), &mut notes);
    if !notes.has_errors() {
        match update(&auth.pool, spec, id, &writes(spec, edited, false)).await {
            Ok(()) => {
                record(&auth.pool, &staff, "admin_update", spec, id).await;
                return Ok(found(&format!("{}/{id}?saved=1", spec.path())));
            }
            Err(e) => {
                if let Some(fail) = refused(spec, e, &mut notes) {
                    return Ok(fail);
                }
            }
        }
    }
    Ok(form_page(&auth.pool, &staff, spec, Some(id), &as_posted(spec, &posted.fields, Some(&before)), &notes).await)
}

async fn delete(State(auth): State<Arc<Auth>>, staff: Staff<Executive>, Path((table, id)): Path<(String, i64)>, _: Posted) -> Page {
    let spec = gate(&staff, &table, |s| s.delete)?;
    let before = stored(&auth.pool, spec, id, staff.who.user.role).await?;
    if let Err(e) = delete_row(&auth.pool, spec, id).await {
        let mut notes = Notes::new(&title(spec, Some(id)), "");
        if let Some(fail) = refused(spec, e, &mut notes) {
            return Ok(fail);
        }
        return Ok(form_page(&auth.pool, &staff, spec, Some(id), &before, &notes).await);
    }
    record(&auth.pool, &staff, "admin_delete", spec, id).await;
    Ok(found(&format!("{}?deleted={id}", spec.path())))
}

pub fn routes() -> Router<Arc<Auth>> {
    Router::new()
        .route("/staff/admin/{table}", get(list))
        .route("/staff/admin/{table}/new", get(new_form).post(create))
        .route("/staff/admin/{table}/{id}", get(edit_form).post(save))
        .route("/staff/admin/{table}/{id}/delete", post(delete))
}

#[cfg(test)]
mod tests {
    use std::collections::{HashMap, HashSet};

    use super::*;
    use crate::db;

    fn plain(name: &str) -> bool {
        let mut chars = name.chars();
        chars.next().is_some_and(|c| c.is_ascii_lowercase() || c == '_') && chars.all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '_')
    }

    /// (type, not null, primary key position, has a default) per column.
    async fn schema(pool: &SqlitePool, table: &str) -> HashMap<String, (String, bool, i64, bool)> {
        let rows = sqlx::query(r#"SELECT name, upper(type), "notnull", pk, dflt_value IS NOT NULL FROM pragma_table_info(?1)"#).bind(table).fetch_all(pool).await.unwrap();
        rows.iter().map(|r| (r.get::<String, _>(0), (r.get::<String, _>(1), r.get::<i64, _>(2) != 0, r.get::<i64, _>(3), r.get::<bool, _>(4)))).collect()
    }

    #[tokio::test]
    async fn every_spec_matches_the_migrated_schema() {
        let pool = db::open_memory().await.unwrap();
        let mut tables = HashSet::new();
        for spec in tables::SPECS {
            let t = spec.table;
            assert!(plain(t) && tables.insert(t), "{t}: a plain name, once");
            let columns = schema(&pool, t).await;
            assert_eq!(columns.get("id").map(|c| (c.0.as_str(), c.2)), Some(("INTEGER", 1)), "{t}: id is the integer primary key");
            assert_eq!(columns.values().filter(|c| c.2 > 0).count(), 1, "{t}: id is the whole primary key");
            let mut names = HashSet::new();
            for c in spec.columns {
                assert!(plain(c.name) && c.name != "id" && names.insert(c.name), "{t}.{}: a plain column, once", c.name);
                let Some((ty, not_null, _, _)) = columns.get(c.name) else { panic!("{t}.{}: not in the schema", c.name) };
                assert_eq!(ty, c.kind.sql_type(), "{t}.{}: type", c.name);
                assert_eq!(*not_null, !c.nullable, "{t}.{}: nullability", c.name);
                assert!(!c.required || c.editable, "{t}.{}: a required column is edited", c.name);
            }
            if spec.create.is_some() {
                for (name, (_, not_null, pk, default)) in &columns {
                    let set = names.contains(name.as_str()) && spec.columns.iter().any(|c| c.name == name && (c.editable || c.kind != Kind::Time(Touch::Never)));
                    assert!(!not_null || *default || *pk > 0 || set, "{t}.{name}: a new row needs it");
                }
            }
            let listed = |n: &str| spec.columns.iter().any(|c| c.name == n && c.listed);
            assert!(listed(spec.order), "{t}: sorted by a listed column");
            match spec.search {
                Search::Contains(cols) => assert!(cols.iter().all(|n| names.contains(n)), "{t}: searches its own columns"),
                Search::Code(c) => {
                    assert_eq!(spec.order, c, "{t}: pages follow the searched index");
                    let leads: i64 = sqlx::query_scalar("SELECT count(*) FROM pragma_index_list(?1) l JOIN pragma_index_info(l.name) i WHERE i.seqno = 0 AND i.name = ?2")
                        .bind(t)
                        .bind(c)
                        .fetch_one(&pool)
                        .await
                        .unwrap();
                    assert!(leads > 0, "{t}.{c}: leads an index");
                }
            }
            if let Some(r) = spec.rank {
                let col = spec.columns.iter().find(|c| c.name == r).unwrap();
                assert!(col.listed, "{t}.{r}: the rank is listed");
                let Kind::Choice(list) = col.kind else { panic!("{t}.{r}: the rank is a choice") };
                assert!(list.iter().all(|(v, _)| Role::parse(v).is_some()), "{t}.{r}: every choice is a role");
            }
            assert!(spec.edit.is_none_or(|e| e >= spec.list), "{t}: who edits also lists");
        }
    }

    fn sample(spec: &Spec, col: &Col) -> Value {
        match col.kind {
            Kind::Text | Kind::LongText => Value::Text(format!("{} sample", col.name)),
            Kind::Integer | Kind::Flag => Value::Int(1),
            Kind::Decimal => Value::Real(1.5),
            Kind::Date => Value::Text("2025-01-02".into()),
            Kind::Choice(list) => Value::Text(options(spec, col, Role::SuperUser, None)[list.len() - 1].0.clone()),
            Kind::Time(_) => Value::Int(0),
        }
    }

    #[tokio::test]
    async fn the_generated_sql_runs_on_every_table() {
        let pool = db::open_memory().await.unwrap();
        for spec in tables::SPECS {
            let edited: Writes = spec.columns.iter().filter(|c| c.editable).map(|c| (c.name, sample(spec, c))).collect();
            let mut created = writes(spec, edited.clone(), true);
            if spec.table == "inventory" {
                created.push(("sku", Value::Text("AB-1".into())));
            }
            let id = insert(&pool, spec, &created).await.unwrap_or_else(|e| panic!("{}: insert: {e}", spec.table));
            let row = stored(&pool, spec, id, Role::SuperUser).await.unwrap_or_else(|_| panic!("{}: read", spec.table));
            let touched = spec.columns.iter().zip(&row).filter(|(c, _)| c.kind == Kind::Time(Touch::Created) || c.kind == Kind::Time(Touch::Saved));
            assert!(touched.clone().all(|(_, v)| v.as_deref().is_some_and(|v| v != "0")), "{}: times set", spec.table);
            for searching in [false, true] {
                let text = match spec.search {
                    Search::Contains(cols) => format!("{} sample", cols[0]),
                    Search::Code(_) => "ab".into(),
                };
                let sql = list_sql(spec, spec.order, true, searching);
                let rows = sqlx::query(&sql).bind(search_value(spec, &text)).bind(ROWS + 1).bind(0).fetch_all(&pool).await.unwrap();
                assert_eq!(rows.len(), 1, "{}: {sql}", spec.table);
            }
            update(&pool, spec, id, &writes(spec, edited, false)).await.unwrap_or_else(|e| panic!("{}: update: {e}", spec.table));
            delete_row(&pool, spec, id).await.unwrap();
            assert!(stored(&pool, spec, id, Role::SuperUser).await.is_err(), "{}: deleted", spec.table);
        }
    }

    #[tokio::test]
    async fn a_part_number_search_pages_through_the_index() {
        let pool = db::open_memory().await.unwrap();
        let spec = tables::find("inventory").unwrap();
        for (searching, desc) in [(false, false), (true, false), (false, true), (true, true)] {
            let plan = sqlx::query(&format!("EXPLAIN QUERY PLAN {}", list_sql(spec, spec.order, desc, searching)))
                .bind("AB")
                .bind(ROWS + 1)
                .bind(0)
                .fetch_all(&pool)
                .await
                .unwrap();
            let plan: Vec<String> = plan.iter().map(|r| r.get::<String, _>("detail")).collect();
            assert!(plan.iter().any(|d| d.contains("USING INDEX inventory_sku_key")), "{plan:?}");
            assert!(!plan.iter().any(|d| d.contains("TEMP B-TREE")), "{plan:?}");
        }
    }

    #[test]
    fn fields_read_by_their_kind() {
        let f = Fields::of(&[("n", " 12 "), ("x", "1.25"), ("d", "1/2/2025"), ("bad", "1.5"), ("c", "Weeks"), ("t", "  a  "), ("e", "")]);
        let c = |name, kind| col(name, "L", kind);
        assert_eq!(parse(&c("n", Kind::Integer), &f, None, &[]), Ok(Value::Int(12)));
        assert_eq!(parse(&c("bad", Kind::Integer), &f, None, &[]), Err("L must be a whole number".into()));
        assert_eq!(parse(&c("x", Kind::Decimal), &f, None, &[]), Ok(Value::Real(1.25)));
        assert_eq!(parse(&c("d", Kind::Date), &f, None, &[]), Ok(Value::Text("2025-01-02".into())));
        assert_eq!(parse(&c("t", Kind::Text), &f, None, &[]), Ok(Value::Text("a".into())));
        assert_eq!(parse(&c("f", Kind::Flag), &f, None, &[]), Ok(Value::Int(0)));
        let allowed = vec![("Weeks".to_string(), "Weeks".to_string())];
        assert_eq!(parse(&c("c", Kind::Choice(&[])), &f, None, &allowed), Ok(Value::Text("Weeks".into())));
        assert_eq!(parse(&c("c", Kind::Choice(&[])), &f, None, &[]), Err("L must be one of the listed values".into()));
    }

    #[test]
    fn an_empty_field_keeps_an_empty_value_as_it_was() {
        let f = Fields::of(&[("e", " ")]);
        let c = |kind| col("e", "L", kind);
        assert_eq!(parse(&c(Kind::Text), &f, Some(""), &[]), Ok(Value::Text(String::new())));
        assert_eq!(parse(&c(Kind::Text), &f, None, &[]), Ok(Value::Null));
        assert_eq!(parse(&c(Kind::Text), &f, Some("was"), &[]), Ok(Value::Null));
        assert_eq!(parse(&c(Kind::Date), &f, Some(ZERO_DATE), &[]), Ok(Value::Text(ZERO_DATE.into())));
        assert_eq!(parse(&c(Kind::Date).not_null(), &f, Some("2025-01-02"), &[]), Ok(Value::Text(ZERO_DATE.into())));
        assert_eq!(parse(&c(Kind::Integer).not_null(), &f, Some("4"), &[]), Ok(Value::Int(0)));
        assert_eq!(parse(&c(Kind::Text).req(), &f, Some(""), &[]), Err("L is required".into()));
    }

    #[test]
    fn the_rank_column_offers_no_role_above_the_editor() {
        let spec = tables::find("user").unwrap();
        let rank = spec.columns.iter().find(|c| Some(c.name) == spec.rank).unwrap();
        let offered: Vec<String> = options(spec, rank, Role::Administrator, Some("Customer")).into_iter().map(|(v, _)| v).collect();
        assert_eq!(offered, ["Customer", "External", "Internal", "Executive", "Administrator"]);
        let names = ["type"];
        assert!(spec.outranks(names, &[Some("Super User".into())], Role::Administrator));
        assert!(!spec.outranks(names, &[Some("Administrator".into())], Role::Administrator));
        assert!(spec.outranks(names, &[Some("Someone".into())], Role::SuperUser), "a role that does not read is above everyone");
        let status = spec.columns.iter().find(|c| c.name == "status").unwrap();
        assert_eq!(options(spec, status, Role::Administrator, Some("Archived")).last().map(|o| o.0.as_str()), Some("Archived"), "a stored value stays choosable");
    }

    #[test]
    fn like_text_is_taken_literally_and_pages_link_both_ways() {
        assert_eq!(contains(r"50%_a\b"), r"%50\%\_a\\b%");
        let p = pager("/staff/admin/bot", &[("q", "a b"), ("sort", "")], 1, true);
        assert_eq!(p, "<p class='standard center'><a href=\"/staff/admin/bot?q=a+b\">&lt; Previous</a> | Page 2 | <a href=\"/staff/admin/bot?q=a+b&amp;page=2\">Next &gt;</a></p>");
        assert_eq!(pager("/x", &[], 0, false), "<p class='standard center'>Page 1</p>");
    }
}
