//! What the catalog knows about who is asking: the `Visitor`, the catalog fields of its account row and the
//! categories blocked for it, read once per request. Everything the catalog shows or hides is decided from this.
//!
//! The PHP kept the same facts in `$_SESSION['user']` (classes/user.php session(), classes/bot.php session()). As
//! there, an account that is not Active has no catalog, CAD or cart, whatever its row says.

use sqlx::SqlitePool;

use crate::{
    accounts::{account::PriceType, bot::BotStatus},
    identity::{Role, Status, UserId},
    pricing::PriceTerms,
    visitor::{Identity, Visitor},
};

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Lang {
    En,
    Fr,
}

impl Lang {
    /// The page's language as links carry it (`lang=fr`); anything else is English, as WPML's default.
    pub fn from_param(s: Option<&str>) -> Lang {
        match s.map(str::trim) {
            Some(l) if l.eq_ignore_ascii_case("fr") => Lang::Fr,
            _ => Lang::En,
        }
    }

    pub fn from_code(code: &str) -> Option<Lang> {
        match code.trim().to_ascii_uppercase().as_str() {
            "EN" => Some(Lang::En),
            "FR" => Some(Lang::Fr),
            _ => None,
        }
    }

    /// `language.code`, `content.language_code`, `user.language_code`.
    pub fn code(self) -> &'static str {
        match self {
            Lang::En => "EN",
            Lang::Fr => "FR",
        }
    }

    /// The `lang=` value catalog links carry.
    pub fn param(self) -> &'static str {
        match self {
            Lang::En => "en",
            Lang::Fr => "fr",
        }
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Kind {
    /// No session: classes/user.php's empty user_data_class (id 0, Pending).
    Anonymous,
    /// A crawler matched by the bot table: the catalog without the per-person parts (profile, page breaks).
    Bot { bot_id: i64 },
    Account(UserId),
}

/// Categories closed to this visitor. `user_category` rows need an Integrator Agreement, `company_category_block`
/// rows (by company name) a Design Partner Agreement.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Blocked {
    pub by_user: Vec<i64>,
    pub by_company: Vec<i64>,
}

impl Blocked {
    pub fn contains(&self, category_id: i64) -> bool {
        self.by_user.contains(&category_id) || self.by_company.contains(&category_id)
    }

    /// catalog.php blocked_agreement_message(), for a category this visitor cannot open.
    pub fn agreement_message(&self, category_id: i64) -> Option<String> {
        let mut needed = Vec::new();
        if self.by_user.contains(&category_id) {
            needed.push("Integrator Agreement");
        }
        if self.by_company.contains(&category_id) {
            needed.push("Design Partner Agreement");
        }
        match needed.as_slice() {
            [] => None,
            [one] => Some(format!("{one} Required for Access.")),
            many => Some(format!("{} are required for Access.", many.join(" and "))),
        }
    }

