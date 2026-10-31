//! A query string as PHP read it into `$_GET`: parse_str()'s names and `a[b]` arrays within PHP's input limits,
//! print_r() for the catalog's debug view, and the number in a request value.

/// PHP's `max_input_vars` on prod (the docroot's .htaccess): the variables after the first 3000 are not read.
pub const MAX_INPUT_VARS: usize = 3000;

/// PHP's `max_input_nesting_level`: a variable with more `[]` levels than this is dropped, with any value already
/// set under its name.
pub const MAX_INPUT_NESTING: usize = 64;

/// A `$_GET` value: a string, or an array whose keys keep the place they were first given.
#[derive(Debug, Clone, PartialEq)]
pub enum Value {
    Str(String),
    Map(Vec<(String, Value)>),
}

/// (int) of a request value: its leading number ("12abc" is 12, "abc" is 0), truncated.
pub fn int(s: &str) -> i64 {
    crate::staff::catalog::php_floatval(s) as i64
}

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
pub fn parse_str(query: &str) -> Vec<(String, Value)> {
    let decode = |s: &str| percent_encoding::percent_decode_str(&s.replace('+', " ")).decode_utf8_lossy().into_owned();
    let mut root: Vec<(String, Value)> = Vec::new();
    for pair in query.split('&').filter(|p| !p.is_empty()).take(MAX_INPUT_VARS) {
        let (k, v) = pair.split_once('=').unwrap_or((pair, ""));
        match variable(&decode(k)) {
            Variable::Set(name, indexes) => {
                let path: Vec<Option<String>> = std::iter::once(Some(name)).chain(indexes).collect();
                insert(&mut root, &path, decode(v));
            }
            Variable::TooDeep(name) => root.retain(|(k, _)| *k != name),
            Variable::Skip => {}
        }
    }
    root
}

/// An array key PHP holds as an integer: the decimal form of one.
fn int_key(k: &str) -> Option<i64> {
    k.parse::<i64>().ok().filter(|n| n.to_string() == k)
}

fn insert(map: &mut Vec<(String, Value)>, path: &[Option<String>], value: String) {
    let Some((first, rest)) = path.split_first() else { return };
    let key = match first {
        Some(s) => s.clone(),
        None => {
            // `[]` takes the next integer key, and PHP refuses to append after the largest one.
            let last = map.iter().filter_map(|(k, _)| int_key(k)).max();
            let Some(next) = last.map_or(Some(0), |i| i.checked_add(1)) else { return };
            next.max(0).to_string()
        }
    };
    let at = match map.iter().position(|(k, _)| *k == key) {
        Some(i) => i,
        None => {
            map.push((key, Value::Str(String::new())));
            map.len() - 1
        }
    };
    if rest.is_empty() {
        map[at].1 = Value::Str(value);
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
        if let Value::Str(s) = v {
            out.push_str(s);
        }
        return;
    };
    out.push_str("Array\n");
    out.push_str(&" ".repeat(pad));
    out.push_str("(\n");
    for (k, child) in entries {
        out.push_str(&format!("{}[{k}] => ", " ".repeat(pad + 4)));
        print_r_into(out, child, pad + 8);
        out.push('\n');
    }
    out.push_str(&" ".repeat(pad));
    out.push_str(")\n");
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn print_r_of_a_query_as_php_prints_get() {
        let get = |q: &str| print_r(&Value::Map(parse_str(q)));
        assert_eq!(get("debug=1&scscpq_wp=1"), "Array\n(\n    [debug] => 1\n    [scscpq_wp] => 1\n)\n");
        assert_eq!(get(""), "Array\n(\n)\n");
        assert_eq!(
            get("a.b=x+y&p[]=1&p[]=2&a.b=z&q[k]=v"),
            "Array\n(\n    [a_b] => z\n    [p] => Array\n        (\n            [0] => 1\n            [1] => 2\n        )\n\n    [q] => Array\n        (\n            [k] => v\n        )\n\n)\n"
        );
        assert_eq!(parse_str("x[=1"), [("x_".to_string(), Value::Str("1".into()))], "an unclosed bracket becomes _");
        assert_eq!(parse_str("=1&%20s=2").len(), 1);
        assert_eq!(get("p[5]=a&p[]=b&p[-0]=c&p[]=d"), "Array\n(\n    [p] => Array\n        (\n            [5] => a\n            [6] => b\n            [-0] => c\n            [7] => d\n        )\n\n)\n");
    }

    #[test]
    fn request_numbers_read_as_php_casts_them() {
        assert_eq!(int("12.5mm"), 12);
        assert_eq!(int("abc"), 0);
        assert_eq!(int(" -3"), -3);
    }
}
