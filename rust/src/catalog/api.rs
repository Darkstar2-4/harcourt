//! scscpq_api.php: what the catalog's scripts post. With X-Requested-With: the price block for a part picked in
//! the CAD configurator (action=sku), the profile form (action=profile) and quick CAD (action=quick_cad). Without
//! it: action=download, which hands over a CAD file PARTsolutions generated. Only PARTsolutions hosts are fetched.

use std::{collections::HashMap, sync::Arc, time::Duration};

use axum::{
    body::Bytes,
    extract::{Query, State},
    http::{header, HeaderMap, HeaderValue, StatusCode},
    response::{IntoResponse, Response},
    routing::any,
    Router,
};
use serde_json::{json, Map, Value};
use sqlx::SqlitePool;

use super::{
    forms, html, model,
    profile::Profile,
    registry::{self, Registry},
    strings::{self, Strings},
    viewer::{self, Viewer},
};
use crate::{
    accounts::{
        cookies,
        profile::{self as address, Layout, Profile as AddressProfile},
    },
    api::Auth,
    identity::Role,
    pricing,
    visitor::{Visitor, PHP_CONTENT_TYPE},
};

/// The only hosts quick CAD talks to or downloads from.
const CAD_HOST: &str = ".partcommunity.com";
const POLLS: usize = 60;
const MAX_FILE: usize = 200 << 20;

pub fn router() -> Router<Arc<Auth>> {
    Router::new().route("/scscpq_api.php", any(scscpq_api))
}

/// A PARTsolutions URL, as fetched: https on the default port to a host under partcommunity.com, with no user or
/// password. Anything else is not fetched.
pub fn cad_url(url: &str) -> Option<reqwest::Url> {
    let parsed = reqwest::Url::parse(url).ok()?;
    let under = parsed.domain()?.strip_suffix(CAD_HOST).is_some_and(|sub| !sub.is_empty() && !sub.ends_with('.'));
    (under && parsed.scheme() == "https" && parsed.port().is_none() && parsed.username().is_empty() && parsed.password().is_none()).then_some(parsed)
}

fn params(query: HashMap<String, String>, body: &Bytes) -> HashMap<String, String> {
    let mut p = query;
    for (k, v) in form_urlencoded::parse(body) {
        p.insert(k.into_owned(), v.into_owned());
    }
    p
}

async fn scscpq_api(State(core): State<Arc<Auth>>, visitor: Visitor, headers: HeaderMap, Query(query): Query<HashMap<String, String>>, body: Bytes) -> Response {
    let p = params(query, &body);
    let get = |k: &str| p.get(k).map(String::as_str).unwrap_or("");
    if !headers.contains_key("x-requested-with") {
        let action = get("action").trim();
        if action != "download" {
            return text(format!("function: html_{}", html::esc(action)));
        }
        return match viewer::load(&core.pool, &visitor, viewer::lang_for(&visitor)).await {
            Ok(viewer) if viewer.uses_quick_cad() => download(get("url")).await,
            Ok(_) => text("Error detected Access denied".into()),
            Err(e) => {
                tracing::error!("scscpq_api: {e}");
                (StatusCode::INTERNAL_SERVER_ERROR, "Internal error").into_response()
            }
        };
    }
    let result = async {
        let lang = viewer::lang_for(&visitor);
        let viewer = viewer::load(&core.pool, &visitor, lang).await?;
        let reg = registry::load(&core.pool).await?;
        let strings = strings::load(&core.pool, lang).await?;
        let remembered = AddressProfile::load(&core.pool, &headers, &visitor).await?;
        let profile = Profile::from(&remembered);
        let ctx = Api { pool: &core.pool, viewer: &viewer, reg: &reg, strings: &strings, profile };
        let mut out = Map::new();
        let mut trace = vec!["json"];
        let mut cookies = Vec::new();
        match get("action") {
            "sku" => {
                trace.extend(["json_sku", "configurator"]);
                ctx.sku(&p, &mut out).await?;
            }
            "cad" => trace.push("json_cad"),
            "profile" => {
                trace.push("json_profile");
                cookies = ctx.profile(&p, remembered, &visitor, &mut out).await?;
            }
            "quick_cad" => {
                trace.extend(["json_quick_cad", "json_quick_cad_process"]);
                let status = ctx.quick_cad(get("sku"), &mut out).await;
                out.insert("html".into(), json!({}));
                out.insert("quick_cad_status".into(), json!(status));
            }
            _ => {}
        }
        if viewer.at_least(Role::SuperUser) {
            out.insert("trace".into(), json!(trace));
        }
        Ok::<_, sqlx::Error>((Value::Object(out), cookies))
    }
    .await;
    match result {
        Ok((v, cookies)) => {
            // json_encode($response, JSON_UNESCAPED_UNICODE): slashes escaped, other characters as they are.
            let body = v.to_string().replace('/', "\\/");
            let mut resp = (StatusCode::OK, [(header::CONTENT_TYPE, PHP_CONTENT_TYPE), (header::CACHE_CONTROL, "no-store")], body).into_response();
            for c in cookies {
                resp.headers_mut().append(header::SET_COOKIE, c);
            }
            resp
        }
        Err(e) => {
            tracing::error!("scscpq_api: {e}");
            (StatusCode::INTERNAL_SERVER_ERROR, "Internal error").into_response()
        }
    }
}

