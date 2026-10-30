//! Microsoft and Google sign-in (oauth/MicrosoftAuth.php, oauth/GoogleAuth.php, and the half of newuser.php that
//! acts on the result). Authorization code flow with PKCE, a state bound to the browser by a cookie, and a nonce
//! checked in the ID token. The address used is one the provider vouches for: for Microsoft, a domain the signing
//! tenant has verified; for Google, an address Google marks verified. What happens next is `admission`'s
//! decision, the same as for the sign-up form.

use std::sync::Arc;

use axum::{
    extract::{Query, State},
    http::{header, HeaderMap, HeaderValue, StatusCode},
    response::{IntoResponse, Response},
};
use base64::{engine::general_purpose::URL_SAFE_NO_PAD, Engine};
use rand::{rngs::OsRng, RngCore};
use serde::Deserialize;
use serde_json::Value;
use sha2::{Digest, Sha256};
use sqlx::SqlitePool;
use subtle::ConstantTimeEq;

use crate::{
    accounts::{
        admission::{self, Admission, Context, Registration},
        cookies,
        html::escape,
        profile::{self, Profile},
    },
    api::Auth,
    db::unix_now,
    identity::Email,
    token::Token,
    visitor::Visitor,
};

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Kind {
    Microsoft,
    Google,
}

impl Kind {
    pub fn name(self) -> &'static str {
        match self {
            Kind::Microsoft => "Microsoft",
            Kind::Google => "Google",
        }
    }

    fn code(self) -> &'static str {
        match self {
            Kind::Microsoft => "microsoft",
            Kind::Google => "google",
        }
    }

    fn parse(s: &str) -> Option<Kind> {
        match s {
            "microsoft" => Some(Kind::Microsoft),
            "google" => Some(Kind::Google),
            _ => None,
        }
    }

    fn callback_path(self) -> &'static str {
        match self {
            Kind::Microsoft => "/oauth/callback.php",
            Kind::Google => "/oauth/google_callback.php",
        }
    }

    fn scope(self) -> &'static str {
        match self {
            Kind::Microsoft => "openid profile email User.Read",
            Kind::Google => "openid profile email",
        }
    }
}

/// What the sign-up page says after a trip through a provider (newuser.php process_oauth()).
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Outcome {
    Error(String),
    NoEmail(Kind),
    Blacklisted,
    Granted,
    Pending,
    Submitted,
    Failed,
}

const COOKIE: &str = "__Host-hx_oauth";
/// oauth/*.php refused a callback more than five minutes after the redirect.
const STATE_SECS: i64 = 300;
/// The outcome waits this long for the sign-up page.
const KEEP_SECS: i64 = 900;
const LANDING: &str = "/request-access";

/// One OAuth app. The endpoints are fields so a test can point them at a local stand-in.
#[derive(Debug, Clone)]
pub struct Provider {
    pub kind: Kind,
    pub client_id: String,
    pub client_secret: String,
    pub authorize_url: String,
    pub token_url: String,
    pub profile_url: String,
}

/// The apps configured. A provider whose settings are absent is not offered.
#[derive(Debug, Clone, Default)]
pub struct Providers {
    pub microsoft: Option<Provider>,
    pub google: Option<Provider>,
}

const GRAPH_ME: &str = "https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName,displayName,givenName,surname,companyName,streetAddress,city,state,postalCode";

impl Providers {
    pub fn none() -> Providers {
        Providers::default()
    }

