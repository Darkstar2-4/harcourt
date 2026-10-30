//! includes/map_rev3.php: moving a table to and from a desktop file. A table describes its columns once (`Map`);
//! the framework writes them as tab- or comma-separated text or an Excel workbook, reads such a file back, matches
//! its header row to the columns, and imports in two steps: the upload is checked and parsed into a draft kept
//! with the staff session (`staff_draft`, where the PHP kept a temporary file named in `$_SESSION`), and the
//! update applies the draft row by row.
//!
//! The tables themselves (which rows, how a row is checked and saved) live with their pages in utility.rs.

use std::collections::HashMap;

use axum::{
    http::{header, HeaderValue, StatusCode},
    response::{IntoResponse, Response},
};
use base64::{engine::general_purpose::STANDARD_NO_PAD, Engine};
use serde::{Deserialize, Serialize};
use sqlx::SqlitePool;

use super::{
    check,
    csrf::Fields,
    html::{button, esc, number, Form, Opts},
    time,
};
use crate::{db::unix_now, token::TokenHash};

/// The largest file an import accepts. The PHP took whatever upload_max_filesize allowed.
pub const MAX_UPLOAD: usize = 20 * 1024 * 1024;
/// Rows an Excel export may hold.
const XLS_ROWS: usize = 1_000_000;

/// A column's type (table_map_item_class), which decides how a value is written and read.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Kind {
    Alphanumeric,
    Lowercase,
    Email,
    Uppercase,
    Text,
    Date,
    Period,
    Time,
    Timestamp,
    Numeric,
    Decimal(u32),
    Fixed(u32),
    Mixed(u32),
    Id,
    Percent(u32),
    Boolean,
    Ipv4,
    Ipv6,
}

impl Kind {
    /// The type as the field list shows it: `decimal:2`, `uppercase`.
    fn spec(self) -> String {
        match self {
            Kind::Decimal(d) => format!("decimal:{d}"),
            Kind::Fixed(d) => format!("fixed:{d}"),
            Kind::Mixed(d) => format!("mixed:{d}"),
            Kind::Percent(d) => format!("percent:{d}"),
            k => k.base().to_string(),
        }
    }

    fn base(self) -> &'static str {
        match self {
            Kind::Alphanumeric => "alphanumeric",
            Kind::Lowercase => "lowercase",
            Kind::Email => "email",
            Kind::Uppercase => "uppercase",
            Kind::Text => "text",
            Kind::Date => "date",
            Kind::Period => "period",
            Kind::Time => "time",
            Kind::Timestamp => "timestamp",
            Kind::Numeric => "numeric",
            Kind::Decimal(_) => "decimal",
            Kind::Fixed(_) => "fixed",
            Kind::Mixed(_) => "mixed",
            Kind::Id => "id",
            Kind::Percent(_) => "percent",
            Kind::Boolean => "boolean",
            Kind::Ipv4 => "ipv4",
            Kind::Ipv6 => "ipv6",
        }
    }

    /// The `$type_list` text, decimals appended.
    pub fn type_name(self) -> String {
        match self {
            Kind::Alphanumeric => "Alphanumeric text".into(),
            Kind::Lowercase => "Alphanumeric lower case text".into(),
            Kind::Email => "Email address".into(),
            Kind::Uppercase => "Alphanumeric upper case text".into(),
            Kind::Text => "Multiple line alphanumeric text".into(),
            Kind::Date => "Date (MM/DD/YYYY)".into(),
            Kind::Period => "Period (MM/YYYY)".into(),
            Kind::Time => "Time (HH:MM)".into(),
            Kind::Timestamp => "Timestamp (MM/DD/YYYY HH:MM:SS)".into(),
            Kind::Numeric => "Number with floating decimal".into(),
            Kind::Decimal(d) => format!("Number Decimal places:  {d}"),
            Kind::Fixed(d) => format!("Numeric Digits: {d}"),
            Kind::Mixed(d) => format!("Mixed Alphanumeric or Decimal places: {d}"),
            Kind::Id => "Unique record ID (integer)".into(),
            Kind::Percent(d) => format!("Percent/Decimal places:  {d}"),
            Kind::Boolean => "True: Y, 1; False: N, 0".into(),
            Kind::Ipv4 => "IPV4 address".into(),
            Kind::Ipv6 => "IPV6 address".into(),
        }
    }

    fn numeric(self) -> bool {
        matches!(self, Kind::Numeric | Kind::Decimal(_) | Kind::Percent(_) | Kind::Id)
    }
}

/// One column of the file.
#[derive(Debug, Clone)]
pub struct Field {
    pub key: String,
    pub name: String,
    pub kind: Kind,
    pub group: Option<&'static str>,
    pub required: bool,
    /// False for a column that is exported only.
    pub import: bool,
    pub hidden: bool,
    pub width: u32,
    pub comment: Option<String>,
    /// A date column holding unix seconds rather than `Y/m/d` text.
    pub timestamp: bool,
}

impl Field {
    pub fn new(key: impl Into<String>, kind: Kind) -> Field {
        let key = key.into();
        Field { name: key.clone(), key, kind, group: None, required: false, import: true, hidden: false, width: 21, comment: None, timestamp: false }
    }

    pub fn name(mut self, name: impl Into<String>) -> Field {
        self.name = name.into();
        self
    }

    pub fn required(mut self) -> Field {
        self.required = true;
        self
    }

    pub fn group(mut self, group: &'static str) -> Field {
        self.group = Some(group);
        self
    }

    pub fn export_only(mut self) -> Field {
        self.import = false;
        self
    }

    pub fn width(mut self, width: u32) -> Field {
        self.width = width;
        self
    }

    pub fn comment(mut self, comment: impl Into<String>) -> Field {
        self.comment = Some(comment.into());
        self
    }

    /// The comment lines the field list shows.
    fn comments(&self) -> Vec<String> {
        let mut out = vec![self.kind.type_name()];
        out.extend(self.comment.clone());
        if self.required {
            out.push("Mandatory field included in all imports/exports".into());
        }
        out
    }
}

/// A table's columns in the order the table named them. Columns of one group stay together, where the group's
/// first column came.
#[derive(Debug, Clone, Default)]
pub struct Map {
    fields: Vec<Field>,
}

/// A line of the field list: one column, or a group and its columns.
enum Entry<'a> {
    Single(&'a Field),
    Group(&'static str, Vec<&'a Field>),
}

impl Map {
    pub fn new() -> Map {
        Map::default()
    }

