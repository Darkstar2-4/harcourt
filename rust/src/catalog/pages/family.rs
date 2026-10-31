//! A product family's page (catalog.php subcategory(), output_subcategory() and the tabs it builds), which is
//! also the part page (action=sku adds the part's tab) and where a part-number lookup lands.

use super::{Ctx, Page};
use crate::{
    catalog::{
        forms,
        html::{self, esc_text, href, href_with, Query},
        model::{self, Family, Parameter, Part},
        tabs::Tabs,
    },
    pricing::{self, ListPrices},
    staff::catalog::php_floatval,
};

/// $database->query_limit: parts per page on a family with page breaks.
const PAGE_SIZE: usize = 50;

/// The parts the family lists for this request (query_subcategory()): the family must be visible to the viewer
/// and its category active; selected search parameters and the new-items filter narrow it.
fn listed<'p>(ctx: &Ctx, family: &Family, parts: &'p [Part]) -> Vec<&'p Part> {
    let category_active = ctx.catalog.categories.get(&family.category_id).is_some_and(|c| c.active);
    if !family.status.visible(ctx.viewer.sees_pending()) || !category_active {
        return Vec::new();
    }
    parts
        .iter()
        .filter(|p| family.searchable().all(|param| matches_param(p, param, &ctx.req.parameter[param.id])))
        .filter(|p| !ctx.req.new_date || ctx.catalog.is_new_date(&p.new_date) || ctx.catalog.is_new_date(&family.new_date))
        .collect()
}

/// MySQL's comparison: numbers as numbers, text case-insensitively and ignoring trailing spaces. No value selected
/// matches everything.
fn matches_param(part: &Part, param: &Parameter, wanted: &str) -> bool {
    if wanted.is_empty() {
        return true;
    }
    if param.numeric() {
        part.nparameter[param.id] == php_floatval(wanted)
    } else {
        part.parameter[param.id].trim_end().eq_ignore_ascii_case(wanted.trim_end())
    }
}

/// The values a search select offers: those of the listed parts under the other selections, once each, in order.
fn values(ctx: &Ctx, family: &Family, parts: &[Part], param: &Parameter) -> Vec<String> {
    let category_active = ctx.catalog.categories.get(&family.category_id).is_some_and(|c| c.active);
    if !family.status.visible(ctx.viewer.sees_pending()) || !category_active {
        return Vec::new();
    }
    let others = parts.iter().filter(|p| family.searchable().filter(|o| o.id != param.id).all(|o| matches_param(p, o, &ctx.req.parameter[o.id])));
    if param.numeric() {
        let mut nums: Vec<f64> = others.map(|p| p.nparameter[param.id]).filter(|n| *n != 0.0).collect();
        nums.sort_by(|a, b| a.total_cmp(b));
        nums.dedup();
        nums.into_iter().map(|n| param.output(n)).collect()
    } else {
        let mut texts: Vec<String> = others.map(|p| p.parameter[param.id].clone()).filter(|t| !t.is_empty()).collect();
        texts.sort_by_key(|t| t.to_lowercase());
        texts.dedup_by(|a, b| a.trim_end().eq_ignore_ascii_case(b.trim_end()));
        texts
    }
}

/// PHP wordwrap($s, $width, $break) without cutting words.
fn wordwrap(s: &str, width: usize, brk: &str) -> String {
    let mut out = String::new();
    let mut line = 0;
    for (i, word) in s.split(' ').enumerate() {
        if i == 0 {
            line = word.len();
        } else if line + 1 + word.len() > width {
            out.push_str(brk);
            line = word.len();
        } else {
            out.push(' ');
            line += 1 + word.len();
        }
        out.push_str(&esc_text(word));
    }
    out
}

fn content_link(id: i64) -> String {
    html::url("/scs_file.php", &Query::new().with("content_id", html::base64_fields(&[&id.to_string(), "0"])))
}

fn is_new(ctx: &Ctx, family: &Family) -> bool {
    ctx.catalog.is_new_date(&family.new_date)
}

