//! Who is asking, for every page. `Visitor` is the one way a handler learns it. Pages never read cookies, the
//! session table or the client address themselves; they take a `Visitor` argument.
//!
//! The first of these that applies decides (scs_header.php menu_class::initial):
//!
//!  1. A live session cookie: an account signed in by emailed code, OAuth or a grant. An Administrator's session
//!     may carry an impersonation, which replaces what the visitor looks like and never shows a role above the
//!     Administrator's own.
//!  2. Sign-in by company address: the account whose `ip` is the client address. The client address is the socket
//!     peer, or X-Real-IP when the peer is one of our proxies (`api::client_addr`); no other header is believed,
//!     and a proxy or loopback address never signs anyone in.
//!  3. A bot: the forward-confirmed reverse DNS name of the client address matches a `bot.pattern`.
//!  4. Anonymous.
//!
//! What other areas can rely on:
//!
//!  * `identity`: `Anonymous`, `Bot(Bot)` or `Account(SignedIn)`. `SignedIn.account` is the whole `user` row
//!    (`accounts::account::Account`): company_name, address fields, portal_id, industry_id, customer_code,
//!    price_type and price_pct (a fraction), currency_code, language_code, domain. `account.catalog()`, `.cad()`
//!    and `.ecommerce()` say what the visitor may use now (false unless the account is Active); `account.grants`
//!    is what is stored. The status may be Pending or Denied.
//!  * `language`: the language to show when the URL does not say. An explicit choice this browser session, else
//!    the account's saved language, else Accept-Language, else English.
//!  * `currency`: the currency to price in: the account's, if the site offers it, else US dollars.
//!  * `campaign`: the active campaign this browser arrived from (`?campaign=CODE`, kept for the browser session).
//!  * `impersonator`: who is viewing the site as this visitor, while an Administrator impersonates.
//!  * `client`: the client address.
//!  * `session`: the stored session this browser signed in with, for per-browser state such as the cart.
//!
//! The shortcuts `user()`, `account()`, `bot()`, `role()`, `at_least()`, `catalog()`, `cad()`, `ecommerce()`,
//! `portal_id()` and `industry_id()` cover the common questions.
//!
//! `layer` resolves the visitor once per request for the page routers and writes what the PHP wrote when a visit
//! began: the sign-in log for a company address, the campaign cookie, the referer and bot log rows.

use std::{convert::Infallible, net::IpAddr, sync::Arc};

use axum::{
    extract::{FromRequestParts, Request, State},
    http::{header, request::Parts, HeaderMap, HeaderName, StatusCode},
    middleware::Next,
    response::{IntoResponse, Response},
};
use percent_encoding::percent_decode_str;
use sqlx::SqlitePool;

use crate::{
    accounts::{
        account::{self, Account, Grants, PriceType},
        bot::{self, Bot, BotStatus},
        campaign::{self, Campaign},
        clock, cookies,
        locale::{Currencies, CurrencyCode, LanguageCode, Languages},
        log::{self, Kind, Subject},
        profile::{self, Profile},
    },
    api::{self, Auth},
    db::unix_now,
    identity::{Email, Role, Status, User, UserId},
    session,
    token::TokenHash,
};

#[derive(Debug, Clone)]
pub struct Visitor {
    pub identity: Identity,
    pub language: LanguageCode,
    pub currency: CurrencyCode,
    pub campaign: Option<Campaign>,
    pub impersonator: Option<Impersonator>,
    pub client: Option<IpAddr>,
    /// The session this browser is signed in with, as stored: what per-browser state (the cart) hangs off. None
    /// for anonymous visitors, bots and sign-in by company address.
    pub session: Option<TokenHash>,
}

#[derive(Debug, Clone)]
pub enum Identity {
    Anonymous,
    Bot(Bot),
    Account(Box<SignedIn>),
}

