//! Differential cases, one JSON object per line:
//! `{"name":"catalog","method":"GET","path":"/catalog.php?lang=fr","persona":"customer-active","language":"fr","body":null}`.
//! `method` defaults to GET, `persona` to anonymous, `language` to en. `headers` is an optional object of extra
//! request headers from a fixed list (`Extra`), sent to both sides. `route` and `hits` are informational (the corpus
//! generator writes them). Blank lines and lines starting with `#` are skipped.

use std::{collections::BTreeMap, path::Path};

use reqwest::Method;
use serde::Deserialize;

use crate::persona::{Lang, Personas};

#[derive(Debug, Clone)]
pub struct Case {
    pub name: String,
    pub method: Method,
    pub path: String,
    pub persona: String,
    pub language: Lang,
    pub body: Option<String>,
    pub content_type: Option<String>,
    pub headers: Vec<(Extra, String)>,
}

/// The origin both stacks answer as. Referer and Origin may only point here.
pub const SITE_ORIGIN: &str = "https://www.harcourt.co";

/// The request headers a case may add. Pages such as /newuser.php redirect a visitor who arrives without a same-site
/// Referer or X-Requested-With, so a case needs them to reach the page at all.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Extra {
    Referer,
    XRequestedWith,
    Accept,
    XHxNav,
    Origin,
}

impl Extra {
    const ALL: [Extra; 5] = [Extra::Referer, Extra::XRequestedWith, Extra::Accept, Extra::XHxNav, Extra::Origin];

    pub fn name(self) -> &'static str {
        match self {
            Extra::Referer => "referer",
            Extra::XRequestedWith => "x-requested-with",
            Extra::Accept => "accept",
            Extra::XHxNav => "x-hx-nav",
            Extra::Origin => "origin",
        }
    }

    fn parse(name: &str) -> Option<Extra> {
        Extra::ALL.into_iter().find(|h| h.name().eq_ignore_ascii_case(name))
    }

    fn check(self, value: &str) -> Result<(), String> {
        if value.is_empty() || !value.bytes().all(|b| (0x20..0x7f).contains(&b)) {
            return Err(format!("{} must be printable ASCII, got {value:?}", self.name()));
        }
        let on_site = |v: &str| {
            reqwest::Url::parse(v).is_ok_and(|u| {
                u.scheme() == "https"
                    && u.host_str() == Some("www.harcourt.co")
                    && u.port().is_none()
                    && u.username().is_empty()
                    && u.password().is_none()
            })
        };
        match self {
            Extra::Referer if !on_site(value) => Err(format!("referer must be a URL on {SITE_ORIGIN}, got {value:?}")),
            Extra::Origin if value != SITE_ORIGIN => Err(format!("origin must be {SITE_ORIGIN}, got {value:?}")),
            _ => Ok(()),
        }
    }
}