/// subcategory_title().
fn title(ctx: &Ctx, family: &Family) -> String {
    let mut name = esc_text(&family.name);
    if is_new(ctx, family) {
        name.push_str(" (New Item!)");
    } else if family.new_inventory {
        name.push_str(" (New Sizes!)");
    }
    let mut out = vec!["<div id=products_header>".to_string(), format!("<h1>{name}</h1>"), "</div>".into(), "<div class=products_content>".into()];
    if !family.thumbnail_url.is_empty() {
        out.push(format!("<div style='display:none;'><img src='{}' id=cart_thumbnail></div>", html::esc_attr(&family.thumbnail_url)));
    }
    out.push("<div id=products_image>".into());
    for src in [&family.image_url, &family.sketch_url] {
        if let Some(img) = forms::img(src, &[("align", "top"), ("width", "450")]) {
            out.push(format!("<span>{img}</span>"));
        }
    }
    out.push("</div>".into());
    out.push("</div>".into());
    out.join("\n")
}

/// output_subcategory(): the family page, and the part's tab when a part is named.
pub async fn page(ctx: &mut Ctx<'_>) -> sqlx::Result<Page> {
    page_listing(ctx, None).await
}

/// output_subcategory() with the parts to list given, as a search that matched several parts of one family does.
pub async fn page_listing(ctx: &mut Ctx<'_>, listing: Option<Vec<Part>>) -> sqlx::Result<Page> {
    let Some(family) = ctx.family().cloned() else { return Ok(Page { head: String::new(), tabs: Tabs::new(), tail: String::new() }) };
    if ctx.blocked(family.category_id) {
        let head = format!("<div id=products_header>\n<h1>{}</h1>\n</div>\n{}", esc_text(&family.name), ctx.overlay(family.category_id));
        return Ok(Page { head, tabs: Tabs::new(), tail: String::new() });
    }
    let all = model::family_parts(ctx.pool, family.id).await?;
    let given = listing.unwrap_or_default();
    let matching = if given.is_empty() { listed(ctx, &family, &all) } else { given.iter().collect() };
    let found = matching.len();
    let shown: Vec<&Part> = if family.page_break && !ctx.viewer.is_bot() && given.is_empty() {
        let page = ctx.req.page.max(1) as usize;
        matching.iter().skip((page - 1) * PAGE_SIZE).take(PAGE_SIZE).copied().collect()
    } else {
        matching.clone()
    };
    let mut tabs = Tabs::new();
    let mut head = title(ctx, &family);
    head.push_str("<div id='cart_dialog'><div id='cart_contents'></div></div>\n");
    overview(ctx, &family, &mut tabs).await?;
    if !family.prj_landing_page {
        if ctx.req.page != 0 {
            tabs.land_next();
        }
        let products = inventory(ctx, &family, &all, &shown, found, &mut tabs);
        tabs.item(ctx.strings.get("catalog_products"), products, Some("catalog_products"), None);
    }
    customs(ctx, &family, &mut tabs).await?;
    if !ctx.req.sku.is_empty() {
        ctx.part = model::part_by_sku(ctx.pool, &ctx.req.sku).await?;
        sku_tab(ctx, &family, &mut tabs);
    }
    cad(ctx, &family, &mut tabs);
    download(ctx, &family, &mut tabs).await?;
    let tail = finish(ctx, &mut tabs).await?;
    Ok(Page { head, tabs, tail })
}

/// output_sku(): a part found by search on its own page, without the family's parts list.
pub async fn part_page(ctx: &mut Ctx<'_>, part: Part) -> sqlx::Result<Page> {
    ctx.subcategory_id = part.subcategory_id;
    ctx.part = Some(part);
    let Some(family) = ctx.family().cloned() else { return Ok(Page { head: String::new(), tabs: Tabs::new(), tail: String::new() }) };
    ctx.category_id = family.category_id;
    let mut tabs = Tabs::new();
    let head = title(ctx, &family);
    sku_tab(ctx, &family, &mut tabs);
    overview(ctx, &family, &mut tabs).await?;
    cad(ctx, &family, &mut tabs);
    download(ctx, &family, &mut tabs).await?;
    let tail = finish(ctx, &mut tabs).await?;
    Ok(Page { head, tabs, tail })
}

