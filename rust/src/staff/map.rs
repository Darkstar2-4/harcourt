//! includes/map_rev3.php: moving a table to and from a desktop file. A table describes its columns once (`Map`);
//! it is written as tab- or comma-separated text or an Excel workbook, and such a file is read back with its header
//! row matched to the columns. The tables themselves (which rows, how a row is checked and saved) are in data.rs.

use std::collections::HashMap;

use super::time;

/// The largest file an import reads.
pub const MAX_UPLOAD: usize = 20 * 1024 * 1024;
/// Rows an Excel export may hold.
const XLS_ROWS: usize = 1_000_000;

/// A column's type (table_map_item_class), which decides how a value is written and read.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Kind {
    Alphanumeric,
    Lowercase,
    Uppercase,
    Text,
    Date,
    Timestamp,
    Numeric,
    Decimal(u32),
    Id,
    Percent(u32),
    Boolean,
}

impl Kind {
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
    pub width: u32,
}

impl Field {
    pub fn new(key: impl Into<String>, kind: Kind) -> Field {
        let key = key.into();
        Field { name: key.clone(), key, kind, group: None, required: false, import: true, width: 21 }
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
}

/// A table's columns in the order the table named them. Columns of one group stay together, where the group's
/// first column came.
#[derive(Debug, Clone, Default)]
pub struct Map {
    fields: Vec<Field>,
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
}

/// The file formats: Excel, tab-separated text, comma-separated text.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum FileType {
    Xlsx,
    Txt,
    Csv,
}

impl FileType {
    /// upload_file_type(): by the file name's ending.
    pub fn of_name(name: &str) -> Option<FileType> {
        let lower = name.to_ascii_lowercase();
        let ends = |s: &str| lower.len() > s.len() && lower.ends_with(s);
        match () {
            _ if ends("csv") => Some(FileType::Csv),
            _ if ends("txt") => Some(FileType::Txt),
            _ if ends("xlsx") => Some(FileType::Xlsx),
            _ => None,
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

/// A row as the table read it, one text per column of the map in the same order (NULL reads as "").
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
    crate::orders::shop::number_format(d, places).replace(',', "")
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
        Kind::Boolean => Cell::Text(if truthy(value) { "Y" } else { "N" }.into()),
        Kind::Date if xlsx => Cell::Text(if truthy(value) { value.replace('/', "-") } else { String::new() }),
        Kind::Timestamp if xlsx => Cell::Text(if truthy(value) { time::date("Y-m-d H:i:s", floatval(value) as i64) } else { String::new() }),
        Kind::Date => Cell::Text(date_ymd(value)),
        Kind::Timestamp => Cell::Text(time::date("m/d/Y H:i:s", floatval(value) as i64)),
        Kind::Text if !xlsx => Cell::Text(value.replace("\r\n", "<br>")),
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
        v => {
            let parts: Vec<&str> = v.split([' ', '/', '.', '-']).collect();
            [1, 2, 0].iter().map(|&i| parts.get(i).copied().unwrap_or("")).collect::<Vec<_>>().join("/")
        }
    }
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
        }
        while let Some(c) = chars.peek().copied() {
            if c == delimiter {
                break;
            }
            field.push(c);
            chars.next();
        }
        out.push(field);
        if chars.next().is_none() {
            break;
        }
    }
    out
}

/// The rows of a table as a file of the given type: the column names, then one line (or worksheet row) each.
pub fn export_file(file: FileType, fields: &[&Field], rows: &[Record]) -> Result<Vec<u8>, String> {
    let cells = |r: &Record| -> Vec<Cell> { fields.iter().zip(r).map(|(f, v)| export_cell(f, v, file)).collect() };
    if file == FileType::Xlsx {
        if rows.len() > XLS_ROWS {
            return Err(format!("{} rows exceed Excel's {XLS_ROWS}; use txt or csv", rows.len()));
        }
        let mut book = rust_xlsxwriter::Workbook::new();
        sheet(&mut book, "Data", fields, rows.iter().map(cells)).map_err(|e| e.to_string())?;
        return book.save_to_buffer().map_err(|e| e.to_string());
    }
    let mut out = vec![0xEF, 0xBB, 0xBF];
    out.extend(csv_line(&fields.iter().map(|f| f.name.clone()).collect::<Vec<_>>(), file.delimiter()));
    for r in rows {
        out.extend(csv_line(&cells(r).iter().map(Cell::text).collect::<Vec<_>>(), file.delimiter()));
    }
    Ok(out)
}

