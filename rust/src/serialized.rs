//! PHP's `serialize()` format, read by the two importers only: `hxweb-cms import` reads WordPress options and post
//! meta with it, and `hx-import` turns the SCS columns that held it into JSON (docs/rust-migration.md). Objects read
//! as arrays of their properties.
//!
//! String lengths count bytes. The SCS tables were latin1 in MySQL, so a length can fall short by the accented
//! characters in the string: when the counted bytes do not end at the closing `";`, the string runs to the next `";`
//! that a value can follow.

use serde_json::Value as Json;

#[derive(Debug, Clone, PartialEq)]
pub enum Php {
    Null,
    Bool(bool),
    Int(i64),
    Float(f64),
    Str(String),
    Array(Vec<(Php, Php)>),
}

/// The deepest nesting read. The stored values nest three deep; deeper text is refused, which keeps the recursion
/// well inside a worker thread's stack.
pub const MAX_DEPTH: usize = 64;

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

    /// An array keyed 0, 1, 2... in order.
    fn is_list(&self) -> bool {
        self.items().iter().enumerate().all(|(i, (k, _))| *k == Php::Int(i as i64))
    }

    /// The array's values keyed 0, 1, 2... in their order.
    pub fn renumbered(self) -> Php {
        match self {
            Php::Array(items) => Php::Array(items.into_iter().enumerate().map(|(i, (_, v))| (Php::Int(i as i64), v)).collect()),
            other => other,
        }
    }

    /// The value as JSON: a list is a JSON array, any other array a JSON object. None for a float that is not finite,
    /// which JSON cannot hold.
    pub fn to_json(&self) -> Option<Json> {
        Some(match self {
            Php::Null => Json::Null,
            Php::Bool(b) => Json::Bool(*b),
            Php::Int(i) => Json::from(*i),
            Php::Float(f) => Json::Number(serde_json::Number::from_f64(*f)?),
            Php::Str(s) => Json::String(s.clone()),
            Php::Array(items) if self.is_list() => Json::Array(items.iter().map(|(_, v)| v.to_json()).collect::<Option<_>>()?),
            Php::Array(items) => Json::Object(items.iter().map(|(k, v)| Some((k.key_str()?, v.to_json()?))).collect::<Option<_>>()?),
        })
    }

    /// Whether `json` holds this value: the same type, the same integer, the same float to the bit, the same text, a
    /// list's values in the same order, a map's keys each with the same value.
    pub fn same(&self, json: &Json) -> bool {
        match (self, json) {
            (Php::Null, Json::Null) => true,
            (Php::Bool(a), Json::Bool(b)) => a == b,
            (Php::Int(a), Json::Number(b)) => !b.is_f64() && b.as_i64() == Some(*a),
            (Php::Float(a), Json::Number(b)) => b.is_f64() && b.as_f64().map(f64::to_bits) == Some(a.to_bits()),
            (Php::Str(a), Json::String(b)) => a == b,
            (Php::Array(items), Json::Array(list)) => self.is_list() && items.len() == list.len() && items.iter().zip(list).all(|((_, v), j)| v.same(j)),
            (Php::Array(items), Json::Object(map)) => {
                !self.is_list() && items.len() == map.len() && items.iter().all(|(k, v)| k.key_str().and_then(|k| map.get(&k)).is_some_and(|j| v.same(j)))
            }
            _ => false,
        }
    }
}