/// tabs_output(): the quick-CAD status line and, except for crawlers, the Profile tab.
async fn finish(ctx: &Ctx<'_>, tabs: &mut Tabs) -> sqlx::Result<String> {
    if !ctx.viewer.is_bot() {
        let form = crate::catalog::profile::enter(ctx, None).await?;
        let form = form.trim();
        tabs.item(ctx.strings.get("catalog_profile"), (!form.is_empty()).then(|| form.to_string()), Some("catalog_profile"), None);
    }
    Ok(format!("<div class=center id=quick_cad_status>{}</div>", ctx.profile.quick_cad_message(ctx.viewer, &ctx.strings)))
}

fn url_base(ctx: &Ctx, family: &Family, action: &str) -> Query {
    let mut q = Query::new().with("lang", ctx.lang_param()).with("action", action).with("subcategory_id", family.id.to_string());
    if ctx.req.debug {
        q.set("debug", "1");
    }
    if !ctx.req.search_code.is_empty() {
        q.set("search_code", ctx.req.search_code.clone());
    }
    if ctx.req.new_date {
        q.set("new_date", "1");
    }
    q
}

fn related_link(ctx: &Ctx, value: &str, sku: &str) -> String {
    let mut q = Query::new().with("action", "related").with("sku", html::base64_fields(&[value])).with("related", html::base64_fields(&[sku]));
    if ctx.req.debug {
        q.set("debug", "1");
    }
    href_with(&esc_text(value), &html::url(ctx.php_self, &q), &[("title", "Related Part#")])
}

/// search(): the parametric search selects and the script that reloads the page with them.
fn search(ctx: &Ctx, family: &Family, parts: &[Part], tabs: &mut Tabs) -> Option<String> {
    if family.parameter_search.is_empty() {
        return None;
    }
    if ctx.req.parameter.iter().any(|p| !p.is_empty()) {
        tabs.land_next();
    }
    let mut q = Query::new().with("lang", ctx.lang_param()).with("action", "subcategory").with("subcategory_id", family.id.to_string());
    if !ctx.req.search_code.is_empty() {
        q.set("search_code", ctx.req.search_code.clone());
    }
    if ctx.req.new_date {
        q.set("new_date", "1");
    }
    let mut pairs: Vec<(String, String)> = q.pairs().to_vec();
    for (i, v) in ctx.req.parameter.iter().enumerate().filter(|(_, v)| !v.is_empty()) {
        pairs.push((format!("parameter{i}"), v.clone()));
    }
    pairs.push(("page".into(), "1".into()));
    pairs.push(("search_complete".into(), "1".into()));
    let js_pairs: Vec<String> = pairs.iter().map(|(k, v)| format!("{}: {}", forms::js_quote(k), forms::js_quote(v))).collect();
    let mut out = String::from("<script type='text/javascript'>function fn_search_select(obj,search_complete) {");
    out.push_str(&format!("var url_query={{{}}};", js_pairs.join(", ")));
    out.push_str("if (search_complete=='0') {  url_query['search_complete']='0';  url_query[obj.id]=obj.value;}");
    out.push_str(&format!("window.location.href = '{}?' + $.param(url_query);}}</script>", ctx.php_self));
    out.push_str("<p class='standard'>");
    for id in &family.parameter_search {
        let Some(param) = family.parameter(*id) else { continue };
        let options = values(ctx, family, parts, param);
        if options.len() < 2 {
            continue;
        }
        out.push_str(&format!("<span class=nobr>{} : ", esc_text(&param.name)));
        out.push_str(&forms::select_list(&format!("parameter{id}"), &options, &ctx.req.parameter[*id], &[("onchange", "fn_search_select(this,'0');")]));
        out.push_str("</span>");
    }
    out.push_str("</p>");
    Some(out)
}

