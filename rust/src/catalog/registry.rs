//! Site settings from the `registry` table, as includes/classes/registry.php loads them: this site's rows (domain
//! '') win over the 'global' ones, a field with items is a list of (item, data) pairs in item order.

use std::collections::HashMap;

use sqlx::SqlitePool;

#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Registry {
    values: HashMap<(String, String), String>,
    items: HashMap<(String, String), Vec<(String, String)>>,
}

impl Registry {
    /// The setting, or "" when it is not set (PHP read an unset property as empty).
    pub fn get(&self, module: &str, field: &str) -> &str {
        self.values.get(&(module.to_string(), field.to_string())).map_or("", String::as_str)
    }

    pub fn items(&self, module: &str, field: &str) -> &[(String, String)] {
        self.items.get(&(module.to_string(), field.to_string())).map_or(&[], Vec::as_slice)
    }

    /// Rows as (domain, module, field, item, data), in any order.
    pub fn from_rows<'a>(rows: impl IntoIterator<Item = (&'a str, &'a str, &'a str, &'a str, Option<&'a str>)>) -> Registry {
        let mut rows: Vec<_> = rows.into_iter().filter(|(d, m, f, _, _)| (d.is_empty() || *d == "global") && !m.is_empty() && !f.is_empty()).collect();
        rows.sort_by_key(|(d, m, f, item, _)| (m.to_string(), f.to_string(), !d.is_empty(), sort_key(item)));
        let mut reg = Registry::default();
        for (_, module, field, item, data) in rows {
            let key = (module.to_string(), field.to_string());
            let data = data.unwrap_or("").to_string();
            if item.is_empty() {
                reg.values.entry(key).or_insert(data);
            } else {
                let list = reg.items.entry(key).or_default();
                if !list.iter().any(|(i, _)| i == item) {
                    list.push((item.to_string(), data));
                }
            }
        }
        reg
    }
}

/// registry.php orders numeric items zero-padded to four places, others as text.
fn sort_key(item: &str) -> String {
    match item.parse::<f64>() {
        Ok(n) if n.ceil() != 0.0 => {
            let padded = format!("0000{item}");
            padded[padded.len() - 4..].to_string()
        }
        _ => item.to_string(),
    }
}

pub async fn load(pool: &SqlitePool) -> sqlx::Result<Registry> {
    let rows = sqlx::query!(
        r#"SELECT domain AS "domain!", module AS "module!", field AS "field!", item AS "item!", data FROM registry WHERE domain IN ('', 'global')"#
    )
    .fetch_all(pool)
    .await?;
    Ok(Registry::from_rows(rows.iter().map(|r| (r.domain.as_str(), r.module.as_str(), r.field.as_str(), r.item.as_str(), r.data.as_deref()))))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn site_rows_win_over_global_and_items_keep_numeric_order() {
        let reg = Registry::from_rows([
            ("global", "local", "new_days", "", Some("30")),
            ("", "local", "new_days", "", Some("90")),
            ("", "cad", "catalog", "", Some("harcourt")),
            ("", "address", "profile", "10", Some("ten")),
            ("", "address", "profile", "9", Some("nine")),
            ("", "address", "profile", "email", Some("required")),
            ("other.example", "local", "track", "", Some("x")),
        ]);
        assert_eq!(reg.get("local", "new_days"), "90");
        assert_eq!(reg.get("cad", "catalog"), "harcourt");
        assert_eq!(reg.get("local", "track"), "");
        let items: Vec<_> = reg.items("address", "profile").iter().map(|(i, _)| i.as_str()).collect();
        assert_eq!(items, ["9", "10", "email"]);
    }
}