    /// table_map_class::item(): a key already taken is ignored.
    pub fn add(&mut self, field: Field) {
        if self.fields.iter().any(|f| f.key == field.key && f.group == field.group) {
            return;
        }
        let at = field.group.and_then(|g| self.fields.iter().rposition(|f| f.group == Some(g)).map(|i| i + 1));
        match at {
            Some(i) => self.fields.insert(i, field),
            None => self.fields.push(field),
        }
    }

    pub fn fields(&self) -> &[Field] {
        &self.fields
    }

    pub fn field(&self, key: &str) -> Option<&Field> {
        self.fields.iter().find(|f| f.key == key)
    }

    fn entries(&self) -> Vec<Entry<'_>> {
        let mut out: Vec<Entry> = Vec::new();
        for f in &self.fields {
            match f.group {
                None => out.push(Entry::Single(f)),
                Some(g) => match out.iter_mut().find_map(|e| match e {
                    Entry::Group(name, items) if *name == g => Some(items),
                    _ => None,
                }) {
                    Some(items) => items.push(f),
                    None => out.push(Entry::Group(g, vec![f])),
                },
            }
        }
        out
    }

    /// fields_map_item(): the columns an export writes. Without `option_selected` (no field list was shown) every
    /// column goes; otherwise the required ones and those whose box (or group box) was ticked. Hidden never.
    pub fn chosen(&self, posted: &Fields) -> Vec<&Field> {
        let selected = posted.checked("option_selected");
        self.fields
            .iter()
            .filter(|f| !f.hidden && (!selected || f.required || posted.checked(&format!("map_{}", f.group.unwrap_or(&f.key)))))
            .collect()
    }
}

/// The file formats offered (`$file_type_list`).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
pub enum FileType {
    Xlsx,
    Txt,
    Csv,
}

pub const FILE_TYPES: [(&str, &str); 3] = [(".xlsx", "Excel (xlsx)"), (".txt", "Tab Separated Values (txt)"), (".csv", "Comma Separated Values (csv)")];

impl FileType {
    /// What the form posted; anything else is a text file, as the PHP defaulted.
    pub fn posted(s: &str) -> FileType {
        match s {
            ".xlsx" => FileType::Xlsx,
            ".csv" => FileType::Csv,
            _ => FileType::Txt,
        }
    }

    /// upload_file_type(): by the file name's ending.
    fn of_name(name: &str) -> Option<FileType> {
        let lower = name.to_ascii_lowercase();
        let ends = |s: &str| lower.len() > s.len() && lower.ends_with(s);
        match () {
            _ if ends("csv") => Some(FileType::Csv),
            _ if ends("txt") => Some(FileType::Txt),
            _ if ends("xlsx") => Some(FileType::Xlsx),
            _ => None,
        }
    }

    pub fn suffix(self) -> &'static str {
        match self {
            FileType::Xlsx => ".xlsx",
            FileType::Txt => ".txt",
            FileType::Csv => ".csv",
        }
    }

    fn delimiter(self) -> u8 {
        if self == FileType::Csv {
            b','
        } else {
            b'\t'
        }
    }
}

pub fn file_type_options() -> Vec<(String, String)> {
    FILE_TYPES.iter().map(|(v, l)| (v.to_string(), l.to_string())).collect()
}

/// A row as the table read it, one text per column of the export in the same order (NULL reads as "").
pub type Record = Vec<String>;

/// PHP's is_numeric() for a string.
pub fn is_numeric(s: &str) -> bool {
    let t = s.trim_start_matches([' ', '\t', '\n', '\r', '\x0b', '\x0c']).trim_end_matches([' ', '\t', '\n', '\r', '\x0b', '\x0c']);
    let t = t.strip_prefix(['+', '-']).unwrap_or(t);
    let (mantissa, exponent) = match t.find(['e', 'E']) {
        Some(i) => (&t[..i], Some(&t[i + 1..])),
        None => (t, None),
    };
    let (int, frac) = mantissa.split_once('.').unwrap_or((mantissa, ""));
    let digits = |s: &str| s.bytes().all(|b| b.is_ascii_digit());
    let mantissa_ok = (!int.is_empty() || !frac.is_empty()) && digits(int) && digits(frac);
    let exponent_ok = exponent.is_none_or(|e| {
        let e = e.strip_prefix(['+', '-']).unwrap_or(e);
        !e.is_empty() && digits(e)
    });
    mantissa_ok && exponent_ok
}

/// floatval(): the leading number of a string, 0 when there is none.
pub fn floatval(s: &str) -> f64 {
    super::catalog::php_floatval(s)
}

/// A float as PHP 8 turns it into a string (shortest form that reads back the same).
pub fn float_text(v: f64) -> String {
    if v == 0.0 {
        return "0".into();
    }
    format!("{v}")
}

/// number_format($v, $places, ".", ""): rounded half away from zero, no thousands separator.
pub fn number_format(v: f64, places: u32) -> String {
    let d: rust_decimal::Decimal = rust_decimal::prelude::FromPrimitive::from_f64(v).unwrap_or_default();
    let rounded = d.round_dp_with_strategy(places, rust_decimal::RoundingStrategy::MidpointAwayFromZero);
    let s = format!("{rounded:.*}", places as usize);
    if s.starts_with('-') && s[1..].bytes().all(|b| b == b'0' || b == b'.') {
        s[1..].to_string()
    } else {
        s
    }
}

/// A value of a column in an export (export_data()). Excel gets numbers where the column is numeric.
#[derive(Debug, Clone, PartialEq)]
pub enum Cell {
    Text(String),
    Number(f64),
}

impl Cell {
    fn text(&self) -> String {
        match self {
            Cell::Text(s) => s.clone(),
            Cell::Number(n) => float_text(*n),
        }
    }
}

