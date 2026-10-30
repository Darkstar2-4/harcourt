//! What the static export (export/README.md in harcourt-web-bench: manifest.json, chrome/{en,fr}/*.html,
//! pages/<id>/{en,fr}.html) adds to the WordPress tables: each page's head and footer values, the text of every
//! field a ported template prints, as printed, and the menu labels and footer texts in the chrome. `check`
//! renders every ported page from the result and compares it with the export.

use std::{collections::BTreeMap, path::Path};

use serde_json::Value as Json;

use super::Import;
use crate::cms::{
    html::pretty,
    model::{Field, Kind, Lang, Page, PageId, Site},
    theme::{self, probe, Part, Slot, CHROME_PLACEHOLDERS},
};

pub struct Export {
    pub exported_year: String,
    /// (post id, language) -> the manifest's values for that page.
    pub pages: BTreeMap<(i64, Lang), serde_json::Map<String, Json>>,
    pub bodies: BTreeMap<(i64, Lang), String>,
    pub chrome: BTreeMap<(Lang, &'static str), String>,
    /// The manifest's values for WordPress's 404 page, which has no post.
    pub not_found: BTreeMap<Lang, serde_json::Map<String, Json>>,
}

const PARTS: [(Part, &str); 3] = [(Part::Head, "head"), (Part::Header, "header"), (Part::Footer, "footer")];

pub fn read(root: &Path) -> Result<Export, String> {
    let read = |rel: &str| std::fs::read_to_string(root.join(rel)).map_err(|e| format!("{rel}: {e}"));
    let manifest: Json = serde_json::from_str(&read("manifest.json")?).map_err(|e| format!("manifest.json: {e}"))?;
    let exported_year = manifest["exported_at"].as_str().unwrap_or("").get(..4).unwrap_or("").to_string();
    let mut out = Export { exported_year, pages: BTreeMap::new(), bodies: BTreeMap::new(), chrome: BTreeMap::new(), not_found: BTreeMap::new() };
    for lang in Lang::ALL {
        for (part, name) in PARTS {
            let text = read(&format!("chrome/{}/{name}.html", lang.code()))?;
            let text = if part == Part::Head { theme::with_current_script_version(&text) } else { text };
            out.chrome.insert((lang, name), text);
        }
    }
    out.add_pages(root, &manifest)?;
    Ok(out)
}

impl Export {
    /// Adds the posts a capture (capture.rs) wrote under `root`, in the export's manifest form.
    pub fn add_capture(&mut self, root: &Path) -> Result<(), String> {
        let text = std::fs::read_to_string(root.join("manifest.json")).map_err(|e| format!("capture manifest.json: {e}"))?;
        let manifest: Json = serde_json::from_str(&text).map_err(|e| format!("capture manifest.json: {e}"))?;
        self.add_pages(root, &manifest)
    }

    fn add_pages(&mut self, root: &Path, manifest: &Json) -> Result<(), String> {
        let read = |rel: &str| std::fs::read_to_string(root.join(rel)).map_err(|e| format!("{rel}: {e}"));
        for page in manifest["pages"].as_array().ok_or("manifest has no pages")? {
            for lang in Lang::ALL {
                let Some(obj) = page.get(lang.code()).and_then(Json::as_object) else { continue };
                if obj.get("status").and_then(Json::as_i64) == Some(404) {
                    self.not_found.insert(lang, obj.clone());
                    continue;
                }
                let Some(id) = obj.get("post_id").and_then(Json::as_i64) else { continue };
                if let Some(file) = obj.get("file").and_then(Json::as_str) {
                    self.bodies.insert((id, lang), read(file)?);
                }
                self.pages.insert((id, lang), obj.clone());
            }
        }
        Ok(())
    }
}

fn between<'a>(s: &'a str, start: &str, end: &str) -> Option<&'a str> {
    let i = s.find(start)? + start.len();
    let j = s[i..].find(end)? + i;
    Some(&s[i..j])
}

/// The request URI WordPress rendered the page at.
pub fn uri(page: &Page) -> String {
    format!("{}{}", page.path, page.lang.query())
}

#[derive(Debug, Default)]
pub struct Report {
    pub extracted: Vec<(i64, Lang)>,
    pub problems: Vec<String>,
}