/// inventory(): the Products tab, or nothing for a family that only shows CAD or has no parts to list.
fn inventory(ctx: &Ctx, family: &Family, all: &[Part], shown: &[&Part], found: usize, tabs: &mut Tabs) -> Option<String> {
    if !family.output.is_empty() || shown.is_empty() {
        return None;
    }
    if ctx.req.action == "subcategory" && !ctx.req.search_code.is_empty() {
        tabs.land_next();
    }
    let blocked = ctx.blocked(family.category_id);
    let buyer = ctx.buyer();
    let shows_price = ctx.viewer.price.is_some();
    let mut out: Vec<String> = vec![forms::hidden("cart_quantity", "")];
    out.push(search(ctx, family, all, tabs).unwrap_or_default());
    if !family.description.is_empty() {
        out.push(format!("<div class='products_content' style='margin-bottom: 10px;'>{}</div>", family.description));
    }
    if ctx.viewer.ecommerce {
        out.push(pricing::currency_note(&ctx.book, &buyer, true).unwrap_or_default());
    }
    if !ctx.related_sku.is_empty() {
        out.push(related_message(ctx));
    }
    out.push("<table class='products_table'>".into());
    out.push("<thead>".into());
    out.push("<tr>".into());
    out.push(format!("<th>{}</th>", esc_text(family.sku_label.as_deref().unwrap_or("Part#"))));
    for p in &family.parameters {
        out.push(format!("<th>{}</th>", wordwrap(&p.name, 12, "<br>")));
    }
    if shows_price {
        out.push(format!("<th>{}</th>", esc_text(family.price_label.as_deref().unwrap_or("Price"))));
    }
    if ctx.viewer.ecommerce {
        out.push(format!("<th class=nobr>{}</th>", forms::font("red", "*", " Typical<br>Lead Time")));
        out.push("<th>Quantity</th>".into());
        out.push("<th class=nobr>Add to<br>Cart</th>".into());
        if !family.prj.is_empty() {
            out.push("<th class=nobr>Quick<br>CAD</th>".into());
        }
    }
    out.push("</tr>".into());
    out.push("</thead>".into());
    let mut q = url_base(ctx, family, "sku");
    if !family.parameter_search.is_empty() {
        q.set("search_complete", ctx.req.search_complete.clone());
        for (i, v) in ctx.req.parameter.iter().enumerate().filter(|(_, v)| !v.is_empty()) {
            q.set(&format!("parameter{i}"), v.clone());
        }
    }
    q.set("search_complete", ctx.req.search_complete.clone());
    q.set("sku", "");
    q.set("scs_page", ctx.req.page.to_string());
    out.push("<tbody>".into());
    for part in shown {
        out.push("<tr>".into());
        q.set("sku", html::base64_fields(&[&part.sku]));
        let mut cell = String::new();
        if is_new(ctx, family) || ctx.catalog.is_new_date(&part.new_date) {
            cell.push_str(&forms::font("red", "*", ""));
        }
        cell.push_str(&if blocked { format!("<span class='catalog_blocked_link'>{}</span>", esc_text(&part.sku)) } else { href(&esc_text(&part.sku), &html::url(ctx.php_self, &q)) });
        out.push(format!("<td class='nobr'>{cell}</td>"));
        for p in &family.parameters {
            let value = &part.parameter[p.id];
            let text = if value.is_empty() {
                "&nbsp;".to_string()
            } else if p.hyperlink {
                related_link(ctx, value, &part.sku)
            } else {
                esc_text(value)
            };
            out.push(format!("<td>{text}</td>"));
        }
        if shows_price {
            let quote = pricing::quote(&ctx.book, &buyer, &family.currency, &ListPrices::parse(&part.pricing), 1);
            out.push(format!("<td class='right nobr'>{}</td>", pricing::simple(&ctx.book, &buyer, &quote)));
        }
        if ctx.viewer.ecommerce {
            let field_sku = html::base64_fields(&["sku", &part.sku]);
            let field_list = html::base64_fields(&["list", &part.sku]);
            out.push(format!("<td class=center>{}</td>", esc_text(&part.leadtime(family))));
            out.push(format!("<td class=center>{}</td>", forms::text(&field_list, "", "6", "6", &[("class", "products_quantity")])));
            let add = ctx.strings.label("catalog_cart_add", "Add to Cart", &[]);
            let icon = forms::img("/scs_images/addtocart.png", &[("class", "products_cart"), ("title", &add)]).map(|i| i.replacen("<img", "<img id=''", 1)).unwrap_or_default();
            out.push(format!("<td class=center>{}</td>", href(&icon, &format!("javascript:cart_update(this,{},{})", forms::js_quote(&field_list), forms::js_quote(&field_sku)))));
            if !family.prj.is_empty() {
                let field = html::base64_fields(&["inventory", &part.sku]);
                let onclick = format!("quick_cad(this.id, {}, 0);", forms::js_quote(&part.sku));
                out.push(format!("<td class=center>{}</td>", forms::checkbox(&field, "1", "0", &[("onclick", &onclick)])));
            }
        }
        out.push("</tr>".into());
    }
    out.push("</tbody>".into());
    out.push("</table>".into());
    if family.page_break && !ctx.viewer.is_bot() && found > PAGE_SIZE {
        let mut q = q.clone();
        q.set("lang", ctx.lang_param());
        q.set("action", "subcategory");
        q.set("scs_page", "%page%");
        q.remove("sku");
        let url = html::url(ctx.php_self, &q);
        out.push(page_breaks(found, ctx.req.page, &format!("window.location.href={};", forms::js_quote(&url))));
    }
    Some(out.join("\n"))
}