/// One value as export_data() wrote it.
pub fn export_cell(field: &Field, value: &str, file: FileType) -> Cell {
    let xlsx = file == FileType::Xlsx;
    match field.kind {
        Kind::Numeric | Kind::Percent(_) | Kind::Decimal(_) if !is_numeric(value) => Cell::Text("0".into()),
        Kind::Numeric => Cell::Number(floatval(value)),
        Kind::Percent(d) => Cell::Text(number_format(floatval(value), d + 2)),
        Kind::Decimal(d) => Cell::Text(number_format(floatval(value), d)),
        Kind::Mixed(d) => {
            if !value.is_empty() && !is_numeric(value) {
                Cell::Text(value.to_string())
            } else {
                Cell::Text(number_format(floatval(value), d))
            }
        }
        Kind::Boolean => Cell::Text(if truthy(value) { "Y" } else { "N" }.into()),
        Kind::Date | Kind::Period if xlsx => Cell::Text(if !truthy(value) {
            String::new()
        } else if field.timestamp {
            time::date("Y-m-d", floatval(value) as i64)
        } else {
            value.replace('/', "-")
        }),
        Kind::Time | Kind::Timestamp if xlsx => Cell::Text(if truthy(value) { time::date("Y-m-d H:i:s", floatval(value) as i64) } else { String::new() }),
        Kind::Date => Cell::Text(match () {
            _ if !truthy(value) => String::new(),
            _ if field.timestamp => time::date("m/d/Y", floatval(value) as i64),
            _ => date_ymd(value),
        }),
        Kind::Period => Cell::Text(match () {
            _ if !truthy(value) => String::new(),
            _ if field.timestamp => time::date("m/Y", floatval(value) as i64),
            _ => date_part(value, &[1, 0]),
        }),
        Kind::Time => Cell::Text(time::date("H:i:s", floatval(value) as i64)),
        Kind::Timestamp => Cell::Text(time::date("m/d/Y H:i:s", floatval(value) as i64)),
        Kind::Text if !xlsx => Cell::Text(value.replace("\r\n", "<br>")),
        Kind::Ipv4 => Cell::Text(std::net::Ipv4Addr::from(floatval(value) as u32).to_string()),
        _ => Cell::Text(value.to_string()),
    }
}

/// PHP truthiness of a string: "" and "0" are false.
pub fn truthy(s: &str) -> bool {
    !matches!(s, "" | "0")
}

/// fn_date($text, "ymd"): a stored `Y-m-d` or `Y/m/d` shown as `m/d/Y`.
fn date_ymd(value: &str) -> String {
    match value {
        "" | "0" | "0000-00-00" | "0000/00/00" => String::new(),
        v => date_part(v, &[1, 2, 0]),
    }
}

fn date_part(value: &str, order: &[usize]) -> String {
    let parts: Vec<&str> = value.split([' ', '/', '.', '-']).collect();
    order.iter().map(|&i| parts.get(i).copied().unwrap_or("")).collect::<Vec<_>>().join("/")
}

/// fputcsv(): a field is quoted when it holds the delimiter, a quote, a backslash or white space; quotes double,
/// except after a backslash.
pub fn csv_line(fields: &[String], delimiter: u8) -> Vec<u8> {
    let mut out = Vec::new();
    for (i, f) in fields.iter().enumerate() {
        if i > 0 {
            out.push(delimiter);
        }
        let b = f.as_bytes();
        if b.iter().any(|&c| c == delimiter || matches!(c, b'"' | b'\\' | b'\n' | b'\r' | b'\t' | b' ')) {
            out.push(b'"');
            let mut escaped = false;
            for &c in b {
                if c == b'\\' {
                    escaped = true;
                } else if !escaped && c == b'"' {
                    out.push(b'"');
                } else {
                    escaped = false;
                }
                out.push(c);
            }
            out.push(b'"');
        } else {
            out.extend_from_slice(b);
        }
    }
    out.push(b'\n');
    out
}

/// str_getcsv() of one line read by fgets(): fields split on the delimiter, quoted fields unquoted, the line end
/// dropped.
pub fn parse_csv_line(line: &str, delimiter: char) -> Vec<String> {
    let line = line.strip_suffix('\n').unwrap_or(line);
    let line = line.strip_suffix('\r').unwrap_or(line);
    let mut out = Vec::new();
    let mut chars = line.chars().peekable();
    loop {
        let mut field = String::new();
        while chars.peek().is_some_and(|c| *c == ' ' || *c == '\t') && Some(delimiter) != chars.peek().copied() {
            field.push(chars.next().unwrap_or(' '));
        }
        if chars.peek() == Some(&'"') {
            field.clear();
            chars.next();
            let mut escaped = false;
            while let Some(c) = chars.next() {
                if c == '\\' {
                    escaped = !escaped;
                    field.push(c);
                    continue;
                }
                if c == '"' && !escaped {
                    if chars.peek() == Some(&'"') {
                        chars.next();
                        field.push('"');
                        continue;
                    }
                    break;
                }
                escaped = false;
                field.push(c);
            }
            while let Some(c) = chars.peek().copied() {
                if c == delimiter {
                    break;
                }
                field.push(c);
                chars.next();
            }
        } else {
            while let Some(c) = chars.peek().copied() {
                if c == delimiter {
                    break;
                }
                field.push(c);
                chars.next();
            }
        }
        out.push(field);
        if chars.next().is_none() {
            break;
        }
    }
    out
}

/// What an export answers: the file, or the field list again with an error.
pub enum Export {
    File(Response),
    Empty,
    TooBig(usize),
}

/// export(): the chosen columns of `rows` as a download named `name`, with the log row the PHP wrote.
pub fn export_file(name: &str, file: FileType, fields: &[&Field], rows: &[Record]) -> Export {
    if file == FileType::Xlsx && rows.len() > XLS_ROWS {
        return Export::TooBig(rows.len());
    }
    let cells = |r: &Record| -> Vec<Cell> { fields.iter().zip(r).map(|(f, v)| export_cell(f, v, file)).collect() };
    let (body, mime) = match file {
        FileType::Xlsx => match xlsx(fields, rows.iter().map(cells)) {
            Ok(b) => (b, "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"),
            Err(e) => {
                tracing::error!("xlsx export {name}: {e}");
                return Export::Empty;
            }
        },
        _ => {
            let mut out = vec![0xEF, 0xBB, 0xBF];
            out.extend(csv_line(&fields.iter().map(|f| f.name.clone()).collect::<Vec<_>>(), file.delimiter()));
            for r in rows {
                out.extend(csv_line(&cells(r).iter().map(Cell::text).collect::<Vec<_>>(), file.delimiter()));
            }
            (out, "text/plain;charset=UTF-8")
        }
    };
    Export::File(download(name, mime, body))
}

pub fn download(name: &str, mime: &'static str, body: Vec<u8>) -> Response {
    let disposition = HeaderValue::from_str(&format!("attachment; filename={name}")).unwrap_or_else(|_| HeaderValue::from_static("attachment"));
    let mut r = (StatusCode::OK, [(header::CONTENT_TYPE, HeaderValue::from_static(mime)), (header::CACHE_CONTROL, HeaderValue::from_static("no-cache"))], body).into_response();
    r.headers_mut().insert(header::CONTENT_DISPOSITION, disposition);
    r.headers_mut().insert(header::PRAGMA, HeaderValue::from_static("no-cache"));
    r.headers_mut().insert(header::EXPIRES, HeaderValue::from_static("0"));
    r
}