/// Copies the per-page values into page meta, then recovers field text from the bodies of ported pages and the
/// site-wide texts from the chrome.
pub fn apply(import: &mut Import, export: &Export) -> Report {
    let mut report = Report::default();
    for ((id, lang), values) in &export.pages {
        if !import.pages.iter().any(|p| p.id.0 == *id && p.lang == *lang) {
            report.problems.push(format!("page {id} ({}) is in the export but not a published page", lang.code()));
            continue;
        }
        let meta = import.meta.entry(PageId(*id)).or_default();
        for name in CHROME_PLACEHOLDERS {
            if let Some(v) = values.get(name).and_then(Json::as_str) {
                meta.insert(name.to_string(), v.to_string());
            }
        }
        let s = |k: &str| values.get(k).and_then(Json::as_str).unwrap_or("");
        let dc = s("seo_description_canonical");
        let ou = s("seo_og_description_url");
        for (key, text, start) in [
            ("description", dc, "<meta name=\"description\" content=\""),
            ("canonical", dc, "<link rel=\"canonical\" href=\""),
            ("og_description", ou, "<meta property=\"og:description\" content=\""),
            ("og_url", ou, "<meta property=\"og:url\" content=\""),
        ] {
            meta.insert(key.into(), between(text, start, "\"").unwrap_or("").to_string());
        }
        if let Some(islands) = values.get("islands") {
            meta.insert("islands".into(), islands.to_string());
        }
    }
    for (lang, values) in &export.not_found {
        let options = import.options.entry(*lang).or_default();
        for name in CHROME_PLACEHOLDERS.iter().chain(&theme::NOT_FOUND_HOLES) {
            let value = match values.get(*name) {
                Some(Json::String(s)) => s.clone(),
                Some(Json::Null) | None => continue,
                Some(other) => other.to_string(),
            };
            options.insert(format!("{}{name}", theme::NOT_FOUND_OPTION), Field { kind: Kind::Html, value });
        }
    }
    chrome_values(import, export, &mut report);
    let mut seen: std::collections::HashMap<Slot, (i64, String)> = std::collections::HashMap::new();
    for page in import.pages.clone() {
        let key = (page.id.0, page.lang);
        let Some(body) = export.bodies.get(&key) else { continue };
        if !theme::ported(page.template) {
            continue;
        }
        let site = import.site();
        let u = uri(&page);
        let Some((skeleton, slots)) = theme::probe_body(&site, &page, &u) else { continue };
        let captured = match probe::extract(&pretty(&skeleton), body) {
            Ok(c) => c,
            Err(m) => {
                report.problems.push(format!("page {} ({}) {}: body {m}", page.id.0, page.lang.code(), page.path));
                continue;
            }
        };
        match probe::values(&slots, captured) {
            Ok(values) => {
                for (slot, text) in values {
                    match seen.get(&slot) {
                        Some((from, earlier)) if *earlier != text => {
                            report.problems.push(format!("{slot:?} shows {earlier:?} on page {from} and {text:?} on page {}", page.id.0));
                        }
                        _ => {
                            seen.insert(slot.clone(), (page.id.0, text.clone()));
                        }
                    }
                    if let Err(e) = store(import, &slot, text, &export.exported_year) {
                        report.problems.push(format!("page {}: {e}", page.id.0));
                    }
                }
                report.extracted.push(key);
            }
            Err(e) => report.problems.push(format!("page {} ({}): {e}", page.id.0, page.lang.code())),
        }
    }
    report
}

/// Menu labels and the footer texts, from the chrome of the first page of each language.
fn chrome_values(import: &mut Import, export: &Export, report: &mut Report) {
    for lang in Lang::ALL {
        let Some(page) = import.pages.iter().find(|p| p.lang == lang && export.pages.contains_key(&(p.id.0, lang))).cloned() else { continue };
        for (part, name) in PARTS {
            let site = import.site();
            let u = uri(&page);
            let (skeleton, slots) = theme::probe_chrome(&site, &page, &u, part);
            let Some(target) = export.chrome.get(&(lang, name)) else { continue };
            let values = probe::extract(&skeleton, target).map_err(|m| m.to_string()).and_then(|c| probe::values(&slots, c));
            match values {
                Ok(values) => {
                    for (slot, text) in values {
                        if let Err(e) = store(import, &slot, text, &export.exported_year) {
                            report.problems.push(format!("chrome {}/{name}: {e}", lang.code()));
                        }
                    }
                }
                Err(e) => report.problems.push(format!("chrome {}/{name}: {e}", lang.code())),
            }
        }
    }
}

