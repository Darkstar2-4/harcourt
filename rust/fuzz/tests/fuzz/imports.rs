//! The staff import files: tab and comma separated text and Excel workbooks, random, truncated and edited.

use std::{io::Write, time::Duration};

use hxweb::staff::map::{self, csv_line, parse_csv_line, FileType};
use hxweb_fuzz::{alloc::bounded, config, gen, heavy, within, PURE};
use proptest::prelude::*;

const READ: Duration = Duration::from_secs(2);

/// What reading a file of `n` bytes may hold at its peak.
fn memory_bound(n: usize) -> usize {
    64 * n + (8 << 20)
}

fn read(name: &str, bytes: &[u8], worksheet: Option<&str>) -> Result<(), TestCaseError> {
    let (_, held) = bounded(|| within(READ, name, || map::read_upload(name, bytes, worksheet)));
    prop_assert!(held <= memory_bound(bytes.len()), "{} of {} bytes held {} bytes", name, bytes.len(), held);
    Ok(())
}

fn workbook(rows: &[Vec<String>], numbers: &[(u32, u16, f64)]) -> Vec<u8> {
    let mut book = rust_xlsxwriter::Workbook::new();
    let sheet = book.add_worksheet();
    for (r, row) in rows.iter().enumerate() {
        for (c, text) in row.iter().enumerate() {
            sheet.write_string(r as u32, c as u16, text).unwrap();
        }
    }
    for &(r, c, n) in numbers {
        sheet.write_number(r, c, n).unwrap();
    }
    book.save_to_buffer().unwrap()
}

