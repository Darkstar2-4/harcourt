//! Values the PHP stored with serialize(): subcategory.parameters/options/download/documents and inventory.pricing.
//! Parsed once at the row boundary into `Value`; the catalog's own types are built from that.
//!
//! The tables were latin1 in MySQL and are UTF-8 here, so a string's declared byte length can be short by the
//! accented characters in it, never long. A string whose declared length does not land on its closing `";` is read
//! up to the next `";` that is followed by something a serialized value can continue with.

#[derive(Debug, Clone, PartialEq)]
pub enum Value {
    Null,
    Bool(bool),
    Int(i64),
    Float(f64),
    Str(String),
    /// Arrays and objects alike: keys in order, object property names as string keys.
    Map(Vec<(Key, Value)>),
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Key {
    Int(i64),
    Str(String),
}

impl Key {
    pub fn as_string(&self) -> String {
        match self {
            Key::Int(i) => i.to_string(),
            Key::Str(s) => s.clone(),
        }
    }
}

impl Value {
    pub fn get(&self, key: &str) -> Option<&Value> {
        match self {
            Value::Map(entries) => entries.iter().find(|(k, _)| k.as_string() == key).map(|(_, v)| v),
            _ => None,
        }
    }

    pub fn entries(&self) -> &[(Key, Value)] {
        match self {
            Value::Map(entries) => entries,
            _ => &[],
        }
    }

    /// The value as PHP would print it: strings as they are, numbers in PHP's shortest form, true as "1".
    pub fn text(&self) -> String {
        match self {
            Value::Null | Value::Bool(false) | Value::Map(_) => String::new(),
            Value::Bool(true) => "1".into(),
            Value::Int(i) => i.to_string(),
            Value::Float(f) => format_float(*f),
            Value::Str(s) => s.clone(),
        }
    }

    pub fn int(&self) -> i64 {
        match self {
            Value::Int(i) => *i,
            Value::Float(f) => *f as i64,
            Value::Bool(b) => i64::from(*b),
            Value::Str(s) => leading_number(s) as i64,
            Value::Null | Value::Map(_) => 0,
        }
    }

