//! Differential cases, one JSON object per line:
//! `{"name":"catalog","method":"GET","path":"/catalog.php?lang=fr","persona":"customer-active","language":"fr","body":null}`.
//! `method` defaults to GET, `persona` to anonymous, `language` to en. `route` and `hits` are informational (the
//! corpus generator writes them). Blank lines and lines starting with `#` are skipped.

use std::path::Path;

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
        out.push(Case { name: raw.name, method, path: raw.path, persona, language, body: raw.body, content_type: raw.content_type });
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
}