/// export() with `xls_multiple`: one Excel worksheet per named part, each with its own columns.
pub fn export_sheets(name: &str, sheets: &[(String, Map, Vec<Record>)]) -> Export {
    if sheets.is_empty() {
        return Export::Empty;
    }
    let mut book = rust_xlsxwriter::Workbook::new();
    for (title, map, rows) in sheets {
        let fields: Vec<&Field> = map.fields().iter().collect();
        let cells = rows.iter().map(|r| fields.iter().zip(r).map(|(f, v)| export_cell(f, v, FileType::Xlsx)).collect());
        if let Err(e) = sheet(&mut book, title, &fields, cells) {
            tracing::error!("xlsx export {name}: {e}");
            return Export::Empty;
        }
    }
    match book.save_to_buffer() {
        Ok(body) => Export::File(download(name, "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", body)),
        Err(e) => {
            tracing::error!("xlsx export {name}: {e}");
            Export::Empty
        }
    }
}

fn xlsx(fields: &[&Field], rows: impl Iterator<Item = Vec<Cell>>) -> Result<Vec<u8>, rust_xlsxwriter::XlsxError> {
    let mut book = rust_xlsxwriter::Workbook::new();
    sheet(&mut book, "Data", fields, rows)?;
    book.save_to_buffer()
}

/// One worksheet: the column names in bold, then the rows. The name loses the characters Excel refuses.
fn sheet(book: &mut rust_xlsxwriter::Workbook, title: &str, fields: &[&Field], rows: impl Iterator<Item = Vec<Cell>>) -> Result<(), rust_xlsxwriter::XlsxError> {
    let sheet = book.add_worksheet();
    let clean: String = title.chars().map(|c| if "\\/?*:[]".contains(c) { ' ' } else { c }).take(31).collect();
    sheet.set_name(clean)?;
    let bold = rust_xlsxwriter::Format::new().set_bold();
    for (c, f) in fields.iter().enumerate() {
        let c = c as u16;
        sheet.set_column_width(c, f.width)?;
        sheet.write_string_with_format(0, c, &f.name, &bold)?;
    }
    for (r, cells) in rows.enumerate() {
        let r = r as u32 + 1;
        for (c, cell) in cells.iter().enumerate() {
            match cell {
                Cell::Number(n) => sheet.write_number(r, c as u16, *n)?,
                Cell::Text(s) if fields[c].kind.numeric() && is_numeric(s) => sheet.write_number(r, c as u16, floatval(s))?,
                Cell::Text(s) => sheet.write_string(r, c as u16, s)?,
            };
        }
    }
    Ok(())
}

/// The export file name: the table, then the group and code made safe, then the suffix.
pub fn file_name(table: &str, group: &str, code: &str, file: FileType) -> String {
    let safe = |s: &str| s.chars().map(|c| if matches!(c, ' ' | ',' | '/' | '\\' | '|' | '&' | '\'' | '"') { '_' } else { c }).collect::<String>().to_lowercase();
    let mut parts = vec![table.to_string()];
    if truthy(group) {
        parts.push(safe(group));
    }
    if truthy(code) {
        parts.push(safe(code));
    }
    format!("{}{}", parts.join("_"), file.suffix())
}

/// The log row export() and import() wrote (`$database->log->ipc`).
pub async fn log(pool: &SqlitePool, who: &super::Staff<impl crate::api::MinRole>, kind: &str, subtype: &str, lines: &[String]) -> sqlx::Result<()> {
    let ip = who.client.map(|c| c.to_string()).unwrap_or_default();
    let now = unix_now();
    let comment = lines.join("\n");
    sqlx::query!(
        "INSERT INTO log (ip, date_time, user_id, email, type, subtype, comment) VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7)",
        ip,
        now,
        who.who.user.id.0,
        who.who.email,
        kind,
        subtype,
        comment
    )
    .execute(pool)
    .await?;
    Ok(())
}

/// A file read for import: its rows of text cells, header row first.
pub struct Sheet {
    pub file_type: FileType,
    pub rows: Vec<Vec<String>>,
    /// The worksheet read, for an Excel file.
    pub worksheet: Option<String>,
}

/// upload_from_post() and upload_open(): what the upload is, or the message the page shows.
pub fn read_upload(file_name: &str, bytes: &[u8], worksheet: Option<&str>) -> Result<Sheet, String> {
    let Some(file_type) = FileType::of_name(file_name) else { return Err(format!("{} format must be txt, csv or xlsx", esc(file_name))) };
    if bytes.len() > MAX_UPLOAD {
        return Err(format!("Upload error #{}", 1));
    }
    match file_type {
        FileType::Xlsx => read_xlsx(bytes, worksheet).map_err(|e| match e {
            Unread::Format => format!("{} not XLXS format", esc(file_name)),
            Unread::Size => format!("Upload error #{}", 1),
        }),
        _ => {
            let text = String::from_utf8_lossy(bytes);
            let delimiter = file_type.delimiter() as char;
            let rows = fgets_lines(&text).map(|l| parse_csv_line(l.strip_prefix('\u{feff}').unwrap_or(l), delimiter)).collect();
            Ok(Sheet { file_type, rows, worksheet: None })
        }
    }
}

/// The lines fgets($fh, 4096) returned: up to each newline, at most 4095 bytes.
fn fgets_lines(text: &str) -> impl Iterator<Item = &str> {
    let mut rest = text;
    std::iter::from_fn(move || {
        if rest.is_empty() {
            return None;
        }
        let mut end = rest.find('\n').map_or(rest.len(), |i| i + 1);
        if end > 4095 {
            end = 4095;
            while !rest.is_char_boundary(end) {
                end -= 1;
            }
        }
        let (line, tail) = rest.split_at(end);
        rest = tail;
        Some(line)
    })
}

/// Why an Excel upload was not read.
enum Unread {
    Format,
    Size,
}

/// What an Excel upload may unpack to. The zip reader holds each part to the size the archive declares for it.
const XLSX_UNPACKED: u64 = 512 << 20;

/// The most cells an Excel sheet is read into, counting the blanks before the last cell of each row.
const XLSX_CELLS: usize = 20_000_000;