    pub fn float(&self) -> f64 {
        match self {
            Value::Int(i) => *i as f64,
            Value::Float(f) => *f,
            Value::Bool(b) => f64::from(u8::from(*b)),
            Value::Str(s) => leading_number(s),
            Value::Null | Value::Map(_) => 0.0,
        }
    }
}

fn format_float(f: f64) -> String {
    if f.fract() == 0.0 && f.abs() < 1e15 {
        format!("{}", f as i64)
    } else {
        format!("{f}")
    }
}

/// PHP's numeric-prefix reading of a string ("12abc" is 12, "abc" is 0).
fn leading_number(s: &str) -> f64 {
    crate::staff::catalog::php_floatval(s)
}

/// PHP's `max_input_vars` on prod (the docroot's .htaccess): the variables after the first 3000 are not read.
pub const MAX_INPUT_VARS: usize = 3000;

/// PHP's `max_input_nesting_level`: a variable with more `[]` levels than this is dropped, with any value already
/// set under its name.
pub const MAX_INPUT_NESTING: usize = 64;

/// A query variable as php_register_variable_ex() reads its decoded name.
pub enum Variable {
    /// The name (leading spaces dropped, ` ` and `.` made `_`; a `[` never closed is part of it, as `_`) and the
    /// `[...]` indexes after it, `None` for `[]`.
    Set(String, Vec<Option<String>>),
    /// More indexes than `max_input_nesting_level`: PHP drops the variable and what was set under its name.
    TooDeep(String),
    /// An empty name: not read.
    Skip,
}

pub fn variable(key: &str) -> Variable {
    let key = key.trim_start_matches(' ');
    let (name, mut rest) = match key.find('[') {
        Some(i) if key[i..].contains(']') => (key[..i].replace(['.', ' '], "_"), &key[i..]),
        Some(i) if i > 0 => (format!("{}_{}", key[..i].replace(['.', ' '], "_"), key[i + 1..].replace(['.', ' ', '['], "_")), ""),
        Some(_) => return Variable::Skip,
        None => (key.replace(['.', ' '], "_"), ""),
    };
    if name.is_empty() {
        return Variable::Skip;
    }
    let mut indexes = Vec::new();
    while let Some((index, after)) = rest.strip_prefix('[').and_then(|r| r.split_once(']')) {
        if indexes.len() == MAX_INPUT_NESTING {
            return Variable::TooDeep(name);
        }
        indexes.push(Some(index.to_string()).filter(|s| !s.is_empty()));
        rest = after;
    }
    Variable::Set(name, indexes)
}

/// PHP's parse_str() of a query string ($_GET): keys and values urldecoded, `.` and spaces in a key's name made
/// `_`, `a[]` and `a[k]` building nested arrays, a repeated key keeping its first place and its last value.
pub fn parse_str(query: &str) -> Value {
    let decode = |s: &str| percent_encoding::percent_decode_str(&s.replace('+', " ")).decode_utf8_lossy().into_owned();
    let mut root: Vec<(Key, Value)> = Vec::new();
    for pair in query.split('&').filter(|p| !p.is_empty()).take(MAX_INPUT_VARS) {
        let (k, v) = pair.split_once('=').unwrap_or((pair, ""));
        match variable(&decode(k)) {
            Variable::Set(name, indexes) => {
                let path: Vec<Option<String>> = std::iter::once(Some(name)).chain(indexes).collect();
                insert(&mut root, &path, Value::Str(decode(v)));
            }
            Variable::TooDeep(name) => root.retain(|(k, _)| k.as_string() != name),
            Variable::Skip => {}
        }
    }
    Value::Map(root)
}

fn insert(map: &mut Vec<(Key, Value)>, path: &[Option<String>], value: Value) {
    let Some((first, rest)) = path.split_first() else { return };
    let key = match first {
        Some(s) => s.parse::<i64>().ok().filter(|n| n.to_string() == *s).map_or_else(|| Key::Str(s.clone()), Key::Int),
        None => {
            let last = map.iter().filter_map(|(k, _)| if let Key::Int(i) = k { Some(*i) } else { None }).max();
            // PHP refuses to append after the largest integer key.
            let Some(next) = last.map_or(Some(0), |i| i.checked_add(1)) else { return };
            Key::Int(next.max(0))
        }
    };
    let at = match map.iter().position(|(k, _)| *k == key) {
        Some(i) => i,
        None => {
            map.push((key, Value::Null));
            map.len() - 1
        }
    };
    if rest.is_empty() {
        map[at].1 = value;
        return;
    }
    if !matches!(map[at].1, Value::Map(_)) {
        map[at].1 = Value::Map(Vec::new());
    }
    if let Value::Map(inner) = &mut map[at].1 {
        insert(inner, rest, value);
    }
}

/// print_r($value, TRUE).
pub fn print_r(v: &Value) -> String {
    let mut out = String::new();
    print_r_into(&mut out, v, 0);
    out
}

fn print_r_into(out: &mut String, v: &Value, pad: usize) {
    let Value::Map(entries) = v else {
        out.push_str(&v.text());
        return;
    };
    out.push_str("Array\n");
    out.push_str(&" ".repeat(pad));
    out.push_str("(\n");
    for (k, child) in entries {
        out.push_str(&format!("{}[{}] => ", " ".repeat(pad + 4), k.as_string()));
        print_r_into(out, child, pad + 8);
        out.push('\n');
    }
    out.push_str(&" ".repeat(pad));
    out.push_str(")\n");
}

/// None for text that is not a serialized value (PHP's unserialize returned false).
pub fn unserialize(s: &str) -> Option<Value> {
    let mut p = Parser { s: s.as_bytes(), at: 0, depth: 0 };
    let v = p.value()?;
    Some(v)
}

/// The deepest nesting read. The stored columns nest three deep; text nested past this reads as not serialized,
/// which keeps the recursion well inside a worker thread's stack.
pub const MAX_DEPTH: usize = 64;

struct Parser<'a> {
    s: &'a [u8],
    at: usize,
    depth: usize,
}

