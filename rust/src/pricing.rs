//! What a visitor pays. Owned by the catalog, reused by orders: the catalog shows these prices and the cart charges
//! them, so both read them from here (classes/configure.php configure_meta_class, configure_results, output_*).
//!
//! `PriceBook` is loaded once per request; everything else is a pure function of it, the buyer and the part's list
//! prices, so a parts table of a thousand rows prices without touching the database.

use sqlx::SqlitePool;

use crate::catalog::stored;

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

/// An active currency with a rate (menu_class: active = 1 and exchange_rate > 0, US dollars first).
#[derive(Debug, Clone, PartialEq)]
pub struct Currency {
    pub code: String,
    pub plural: String,
    pub exchange_rate: f64,
    pub adder_pct: f64,
}

#[derive(Debug, Clone, Default, PartialEq)]
pub struct PriceBook {
    pub currencies: Vec<Currency>,
    /// registry local.configure_contact: shown where a price cannot be given.
    pub contact: String,
}

impl PriceBook {
    pub fn currency(&self, code: &str) -> Option<&Currency> {
        self.currencies.iter().find(|c| c.code == code)
    }

    pub async fn load(pool: &SqlitePool, contact: &str) -> sqlx::Result<PriceBook> {
        let rows = sqlx::query!(
            r#"SELECT code AS "code!", plural, exchange_rate AS "exchange_rate!: f64", adder_pct
               FROM currency WHERE exchange_rate > 0 AND active = 1 AND code IS NOT NULL
               ORDER BY CASE WHEN code = 'USD' THEN 0 ELSE 1 END, name"#
        )
        .fetch_all(pool)
        .await?;
        let mut currencies: Vec<Currency> = Vec::new();
        for r in rows {
            if !currencies.iter().any(|c| c.code == r.code) {
                currencies.push(Currency { code: r.code, plural: r.plural.unwrap_or_default(), exchange_rate: r.exchange_rate, adder_pct: r.adder_pct.unwrap_or(0.0) });
            }
        }
        Ok(PriceBook { currencies, contact: contact.to_string() })
    }
}

/// Who is buying: their terms, whether their account may see prices at all (Pending and Denied accounts may not),
/// and the currency on their account.
#[derive(Debug, Clone, Copy, PartialEq)]
pub struct Buyer<'a> {
    pub terms: Option<PriceTerms>,
    pub may_buy: bool,
    pub currency_code: &'a str,
}

impl Buyer<'_> {
    /// The currency prices are given in: the account's when it is active here, else US dollars.
    pub fn currency<'b>(&'b self, book: &'b PriceBook) -> &'b str {
        if book.currency(self.currency_code).is_some() {
            self.currency_code
        } else {
            "USD"
        }
    }
}

/// A part's list prices by quantity break, in stored order.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct ListPrices(pub Vec<(i64, f64)>);

