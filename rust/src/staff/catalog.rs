//! What the staff pages read of classes/subcategory.php: a subcategory's parameters and download labels, and the
//! pick list of every subcategory under its category.

use sqlx::SqlitePool;

use super::{
    html::{php_float, text, OptGroup},
    phpser::{self, Php},
};

/// subcategory_parameter_class, keyed by its position in the subcategory's list.
#[derive(Debug, Clone)]
pub struct Parameter {
    pub key: usize,
    pub name: String,
    kind: String,
    pub numeric: bool,
    decimal: usize,
}

impl Parameter {
    /// output(): what a numeric parameter keeps of the posted text.
    pub fn output(&self, posted: &str) -> String {
        let n = php_floatval(posted);
        if self.kind == "Numeric" {
            return php_float(n);
        }
        let d = rust_decimal::Decimal::try_from(n).unwrap_or_default().round_dp_with_strategy(self.decimal as u32, rust_decimal::RoundingStrategy::MidpointAwayFromZero);
        format!("{d:.*}", self.decimal)
    }
}

/// floatval(): the leading number of the text, 0 when there is none.
pub fn php_floatval(s: &str) -> f64 {
    let s = s.trim_start();
    let b = s.as_bytes();
    let mut end = usize::from(matches!(b.first(), Some(b'+' | b'-')));
    let digits = |from: usize| b[from..].iter().take_while(|c| c.is_ascii_digit()).count();
    end += digits(end);
    if b.get(end) == Some(&b'.') {
        end += 1 + digits(end + 1);
    }
    if matches!(b.get(end), Some(b'e' | b'E')) {
        let sign = usize::from(matches!(b.get(end + 1), Some(b'+' | b'-')));
        let exp = digits(end + 1 + sign);
        if exp > 0 {
            end += 1 + sign + exp;
        }
    }
    s[..end].parse().unwrap_or(0.0)
}

pub struct Subcategory {
    pub id: i64,
    pub category_id: i64,
    pub name: String,
    pub category_name: String,
    pub parameters: Vec<Parameter>,
    /// The download labels; `None` when the column is empty, as the PHP's empty array.
    pub download: Option<Vec<String>>,
}

fn parameters(stored: &str) -> Vec<Parameter> {
    let Some(list) = phpser::parse(stored) else { return Vec::new() };
    list.entries()
        .iter()
        .map(|(key, p)| {
            let kind = p.get("type").map(Php::text).unwrap_or_default();
            Parameter {
                key: usize::try_from(key.int()).unwrap_or(0),
                name: p.get("name").map(Php::text).unwrap_or_default(),
                numeric: p.get("numeric").is_some_and(Php::truthy),
                decimal: usize::try_from(p.get("decimal").map_or(0, Php::int)).unwrap_or(0),
                kind,
            }
        })
        .collect()
}

pub async fn read(pool: &SqlitePool, id: i64) -> sqlx::Result<Option<Subcategory>> {
    let row = sqlx::query!(
        r#"SELECT s.id AS "id!: i64", s.category_id, s.name, s.parameters, s.download, c.name AS "category_name?"
           FROM subcategory s LEFT JOIN category c ON c.id = s.category_id WHERE s.id = ?1"#,
        id
    )
    .fetch_optional(pool)
    .await?;
    Ok(row.map(|r| Subcategory {
        id: r.id,
        category_id: r.category_id.unwrap_or(0),
        name: r.name.unwrap_or_default(),
        category_name: r.category_name.unwrap_or_default(),
        parameters: r.parameters.as_deref().map(parameters).unwrap_or_default(),
        download: r.download.filter(|d| !d.is_empty()).and_then(|d| phpser::parse(&d)).map(|d| d.entries().iter().map(|(_, v)| v.text()).collect()),
    }))
}

/// subcategory->select(): every subcategory under its category, its status after the name unless Active.
pub async fn groups(pool: &SqlitePool) -> sqlx::Result<Vec<OptGroup>> {
    let rows = sqlx::query!(
        r#"SELECT s.id AS "id!: i64", s.name AS "name?", s.status AS "status?", COALESCE(c.name, '') AS "category!: String"
           FROM subcategory s LEFT JOIN category c ON c.id = s.category_id
           ORDER BY c.name COLLATE NOCASE, s.name COLLATE NOCASE, s.id"#
    )
    .fetch_all(pool)
    .await?;
    Ok(grouped(rows.into_iter().map(|r| (r.id, r.name, r.status, r.category))))
}

/// subcategory->select() with `output`: the subcategories of one output kind ("smart" for the configurator's).
pub async fn groups_with_output(pool: &SqlitePool, output: &str) -> sqlx::Result<Vec<OptGroup>> {
    let rows = sqlx::query!(
        r#"SELECT s.id AS "id!: i64", s.name AS "name?", s.status AS "status?", COALESCE(c.name, '') AS "category!: String"
           FROM subcategory s LEFT JOIN category c ON c.id = s.category_id WHERE s.output = ?1
           ORDER BY c.name COLLATE NOCASE, s.name COLLATE NOCASE, s.id"#,
        output
    )
    .fetch_all(pool)
    .await?;
    Ok(grouped(rows.into_iter().map(|r| (r.id, r.name, r.status, r.category))))
}

fn grouped(rows: impl Iterator<Item = (i64, Option<String>, Option<String>, String)>) -> Vec<OptGroup> {
    let mut groups: Vec<OptGroup> = Vec::new();
    for (id, name, status, category) in rows {
        let status = status.unwrap_or_default();
        let mut label = name.unwrap_or_default();
        if status != "Active" {
            label.push_str(&format!(" ({status})"));
        }
        let option = (id.to_string(), text(&label));
        match groups.last_mut() {
            Some((g, options)) if *g == category => options.push(option),
            _ => groups.push((category, vec![option])),
        }
    }
    groups
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parameters_read_from_the_serialized_list() {
        let stored = r#"a:2:{i:0;O:27:"subcategory_parameter_class":5:{s:2:"id";i:0;s:4:"name";s:8:"Material";s:4:"type";s:12:"Alphanumeric";s:7:"numeric";b:0;s:7:"decimal";i:0;}i:3;O:27:"subcategory_parameter_class":5:{s:2:"id";i:3;s:4:"name";s:1:"A";s:4:"type";s:9:"Decimal:3";s:7:"numeric";b:1;s:7:"decimal";s:1:"3";}}"#;
        let p = parameters(stored);
        assert_eq!(p.len(), 2);
        assert_eq!((p[1].key, p[1].name.as_str(), p[1].numeric), (3, "A", true));
        assert_eq!(p[1].output(" 1.5mm"), "1.500");
        assert!(!p[0].numeric);
    }

    #[test]
    fn floatval_reads_the_leading_number() {
        assert_eq!(php_floatval(" 12.5abc"), 12.5);
        assert_eq!(php_floatval("1e3"), 1000.0);
        assert_eq!(php_floatval("abc"), 0.0);
        assert_eq!(php_floatval("-.5"), -0.5);
    }
}