    /// HXWEB_MICROSOFT_CLIENT_ID, _CLIENT_SECRET and _TENANT (the PHP .env MICROSOFT_* values), and
    /// HXWEB_GOOGLE_CLIENT_ID and _CLIENT_SECRET. A provider given only some of its settings is an error.
    pub fn from_env(get: &impl Fn(&str) -> Option<String>) -> Result<Providers, String> {
        let get = |k: &str| get(k).map(|v| v.trim().to_string()).filter(|v| !v.is_empty());
        let microsoft = match (get("HXWEB_MICROSOFT_CLIENT_ID"), get("HXWEB_MICROSOFT_CLIENT_SECRET"), get("HXWEB_MICROSOFT_TENANT")) {
            (None, None, None) => None,
            (Some(client_id), Some(client_secret), Some(tenant)) => {
                if !tenant.bytes().all(|c| c.is_ascii_alphanumeric() || c == b'-' || c == b'.') {
                    return Err(format!("HXWEB_MICROSOFT_TENANT {tenant:?} is not a tenant id or domain"));
                }
                Some(Provider {
                    kind: Kind::Microsoft,
                    client_id,
                    client_secret,
                    authorize_url: format!("https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize"),
                    token_url: format!("https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token"),
                    profile_url: GRAPH_ME.into(),
                })
            }
            _ => return Err("HXWEB_MICROSOFT_CLIENT_ID, _CLIENT_SECRET and _TENANT go together".into()),
        };
        let google = match (get("HXWEB_GOOGLE_CLIENT_ID"), get("HXWEB_GOOGLE_CLIENT_SECRET")) {
            (None, None) => None,
            (Some(client_id), Some(client_secret)) => Some(Provider {
                kind: Kind::Google,
                client_id,
                client_secret,
                authorize_url: "https://accounts.google.com/o/oauth2/v2/auth".into(),
                token_url: "https://oauth2.googleapis.com/token".into(),
                profile_url: "https://openidconnect.googleapis.com/v1/userinfo".into(),
            }),
            _ => return Err("HXWEB_GOOGLE_CLIENT_ID and _CLIENT_SECRET go together".into()),
        };
        Ok(Providers { microsoft, google })
    }
}

fn random_b64(bytes: usize) -> String {
    let mut b = vec![0u8; bytes];
    OsRng.fill_bytes(&mut b);
    URL_SAFE_NO_PAD.encode(b)
}

/// PkceTrait.php: the S256 challenge for a verifier.
fn challenge(verifier: &str) -> String {
    URL_SAFE_NO_PAD.encode(Sha256::digest(verifier.as_bytes()))
}

fn cookie(token: &Token, max_age: i64) -> Option<HeaderValue> {
    HeaderValue::from_str(&format!("{COOKIE}={}; Path=/; Max-Age={max_age}; Secure; HttpOnly; SameSite=Lax", token.as_str())).ok()
}

fn browser_token(headers: &HeaderMap) -> Option<Token> {
    cookies::get(headers, COOKIE).as_deref().and_then(Token::parse)
}

fn redirect(to: &str, set: Vec<HeaderValue>) -> Response {
    let headers = [(header::LOCATION, to), (header::CACHE_CONTROL, "no-store"), (header::CONTENT_TYPE, crate::visitor::PHP_CONTENT_TYPE)];
    let mut r = (StatusCode::FOUND, headers).into_response();
    for c in set {
        r.headers_mut().append(header::SET_COOKIE, c);
    }
    r
}

fn query(pairs: &[(&str, &str)]) -> String {
    use percent_encoding::{utf8_percent_encode, NON_ALPHANUMERIC};
    pairs.iter().map(|(k, v)| format!("{k}={}", utf8_percent_encode(v, NON_ALPHANUMERIC))).collect::<Vec<_>>().join("&")
}

pub async fn microsoft_init(State(core): State<Arc<Auth>>) -> Response {
    init(&core, Kind::Microsoft).await
}

pub async fn google_init(State(core): State<Arc<Auth>>) -> Response {
    init(&core, Kind::Google).await
}

pub async fn microsoft_callback(State(core): State<Arc<Auth>>, visitor: Visitor, headers: HeaderMap, Query(q): Query<Callback>) -> Response {
    callback(&core, Kind::Microsoft, visitor, &headers, q).await
}

pub async fn google_callback(State(core): State<Arc<Auth>>, visitor: Visitor, headers: HeaderMap, Query(q): Query<Callback>) -> Response {
    callback(&core, Kind::Google, visitor, &headers, q).await
}

fn provider(core: &Auth, kind: Kind) -> Option<&Provider> {
    match kind {
        Kind::Microsoft => core.accounts.oauth.microsoft.as_ref(),
        Kind::Google => core.accounts.oauth.google.as_ref(),
    }
}