impl Parser<'_> {
    fn value(&mut self) -> Option<Value> {
        let tag = *self.s.get(self.at)?;
        self.at += 1;
        match tag {
            b'N' => {
                self.expect(b';')?;
                Some(Value::Null)
            }
            b'b' => {
                self.expect(b':')?;
                let n = self.until(b';')?;
                Some(Value::Bool(n != "0"))
            }
            b'i' => {
                self.expect(b':')?;
                Some(Value::Int(self.until(b';')?.parse().ok()?))
            }
            b'd' => {
                self.expect(b':')?;
                let n = self.until(b';')?;
                Some(Value::Float(match n.as_str() {
                    "INF" => f64::INFINITY,
                    "-INF" => f64::NEG_INFINITY,
                    "NAN" => f64::NAN,
                    n => n.parse().ok()?,
                }))
            }
            b's' => {
                self.expect(b':')?;
                let len: usize = self.until(b':')?.parse().ok()?;
                self.expect(b'"')?;
                let text = self.string_body(len)?;
                Some(Value::Str(text))
            }
            b'a' => {
                self.expect(b':')?;
                let n: usize = self.until(b':')?.parse().ok()?;
                self.map(n).map(Value::Map)
            }
            b'O' => {
                self.expect(b':')?;
                let len: usize = self.until(b':')?.parse().ok()?;
                self.expect(b'"')?;
                self.at = self.at.checked_add(len)?;
                self.expect(b'"')?;
                self.expect(b':')?;
                let n: usize = self.until(b':')?.parse().ok()?;
                self.map(n).map(Value::Map)
            }
            _ => None,
        }
    }

    fn map(&mut self, n: usize) -> Option<Vec<(Key, Value)>> {
        self.expect(b'{')?;
        self.depth += 1;
        if self.depth > MAX_DEPTH {
            return None;
        }
        let mut entries = Vec::with_capacity(n.min(256));
        for _ in 0..n {
            let key = match self.value()? {
                Value::Int(i) => Key::Int(i),
                Value::Str(s) => Key::Str(property_name(&s)),
                _ => return None,
            };
            let value = self.value()?;
            entries.push((key, value));
        }
        self.expect(b'}')?;
        self.depth -= 1;
        Some(entries)
    }

    fn string_body(&mut self, declared: usize) -> Option<String> {
        let start = self.at;
        let exact = start.checked_add(declared)?;
        let end = if self.s.get(exact..exact.saturating_add(2)) == Some(b"\";") && self.continues(exact + 2) {
            exact
        } else {
            (exact..self.s.len().saturating_sub(1)).find(|&i| &self.s[i..i + 2] == b"\";" && self.continues(i + 2))?
        };
        self.at = end + 2;
        Some(String::from_utf8_lossy(&self.s[start..end]).into_owned())
    }

    /// After a string's `";` comes the end of input, the end of a map, or the next key or value.
    fn continues(&self, at: usize) -> bool {
        match self.s.get(at) {
            None | Some(b'}') => true,
            Some(b'N') => self.s.get(at + 1) == Some(&b';'),
            Some(b'i' | b'd' | b's' | b'a' | b'b' | b'O') => self.s.get(at + 1) == Some(&b':'),
            _ => false,
        }
    }

    fn expect(&mut self, b: u8) -> Option<()> {
        (self.s.get(self.at) == Some(&b)).then(|| self.at += 1)
    }

    fn until(&mut self, stop: u8) -> Option<String> {
        let start = self.at;
        let len = self.s[start..].iter().position(|&c| c == stop)?;
        self.at = start + len + 1;
        String::from_utf8(self.s[start..start + len].to_vec()).ok()
    }
}

/// Private and protected properties carry a NUL-delimited class prefix in their serialized name.
fn property_name(s: &str) -> String {
    match s.rfind('\0') {
        Some(i) => s[i + 1..].to_string(),
        None => s.to_string(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn print_r_of_a_query_as_php_prints_get() {
        assert_eq!(print_r(&parse_str("debug=1&scscpq_wp=1")), "Array\n(\n    [debug] => 1\n    [scscpq_wp] => 1\n)\n");
        assert_eq!(print_r(&parse_str("")), "Array\n(\n)\n");
        assert_eq!(
            print_r(&parse_str("a.b=x+y&p[]=1&p[]=2&a.b=z&q[k]=v")),
            "Array\n(\n    [a_b] => z\n    [p] => Array\n        (\n            [0] => 1\n            [1] => 2\n        )\n\n    [q] => Array\n        (\n            [k] => v\n        )\n\n)\n"
        );
        assert_eq!(parse_str("x[=1").get("x_").map(Value::text).as_deref(), Some("1"), "an unclosed bracket becomes _");
        assert_eq!(parse_str("=1&%20s=2").entries().len(), 1);
    }

    #[test]
    fn reads_scalars_arrays_and_objects() {
        let v = unserialize(r#"a:2:{i:0;O:27:"subcategory_parameter_class":3:{s:2:"id";i:0;s:4:"name";s:8:"Diameter";s:7:"numeric";b:1;}s:3:"sku";s:5:"Part#";}"#).unwrap();
        let first = v.get("0").unwrap();
        assert_eq!(first.get("name").unwrap().text(), "Diameter");
        assert_eq!(first.get("id").unwrap().int(), 0);
        assert_eq!(first.get("numeric"), Some(&Value::Bool(true)));
        assert_eq!(v.get("sku").unwrap().text(), "Part#");
        assert_eq!(unserialize("d:12.5;"), Some(Value::Float(12.5)));
        assert_eq!(unserialize("N;"), Some(Value::Null));
    }

    #[test]
    fn a_string_whose_length_counted_latin1_bytes_still_reads() {
        let v = unserialize(r#"a:2:{s:4:"name";s:6:"Dégagé";s:4:"next";i:3;}"#).unwrap();
        assert_eq!(v.get("name").unwrap().text(), "Dégagé");
        assert_eq!(v.get("next").unwrap().int(), 3);
    }

    #[test]
    fn junk_is_not_a_value() {
        for bad in ["", "x", "a:1:{i:0;}", "s:10:\"short\";", "a:2:{i:0;i:1;}"] {
            assert_eq!(unserialize(bad), None, "{bad:?}");
        }
    }

    #[test]
    fn numbers_print_and_read_as_php_does() {
        assert_eq!(Value::Float(3.0).text(), "3");
        assert_eq!(Value::Float(0.25).text(), "0.25");
        assert_eq!(Value::Str("12.5mm".into()).float(), 12.5);
        assert_eq!(Value::Str("abc".into()).int(), 0);
    }
}
