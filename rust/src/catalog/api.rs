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
        profile::{self as address, Basis, Layout, Profile as AddressProfile},
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
        let (remembered, basis) = AddressProfile::loaded(&core.pool, &headers, &visitor).await?;
        let profile = Profile::from(&remembered.clone().shown(&headers));
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
                cookies = ctx.profile(&p, remembered, &basis, &visitor, &mut out).await?;
            }
            "quick_cad" => {
                trace.extend(["json_quick_cad", "json_quick_cad_process"]);
                let status = ctx.quick_cad(&p, &visitor, &mut out).await;
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

    /// The family quick CAD's number belongs to (the one the configurator's `info` names, else the inventory part's),
    /// unless the catalog hides the part or that family from this viewer. A configured number the inventory does
    /// not hold is the configurator's to answer for; a failed lookup counts as hidden.
    async fn cad_family(&self, sku: &str, configured: Option<&Configured>) -> Result<Option<(String, i64)>, ()> {
        let catalog = self.catalog().await.map_err(drop)?;
        let part = self.find_part(sku.trim()).await.map_err(drop)?;
        if part.as_ref().is_some_and(|p| !self.shows(&catalog, p)) {
            return Err(());
        }
        let family = match configured {
            Some(c) => catalog.families.values().find(|f| c.names(&f.prj)),
            None => part.as_ref().and_then(|p| catalog.family(p.subcategory_id)),
        };
        match family {
            Some(f) if !catalog.shows(self.viewer, f) => Err(()),
            Some(f) => Ok(Some((format!("{}: {}", catalog.category_name(f.category_id), f.name), f.id))),
            None => Ok(None),
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
    async fn profile(&self, p: &HashMap<String, String>, remembered: AddressProfile, basis: &Basis, visitor: &Visitor, out: &mut Map<String, Value>) -> sqlx::Result<Vec<HeaderValue>> {
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
        let mut cookies = Vec::new();
        if let Some(kept) = saved.remember(self.pool, visitor, basis, now).await? {
            cookies.extend(cookies::php_year(cookies::EMAIL, saved.email.as_deref().unwrap_or(""), now));
            cookies.push(kept);
        }
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

    /// json_quick_cad_process(): ask PARTsolutions for the CAD of the part's number, or of the configurator's part
    /// (`lina`) when its answer is posted as `configurator`, in the profile's format; a download is polled until
    /// ready and handed over through action=download. Each request is logged under PARTSolutions.
    async fn quick_cad(&self, p: &HashMap<String, String>, visitor: &Visitor, out: &mut Map<String, Value>) -> String {
        let s = self.strings;
        let profile = &self.profile;
        if profile.cad_name.is_empty() {
            return s.label("catalog_cad_invalid_code", "Invalid CAD code: {code}", &[("code", &html::esc_text(&profile.cad_code))]);
        }
        let configured = p.get("configurator").map(|json| Configured::parse(json));
        let sku = match &configured {
            Some(c) => c.lina.as_str(),
            None => p.get("sku").map_or("", String::as_str),
        };
        let unavailable = || s.label("catalog_cad_quick_unavailable", "Quick CAD not available for {sku}", &[("sku", &html::esc_text(sku))]);
        if !self.viewer.uses_quick_cad() || (configured.is_none() && sku.trim().is_empty()) {
            return unavailable();
        }
        let Ok(family) = self.cad_family(sku, configured.as_ref()).await else { return unavailable() };
        let result = self.generate(sku).await;
        let mut comment: Vec<String> = family.iter().map(|(name, _)| name.clone()).collect();
        comment.push(format!("CAD Format: {}", profile.cad_name));
        let (subtype, status) = match &result {
            Err(e) => {
                comment.push(e.clone());
                ("Quick CAD Error", s.label("catalog_cad_generator_error", "CAD Generator Error", &[]))
            }
            Ok(Generated::Emailed) => {
                comment.extend(["Emailed to user".to_string(), "Transaction ID: ".to_string()]);
                ("Quick CAD", format!("SKU: {} Format: {} Emailed to: {}", html::esc_text(sku), html::esc_text(&profile.cad_name), html::esc_text(&profile.email)))
            }
            Ok(Generated::File { link, order }) => {
                let label = format!("Download File ({})", html::esc_text(sku));
                comment.push(html::href_with(&label, link, &[("target", "_blank"), ("title", "Download CAD")]));
                comment.push(format!("Transaction ID: {order}"));
                out.insert("url".into(), json!(html::url("/scscpq_api.php", &html::Query::new().with("action", "download").with("url", link.as_str()))));
                ("Quick CAD", "CAD File Downloaded Successfully".into())
            }
        };
        self.log_quick_cad(visitor, subtype, sku, family.map_or(0, |(_, id)| id), &comment.join("\n")).await;
        status
    }

    /// cad_generate_class: the request for this number, then, for a download, the polls until the file is named.
    /// The error is the text the log keeps.
    async fn generate(&self, sku: &str) -> Result<Generated, String> {
        let p = &self.profile;
        if sku.is_empty() {
            return Err("SKU cannot blank".into());
        }
        let Some(server) = cad_url(self.reg.get("cad", "partserver_production")) else { return Err("PARTserver URL Missing".into()) };
        let catalog = self.reg.get("cad", "catalog");
        if catalog.is_empty() {
            return Err("PARTserver Catalog Missing".into());
        }
        let fields = cad_request(p, catalog, self.reg.get("cad", "apikey"), sku);
        let curl = |e: reqwest::Error| format!("CURL error: {e}");
        let client = reqwest::Client::builder().timeout(Duration::from_secs(30)).redirect(reqwest::redirect::Policy::none()).build().map_err(curl)?;
        let resp = client.post(server).form(&fields).send().await.map_err(curl)?;
        if resp.status() != reqwest::StatusCode::OK {
            return Err(format!("HTTP error: {}", resp.status().as_u16()));
        }
        if p.generate {
            return Ok(Generated::Emailed);
        }
        let Some(poll_url) = cad_url(resp.text().await.unwrap_or_default().trim()) else { return Err("No response from server".into()) };
        for _ in 0..POLLS {
            tokio::time::sleep(Duration::from_secs(2)).await;
            let Ok(r) = client.get(poll_url.clone()).send().await else { return Err(format!("Cannot connect to {poll_url}")) };
            if r.status().as_u16() >= 400 {
                continue;
            }
            let xml = r.text().await.unwrap_or_default();
            if let Some(message) = tag(&xml, "MESSAGE") {
                return Err(message);
            }
            return match tag(&xml, "ZIPFILE").or_else(|| tag(&xml, "FILENAME")) {
                Some(file) => Ok(Generated::File { link: poll_url.as_str().replace("download.xml", &file), order: tag(&xml, "ORDERNO").unwrap_or_default() }),
                None => Err("Unknown CURL error".into()),
            };
        }
        Err("Unknown CURL error".into())
    }

    /// log->update(['PARTSolutions', subtype]): the profile's address is the row's email, as the PHP's log kept it.
    async fn log_quick_cad(&self, visitor: &Visitor, subtype: &str, sku: &str, family_id: i64, comment: &str) {
        let ip = visitor.client.map(|ip| ip.to_string()).unwrap_or_default();
        let (now, user_id) = (crate::db::unix_now(), visitor.user().map_or(0, |u| u.id.0));
        let email = &self.profile.email;
        let result = sqlx::query!(
            "INSERT INTO log (ip, date_time, user_id, bot_id, email, type, subtype, sku, subcategory_id, comment) VALUES (?1, ?2, ?3, 0, ?4, 'PARTSolutions', ?5, ?6, ?7, ?8)",
            ip,
            now,
            user_id,
            email,
            subtype,
            sku,
            family_id,
            comment
        )
        .execute(self.pool)
        .await;
        if let Err(e) = result {
            tracing::warn!("quick CAD log write failed: {e}");
        }
    }
}

/// What PARTsolutions answered quick CAD: the file is being mailed, or a link to it and the order number.
enum Generated {
    Emailed,
    File { link: String, order: String },
}

/// The configurator's PCOM_INFO answer, as quick CAD posts it from the Configure tab: the number it shows (`lina`)
/// and the project its part id names (`partId`, `(info)(varset)`).
#[derive(Debug, Default, PartialEq)]
struct Configured {
    lina: String,
    info: String,
}

impl Configured {
    fn parse(json: &str) -> Configured {
        let v: Value = serde_json::from_str(json).unwrap_or_default();
        let field = |k: &str| match v.get(k) {
            Some(Value::String(s)) => s.clone(),
            Some(n @ Value::Number(_)) => n.to_string(),
            _ => String::new(),
        };
        Configured { lina: field("lina"), info: mident_info(&field("partId")).unwrap_or("").to_string() }
    }

    /// Whether a family's project (subcategory.prj) is this part's, `info={info}`, compared as MariaDB compared it.
    fn names(&self, prj: &str) -> bool {
        !self.info.is_empty() && crate::staff::filesort::ci_eq(prj, &format!("info={}", self.info))
    }
}

/// parse_mident: the `info` of a part id `(info)(varset)`.
fn mident_info(mident: &str) -> Option<&str> {
    mident.strip_prefix('(')?.strip_suffix(')')?.rsplit_once(")(").map(|(info, _)| info)
}

/// The fields cad_generate_class posts for a number (`generate_sku`: the part named by catalog and number).
fn cad_request(p: &Profile, catalog: &str, apikey: &str, sku: &str) -> Vec<(&'static str, String)> {
    let mut fields = vec![
        ("cgiaction", if p.generate { "generate" } else { "download" }.to_string()),
        ("part", format!("{{PNO={catalog},{sku}}}")),
        ("format", p.cad_code.clone()),
        ("firm", catalog.to_string()),
        ("server_type", format!("SUPPLIER_EXTERNAL_{catalog}")),
    ];
    if !apikey.is_empty() {
        fields.push(("apikey", apikey.to_string()));
    }
    fields.extend([
        ("language", "english".into()),
        ("country", p.country_code.clone()),
        ("user", format!("{} {}", p.name_first, p.name_last)),
        ("userfirm", p.company_name.clone()),
        ("street", p.address.clone()),
        ("zip", p.zip.clone()),
        ("city", p.city.clone()),
        ("state", p.state.clone()),
        ("phone", p.phone.clone()),
        ("email", p.email.clone()),
        ("downloadflags", if p.cad_code == "PDFDATASHEET" { "XML" } else { "zip" }.into()),
        ("zipfilename", format!("{catalog}_{sku}.zip")),
        ("ok_url", if p.generate { "" } else { "<%download_xml%>" }.into()),
        ("ok_url_type", if p.generate { "" } else { "text" }.into()),
    ]);
    fields
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
    fn the_configurator_answer_names_its_number_and_project() {
        let posted = r#"{"standardName":"Ball Lock Pin","lina":"PIN_10","partId":"(harcourt/pins/pin.prj)({LEN=10},{D=6})"}"#;
        assert_eq!(Configured::parse(posted), Configured { lina: "PIN_10".into(), info: "harcourt/pins/pin.prj".into() });
        assert_eq!(mident_info("(a/(b).prj)({X=1})"), Some("a/(b).prj"));
        assert_eq!(mident_info("harcourt/pins/pin.prj"), None);
        assert_eq!(Configured::parse("not json"), Configured::default());
        let pin = Configured::parse(posted);
        assert!(pin.names("INFO=harcourt/pins/pin.prj"));
        assert!(!pin.names("info=harcourt/pins/pin.prj&varset={LEN=20}"));
        assert!(!Configured::default().names("info="));
    }

    #[test]
    fn quick_cad_asks_for_the_number_in_the_catalog() {
        let p = Profile { email: "buyer@example.com".into(), name_first: "Pat".into(), name_last: "Lee".into(), cad_code: "STEP".into(), phone: "555-0100".into(), ..Default::default() };
        let fields: HashMap<_, _> = cad_request(&p, "harcourt", "", "PIN_10").into_iter().collect();
        assert_eq!(fields["part"], "{PNO=harcourt,PIN_10}");
        assert_eq!(fields["zipfilename"], "harcourt_PIN_10.zip");
        assert_eq!((fields["cgiaction"].as_str(), fields["ok_url"].as_str(), fields["ok_url_type"].as_str()), ("download", "<%download_xml%>", "text"));
        assert_eq!((fields["user"].as_str(), fields["phone"].as_str(), fields["email"].as_str()), ("Pat Lee", "555-0100", "buyer@example.com"));
        assert!(!fields.contains_key("apikey"));
        let emailed = Profile { generate: true, ..p };
        let fields: HashMap<_, _> = cad_request(&emailed, "harcourt", "k1", "PIN_10").into_iter().collect();
        assert_eq!((fields["cgiaction"].as_str(), fields["ok_url"].as_str(), fields["apikey"].as_str()), ("generate", "", "k1"));
    }

    #[test]
    fn download_xml_tags() {
        assert_eq!(tag("<X><SECTION><ZIPFILE>a.zip</ZIPFILE></SECTION></X>", "ZIPFILE").as_deref(), Some("a.zip"));
        assert_eq!(tag("<X></X>", "ZIPFILE"), None);
    }
}