    /// Every blocked category once, user rows first (catalog.php blocked_categories()).
    pub fn ids(&self) -> Vec<i64> {
        let mut ids = self.by_user.clone();
        for id in &self.by_company {
            if !ids.contains(id) {
                ids.push(*id);
            }
        }
        ids
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct Viewer {
    pub kind: Kind,
    pub lang: Lang,
    /// The language saved on the account (user.language_code); a page in the other language sends the visitor there.
    pub account_lang: Option<Lang>,
    pub role: Role,
    pub active: bool,
    pub catalog: bool,
    pub cad: bool,
    pub ecommerce: bool,
    pub price: Option<PriceTerms>,
    pub currency_code: String,
    /// Upper-case and trimmed; empty when unknown.
    pub country_code: String,
    pub company_name: Option<String>,
    pub industry_id: Option<i64>,
    pub portal_id: Option<i64>,
    pub landing_document_id: Option<i64>,
    pub login_document_id: Option<i64>,
    pub blocked: Blocked,
}

impl Viewer {
    pub fn anonymous(lang: Lang) -> Viewer {
        Viewer {
            kind: Kind::Anonymous,
            lang,
            account_lang: None,
            role: Role::Customer,
            active: false,
            catalog: false,
            cad: false,
            ecommerce: false,
            price: None,
            currency_code: "USD".into(),
            country_code: String::new(),
            company_name: None,
            industry_id: None,
            portal_id: None,
            landing_document_id: None,
            login_document_id: None,
            blocked: Blocked::default(),
        }
    }

    /// A crawler from the bot table (classes/bot.php session()): a Customer with the bot's status, catalog flag and
    /// portal, and nothing else.
    pub fn bot(bot_id: i64, status: Status, catalog: bool, portal_id: Option<i64>, lang: Lang) -> Viewer {
        let active = status == Status::Active;
        Viewer { kind: Kind::Bot { bot_id }, active, catalog: active && catalog, portal_id, ..Viewer::anonymous(lang) }
    }

    pub fn account_id(&self) -> Option<UserId> {
        match self.kind {
            Kind::Account(id) => Some(id),
            _ => None,
        }
    }

    pub fn is_bot(&self) -> bool {
        matches!(self.kind, Kind::Bot { .. })
    }

    /// May open catalog pages at all (catalog.php's checks before route()). Everyone else is shown document 157.
    pub fn may_browse(&self) -> bool {
        !matches!(self.kind, Kind::Anonymous) && self.active && self.catalog
    }

    /// Administrators also see Pending subcategories.
    pub fn sees_pending(&self) -> bool {
        self.role >= Role::Administrator
    }

    pub fn at_least(&self, role: Role) -> bool {
        !matches!(self.kind, Kind::Anonymous) && self.role >= role
    }
}

/// The page's language: the visitor's, as the PHP session held it (the catalog page's language, an explicit
/// choice, the account's, the browser's). A link's `lang=` is WordPress's and does not change it.
pub fn lang_for(visitor: &Visitor) -> Lang {
    Lang::from_code(visitor.language.as_str()).unwrap_or(Lang::En)
}

/// The viewer for this request.
pub async fn load(pool: &SqlitePool, visitor: &Visitor, lang: Lang) -> sqlx::Result<Viewer> {
    let signed_in = match &visitor.identity {
        Identity::Anonymous => return Ok(Viewer::anonymous(lang)),
        Identity::Bot(b) => {
            let status = if b.status == BotStatus::Active { Status::Active } else { Status::Denied };
            return Ok(Viewer::bot(b.id, status, b.catalog, (b.portal_id > 0).then_some(b.portal_id), lang));
        }
        Identity::Account(s) => s,
    };
    let account = &signed_in.account;
    let user = &account.user;
    let company_name = user.company_name.clone().filter(|c| !c.is_empty());
    let blocked = blocked(pool, user.id, company_name.as_deref()).await?;
    let id_of = |v: i64| (v > 0).then_some(v);
    let price_type = match account.price_type {
        PriceType::None => "",
        PriceType::Base => "base",
        PriceType::Discount => "discount",
        PriceType::Plus => "plus",
    };
    Ok(Viewer {
        kind: Kind::Account(user.id),
        lang,
        account_lang: Lang::from_code(&account.language_code),
        role: user.role,
        active: user.is_active(),
        catalog: account.catalog(),
        cad: account.cad(),
        ecommerce: account.ecommerce(),
        price: PriceTerms::parse(price_type, account.price_pct),
        currency_code: account.currency_code.clone(),
        country_code: account.country_code.clone().unwrap_or_default().trim().to_ascii_uppercase(),
        company_name,
        industry_id: id_of(account.industry_id),
        portal_id: id_of(account.portal_id),
        landing_document_id: id_of(account.landing_document_id),
        login_document_id: id_of(account.login_document_id),
        blocked,
    })
}

async fn blocked(pool: &SqlitePool, user: UserId, company_name: Option<&str>) -> sqlx::Result<Blocked> {
    let by_user = sqlx::query_scalar!(r#"SELECT category_id AS "id!: i64" FROM user_category WHERE user_id = ?1 ORDER BY id"#, user.0)
        .fetch_all(pool)
        .await?;
    let by_company = match company_name {
        Some(name) => {
            sqlx::query_scalar!(r#"SELECT category_id AS "id!: i64" FROM company_category_block WHERE company_name = ?1 ORDER BY id"#, name)
                .fetch_all(pool)
                .await?
        }
        None => Vec::new(),
    };
    Ok(Blocked { by_user, by_company })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn lang_from_the_link() {
        assert_eq!(Lang::from_param(Some("fr")), Lang::Fr);
        assert_eq!(Lang::from_param(Some("FR ")), Lang::Fr);
        assert_eq!(Lang::from_param(Some("de")), Lang::En);
        assert_eq!(Lang::from_param(None), Lang::En);
    }

    #[test]
    fn agreement_messages_name_each_missing_agreement() {
        let b = Blocked { by_user: vec![3, 5], by_company: vec![5, 7] };
        assert_eq!(b.agreement_message(3).as_deref(), Some("Integrator Agreement Required for Access."));
        assert_eq!(b.agreement_message(7).as_deref(), Some("Design Partner Agreement Required for Access."));
        assert_eq!(b.agreement_message(5).as_deref(), Some("Integrator Agreement and Design Partner Agreement are required for Access."));
        assert_eq!(b.agreement_message(9), None);
        assert_eq!(b.ids(), vec![3, 5, 7]);
    }

    #[test]
    fn only_active_accounts_and_bots_with_the_catalog_may_browse() {
        assert!(!Viewer::anonymous(Lang::En).may_browse());
        assert!(Viewer::bot(1, Status::Active, true, None, Lang::En).may_browse());
        assert!(!Viewer::bot(1, Status::Denied, true, None, Lang::En).may_browse());
        assert!(!Viewer::bot(1, Status::Active, false, None, Lang::En).may_browse());
        let account = Viewer { kind: Kind::Account(UserId(4)), active: true, catalog: true, ..Viewer::anonymous(Lang::En) };
        assert!(account.may_browse());
        assert!(!Viewer { catalog: false, ..account.clone() }.may_browse());
        assert!(!account.sees_pending());
        assert!(Viewer { role: Role::Administrator, ..account }.sees_pending());
    }
}