/// The sheet's rows from its first row and column with a value, each up to its last value.
fn read_xlsx(bytes: &[u8], wanted: Option<&str>) -> Result<Sheet, Unread> {
    use calamine::Reader;
    let mut zip = zip::ZipArchive::new(std::io::Cursor::new(bytes)).map_err(|_| Unread::Format)?;
    let mut unpacked = 0u64;
    for i in 0..zip.len() {
        unpacked = unpacked.saturating_add(zip.by_index_raw(i).map_err(|_| Unread::Format)?.size());
    }
    if unpacked > XLSX_UNPACKED {
        return Err(Unread::Size);
    }
    let mut book = calamine::Xlsx::new(std::io::Cursor::new(bytes)).map_err(|_| Unread::Format)?;
    let names = book.sheet_names();
    let clean = |s: &str| s.chars().map(|c| if "\\/?*:[]".contains(c) { ' ' } else { c }).collect::<String>();
    let name = wanted.map(clean).and_then(|w| names.iter().find(|n| n.eq_ignore_ascii_case(&w)).cloned()).or_else(|| names.first().cloned());
    let Some(name) = name else { return Ok(Sheet { file_type: FileType::Xlsx, rows: Vec::new(), worksheet: None }) };
    let mut reader = book.worksheet_cells_reader(&name).map_err(|_| Unread::Format)?;
    let mut cells: Vec<(u32, u32, String)> = Vec::new();
    while let Some(cell) = reader.next_cell().map_err(|_| Unread::Format)? {
        if cells.len() == XLSX_CELLS {
            return Err(Unread::Size);
        }
        let (row, col) = cell.get_position();
        let text = match calamine::Data::from(cell.get_value().clone()) {
            calamine::Data::Empty => continue,
            calamine::Data::Float(f) => float_text(f),
            calamine::Data::Int(i) => i.to_string(),
            calamine::Data::Bool(b) => if b { "1" } else { "" }.into(),
            calamine::Data::DateTime(d) => float_text(d.as_f64()),
            other => other.to_string(),
        };
        cells.push((row, col, text));
    }
    let (Some(top), Some(bottom), Some(left)) =
        (cells.iter().map(|c| c.0).min(), cells.iter().map(|c| c.0).max(), cells.iter().map(|c| c.1).min())
    else {
        return Ok(Sheet { file_type: FileType::Xlsx, rows: Vec::new(), worksheet: Some(name) });
    };
    let height = (bottom - top) as usize + 1;
    if height > XLS_ROWS {
        return Err(Unread::Size);
    }
    let mut widths = vec![0usize; height];
    for &(row, col, _) in &cells {
        let w = &mut widths[(row - top) as usize];
        *w = (*w).max((col - left) as usize + 1);
    }
    if widths.iter().sum::<usize>() > XLSX_CELLS {
        return Err(Unread::Size);
    }
    let mut rows: Vec<Vec<String>> = widths.iter().map(|&w| vec![String::new(); w]).collect();
    for (row, col, text) in cells {
        rows[(row - top) as usize][(col - left) as usize] = text;
    }
    Ok(Sheet { file_type: FileType::Xlsx, rows, worksheet: Some(name) })
}

/// upload_value(): a cell of the file as the column takes it.
pub fn import_value(field: &Field, value: &str, file: FileType) -> String {
    let value = value.trim();
    match field.kind {
        Kind::Decimal(d) | Kind::Percent(d) => number_format(posted_number(value), d),
        Kind::Mixed(d) if is_numeric(value) => number_format(posted_number(value), d),
        Kind::Boolean => if value.starts_with(['1', ',', 'Y', 'y']) { "1" } else { "0" }.into(),
        Kind::Id => super::csrf::php_intval(value).to_string(),
        Kind::Fixed(d) => {
            let n = format!("000000000{}", super::csrf::php_intval(value));
            n[n.len().saturating_sub(d as usize)..].to_string()
        }
        Kind::Date | Kind::Period => {
            let date = if value.is_empty() {
                None
            } else if file == FileType::Xlsx && !value.contains('/') {
                let days = floatval(value);
                chrono::NaiveDate::from_ymd_opt(1899, 12, 30).and_then(|d| d.checked_add_signed(chrono::Duration::days(days as i64)))
            } else {
                parse_any_date(value)
            };
            date.map_or_else(|| "0000/00/00".into(), |d| d.format("%Y/%m/%d").to_string())
        }
        Kind::Uppercase | Kind::Mixed(_) => value.to_uppercase(),
        Kind::Lowercase => value.to_lowercase(),
        Kind::Text => value.replace("<br>", "\r\n").replace("<BR>", "\r\n"),
        Kind::Email => {
            let (address, error) = check::email(value, false);
            if error.is_some() {
                String::new()
            } else {
                address
            }
        }
        _ => value.to_string(),
    }
}

/// fn_number(): the number in the text, a percent sign making it hundredths.
fn posted_number(s: &str) -> f64 {
    let multiplier = if s.contains('%') { 0.01 } else { 1.0 };
    let kept: String = s.chars().filter(|c| c.is_ascii_digit() || *c == '.' || *c == '-').collect();
    floatval(&kept) * multiplier
}

/// strtotime() for the date forms people type: `m/d/Y`, `Y-m-d`, `Y/m/d`.
fn parse_any_date(s: &str) -> Option<chrono::NaiveDate> {
    let parts: Vec<&str> = s.split(['/', '-']).collect();
    let [a, b, c] = parts[..] else { return None };
    let (a, b, c): (i32, u32, u32) = (a.trim().parse().ok()?, b.trim().parse().ok()?, c.trim().parse().ok()?);
    if s.contains('-') || a > 31 {
        chrono::NaiveDate::from_ymd_opt(a, b, c)
    } else {
        let year = c as i32;
        let year = if year < 70 { year + 2000 } else if year < 100 { year + 1900 } else { year };
        chrono::NaiveDate::from_ymd_opt(year, a as u32, b)
    }
}

/// An import's state between the review screen and the update: the parsed rows, as the PHP's internal file.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Draft {
    pub external_file: String,
    /// The columns of the file, by key, in map order.
    pub columns: Vec<String>,
    pub rows: Vec<Vec<String>>,
}

impl Draft {
    pub async fn save(&self, pool: &SqlitePool, session: &TokenHash, page: &str) -> sqlx::Result<()> {
        let body = serde_json::to_vec(self).unwrap_or_default();
        let (hash, now) = (session.as_slice(), unix_now());
        sqlx::query!(
            "INSERT INTO staff_draft (token_hash, page, body, updated_at) VALUES (?1, ?2, ?3, ?4)
             ON CONFLICT (token_hash, page) DO UPDATE SET body = excluded.body, updated_at = excluded.updated_at",
            hash,
            page,
            body,
            now
        )
        .execute(pool)
        .await?;
        Ok(())
    }