/// oauth/init.php and google_init.php: remember state, verifier and nonce for this browser, then go to the provider.
async fn init(core: &Auth, kind: Kind) -> Response {
    let now = unix_now();
    let Some(p) = provider(core, kind) else {
        return finish(&core.pool, kind, None, Outcome::Error("OAuth configuration is missing. Contact the administrator.".into()), now, Vec::new()).await;
    };
    let token = Token::generate();
    let (state, verifier, nonce) = (random_b64(32), random_b64(32), hex::encode(random_b64(16).as_bytes()).chars().take(32).collect::<String>());
    let hash = token.hash();
    let hash = hash.as_slice();
    let stale = now - KEEP_SECS;
    let code = kind.code();
    let saved = async {
        sqlx::query!("DELETE FROM oauth_login WHERE created_at < ?1", stale).execute(&core.pool).await?;
        sqlx::query!(
            "INSERT INTO oauth_login (token_hash, provider, state, verifier, nonce, created_at) VALUES (?1, ?2, ?3, ?4, ?5, ?6)",
            hash,
            code,
            state,
            verifier,
            nonce,
            now
        )
        .execute(&core.pool)
        .await
    }
    .await;
    if let Err(e) = saved {
        tracing::error!("oauth init: {e}");
        return (StatusCode::INTERNAL_SERVER_ERROR, "Internal error").into_response();
    }
    let redirect_uri = format!("{}{}", core.public_origin, kind.callback_path());
    let challenge = challenge(&verifier);
    let params = query(&[
        ("client_id", &p.client_id),
        ("response_type", "code"),
        ("redirect_uri", &redirect_uri),
        ("response_mode", "query"),
        ("scope", kind.scope()),
        ("state", &state),
        ("code_challenge", &challenge),
        ("code_challenge_method", "S256"),
        ("nonce", &nonce),
    ]);
    redirect(&format!("{}?{params}", p.authorize_url), cookie(&token, KEEP_SECS).into_iter().collect())
}

#[derive(Debug, Deserialize, Default)]
#[serde(default)]
pub struct Callback {
    state: Option<String>,
    code: Option<String>,
    error: Option<String>,
    error_description: Option<String>,
}

/// Store the outcome for the sign-up page and send the browser there. `existing` is this browser's row, if any.
async fn finish(pool: &SqlitePool, kind: Kind, existing: Option<Token>, outcome: Outcome, now: i64, mut set: Vec<HeaderValue>) -> Response {
    let token = existing.unwrap_or_else(Token::generate);
    let hash = token.hash();
    let hash = hash.as_slice();
    let (code, message) = match &outcome {
        Outcome::Error(m) => ("error", Some(m.as_str())),
        Outcome::NoEmail(_) => ("no_email", None),
        Outcome::Blacklisted => ("blacklisted", None),
        Outcome::Granted => ("granted", None),
        Outcome::Pending => ("pending", None),
        Outcome::Submitted => ("submitted", None),
        Outcome::Failed => ("failed", None),
    };
    let provider = kind.code();
    let saved = sqlx::query!(
        "INSERT INTO oauth_login (token_hash, provider, created_at, outcome, message) VALUES (?1, ?2, ?3, ?4, ?5)
         ON CONFLICT (token_hash) DO UPDATE SET provider = excluded.provider, state = NULL, verifier = NULL, nonce = NULL,
             created_at = excluded.created_at, outcome = excluded.outcome, message = excluded.message",
        hash,
        provider,
        now,
        code,
        message
    )
    .execute(pool)
    .await;
    if let Err(e) = saved {
        tracing::error!("oauth outcome not saved: {e}");
    }
    set.extend(cookie(&token, KEEP_SECS));
    redirect(LANDING, set)
}

