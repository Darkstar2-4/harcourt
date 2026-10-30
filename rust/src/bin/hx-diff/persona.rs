//! The synthetic personas, parsed once from `oracle/personas.json`. One table feeds the oracle's MySQL rows
//! (`hx-diff personas --sql`), the Rust copy's sessions (`hx-diff seed`) and the headers each request carries.

use std::{collections::BTreeSet, net::IpAddr};

use base64::{engine::general_purpose::URL_SAFE_NO_PAD, Engine};
use hxweb::identity::{Role, Status};
use serde::Deserialize;
use sha2::{Digest, Sha256};

pub const ID_RANGE: std::ops::RangeInclusive<i64> = 990_001..=990_999;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Lang {
    En,
    Fr,
}

impl Lang {
    pub fn parse(s: &str) -> Option<Lang> {
        match s.to_ascii_lowercase().as_str() {
            "en" => Some(Lang::En),
            "fr" => Some(Lang::Fr),
            _ => None,
        }
    }

    pub fn accept_language(self) -> &'static str {
        match self {
            Lang::En => "en-US,en;q=0.9",
            Lang::Fr => "fr-CA,fr;q=0.9",
        }
    }

    pub fn code(self) -> &'static str {
        match self {
            Lang::En => "EN",
            Lang::Fr => "FR",
        }
    }
}

#[derive(Debug, Clone)]
pub struct Account {
    pub id: i64,
    pub role: Role,
    pub status: Status,
    pub language: Lang,
    pub company: String,
    pub domain: String,
}

#[derive(Debug, Clone)]
pub struct Persona {
    pub name: String,
    pub ip: IpAddr,
    pub account: Option<Account>,
}

pub fn status_str(s: Status) -> &'static str {
    match s {
        Status::Pending => "Pending",
        Status::Active => "Active",
        Status::Denied => "Denied",
    }
}

