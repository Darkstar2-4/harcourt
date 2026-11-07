//! Settings read once at start-up. Everything past this point works with typed values.

use std::{net::IpAddr, path::PathBuf};

pub struct Config {
    pub db_path: PathBuf,
    /// The one origin browsers may POST to /api from, e.g. https://www.harcourt.co (no trailing slash).
    pub public_origin: String,
    /// Absent until an operator provides it; the emailed-code routes answer 503 meanwhile.
    pub secret: Option<Secret>,
    /// Peers whose X-Real-IP header is believed. Everyone else is identified by the socket address.
    pub trusted_proxies: Vec<IpAddr>,
}

/// Key for the HMAC over emailed codes: at least 32 bytes, given as hex.
pub struct Secret(Vec<u8>);

impl Secret {
    pub fn parse(hex_str: &str) -> Result<Self, String> {
        let bytes = hex::decode(hex_str.trim()).map_err(|e| format!("HXWEB_SECRET is not hex: {e}"))?;
        if bytes.len() < 32 {
            return Err(format!("HXWEB_SECRET has {} bytes, needs at least 32", bytes.len()));
        }
        Ok(Secret(bytes))
    }

    pub fn from_bytes(bytes: Vec<u8>) -> Self {
        Secret(bytes)
    }

    pub fn as_bytes(&self) -> &[u8] {
        &self.0
    }
}

impl Config {
    pub fn from_env(get: impl Fn(&str) -> Option<String>) -> Result<Self, String> {
        let db_path = PathBuf::from(get("HXWEB_DB").unwrap_or_else(|| "/var/lib/hxweb/hx.db".into()));
        let public_origin = get("HXWEB_PUBLIC_ORIGIN").unwrap_or_else(|| "https://www.harcourt.co".into());
        let public_origin = public_origin.trim_end_matches('/').to_string();
        if !public_origin.starts_with("https://") || public_origin[8..].contains('/') {
            return Err(format!("HXWEB_PUBLIC_ORIGIN must look like https://host, got {public_origin:?}"));
        }
        let secret = get("HXWEB_SECRET").map(|s| Secret::parse(&s)).transpose()?;
        let trusted_proxies = match get("HXWEB_TRUSTED_PROXIES") {
            None => vec![IpAddr::from([127, 0, 0, 1]), IpAddr::from([0, 0, 0, 0, 0, 0, 0, 1])],
            Some(list) => list
                .split(',')
                .map(|s| s.trim().parse::<IpAddr>().map_err(|e| format!("HXWEB_TRUSTED_PROXIES: {s:?}: {e}")))
                .collect::<Result<_, _>>()?,
        };
        Ok(Config { db_path, public_origin, secret, trusted_proxies })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn env<'a>(pairs: &'a [(&'a str, &'a str)]) -> impl Fn(&str) -> Option<String> + 'a {
        move |k| pairs.iter().find(|(n, _)| *n == k).map(|(_, v)| v.to_string())
    }

    #[test]
    fn defaults_are_the_production_site_and_no_secret() {
        let c = Config::from_env(env(&[])).unwrap();
        assert_eq!(c.public_origin, "https://www.harcourt.co");
        assert!(c.secret.is_none());
        assert_eq!(c.trusted_proxies.len(), 2);
    }

    #[test]
    fn short_or_non_hex_secrets_are_rejected() {
        assert!(Config::from_env(env(&[("HXWEB_SECRET", "abcd")])).is_err());
        assert!(Config::from_env(env(&[("HXWEB_SECRET", "zz")])).is_err());
        assert!(Config::from_env(env(&[("HXWEB_SECRET", &"ab".repeat(32))])).unwrap().secret.is_some());
    }

    #[test]
    fn origin_must_be_https_without_a_path() {
        assert!(Config::from_env(env(&[("HXWEB_PUBLIC_ORIGIN", "http://www.harcourt.co")])).is_err());
        assert!(Config::from_env(env(&[("HXWEB_PUBLIC_ORIGIN", "https://www.harcourt.co/catalog")])).is_err());
        let c = Config::from_env(env(&[("HXWEB_PUBLIC_ORIGIN", "https://stage2.harcourt.co/")])).unwrap();
        assert_eq!(c.public_origin, "https://stage2.harcourt.co");
    }
}