#[derive(Debug, Clone)]
pub struct SignedIn {
    pub account: Account,
    /// The person, when they proved an address at the company. None for a company address or an impersonation.
    pub email: Option<Email>,
    pub via: Via,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Via {
    Session,
    CompanyAddress,
}

#[derive(Debug, Clone)]
pub struct Impersonator {
    pub id: UserId,
    pub email: Option<Email>,
    /// The impersonation form's "Legacy?" box, read by the legacy portal view.
    pub portal_legacy: bool,
}

impl Visitor {
    pub fn anonymous() -> Visitor {
        Visitor {
            identity: Identity::Anonymous,
            language: LanguageCode::english(),
            currency: CurrencyCode::usd(),
            campaign: None,
            impersonator: None,
            client: None,
            session: None,
        }
    }

    pub fn signed_in(&self) -> Option<&SignedIn> {
        match &self.identity {
            Identity::Account(s) => Some(s),
            _ => None,
        }
    }

    pub fn account(&self) -> Option<&Account> {
        self.signed_in().map(|s| &s.account)
    }

    pub fn user(&self) -> Option<&User> {
        self.account().map(|a| &a.user)
    }

    pub fn bot(&self) -> Option<&Bot> {
        match &self.identity {
            Identity::Bot(b) => Some(b),
            _ => None,
        }
    }

    /// As the PHP had it: an anonymous visitor or a bot counts as a Customer. Use `account()` to ask whether
    /// someone is signed in.
    pub fn role(&self) -> Role {
        self.user().map_or(Role::Customer, |u| u.role)
    }

    /// classes/user.php access($role, FALSE).
    pub fn at_least(&self, role: Role) -> bool {
        self.role() >= role
    }

    /// A bot sees the catalog when its row says so, whatever its status, as classes/bot.php session() set it.
    pub fn catalog(&self) -> bool {
        match &self.identity {
            Identity::Account(s) => s.account.catalog(),
            Identity::Bot(b) => b.catalog,
            Identity::Anonymous => false,
        }
    }

    pub fn cad(&self) -> bool {
        self.account().is_some_and(Account::cad)
    }

    pub fn ecommerce(&self) -> bool {
        self.account().is_some_and(Account::ecommerce)
    }

    pub fn portal_id(&self) -> i64 {
        match &self.identity {
            Identity::Account(s) => s.account.portal_id,
            Identity::Bot(b) => b.portal_id,
            Identity::Anonymous => 0,
        }
    }

    pub fn industry_id(&self) -> i64 {
        self.account().map_or(0, |a| a.industry_id)
    }
}

impl FromRequestParts<Arc<Auth>> for Visitor {
    type Rejection = Infallible;