/// The outcome waiting for this browser, once.
pub async fn take_outcome(pool: &SqlitePool, headers: &HeaderMap) -> sqlx::Result<Option<Outcome>> {
    let Some(token) = browser_token(headers) else { return Ok(None) };
    let hash = token.hash();
    let hash = hash.as_slice();
    let floor = unix_now() - KEEP_SECS;
    let row = sqlx::query!(
        r#"DELETE FROM oauth_login WHERE token_hash = ?1 AND outcome IS NOT NULL AND created_at > ?2
           RETURNING provider AS "provider!", outcome AS "outcome!", message"#,
        hash,
        floor
    )
    .fetch_optional(pool)
    .await?;
    Ok(row.and_then(|r| {
        let kind = Kind::parse(&r.provider)?;
        Some(match r.outcome.as_str() {
            "error" => Outcome::Error(r.message.unwrap_or_default()),
            "no_email" => Outcome::NoEmail(kind),
            "blacklisted" => Outcome::Blacklisted,
            "granted" => Outcome::Granted,
            "pending" => Outcome::Pending,
            "submitted" => Outcome::Submitted,
            _ => Outcome::Failed,
        })
    }))
}

/// Who the provider says signed in.
#[derive(Debug, Default, Clone, PartialEq)]
pub struct Person {
    pub email: String,
    pub name_first: String,
    pub name_last: String,
    pub display_name: String,
    pub company_name: String,
    pub address: String,
    pub city: String,
    pub state: String,
    pub zip: String,
}

#[derive(Deserialize)]
struct Tokens {
    access_token: String,
    id_token: Option<String>,
}

/// The claims of an ID token received straight from the token endpoint over TLS, which is what vouches for it
/// (OpenID Connect Core 3.1.3.7), so the signature is not checked here.
fn claims(id_token: &str) -> Option<Value> {
    let mut parts = id_token.split('.');
    let (_, payload, _, None) = (parts.next()?, parts.next()?, parts.next()?, parts.next()) else { return None };
    serde_json::from_slice(&URL_SAFE_NO_PAD.decode(payload.trim_end_matches('=')).ok()?).ok()
}

fn text(v: &Value, key: &str) -> Option<String> {
    v.get(key).and_then(Value::as_str).map(str::to_string)
}

/// PHP `$a[$k] ?? $b[$k] ?? ''`: the first that is present and not null.
fn first(candidates: &[Option<String>]) -> String {
    candidates.iter().flatten().next().cloned().unwrap_or_default()
}

fn truthy(v: Option<&Value>) -> bool {
    match v {
        None | Some(Value::Null) => false,
        Some(Value::Bool(b)) => *b,
        Some(Value::Number(n)) => n.as_f64().is_some_and(|f| f != 0.0),
        Some(Value::String(s)) => !s.is_empty() && s != "0",
        Some(_) => true,
    }
}

/// MicrosoftAuth.php verifiedEmail(): an address whose domain the signing tenant has proven it owns, or nothing.
/// Any Entra tenant can sign in and a tenant admin can set `mail` to anything, so `mail` alone is never trusted.
fn microsoft_email(profile: &Value, claims: &Value) -> Option<String> {
    let normalise = |s: String| Email::parse(&s).map(|e| e.as_str().to_string());
    let domain = |s: &str| s.rsplit_once('@').map(|(_, d)| d.to_string());
    let upn = normalise(first(&[text(profile, "userPrincipalName"), text(claims, "upn")])).filter(|u| !u.contains("#ext#"));
    let mail = normalise(text(profile, "mail").unwrap_or_default());
    let claim_email = normalise(text(claims, "email").unwrap_or_default());
    let edov = truthy(claims.get("xms_edov")) && claim_email.is_some();
    if let Some(mail) = &mail {
        let by_claim = edov && claim_email.as_ref() == Some(mail);
        let by_upn = upn.as_ref().is_some_and(|u| domain(u) == domain(mail));
        if by_claim || by_upn {
            return Some(mail.clone());
        }
    }
    upn.or(if edov { claim_email } else { None })
}

fn failed(kind: Kind) -> String {
    format!("{} authentication failed. Please try again.", kind.name())
}

