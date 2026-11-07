//! The whole `user` row (classes/user.php user_data_class): a company account and what it may see. `identity::User`
//! is the part sign-in decisions need; `Account` adds everything a page reads.

use sqlx::{SqliteExecutor, SqlitePool};

use crate::identity::{is_free_mail, Email, Role, Status, User, UserId};

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

/// The account an address belongs to (classes/remote.php access()): one that owns its company domain (never a
/// free-mail one), or the one that address signed up as (`signup_email`), so a request not yet approved, which has no
/// domain, is found again. Denied accounts belong to no one. Of several, an Active one, then a Pending one; then the
/// domain's owner; then the lowest id.
pub async fn for_email(db: impl SqliteExecutor<'_>, email: &Email) -> sqlx::Result<Option<UserId>> {
    let (domain, address) = (email.company_domain(), email.as_str());
    let id = sqlx::query_scalar!(
        r#"SELECT id AS "id!: i64" FROM "user"
           WHERE (lower(domain) = lower(?1) OR signup_email = ?2) AND status IN ('Active', 'Pending')
           ORDER BY status <> 'Active', coalesce(lower(domain) = lower(?1), 0) DESC, id LIMIT 1"#,
        domain,
        address
    )
    .fetch_optional(db)
    .await?;
    Ok(id.map(UserId))
}

/// The address the account signed up with (none when blank), recorded at registration (and, for accounts older
/// than that column, by migration 0108 and the import from their latest sign-up log row). Log rows are never read here: their user id
/// can belong to an account deleted before this one took the id.
pub async fn signup_email(db: impl SqliteExecutor<'_>, id: UserId) -> sqlx::Result<Option<String>> {
    let email = sqlx::query_scalar!(
        r#"SELECT signup_email AS "email?: String" FROM "user" WHERE id = ?1"#,
        id.0
    )
    .fetch_optional(db)
    .await?;
    Ok(email.flatten().filter(|e| !e.trim().is_empty()))
}

/// Record on each account without one the address of its latest sign-up log row, as migration 0108 did for the
/// accounts that existed then. For a file filled after the migrations ran. Returns how many were filled.
pub async fn fill_signup_emails(pool: &SqlitePool) -> sqlx::Result<u64> {
    let done = sqlx::query!(
        r#"UPDATE "user" SET signup_email = (
               SELECT lower(trim(email)) FROM log
               WHERE log.user_id = "user".id AND type = 'User' AND subtype = 'Signup' AND trim(coalesce(email, '')) <> ''
               ORDER BY date_time DESC, id DESC LIMIT 1)
           WHERE signup_email IS NULL
             AND EXISTS (SELECT 1 FROM log WHERE log.user_id = "user".id AND type = 'User' AND subtype = 'Signup' AND trim(coalesce(email, '')) <> '')"#
    )
    .execute(pool)
    .await?;
    Ok(done.rows_affected())
}

/// Clear every account's domain that names a free-mail provider (`identity::is_free_mail`), which can name no
/// company. Returns how many were cleared.
pub async fn clear_free_mail_domains(pool: &SqlitePool) -> sqlx::Result<u64> {
    let rows = sqlx::query!(r#"SELECT id AS "id!: i64", domain AS "domain!" FROM "user" WHERE domain IS NOT NULL"#).fetch_all(pool).await?;
    let mut tx = pool.begin().await?;
    let mut cleared = 0;
    for r in rows.iter().filter(|r| is_free_mail(&r.domain)) {
        cleared += sqlx::query!(r#"UPDATE "user" SET domain = NULL WHERE id = ?1"#, r.id).execute(&mut *tx).await?.rows_affected();
    }
    tx.commit().await?;
    Ok(cleared)
}

pub async fn set_language(pool: &SqlitePool, id: UserId, code: &str) -> sqlx::Result<()> {
    sqlx::query!(r#"UPDATE "user" SET language_code = ?2 WHERE id = ?1"#, id.0, code).execute(pool).await?;
    Ok(())
}

/// Record a sign-in at the start of a visit, as login_update() did at the start of every PHP session. False when
/// the account is gone.
pub async fn touch_login(pool: &SqlitePool, id: UserId, now: i64) -> sqlx::Result<bool> {
    let done = sqlx::query!(r#"UPDATE "user" SET last_login = ?2 WHERE id = ?1"#, id.0, now).execute(pool).await?;
    Ok(done.rows_affected() == 1)
}
