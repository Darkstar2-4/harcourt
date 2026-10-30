//! PHP `serialize()` values, as WordPress stores options and post meta. String lengths are in bytes. Objects are
//! read as arrays of their properties.

#[derive(Debug, Clone, PartialEq)]
pub enum Php {
    Null,
    Bool(bool),
    Int(i64),
    Float(f64),
    Str(String),
    Array(Vec<(Php, Php)>),
}

impl Php {
    /// The value under a string or integer key.
    pub fn get(&self, key: &str) -> Option<&Php> {
        let Php::Array(items) = self else { return None };
        items.iter().find(|(k, _)| k.key_str().as_deref() == Some(key)).map(|(_, v)| v)
    }

    pub fn as_str(&self) -> Option<&str> {
        match self {
            Php::Str(s) => Some(s),
            _ => None,
        }
    }

    pub fn as_int(&self) -> Option<i64> {
        match self {
            Php::Int(i) => Some(*i),
            Php::Str(s) => s.parse().ok(),
            Php::Float(f) => Some(*f as i64),
            _ => None,
        }
    }

    pub fn items(&self) -> &[(Php, Php)] {
        match self {
            Php::Array(items) => items,
            _ => &[],
        }
    }

    fn key_str(&self) -> Option<String> {
        match self {
            Php::Str(s) => Some(s.clone()),
            Php::Int(i) => Some(i.to_string()),
            _ => None,
        }
    }
}

pub fn unserialize(s: &str) -> Result<Php, String> {
    let mut p = Parser { b: s.as_bytes(), i: 0 };
    let v = p.value()?;
    if p.i != p.b.len() {
        return Err(format!("trailing bytes at {}", p.i));
    }
    Ok(v)
}

/// True when `s` looks like serialized data (WordPress's is_serialized(), roughly).
pub fn is_serialized(s: &str) -> bool {
    let s = s.trim().as_bytes();
    s == b"N;" || (s.len() >= 4 && matches!(&s[..2], b"a:" | b"s:" | b"i:" | b"b:" | b"d:" | b"O:") && matches!(s[s.len() - 1], b';' | b'}'))
}

struct Parser<'a> {
    b: &'a [u8],
    i: usize,
}

impl Parser<'_> {
    fn err<T>(&self, what: &str) -> Result<T, String> {
        Err(format!("{what} at byte {}", self.i))
    }

    fn expect(&mut self, c: u8) -> Result<(), String> {
        if self.b.get(self.i) == Some(&c) {
            self.i += 1;
            Ok(())
        } else {
            self.err(&format!("expected {:?}", c as char))
        }
    }

    fn until(&mut self, c: u8) -> Result<&str, String> {
        let start = self.i;
        while self.b.get(self.i).is_some_and(|x| *x != c) {
            self.i += 1;
        }
        let s = std::str::from_utf8(&self.b[start..self.i]).map_err(|e| e.to_string())?;
        self.expect(c)?;
        Ok(s)
    }

    fn len(&mut self) -> Result<usize, String> {
        self.until(b':')?.parse().map_err(|_| format!("bad length at byte {}", self.i))
    }

    fn string(&mut self) -> Result<String, String> {
        let n = self.len()?;
        self.expect(b'"')?;
        let bytes = self.b.get(self.i..self.i + n).ok_or("string runs past the end")?;
        let s = String::from_utf8(bytes.to_vec()).map_err(|e| e.to_string())?;
        self.i += n;
        self.expect(b'"')?;
        Ok(s)
    }

    fn entries(&mut self) -> Result<Vec<(Php, Php)>, String> {
        let n = self.len()?;
        self.expect(b'{')?;
        let mut items = Vec::with_capacity(n);
        for _ in 0..n {
            let k = self.value()?;
            let v = self.value()?;
            items.push((k, v));
        }
        self.expect(b'}')?;
        Ok(items)
    }

    fn value(&mut self) -> Result<Php, String> {
        let t = *self.b.get(self.i).ok_or("unexpected end")?;
        self.i += 1;
        if t == b'N' {
            self.expect(b';')?;
            return Ok(Php::Null);
        }
        self.expect(b':')?;
        Ok(match t {
            b'b' => Php::Bool(self.until(b';')? == "1"),
            b'i' => Php::Int(self.until(b';')?.parse().map_err(|_| "bad int")?),
            b'd' => Php::Float(self.until(b';')?.parse().map_err(|_| "bad float")?),
            b's' => {
                let s = self.string()?;
                self.expect(b';')?;
                Php::Str(s)
            }
            b'a' => Php::Array(self.entries()?),
            b'O' => {
                self.string()?;
                self.expect(b':')?;
                Php::Array(self.entries()?)
            }
            _ => return self.err("unknown type"),
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reads_attachment_metadata() {
        let v = unserialize(r#"a:4:{s:5:"width";i:1398;s:6:"height";i:614;s:4:"file";s:18:"2019/10/cta-bg.png";s:5:"sizes";a:1:{s:6:"medium";a:3:{s:4:"file";s:18:"cta-bg-683x300.png";s:5:"width";i:683;s:6:"height";i:300;}}}"#).unwrap();
        assert_eq!(v.get("width").and_then(Php::as_int), Some(1398));
        assert_eq!(v.get("file").and_then(Php::as_str), Some("2019/10/cta-bg.png"));
        let medium = v.get("sizes").and_then(|s| s.get("medium")).unwrap();
        assert_eq!(medium.get("width").and_then(Php::as_int), Some(683));
    }

    #[test]
    fn string_lengths_count_bytes() {
        assert_eq!(unserialize(r#"s:4:"é;}";"#).unwrap(), Php::Str("é;}".into()));
        assert_eq!(unserialize("a:2:{i:0;b:1;i:1;N;}").unwrap().items().len(), 2);
        assert!(unserialize(r#"s:9:"short";"#).is_err());
        assert!(is_serialized("a:0:{}") && !is_serialized("hello"));
    }
}