fn text(s: String) -> Response {
    (StatusCode::OK, [(header::CONTENT_TYPE, "text/html; charset=UTF-8")], s).into_response()
}

struct Api<'a> {
    pool: &'a SqlitePool,
    viewer: &'a Viewer,
    reg: &'a Registry,
    strings: &'a Strings,
    profile: Profile,
}

impl Api<'_> {
    async fn catalog(&self) -> sqlx::Result<model::Catalog> {
        model::load(self.pool, self.viewer.lang, self.reg, model::Day::from_unix(crate::db::unix_now())).await
    }

    /// The inventory part a configurator number names, as typed or with underscores read as spaces.
    async fn find_part(&self, sku: &str) -> sqlx::Result<Option<model::Part>> {
        match model::part_by_sku(self.pool, sku).await? {
            Some(p) => Ok(Some(p)),
            None => model::part_by_sku(self.pool, &sku.replace('_', " ")).await,
        }
    }

    fn shows(&self, catalog: &model::Catalog, part: &model::Part) -> bool {
        catalog.family(part.subcategory_id).is_some_and(|f| catalog.shows(self.viewer, f))
    }

    /// Whether this number is an inventory part the catalog hides from this viewer. A configured number the inventory
    /// does not hold is the configurator's to answer for; a failed lookup counts as hidden.
    async fn hides(&self, sku: &str) -> bool {
        match self.find_part(sku.trim()).await {
            Ok(None) => false,
            Ok(Some(part)) => self.catalog().await.map_or(true, |c| !self.shows(&c, &part)),
            Err(_) => true,
        }
    }

    /// json_sku(): the configured part's family, number, prices and buttons.
    async fn sku(&self, p: &HashMap<String, String>, out: &mut Map<String, Value>) -> sqlx::Result<()> {
        let data: Map<String, Value> = p.iter().filter_map(|(k, v)| k.strip_prefix("data[").and_then(|k| k.strip_suffix(']')).map(|k| (k.to_string(), json!(v)))).collect();
        if data.is_empty() {
            return Ok(());
        }
        let sku_url = data.get("lina").and_then(Value::as_str).unwrap_or("").to_uppercase();
        let catalog = self.catalog().await?;
        let part = self.find_part(&sku_url).await?.filter(|p| self.shows(&catalog, p));
        let book = pricing::PriceBook::load(self.pool, self.reg.get("local", "configure_contact")).await?;
        let buyer = pricing::Buyer { terms: self.viewer.price, may_buy: self.viewer.active(), currency_code: &self.viewer.currency_code };
        let s = self.strings;
        let mut html_parts: Vec<String> = Vec::new();
        let sku = part.as_ref().map_or(sku_url.clone(), |p| p.sku.clone());
        match part.as_ref().and_then(|p| catalog.family(p.subcategory_id).map(|f| (p, f))) {
            None => {
                html_parts.push(format!("<div>{}<br>{}</div>", s.label("catalog_cad_part_number", "Part number", &[]), html::esc_text(&sku_url)));
                html_parts.push(format!("<div>{}</div>", self.reg.get("local", "configure_contact")));
            }
            Some((part, family)) => {
                html_parts.push(format!("<div>{}: {}</div>", html::esc_text(catalog.category_name(family.category_id)), html::esc_text(&family.name)));
                html_parts.push(format!("<div>{}</div>", html::esc_text(&sku_url)));
                let quote = pricing::quote(&book, &buyer, &family.currency, &pricing::ListPrices::parse(&part.pricing), 1);
                let mut block = pricing::verbose(&book, &buyer, &quote);
                let leadtime = part.leadtime(family);
                if !leadtime.is_empty() {
                    block.push_str(&format!("<p>Typical lead time: {}</p>", html::esc_text(&leadtime)));
                }
                html_parts.push(format!("<div>{block}</div>"));
                if self.viewer.ecommerce && part.orderable() {
                    let field_sku = html::base64_fields(&["sku_cad", &sku]);
                    let field_list = html::base64_fields(&["list_cad", &sku]);
                    let onclick = format!("cart_update(this,{},{});", forms::js_quote(&field_sku), forms::js_quote(&field_list));
                    html_parts.push(format!(
                        "<p>{} {}</p>",
                        forms::text(&field_sku, "", "6", "6", &[("class", "products_sku_quantity")]),
                        forms::button(&s.label("catalog_cart_add", "Add to Cart", &[]), &[("class", "cart_button"), ("onclick", &onclick)])
                    ));
                }
            }
        }
        if self.viewer.uses_quick_cad() {
            let label = s.label("catalog_cad_download", "Download CAD", &[]);
            let onclick = format!("quick_cad('', {},1);", forms::js_quote(&sku));
            html_parts.push(format!("<p>{}</p>", forms::button(&label, &[("title", &label), ("class", "quick_cad_button"), ("onclick", &onclick)])));
        }
        out.insert("data".into(), Value::Object(data));
        out.insert("meta".into(), json!({"source": if part.is_some() { "inventory" } else { "" }, "sku_url": sku_url, "sku": sku, "quantity": 1}));
        out.insert("html".into(), json!(html_parts.join("<br>")));
        Ok(())
    }

    /// json_profile(): check the form (address_class verify() over the posted fields, the ones the form left out at
    /// their values), then json_profile_update(): the account takes the company and address, and the profile is kept
    /// for the visit and in its cookie, where the next request loads it from.
    async fn profile(&self, p: &HashMap<String, String>, remembered: AddressProfile, visitor: &Visitor, out: &mut Map<String, Value>) -> sqlx::Result<Vec<HeaderValue>> {
        let get = |k: &str| p.get(k).map(|v| v.trim().to_string()).unwrap_or_default();
        let layout = Layout::load(self.pool).await?;
        let input = self.profile.posted_form(self.viewer.account_id().is_some(), &layout, p);
        let (mut saved, failed) = address::verify(&layout, &input, remembered);
        saved.generate = i64::from(matches!(get("profile_generate").to_ascii_lowercase().as_str(), "1" | "true"));
        let cad_code = get("cad_code");
        saved.cad_name = sqlx::query_scalar!(r#"SELECT name AS "name!" FROM cad WHERE code = ?1 AND name IS NOT NULL LIMIT 1"#, cad_code).fetch_optional(self.pool).await?;
        saved.cad_code = Some(cad_code);
        let cad_failed = saved.cad_name.is_none();
        let failed_input = |k: &str| if k == "cad_code" { cad_failed } else { failed.iter().any(|(f, _)| f == k) };
        let mut errors: Map<String, Value> = input.iter().map(|(k, _)| (k.clone(), json!(i32::from(failed_input(k))))).collect();
        errors.insert("cad_code_error".into(), json!(i32::from(cad_failed)));
        let count = failed.len() + usize::from(cad_failed);
        out.insert("success_url".into(), json!(""));
        out.insert("profile_tab".into(), json!(""));
        if count > 0 {
            out.insert("html".into(), json!({"profile_message": self.strings.label("catalog_profile_errors", "Correct {count} error(s)", &[("count", &count.to_string())])}));
            out.insert("error".into(), Value::Object(errors));
            return Ok(Vec::new());
        }
        let now = crate::db::unix_now();
        if let Some(id) = self.viewer.account_id() {
            saved.save_address(self.pool, &layout, id).await?;
        }
        let mut cookies: Vec<HeaderValue> = cookies::php_year(cookies::EMAIL, saved.email.as_deref().unwrap_or(""), now).into_iter().collect();
        cookies.extend(saved.remember(self.pool, visitor, now).await?);
        let form = Profile::from(&saved);
        let mut html_out = Map::new();
        html_out.insert("quick_cad_status".into(), json!(form.quick_cad_message(self.viewer, self.strings)));
        html_out.insert("cad_name".into(), json!(form.cad_name));
        html_out.insert("profile_message".into(), json!(self.strings.label("catalog_profile_updated", "Profile Updated", &[])));
        let success_url = get("success_url");
        if crate::routes::local_path(&success_url) {
            out.insert("success_url".into(), json!(success_url));
        } else {
            out.insert("profile_tab".into(), json!(get("profile_tab")));
            html_out.insert("profile_tab".into(), json!(""));
        }
        out.insert("html".into(), Value::Object(html_out));
        out.insert("error".into(), Value::Object(errors));
        Ok(cookies)
    }

    /// json_quick_cad_process(): ask PARTsolutions for the part's CAD in the profile's format; a download is polled
    /// until ready and handed over through action=download.
    async fn quick_cad(&self, sku: &str, out: &mut Map<String, Value>) -> String {
        let s = self.strings;
        let p = &self.profile;
        if p.cad_name.is_empty() {
            return s.label("catalog_cad_invalid_code", "Invalid CAD code: {code}", &[("code", &html::esc_text(&p.cad_code))]);
        }
        if !self.viewer.uses_quick_cad() || sku.trim().is_empty() || self.hides(sku).await {
            return s.label("catalog_cad_quick_unavailable", "Quick CAD not available for {sku}", &[("sku", &html::esc_text(sku))]);
        }
        let server = self.reg.get("cad", "partserver_production");
        let catalog = self.reg.get("cad", "catalog");
        let failed = s.label("catalog_cad_generator_error", "CAD Generator Error", &[]);
        let Some(server) = cad_url(server).filter(|_| !catalog.is_empty()) else { return failed };
        let name = format!("{} {}", p.name_first, p.name_last);
        let mut fields = vec![
            ("cgiaction", if p.generate { "generate" } else { "download" }.to_string()),
            ("part", format!("{{PNO={catalog},{sku}}}")),
            ("format", p.cad_code.clone()),
            ("firm", catalog.to_string()),
            ("server_type", format!("SUPPLIER_EXTERNAL_{catalog}")),
            ("language", "english".into()),
            ("country", p.country_code.clone()),
            ("user", name),
            ("userfirm", p.company_name.clone()),
            ("street", p.address.clone()),
            ("zip", p.zip.clone()),
            ("city", p.city.clone()),
            ("state", p.state.clone()),
            ("email", p.email.clone()),
            ("downloadflags", if p.cad_code == "PDFDATASHEET" { "XML" } else { "zip" }.into()),
            ("zipfilename", format!("{catalog}_{sku}.zip")),
            ("ok_url", if p.generate { "" } else { "<%download_xml%>" }.into()),
            ("ok_url_type", if p.generate { "" } else { "text" }.into()),
        ];
        let key = self.reg.get("cad", "apikey");
        if !key.is_empty() {
            fields.push(("apikey", key.to_string()));
        }
        let Ok(client) = reqwest::Client::builder().timeout(Duration::from_secs(30)).redirect(reqwest::redirect::Policy::none()).build() else { return failed };
        let Ok(resp) = client.post(server).form(&fields).send().await else { return failed };
        if resp.status() != reqwest::StatusCode::OK {
            return failed;
        }
        if p.generate {
            return format!("SKU: {} Format: {} Emailed to: {}", html::esc_text(sku), html::esc_text(&p.cad_name), html::esc_text(&p.email));
        }
        let Some(poll_url) = cad_url(resp.text().await.unwrap_or_default().trim()) else { return failed };
        for _ in 0..POLLS {
            tokio::time::sleep(Duration::from_secs(2)).await;
            let Ok(r) = client.get(poll_url.clone()).send().await else { return failed };
            if r.status().as_u16() >= 400 {
                continue;
            }
            let xml = r.text().await.unwrap_or_default();
            if tag(&xml, "MESSAGE").is_some() {
                return failed;
            }
            if let Some(file) = tag(&xml, "ZIPFILE").or_else(|| tag(&xml, "FILENAME")) {
                let link = poll_url.as_str().replace("download.xml", &file);
                out.insert("url".into(), json!(html::url("/scscpq_api.php", &html::Query::new().with("action", "download").with("url", link))));
                return "CAD File Downloaded Successfully".into();
            }
            return failed;
        }
        failed
    }
}

