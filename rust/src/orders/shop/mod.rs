//! The cart (cart.php) and checkout (checkout.php).
//!
//! PHP kept the cart in `$_SESSION['cart']` with the price worked out when an item was added. Here the cart is a
//! table keyed by the browser's visit (`CartKey`), and every price comes from `Pricing` when the cart is shown or
//! ordered.

use std::{collections::HashMap, sync::Arc};

use axum::{http::HeaderMap, routing::get, Router};
use rust_decimal::{Decimal, RoundingStrategy};
use sha2::{Digest, Sha256};
use sqlx::SqlitePool;

use super::{pricing::Price, Orders};
use crate::{
    accounts::cookies,
    identity::{User, UserId},
    visitor::Visitor,
};

pub mod cart;
pub mod checkout;

pub fn routes() -> Router<Arc<Orders>> {
    Router::new().route("/cart.php", get(cart::page).post(cart::page)).route("/checkout.php", get(checkout::page).post(checkout::page))
}

/// A signed-in account allowed to shop (`user.ecommerce`), in this browser session.
pub struct Shopper {
    pub user: User,
    pub email: Option<String>,
    pub cart: Option<CartKey>,
    pub currency: Currency,
    pub language: String,
}

/// Which cart is the shopper's. With a session it is the session's, and goes with it. An account signed in by
/// company address has no session; PHP kept its cart in the PHP session, so the PHP session id the browser carries
/// names it, together with the account. A browser with neither has no cart to keep.
pub struct CartKey([u8; 32]);

impl CartKey {
    fn of(visitor: &Visitor, user: UserId, headers: &HeaderMap) -> Option<Self> {
        if let Some(session) = &visitor.session {
            return session.as_slice().try_into().ok().map(CartKey);
        }
        let php = cookies::get(headers, "PHPSESSID").filter(|id| !id.is_empty())?;
        let digest = Sha256::new().chain_update(b"hx cart\0").chain_update(user.0.to_be_bytes()).chain_update(php.as_bytes()).finalize();
        Some(CartKey(digest.into()))
    }

    pub fn as_slice(&self) -> &[u8] {
        &self.0
    }
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

/// The visitor, when signed in to an Active account that may shop.
pub async fn shopper(pool: &SqlitePool, visitor: &Visitor, headers: &HeaderMap) -> sqlx::Result<Option<Shopper>> {
    let Some(signed_in) = visitor.signed_in().filter(|_| visitor.ecommerce()) else { return Ok(None) };
    let account = &signed_in.account;
    let currency = Currency::load(pool, &account.currency_code).await?;
    Ok(Some(Shopper {
        user: account.user.clone(),
        email: signed_in.email.as_ref().map(|e| e.as_str().to_string()),
        cart: CartKey::of(visitor, account.user.id, headers),
        currency,
        language: account.language_code.clone(),
    }))
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
    // Decimal's own {:.N} runs out of room for a 28-digit amount; its plain form has at most `dp` places.
    let text = rounded.abs().to_string();
    let (int, frac) = text.split_once('.').map_or((text.as_str(), ""), |(i, f)| (i, f));
    let frac = format!("{frac:0<width$}", width = dp as usize);
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
        assert_eq!(number_format(d("1.5"), 4), "1.5000");
        assert_eq!(number_format(d("1234567890123456789012345678"), 4), "1,234,567,890,123,456,789,012,345,678.0000");
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
