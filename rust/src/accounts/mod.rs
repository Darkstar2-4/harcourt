//! Accounts: who is asking and how they get in. Other areas read `crate::visitor::Visitor`; this module holds what
//! it is built from (the account row, bots, campaigns, language and currency) and the sign-in and sign-up flows.

pub mod account;
pub mod admission;
pub mod bot;
pub mod campaign;
pub mod campaign_page;
pub mod clock;
pub mod cookies;
pub mod html;
pub mod locale;
pub mod log;
pub mod oauth;
pub mod profile;
pub mod recaptcha;
pub mod registry;
pub mod signout;
pub mod signup;
pub mod static_dirs;
pub mod visit;

use std::time::Duration;

/// What the accounts flows need besides the database: DNS for bot matching, an HTTP client for OAuth and
/// reCAPTCHA, the OAuth app settings, and whether a new account's address is looked up at IPStack.
pub struct Services {
    pub dns: bot::Dns,
    pub http: reqwest::Client,
    pub oauth: oauth::Providers,
    /// `HXWEB_IPSTACK=on`. Off by default, so a test or staging server never calls the paid service.
    pub ipstack: bool,
}

impl Services {
    pub fn from_env(get: impl Fn(&str) -> Option<String>) -> Result<Self, String> {
        let http = reqwest::Client::builder()
            .timeout(Duration::from_secs(15))
            .redirect(reqwest::redirect::Policy::none())
            .build()
            .map_err(|e| format!("http client: {e}"))?;
        let ipstack = match get("HXWEB_IPSTACK").as_deref().map(str::trim) {
            None | Some("" | "off") => false,
            Some("on") => true,
            Some(other) => return Err(format!("HXWEB_IPSTACK: expected on or off, got {other:?}")),
        };
        Ok(Services { dns: bot::Dns::system(), http, oauth: oauth::Providers::from_env(&get)?, ipstack })
    }

    /// No network: DNS answers only from the table given, no OAuth app is configured and IPStack is off.
    pub fn offline(dns: bot::Dns) -> Self {
        Services { dns, http: reqwest::Client::new(), oauth: oauth::Providers::none(), ipstack: false }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn ipstack(value: Option<&str>) -> Result<bool, String> {
        Services::from_env(|k| (k == "HXWEB_IPSTACK").then(|| value.map(str::to_string)).flatten()).map(|s| s.ipstack)
    }

    #[test]
    fn ipstack_is_off_unless_switched_on() {
        assert_eq!(ipstack(None), Ok(false));
        assert_eq!(ipstack(Some("off")), Ok(false));
        assert_eq!(ipstack(Some("on")), Ok(true));
        assert!(ipstack(Some("yes")).is_err());
    }
}
