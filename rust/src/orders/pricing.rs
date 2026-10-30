//! What the cart needs from the catalog: whether a SKU can be ordered, what to call it on an order line, and what
//! it costs this account at this quantity. The catalog area owns pricing; until its `pricing.rs` lands, `Unpriced`
//! answers from the inventory tables and quotes every line on request, which is what the PHP cart shows for an
//! item it cannot price.

use std::{future::Future, pin::Pin};

use rust_decimal::Decimal;
use sqlx::SqlitePool;

use crate::identity::UserId;

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Price {
    OnRequest,
    Quoted { unit: Decimal, extended: Decimal },
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Priced {
    pub description: String,
    pub price: Price,
}

pub type PriceFuture<'a> = Pin<Box<dyn Future<Output = sqlx::Result<Option<Priced>>> + Send + 'a>>;

pub trait Pricing: Send + Sync {
    /// None when the SKU cannot be ordered (unknown, inactive, or not visible to this account).
    fn price<'a>(&'a self, pool: &'a SqlitePool, account: UserId, sku: &'a str, quantity: u32) -> PriceFuture<'a>;
}

/// Every active inventory SKU, described as "Category: Subcategory" as cart.php does, priced on request.
pub struct Unpriced;

impl Pricing for Unpriced {
    fn price<'a>(&'a self, pool: &'a SqlitePool, _account: UserId, sku: &'a str, _quantity: u32) -> PriceFuture<'a> {
        Box::pin(async move {
            let row = sqlx::query!(
                r#"SELECT coalesce(c.name, '') AS "category!: String", coalesce(s.name, '') AS "subcategory!: String"
                   FROM inventory i
                   LEFT JOIN subcategory s ON s.id = i.subcategory_id
                   LEFT JOIN category c ON c.id = i.category_id
                   WHERE i.sku = ?1 AND i.active = 1"#,
                sku
            )
            .fetch_optional(pool)
            .await?;
            Ok(row.map(|r| Priced { description: format!("{}: {}", r.category, r.subcategory), price: Price::OnRequest }))
        })
    }
}
