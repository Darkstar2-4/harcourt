//! Interface text per language: the `language.menu` JSON over the defaults in classes/language.php, as
//! menu_class::language() reads it. A language with no active row shows each key itself, as the PHP did.

use std::collections::HashMap;

use sqlx::SqlitePool;

use super::viewer::Lang;

const DEFAULTS: &[(&str, &str)] = &[
    ("menu_home", "Home"),
    ("menu_products", "Products"),
    ("menu_quality", "Quality"),
    ("menu_contact_us", "Contact"),
    ("menu_dashboard", "Dashboard"),
    ("menu_solutions", "Solutions"),
    ("menu_myaccount", "My Account"),
    ("menu_newuser", "Request Access"),
    ("menu_history", "History & Values"),
    ("menu_privacy", "Privacy"),
    ("menu_legal", "Legal Notice"),
    ("menu_company", "Company"),
    ("menu_search", "Search"),
    ("menu_search_placeholder", "Product Search"),
    ("menu_cart", "Items"),
    ("menu_cart_title", "View Items"),
    ("menu_portal", "Portal"),
    ("catalog_overview", "Overview"),
    ("catalog_products", "Product Details"),
    ("catalog_sku", "Part#"),
    ("catalog_cad", "Configure"),
    ("catalog_search", "Search"),
    ("catalog_download", "Download"),
    ("catalog_competitors", "Competitors"),
    ("catalog_tabs", "Tabs"),
    ("catalog_specials", "Specials"),
    ("catalog_customs", "Customs"),
    ("catalog_profile", "Profile"),
    ("cart_cart", "Shopping Cart"),
    ("cart_add", "Add to Cart"),
    ("cart_clear", "Clear Cart"),
    ("cart_update", "Update Cart"),
    ("cart_checkout", "Check Out"),
];

/// The catalog tabs a visitor may reorder, in their default order (language_constant_class product_sortable).
pub const PRODUCT_SORTABLE: &[&str] = &[
    "catalog_overview",
    "catalog_products",
    "catalog_sku",
    "catalog_specials",
    "catalog_customs",
    "catalog_download",
    "catalog_cad",
    "cart_cart",
    "catalog_competitors",
];

#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Strings(Option<HashMap<String, String>>);

impl Strings {
    /// The text for `field`, or `field` itself when the language or the key is unknown.
    pub fn get<'a>(&'a self, field: &'a str) -> &'a str {
        self.0.as_ref().and_then(|m| m.get(field)).map_or(field, String::as_str)
    }

    /// From an active language row's `menu` column; None for a language with no active row.
    pub fn from_menu(menu: Option<Option<&str>>) -> Strings {
        let Some(menu) = menu else { return Strings(None) };
        let mut map: HashMap<String, String> = menu
            .and_then(|m| serde_json::from_str::<HashMap<String, serde_json::Value>>(m).ok())
            .unwrap_or_default()
            .into_iter()
            .map(|(k, v)| {
                let text = match v {
                    serde_json::Value::String(s) => s,
                    other => other.to_string(),
                };
                (k, text)
            })
            .collect();
        for (k, v) in DEFAULTS {
            map.entry((*k).to_string()).or_insert_with(|| (*v).to_string());
        }
        Strings(Some(map))
    }
}

pub async fn load(pool: &SqlitePool, lang: Lang) -> sqlx::Result<Strings> {
    let code = lang.code();
    let row = sqlx::query_scalar!(r#"SELECT menu FROM language WHERE code = ?1 AND active = 1 ORDER BY priority, name LIMIT 1"#, code)
        .fetch_optional(pool)
        .await?;
    Ok(Strings::from_menu(row.as_ref().map(|m| m.as_deref())))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn menu_text_overrides_defaults_and_unknown_keys_show_themselves() {
        let fr = Strings::from_menu(Some(Some(r#"{"catalog_products":"Détails du produit"}"#)));
        assert_eq!(fr.get("catalog_products"), "Détails du produit");
        assert_eq!(fr.get("catalog_cad"), "Configure");
        assert_eq!(fr.get("no_such_key"), "no_such_key");
        assert_eq!(Strings::from_menu(Some(None)).get("menu_cart"), "Items");
        assert_eq!(Strings::from_menu(None).get("menu_cart"), "menu_cart");
    }
}