impl Persona {
    /// The deterministic session token `hx-diff seed` stores for this persona in a Rust-side copy.
    pub fn session_token(&self) -> String {
        URL_SAFE_NO_PAD.encode(Sha256::digest(format!("hx-persona-session:{}", self.name)))
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct RawPersona {
    name: String,
    ip: String,
    account: Option<RawAccount>,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct RawAccount {
    id: i64,
    role: String,
    status: String,
    language: String,
    company: String,
    domain: String,
}

pub struct Personas(Vec<Persona>);

impl Personas {
    pub fn embedded() -> Result<Personas, String> {
        Personas::parse(include_str!("../../../oracle/personas.json"))
    }

    pub fn parse(json: &str) -> Result<Personas, String> {
        let raw: Vec<RawPersona> = serde_json::from_str(json).map_err(|e| format!("personas.json: {e}"))?;
        let (mut names, mut ips, mut ids) = (BTreeSet::new(), BTreeSet::new(), BTreeSet::new());
        let mut out = Vec::new();
        for p in raw {
            let at = |m: &str| format!("personas.json: {}: {m}", p.name);
            let ip: IpAddr = p.ip.parse().map_err(|_| at("bad ip"))?;
            if p.name.is_empty() || !p.name.bytes().all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b == b'-') {
                return Err(at("names are lowercase, digits and dashes"));
            }
            if !names.insert(p.name.clone()) || !ips.insert(ip) {
                return Err(at("duplicate name or ip"));
            }
            let account = match p.account {
                None => None,
                Some(a) => {
                    let plain = |s: &str| !s.is_empty() && s.chars().all(|c| c.is_ascii_alphanumeric() || " .-".contains(c));
                    if !ID_RANGE.contains(&a.id) || !ids.insert(a.id) {
                        return Err(at("account ids are unique and in 990001..=990999"));
                    }
                    if !plain(&a.company) || !plain(&a.domain) {
                        return Err(at("company and domain are plain ASCII"));
                    }
                    Some(Account {
                        id: a.id,
                        role: Role::parse(&a.role).ok_or_else(|| at("unknown role"))?,
                        status: Status::parse(&a.status).ok_or_else(|| at("unknown status"))?,
                        language: Lang::parse(&a.language).ok_or_else(|| at("unknown language"))?,
                        company: a.company,
                        domain: a.domain,
                    })
                }
            };
            out.push(Persona { name: p.name, ip, account });
        }
        if !out.iter().any(|p| p.name == "anonymous" && p.account.is_none()) {
            return Err("personas.json: needs an `anonymous` persona without an account".into());
        }
        Ok(Personas(out))
    }

    pub fn all(&self) -> &[Persona] {
        &self.0
    }

    pub fn get(&self, name: &str) -> Option<&Persona> {
        self.0.iter().find(|p| p.name == name)
    }

    /// The oracle's persona rows. refresh-db.sh loads this after every copy of the production database.
    pub fn sql(&self) -> String {
        let rows: Vec<String> = self
            .0
            .iter()
            .filter_map(|p| p.account.as_ref().map(|a| (p, a)))
            .map(|(p, a)| {
                format!(
                    "({}, '{}', '{}', 'US', '{}', '{}', 1, 1, 1, '{}', '{}', 'USD')",
                    a.id,
                    p.ip,
                    a.company,
                    status_str(a.status),
                    a.role.as_str(),
                    a.language.code(),
                    a.domain
                )
            })
            .collect();
        format!(
            "-- Synthetic personas for the oracle only (generated from rust/oracle/personas.json). Never run on prod.\n\
             DELETE FROM user WHERE id BETWEEN {} AND {};\n\
             INSERT INTO user (id, ip, company_name, country_code, status, type, catalog, cad, ecommerce, language_code, \
             domain, currency_code) VALUES\n{};\n",
            ID_RANGE.start() - 1,
            ID_RANGE.end(),
            rows.join(",\n")
        )
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_embedded_table_parses_and_covers_every_role_and_status() {
        let p = Personas::embedded().unwrap();
        let accounts: Vec<&Account> = p.all().iter().filter_map(|p| p.account.as_ref()).collect();
        for role in [Role::Customer, Role::External, Role::Internal, Role::Executive, Role::Administrator, Role::SuperUser] {
            for status in [Status::Active, Status::Pending, Status::Denied] {
                assert!(accounts.iter().any(|a| a.role == role && a.status == status), "{role:?} {status:?}");
            }
        }
        assert!(accounts.iter().any(|a| a.language == Lang::Fr));
        assert!(p.get("anonymous").is_some_and(|a| a.account.is_none()));
        assert!(p.all().iter().all(|p| p.ip.to_string().starts_with("198.51.100.")), "documentation range only");
    }

    #[test]
    fn bad_tables_are_refused() {
        let anon = r#"{"name":"anonymous","ip":"198.51.100.250","account":null}"#;
        let acct = |name: &str, ip: &str, id: i64, role: &str| {
            format!(
                r#"{{"name":"{name}","ip":"{ip}","account":{{"id":{id},"role":"{role}","status":"Active","language":"EN","company":"C","domain":"d.example"}}}}"#
            )
        };
        assert!(Personas::parse(&format!("[{anon}]")).is_ok());
        assert!(Personas::parse("[]").is_err());
        assert!(Personas::parse(&format!("[{anon},{}]", acct("a", "198.51.100.250", 990001, "Customer"))).is_err());
        assert!(Personas::parse(&format!("[{anon},{}]", acct("a", "198.51.100.1", 5, "Customer"))).is_err());
        assert!(Personas::parse(&format!("[{anon},{}]", acct("a", "198.51.100.1", 990001, "Owner"))).is_err());
        assert!(Personas::parse(&format!("[{anon},{}]", acct("A b", "198.51.100.1", 990001, "Customer"))).is_err());
        let quoted = acct("a", "198.51.100.1", 990001, "Customer").replace("\"C\"", "\"C'; DROP\"");
        assert!(Personas::parse(&format!("[{anon},{quoted}]")).is_err());
    }

    #[test]
    fn session_tokens_are_what_the_cookie_parser_accepts() {
        let p = Personas::embedded().unwrap();
        let t = p.get("customer-active").unwrap().session_token();
        assert!(hxweb::token::Token::parse(&t).is_some());
        assert_ne!(t, p.get("customer-pending").unwrap().session_token());
    }

    #[test]
    fn sql_names_every_account_once() {
        let p = Personas::embedded().unwrap();
        let sql = p.sql();
        assert!(sql.starts_with("-- Synthetic personas"));
        assert_eq!(sql.matches("'US'").count(), p.all().iter().filter(|p| p.account.is_some()).count());
        assert!(sql.contains("'Super User'"));
    }
}
