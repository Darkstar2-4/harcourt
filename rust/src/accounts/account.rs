//! The whole `user` row (classes/user.php user_data_class): a company account and what it may see. `identity::User`
//! is the part sign-in decisions need; `Account` adds everything a page reads.

use sqlx::SqlitePool;

use crate::identity::{Role, Status, User, UserId};

/// classes/user.php price_type. Unknown text means no pricing.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum PriceType {
    None,
    Base,
    Discount,
    Plus,
}

impl PriceType {
    pub fn parse(s: &str) -> PriceType {
        match s {
            "base" => PriceType::Base,
            "discount" => PriceType::Discount,
            "plus" => PriceType::Plus,
            _ => PriceType::None,
        }
    }

    pub fn as_str(self) -> &'static str {
        match self {
            PriceType::None => "",
            PriceType::Base => "base",
            PriceType::Discount => "discount",
            PriceType::Plus => "plus",
        }
    }
}

/// What the account was granted, as stored. Use `Account::catalog()` and friends for what the visitor may do now.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub struct Grants {
    pub catalog: bool,
    pub cad: bool,
    pub ecommerce: bool,
}

#[derive(Debug, Clone, PartialEq)]
pub struct Account {
    pub user: User,
    pub ip: Option<String>,
    pub address: Option<String>,
    pub city: Option<String>,
    pub state: Option<String>,
    pub zip: Option<String>,
    pub country_code: Option<String>,
    pub last_login: i64,
    pub login_document_id: i64,
    pub landing_document_id: i64,
    pub comment: Option<String>,
    pub portal_id: i64,
    pub customer_code: Option<String>,
    pub grants: Grants,
    pub price_type: PriceType,
    /// A fraction: 0.15 is 15%.
    pub price_pct: f64,
    pub language_code: String,
    pub industry_id: i64,
    pub leadscore: i64,
    pub revenue: String,
    pub employees: String,
    pub domain: Option<String>,
    pub currency_code: String,
    pub recaptcha_score: f64,
}

impl Account {
    pub fn id(&self) -> UserId {
        self.user.id
    }

    pub fn role(&self) -> Role {
        self.user.role
    }

    pub fn status(&self) -> Status {
        self.user.status
    }

    /// classes/user.php session(): an account that is not Active sees no catalog, CAD or shop, whatever it was granted.
    pub fn catalog(&self) -> bool {
        self.user.is_active() && self.grants.catalog
    }

    pub fn cad(&self) -> bool {
        self.user.is_active() && self.grants.cad
    }

    pub fn ecommerce(&self) -> bool {
        self.user.is_active() && self.grants.ecommerce
    }
}

pub async fn load(pool: &SqlitePool, id: UserId) -> sqlx::Result<Option<Account>> {
    let r = sqlx::query!(
        r#"SELECT id AS "id!: i64", ip, company_name, address, city, state, zip, country_code, status AS "status!",
                  "type" AS "ty!", last_login AS "last_login!: i64", login_document_id AS "login_document_id!: i64",
                  landing_document_id AS "landing_document_id!: i64", comment, portal_id AS "portal_id!: i64",
                  customer_code, catalog AS "catalog!: i64", cad AS "cad!: i64", ecommerce AS "ecommerce!: i64",
                  price_pct AS "price_pct!: f64", language_code AS "language_code!", industry_id AS "industry_id!: i64",
                  leadscore AS "leadscore!: i64", price_type AS "price_type!", revenue AS "revenue!",
                  employees AS "employees!", domain, currency_code AS "currency_code!",
                  recaptcha_score AS "recaptcha_score!: f64"
           FROM "user" WHERE id = ?1"#,
        id.0
    )
    .fetch_optional(pool)
    .await?;
    let Some(r) = r else { return Ok(None) };
    let Some(user) = User::from_row(r.id, &r.status, &r.ty, r.company_name) else { return Ok(None) };
    Ok(Some(Account {
        user,
        ip: r.ip,
        address: r.address,
        city: r.city,
        state: r.state,
        zip: r.zip,
        country_code: r.country_code,
        last_login: r.last_login,
        login_document_id: r.login_document_id,
        landing_document_id: r.landing_document_id,
        comment: r.comment,
        portal_id: r.portal_id,
        customer_code: r.customer_code,
        grants: Grants { catalog: r.catalog != 0, cad: r.cad != 0, ecommerce: r.ecommerce != 0 },
        price_type: PriceType::parse(&r.price_type),
        price_pct: r.price_pct,
        language_code: r.language_code,
        industry_id: r.industry_id,
        leadscore: r.leadscore,
        revenue: r.revenue,
        employees: r.employees,
        domain: r.domain,
        currency_code: r.currency_code,
        recaptcha_score: r.recaptcha_score,
    }))
}

/// The account registered at this address, lowest id first, whatever its status (scs_header.php initial_user).
pub async fn at_address(pool: &SqlitePool, ip: &str) -> sqlx::Result<Option<UserId>> {
    let id = sqlx::query_scalar!(r#"SELECT id AS "id!: i64" FROM "user" WHERE ip = ?1 ORDER BY id LIMIT 1"#, ip)
        .fetch_optional(pool)
        .await?;
    Ok(id.map(UserId))
}

/// The account that owns this mail domain, lowest id first, whatever its status (classes/remote.php access()).
pub async fn for_domain(pool: &SqlitePool, domain: &str) -> sqlx::Result<Option<UserId>> {
    let id = sqlx::query_scalar!(
        r#"SELECT id AS "id!: i64" FROM "user" WHERE lower(domain) = lower(?1) ORDER BY id LIMIT 1"#,
        domain
    )
    .fetch_optional(pool)
    .await?;
    Ok(id.map(UserId))
}

pub async fn set_language(pool: &SqlitePool, id: UserId, code: &str) -> sqlx::Result<()> {
    sqlx::query!(r#"UPDATE "user" SET language_code = ?2 WHERE id = ?1"#, id.0, code).execute(pool).await?;
    Ok(())
}

/// Record a sign-in. False when this account already signed in within `window` seconds, so a caller that runs on
/// every request logs once per visit, as the PHP did once per session.
pub async fn touch_login(pool: &SqlitePool, id: UserId, now: i64, window: i64) -> sqlx::Result<bool> {
    let floor = now - window;
    let done = sqlx::query!(r#"UPDATE "user" SET last_login = ?2 WHERE id = ?1 AND last_login <= ?3"#, id.0, now, floor)
        .execute(pool)
        .await?;
    Ok(done.rows_affected() == 1)
}
