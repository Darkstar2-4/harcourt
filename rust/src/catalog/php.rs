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
    let s = s.trim_start();
    let mut end = 0;
    for (i, c) in s.char_indices() {
        let ok = c.is_ascii_digit() || (i == 0 && (c == '-' || c == '+')) || c == '.' || c == 'e' || c == 'E';
        if !ok {
            break;
        }
        end = i + c.len_utf8();
    }
    (0..=end).rev().find_map(|n| s[..n].parse::<f64>().ok()).unwrap_or(0.0)
}

/// None for text that is not a serialized value (PHP's unserialize returned false).
pub fn unserialize(s: &str) -> Option<Value> {
    let mut p = Parser { s: s.as_bytes(), at: 0 };
    let v = p.value()?;
    Some(v)
}

struct Parser<'a> {
    s: &'a [u8],
    at: usize,
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
        Some(entries)
    }

    fn string_body(&mut self, declared: usize) -> Option<String> {
        let start = self.at;
        let exact = start.checked_add(declared)?;
        let end = if self.s.get(exact..exact + 2) == Some(b"\";") && self.continues(exact + 2) {
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