/// Writes a recovered value where the slot says it lives. A stored `[year]` shortcode stays a shortcode.
fn store(import: &mut Import, slot: &Slot, text: String, year: &str) -> Result<(), String> {
    let restore_year = |raw: Option<&str>, text: String| -> String {
        match raw {
            Some(raw) if raw.contains("[year]") && !year.is_empty() => text.replace(year, "[year]"),
            _ => text,
        }
    };
    match slot {
        Slot::Field(id, name) => {
            let fields = import.fields.entry(*id).or_default();
            let raw = fields.get(name).map(|f| f.value.clone());
            let value = restore_year(raw.as_deref(), text);
            fields.insert(name.clone(), Field { kind: Kind::Html, value });
        }
        Slot::Meta(id, name) => {
            import.meta.entry(*id).or_default().insert(name.clone(), text);
        }
        Slot::Option(lang, name) => {
            let fields = import.options.entry(*lang).or_default();
            let raw = fields.get(name).map(|f| f.value.clone());
            let value = restore_year(raw.as_deref(), text);
            fields.insert(name.clone(), Field { kind: Kind::Html, value });
        }
        Slot::MenuLabel(id) => {
            let item = import.menus.iter_mut().find(|m| m.id == *id).ok_or_else(|| format!("menu item {id} is not imported"))?;
            item.label = text;
        }
    }
    Ok(())
}

/// The export's page assembled as pages.rs serves it, without the forms.
pub fn exported_page(export: &Export, id: i64, lang: Lang) -> Option<[String; 4]> {
    let values = export.pages.get(&(id, lang))?;
    let fill = |tpl: &str| -> String {
        let mut out = String::new();
        let mut rest = tpl;
        while let Some(start) = rest.find("{{") {
            let Some(len) = rest[start + 2..].find("}}") else { break };
            let name = &rest[start + 2..start + 2 + len];
            out.push_str(&rest[..start]);
            match values.get(name).and_then(Json::as_str) {
                Some(v) if !name.starts_with("gform:") => out.push_str(v),
                _ => out.push_str(&rest[start..start + 4 + len]),
            }
            rest = &rest[start + 4 + len..];
        }
        out.push_str(rest);
        out
    };
    let part = |name: &str| export.chrome.get(&(lang, name)).map(|t| fill(t)).unwrap_or_default();
    Some([part("head"), part("header"), export.bodies.get(&(id, lang)).cloned().unwrap_or_default(), part("footer")])
}

/// Renders every ported page and compares it, part by part, with the export (bodies after the export's
/// whitespace normalization). Returns one line per page.
pub fn check(site: &Site, export: &Export, ported_only: bool) -> Vec<(bool, String)> {
    let mut out = Vec::new();
    for page in &site.pages {
        let Some(expected) = exported_page(export, page.id.0, page.lang) else { continue };
        let label = format!("{:>5} {} {:<12} {}", page.id.0, page.lang.code(), short_template(page), page.path);
        if !theme::ported(page.template) {
            if !ported_only {
                out.push((false, format!("{label}  not ported")));
            }
            continue;
        }
        let u = uri(page);
        // A page that uses a part not ported yet is not served from the cms, like a page of an unported template.
        let Some(rendered) = theme::render_parts(site, page, &u) else {
            if !ported_only {
                out.push((false, format!("{label}  uses an unported part")));
            }
            continue;
        };
        let mut diffs = Vec::new();
        for (i, name) in ["head", "header", "body", "footer"].iter().enumerate() {
            let (got, want) = if i == 2 { (pretty(&rendered[i]), expected[i].clone()) } else { (rendered[i].clone(), expected[i].clone()) };
            if got != want {
                let at = got.bytes().zip(want.bytes()).take_while(|(a, b)| a == b).count();
                let ctx = |s: &str| {
                    let mut a = at.saturating_sub(60);
                    while !s.is_char_boundary(a) {
                        a -= 1;
                    }
                    let mut b = (at + 100).min(s.len());
                    while !s.is_char_boundary(b) {
                        b -= 1;
                    }
                    s[a..b].to_string()
                };
                diffs.push(format!("{name} @{at}: got {:?} want {:?}", ctx(&got), ctx(&want)));
            }
        }
        if diffs.is_empty() {
            out.push((true, format!("{label}  ok")));
        } else {
            out.push((false, format!("{label}  {}", diffs.join(" | "))));
        }
    }
    out
}

fn short_template(page: &Page) -> &'static str {
    let f = page.template.file();
    let f = f.rsplit('/').next().unwrap_or(f);
    let f = f.strip_prefix("template-").unwrap_or(f);
    let f = f.strip_suffix(".php").unwrap_or(f);
    if f.is_empty() {
        "page"
    } else {
        f
    }
}