/// Exchange the code and read who signed in. Err is the message the sign-up page shows.
async fn identify(core: &Auth, p: &Provider, kind: Kind, code: &str, verifier: &str, nonce: &str) -> Result<Person, String> {
    let redirect_uri = format!("{}{}", core.public_origin, kind.callback_path());
    let form = [
        ("client_id", p.client_id.as_str()),
        ("client_secret", p.client_secret.as_str()),
        ("grant_type", "authorization_code"),
        ("code", code),
        ("redirect_uri", redirect_uri.as_str()),
        ("code_verifier", verifier),
    ];
    let http = &core.accounts.http;
    let tokens: Tokens = async { http.post(&p.token_url).form(&form).send().await?.error_for_status()?.json().await }
        .await
        .map_err(|e| {
            tracing::warn!("{} token exchange failed: {e}", kind.name());
            failed(kind)
        })?;
    let claims = tokens.id_token.as_deref().and_then(claims).ok_or_else(|| failed(kind))?;
    let nonce_ok = text(&claims, "nonce").is_some_and(|n| bool::from(n.as_bytes().ct_eq(nonce.as_bytes())));
    let audience_ok = match claims.get("aud") {
        Some(Value::String(a)) => *a == p.client_id,
        Some(Value::Array(a)) => a.iter().any(|v| v.as_str() == Some(p.client_id.as_str())),
        _ => false,
    };
    if !nonce_ok || !audience_ok {
        tracing::warn!("{} ID token nonce or audience mismatch", kind.name());
        return Err(failed(kind));
    }
    let profile: Value = async { http.get(&p.profile_url).bearer_auth(&tokens.access_token).send().await?.error_for_status()?.json().await }
        .await
        .unwrap_or_else(|e| {
            tracing::warn!("{} profile lookup failed: {e}", kind.name());
            Value::Null
        });
    match kind {
        Kind::Microsoft => {
            if text(&claims, "tid").unwrap_or_default().is_empty() {
                return Err(failed(kind));
            }
            let Some(email) = microsoft_email(&profile, &claims) else {
                return Err("We could not verify the email address on your Microsoft account. Please register using the form instead.".into());
            };
            Ok(Person {
                email,
                name_first: first(&[text(&profile, "givenName"), text(&claims, "given_name")]),
                name_last: first(&[text(&profile, "surname"), text(&claims, "family_name")]),
                display_name: first(&[text(&profile, "displayName"), text(&claims, "name")]),
                company_name: text(&profile, "companyName").unwrap_or_default(),
                address: text(&profile, "streetAddress").unwrap_or_default(),
                city: text(&profile, "city").unwrap_or_default(),
                state: text(&profile, "state").unwrap_or_default(),
                zip: text(&profile, "postalCode").unwrap_or_default(),
            })
        }
        Kind::Google => {
            if profile.is_null() {
                return Err(failed(kind));
            }
            if !truthy(profile.get("email_verified")) {
                return Err("Your Google email address is not verified. Please verify it and try again.".into());
            }
            Ok(Person {
                email: text(&profile, "email").unwrap_or_default().trim().to_lowercase(),
                name_first: text(&profile, "given_name").unwrap_or_default(),
                name_last: text(&profile, "family_name").unwrap_or_default(),
                display_name: text(&profile, "name").unwrap_or_default(),
                ..Person::default()
            })
        }
    }
}

