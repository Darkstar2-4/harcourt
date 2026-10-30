//! PHP's serialize() format, for the columns the PHP classes stored that way (inventory.pricing and .documents,
//! subcategory.parameters and .download). Strings are counted in bytes, as PHP counted them.

#[derive(Debug, Clone, PartialEq)]
pub enum Php {
    Null,
    Bool(bool),
    Int(i64),
    /// A float kept as the text PHP wrote, so it round-trips digit for digit.
    Float(String),
    Str(String),
    Array(Vec<(Php, Php)>),
    Object(String, Vec<(Php, Php)>),
}

impl Php {
    /// The entries of an array or the properties of an object, in stored order.
    pub fn entries(&self) -> &[(Php, Php)] {
        match self {
            Php::Array(e) | Php::Object(_, e) => e,
            _ => &[],
        }
    }

    pub fn get(&self, key: &str) -> Option<&Php> {
        self.entries().iter().find(|(k, _)| k.text() == key).map(|(_, v)| v)
    }

    /// The value as PHP would print it.
    pub fn text(&self) -> String {
        match self {
            Php::Null | Php::Bool(false) | Php::Array(_) | Php::Object(..) => String::new(),
            Php::Bool(true) => "1".into(),
            Php::Int(n) => n.to_string(),
            Php::Float(s) | Php::Str(s) => s.clone(),
        }
    }

    /// intval().
    pub fn int(&self) -> i64 {
        match self {
            Php::Bool(b) => i64::from(*b),
            Php::Int(n) => *n,
            Php::Float(s) | Php::Str(s) => super::csrf::php_intval(s),
            _ => 0,
        }
    }

    pub fn truthy(&self) -> bool {
        match self {
            Php::Null => false,
            Php::Bool(b) => *b,
            Php::Int(n) => *n != 0,
            Php::Float(s) => s.parse::<f64>().is_ok_and(|f| f != 0.0),
            Php::Str(s) => !s.is_empty() && s != "0",
            Php::Array(e) | Php::Object(_, e) => !e.is_empty(),
        }
    }
}

/// unserialize(); `None` for anything it cannot read, where PHP returned false.
pub fn parse(s: &str) -> Option<Php> {
    let mut p = Parser { s: s.as_bytes(), at: 0 };
    let v = p.value()?;
    (p.at == p.s.len()).then_some(v)
}

struct Parser<'a> {
    s: &'a [u8],
    at: usize,
}

impl Parser<'_> {
    fn eat(&mut self, b: u8) -> Option<()> {
        (self.s.get(self.at) == Some(&b)).then(|| self.at += 1)
    }

    fn until(&mut self, end: u8) -> Option<&str> {
        let start = self.at;
        let len = self.s[start..].iter().position(|&b| b == end)?;
        self.at += len + 1;
        std::str::from_utf8(&self.s[start..start + len]).ok()
    }

    fn string(&mut self) -> Option<String> {
        let len: usize = self.until(b':')?.parse().ok()?;
        self.eat(b'"')?;
        let bytes = self.s.get(self.at..self.at + len)?;
        self.at += len;
        self.eat(b'"')?;
        Some(String::from_utf8_lossy(bytes).into_owned())
    }

    fn entries(&mut self) -> Option<Vec<(Php, Php)>> {
        let n: usize = self.until(b':')?.parse().ok()?;
        self.eat(b'{')?;
        let mut out = Vec::with_capacity(n.min(1024));
        for _ in 0..n {
            let k = self.value()?;
            let v = self.value()?;
            out.push((k, v));
        }
        self.eat(b'}')?;
        Some(out)
    }

    fn value(&mut self) -> Option<Php> {
        let tag = *self.s.get(self.at)?;
        self.at += 1;
        if tag == b'N' {
            self.eat(b';')?;
            return Some(Php::Null);
        }
        self.eat(b':')?;
        Some(match tag {
            b'b' => Php::Bool(self.until(b';')? == "1"),
            b'i' => Php::Int(self.until(b';')?.parse().ok()?),
            b'd' => Php::Float(self.until(b';')?.to_string()),
            b's' => {
                let s = self.string()?;
                self.eat(b';')?;
                Php::Str(s)
            }
            b'a' => Php::Array(self.entries()?),
            b'O' => {
                let class = self.string()?;
                self.eat(b':')?;
                Php::Object(class, self.entries()?)
            }
            _ => return None,
        })
    }
}

/// serialize().
pub fn write(v: &Php) -> String {
    match v {
        Php::Null => "N;".into(),
        Php::Bool(b) => format!("b:{};", u8::from(*b)),
        Php::Int(n) => format!("i:{n};"),
        Php::Float(s) => format!("d:{s};"),
        Php::Str(s) => format!("s:{}:\"{s}\";", s.len()),
        Php::Array(e) => format!("a:{}:{{{}}}", e.len(), e.iter().map(|(k, v)| write(k) + &write(v)).collect::<String>()),
        Php::Object(class, e) => format!("O:{}:\"{class}\":{}:{{{}}}", class.len(), e.len(), e.iter().map(|(k, v)| write(k) + &write(v)).collect::<String>()),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn stored_pricing_and_documents_round_trip() {
        for s in [
            r#"a:2:{i:0;O:23:"inventory_pricing_class":2:{s:5:"price";d:284.92;s:9:"qty_break";i:0;}i:1;O:23:"inventory_pricing_class":2:{s:5:"price";d:29.709999999999997;s:9:"qty_break";d:2;}}"#,
            r#"a:4:{s:8:"overview";s:0:"";s:8:"features";N;s:12:"applications";s:5:"été";s:8:"specials";b:1;}"#,
            "a:0:{}",
        ] {
            assert_eq!(write(&parse(s).unwrap()), s);
        }
        let v = parse(r#"a:1:{i:0;O:23:"inventory_pricing_class":2:{s:5:"price";d:12.5;s:9:"qty_break";i:3;}}"#).unwrap();
        let first = &v.entries()[0].1;
        assert_eq!(first.get("price").map(Php::text).as_deref(), Some("12.5"));
        assert_eq!(first.get("qty_break").map(Php::int), Some(3));
    }

    #[test]
    fn broken_text_is_not_read() {
        assert_eq!(parse(""), None);
        assert_eq!(parse("s:5:\"abc\";"), None);
        assert_eq!(parse("a:1:{i:0;s:1:\"x\";"), None);
    }
}