    async fn from_request_parts(parts: &mut Parts, core: &Arc<Auth>) -> Result<Self, Infallible> {
        if let Some(v) = parts.extensions.get::<Visitor>() {
            return Ok(v.clone());
        }
        Ok(resolve_or_anonymous(core, parts, unix_now()).await.visitor)
    }
}

/// What a visit began with that the PHP wrote down. Applied by `layer`, never by the extractor alone.
#[derive(Debug)]
enum Effect {
    CompanyLogin(UserId),
    Bot(Bot),
    Campaign(Option<Campaign>),
    Referer(String),
}

pub struct Resolved {
    pub visitor: Visitor,
    effects: Vec<Effect>,
}

/// A browser at the same company address counts as one visit for this long (PHP's default session lifetime).
const VISIT_SECS: i64 = 1440;

async fn resolve_or_anonymous(core: &Auth, parts: &Parts, now: i64) -> Resolved {
    match resolve(core, parts, now).await {
        Ok(r) => r,
        Err(e) => {
            tracing::error!("visitor: database error, treating the request as anonymous: {e}");
            Resolved { visitor: Visitor::anonymous(), effects: Vec::new() }
        }
    }
}

pub async fn resolve(core: &Auth, parts: &Parts, now: i64) -> sqlx::Result<Resolved> {
    let pool = &core.pool;
    let client = api::client_addr(parts, &core.trusted_proxies);
    let mut effects = Vec::new();
    let mut impersonator = None;
    let mut overlay_campaign = None;
    let mut overlay_language = None;
    let mut overlay_currency = None;

    let mut identity = Identity::Anonymous;
    let mut session = None;
    if let Some(token) = api::session_cookie(&parts.headers) {
        if let Some(found) = session::lookup(pool, &token, now).await? {
            session = Some(token.hash());
            let email = found.email.as_deref().and_then(Email::parse);
            if let Some(view) = impersonation(pool, &token, &found.user).await? {
                impersonator = Some(Impersonator { id: found.user.id, email: email.clone(), portal_legacy: view.portal_legacy });
                overlay_campaign = Some(view.campaign_code.clone());
                overlay_language = Some(view.language_code.clone());
                overlay_currency = Some(view.currency_code.clone());
                identity = match view.user_id {
                    Some(id) => match account::load(pool, id).await? {
                        Some(account) => Identity::Account(Box::new(SignedIn { account: view.apply(account), email: None, via: Via::Session })),
                        None => Identity::Anonymous,
                    },
                    None => Identity::Anonymous,
                };
            } else if let Some(account) = account::load(pool, found.user.id).await? {
                identity = Identity::Account(Box::new(SignedIn { account, email, via: Via::Session }));
            }
        }
    }
    let has_session = impersonator.is_some() || matches!(identity, Identity::Account(_));

    if !has_session {
        if let Some(ip) = client.filter(|ip| may_sign_in_by_address(*ip, &core.trusted_proxies)) {
            if let Some(id) = account::at_address(pool, &ip.to_string()).await? {
                if let Some(account) = account::load(pool, id).await? {
                    effects.push(Effect::CompanyLogin(id));
                    identity = Identity::Account(Box::new(SignedIn { account, email: None, via: Via::CompanyAddress }));
                }
            }
        }
    }
    if !has_session && matches!(identity, Identity::Anonymous) {
        if let Some(ip) = client {
            if let Some(b) = bot::identify(pool, &core.accounts.dns, ip).await? {
                effects.push(Effect::Bot(b.clone()));
                identity = Identity::Bot(b);
            }
        }
    }

    let languages = Languages::load(pool).await?;
    let language = overlay_language
        .and_then(|code| languages.get(&code))
        .or_else(|| cookies::get(&parts.headers, cookies::LANGUAGE).and_then(|c| languages.get(&c)))
        .or_else(|| match &identity {
            Identity::Account(s) => languages.get(&s.account.language_code),
            _ => None,
        })
        .unwrap_or_else(|| languages.from_accept_language(header_str(&parts.headers, header::ACCEPT_LANGUAGE)));

    let currencies = Currencies::load(pool).await?;
    let currency = overlay_currency
        .or_else(|| match &identity {
            Identity::Account(s) => Some(s.account.currency_code.clone()),
            _ => None,
        })
        .and_then(|code| currencies.get(&code))
        .unwrap_or_else(CurrencyCode::usd);

    let today = clock::detroit(now).date;
    let campaign = match (overlay_campaign, campaign_in_query(parts.uri.query())) {
        (Some(code), _) => match code {
            Some(code) => campaign::active(pool, &code, today).await?,
            None => None,
        },
        (None, Some(code)) => {
            let found = campaign::active(pool, &code, today).await?;
            effects.push(Effect::Campaign(found.clone()));
            found
        }
        (None, None) => match cookies::get(&parts.headers, cookies::CAMPAIGN) {
            Some(code) => campaign::active(pool, &code, today).await?,
            None => None,
        },
    };

    if let Some(host) = external_referer(header_str(&parts.headers, header::REFERER)) {
        effects.push(Effect::Referer(host));
    }
    if impersonator.is_some() {
        effects.clear();
    }

    Ok(Resolved { visitor: Visitor { identity, language, currency, campaign, impersonator, client, session }, effects })
}

/// What PHP labelled every answer it gave, redirects included.
pub const PHP_CONTENT_TYPE: &str = "text/html; charset=UTF-8";

/// A request that arrives from outside the site begins a visit. The PHP started a session on it and sent the
/// visitor to their home page unless they had asked for it (scs_header.php menu_class::page(TRUE)). A request
/// from one of the site's own pages, a script on them, or a browser that still has a PHP session is mid-visit.
pub fn begins_visit(headers: &HeaderMap, public_origin: &str) -> bool {
    let from_page = header_str(headers, header::REFERER).strip_prefix(public_origin).is_some_and(|rest| rest.starts_with('/'));
    let same_origin = header_str(headers, HeaderName::from_static("sec-fetch-site")) == "same-origin";
    let script = headers.contains_key("x-requested-with");
    let php_session = cookies::get(headers, "PHPSESSID").is_some();
    !(from_page || same_origin || script || php_session)
}

/// Where scs_header.php sent a visit that began on an SCS page: the dashboard for Executives and above, the
/// catalog for an Active account that can see a category, otherwise the home page. Inside the WordPress frame
/// (`?scscpq_wp=1`) home is `/` and the catalog is the WordPress catalog page in the account's language.
async fn landing_target(pool: &SqlitePool, visitor: &Visitor, framed: bool) -> sqlx::Result<&'static str> {
    if visitor.user().is_some_and(|u| u.role >= Role::Executive) {
        return Ok("/dashboard.php");
    }
    let home = if framed { "/" } else { "/scs_index.php" };
    let Some(account) = visitor.account().filter(|a| a.user.is_active()) else { return Ok(home) };
    let with_pending = account.role() >= Role::Administrator;
    let industry = (account.industry_id != 0).then(|| format!("%^{}^%", account.industry_id));
    let portal = (account.portal_id != 0).then(|| format!("%^{}^%", account.portal_id));
    let filtered = industry.is_some() || portal.is_some();
    let visible = sqlx::query_scalar!(
        r#"SELECT EXISTS (SELECT 1 FROM subcategory
               WHERE (status = 'Active' OR (?1 AND status = 'Pending'))
                 AND (NOT ?2 OR industry LIKE ?3 OR portal LIKE ?4)) AS "visible!: bool""#,
        with_pending,
        filtered,
        industry,
        portal
    )
    .fetch_one(pool)
    .await?;
    Ok(match (visible, framed, account.language_code.eq_ignore_ascii_case("FR")) {
        (false, _, _) => home,
        (true, false, _) => "/catalog.php",
        (true, true, true) => "/catalogue/",
        (true, true, false) => "/catalog/",
    })
}

