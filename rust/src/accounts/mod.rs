//! Accounts: who is asking and how they get in. Other areas read `crate::visitor::Visitor`; this module holds what
//! it is built from (the account row, bots, campaigns, language and currency) and the sign-in and sign-up flows.

pub mod account;
pub mod admission;
pub mod bot;
pub mod campaign;
pub mod choice;
pub mod clock;
pub mod cookies;
pub mod html;
pub mod impersonate;
pub mod locale;
pub mod log;
pub mod oauth;
pub mod profile;
pub mod recaptcha;
pub mod registry;
pub mod signup;
pub mod visit;

use std::time::Duration;

/// What the accounts flows need besides the database: DNS for bot matching, an HTTP client for OAuth and
/// reCAPTCHA, and the OAuth app settings.
pub struct Services {
    pub dns: bot::Dns,
    pub http: reqwest::Client,
    pub oauth: oauth::Providers,
}

impl Services {
    pub fn from_env(get: impl Fn(&str) -> Option<String>) -> Result<Self, String> {
        let http = reqwest::Client::builder()
            .timeout(Duration::from_secs(15))
            .redirect(reqwest::redirect::Policy::none())
            .build()
            .map_err(|e| format!("http client: {e}"))?;
        Ok(Services { dns: bot::Dns::system(), http, oauth: oauth::Providers::from_env(&get)? })
    }

    /// No network: DNS answers only from the table given, and no OAuth app is configured.
    pub fn offline(dns: bot::Dns) -> Self {
        Services { dns, http: reqwest::Client::new(), oauth: oauth::Providers::none() }
    }
}
