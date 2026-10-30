//! What a visitor pays. Owned by the catalog, reused by orders: the catalog shows these prices and the cart charges
//! them, so both read them from here (classes/configure.php, classes/pricing.php).

/// The `user.price_type` / `user.price_pct` pair, parsed once. No value means the visitor sees no prices
/// ("No pricing" in classes/user.php); text this code does not know parses to no value as well.
#[derive(Debug, Clone, Copy, PartialEq)]
pub enum PriceTerms {
    Base,
    Discount(f64),
    Plus(f64),
}

impl PriceTerms {
    pub fn parse(price_type: &str, price_pct: f64) -> Option<PriceTerms> {
        match price_type.trim() {
            "base" => Some(PriceTerms::Base),
            "discount" => Some(PriceTerms::Discount(price_pct)),
            "plus" => Some(PriceTerms::Plus(price_pct)),
            _ => None,
        }
    }

    /// The factor applied to the list price (configure_meta_class).
    pub fn multiplier(self) -> f64 {
        match self {
            PriceTerms::Base => 1.0,
            PriceTerms::Discount(pct) => 1.0 - pct,
            PriceTerms::Plus(pct) => 1.0 + pct,
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn terms_parse_from_the_user_row() {
        assert_eq!(PriceTerms::parse("", 0.2), None);
        assert_eq!(PriceTerms::parse("wholesale", 0.2), None);
        assert_eq!(PriceTerms::parse("base", 0.2), Some(PriceTerms::Base));
        assert_eq!(PriceTerms::parse("discount", 0.25).map(PriceTerms::multiplier), Some(0.75));
        assert_eq!(PriceTerms::parse("plus", 0.1).map(PriceTerms::multiplier), Some(1.1));
    }
}