/// scs_header.php's landing redirect for an SCS page at `path`, when this request begins a visit. Campaign
/// visitors and bots were never redirected. The PHP wrote the browser's profile cookie on the way.
pub async fn landing(core: &Auth, visitor: &Visitor, path: &str, query: Option<&str>, headers: &HeaderMap) -> sqlx::Result<Option<Response>> {
    if !begins_visit(headers, &core.public_origin) || visitor.campaign.is_some() || visitor.bot().is_some() || visitor.impersonator.is_some() {
        return Ok(None);
    }
    let framed = query.unwrap_or("").split('&').any(|kv| kv.split_once('=').is_some_and(|(k, v)| k == "scscpq_wp" && !v.is_empty() && v != "0"));
    let target = landing_target(&core.pool, visitor, framed).await?;
    if target.to_ascii_lowercase().starts_with(&path.to_ascii_lowercase()) {
        return Ok(None);
    }
    let profile = Profile::load(headers, visitor.account(), visitor.account().map_or(visitor.language.as_str(), |a| a.language_code.as_str()));
    let mut r = (StatusCode::FOUND, [(header::LOCATION, target), (header::CONTENT_TYPE, PHP_CONTENT_TYPE)]).into_response();
    if let Some(c) = cookies::set(profile::COOKIE, &profile.to_cookie(), cookies::Life::Year) {
        r.headers_mut().append(header::SET_COOKIE, c);
    }
    Ok(Some(r))
}

fn may_sign_in_by_address(ip: IpAddr, trusted_proxies: &[IpAddr]) -> bool {
    !ip.is_loopback() && !ip.is_unspecified() && !trusted_proxies.contains(&ip)
}

fn header_str(headers: &HeaderMap, name: header::HeaderName) -> &str {
    headers.get(name).and_then(|v| v.to_str().ok()).unwrap_or("")
}

