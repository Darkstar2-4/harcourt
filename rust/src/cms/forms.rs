//! Filling a rendered page's `{{gform:N}}` holes. Gravity Forms belongs to the orders area (see the module docs of
//! `cms`); until it provides `forms::embed`, the markup comes from the WordPress island endpoint
//! (`GET /?hx_form=N&hx_lang=..&hx_page=..`, mu-plugins/hx-form-island.php), cached for an hour per page as the
//! static pages do.

use std::{
    collections::HashMap,
    time::{Duration, Instant},
};

use tokio::sync::RwLock;

use super::{FormEmbed, Lang};

const TTL: Duration = Duration::from_secs(3600);
const CACHE_MAX: usize = 5000;

pub struct Islands {
    /// e.g. https://127.0.0.1:8443, the Apache/PHP stack.
    legacy: String,
    /// The Host header WordPress expects, e.g. www.harcourt.co.
    site: String,
    http: reqwest::Client,
    cache: RwLock<Cache>,
}

/// (form, language, page URI) -> (fetched at, markup).
type Cache = HashMap<(u32, Lang, String), (Instant, String)>;

impl Islands {
    pub fn new(legacy: String, site: String) -> Option<Islands> {
        let http = reqwest::Client::builder()
            .danger_accept_invalid_certs(true)
            .redirect(reqwest::redirect::Policy::none())
            .timeout(Duration::from_secs(30))
            .build()
            .ok()?;
        Some(Islands { legacy, site, http, cache: RwLock::new(HashMap::new()) })
    }

    /// HXWEB_LEGACY and HXWEB_SITE, with main.rs's defaults.
    pub fn from_env() -> Option<Islands> {
        let env = |k: &str, d: &str| std::env::var(k).unwrap_or_else(|_| d.to_string());
        Islands::new(env("HXWEB_LEGACY", "https://127.0.0.1:8443"), env("HXWEB_SITE", "www.harcourt.co"))
    }

    pub async fn markup(&self, form: &FormEmbed<'_>) -> Result<String, String> {
        let key = (form.form, form.lang, form.page_uri.to_string());
        if let Some((at, html)) = self.cache.read().await.get(&key) {
            if at.elapsed() < TTL {
                return Ok(html.clone());
            }
        }
        let id = form.form.to_string();
        let r = self
            .http
            .get(format!("{}/", self.legacy))
            .query(&[("hx_form", id.as_str()), ("hx_lang", form.lang.code()), ("hx_page", form.page_uri)])
            .header(reqwest::header::HOST, &self.site)
            .header(reqwest::header::USER_AGENT, "hxweb/0.1 island")
            .send()
            .await
            .map_err(|e| format!("island {id}: {e}"))?;
        if r.status() != reqwest::StatusCode::OK {
            return Err(format!("island {id} for {}: HTTP {}", form.page_uri, r.status()));
        }
        let html = r.text().await.map_err(|e| format!("island {id} body: {e}"))?;
        let mut cache = self.cache.write().await;
        if cache.len() >= CACHE_MAX {
            cache.retain(|_, (at, _)| at.elapsed() < TTL);
            if cache.len() >= CACHE_MAX {
                cache.clear();
            }
        }
        cache.insert(key, (Instant::now(), html.clone()));
        Ok(html)
    }
}

pub type Markup<'a> = std::pin::Pin<Box<dyn std::future::Future<Output = Result<String, String>> + Send + 'a>>;

/// Where a page's form markup comes from: the island endpoint now, the orders area's `forms::embed` later.
pub trait FormSource: Send + Sync {
    fn markup<'a>(&'a self, form: &'a FormEmbed<'a>) -> Markup<'a>;
}

impl FormSource for Islands {
    fn markup<'a>(&'a self, form: &'a FormEmbed<'a>) -> Markup<'a> {
        Box::pin(Islands::markup(self, form))
    }
}

/// Gravity Forms as the orders area renders them from SQLite, falling back to the WordPress island endpoint for a
/// form that is not imported there.
pub struct OrdersForms {
    pub orders: std::sync::Arc<crate::orders::Orders>,
    pub fallback: Option<Islands>,
}

impl FormSource for OrdersForms {
    fn markup<'a>(&'a self, form: &'a FormEmbed<'a>) -> Markup<'a> {
        Box::pin(async move {
            let lang = match form.lang {
                Lang::En => crate::orders::forms::model::Lang::En,
                Lang::Fr => crate::orders::forms::model::Lang::Fr,
            };
            match crate::orders::forms::island(&self.orders, i64::from(form.form), lang, form.page_uri).await {
                Ok(Some(html)) => return Ok(html),
                Ok(None) => {}
                Err(e) => tracing::warn!("form {}: {e}", form.form),
            }
            match &self.fallback {
                Some(islands) => islands.markup(form).await,
                None => Err(format!("form {} is not available", form.form)),
            }
        })
    }
}

/// Replaces each `{{gform:N}}` in `page`, in order, with `markup(N)`. A hole whose form has no markup is an error,
/// so a page never goes out with a form missing.
pub async fn fill<F, Fut>(page: &str, mut markup: F) -> Result<String, String>
where
    F: FnMut(u32, usize) -> Fut,
    Fut: std::future::Future<Output = Result<String, String>>,
{
    let mut out = String::with_capacity(page.len() + 32_000);
    let mut rest = page;
    let mut index = 0;
    while let Some(start) = rest.find("{{gform:") {
        let after = &rest[start + 8..];
        let Some(end) = after.find("}}") else { break };
        let Ok(form) = after[..end].parse::<u32>() else {
            out.push_str(&rest[..start + 8]);
            rest = after;
            continue;
        };
        out.push_str(&rest[..start]);
        out.push_str(&markup(form, index).await?);
        index += 1;
        rest = &after[end + 2..];
    }
    out.push_str(rest);
    Ok(out)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[tokio::test]
    async fn fills_holes_in_order_and_leaves_other_braces() {
        let out = fill("a{{gform:3}}b{{gform:x}}c{{gform:1}}", |f, i| async move { Ok(format!("[{f}#{i}]")) }).await.unwrap();
        assert_eq!(out, "a[3#0]b{{gform:x}}c[1#1]");
        assert!(fill("{{gform:2}}", |_, _| async { Err("down".to_string()) }).await.is_err());
    }
}
