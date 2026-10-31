//! The languages the site offers (scs_header.php menu_class, classes/language.php) and their page text. A code is
//! only ever built from the list, so a `LanguageCode` is always one the site has.

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

/// The active languages, English first, then by priority and name.
pub struct Languages(Vec<LanguageCode>);

impl Languages {
    pub async fn load(pool: &SqlitePool) -> sqlx::Result<Languages> {
        let codes = sqlx::query_scalar!(r#"SELECT code AS "code!" FROM language WHERE active = 1 AND code <> '' ORDER BY code = 'EN' DESC, priority, name"#)
            .fetch_all(pool)
            .await?;
        Ok(Languages(codes.into_iter().map(|c| LanguageCode(c.to_ascii_uppercase())).collect()))
    }

    /// The offered language with this code, any case.
    pub fn get(&self, code: &str) -> Option<LanguageCode> {
        self.0.iter().find(|l| l.0.eq_ignore_ascii_case(code.trim())).cloned()
    }

    /// The first offered language an Accept-Language header names, English when none. scs_header.php
    /// request_language(): split on `;`, `|` or `,`, and take the first piece that starts with an offered code.
    /// Pieces are not trimmed there, so `en-US, fr` finds `en` but not ` fr`.
    pub fn from_accept_language(&self, header: &str) -> LanguageCode {
        header
            .split([';', '|', ','])
            .find_map(|piece| self.0.iter().find(|l| piece.len() >= l.0.len() && piece.as_bytes()[..l.0.len()].eq_ignore_ascii_case(l.0.as_bytes())))
            .map_or_else(LanguageCode::english, LanguageCode::clone)
    }
}

/// Translated page text for one language: the `menu` JSON of its `language` row (prod's classes/scs_lbl.php).
pub struct Labels(serde_json::Map<String, serde_json::Value>);

impl Labels {
    pub async fn load(pool: &SqlitePool, code: &LanguageCode) -> sqlx::Result<Labels> {
        let menu = sqlx::query_scalar!(r#"SELECT menu FROM language WHERE active = 1 AND upper(code) = ?1 LIMIT 1"#, code.0)
            .fetch_optional(pool)
            .await?
            .flatten();
        Ok(Labels(menu.and_then(|m| serde_json::from_str(&m).ok()).unwrap_or_default()))
    }

    /// scs_lbl($key, $default): the translation when there is a usable one, else the English default. The text
    /// is markup staff wrote, as it was in PHP.
    pub fn get(&self, key: &str, default: &str) -> String {
        match self.0.get(key).and_then(|v| v.as_str()) {
            Some(t) if !t.is_empty() && t != key && t != "Missing" => t.to_string(),
            _ => default.to_string(),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn langs(codes: &[&str]) -> Languages {
        Languages(codes.iter().map(|c| LanguageCode(c.to_string())).collect())
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

    #[tokio::test]
    async fn the_list_holds_the_active_codes_in_menu_order() {
        let pool = crate::db::open_memory().await.unwrap();
        sqlx::query("INSERT INTO language (code, country_code, name, priority, active) VALUES ('fr', 'CA', 'French', 1, 1), ('EN', 'US', 'English', 5, 1), ('DE', 'DE', 'German', 1, 0)")
            .execute(&pool)
            .await
            .unwrap();
        let l = Languages::load(&pool).await.unwrap();
        assert_eq!(l.0.iter().map(LanguageCode::as_str).collect::<Vec<_>>(), ["EN", "FR"]);
    }
}