/// The value of the first query parameter whose name is `campaign` in any case, as scs_header.php read $_GET.
fn campaign_in_query(query: Option<&str>) -> Option<String> {
    query?
        .split('&')
        .filter_map(|kv| kv.split_once('=').or(Some((kv, ""))))
        .find(|(k, _)| k.eq_ignore_ascii_case("campaign"))
        .map(|(_, v)| percent_decode_str(&v.replace('+', " ")).decode_utf8_lossy().into_owned())
}

/// The referring host without `www.`, when it is not this site. The PHP test was `/harcourt.co/i` on the host,
/// whose `.` matches any character.
fn external_referer(referer: &str) -> Option<String> {
    let rest = referer.split_once("://")?.1;
    let host = rest.split(['/', '?', '#']).next()?.rsplit('@').next()?;
    let host = host.split(':').next()?;
    if host.is_empty() {
        return None;
    }
    let lower = host.to_ascii_lowercase();
    let b = lower.as_bytes();
    let ours = b.windows(11).any(|w| w.starts_with(b"harcourt") && w.ends_with(b"co"));
    if ours {
        return None;
    }
    let stripped = if lower.starts_with("www.") { &host[4..] } else { host };
    Some(stripped.to_string())
}

struct View {
    user_id: Option<UserId>,
    role: Role,
    status: Status,
    grants: Grants,
    price_type: PriceType,
    price_pct: f64,
    industry_id: i64,
    portal_id: i64,
    portal_legacy: bool,
    currency_code: String,
    language_code: String,
    campaign_code: Option<String>,
}

impl View {
    fn apply(&self, mut a: Account) -> Account {
        a.user.role = self.role;
        a.user.status = self.status;
        a.grants = self.grants;
        a.price_type = self.price_type;
        a.price_pct = self.price_pct;
        a.industry_id = self.industry_id;
        a.portal_id = self.portal_id;
        a.currency_code = self.currency_code.clone();
        a.language_code = self.language_code.clone();
        a
    }
}

/// The impersonation on this session, if the session's own account may still impersonate. The stored role is
/// capped at the Administrator's current role, so a demotion takes effect at once.
async fn impersonation(pool: &SqlitePool, token: &crate::token::Token, actor: &User) -> sqlx::Result<Option<View>> {
    if !actor.is_active() || actor.role < Role::Administrator {
        return Ok(None);
    }
    let hash = token.hash();
    let hash = hash.as_slice();
    let row = sqlx::query!(
        r#"SELECT user_id, "type" AS "ty!", status AS "status!", catalog AS "catalog!: i64", cad AS "cad!: i64",
                  ecommerce AS "ecommerce!: i64", price_type AS "price_type!", price_pct AS "price_pct!: f64",
                  industry_id AS "industry_id!: i64", portal_id AS "portal_id!: i64", portal_legacy AS "portal_legacy!: i64",
                  currency_code AS "currency_code!", language_code AS "language_code!", campaign_code
           FROM impersonation WHERE session_hash = ?1"#,
        hash
    )
    .fetch_optional(pool)
    .await?;
    let Some(r) = row else { return Ok(None) };
    let (Some(role), Some(status)) = (Role::parse(&r.ty), Status::parse(&r.status)) else { return Ok(None) };
    Ok(Some(View {
        user_id: r.user_id.map(UserId),
        role: role.min(actor.role),
        status,
        grants: Grants { catalog: r.catalog != 0, cad: r.cad != 0, ecommerce: r.ecommerce != 0 },
        price_type: PriceType::parse(&r.price_type),
        price_pct: r.price_pct,
        industry_id: r.industry_id,
        portal_id: r.portal_id,
        portal_legacy: r.portal_legacy != 0,
        currency_code: r.currency_code,
        language_code: r.language_code,
        campaign_code: r.campaign_code,
    }))
}