/// page_item_break::page(): First, Previous, Next and Last buttons and the record count.
fn page_breaks(found: usize, page: i64, script: &str) -> String {
    let current = page.max(1) as usize;
    let last = found.div_ceil(PAGE_SIZE);
    let items: [(&str, Option<usize>); 4] = [
        ("&lt;&lt; First", (current > 1).then_some(1)),
        ("&lt; Previous", (current > 1).then(|| current - 1)),
        ("Next &gt;", (last > current).then(|| current + 1)),
        ("Last &gt;&gt;", (last > current).then_some(last)),
    ];
    let mut buttons = String::new();
    let mut any = false;
    for (label, target) in items {
        let label = label.replace("&lt;", "<").replace("&gt;", ">");
        match target.filter(|t| *t != current && *t <= last) {
            Some(t) => {
                any = true;
                let onclick = script.replace("%page%", &t.to_string()).replace("%25page%25", &t.to_string());
                buttons.push_str(&forms::button(&label, &[("onclick", &onclick)]));
            }
            None => buttons.push_str(&forms::button(&label, &[("class", "nav_button_disabled")])),
        }
    }
    let mut out = vec!["<p class='standard center'>".to_string()];
    if any {
        out.push(buttons);
    }
    out.push(format!("<br>{} Records Found", pricing::number_format(found as f64, 0)));
    if any {
        out.push(format!("<br>Page {} of {}", pricing::number_format(current as f64, 0), pricing::number_format(last as f64, 0)));
    }
    out.push("</p>".into());
    out.join("\n")
}

fn related_message(ctx: &Ctx) -> String {
    let message = ctx.registry.get("local", "related_message");
    if message.is_empty() {
        return String::new();
    }
    format!("<div>{}</div>", message.replace("%sku%", &esc_text(&ctx.related_sku)))
}

/// output_overview(): overview:* content in the viewer's language first, then English.
async fn overview(ctx: &Ctx<'_>, family: &Family, tabs: &mut Tabs) -> sqlx::Result<()> {
    let code = ctx.viewer.lang.code();
    let rows = sqlx::query!(
        r#"SELECT id AS "id!: i64", record_item AS "item!", name AS "name!", filetype AS "filetype!", html_content
           FROM content WHERE record_type = 'subcategory' AND record_id = ?1 AND record_item LIKE 'overview:%' AND language_code IN ('EN', ?2)
           ORDER BY CASE WHEN language_code = 'EN' THEN 2 ELSE 1 END, record_item, id"#,
        family.id,
        code
    )
    .fetch_all(ctx.pool)
    .await?;
    if rows.is_empty() {
        return Ok(());
    }
    let mut seen: Vec<String> = Vec::new();
    let mut out: Vec<String> = Vec::new();
    for r in rows {
        if seen.contains(&r.item) {
            continue;
        }
        seen.push(r.item.clone());
        let name = esc_text(&r.name);
        match r.filetype.as_str() {
            "image" => {
                out.push(format!("<p class=products_overview>{name}</p>"));
                out.push(format!("<img src='{}'>", html::esc_attr(&content_link(r.id))));
            }
            "html" => {
                out.push(format!("<p class=products_overview>{name}</p>"));
                out.push(format!("<div>{}</div>", r.html_content.unwrap_or_default()));
            }
            _ => out.push(format!("<p class=products_overview>{}</p>", href_with(&name, &content_link(r.id), &[("target", "_blank")]))),
        }
        out.push("<hr>".into());
    }
    tabs.item(ctx.strings.get("catalog_overview"), Some(out.join("\n")), Some("catalog_overview"), None);
    Ok(())
}