impl ListPrices {
    /// inventory.pricing: `[{"price": 12.5, "qty_break": 0}, ...]`.
    pub fn parse(json: &str) -> ListPrices {
        let breaks = stored::parse(Some(json));
        ListPrices(stored::entries(&breaks).into_iter().map(|(_, b)| (b.get("qty_break").map_or(0, stored::int), b.get("price").map_or(0.0, stored::float))).collect())
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct Break {
    pub qty: i64,
    pub msrp: f64,
    pub price: f64,
}

#[derive(Debug, Clone, PartialEq)]
pub enum Quote {
    /// No account terms, an account that may not buy, no rate for the currency, or no list price at this quantity.
    NoPrice,
    Priced { msrp: f64, price: f64, breaks: Vec<Break>, currency: String, discount: bool },
}

/// configure_class::inventory_msrp() and configure_results::update() for a stocked part.
/// `adders` are the family's own currency adders (subcategory.currency), which replace the currency's.
pub fn quote(book: &PriceBook, buyer: &Buyer, adders: &[(String, f64)], list: &ListPrices, quantity: i64) -> Quote {
    let quantity = quantity.max(1);
    let code = buyer.currency(book).to_string();
    let Some(terms) = buyer.terms.filter(|_| buyer.may_buy) else { return Quote::NoPrice };
    let multiplier = terms.multiplier();
    let (rate, mut adder) = book.currency(&code).map_or((0.0, 0.0), |c| (c.exchange_rate, c.adder_pct));
    if let Some((_, a)) = adders.iter().find(|(c, _)| *c == code) {
        adder = *a;
    }
    let net = php_round(rate * (1.0 + adder), 4);
    if multiplier == 0.0 || net == 0.0 {
        return Quote::NoPrice;
    }
    let mut breaks: Vec<Break> = Vec::new();
    for &(qty, list_price) in &list.0 {
        let msrp = if code == "USD" { list_price } else { php_round(list_price * net, 2) };
        let b = Break { qty, msrp, price: php_round(msrp * multiplier, 2) };
        match breaks.iter_mut().find(|x| x.qty == qty) {
            Some(existing) => *existing = b,
            None => breaks.push(b),
        }
    }
    let (mut msrp, mut price) = (0.0, 0.0);
    for b in &breaks {
        if b.qty > quantity {
            break;
        }
        msrp = b.msrp;
        price = b.price;
    }
    if price == 0.0 {
        return Quote::NoPrice;
    }
    Quote::Priced { msrp, price, breaks, currency: code, discount: matches!(terms, PriceTerms::Discount(_)) }
}

/// configure_class::currency_price().
pub fn money(book: &PriceBook, buyer: &Buyer, amount: f64) -> String {
    let code = buyer.currency(book);
    let mut out = String::new();
    if code == "USD" && buyer.currency_code == "USD" {
        out.push('$');
    }
    out.push_str(&number_format(amount, 2));
    if buyer.currency_code != "USD" || code != "USD" {
        out.push(' ');
        out.push_str(code);
    }
    out
}

/// configure_class::output_simple(): the price at quantity one, or "Contact Harcourt".
pub fn simple(book: &PriceBook, buyer: &Buyer, quote: &Quote) -> String {
    match quote {
        Quote::Priced { price, .. } => money(book, buyer, *price),
        Quote::NoPrice => "Contact Harcourt".into(),
    }
}

/// configure_class::currency_name(): "Priced in ..." when prices are not plain US dollars for this buyer.
pub fn currency_note(book: &PriceBook, buyer: &Buyer, div: bool) -> Option<String> {
    let code = buyer.currency(book);
    if code == "USD" && book.currency(buyer.currency_code).is_some() {
        return None;
    }
    let plural = book.currency(code).map_or("", |c| c.plural.as_str());
    let text = format!("Priced in <b>{}</b>", crate::catalog::html::esc(plural));
    Some(if div { format!("<div>{text}</div>") } else { text })
}

/// configure_class::output_verbose() for a part: the break table, the discounted price or the plain price.
pub fn verbose(book: &PriceBook, buyer: &Buyer, quote: &Quote) -> String {
    let red = |s: String| format!("<font color=red>{s}</font>");
    match quote {
        Quote::NoPrice => format!("<p>{}</p>", book.contact),
        Quote::Priced { breaks, discount, .. } if breaks.len() > 1 => {
            let mut out = currency_note(book, buyer, true).unwrap_or_default();
            out.push_str("<table class='standard noborder'><tr><td class='right'><b>From</b></td><td class='right'><b>Thru</b></td><td class='right'><b>Price</b></td>");
            if *discount {
                out.push_str("<td class='right nobr'><b>Your Price</b></td>");
            }
            out.push_str("</tr>");
            for (i, b) in breaks.iter().enumerate() {
                let from = if i == 0 { 1 } else { b.qty };
                let thru = breaks.get(i + 1).map_or(0, |next| next.qty - 1);
                out.push_str(&format!(
                    "<tr><td class='right'>{}{}</td><td class='right'>{}</td><td class='right'>{}</td>",
                    number_format(from as f64, 0),
                    if thru == 0 { "+" } else { "" },
                    if thru == 0 { String::new() } else { number_format(thru as f64, 0) },
                    money(book, buyer, b.msrp)
                ));
                if *discount {
                    out.push_str(&format!("<td class='right'>{}</td>", red(format!("<b><i>{}</i></b>", money(book, buyer, b.price)))));
                }
                out.push_str("</tr>");
            }
            out.push_str("</table>");
            out
        }
        Quote::Priced { msrp, price, discount: true, .. } => {
            let lines = [
                currency_note(book, buyer, false).unwrap_or_default(),
                format!("Harcourt List Price: {}", money(book, buyer, *msrp)),
                red(format!("<b><i>Your Discounted Price: {}</i></b>", money(book, buyer, *price))),
            ];
            format!("<div>{}</div>", lines.join("<br>"))
        }
        Quote::Priced { .. } => format!("<div>Price: {}</div>{}", simple(book, buyer, quote), currency_note(book, buyer, true).unwrap_or_default()),
    }
}

/// PHP round(): half away from zero, with the small correction PHP applies to values like 1.005.
pub fn php_round(x: f64, places: i32) -> f64 {
    let f = 10f64.powi(places);
    let v = x * f;
    let r = (v.abs() + 1e-9 * v.abs().max(1.0)).floor();
    let r = if v.abs() - r >= 0.5 - 1e-9 { r + 1.0 } else { r };
    r.copysign(x) / f
}

/// PHP number_format($n, $decimals): rounded half away from zero, thousands grouped with commas.
pub fn number_format(n: f64, decimals: usize) -> String {
    let rounded = php_round(n, decimals as i32);
    let s = format!("{:.*}", decimals, rounded.abs());
    let (int, frac) = match s.split_once('.') {
        Some((i, f)) => (i.to_string(), Some(f.to_string())),
        None => (s, None),
    };
    let mut grouped = String::new();
    for (i, c) in int.chars().enumerate() {
        if i > 0 && (int.len() - i) % 3 == 0 {
            grouped.push(',');
        }
        grouped.push(c);
    }
    let sign = if rounded < 0.0 && rounded != 0.0 { "-" } else { "" };
    match frac {
        Some(f) => format!("{sign}{grouped}.{f}"),
        None => format!("{sign}{grouped}"),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn book() -> PriceBook {
        PriceBook {
            currencies: vec![
                Currency { code: "USD".into(), plural: "US Dollars".into(), exchange_rate: 1.0, adder_pct: 0.0 },
                Currency { code: "CAD".into(), plural: "Canadian Dollars".into(), exchange_rate: 1.35, adder_pct: 0.02 },
            ],
            contact: "Please contact Harcourt for pricing".into(),
        }
    }

    fn list() -> ListPrices {
        ListPrices(vec![(1, 10.0), (10, 9.0), (100, 8.25)])
    }

    #[test]
    fn terms_parse_from_the_user_row() {
        assert_eq!(PriceTerms::parse("", 0.2), None);
        assert_eq!(PriceTerms::parse("wholesale", 0.2), None);
        assert_eq!(PriceTerms::parse("base", 0.2), Some(PriceTerms::Base));
        assert_eq!(PriceTerms::parse("discount", 0.25).map(PriceTerms::multiplier), Some(0.75));
        assert_eq!(PriceTerms::parse("plus", 0.1).map(PriceTerms::multiplier), Some(1.1));
    }

    #[test]
    fn list_prices_parse_from_inventory_pricing() {
        let s = r#"[{"price":12.5,"qty_break":1},{"price":"11.00","qty_break":"25"},{"price":9.5,"qty_break":40.0}]"#;
        assert_eq!(ListPrices::parse(s), ListPrices(vec![(1, 12.5), (25, 11.0), (40, 9.5)]));
        assert_eq!(ListPrices::parse(""), ListPrices::default());
        assert_eq!(ListPrices::parse("[]"), ListPrices::default());
    }

    #[test]
    fn quotes_by_terms_quantity_and_currency() {
        let b = book();
        let base = Buyer { terms: Some(PriceTerms::Base), may_buy: true, currency_code: "USD" };
        let q = quote(&b, &base, &[], &list(), 1);
        assert!(matches!(q, Quote::Priced { price, .. } if price == 10.0));
        assert!(matches!(quote(&b, &base, &[], &list(), 10), Quote::Priced { price, .. } if price == 9.0));
        assert!(matches!(quote(&b, &base, &[], &list(), 99), Quote::Priced { price, .. } if price == 9.0));
        assert_eq!(simple(&b, &base, &q), "$10.00");

        let disc = Buyer { terms: Some(PriceTerms::Discount(0.15)), ..base };
        assert!(matches!(quote(&b, &disc, &[], &list(), 1), Quote::Priced { msrp, price, .. } if msrp == 10.0 && price == 8.5));

        let cad = Buyer { currency_code: "CAD", ..base };
        let q = quote(&b, &cad, &[], &list(), 1);
        assert!(matches!(&q, Quote::Priced { msrp, currency, .. } if *msrp == 13.77 && currency == "CAD"));
        assert_eq!(simple(&b, &cad, &q), "13.77 CAD");
        assert_eq!(currency_note(&b, &cad, true).as_deref(), Some("<div>Priced in <b>Canadian Dollars</b></div>"));
        let q = quote(&b, &cad, &[("CAD".into(), 0.0)], &list(), 1);
        assert!(matches!(q, Quote::Priced { msrp, .. } if msrp == 13.5));

        let unknown = Buyer { currency_code: "EUR", ..base };
        let q = quote(&b, &unknown, &[], &list(), 1);
        assert_eq!(simple(&b, &unknown, &q), "10.00 USD");
        assert_eq!(currency_note(&b, &unknown, false).as_deref(), Some("Priced in <b>US Dollars</b>"));
        assert_eq!(currency_note(&b, &base, true), None);
    }

    #[test]
    fn no_price_without_terms_access_or_a_first_break() {
        let b = book();
        let none = Buyer { terms: None, may_buy: true, currency_code: "USD" };
        assert_eq!(quote(&b, &none, &[], &list(), 1), Quote::NoPrice);
        let pending = Buyer { terms: Some(PriceTerms::Base), may_buy: false, currency_code: "USD" };
        assert_eq!(quote(&b, &pending, &[], &list(), 1), Quote::NoPrice);
        let base = Buyer { may_buy: true, ..pending };
        assert_eq!(quote(&b, &base, &[], &ListPrices(vec![(5, 10.0)]), 1), Quote::NoPrice);
        assert_eq!(quote(&PriceBook::default(), &base, &[], &list(), 1), Quote::NoPrice);
        assert_eq!(simple(&b, &base, &Quote::NoPrice), "Contact Harcourt");
        assert_eq!(verbose(&b, &base, &Quote::NoPrice), "<p>Please contact Harcourt for pricing</p>");
    }

    #[test]
    fn verbose_blocks() {
        let b = book();
        let base = Buyer { terms: Some(PriceTerms::Base), may_buy: true, currency_code: "USD" };
        let one = quote(&b, &base, &[], &ListPrices(vec![(1, 1234.5)]), 1);
        assert_eq!(verbose(&b, &base, &one), "<div>Price: $1,234.50</div>");
        let disc = Buyer { terms: Some(PriceTerms::Discount(0.1)), ..base };
        let q = quote(&b, &disc, &[], &ListPrices(vec![(1, 20.0)]), 1);
        assert_eq!(verbose(&b, &disc, &q), "<div><br>Harcourt List Price: $20.00<br><font color=red><b><i>Your Discounted Price: $18.00</i></b></font></div>");
        let q = quote(&b, &base, &[], &list(), 1);
        let table = verbose(&b, &base, &q);
        assert!(table.contains("<td class='right'>1</td><td class='right'>9</td><td class='right'>$10.00</td>"));
        assert!(table.contains("<td class='right'>100+</td><td class='right'></td><td class='right'>$8.25</td>"));
    }

    #[test]
    fn php_rounding_and_number_format() {
        assert_eq!(php_round(1.005, 2), 1.01);
        assert_eq!(php_round(2.675, 2), 2.68);
        assert_eq!(php_round(-1.5, 0), -2.0);
        assert_eq!(number_format(1234567.891, 2), "1,234,567.89");
        assert_eq!(number_format(999.995, 2), "1,000.00");
        assert_eq!(number_format(12.0, 0), "12");
        assert_eq!(number_format(0.0, 2), "0.00");
    }
}
