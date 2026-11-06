//! What the cart needs from the catalog: whether this viewer may order a SKU, what to call it on an order line, and
//! what it costs at this quantity. `CatalogPrices` answers what the catalog page shows the same viewer: the family's
//! visibility (`Viewer::sees_family`) and the quote (`crate::pricing`).

use std::{future::Future, pin::Pin};

use rust_decimal::{prelude::FromPrimitive, Decimal};
use sqlx::SqlitePool;

use crate::{
    catalog::{
        model::{self, Status},
        viewer::Viewer,
    },
    pricing::{self, Buyer, ListPrices, PriceBook, Quote},
};

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
    /// None when the SKU cannot be ordered (unknown, inactive, or in a family the catalog does not show this viewer).
    fn price<'a>(&'a self, pool: &'a SqlitePool, viewer: &'a Viewer, sku: &'a str, quantity: u32) -> PriceFuture<'a>;
}

/// The catalog's prices. An active part in a family the viewer is shown, described as "Category: Subcategory" as
/// cart.php did, priced at the quantity's break, or on request where the catalog says "Contact Harcourt".
pub struct CatalogPrices;

impl Pricing for CatalogPrices {
    fn price<'a>(&'a self, pool: &'a SqlitePool, viewer: &'a Viewer, sku: &'a str, quantity: u32) -> PriceFuture<'a> {
        Box::pin(async move {
            let row = sqlx::query!(
                r#"SELECT coalesce(i.pricing, '') AS "pricing!: String", coalesce(c.name, '') AS "category!: String",
                          coalesce(s.name, '') AS "family!: String", s.status AS "status!: String", s.currency,
                          coalesce(s.category_id, 0) AS "family_category!: i64", coalesce(fc.active, 0) AS "category_active!: i64",
                          coalesce(i.active, 0) AS "active!: i64"
                   FROM inventory i
                   JOIN subcategory s ON s.id = i.subcategory_id
                   LEFT JOIN category c ON c.id = i.category_id
                   LEFT JOIN category fc ON fc.id = s.category_id
                   WHERE i.sku = ?1"#,
                sku
            )
            .fetch_optional(pool)
            .await?;
            let Some(row) = row.filter(|r| model::orderable(r.active != 0) && viewer.sees_family(Status::parse(&r.status), r.category_active != 0, r.family_category)) else { return Ok(None) };
            let book = PriceBook::load(pool, "").await?;
            let buyer = Buyer { terms: viewer.price, may_buy: viewer.active(), currency_code: &viewer.currency_code };
            let quote = pricing::quote(&book, &buyer, &model::currency_adders(row.currency.as_deref()), &ListPrices::parse(&row.pricing), i64::from(quantity));
            Ok(Some(Priced { description: format!("{}: {}", row.category, row.family), price: price(&quote, quantity) }))
        })
    }
}

fn price(quote: &Quote, quantity: u32) -> Price {
    match quote {
        Quote::Priced { price, .. } => match Decimal::from_f64(*price) {
            Some(unit) => {
                let unit = unit.round_dp(2);
                Price::Quoted { unit, extended: unit * Decimal::from(quantity) }
            }
            None => Price::OnRequest,
        },
        Quote::NoPrice => Price::OnRequest,
    }
}