/// output_customs(): the Specials and Customs tabs, in any language, the viewer's first.
async fn customs(ctx: &Ctx<'_>, family: &Family, tabs: &mut Tabs) -> sqlx::Result<()> {
    let code = ctx.viewer.lang.code();
    let rows = sqlx::query!(
        r#"SELECT id AS "id!: i64", record_item AS "item!", filetype AS "filetype!", html_content
           FROM content WHERE record_type = 'subcategory' AND record_id = ?1 AND record_item IN ('specials', 'customs')
           ORDER BY record_item, CASE WHEN language_code = ?2 THEN 0 ELSE 1 END, id"#,
        family.id,
        code
    )
    .fetch_all(ctx.pool)
    .await?;
    let mut seen: Vec<String> = Vec::new();
    for r in rows {
        if seen.contains(&r.item) {
            continue;
        }
        seen.push(r.item.clone());
        let field = format!("catalog_{}", r.item);
        let content = match r.filetype.as_str() {
            "image" => Some(format!("<img src='{}'>", html::esc_attr(&content_link(r.id)))),
            "html" => Some(format!("<div>{}</div>", r.html_content.unwrap_or_default())),
            _ => None,
        };
        tabs.item(ctx.strings.get(&field), content, Some(&field), None);
    }
    Ok(())
}

/// output_download(): the part's and the family's download:* files.
async fn download(ctx: &Ctx<'_>, family: &Family, tabs: &mut Tabs) -> sqlx::Result<()> {
    let part_id = ctx.part.as_ref().filter(|_| !ctx.req.sku.is_empty()).map_or(-1, |p| p.id);
    let rows = sqlx::query!(
        r#"SELECT id AS "id!: i64", record_type AS "record_type!", record_item AS "item!", name AS "name!", meta
           FROM content
           WHERE (record_type = 'subcategory' AND record_id = ?1 AND record_item LIKE 'download:%')
              OR (record_type = 'inventory' AND record_id = ?2 AND record_item LIKE 'download:%')
           ORDER BY CASE WHEN record_type = 'subcategory' THEN 1 ELSE 0 END, record_item, id"#,
        family.id,
        part_id
    )
    .fetch_all(ctx.pool)
    .await?;
    if rows.is_empty() {
        return Ok(());
    }
    let mut out = vec!["<table class='standard noborder'>".to_string()];
    for r in rows {
        let name = if r.record_type == "inventory" {
            let key = r.item.split(':').nth(1).unwrap_or("");
            family.download.iter().find(|(k, _)| k == key).map(|(_, n)| n.clone()).unwrap_or_default()
        } else {
            r.name
        };
        if name.is_empty() {
            continue;
        }
        let file = r.meta.as_deref().and_then(|m| serde_json::from_str::<serde_json::Value>(m).ok()).and_then(|m| m.get("name").and_then(|n| n.as_str()).map(str::to_string)).unwrap_or_default();
        let local_log = {
            use base64::Engine;
            base64::engine::general_purpose::STANDARD.encode(["download", &file, &ctx.req.sku, &family.id.to_string()].join("\t"))
        };
        let q = Query::new().with("content_id", html::base64_fields(&[&r.id.to_string(), "0"])).with("local_log", local_log);
        out.push("<tr>".into());
        out.push(format!("<td>{}</td>", href_with(&esc_text(&name), &html::url("/scs_file.php", &q), &[("target", "_blank")])));
        out.push("</tr>".into());
    }
    out.push("</table>".into());
    tabs.item(ctx.strings.get("catalog_download"), Some(out.join("\n")), Some("catalog_download"), None);
    Ok(())
}

/// output_sku_tab(): the part's details, price block and cart buttons.
fn sku_tab(ctx: &Ctx, family: &Family, tabs: &mut Tabs) {
    let label = ctx.strings.get("catalog_sku");
    if ctx.blocked(ctx.category_id) {
        tabs.item(label, Some(ctx.overlay(ctx.category_id)), Some("catalog_sku"), None);
        return;
    }
    let Some(part) = ctx.part.as_ref() else {
        tabs.item(label, Some(sku_details(ctx, family, None)), Some("catalog_sku"), None);
        return;
    };
    tabs.item(label, Some(sku_details(ctx, family, Some(part))), Some("catalog_sku"), None);
    if ctx.viewer.at_least(crate::identity::Role::Internal) && !part.competitor_sku.is_empty() {
        let list = part.competitor_sku.iter().map(|s| esc_text(s)).collect::<Vec<_>>().join("<br>\n&bull; ");
        tabs.item(ctx.strings.get("catalog_competitors"), Some(format!("<p class='standard catalog'>\n&bull; {list}\n</p>")), Some("catalog_competitors"), None);
    }
}

