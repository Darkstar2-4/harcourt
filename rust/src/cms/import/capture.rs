//! The static export holds WordPress's render of every page; this adds the other post types' singles (FAQs, blog
//! posts, case studies, testimonials) in the same form, fetched once from a running WordPress: a manifest entry per
//! post and language with the value of each chrome hole, and the body. The import reads it next to the export, so a
//! post's head (Yoast's lines, the plugins' scripts) is kept as WordPress printed it, as a page's is.

use std::path::Path;

use serde_json::{json, Map, Value as Json};

use super::{export::Export, Import};
use crate::cms::{
    model::{PostType, Status},
    theme::probe,
};

/// The chrome templates of `lang` with every `{{hole}}` as a probe sentinel, and the hole names by sentinel index.
fn skeleton(export: &Export, lang: crate::cms::Lang) -> Result<(String, Vec<String>), String> {
    let mut names = Vec::new();
    let mut out = String::new();
    for part in ["head", "header", "body", "footer"] {
        if part == "body" {
            out.push_str(&probe::sentinel(names.len()));
            names.push("@body".to_string());
            continue;
        }
        let tpl = export.chrome.get(&(lang, part)).ok_or_else(|| format!("the export has no {} {part}", lang.code()))?;
        let mut rest = tpl.as_str();
        while let Some(start) = rest.find("{{") {
            let Some(len) = rest[start + 2..].find("}}") else { break };
            out.push_str(&rest[..start]);
            out.push_str(&probe::sentinel(names.len()));
            names.push(rest[start + 2..start + 2 + len].to_string());
            rest = &rest[start + 4 + len..];
        }
        out.push_str(rest);
    }
    Ok((out, names))
}

/// Fetches every published post that is not a page from `origin` (sent as `host`), splits it into the export's
/// chrome holes and body, and writes `manifest.json` and `pages/post-<id>/<lang>.html` under `out`. Returns one
/// line per post that could not be captured.
pub async fn capture(import: &Import, export: &Export, origin: &str, host: &str, insecure: bool, out: &Path) -> Result<Vec<String>, String> {
    let http = reqwest::Client::builder()
        .danger_accept_invalid_certs(insecure)
        .redirect(reqwest::redirect::Policy::none())
        .build()
        .map_err(|e| format!("http client: {e}"))?;
    let mut problems = Vec::new();
    let mut entries: std::collections::BTreeMap<i64, Map<String, Json>> = std::collections::BTreeMap::new();
    for p in import.pages.iter().filter(|p| p.kind != PostType::Page && p.status == Status::Publish) {
        let uri = format!("{}{}", p.path, p.lang.query());
        let r = http
            .get(format!("{origin}{uri}"))
            .header(reqwest::header::HOST, host)
            .header(reqwest::header::ACCEPT_ENCODING, "identity")
            .send()
            .await
            .map_err(|e| format!("{uri}: {e:?}"))?;
        if r.status() != reqwest::StatusCode::OK {
            problems.push(format!("{uri}: HTTP {}", r.status()));
            continue;
        }
        // The theme's script version is code, not content: read the page as the current theme prints it.
        let html = crate::cms::theme::with_current_script_version(&r.text().await.map_err(|e| format!("{uri}: {e}"))?);
        let (skeleton, names) = skeleton(export, p.lang)?;
        let captured = match probe::extract(&skeleton, &html) {
            Ok(c) => c,
            Err(m) => {
                problems.push(format!("{uri}: chrome {m}"));
                continue;
            }
        };
        let mut values = Map::new();
        let mut body = String::new();
        for (i, text) in captured {
            match names[i].as_str() {
                // The export stores bodies normalized (html::pretty); so does the capture.
                "@body" => body = crate::cms::html::pretty(&text),
                name if name.starts_with("gform:") => {}
                name => {
                    values.entry(name.to_string()).or_insert(Json::String(text));
                }
            }
        }
        if body.contains("gform_wrapper") {
            problems.push(format!("{uri}: a form in the body, whose embedding the capture does not know"));
            continue;
        }
        let file = format!("pages/post-{}/{}.html", p.id.0, p.lang.code());
        let dir = out.join(format!("pages/post-{}", p.id.0));
        std::fs::create_dir_all(&dir).map_err(|e| format!("{}: {e}", dir.display()))?;
        std::fs::write(out.join(&file), &body).map_err(|e| format!("{file}: {e}"))?;
        let islands = export.not_found.get(&p.lang).and_then(|v| v.get("islands")).cloned().unwrap_or_else(|| json!([]));
        values.insert("post_id".into(), json!(p.id.0));
        values.insert("url".into(), json!(p.url(crate::cms::theme::ORIGIN)));
        values.insert("status".into(), json!(200));
        values.insert("file".into(), json!(file));
        values.insert("islands".into(), islands);
        entries.entry(p.group_id).or_default().insert(p.lang.code().to_string(), Json::Object(values));
    }
    let pages: Vec<Json> = entries
        .into_iter()
        .map(|(group, langs)| {
            let mut entry = langs;
            entry.insert("id".into(), json!(format!("post-{group}")));
            Json::Object(entry)
        })
        .collect();
    let manifest = json!({ "pages": pages });
    std::fs::create_dir_all(out).map_err(|e| format!("{}: {e}", out.display()))?;
    std::fs::write(out.join("manifest.json"), serde_json::to_string_pretty(&manifest).unwrap_or_default()).map_err(|e| format!("manifest.json: {e}"))?;
    Ok(problems)
}
