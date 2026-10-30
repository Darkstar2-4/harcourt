//! Rows out of a `mysqldump --complete-insert` file: `INSERT INTO `t` (`a`, `b`) VALUES (1,'x'),(2,NULL);`.
//! Strings use MySQL's backslash escapes. Nothing else in the dump is read.

use std::collections::HashMap;

#[derive(Debug, Clone, PartialEq)]
pub enum Value {
    Null,
    Int(i64),
    /// Decimal, float or anything else unquoted, as written.
    Num(String),
    Str(String),
}

impl Value {
    pub fn str(&self) -> &str {
        match self {
            Value::Str(s) | Value::Num(s) => s,
            _ => "",
        }
    }

    pub fn int(&self) -> i64 {
        match self {
            Value::Int(i) => *i,
            Value::Str(s) | Value::Num(s) => s.trim().parse().unwrap_or(0),
            Value::Null => 0,
        }
    }
}

#[derive(Debug, Default)]
pub struct Table {
    pub columns: Vec<String>,
    pub rows: Vec<Vec<Value>>,
}

impl Table {
    pub fn col(&self, name: &str) -> Option<usize> {
        self.columns.iter().position(|c| c == name)
    }
}

pub type Tables = HashMap<String, Table>;

pub fn parse(dump: &str) -> Result<Tables, String> {
    let mut tables: Tables = HashMap::new();
    for (n, line) in dump.lines().enumerate() {
        let Some(rest) = line.strip_prefix("INSERT INTO `") else { continue };
        let err = |m: &str| format!("line {}: {m}", n + 1);
        let (name, rest) = rest.split_once('`').ok_or_else(|| err("table name"))?;
        let rest = rest.trim_start().strip_prefix('(').ok_or_else(|| err("expected a column list (dump with --complete-insert)"))?;
        let (cols, rest) = rest.split_once(')').ok_or_else(|| err("column list"))?;
        let columns: Vec<String> = cols.split(',').map(|c| c.trim().trim_matches('`').to_string()).collect();
        let values = rest.trim_start().strip_prefix("VALUES").ok_or_else(|| err("VALUES"))?;
        let table = tables.entry(name.to_string()).or_default();
        if table.columns.is_empty() {
            table.columns = columns.clone();
        } else if table.columns != columns {
            return Err(err("column list changed between INSERTs"));
        }
        for row in Rows::new(values) {
            let row = row.map_err(|e| err(&e))?;
            if row.len() != columns.len() {
                return Err(err("row width does not match the column list"));
            }
            table.rows.push(row);
        }
    }
    Ok(tables)
}

struct Rows<'a> {
    s: &'a [u8],
    src: &'a str,
    i: usize,
}

impl<'a> Rows<'a> {
    fn new(src: &'a str) -> Self {
        Rows { s: src.as_bytes(), src, i: 0 }
    }

    fn row(&mut self) -> Result<Vec<Value>, String> {
        let mut row = Vec::new();
        loop {
            while self.s.get(self.i).is_some_and(u8::is_ascii_whitespace) {
                self.i += 1;
            }
            let v = match self.s.get(self.i) {
                Some(b'\'') => Value::Str(self.string()?),
                Some(_) => {
                    let start = self.i;
                    while self.s.get(self.i).is_some_and(|c| *c != b',' && *c != b')') {
                        self.i += 1;
                    }
                    let tok = self.src[start..self.i].trim();
                    if tok == "NULL" {
                        Value::Null
                    } else if let Ok(i) = tok.parse() {
                        Value::Int(i)
                    } else {
                        Value::Num(tok.to_string())
                    }
                }
                None => return Err("unterminated row".into()),
            };
            row.push(v);
            while self.s.get(self.i).is_some_and(u8::is_ascii_whitespace) {
                self.i += 1;
            }
            match self.s.get(self.i) {
                Some(b',') => self.i += 1,
                Some(b')') => {
                    self.i += 1;
                    return Ok(row);
                }
                _ => return Err("expected , or )".into()),
            }
        }
    }

    fn string(&mut self) -> Result<String, String> {
        self.i += 1;
        let mut out: Vec<u8> = Vec::new();
        loop {
            match self.s.get(self.i) {
                Some(b'\\') => {
                    let c = *self.s.get(self.i + 1).ok_or("dangling escape")?;
                    out.push(match c {
                        b'n' => b'\n',
                        b'r' => b'\r',
                        b't' => b'\t',
                        b'0' => 0,
                        b'Z' => 0x1a,
                        b'b' => 0x08,
                        other => other,
                    });
                    self.i += 2;
                }
                Some(b'\'') if self.s.get(self.i + 1) == Some(&b'\'') => {
                    out.push(b'\'');
                    self.i += 2;
                }
                Some(b'\'') => {
                    self.i += 1;
                    return String::from_utf8(out).map_err(|e| format!("not UTF-8: {e}"));
                }
                Some(c) => {
                    out.push(*c);
                    self.i += 1;
                }
                None => return Err("unterminated string".into()),
            }
        }
    }
}

impl Iterator for Rows<'_> {
    type Item = Result<Vec<Value>, String>;

    fn next(&mut self) -> Option<Self::Item> {
        while let Some(c) = self.s.get(self.i) {
            match c {
                b'(' => {
                    self.i += 1;
                    return Some(self.row());
                }
                b';' => return None,
                _ => self.i += 1,
            }
        }
        None
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reads_complete_inserts_with_mysql_escapes() {
        let dump = "-- comment\nCREATE TABLE `t` (x int);\nINSERT INTO `t` (`id`, `name`, `n`) VALUES (1,'it\\'s \\\"x\\\"\\n(ok), done',NULL),(2,'a''b',1.50);\nINSERT INTO `t` (`id`, `name`, `n`) VALUES (3,'é',-4);\n";
        let t = &parse(dump).unwrap()["t"];
        assert_eq!(t.columns, ["id", "name", "n"]);
        assert_eq!(t.rows.len(), 3);
        assert_eq!(t.rows[0], vec![Value::Int(1), Value::Str("it's \"x\"\n(ok), done".into()), Value::Null]);
        assert_eq!(t.rows[1][1], Value::Str("a'b".into()));
        assert_eq!(t.rows[1][2], Value::Num("1.50".into()));
        assert_eq!(t.rows[2], vec![Value::Int(3), Value::Str("é".into()), Value::Int(-4)]);
    }

    #[test]
    fn refuses_a_dump_without_column_names() {
        assert!(parse("INSERT INTO `t` VALUES (1);\n").is_err());
    }
}