/// oauth/callback.php and google_callback.php, then newuser.php process_oauth(): check the browser's state,
/// learn who signed in, and let `admission` decide.
async fn callback(core: &Auth, kind: Kind, visitor: Visitor, headers: &HeaderMap, q: Callback) -> Response {
    let pool = &core.pool;
    let now = unix_now();
    let token = browser_token(headers);
    if token.is_none() {
        return redirect(LANDING, Vec::new());
    }
    let pending = match &token {
        Some(t) => {
            let hash = t.hash();
            let hash = hash.as_slice();
            let code = kind.code();
            sqlx::query!(
                r#"SELECT state AS "state!", verifier AS "verifier!", nonce AS "nonce!", created_at AS "created_at!: i64"
                   FROM oauth_login WHERE token_hash = ?1 AND provider = ?2 AND outcome IS NULL"#,
                hash,
                code
            )
            .fetch_optional(pool)
            .await
            .unwrap_or_else(|e| {
                tracing::error!("oauth callback: {e}");
                None
            })
        }
        None => None,
    };
    let state_ok = match (&pending, &q.state) {
        (Some(row), Some(s)) => !s.is_empty() && bool::from(row.state.as_bytes().ct_eq(s.as_bytes())),
        _ => false,
    };
    let Some(row) = pending.filter(|_| state_ok) else {
        return finish(pool, kind, token, Outcome::Error("Invalid OAuth state. Please try again.".into()), now, Vec::new()).await;
    };
    if now - row.created_at > STATE_SECS {
        return finish(pool, kind, token, Outcome::Error("Authentication request expired. Please try again.".into()), now, Vec::new()).await;
    }
    if let Some(error) = &q.error {
        let message = q.error_description.clone().unwrap_or_else(|| error.clone());
        return finish(pool, kind, token, Outcome::Error(message), now, Vec::new()).await;
    }
    let Some(p) = provider(core, kind) else {
        return finish(pool, kind, token, Outcome::Error(failed(kind)), now, Vec::new()).await;
    };
    let person = match identify(core, p, kind, q.code.as_deref().unwrap_or(""), &row.verifier, &row.nonce).await {
        Ok(person) => person,
        Err(message) => return finish(pool, kind, token, Outcome::Error(message), now, Vec::new()).await,
    };
    let ip = visitor.client.map_or_else(String::new, |ip| ip.to_string());
    let ctx = Context { ip: &ip, user_agent: headers.get(header::USER_AGENT).and_then(|v| v.to_str().ok()).unwrap_or(""), dns_name: None, now };
    let (outcome, set) = match admit(pool, kind, &person, &ctx).await {
        Ok(done) => done,
        Err(e) => {
            tracing::error!("oauth admission: {e}");
            (Outcome::Failed, Vec::new())
        }
    };
    finish(pool, kind, token, outcome, now, set).await
}