/// A workbook whose parts are stored uncompressed, so an edit lands in the XML itself.
fn stored_workbook(sheet_xml: &str, shared: &str) -> Vec<u8> {
    let parts = [
        ("[Content_Types].xml", r#"<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>"#.to_string()),
        ("_rels/.rels", r#"<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>"#.to_string()),
        ("xl/workbook.xml", r#"<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Data" sheetId="1" r:id="rId1"/></sheets></workbook>"#.to_string()),
        ("xl/_rels/workbook.xml.rels", r#"<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>"#.to_string()),
        ("xl/sharedStrings.xml", shared.to_string()),
        ("xl/worksheets/sheet1.xml", sheet_xml.to_string()),
    ];
    let mut out = zip::ZipWriter::new(std::io::Cursor::new(Vec::new()));
    let stored = zip::write::SimpleFileOptions::default().compression_method(zip::CompressionMethod::Stored);
    for (name, body) in parts {
        out.start_file(name, stored).unwrap();
        out.write_all(body.as_bytes()).unwrap();
    }
    out.finish().unwrap().into_inner()
}

fn column(c: u32) -> String {
    let mut c = c + 1;
    let mut name = Vec::new();
    while c > 0 {
        name.push(b'A' + ((c - 1) % 26) as u8);
        c = (c - 1) / 26;
    }
    name.reverse();
    String::from_utf8(name).unwrap()
}

/// An ordinary sheet: a header and rows of shared strings and numbers, as Excel writes them.
fn sheet_xml(cells: &[(u32, u32, Result<usize, i64>)]) -> String {
    let mut xml = String::from(r#"<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>"#);
    for &(r, c, v) in cells {
        let at = format!("{}{}", column(c), r + 1);
        match v {
            Ok(s) => xml.push_str(&format!(r#"<row r="{}"><c r="{at}" t="s"><v>{s}</v></c></row>"#, r + 1)),
            Err(n) => xml.push_str(&format!(r#"<row r="{}"><c r="{at}"><v>{n}</v></c></row>"#, r + 1)),
        }
    }
    xml.push_str("</sheetData></worksheet>");
    xml
}

fn shared_xml(strings: &[String]) -> String {
    let items: String = strings.iter().map(|s| format!("<si><t>{}</t></si>", s.replace('&', "&amp;").replace('<', "&lt;"))).collect();
    format!(r#"<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="{0}" uniqueCount="{0}">{items}</sst>"#, strings.len())
}

fn edit(bytes: &mut Vec<u8>, edits: &[(usize, u8, u8)]) {
    for &(at, kind, b) in edits {
        if bytes.is_empty() {
            return;
        }
        let at = at % bytes.len();
        match kind % 4 {
            0 => bytes.truncate(at),
            1 => bytes[at] = b,
            2 => bytes.insert(at, b),
            _ => bytes[at] = if bytes[at].is_ascii_digit() { b'0' + b % 10 } else { bytes[at] },
        }
    }
}

proptest! {
    #![proptest_config(config(4096))]

    #[test]
    fn text_files_read_in_bounded_time_and_memory(bytes in prop_oneof![gen::bytes(4000), gen::text_of("ab,\t\"\\\r\n é", 0..4000).prop_map(String::into_bytes)], tab in any::<bool>()) {
        read(if tab { "rows.txt" } else { "rows.csv" }, &bytes, None)?;
    }

    /// fputcsv() then str_getcsv(): a line of fields reads back as the same fields, outside the corner PHP itself
    /// gets wrong (a backslash before a quote).
    #[test]
    fn csv_lines_round_trip(fields in proptest::collection::vec(gen::text_of("ab ,\t\"é;|", 0..8), 1..6), tab in any::<bool>()) {
        let delimiter = if tab { b'\t' } else { b',' };
        let line = String::from_utf8(csv_line(&fields, delimiter)).unwrap();
        let back = parse_csv_line(&line, delimiter as char);
        prop_assert_eq!(back, fields, "{:?}", line);
    }

    #[test]
    fn csv_line_reading_is_total(line in gen::text(300), delimiter in prop_oneof![Just(','), Just('\t'), any::<char>()]) {
        within(PURE, "parse_csv_line", || parse_csv_line(&line, delimiter));
    }

    #[test]
    fn upload_names_are_total(name in gen::text(40), worksheet in proptest::option::of(gen::text(20))) {
        let _ = map::read_upload(&name, b"a,b\n1,2\n", worksheet.as_deref());
    }
}

proptest! {
    #![proptest_config(heavy(192, 200))]

    /// A workbook Excel would write reads back cell for cell.
    #[test]
    fn workbooks_read_back(rows in proptest::collection::vec(proptest::collection::vec(gen::text_of("abcXYZ 0123456789.-é€\"'<&", 1..10), 1..5), 1..8)) {
        let bytes = workbook(&rows, &[]);
        let sheet = map::read_upload("upload.xlsx", &bytes, None).map_err(TestCaseError::fail)?;
        prop_assert_eq!(sheet.file_type, FileType::Xlsx);
        prop_assert_eq!(sheet.rows, rows);
    }

    #[test]
    fn edited_workbooks_are_read_or_refused(rows in proptest::collection::vec(proptest::collection::vec("[a-z0-9]{1,6}", 1..4), 1..6), edits in proptest::collection::vec((any::<usize>(), any::<u8>(), any::<u8>()), 1..6)) {
        let mut bytes = workbook(&rows, &[(3, 2, 1.5)]);
        edit(&mut bytes, &edits);
        read("upload.xlsx", &bytes, Some("Sheet1"))?;
    }

    /// The same with the XML stored as written: an edit changes a cell's place, a length or a count.
    #[test]
    fn edited_sheet_xml_is_read_or_refused(
        strings in proptest::collection::vec("[a-z]{0,6}", 1..5),
        cells in proptest::collection::vec((0u32..40, 0u32..30, prop_oneof![(0usize..5).prop_map(Ok), any::<i64>().prop_map(Err)]), 1..12),
        edits in proptest::collection::vec((any::<usize>(), any::<u8>(), any::<u8>()), 0..6),
    ) {
        let mut bytes = stored_workbook(&sheet_xml(&cells), &shared_xml(&strings));
        edit(&mut bytes, &edits);
        read("upload.xlsx", &bytes, None)?;
    }

    #[test]
    fn random_bytes_named_xlsx_are_refused(bytes in gen::bytes(3000)) {
        prop_assert!(map::read_upload("upload.xlsx", &bytes, None).is_err());
    }
}
