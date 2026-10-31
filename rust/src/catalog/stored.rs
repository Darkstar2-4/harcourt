//! The columns hx-import turned from PHP's serialize() into JSON (docs/rust-migration.md) and the site reads:
//! subcategory.parameters, .options and .download, inventory.pricing and campaign.divs. A value reads as the PHP
//! read it: a number prints as PHP printed it, and a string's number is its leading number.

use serde_json::Value;
use sqlx::SqlitePool;

/// The column as JSON; null when it is empty or not JSON.
pub fn parse(s: Option<&str>) -> Value {
    s.and_then(|s| serde_json::from_str(s).ok()).unwrap_or(Value::Null)
}

/// A list's values keyed 0, 1, 2..., or an object's entries.
pub fn entries(v: &Value) -> Vec<(String, &Value)> {
    match v {
        Value::Array(list) => list.iter().enumerate().map(|(i, v)| (i.to_string(), v)).collect(),
        Value::Object(map) => map.iter().map(|(k, v)| (k.clone(), v)).collect(),
        _ => Vec::new(),
    }
}

/// True as "1"; false, null, lists and objects as "".
pub fn text(v: &Value) -> String {
    match v {
        Value::String(s) => s.clone(),
        Value::Bool(true) => "1".into(),
        Value::Number(n) => n.as_i64().map_or_else(|| crate::staff::map::float_text(n.as_f64().unwrap_or(0.0)), |i| i.to_string()),
        _ => String::new(),
    }
}

pub fn float(v: &Value) -> f64 {
    match v {
        Value::Number(n) => n.as_f64().unwrap_or(0.0),
        Value::Bool(b) => f64::from(u8::from(*b)),
        Value::String(s) => crate::staff::catalog::php_floatval(s),
        _ => 0.0,
    }
}

pub fn int(v: &Value) -> i64 {
    match v {
        Value::Number(n) => n.as_i64().unwrap_or_else(|| n.as_f64().unwrap_or(0.0) as i64),
        other => float(other) as i64,
    }
}

/// Whether these columns still hold PHP's format: a file an older hx-import wrote, which `hx-import --convert`
/// brings up to date.
pub async fn unconverted(pool: &SqlitePool) -> sqlx::Result<bool> {
    sqlx::query_scalar!(
        r#"SELECT EXISTS (SELECT 1 FROM subcategory WHERE parameters GLOB 'a:*' OR options GLOB 'a:*' OR download GLOB 'a:*')
               OR EXISTS (SELECT 1 FROM inventory WHERE pricing GLOB 'a:*')
               OR EXISTS (SELECT 1 FROM campaign WHERE divs GLOB 'a:*') AS "unconverted!: bool""#
    )
    .fetch_one(pool)
    .await
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn values_read_with_php_types() {
        let v = parse(Some(r#"{"a":"12.5mm","b":3,"c":2.0,"d":0.25,"e":true,"f":false,"g":null,"h":[1]}"#));
        let [a, b, c, d, e, f, g, h] = ["a", "b", "c", "d", "e", "f", "g", "h"].map(|k| &v[k]);
        assert_eq!([a, b, c, d, e, f, g, h].map(text), ["12.5mm", "3", "2", "0.25", "1", "", "", ""]);
        assert_eq!([a, b, c, d, e, f, g, h].map(int), [12, 3, 2, 0, 1, 0, 0, 0]);
        assert_eq!([a, b, c, d, e, f, g, h].map(float), [12.5, 3.0, 2.0, 0.25, 1.0, 0.0, 0.0, 0.0]);
        assert_eq!(entries(&parse(Some(r#"["x","y"]"#))).into_iter().map(|(k, v)| (k, text(v))).collect::<Vec<_>>(), [("0".to_string(), "x".to_string()), ("1".into(), "y".into())]);
        assert_eq!(parse(Some("a:0:{}")), Value::Null);
        assert_eq!(parse(None), Value::Null);
        assert!(entries(&parse(Some(""))).is_empty());
    }
}