    /// The draft, removed: an import runs once.
    pub async fn take(pool: &SqlitePool, session: &TokenHash, page: &str) -> sqlx::Result<Option<Draft>> {
        let hash = session.as_slice();
        let body = sqlx::query_scalar!("DELETE FROM staff_draft WHERE token_hash = ?1 AND page = ?2 RETURNING body", hash, page).fetch_optional(pool).await?;
        Ok(body.and_then(|b| serde_json::from_slice(&b).ok()))
    }

    pub async fn discard(pool: &SqlitePool, session: &TokenHash, page: &str) -> sqlx::Result<()> {
        let hash = session.as_slice();
        sqlx::query!("DELETE FROM staff_draft WHERE token_hash = ?1 AND page = ?2", hash, page).execute(pool).await?;
        Ok(())
    }
}

/// The upload check: which column of the file holds each field, and what is wrong with the header.
pub struct Header {
    /// For each field of the map, its column in the file.
    pub column: HashMap<String, usize>,
    pub errors: Vec<String>,
    /// Every field has a column (an import may then add records).
    pub complete: bool,
}

/// upload_header(): match the header row's names (any case) to the fields' names; the first column of a name wins. A
/// column of an export-only field is ignored; a group with any column needs all its columns.
pub fn match_header(map: &Map, names: &[String]) -> Header {
    let mut column: HashMap<String, usize> = HashMap::new();
    for (i, name) in names.iter().enumerate() {
        let name = name.to_lowercase();
        let Some(f) = map.fields.iter().find(|f| f.name.to_lowercase() == name).or_else(|| map.fields.iter().find(|f| f.key == name)) else { continue };
        if f.import {
            column.entry(f.key.clone()).or_insert(i);
        }
    }
    let groups: Vec<&str> = map.fields.iter().filter(|f| column.contains_key(&f.key)).filter_map(|f| f.group).collect();
    let mut errors = Vec::new();
    for f in &map.fields {
        let required = f.required || f.group.is_some_and(|g| groups.contains(&g));
        if !column.contains_key(&f.key) && required {
            errors.push(format!("Required field missing: {}", f.name));
        }
    }
    let complete = map.fields.iter().all(|f| column.contains_key(&f.key));
    Header { column, errors, complete }
}

/// The upload counts upload_html() shows.
#[derive(Debug, Default, Clone, Copy)]
pub struct Counts {
    pub lines: usize,
    pub accepted: usize,
    pub empty: usize,
    pub rejected: usize,
}

/// `map_class::js()`.
pub fn js() -> &'static str {
    "\n<script>\nfunction scscpq_map_action(action) {\nif (action == 'update') {\nif (!confirm('Continue?')) return;\njQuery('#scscpq_map_buttons').html('Working ...');\n}\njQuery('#action').val(action);\njQuery('#scs_form').submit();\n}\n</script>\n"
}

/// A report value hidden_html() carries forward: one value, or a list (each a `name\tvalue` field set to 1).
pub enum Carried {
    One(String),
    List(Vec<String>),
}

/// hidden_html() / forms->hidden_fields(): the report as hidden fields, skipping names the page already printed.
pub fn hidden_fields(report: &[(&str, Carried)], printed: &[&str]) -> String {
    let mut out = String::new();
    for (key, value) in report {
        match value {
            Carried::One(v) if !printed.contains(key) => out.push_str(&hidden_field(key, v)),
            Carried::One(_) => {}
            Carried::List(items) => {
                for item in items {
                    let name = STANDARD_NO_PAD.encode(format!("{key}\t{item}"));
                    out.push_str(&hidden_field(&name, "1"));
                }
            }
        }
    }
    out
}

/// forms->hidden() as hidden_fields() calls it: a class attribute with no value.
pub fn hidden_field(name: &str, value: &str) -> String {
    let value = if value.is_empty() { String::new() } else { format!(" value='{}'", esc(value)) };
    format!("<input type=hidden name='{0}' class='' id='{0}'{value}>\n", esc(name))
}

const DARKSEAGREEN: &str = "style='background-color: #8fbc8f;'";
const RED: &str = "style='background-color: #ff0000;'";
const YELLOW: &str = "style='background-color: #ffff00;'";

/// export_html(): the field list with a box per optional column or group, and the download button.
pub fn export_html(form: &Form<'_>, map: &Map, hidden: &str, title: &str, count: usize) -> String {
    let checkbox = |name: &str| form.checkbox(name, "1", true, Opts::new().class("scscpq_map_checkbox"));
    let mut lines: Vec<String> = vec![js().to_string(), String::new(), super::html::hidden("option_selected", "1")];
    let all = form.checkbox("option_checked", "1", true, Opts::new().onchange("jQuery('.scscpq_map_checkbox').prop('checked',this.checked);"));
    lines.push(format!("<p class=standard><b>{}</b><br>Select all? {all}</p>", esc(title)));
    lines.extend(["<table class='standard border tablesorter'>", "<thead>", "<tr>", "<th>Include?</th>", "<th>Field name</th>", "<th>Type</th>", "<th>Comment</th>", "</tr>", "</thead>", "<tbody>"].map(String::from));
    for entry in map.entries() {
        match entry {
            Entry::Single(f) if f.hidden => {}
            Entry::Single(f) => {
                let bg = if f.required { DARKSEAGREEN } else { "" };
                lines.push(format!("<tr {bg}>"));
                lines.push("<td class=center rowspan=1>".into());
                lines.push(if f.required { "&nbsp;".into() } else { checkbox(&format!("map_{}", f.key)) });
                lines.push("</td>".into());
                lines.push(format!("<td>{}</td>", esc(&f.name)));
                lines.push(format!("<td>{}</td>", f.kind.spec()));
                lines.push(format!("<td>{}</td>", f.comments().iter().map(|c| esc(c)).collect::<Vec<_>>().join("<br>")));
                lines.push("</tr>".into());
            }
            Entry::Group(name, items) => {
                lines.push("<tr >".into());
                lines.push(format!("<td class=center rowspan={}>", items.len() + 1));
                lines.push(checkbox(&format!("map_{name}")));
                lines.push("</td>".into());
                lines.push(format!("<td>Group: {}</td>", esc(name)));
                lines.push("<td></td>".into());
                lines.push("<td>All fields in group must be imported/exported</td>".into());
                lines.push("</tr>".into());
                for f in items {
                    lines.push("<tr >".into());
                    lines.push(format!("<td>{}</td>", esc(&f.name)));
                    lines.push(format!("<td>{}</td>", esc(&f.kind.type_name())));
                    lines.push(format!("<td>{}</td>", f.comments().iter().map(|c| esc(c)).collect::<Vec<_>>().join("<br>\n")));
                    lines.push("</tr>".into());
                }
            }
        }
    }
    lines.push("</tbody>".into());
    lines.push("</table>".into());
    let update = if count == 0 { "All records will be downloaded".to_string() } else { format!("Download {} records", number(count as i64)) };
    let buttons = [
        button("Return", Opts::new().onclick("scscpq_map_action('cancel');")),
        String::new(),
        button(&update, Opts::new().set("id", "scs_update_button").onclick("scscpq_map_action('update');")),
    ];
    lines.push(format!("<p>{}</p>", buttons.join(" ")));
    lines.extend(
        [
            "<script>",
            "function fn_checked(checked) { ",
            "  for(i=0; i< document.scs_form.elements.length; i++) {",
            "    field=document.scs_form.elements[i].name;",
            "    if (field.substring(0,4)=='map_') document.getElementById(field).checked=checked;",
            "  }",
            "}",
            "</script>",
        ]
        .map(String::from),
    );
    format!("{hidden}{}", lines.join("\n"))
}