/// The text of the first `<NAME>` element, enough for PARTsolutions' download.xml.
fn tag(xml: &str, name: &str) -> Option<String> {
    let start = xml.find(&format!("<{name}>"))? + name.len() + 2;
    let end = xml[start..].find(&format!("</{name}>"))? + start;
    Some(xml[start..end].trim().to_string()).filter(|t| !t.is_empty())
}

/// html_download(): the generated file, from a PARTsolutions host only.
async fn download(url: &str) -> Response {
    let url = url.trim();
    if url.is_empty() {
        return text("Error detected Empty URL".into());
    }
    let Some(url) = cad_url(url) else {
        return text("Error detected Malformed URL".into());
    };
    let client = reqwest::Client::builder().timeout(Duration::from_secs(120)).redirect(reqwest::redirect::Policy::none()).build();
    let Ok(Ok(mut resp)) = (match client {
        Ok(c) => Ok(c.get(url.clone()).send().await),
        Err(e) => Err(e),
    }) else {
        return text("Error detected CURL error".into());
    };
    if resp.status() != reqwest::StatusCode::OK {
        return text(format!("Error detected CURL HTTP: {}", resp.status().as_u16()));
    }
    if resp.content_length().is_some_and(|n| n as usize > MAX_FILE) {
        return text("Error detected File too large".into());
    }
    let mut bytes = Vec::new();
    loop {
        match resp.chunk().await {
            Ok(Some(chunk)) if bytes.len() + chunk.len() > MAX_FILE => return text("Error detected File too large".into()),
            Ok(Some(chunk)) => bytes.extend_from_slice(&chunk),
            Ok(None) => break,
            Err(_) => return text("Error detected Empty file".into()),
        }
    }
    if bytes.is_empty() {
        return text("Error detected Empty file".into());
    }
    let name: String = url.path().rsplit('/').next().unwrap_or("").chars().filter(|c| c.is_ascii_alphanumeric() || "._-".contains(*c)).collect();
    let mut r = (StatusCode::OK, bytes).into_response();
    let h = r.headers_mut();
    h.insert(header::CONTENT_TYPE, HeaderValue::from_static("application/octet-stream"));
    if let Ok(v) = HeaderValue::from_str(&format!("attachment; filename=harcourt_{name}")) {
        h.insert(header::CONTENT_DISPOSITION, v);
    }
    h.insert(header::PRAGMA, HeaderValue::from_static("no-cache"));
    h.insert(header::EXPIRES, HeaderValue::from_static("0"));
    r
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_partsolutions_hosts_are_fetched() {
        let fetched = |url: &str| cad_url(url).map(|u| u.to_string());
        assert_eq!(fetched("https://webassistants.partcommunity.com/cgi-bin/cgi2pview.exe").as_deref(), Some("https://webassistants.partcommunity.com/cgi-bin/cgi2pview.exe"));
        assert_eq!(fetched("https://a.b.partcommunity.com/x/download.xml?id=1").as_deref(), Some("https://a.b.partcommunity.com/x/download.xml?id=1"));
        for other in ["http://webassistants.partcommunity.com/x", "https://partcommunity.com.example/x", "https://example.com/x.partcommunity.com", "https://partcommunity.com/x", ""] {
            assert_eq!(fetched(other), None, "{other}");
        }
    }

    #[test]
    fn download_xml_tags() {
        assert_eq!(tag("<X><SECTION><ZIPFILE>a.zip</ZIPFILE></SECTION></X>", "ZIPFILE").as_deref(), Some("a.zip"));
        assert_eq!(tag("<X></X>", "ZIPFILE"), None);
    }
}