fn sku_details(ctx: &Ctx, family: &Family, part: Option<&Part>) -> String {
    let buyer = ctx.buyer();
    let sku = part.map_or("", |p| p.sku.as_str());
    let mut out: Vec<String> = Vec::new();
    if !ctx.related_sku.is_empty() {
        out.push(related_message(ctx));
    }
    out.push("<table class='noborder large'>".into());
    out.push("<tr>".into());
    out.push("<td width='50%'>".into());
    let mut data = vec![
        format!("Part number: {}", esc_text(sku)),
        format!("Category: {}", esc_text(ctx.catalog.category_name(ctx.category_id))),
        format!("Subcategory: {}", esc_text(&family.name)),
    ];
    if part.is_some_and(|p| ctx.catalog.is_new_date(&p.new_date)) {
        data.push("New Item!".into());
    } else if is_new(ctx, family) {
        data.push("New Series!".into());
    }
    out.push(format!("<p>{}</p>", data.join("<br>\n")));
    let list = part.map(|p| ListPrices::parse(&p.pricing)).unwrap_or_default();
    let quote = pricing::quote(&ctx.book, &buyer, &family.currency, &list, 1);
    out.push(pricing::verbose(&ctx.book, &buyer, &quote));
    if ctx.viewer.ecommerce {
        let field_sku = html::base64_fields(&["sku", sku]);
        let field_list = html::base64_fields(&["list", sku]);
        let onclick = format!("cart_update(this,{},{});", forms::js_quote(&field_sku), forms::js_quote(&field_list));
        out.push(format!(
            "<p>{} {}</p>",
            forms::text(&field_sku, "", "6", "6", &[("class", "products_sku_quantity")]),
            forms::button(&ctx.strings.label("catalog_cart_add", "Add to Cart", &[]), &[("class", "cart_button"), ("onclick", &onclick)])
        ));
        if !family.prj.is_empty() {
            let onclick = format!("quick_cad('', {}, 0);", forms::js_quote(sku));
            let label = ctx.strings.label("catalog_cad_download", "Download CAD", &[]);
            out.push(format!("<p>{}</p>", forms::button(&label, &[("title", &label), ("class", "quick_cad_button"), ("onclick", &onclick)])));
        }
    }
    out.push("</td>".into());
    let mut items: Vec<String> = Vec::new();
    if let Some(part) = part {
        for p in &family.parameters {
            let value = &part.parameter[p.id];
            if value.is_empty() {
                continue;
            }
            let text = if p.hyperlink { related_link(ctx, value, &part.sku) } else { esc_text(value) };
            items.push(format!("{}: <b>{text}</b>", esc_text(&p.name)));
        }
    }
    if !items.is_empty() {
        items.insert(0, "<b>Specifications:</b>".into());
    }
    out.push(format!("<td width='50%'>{}</td>", items.join("<br>")));
    out.push("</tr>".into());
    out.push("</table>".into());
    out.join("\n")
}

/// cad_class::prj_parse(): the configurator's own query from a stored prj.
fn prj_query(prj: &str) -> Query {
    let mut q = Query::new();
    let lower = prj.to_ascii_lowercase();
    if let Some(rest) = lower.strip_prefix("info=").map(|_| &prj[5..]) {
        match rest.split_once("&varset=").or_else(|| rest.split_once("&VARSET=")) {
            Some((info, varset)) => {
                q.set("info", info);
                q.set("varset", varset);
            }
            None => {
                q.set("info", rest);
            }
        }
    } else if lower.starts_with("catalog=") {
        if let Some((catalog, part)) = prj[8..].split_once("&part=") {
            q.set("catalog", catalog);
            q.set("part", part);
        }
    }
    q
}