/// export() with `xls_multiple`: one Excel worksheet per named part, each with its own columns.
pub fn export_sheets(sheets: &[(String, Map, Vec<Record>)]) -> Result<Vec<u8>, String> {
    let mut book = rust_xlsxwriter::Workbook::new();
    for (title, map, rows) in sheets {
        let fields: Vec<&Field> = map.fields().iter().collect();
        let cells = rows.iter().map(|r| fields.iter().zip(r).map(|(f, v)| export_cell(f, v, FileType::Xlsx)).collect());
        sheet(&mut book, title, &fields, cells).map_err(|e| e.to_string())?;
    }
    book.save_to_buffer().map_err(|e| e.to_string())
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

/// A file read for import: its rows of text cells, header row first.
pub struct Sheet {
    pub file_type: FileType,
    pub rows: Vec<Vec<String>>,
    /// The worksheet read, for an Excel file.
    pub worksheet: Option<String>,
}

/// upload_from_post() and upload_open(): the file's rows, or why it cannot be read.
pub fn read_upload(file_name: &str, bytes: &[u8], worksheet: Option<&str>) -> Result<Sheet, String> {
    let Some(file_type) = FileType::of_name(file_name) else { return Err(format!("{file_name} format must be txt, csv or xlsx")) };
    if bytes.len() > MAX_UPLOAD {
        return Err(format!("{file_name} is larger than {MAX_UPLOAD} bytes"));
    }
    match file_type {
        FileType::Xlsx => read_xlsx(bytes, worksheet).map_err(|e| match e {
            Unread::Format => format!("{file_name} not XLSX format"),
            Unread::Size => format!("{file_name} is too large to import"),
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

/// Why an Excel file was not read.
enum Unread {
    Format,
    Size,
}

/// What an Excel file may unpack to. The zip reader holds each part to the size the archive declares for it.
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
        Kind::Boolean => if value.starts_with(['1', ',', 'Y', 'y']) { "1" } else { "0" }.into(),
        Kind::Id => super::csrf::php_intval(value).to_string(),
        Kind::Date => {
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
        Kind::Uppercase => value.to_uppercase(),
        Kind::Lowercase => value.to_lowercase(),
        Kind::Text => value.replace("<br>", "\r\n").replace("<BR>", "\r\n"),
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

/// The header check: which column of the file holds each field, and what is wrong with the header.
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
    fn excel_files_read_from_their_first_value() {
        let sheet = read_upload("parts.xlsx", &workbook(&[(1, 1, "sku"), (1, 2, "name"), (2, 1, "A-1"), (3, 2, "Clamp")]), None).ok().unwrap();
        assert_eq!(sheet.rows, [vec!["sku", "name"], vec!["A-1"], vec!["", "Clamp"]]);
        assert_eq!(sheet.worksheet.as_deref(), Some("Sheet1"));
        assert_eq!(read_upload("parts.xlsx", b"not a workbook", None).err().unwrap(), "parts.xlsx not XLSX format");
    }

    #[test]
    fn an_excel_sheet_taller_than_an_import_is_refused() {
        let tall = workbook(&[(0, 0, "sku"), (1_048_575, 0, "end")]);
        assert_eq!(read_upload("parts.xlsx", &tall, None).err().unwrap(), "parts.xlsx is too large to import");
    }

    #[test]
    fn exports_read_back_through_the_header() {
        let m = inventory_like();
        let fields: Vec<&Field> = m.fields().iter().collect();
        let row: Record = ["ab-1", "Clamps", "Toggle", "5", "12.5", "3", "1"].map(String::from).to_vec();
        for (name, file) in [("x.txt", FileType::Txt), ("x.csv", FileType::Csv), ("x.xlsx", FileType::Xlsx)] {
            let bytes = export_file(file, &fields, std::slice::from_ref(&row)).unwrap();
            let sheet = read_upload(name, &bytes, None).ok().unwrap();
            let header = match_header(&m, &sheet.rows[0]);
            assert!(header.errors.is_empty() && header.complete, "{name}");
            assert_eq!(sheet.rows[1][header.column["price0"]], if file == FileType::Xlsx { "12.5" } else { "12.50" }, "{name}");
        }
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
    fn imported_values_take_the_column_type() {
        let f = |k| Field::new("x", k);
        assert_eq!(import_value(&f(Kind::Decimal(2)), " $1,234.5 ", FileType::Txt), "1234.50");
        assert_eq!(import_value(&f(Kind::Boolean), "yes", FileType::Txt), "1");
        assert_eq!(import_value(&f(Kind::Date), "3/5/2024", FileType::Txt), "2024/03/05");
        assert_eq!(import_value(&f(Kind::Date), "45356", FileType::Xlsx), "2024/03/05");
        assert_eq!(import_value(&f(Kind::Uppercase), "ab-1", FileType::Txt), "AB-1");
    }
}