fn parse_headers(raw: BTreeMap<String, String>) -> Result<Vec<(Extra, String)>, String> {
    let mut out: Vec<(Extra, String)> = Vec::new();
    for (name, value) in raw {
        let allowed = Extra::ALL.map(Extra::name).join(", ");
        let h = Extra::parse(&name).ok_or_else(|| format!("header {name:?} is not allowed (allowed: {allowed})"))?;
        if out.iter().any(|(seen, _)| *seen == h) {
            return Err(format!("header {} is given twice", h.name()));
        }
        h.check(&value)?;
        out.push((h, value));
    }
    Ok(out)
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct RawCase {
    name: String,
    method: Option<String>,
    path: String,
    persona: Option<String>,
    language: Option<String>,
    body: Option<String>,
    content_type: Option<String>,
    headers: Option<BTreeMap<String, String>>,
    #[allow(dead_code)]
    route: Option<String>,
    #[allow(dead_code)]
    hits: Option<u64>,
}

pub fn parse(text: &str, origin: &str, personas: &Personas) -> Result<Vec<Case>, String> {
    let mut out = Vec::new();
    for (n, line) in text.lines().enumerate() {
        let line = line.trim();
        if line.is_empty() || line.starts_with('#') {
            continue;
        }
        let at = |m: String| format!("{origin}:{}: {m}", n + 1);
        let raw: RawCase = serde_json::from_str(line).map_err(|e| at(e.to_string()))?;
        if !raw.path.starts_with('/') || raw.path.chars().any(|c| c.is_whitespace() || c.is_control()) {
            return Err(at(format!("path must start with / and hold no spaces: {:?}", raw.path)));
        }
        let method = raw.method.as_deref().unwrap_or("GET").to_ascii_uppercase();
        let method = Method::from_bytes(method.as_bytes()).map_err(|_| at(format!("bad method {method:?}")))?;
        let persona = raw.persona.unwrap_or_else(|| "anonymous".into());
        if personas.get(&persona).is_none() {
            return Err(at(format!("unknown persona {persona:?} (see `hx-diff personas`)")));
        }
        let language = match raw.language.as_deref() {
            None => Lang::En,
            Some(l) => Lang::parse(l).ok_or_else(|| at(format!("language must be en or fr, got {l:?}")))?,
        };
        let headers = parse_headers(raw.headers.unwrap_or_default()).map_err(at)?;
        out.push(Case {
            name: raw.name,
            method,
            path: raw.path,
            persona,
            language,
            body: raw.body,
            content_type: raw.content_type,
            headers,
        });
    }
    Ok(out)
}

pub fn load(path: &Path, personas: &Personas) -> Result<Vec<Case>, String> {
    let text = std::fs::read_to_string(path).map_err(|e| format!("{}: {e}", path.display()))?;
    parse(&text, &path.display().to_string(), personas)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn personas() -> Personas {
        Personas::embedded().unwrap()
    }

    #[test]
    fn defaults_apply() {
        let c = parse(r#"{"name":"home","path":"/"}"#, "t", &personas()).unwrap();
        assert_eq!(c[0].method, Method::GET);
        assert_eq!(c[0].persona, "anonymous");
        assert_eq!(c[0].language, Lang::En);
    }

    #[test]
    fn comments_and_blank_lines_are_skipped() {
        let text = "# seed\n\n{\"name\":\"a\",\"path\":\"/a\",\"language\":\"fr\",\"persona\":\"customer-active-fr\"}\n";
        let c = parse(text, "t", &personas()).unwrap();
        assert_eq!(c.len(), 1);
        assert_eq!(c[0].language, Lang::Fr);
    }

    #[test]
    fn errors_name_the_file_and_line() {
        let p = personas();
        let e = parse("\n{\"name\":\"a\",\"path\":\"/\",\"persona\":\"nobody\"}", "seed.jsonl", &p).unwrap_err();
        assert!(e.starts_with("seed.jsonl:2:"), "{e}");
        assert!(parse(r#"{"name":"a","path":"no-slash"}"#, "t", &p).is_err());
        assert!(parse(r#"{"name":"a","path":"/a b"}"#, "t", &p).is_err());
        assert!(parse(r#"{"name":"a","path":"/","language":"de"}"#, "t", &p).is_err());
        assert!(parse(r#"{"name":"a","path":"/","colour":"red"}"#, "t", &p).is_err());
        assert!(parse(r#"{"name":"a","path":"/","method":"GE T"}"#, "t", &p).is_err());
    }

    fn one(headers: &str) -> Result<Vec<(Extra, String)>, String> {
        parse(&format!(r#"{{"name":"a","path":"/newuser.php","headers":{headers}}}"#), "t", &personas())
            .map(|mut c| c.remove(0).headers)
    }

    #[test]
    fn allowed_headers_parse_whatever_their_case() {
        let all = concat!(
            r#"{"REFERER":"https://www.harcourt.co/request-access/","x-requested-with":"XMLHttpRequest","#,
            r#""Accept":"text/html","X-Hx-Nav":"1","Origin":"https://www.harcourt.co"}"#
        );
        let h = one(all).unwrap();
        let names: Vec<&str> = h.iter().map(|(e, _)| e.name()).collect();
        assert_eq!(names, ["accept", "origin", "referer", "x-hx-nav", "x-requested-with"]);
        assert!(h.contains(&(Extra::Referer, "https://www.harcourt.co/request-access/".into())));
        assert_eq!(one("{}").unwrap(), vec![]);
        assert_eq!(parse(r#"{"name":"a","path":"/"}"#, "t", &personas()).unwrap()[0].headers, vec![]);
    }

    #[test]
    fn other_header_names_are_corpus_errors() {
        for name in ["Cookie", "Host", "X-Real-IP", "Authorization", "Content-Type", "X-Forwarded-For", "Referrer"] {
            let e = one(&format!(r#"{{"{name}":"x"}}"#)).unwrap_err();
            assert!(e.starts_with("t:1: header") && e.contains("not allowed"), "{e}");
        }
        assert!(one(r#"{"Referer":"https://www.harcourt.co/","referer":"https://www.harcourt.co/x"}"#).is_err());
        assert!(one(r#"{"Accept":5}"#).is_err());
        assert!(one(r#"["Accept"]"#).is_err());
    }

    #[test]
    fn referer_and_origin_stay_on_the_site() {
        assert!(one(r#"{"Referer":"https://www.harcourt.co"}"#).is_ok());
        assert!(one(r#"{"Referer":"https://www.harcourt.co/newuser.php?scscpq_wp=1"}"#).is_ok());
        for bad in [
            "http://www.harcourt.co/",
            "https://harcourt.co/",
            "https://www.harcourt.co.evil.example/",
            "https://www.harcourt.co@evil.example/",
            "https://user@www.harcourt.co/",
            "https://www.harcourt.co:8443/",
            "https://172.31.9.181/",
            "/request-access/",
            "",
        ] {
            assert!(one(&format!(r#"{{"Referer":"{bad}"}}"#)).is_err(), "{bad}");
        }
        for bad in ["https://www.harcourt.co/", "http://www.harcourt.co", "https://evil.example", "null"] {
            assert!(one(&format!(r#"{{"Origin":"{bad}"}}"#)).is_err(), "{bad}");
        }
        assert!(one(r#"{"Accept":"text/html "}"#).is_err());
        assert!(one(r#"{"X-HX-Nav":"é"}"#).is_err());
    }
}