/// output_cad(): the PARTsolutions configurator for viewers with CAD, a profile email and a family prj.
fn cad(ctx: &Ctx, family: &Family, tabs: &mut Tabs) {
    if ctx.viewer.account_id().is_none() && !ctx.viewer.is_bot() {
        return;
    }
    if !ctx.viewer.cad || !ctx.viewer.active() || ctx.profile.email.is_empty() || family.prj.is_empty() {
        return;
    }
    let mut prj = family.prj.clone();
    if family.lookup && !ctx.req.sku.is_empty() {
        if let Some(part) = ctx.part.as_ref().filter(|p| !p.prj.is_empty()) {
            prj = part.prj.clone();
        }
    }
    if ctx.req.search_source == "lookup" || ctx.req.action == "sku" {
        tabs.land_next();
    }
    let mut q = prj_query(&prj);
    q.set("partFixed", "false");
    q.set("languageIso", ctx.lang_param());
    let mut url = format!("{}{}", ctx.registry.get("cad", "configurator"), q.encoded());
    url.push_str(if q.encoded().is_empty() { "?" } else { "&" });
    url.push_str("hidePortlets=briefcase&hidePortlets=searchHeader");
    let content = [
        "<div id=products_cad_configurator>".to_string(),
        format!("<iframe id=scscpq_configurator_id src={} onload='javascript:scscpq_initial(this.id, false);'>{}</iframe>", html::bare_attr(&url), ctx.strings.label("catalog_iframe_required", "IFrame support required to view content", &[])),
        "</div>".into(),
        "<div id=products_cad_price>".into(),
        "<span id=products_cad_html></span>".into(),
        "</div>".into(),
    ]
    .join("\n");
    tabs.item(ctx.strings.get("catalog_cad"), Some(content), Some("catalog_cad"), Some("products_cad"));
}

/// lookup(): a part number typed into the search box. Found in the search table, it opens its family's page;
/// otherwise the visitor is told it was not found.
pub async fn lookup(ctx: &mut Ctx<'_>, found: Option<super::results::Hit>) -> sqlx::Result<String> {
    let wanted = ctx.req.raw_search_code.trim().to_uppercase();
    if wanted.is_empty() {
        return Ok("<p class=center>Blank SKU</p>".into());
    }
    let Some(hit) = found else {
        return Ok(format!("<p class=center>Part# <b>{}</b> not found</p>", esc_text(&wanted)));
    };
    ctx.part = hit.part;
    if ctx.family().is_none() {
        return Ok(String::new());
    }
    let mut out = String::new();
    page(ctx).await?.write_to(&mut out);
    Ok(out)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn headers_wrap_at_twelve_characters() {
        assert_eq!(wordwrap("Thread Size Metric", 12, "<br>"), "Thread Size<br>Metric");
        assert_eq!(wordwrap("abcde fghijk", 12, "<br>"), "abcde fghijk");
        assert_eq!(wordwrap("Overalllengthmm A", 12, "<br>"), "Overalllengthmm<br>A");
    }

    #[test]
    fn page_break_buttons() {
        let html = page_breaks(120, 2, "window.location.href='/catalog/?scs_page=%25page%25';");
        assert!(html.starts_with("<p class='standard center'>\n<input type=button class='nav_button' value='&lt;&lt; First' onclick=\"javascript:window.location.href='/catalog/?scs_page=1';\">\n"));
        assert!(html.contains("value='Next &gt;' onclick=\"javascript:window.location.href='/catalog/?scs_page=3';\""));
        assert!(html.ends_with("<br>120 Records Found\n<br>Page 2 of 3\n</p>"));
        assert!(page_breaks(120, 3, "x=%page%").contains("value='Next &gt;' class='nav_button_disabled'") || page_breaks(120, 3, "x=%page%").contains("class='nav_button_disabled' value='Next &gt;'"));
    }

    #[test]
    fn prj_values_become_the_configurator_query() {
        assert_eq!(prj_query("info=harcourt/pins/a.prj&varset={LEN=20}").encoded(), "?info=harcourt%2Fpins%2Fa.prj&varset=%7BLEN%3D20%7D");
        assert_eq!(prj_query("catalog=harcourt&part=HB-1").encoded(), "?catalog=harcourt&part=HB-1");
        assert_eq!(prj_query("nonsense").encoded(), "");
    }
}