/// What upload_html() needs of an upload that was read.
pub struct Review<'a> {
    pub map: &'a Map,
    pub header: &'a Header,
    pub counts: Counts,
    /// Errors of the header (field key or name to message).
    pub errors: &'a HashMap<String, String>,
    pub update: bool,
    /// The table lets an import add records only when every column is present (`map->options['add']`).
    pub add_option: bool,
    pub omit: &'a [&'a str],
}

/// upload_html(): what was read, column by column, and the button that goes on.
pub fn upload_html(hidden: &str, r: &Review<'_>) -> String {
    let mut lines = vec![js().to_string(), String::new()];
    let mut missing = 0;
    lines.push(format!("<p class='standard left'>Records in table: {}", number(r.counts.lines as i64)));
    lines.push(format!("<br>Records accepted for update: {}", number(r.counts.accepted as i64)));
    lines.push(format!("<br>Empty & header records: {}", number(r.counts.empty as i64 + 1)));
    if r.counts.rejected > 0 {
        lines.push(format!("<br>Records rejected: {}", number(r.counts.rejected as i64)));
    }
    lines.push("</p>".into());
    lines.extend(["<table class='scscpq_small border'>", "<tr>", "<th>Field</th>", "<th>Required?</th>", "<th>Column#</th>", "<th>Type</th>", "<th>Error</th>", "</tr>"].map(String::from));
    for f in r.map.fields() {
        let column = r.header.column.get(&f.key);
        if f.hidden && column.is_none() {
            continue;
        }
        let bg = if r.errors.contains_key(&f.key) {
            missing += 1;
            RED
        } else if !f.import || column.is_some() {
            ""
        } else {
            missing += 1;
            YELLOW
        };
        if r.omit.contains(&f.key.as_str()) {
            continue;
        }
        lines.push(format!("<tr {bg}>"));
        lines.push(format!("<td>{}</td>", esc(&f.name)));
        let required = if f.required {
            "Yes"
        } else if !f.import {
            "Export only"
        } else {
            "No"
        };
        lines.push(format!("<td class=center>{required}</td>"));
        lines.push("<td class=center>".into());
        lines.push(column.map_or_else(|| "Omitted".to_string(), |c| (c + 1).to_string()));
        lines.push("</td>".into());
        lines.push(format!("<td>{}</td>", f.comments().iter().map(|c| esc(c)).collect::<Vec<_>>().join("<br>")));
        lines.push(format!("<td>{}</td>", r.errors.get(&f.key).map(String::as_str).unwrap_or("")));
        lines.push("</tr>".into());
    }
    lines.push("</table>".into());
    let mut buttons = vec![button("Cancel", Opts::new().onclick("scscpq_map_action('cancel');"))];
    let text = if !r.header.errors.is_empty() || r.counts.accepted == 0 {
        ""
    } else if !r.update {
        "Continue (Review only, no update)"
    } else if missing > 0 || r.add_option {
        "Continue (update only)"
    } else {
        "Continue (update and add)"
    };
    if !text.is_empty() {
        buttons.push(button(text, Opts::new().onclick("scscpq_map_action('update');")));
    }
    lines.push(format!("<p id=scscpq_map_buttons>{}</p>", buttons.join(" ")));
    if missing > 0 {
        lines.push("<p class='standard center'>Legend: ".into());
        lines.push("<span style='background: #ffff66; color: #000000;'>&nbsp;&nbsp;Missing, required to add&nbsp;&nbsp;</span>".into());
        lines.push("&nbsp;&nbsp;<span style='background: #ff0000; color: #000000;'>&nbsp;&nbsp;Defined multiple times&nbsp;&nbsp;</span>".into());
        lines.push("</p>".into());
    }
    format!("{hidden}{}", lines.join("\n"))
}

/// One rejected line of an import: its line number and the errors by field.
pub struct Rejected {
    pub line: usize,
    pub errors: Vec<(String, String)>,
}

