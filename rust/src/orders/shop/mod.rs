//! The cart (cart.php), checkout (checkout.php) and the order viewer (orderhd.php).
//!
//! PHP kept the cart in `$_SESSION['cart']` with the price worked out when an item was added. Here the cart is a
//! table keyed by the browser session, and every price comes from `Pricing` when the cart is shown or ordered.

use std::{collections::HashMap, sync::Arc};

use axum::{
    routing::get,
    Router,
};
use rust_decimal::{Decimal, RoundingStrategy};
use sqlx::SqlitePool;

use super::{pricing::Price, Orders};
use crate::{identity::User, token::TokenHash, visitor::Visitor};

pub mod cart;
mod checkout;
mod viewer;

pub fn routes() -> Router<Arc<Orders>> {
    Router::new()
        .route("/cart.php", get(cart::page).post(cart::page))
        .route("/checkout.php", get(checkout::page).post(checkout::page))
        .route("/orderhd.php", get(viewer::page).post(viewer::page))
}

/// A signed-in account allowed to shop (`user.ecommerce`), in this browser session.
pub struct Shopper {
    pub user: User,
    pub email: Option<String>,
    pub session: TokenHash,
    pub currency: Currency,
    pub language: String,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Currency {
    pub code: String,
    pub plural: String,
}

impl Currency {
    pub fn usd() -> Self {
        Currency { code: "USD".into(), plural: "US dollars".into() }
    }

    /// An active currency, or US dollars for a code the `currency` table does not offer, as `currency_code()` does.
    pub async fn load(pool: &SqlitePool, code: &str) -> sqlx::Result<Self> {
        let row = sqlx::query!(
            r#"SELECT code AS "code!: String", coalesce(plural, '') AS "plural!: String" FROM currency WHERE code = ?1 AND active = 1"#,
            code
        )
        .fetch_optional(pool)
        .await?;
        Ok(row.map_or_else(Currency::usd, |r| Currency { code: r.code, plural: r.plural }))
    }
}

pub async fn shopper(pool: &SqlitePool, visitor: Visitor) -> sqlx::Result<Option<Shopper>> {
    let Visitor::SignedIn { user, email, session } = visitor else { return Ok(None) };
    let row = sqlx::query!(
        r#"SELECT ecommerce AS "ecommerce!: i64", currency_code AS "currency!: String", language_code AS "language!: String" FROM "user" WHERE id = ?1"#,
        user.id.0
    )
    .fetch_optional(pool)
    .await?;
    let Some(row) = row.filter(|r| r.ecommerce != 0) else { return Ok(None) };
    let currency = Currency::load(pool, &row.currency).await?;
    Ok(Some(Shopper { user, email, session, currency, language: row.language }))
}

/// How `currency_price()` writes an amount: `$` only when both the order and the viewer deal in US dollars, the
/// code after it when either does not.
pub struct MoneyFormat<'a> {
    pub order: &'a Currency,
    pub viewer: &'a str,
}

impl MoneyFormat<'_> {
    pub fn price(&self, amount: Decimal) -> String {
        let usd = self.order.code == "USD" && self.viewer == "USD";
        let mut out = if usd { "$".to_string() } else { String::new() };
        out.push_str(&number_format(amount, 2));
        if !usd {
            out.push(' ');
            out.push_str(&self.order.code);
        }
        out
    }

    /// `currency_name(FALSE)`: says which currency prices are in, unless that is the default.
    pub fn name(&self) -> String {
        if self.order.code != "USD" || self.viewer != "USD" {
            format!("Priced in <b>{}</b>", super::html::esc(&self.order.plural))
        } else {
            String::new()
        }
    }
}

/// PHP's `number_format($n, $dp)`: rounded half away from zero, thousands separated by commas.
pub fn number_format(amount: Decimal, dp: u32) -> String {
    let rounded = amount.round_dp_with_strategy(dp, RoundingStrategy::MidpointAwayFromZero);
    let text = format!("{:.*}", dp as usize, rounded.abs());
    let (int, frac) = text.split_once('.').map_or((text.as_str(), ""), |(i, f)| (i, f));
    let mut grouped = String::new();
    for (i, c) in int.chars().enumerate() {
        if i > 0 && (int.len() - i) % 3 == 0 {
            grouped.push(',');
        }
        grouped.push(c);
    }
    let sign = if rounded.is_sign_negative() && !rounded.is_zero() { "-" } else { "" };
    if frac.is_empty() { format!("{sign}{grouped}") } else { format!("{sign}{grouped}.{frac}") }
}

/// A line's price for display and totals. A quoted price of zero counts as on request, as `if ($item->price)` did.
pub fn quoted(price: &Price) -> Option<(Decimal, Decimal)> {
    match price {
        Price::Quoted { unit, extended } if !unit.is_zero() => Some((*unit, *extended)),
        _ => None,
    }
}

/// The interface words from `language.menu` for this language; a key with no word shows as itself.
pub struct Labels(HashMap<String, String>);

impl Labels {
    pub async fn load(pool: &SqlitePool, language: &str) -> sqlx::Result<Self> {
        let menu = sqlx::query_scalar!(r#"SELECT menu AS "menu!: String" FROM language WHERE code = ?1 AND menu IS NOT NULL"#, language)
            .fetch_optional(pool)
            .await?;
        Ok(Labels(menu.and_then(|m| serde_json::from_str(&m).ok()).unwrap_or_default()))
    }

    pub fn get<'a>(&'a self, key: &'a str) -> &'a str {
        self.0.get(key).map_or(key, String::as_str)
    }
}

/// `fn_base64(array(...))`: the fields joined by tabs, base64 without padding. Used for cart field names.
pub fn fn_base64(parts: &[&str]) -> String {
    use base64::{engine::general_purpose::STANDARD_NO_PAD, Engine};
    STANDARD_NO_PAD.encode(parts.join("\t"))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn number_format_matches_php() {
        let d = |s: &str| s.parse::<Decimal>().unwrap();
        assert_eq!(number_format(d("1234567.005"), 2), "1,234,567.01");
        assert_eq!(number_format(d("0.125"), 2), "0.13");
        assert_eq!(number_format(d("999.994"), 2), "999.99");
        assert_eq!(number_format(d("-1500"), 2), "-1,500.00");
        assert_eq!(number_format(d("12"), 0), "12");
    }

    #[test]
    fn prices_show_dollars_or_the_code() {
        let usd = Currency::usd();
        let cad = Currency { code: "CAD".into(), plural: "Canadian dollars".into() };
        assert_eq!(MoneyFormat { order: &usd, viewer: "USD" }.price(Decimal::new(123450, 2)), "$1,234.50");
        assert_eq!(MoneyFormat { order: &cad, viewer: "CAD" }.price(Decimal::new(5, 0)), "5.00 CAD");
        assert_eq!(MoneyFormat { order: &usd, viewer: "USD" }.name(), "");
        assert_eq!(MoneyFormat { order: &cad, viewer: "CAD" }.name(), "Priced in <b>Canadian dollars</b>");
    }

    #[test]
    fn field_names_are_unpadded_base64() {
        assert_eq!(fn_base64(&["quantity", "AB-1"]), "cXVhbnRpdHkJQUItMQ");
    }
}
