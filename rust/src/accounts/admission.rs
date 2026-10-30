//! How a person with a mail address gets in (newuser.php, the OAuth half of it, and classes/remote.php access()).
//! The sign-up form and Microsoft and Google sign-in share this: classify the address, then either sign the person
//! in to the company that owns the domain or record a Pending account for staff to approve.
//!
//! A `remote` row still marks an address that has had remote access, so staff see the same list and the
//! auto-approved notice goes out once per address. It holds no token or code any more: the session table and
//! `access_code` do that work.

use axum::http::HeaderValue;
use sqlx::SqlitePool;

use crate::{
    accounts::{
        account::{self, Account},
        clock, cookies,
        html::escape,
        log::{self, Kind, Subject},
        registry, Services,
    },
    identity::{Email, Status, UserId},
    session,
    token::Token,
};

#[derive(Debug, Clone, PartialEq)]
pub enum Admission {
    /// The domain is on the staff blacklist.
    Blacklisted,
    /// An Active company owns the domain. `known` when this address has had access before.
    Company { company: Box<Account>, known: bool },
    /// The company that owns the domain is waiting for approval.
    Pending,
    /// No company owns the domain, or the one that does is Denied.
    Unknown,
}

/// What a request contributes to log rows and staff notices.
pub struct Context<'a> {
    pub ip: &'a str,
    pub user_agent: &'a str,
    /// Forward-confirmed reverse DNS of the client; the notices print the address when there is none.
    pub dns_name: Option<&'a str>,
    pub now: i64,
}

pub async fn classify(pool: &SqlitePool, email: &Email) -> sqlx::Result<Admission> {
    if blacklisted(pool, email).await? {
        return Ok(Admission::Blacklisted);
    }
    let Some(id) = account::for_domain(pool, email.domain()).await? else { return Ok(Admission::Unknown) };
    let Some(company) = account::load(pool, id).await? else { return Ok(Admission::Unknown) };
    Ok(match company.status() {
        Status::Active => {
            let known = remote_known(pool, email).await?;
            Admission::Company { company: Box::new(company), known }
        }
        Status::Pending => Admission::Pending,
        Status::Denied => Admission::Unknown,
    })
}

