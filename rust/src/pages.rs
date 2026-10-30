//! The former WordPress pages from the static export (see export/README.md), for the catalog page's shell when the
//! cms lacks it: chrome + body + per-page values, then live Gravity Forms islands from the orders area's port. The
//! export is reloaded when any of its files changes.

use std::{
    collections::HashMap,
    path::{Path, PathBuf},
    sync::{Arc, OnceLock},
    time::{Duration, Instant, SystemTime},
};

use axum::{
    body::Body,
    http::{header, StatusCode},
};
use regex::Regex;
use tokio::sync::RwLock;

use crate::App;

const ISLAND_TTL: Duration = Duration::from_secs(3600);
/// Island cache keys include the full request URI (utm tags, catalog queries), so cap it.
const ISLAND_CACHE_MAX: usize = 5000;

pub struct Export {
    chrome: HashMap<(String, String), String>,
    pages: HashMap<(String, String), Entry>,
    stamp: SystemTime,
}

struct Entry {
    body: String,
    values: HashMap<String, String>,
}

pub struct Pages {
    root: PathBuf,
    export: RwLock<Option<Arc<Export>>>,
    islands: RwLock<HashMap<(u32, String, String), (Instant, String)>>,
    placeholder: Regex,
    island: Regex,
}

impl Pages {
    pub fn new(root: PathBuf) -> Self {
        Pages {
            root,
            export: RwLock::new(None),
            islands: RwLock::new(HashMap::new()),
            placeholder: Regex::new(r"\{\{([A-Za-z0-9_]+)\}\}").unwrap(),
            island: Regex::new(r"\{\{gform:(\d+)\}\}").unwrap(),
        }
    }

    /// Reload the export when a file under it changed. Keeps the previous export if the new one does not load.
    pub async fn refresh(&self) {
        let stamp = newest_mtime(&self.root);
        if self.export.read().await.as_ref().is_some_and(|e| Some(e.stamp) == stamp) {
            return;
        }
        let Some(stamp) = stamp else { return };
        match load(&self.root, stamp) {
            Ok(e) => {
                tracing::info!("export loaded: {} pages", e.pages.len());
                *self.export.write().await = Some(Arc::new(e));
            }
            Err(e) => tracing::error!("export not loaded, keeping the previous one: {e}"),
        }
    }

    /// Full HTML for (path, lang) as WordPress would render it at `uri`. Ok(None) when the export has no such page.
    pub async fn assemble(&self, app: &App, path: &str, lang: &str, uri: &str) -> Result<Option<String>, String> {
        let Some(export) = self.export.read().await.clone() else { return Ok(None) };
        let Some(entry) = export.pages.get(&(path.to_string(), lang.to_string())) else { return Ok(None) };
        let mut page = String::new();
        for part in ["head", "header"] {
            page.push_str(&self.fill(&export, lang, part, entry)?);
        }
        page.push_str(&entry.body);
        page.push_str(&self.fill(&export, lang, "footer", entry)?);
        let mut out = String::with_capacity(page.len() + 32_000);
        let mut last = 0;
        for c in self.island.captures_iter(&page) {
            let m = c.get(0).unwrap();
            out.push_str(&page[last..m.start()]);
            let form: u32 = c[1].parse().map_err(|_| "bad island id")?;
            out.push_str(&self.island(app, form, lang, uri).await?);
            last = m.end();
        }
        out.push_str(&page[last..]);
        Ok(Some(out))
    }

    fn fill(&self, export: &Export, lang: &str, part: &str, entry: &Entry) -> Result<String, String> {
        let tpl = export.chrome.get(&(lang.to_string(), part.to_string())).ok_or_else(|| format!("no chrome {lang}/{part}"))?;
        let mut missing = None;
        let out = self.placeholder.replace_all(tpl, |c: &regex::Captures| match entry.values.get(&c[1]) {
            Some(v) => v.clone(),
            None => {
                missing = Some(c[1].to_string());
                String::new()
            }
        });
        match missing {
            Some(name) => Err(format!("{lang}/{part}: no value for {{{{{name}}}}}")),
            None => Ok(out.into_owned()),
        }
    }

    async fn island(&self, app: &App, form: u32, lang: &str, uri: &str) -> Result<String, String> {
        let key = (form, lang.to_string(), uri.to_string());
        if let Some((at, html)) = self.islands.read().await.get(&key) {
            if at.elapsed() < ISLAND_TTL {
                return Ok(html.clone());
            }
        }
        let html = ported_island(app, form, lang, uri).await.ok_or_else(|| format!("form {form} is not available"))?;
        let mut cache = self.islands.write().await;
        if cache.len() >= ISLAND_CACHE_MAX {
            cache.retain(|_, (at, _)| at.elapsed() < ISLAND_TTL);
            if cache.len() >= ISLAND_CACHE_MAX {
                cache.clear();
            }
        }
        cache.insert(key, (Instant::now(), html.clone()));
        Ok(html)
    }
}

const ISLAND_AGENT: &str = "hxweb/0.1 island";

/// The form as the orders area renders it from SQLite (`GET /hx/forms/island`, in-process). None for a form it
/// does not have, which WordPress still renders.
async fn ported_island(app: &App, form: u32, lang: &str, uri: &str) -> Option<String> {
    use tower::ServiceExt;
    let lib = app.lib.clone()?;
    let query = form_urlencoded::Serializer::new(String::new()).append_pair("hx_form", &form.to_string()).append_pair("hx_lang", lang).append_pair("hx_page", uri).finish();
    let req = axum::http::Request::get(format!("/hx/forms/island?{query}"))
        .header(header::HOST, &app.site)
        .header(header::USER_AGENT, ISLAND_AGENT)
        .body(Body::empty())
        .ok()?;
    let resp = lib.oneshot(req).await.ok()?;
    if resp.status() != StatusCode::OK {
        return None;
    }
    let bytes = axum::body::to_bytes(resp.into_body(), 4 << 20).await.ok()?;
    String::from_utf8(bytes.to_vec()).ok()
}