pub fn unserialize(s: &str) -> Result<Php, String> {
    let mut p = Parser { b: s.as_bytes(), i: 0, depth: 0 };
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
    depth: usize,
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

    /// A class name: exactly its counted bytes.
    fn name(&mut self) -> Result<String, String> {
        let n = self.len()?;
        self.expect(b'"')?;
        let bytes = self.i.checked_add(n).and_then(|end| self.b.get(self.i..end)).ok_or("string runs past the end")?;
        let s = String::from_utf8(bytes.to_vec()).map_err(|e| e.to_string())?;
        self.i += n;
        self.expect(b'"')?;
        Ok(s)
    }

    /// A string value and its `";`.
    fn text(&mut self) -> Result<String, String> {
        let n = self.len()?;
        self.expect(b'"')?;
        let start = self.i;
        let ends = |at: usize| self.b.get(at..at.saturating_add(2)) == Some(b"\";") && self.continues(at + 2);
        let exact = start.checked_add(n).ok_or("string runs past the end")?;
        let end = if ends(exact) { Some(exact) } else { (exact..self.b.len()).find(|&at| ends(at)) };
        let end = end.ok_or("string runs past the end")?;
        let s = std::str::from_utf8(&self.b[start..end]).map_err(|e| e.to_string())?.to_string();
        self.i = end + 2;
        Ok(s)
    }

    /// After a string's `";` comes the end, the end of an array, or the next key or value.
    fn continues(&self, at: usize) -> bool {
        match self.b.get(at) {
            None | Some(b'}') => true,
            Some(b'N') => self.b.get(at + 1) == Some(&b';'),
            Some(b'i' | b'd' | b's' | b'a' | b'b' | b'O') => self.b.get(at + 1) == Some(&b':'),
            _ => false,
        }
    }

    fn entries(&mut self) -> Result<Vec<(Php, Php)>, String> {
        let n = self.len()?;
        self.expect(b'{')?;
        self.depth += 1;
        if self.depth > MAX_DEPTH {
            return self.err("nested too deep");
        }
        let mut items = Vec::with_capacity(n.min(1024));
        for _ in 0..n {
            let k = self.value()?;
            let v = self.value()?;
            items.push((k, v));
        }
        self.expect(b'}')?;
        self.depth -= 1;
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
            b's' => Php::Str(self.text()?),
            b'a' => Php::Array(self.entries()?),
            b'O' => {
                self.name()?;
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

    #[test]
    fn a_length_counted_in_latin1_still_reads() {
        let v = unserialize(r#"a:2:{s:4:"name";s:6:"Dégagé";s:4:"next";i:3;}"#).unwrap();
        assert_eq!(v.get("name").and_then(Php::as_str), Some("Dégagé"));
        assert_eq!(v.get("next").and_then(Php::as_int), Some(3));
        assert!(unserialize(r#"a:1:{i:0;s:3:"abc";x}"#).is_err());
    }

    #[test]
    fn values_become_json_that_holds_the_same_data() {
        let s = r#"a:2:{i:0;O:23:"inventory_pricing_class":2:{s:5:"price";d:29.709999999999997;s:9:"qty_break";i:0;}i:1;O:23:"inventory_pricing_class":2:{s:5:"price";d:284.92000000000002;s:9:"qty_break";d:2;}}"#;
        let v = unserialize(s).unwrap();
        let json = v.to_json().unwrap();
        assert_eq!(json.to_string(), r#"[{"price":29.709999999999997,"qty_break":0},{"price":284.92,"qty_break":2.0}]"#);
        assert!(v.same(&serde_json::from_str(&json.to_string()).unwrap()));
        let map = unserialize(r#"a:3:{s:7:"content";s:9:"guest_div";i:5;N;s:1:"x";b:0;}"#).unwrap();
        assert_eq!(map.to_json().unwrap().to_string(), r#"{"5":null,"content":"guest_div","x":false}"#);
        assert_eq!(unserialize("a:0:{}").unwrap().to_json().unwrap().to_string(), "[]");
        let gappy = unserialize(r#"a:2:{i:0;s:1:"a";i:3;s:1:"b";}"#).unwrap();
        assert_eq!(gappy.to_json().unwrap().to_string(), r#"{"0":"a","3":"b"}"#);
        assert_eq!(gappy.renumbered().to_json().unwrap().to_string(), r#"["a","b"]"#);
        assert_eq!(Php::Float(f64::INFINITY).to_json(), None);
    }

    #[test]
    fn json_floats_read_back_to_the_bit() {
        for i in 1..100_000 {
            let f = f64::from(i) * 1.1 / 3.0;
            let back: f64 = serde_json::from_str(&Json::from(f).to_string()).unwrap();
            assert_eq!(back.to_bits(), f.to_bits(), "{f}");
        }
    }

    #[test]
    fn same_tells_types_order_and_keys_apart() {
        let json = |s: &str| serde_json::from_str::<Json>(s).unwrap();
        assert!(!Php::Int(2).same(&json("2.0")) && !Php::Float(2.0).same(&json("2")));
        assert!(!Php::Str("1".into()).same(&json("1")) && !Php::Bool(true).same(&json("1")));
        let list = unserialize(r#"a:2:{i:0;s:1:"a";i:1;s:1:"b";}"#).unwrap();
        assert!(list.same(&json(r#"["a","b"]"#)) && !list.same(&json(r#"["b","a"]"#)) && !list.same(&json(r#"{"0":"a","1":"b"}"#)));
        let map = unserialize(r#"a:2:{s:1:"k";i:1;s:1:"j";i:2;}"#).unwrap();
        assert!(map.same(&json(r#"{"j":2,"k":1}"#)) && !map.same(&json(r#"{"j":2,"k":1,"l":3}"#)) && !map.same(&json(r#"{"j":2,"k":"1"}"#)));
        let twice = unserialize(r#"a:2:{s:1:"k";i:1;s:1:"k";i:2;}"#).unwrap();
        assert!(!twice.same(&twice.to_json().unwrap()), "a repeated key does not survive");
    }
}