/// classes/blacklist.php check(): the address's domain, lower-cased, is listed.
pub async fn blacklisted(pool: &SqlitePool, email: &Email) -> sqlx::Result<bool> {
    let domain = email.domain();
    Ok(sqlx::query_scalar!(r#"SELECT 1 AS "x!: i64" FROM blacklist WHERE lower(domain) = ?1 LIMIT 1"#, domain)
        .fetch_optional(pool)
        .await?
        .is_some())
}

/// classes/captcha_bypass.php check(): the address itself or `@domain` is listed, so reCAPTCHA is not asked.
pub async fn captcha_bypassed(pool: &SqlitePool, email: &Email) -> sqlx::Result<bool> {
    let address = email.as_str();
    let at_domain = format!("@{}", email.domain());
    Ok(sqlx::query_scalar!(r#"SELECT 1 AS "x!: i64" FROM captcha_bypass WHERE lower(email) IN (?1, ?2) LIMIT 1"#, address, at_domain)
        .fetch_optional(pool)
        .await?
        .is_some())
}

pub async fn log_blacklisted(pool: &SqlitePool, email: &Email, ctx: &Context<'_>, via: &str) {
    let who = Subject { ip: ctx.ip, email: Some(email.as_str()), ..Default::default() };
    log::record(pool, Kind::BlacklistDenied, &who, &format!("Blacklisted domain denied ({via})")).await;
}

async fn remote_known(pool: &SqlitePool, email: &Email) -> sqlx::Result<bool> {
    let address = email.as_str();
    Ok(sqlx::query_scalar!(r#"SELECT 1 AS "x!: i64" FROM remote WHERE lower(email) = ?1"#, address).fetch_optional(pool).await?.is_some())
}

/// Mark the address as having remote access, with the expiry staff see in the remote list.
pub async fn remember_address(pool: &SqlitePool, email: &Email, now: i64) -> sqlx::Result<()> {
    let expiry = now + remote_days(pool).await? * 86400;
    let address = email.as_str();
    sqlx::query!(
        "INSERT INTO remote (email, expiry, token, unlock_code) VALUES (?1, ?2, '', '')
         ON CONFLICT (email) DO UPDATE SET expiry = excluded.expiry, token = '', unlock_code = ''",
        address,
        expiry
    )
    .execute(pool)
    .await?;
    Ok(())
}

/// registry local.remote_days: how long a remote sign-in lasts without use. Missing means none is remembered.
pub async fn remote_days(pool: &SqlitePool) -> sqlx::Result<i64> {
    Ok(registry::number(pool, "local", "remote_days").await?.map_or(0, |d| d.max(0.0) as i64))
}

/// The person proved this address at an Active company: a sign-in to the company, remembered for remote_days
/// (scs_header.php login_update(1)).
pub async fn grant(pool: &SqlitePool, company: &Account, email: &Email, ctx: &Context<'_>) -> sqlx::Result<SignIn> {
    let renew = remote_days(pool).await? * 86400;
    let token = if renew > 0 {
        session::create_remembered(pool, company.id(), Some(email.as_str()), ctx.now, renew).await?
    } else {
        session::create(pool, company.id(), Some(email.as_str()), ctx.now).await?
    };
    remember_address(pool, email, ctx.now).await?;
    let id = company.id().0;
    sqlx::query!(r#"UPDATE "user" SET last_login = ?2 WHERE id = ?1"#, id, ctx.now).execute(pool).await?;
    let who = Subject { ip: ctx.ip, user_id: company.id().0, email: Some(email.as_str()), ..Default::default() };
    let domain = company.domain.as_deref().unwrap_or("");
    log::record(pool, Kind::UserRemoteLogin, &who, &format!("Domain: {domain} IP Address {}", ctx.ip)).await;
    Ok(SignIn { token, remembered: renew > 0, email: email.clone() })
}

/// A sign-in that the response must hand to the browser.
pub struct SignIn {
    pub token: Token,
    pub remembered: bool,
    pub email: Email,
}

impl SignIn {
    /// The session cookie and the remembered address, as the PHP set `remote` and `harcourt`.
    pub fn cookies(&self) -> Vec<HeaderValue> {
        cookies::session(&self.token, self.remembered).into_iter().chain(cookies::set(cookies::EMAIL, self.email.as_str(), cookies::Life::Year)).collect()
    }
}

/// What the sign-up form or an OAuth profile says about the company asking for access.
#[derive(Debug, Default, Clone)]
pub struct Registration {
    pub company_name: String,
    pub address: String,
    pub city: String,
    pub state: String,
    pub zip: String,
    pub country_code: String,
    /// Set from the mail domain for OAuth sign-ups, so approving the account lets the domain in.
    pub domain: Option<String>,
    pub recaptcha_score: f64,
}

/// A Pending account for staff to approve, signed in as itself so the visitor sees "pending" (newuser.php
/// json_register_update and process_oauth_register). `remembered` keeps the sign-in like an OAuth grant.
/// A new account at the visitor's address. With IPStack on, its location and currency are looked up afterwards, as
/// user->update() did for every new user, without holding up the sign-up.
pub async fn register(pool: &SqlitePool, services: &Services, reg: &Registration, email: &Email, remembered: bool, ctx: &Context<'_>) -> sqlx::Result<(UserId, SignIn)> {
    let none_if_empty = |s: &str| (!s.is_empty()).then(|| s.to_string());
    let (address, city, state, zip, country) =
        (none_if_empty(&reg.address), none_if_empty(&reg.city), none_if_empty(&reg.state), none_if_empty(&reg.zip), none_if_empty(&reg.country_code));
    let id = sqlx::query_scalar!(
        r#"INSERT INTO "user" (ip, company_name, address, city, state, zip, country_code, last_login, recaptcha_score, domain)
           VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10) RETURNING id AS "id!: i64""#,
        ctx.ip,
        reg.company_name,
        address,
        city,
        state,
        zip,
        country,
        ctx.now,
        reg.recaptcha_score,
        reg.domain
    )
    .fetch_one(pool)
    .await?;
    let id = UserId(id);
    if services.ipstack {
        let (pool, ip) = (pool.clone(), ctx.ip.to_string());
        tokio::spawn(async move {
            if let Err(e) = crate::staff::user::ipstack(&pool, id.0, &ip).await {
                tracing::warn!("IPStack for account {}: {e}", id.0);
            }
        });
    }
    let renew = if remembered { remote_days(pool).await? * 86400 } else { 0 };
    let token = if renew > 0 {
        remember_address(pool, email, ctx.now).await?;
        session::create_remembered(pool, id, Some(email.as_str()), ctx.now, renew).await?
    } else {
        session::create(pool, id, Some(email.as_str()), ctx.now).await?
    };
    Ok((id, SignIn { token, remembered: renew > 0, email: email.clone() }))
}

pub async fn log_signup(pool: &SqlitePool, id: UserId, ctx: &Context<'_>, comment: &str) {
    let who = Subject { ip: ctx.ip, user_id: id.0, ..Default::default() };
    log::record(pool, Kind::UserSignup, &who, comment).await;
}

/// A notice to the staff address of the `register` mail form (scsmail_class("register")), queued in `outbox`.
/// `lines` are already HTML; build them with `escape` around anything a visitor or a provider supplied.
pub async fn notify_staff(pool: &SqlitePool, subject: Option<&str>, lines: &[String], now: i64) -> sqlx::Result<()> {
    let form = sqlx::query!(r#"SELECT recipient AS "recipient!", subject FROM mailform WHERE code = 'register' AND active = 1 ORDER BY domain = '' DESC LIMIT 1"#)
        .fetch_optional(pool)
        .await?;
    let Some(form) = form.filter(|f| !f.recipient.trim().is_empty()) else {
        tracing::warn!("no active `register` mail form: staff notice not queued");
        return Ok(());
    };
    let subject = subject.map(str::to_string).or(form.subject).unwrap_or_default();
    let body = format!("<p class='standard'>{}</p>", lines.join("<br>"));
    let to = form.recipient.trim();
    sqlx::query!("INSERT INTO outbox (to_email, subject, body, created_at, content_type) VALUES (?1, ?2, ?3, ?4, 'html')", to, subject, body, now)
        .execute(pool)
        .await?;
    Ok(())
}

/// The lines every staff notice ends with.
pub fn request_lines(ctx: &Context<'_>) -> Vec<String> {
    vec![
        format!("Browser: {}", escape(ctx.user_agent)),
        format!("IP Address: {}", escape(ctx.ip)),
        format!("DNS Name: {}", escape(ctx.dns_name.unwrap_or(ctx.ip))),
        format!("Date/Time: {}", clock::detroit(ctx.now).mdy_hm_ampm()),
    ]
}

/// newuser.php auto_approved_email(): first access for an address at an Active company, which nobody approved.
pub async fn notify_auto_approved(pool: &SqlitePool, company: &Account, email: &Email, method: &str, ctx: &Context<'_>) -> sqlx::Result<()> {
    let mut lines = vec![
        "<b>A new user was auto-approved because their email domain matches an active account.</b>".to_string(),
        String::new(),
        format!("Email: {}", escape(email.as_str())),
        format!(
            "Company: {} ({})",
            escape(company.user.company_name.as_deref().unwrap_or("")),
            escape(company.domain.as_deref().unwrap_or(""))
        ),
        format!("Method: {}", escape(method)),
        String::new(),
    ];
    lines.extend(request_lines(ctx));
    notify_staff(pool, Some(&format!("New Account Auto-Approved: {}", email.as_str())), &lines, ctx.now).await
}