/// Middleware for the page routers: resolve the visitor once, hand it to the handler, and write what a visit
/// beginning wrote in the PHP. A Denied bot is sent to the home page, as classes/bot.php session() did.
pub async fn layer(State(core): State<Arc<Auth>>, req: Request, next: Next) -> Response {
    let now = unix_now();
    let (mut parts, body) = req.into_parts();
    let Resolved { visitor, effects } = resolve_or_anonymous(&core, &parts, now).await;
    let mut set_cookies = Vec::new();
    for effect in &effects {
        apply(&core.pool, &visitor, effect, now, &mut set_cookies).await;
    }
    let denied_bot = visitor.bot().is_some_and(|b| b.status == BotStatus::Denied);
    parts.extensions.insert(visitor);
    let req = Request::from_parts(parts, body);
    let mut response =
        if denied_bot { (StatusCode::FOUND, [(header::LOCATION, "/index.php"), (header::CONTENT_TYPE, PHP_CONTENT_TYPE)]).into_response() } else { next.run(req).await };
    for c in set_cookies {
        response.headers_mut().append(header::SET_COOKIE, c);
    }
    response
}

async fn apply(pool: &SqlitePool, visitor: &Visitor, effect: &Effect, now: i64, set_cookies: &mut Vec<axum::http::HeaderValue>) {
    let ip = visitor.client.map(|ip| ip.to_string()).unwrap_or_default();
    let who = Subject {
        ip: &ip,
        user_id: visitor.user().map_or(0, |u| u.id.0),
        bot_id: visitor.bot().map_or(0, |b| b.id),
        email: visitor.signed_in().and_then(|s| s.email.as_ref()).map(Email::as_str),
    };
    match effect {
        Effect::CompanyLogin(id) => match account::touch_login(pool, *id, now, VISIT_SECS).await {
            Ok(true) => log::record(pool, Kind::UserLogin, &who, "").await,
            Ok(false) => {}
            Err(e) => tracing::warn!("last_login update failed: {e}"),
        },
        Effect::Bot(b) => {
            let kind = if b.status == BotStatus::Active { Kind::BotActive } else { Kind::BotDenied };
            log::record(pool, kind, &who, "").await;
        }
        Effect::Campaign(found) => {
            let cookie = match found {
                Some(c) => cookies::set(cookies::CAMPAIGN, &c.code, cookies::Life::Browser),
                None => cookies::set(cookies::CAMPAIGN, "", cookies::Life::Gone),
            };
            set_cookies.extend(cookie);
            if let Some(c) = found {
                log::record(pool, Kind::InitialCampaign, &who, &c.code).await;
            }
        }
        Effect::Referer(host) => log::record(pool, Kind::InitialReferer, &who, host).await,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn campaign_is_read_from_any_case_of_the_name() {
        assert_eq!(campaign_in_query(Some("a=1&Campaign=SPRING%202026&b=2")).as_deref(), Some("SPRING 2026"));
        assert_eq!(campaign_in_query(Some("campaigns=x")), None);
        assert_eq!(campaign_in_query(Some("CAMPAIGN")).as_deref(), Some(""));
        assert_eq!(campaign_in_query(None), None);
    }

    #[test]
    fn referers_from_this_site_are_not_external() {
        assert_eq!(external_referer("https://www.google.com/search?q=x").as_deref(), Some("google.com"));
        assert_eq!(external_referer("https://WWW.Bing.com:443/").as_deref(), Some("Bing.com"));
        assert_eq!(external_referer("https://www.harcourt.co/catalog/"), None);
        assert_eq!(external_referer("https://stage2.harcourt.co/"), None);
        assert_eq!(external_referer("https://harcourtXco.example/"), None);
        assert_eq!(external_referer(""), None);
        assert_eq!(external_referer("not a url"), None);
    }

    #[test]
    fn proxies_and_loopback_never_sign_in_by_address() {
        let proxies: Vec<IpAddr> = vec!["10.0.0.5".parse().unwrap()];
        assert!(!may_sign_in_by_address("127.0.0.1".parse().unwrap(), &proxies));
        assert!(!may_sign_in_by_address("::1".parse().unwrap(), &proxies));
        assert!(!may_sign_in_by_address("0.0.0.0".parse().unwrap(), &proxies));
        assert!(!may_sign_in_by_address("10.0.0.5".parse().unwrap(), &proxies));
        assert!(may_sign_in_by_address("203.0.113.9".parse().unwrap(), &proxies));
    }
}
