//! Languages and currencies the site offers (scs_header.php menu_class, classes/language.php, classes/currency.php).
//! A code is only ever built from these lists, so a `LanguageCode` or `CurrencyCode` is always one the site has.

use sqlx::SqlitePool;

/// A language the site offers: the `language.code`, upper case.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct LanguageCode(String);

impl LanguageCode {
    pub fn english() -> Self {
        LanguageCode("EN".into())
    }

    pub fn as_str(&self) -> &str {
        &self.0
    }
}

/// A currency the site prices in: the `currency.code`, upper case.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct CurrencyCode(String);

impl CurrencyCode {
    pub fn usd() -> Self {
        CurrencyCode("USD".into())
    }

    pub fn as_str(&self) -> &str {
        &self.0
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct Language {
    pub code: LanguageCode,
    pub name: String,
    pub local_name: String,
}

#[derive(Debug, Clone, PartialEq)]
pub struct Currency {
    pub code: CurrencyCode,
    pub name: String,
    pub symbol: Option<String>,
    pub exchange_rate: f64,
    pub adder_pct: f64,
}

/// The active languages, English first, then by priority and name.
pub struct Languages(Vec<Language>);

impl Languages {
    pub async fn load(pool: &SqlitePool) -> sqlx::Result<Languages> {
        let rows = sqlx::query!(
            r#"SELECT code AS "code!", name AS "name!", local_name AS "local_name!" FROM language
               WHERE active = 1 AND code <> '' ORDER BY code = 'EN' DESC, priority, name"#
        )
        .fetch_all(pool)
        .await?;
        Ok(Languages(
            rows.into_iter()
                .map(|r| Language { code: LanguageCode(r.code.to_ascii_uppercase()), name: r.name, local_name: r.local_name })
                .collect(),
        ))
    }

    pub fn all(&self) -> &[Language] {
        &self.0
    }

    /// The offered language with this code, any case.
    pub fn get(&self, code: &str) -> Option<LanguageCode> {
        self.0.iter().find(|l| l.code.0.eq_ignore_ascii_case(code.trim())).map(|l| l.code.clone())
    }

    /// The first offered language an Accept-Language header names, English when none. scs_header.php
    /// request_language(): split on `;`, `|` or `,`, and take the first piece that starts with an offered code.
    /// Pieces are not trimmed there, so `en-US, fr` finds `en` but not ` fr`.
    pub fn from_accept_language(&self, header: &str) -> LanguageCode {
        header
            .split([';', '|', ','])
            .find_map(|piece| {
                self.0.iter().find(|l| piece.len() >= l.code.0.len() && piece.as_bytes()[..l.code.0.len()].eq_ignore_ascii_case(l.code.0.as_bytes()))
            })
            .map_or_else(LanguageCode::english, |l| l.code.clone())
    }
}

/// The active currencies with a rate, US dollars first, then by name.
pub struct Currencies(Vec<Currency>);

impl Currencies {
    pub async fn load(pool: &SqlitePool) -> sqlx::Result<Currencies> {
        let rows = sqlx::query!(
            r#"SELECT code AS "code!", name, symbol, exchange_rate AS "exchange_rate!: f64", adder_pct
               FROM currency WHERE code IS NOT NULL AND exchange_rate > 0 AND active = 1
               ORDER BY code = 'USD' DESC, name"#
        )
        .fetch_all(pool)
        .await?;
        Ok(Currencies(
            rows.into_iter()
                .map(|r| Currency {
                    code: CurrencyCode(r.code.to_ascii_uppercase()),
                    name: r.name.unwrap_or_default(),
                    symbol: r.symbol,
                    exchange_rate: r.exchange_rate,
                    adder_pct: r.adder_pct.unwrap_or(0.0),
                })
                .collect(),
        ))
    }

    pub fn all(&self) -> &[Currency] {
        &self.0
    }

    pub fn get(&self, code: &str) -> Option<CurrencyCode> {
        self.0.iter().find(|c| c.code.0.eq_ignore_ascii_case(code.trim())).map(|c| c.code.clone())
    }
}

impl Currency {
    /// classes/currency.php exchange_net: the rate with the adder, rounded to 4 places.
    pub fn exchange_net(&self) -> f64 {
        (self.exchange_rate * (1.0 + self.adder_pct) * 10_000.0).round() / 10_000.0
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn langs(codes: &[&str]) -> Languages {
        Languages(codes.iter().map(|c| Language { code: LanguageCode(c.to_string()), name: c.to_string(), local_name: c.to_string() }).collect())
    }

    #[test]
    fn accept_language_takes_the_first_piece_that_starts_with_an_offered_code() {
        let l = langs(&["EN", "FR"]);
        assert_eq!(l.from_accept_language("fr-CA,fr;q=0.9,en;q=0.8").as_str(), "FR");
        assert_eq!(l.from_accept_language("en-US,en;q=0.9").as_str(), "EN");
        assert_eq!(l.from_accept_language("de-DE, fr;q=0.5").as_str(), "EN", "a piece with a leading space does not match");
        assert_eq!(l.from_accept_language("de-DE,fr;q=0.5").as_str(), "FR");
        assert_eq!(l.from_accept_language("").as_str(), "EN");
        assert_eq!(langs(&[]).from_accept_language("fr").as_str(), "EN");
    }

    #[test]
    fn codes_are_only_those_offered() {
        let l = langs(&["EN", "FR"]);
        assert_eq!(l.get("fr").unwrap().as_str(), "FR");
        assert!(l.get("de").is_none());
        assert!(l.get("").is_none());
    }

    #[test]
    fn exchange_net_adds_the_adder_and_rounds() {
        let c = Currency { code: CurrencyCode("CAD".into()), name: String::new(), symbol: None, exchange_rate: 1.3712, adder_pct: 0.03 };
        assert_eq!(c.exchange_net(), 1.4123);
    }

    #[tokio::test]
    async fn lists_load_in_menu_order() {
        let pool = crate::db::open_memory().await.unwrap();
        sqlx::query("INSERT INTO language (code, country_code, name, priority, active) VALUES ('FR', 'CA', 'French', 1, 1), ('EN', 'US', 'English', 5, 1), ('DE', 'DE', 'German', 1, 0)")
            .execute(&pool)
            .await
            .unwrap();
        sqlx::query("INSERT INTO currency (code, name, plural, exchange_rate, active) VALUES ('EUR', 'Euro', 'Euros', 0.9, 1), ('USD', 'US Dollar', 'US Dollars', 1, 1), ('CAD', 'Canadian Dollar', 'Canadian Dollars', 1.37, 1), ('GBP', 'Pound', 'Pounds', 0, 1)")
            .execute(&pool)
            .await
            .unwrap();
        let l = Languages::load(&pool).await.unwrap();
        assert_eq!(l.all().iter().map(|x| x.code.as_str()).collect::<Vec<_>>(), ["EN", "FR"]);
        let c = Currencies::load(&pool).await.unwrap();
        assert_eq!(c.all().iter().map(|x| x.code.as_str()).collect::<Vec<_>>(), ["USD", "CAD", "EUR"]);
    }
}
