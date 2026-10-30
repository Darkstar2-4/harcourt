//! Microsoft and Google sign-in (oauth/MicrosoftAuth.php, oauth/GoogleAuth.php): the app settings.

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Kind {
    Microsoft,
    Google,
}

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

#[cfg(test)]
mod tests {
    use super::*;

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