/// What every former WordPress page gets before it leaves, whether it came from the export or from the cms
/// tables: the static Font Awesome icons, the hero background preload, vendored assets, the public host.
pub fn finish(app: &App, html: String) -> String {
    let html = if crate::on("fa-static") { crate::compose::without_fa_kit(app, &html) } else { html };
    let html = if crate::on("bg-preload") { with_hero_bg_preload(&html) } else { html };
    app.public(&app.vendor.localize(&html))
}

/// Most pages open with a hero section whose photo is an inline CSS background (hero-bg-big.jpg on 26 English pages). The browser
/// requests it only once that section is styled, after the head's stylesheets and scripts; on a warm page it was the
/// largest paint, ~200 ms after the first. Preloading it from <head> starts it with the page. Only the first section
/// counts: a background further down (home) is off screen and would compete with what is on it.
fn with_hero_bg_preload(html: &str) -> String {
    static RE: OnceLock<Regex> = OnceLock::new();
    let bg = RE.get_or_init(|| Regex::new(r#"^<section\b[^>]*\sstyle="[^"]*background-image:\s*url\(['"]?([^'")]+)['"]?\)"#).unwrap());
    let Some(first) = html.find("<body").and_then(|b| html[b..].find("<section").map(|s| b + s)) else { return html.to_string() };
    match bg.captures(&html[first..]).filter(|c| is_image_url(&c[1])) {
        Some(c) => html.replacen("</head>", &format!("<link rel=\"preload\" as=\"image\" href=\"{}\" fetchpriority=\"high\">\n</head>", &c[1]), 1),
        None => html.to_string(),
    }
}

/// Three French contact pages carry `background-image:url('474')` (an attachment id, a 404 page if fetched).
fn is_image_url(url: &str) -> bool {
    let path = url.split(['?', '#']).next().unwrap_or("").to_ascii_lowercase();
    (path.starts_with('/') || path.starts_with("http://") || path.starts_with("https://"))
        && [".jpg", ".jpeg", ".png", ".webp", ".gif"].iter().any(|ext| path.ends_with(ext))
}

fn newest_mtime(root: &Path) -> Option<SystemTime> {
    let mut newest = None;
    let mut stack = vec![root.to_path_buf()];
    while let Some(dir) = stack.pop() {
        for e in std::fs::read_dir(&dir).ok()?.flatten() {
            let Ok(meta) = e.metadata() else { continue };
            if meta.is_dir() {
                stack.push(e.path());
            } else if let Ok(m) = meta.modified() {
                newest = Some(newest.map_or(m, |n: SystemTime| n.max(m)));
            }
        }
    }
    newest
}

fn load(root: &Path, stamp: SystemTime) -> Result<Export, String> {
    let read = |rel: &str| std::fs::read_to_string(root.join(rel)).map_err(|e| format!("{rel}: {e}"));
    let manifest: serde_json::Value = serde_json::from_str(&read("manifest.json")?).map_err(|e| format!("manifest: {e}"))?;
    let mut chrome = HashMap::new();
    for lang in ["en", "fr"] {
        for part in ["head", "header", "footer"] {
            chrome.insert((lang.to_string(), part.to_string()), read(&format!("chrome/{lang}/{part}.html"))?);
        }
    }
    let mut pages = HashMap::new();
    for page in manifest["pages"].as_array().ok_or("manifest has no pages")? {
        if page.get("id").and_then(|v| v.as_str()) == Some("404") {
            continue;
        }
        for lang in ["en", "fr"] {
            let Some(obj) = page.get(lang).and_then(|v| v.as_object()) else { continue };
            let url = obj.get("url").and_then(|v| v.as_str()).ok_or("page without url")?;
            let file = obj.get("file").and_then(|v| v.as_str()).ok_or("page without file")?;
            let path = url
                .split_once("://")
                .and_then(|(_, rest)| rest.find('/').map(|i| &rest[i..]))
                .unwrap_or(url)
                .split('?')
                .next()
                .unwrap()
                .to_string();
            let values = obj
                .iter()
                .filter_map(|(k, v)| v.as_str().map(|s| (k.clone(), s.to_string())))
                .collect();
            pages.insert((path, lang.to_string()), Entry { body: read(file)?, values });
        }
    }
    Ok(Export { chrome, pages, stamp })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn hero_bg_preload() {
        let page = "<head>\n</head><body><section class=\"product-hero \" style=\"background-image:url('https://www.harcourt.co/up/hero.jpg');\"></section><section style=\"background-image:url(/up/2.jpg)\"></section></body>";
        assert_eq!(
            with_hero_bg_preload(page),
            page.replacen("</head>", "<link rel=\"preload\" as=\"image\" href=\"https://www.harcourt.co/up/hero.jpg\" fetchpriority=\"high\">\n</head>", 1)
        );
        let plain = "<head></head><body><section class=\"x\"><div style=\"background-image:url(/up/3.jpg)\"></div></section><section style=\"background-image:url(/up/4.jpg)\"></section></body>";
        assert_eq!(with_hero_bg_preload(plain), plain);
        let id = "<head></head><body><section class=\"contact-hero\" style=\"background-image:url('474')\"></section></body>";
        assert_eq!(with_hero_bg_preload(id), id);
    }
}