/// newuser.php process_oauth() after the provider: the same decision as the sign-up form.
async fn admit(pool: &SqlitePool, kind: Kind, person: &Person, ctx: &Context<'_>) -> sqlx::Result<(Outcome, Vec<HeaderValue>)> {
    let Some(email) = Email::parse(&person.email) else { return Ok((Outcome::NoEmail(kind), Vec::new())) };
    let via = format!("{} OAuth", kind.name());
    let profile = Profile { email: email.as_str().into(), name_first: person.name_first.clone(), name_last: person.name_last.clone(), ..Profile::default() };
    let mut set: Vec<HeaderValue> = cookies::set(profile::COOKIE, &profile.to_cookie(), cookies::Life::Year).into_iter().collect();
    match admission::classify(pool, &email).await? {
        Admission::Blacklisted => {
            admission::log_blacklisted(pool, &email, ctx, &via).await;
            Ok((Outcome::Blacklisted, Vec::new()))
        }
        Admission::Company { company, known } => {
            if !known {
                admission::notify_auto_approved(pool, &company, &email, &via, ctx).await?;
            }
            set.extend(admission::grant(pool, &company, &email, ctx).await?.cookies());
            Ok((Outcome::Granted, set))
        }
        Admission::Pending => Ok((Outcome::Pending, set)),
        Admission::Unknown => {
            let registration = Registration {
                company_name: if person.company_name.is_empty() { person.display_name.clone() } else { person.company_name.clone() },
                address: person.address.clone(),
                city: person.city.clone(),
                state: person.state.clone(),
                zip: person.zip.clone(),
                country_code: String::new(),
                domain: Some(email.domain().to_string()),
                recaptcha_score: 1.0,
            };
            let (id, signin) = admission::register(pool, &registration, &email, true, ctx).await?;
            set.extend(signin.cookies());
            let mut lines = vec![format!("Email: {}", escape(email.as_str())), format!("Name: {}", escape(&person.display_name)), format!("{} OAuth registration", kind.name())];
            admission::log_signup(pool, id, ctx, &lines.join("<br>")).await;
            lines.push(String::new());
            lines.extend(admission::request_lines(ctx));
            admission::notify_staff(pool, None, &lines, ctx.now).await?;
            Ok((Outcome::Submitted, set))
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn microsoft_email_is_only_a_domain_the_tenant_proved() {
        let m = |p: Value, c: Value| microsoft_email(&p, &c);
        assert_eq!(m(json!({"mail": "Pat@Example.com", "userPrincipalName": "pat@example.com"}), json!({})).as_deref(), Some("pat@example.com"));
        assert_eq!(m(json!({"mail": "boss@victim.example", "userPrincipalName": "pat@tenant.example"}), json!({})).as_deref(), Some("pat@tenant.example"), "mail at another domain falls back to the UPN");
        assert_eq!(m(json!({"mail": "boss@victim.example"}), json!({})), None, "mail alone is not trusted");
        assert_eq!(m(json!({"mail": "pat@ext.example", "userPrincipalName": "pat_ext.example#EXT#@host.example"}), json!({})), None, "a guest UPN says nothing");
        assert_eq!(m(json!({"mail": "pat@ext.example"}), json!({"email": "pat@ext.example", "xms_edov": true})).as_deref(), Some("pat@ext.example"));
        assert_eq!(m(json!({}), json!({"email": "pat@ext.example", "xms_edov": "1"})).as_deref(), Some("pat@ext.example"));
        assert_eq!(m(json!({}), json!({"email": "pat@ext.example", "xms_edov": false})), None);
        assert_eq!(m(json!({"userPrincipalName": null}), json!({"upn": "pat@tenant.example"})).as_deref(), Some("pat@tenant.example"));
    }

    #[test]
    fn id_token_claims_decode_and_junk_does_not() {
        let payload = URL_SAFE_NO_PAD.encode(br#"{"nonce":"n1","tid":"t"}"#);
        assert_eq!(claims(&format!("h.{payload}.s")).unwrap()["nonce"], "n1");
        for bad in ["", "a.b", "a.b.c.d", "h.!!!.s"] {
            assert!(claims(bad).is_none(), "{bad:?}");
        }
    }

    #[test]
    fn pkce_challenge_is_the_rfc_example() {
        assert_eq!(challenge("dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk"), "E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM");
    }

    fn env<'a>(pairs: &'a [(&'a str, &'a str)]) -> impl Fn(&str) -> Option<String> + 'a {
        move |k| pairs.iter().find(|(n, _)| *n == k).map(|(_, v)| v.to_string())
    }

    #[test]
    fn providers_are_all_or_nothing() {
        let p = Providers::from_env(&env(&[])).unwrap();
        assert!(p.microsoft.is_none() && p.google.is_none());
        let p = Providers::from_env(&env(&[("HXWEB_GOOGLE_CLIENT_ID", "g"), ("HXWEB_GOOGLE_CLIENT_SECRET", "s")])).unwrap();
        assert!(p.google.is_some() && p.microsoft.is_none());
        assert!(Providers::from_env(&env(&[("HXWEB_GOOGLE_CLIENT_ID", "g")])).is_err());
        assert!(Providers::from_env(&env(&[("HXWEB_MICROSOFT_CLIENT_ID", "m"), ("HXWEB_MICROSOFT_CLIENT_SECRET", "s")])).is_err());
        assert!(Providers::from_env(&env(&[("HXWEB_MICROSOFT_CLIENT_ID", "m"), ("HXWEB_MICROSOFT_CLIENT_SECRET", "s"), ("HXWEB_MICROSOFT_TENANT", "a/b")])).is_err());
        let p = Providers::from_env(&env(&[("HXWEB_MICROSOFT_CLIENT_ID", "m"), ("HXWEB_MICROSOFT_CLIENT_SECRET", "s"), ("HXWEB_MICROSOFT_TENANT", "organizations")])).unwrap();
        assert_eq!(p.microsoft.unwrap().token_url, "https://login.microsoftonline.com/organizations/oauth2/v2.0/token");
    }
}