/// import_html(): the summary and each rejected line.
pub fn import_html(hidden: &str, map: &Map, summary: &[String], rejected: &[Rejected]) -> String {
    let mut lines = vec![js().to_string(), String::new()];
    lines.push(format!("<p class=standard>{}</p>", summary.join("<br>")));
    lines.push(format!("<p class=standard>{}</p>", button("Return", Opts::new().onclick("scscpq_map_action('');"))));
    if !rejected.is_empty() {
        lines.extend(["<table class='scscpq_small border'>", "<tr>", "<th>Line#</th>", "<th>Field</th>", "<th>Value</th>", "</tr>"].map(String::from));
        for r in rejected {
            for (i, (field, error)) in r.errors.iter().enumerate() {
                lines.push("<tr>".into());
                if i == 0 {
                    lines.push(format!("<td class=center rowspan={}>{}</td>", r.errors.len(), r.line));
                }
                let name = map.field(field).map_or(field.as_str(), |f| f.name.as_str());
                lines.push(format!("<td>{}</td>", esc(name)));
                lines.push(format!("<td>{error}</td>"));
                lines.push("</tr>".into());
            }
        }
        lines.push("</table>".into());
    }
    format!("{hidden}{}", lines.join("\n"))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn inventory_like() -> Map {
        let mut m = Map::new();
        m.add(Field::new("sku", Kind::Uppercase).name("Harcourt SKU").required());
        m.add(Field::new("category_name", Kind::Alphanumeric).name("Category Name").group("subcategory"));
        m.add(Field::new("subcategory_name", Kind::Alphanumeric).name("Subcategory Name").group("subcategory"));
        m.add(Field::new("sort_order", Kind::Decimal(0)).name("Sort Order"));
        m.add(Field::new("price0", Kind::Decimal(2)).name("Base Price").group("pricing"));
        m.add(Field::new("active", Kind::Boolean).name("Active"));
        m.add(Field::new("qty_break1", Kind::Decimal(0)).name("Qty Break #1").group("pricing"));
        m
    }

    #[test]
    fn groups_keep_their_columns_together() {
        let m = inventory_like();
        let keys: Vec<&str> = m.fields().iter().map(|f| f.key.as_str()).collect();
        assert_eq!(keys, ["sku", "category_name", "subcategory_name", "sort_order", "price0", "qty_break1", "active"]);
    }

    #[test]
    fn a_ticked_group_exports_all_its_columns() {
        let m = inventory_like();
        let posted = Fields::of(&[("option_selected", "1"), ("map_pricing", "1")]);
        let keys: Vec<&str> = m.chosen(&posted).iter().map(|f| f.key.as_str()).collect();
        assert_eq!(keys, ["sku", "price0", "qty_break1"]);
        assert_eq!(m.chosen(&Fields::default()).len(), 7);
    }

    #[test]
    fn values_export_as_export_data_wrote_them() {
        let f = |k| Field::new("x", k);
        assert_eq!(export_cell(&f(Kind::Decimal(2)), "13.535", FileType::Txt), Cell::Text("13.54".into()));
        assert_eq!(export_cell(&f(Kind::Decimal(2)), "", FileType::Txt), Cell::Text("0".into()));
        assert_eq!(export_cell(&f(Kind::Numeric), "2.50", FileType::Txt), Cell::Number(2.5));
        assert_eq!(export_cell(&f(Kind::Boolean), "0", FileType::Txt), Cell::Text("N".into()));
        assert_eq!(export_cell(&f(Kind::Date), "2024-03-05", FileType::Txt), Cell::Text("03/05/2024".into()));
        assert_eq!(export_cell(&f(Kind::Date), "0000-00-00", FileType::Txt), Cell::Text(String::new()));
        assert_eq!(export_cell(&f(Kind::Percent(4)), "0.125", FileType::Txt), Cell::Text("0.125000".into()));
        assert_eq!(export_cell(&f(Kind::Text), "a\r\nb", FileType::Txt), Cell::Text("a<br>b".into()));
    }

    #[test]
    fn csv_lines_quote_like_fputcsv() {
        let line = csv_line(&["A1".into(), "two words".into(), "say \"hi\"".into(), "".into()], b',');
        assert_eq!(String::from_utf8(line).unwrap(), "A1,\"two words\",\"say \"\"hi\"\"\",\n");
        assert_eq!(parse_csv_line("A1,\"two words\",\"say \"\"hi\"\"\",\n", ','), ["A1", "two words", "say \"hi\"", ""]);
        assert_eq!(parse_csv_line("a\tb\r\n", '\t'), ["a", "b"]);
    }

    fn workbook(cells: &[(u32, u16, &str)]) -> Vec<u8> {
        let mut book = rust_xlsxwriter::Workbook::new();
        let sheet = book.add_worksheet();
        for &(row, col, text) in cells {
            sheet.write_string(row, col, text).unwrap();
        }
        book.save_to_buffer().unwrap()
    }

    #[test]
    fn excel_uploads_read_from_their_first_value() {
        let sheet = read_upload("parts.xlsx", &workbook(&[(1, 1, "sku"), (1, 2, "name"), (2, 1, "A-1"), (3, 2, "Clamp")]), None).ok().unwrap();
        assert_eq!(sheet.rows, [vec!["sku", "name"], vec!["A-1"], vec!["", "Clamp"]]);
        assert_eq!(sheet.worksheet.as_deref(), Some("Sheet1"));
        assert_eq!(read_upload("parts.xlsx", b"not a workbook", None).err().unwrap(), "parts.xlsx not XLXS format");
    }

    #[test]
    fn an_excel_sheet_taller_than_an_import_is_refused() {
        let tall = workbook(&[(0, 0, "sku"), (1_048_575, 0, "end")]);
        assert_eq!(read_upload("parts.xlsx", &tall, None).err().unwrap(), "Upload error #1");
    }

    #[test]
    fn numbers_read_like_php() {
        assert!(is_numeric(" 12.5"));
        assert!(is_numeric("1e3"));
        assert!(!is_numeric("12abc"));
        assert!(!is_numeric(""));
        assert_eq!(number_format(2.675, 2), "2.68");
        assert_eq!(number_format(-0.001, 2), "0.00");
        assert_eq!(float_text(3.0), "3");
    }

    #[test]
    fn headers_match_by_name_and_need_whole_groups() {
        let m = inventory_like();
        let h = match_header(&m, &["harcourt sku".into(), "Base Price".into(), "Ignored".into()]);
        assert_eq!(h.column.get("price0"), Some(&1));
        assert_eq!(h.errors, ["Required field missing: Qty Break #1"]);
        assert!(!h.complete);
    }

    #[test]
    fn file_names_carry_the_group() {
        assert_eq!(file_name("inventory", "147", "", FileType::Csv), "inventory_147.csv");
        assert_eq!(file_name("subcategory", "", "", FileType::Txt), "subcategory.txt");
    }

    #[test]
    fn imported_values_take_the_column_type() {
        let f = |k| Field::new("x", k);
        assert_eq!(import_value(&f(Kind::Decimal(2)), " $1,234.5 ", FileType::Txt), "1234.50");
        assert_eq!(import_value(&f(Kind::Boolean), "yes", FileType::Txt), "1");
        assert_eq!(import_value(&f(Kind::Date), "3/5/2024", FileType::Txt), "2024/03/05");
        assert_eq!(import_value(&f(Kind::Date), "45356", FileType::Xlsx), "2024/03/05");
        assert_eq!(import_value(&f(Kind::Uppercase), "ab-1", FileType::Txt), "AB-1");
    }
}
