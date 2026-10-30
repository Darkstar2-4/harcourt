//! What the search box opens (catalog.php search_source=search): the row the visitor picked, an exact part
//! number, or the keyword search over the `search` table.

use super::{family, home, Ctx};
use crate::catalog::{html::esc_text, keywords, model, model::Part};

/// A `search` row: what it points at.
pub struct Hit {
    pub table: String,
    pub category_id: i64,
    pub subcategory_id: i64,
    pub part: Option<Part>,
}

/// search_class::normalize_code(): upper case without separators, so "hb-lt 1" finds HBLT1.
pub fn normalize_code(s: &str) -> String {
    s.chars().filter(|c| !c.is_whitespace() && !"-_./".contains(*c)).collect::<String>().to_uppercase()
}

/// lookup()'s first step: the part number as typed, without its spaces, or with them as underscores, matched
/// exactly against parts first and competitor numbers second.
pub async fn exact(ctx: &Ctx<'_>, code: &str) -> sqlx::Result<Option<Hit>> {
    let wanted = code.trim().to_uppercase();
    let mut codes = vec![wanted.clone()];
    for joiner in ["", "_"] {
        let c = wanted.replace(' ', joiner);
        if !codes.contains(&c) {
            codes.push(c);
        }
    }
    let (a, b, c) = (codes[0].clone(), codes.get(1).cloned().unwrap_or_default(), codes.get(2).cloned().unwrap_or_default());
    let normalized = normalize_code(&wanted);
    let row = sqlx::query!(
        r#"SELECT table_name AS "table_name!", category_id AS "category_id: i64", subcategory_id AS "subcategory_id: i64", inventory_id AS "inventory_id: i64"
           FROM search WHERE table_name IN ('inventory', 'competitor_sku') AND (code IN (?1, ?2, ?3) COLLATE NOCASE OR code_normalized = ?4)
           ORDER BY CASE WHEN code IN (?1, ?2, ?3) COLLATE NOCASE THEN 0 ELSE 1 END, CASE WHEN table_name = 'inventory' THEN 0 ELSE 1 END, id LIMIT 1"#,
        a,
        b,
        c,
        normalized
    )
    .fetch_optional(ctx.pool)
    .await?;
    hit(ctx, row.map(|r| (r.table_name, r.category_id, r.subcategory_id, r.inventory_id))).await
}

/// A `search` row as read: table, category, family and part ids.
type Row = (String, Option<i64>, Option<i64>, Option<i64>);

async fn hit(ctx: &Ctx<'_>, row: Option<Row>) -> sqlx::Result<Option<Hit>> {
    let Some((table, category_id, subcategory_id, inventory_id)) = row else { return Ok(None) };
    let part = match inventory_id.filter(|_| table != "subcategory") {
        Some(id) => model::part_by_id(ctx.pool, id).await?,
        None => None,
    };
    Ok(Some(Hit { table, category_id: category_id.unwrap_or(0), subcategory_id: subcategory_id.unwrap_or(0), part }))
}

/// What catalog.php's constructor read for search_source=search and lookup, before anything is printed: the row
/// picked from the suggestions (search_id), else the exact part number.
pub async fn resolve(ctx: &Ctx<'_>) -> sqlx::Result<Option<Hit>> {
    let id = ctx.req.search_id;
    match ctx.req.search_source.as_str() {
        "search" if id != 0 => {
            let row = sqlx::query!(
                r#"SELECT table_name, category_id AS "category_id: i64", subcategory_id AS "subcategory_id: i64", inventory_id AS "inventory_id: i64" FROM search WHERE id = ?1"#,
                id
            )
            .fetch_optional(ctx.pool)
            .await?;
            hit(ctx, row.map(|r| (r.table_name.unwrap_or_default(), r.category_id, r.subcategory_id, r.inventory_id))).await
        }
        "search" | "lookup" if !ctx.req.raw_search_code.trim().is_empty() => exact(ctx, &ctx.req.raw_search_code).await,
        _ => Ok(None),
    }
}

/// search_source=search: the resolved row's page, else the keyword results.
pub async fn search(ctx: &mut Ctx<'_>, found: Option<Hit>) -> sqlx::Result<String> {
    let Some(hit) = found else { return search_code(ctx).await };
    open(ctx, hit).await
}

async fn open(ctx: &mut Ctx<'_>, hit: Hit) -> sqlx::Result<String> {
    ctx.category_id = hit.category_id;
    ctx.subcategory_id = hit.subcategory_id;
    match hit.table.as_str() {
        "inventory" | "competitor_sku" => {
            ctx.req.action = "sku".into();
            ctx.req.sku = hit.part.as_ref().map(|p| p.sku.clone()).unwrap_or_default();
            ctx.part = hit.part;
        }
        "subcategory" | "search_words" => {}
        _ => return Ok(no_results(ctx)),
    }
    let mut out = String::new();
    family::page(ctx).await?.write_to(&mut out);
    Ok(out)
}

fn no_results(ctx: &Ctx) -> String {
    format!("<p class=center>No results for search term: <b>{}</b></p>", esc_text(&ctx.req.search_code))
}

/// When the keyword search finds nothing for a term typed and entered (no suggestion picked), the row the search box
/// would have put first: PHP's search came up empty for about four searches in ten.
async fn best_match(ctx: &Ctx<'_>) -> sqlx::Result<Option<Hit>> {
    let Some(cache) = ctx.search.clone().filter(|_| ctx.req.search_id == 0) else { return Ok(None) };
    let Some(id) = crate::catalog::search::best(&cache, ctx.pool, ctx.viewer, ctx.req.raw_search_code.trim()).await? else { return Ok(None) };
    let id = i64::try_from(id).unwrap_or(0);
    let row = sqlx::query!(
        r#"SELECT table_name, category_id AS "category_id: i64", subcategory_id AS "subcategory_id: i64", inventory_id AS "inventory_id: i64" FROM search WHERE id = ?1"#,
        id
    )
    .fetch_optional(ctx.pool)
    .await?;
    hit(ctx, row.map(|r| (r.table_name.unwrap_or_default(), r.category_id, r.subcategory_id, r.inventory_id))).await
}

/// search_code() as prod has it: the keyword search (keywords.rs), corrected for a typo when it finds nothing,
/// grouped by family and listed best-ranked first.
async fn search_code(ctx: &mut Ctx<'_>) -> sqlx::Result<String> {
    let raw = ctx.req.raw_search_code.trim().to_string();
    let terms: Vec<String> = if raw.is_empty() { vec![String::new()] } else { raw.split_whitespace().map(str::to_string).collect() };
    let lang = ctx.viewer.lang.code();
    let within = ctx.subcategory_id;
    let rows: Vec<keywords::Row> = sqlx::query!(
        r#"SELECT table_name AS "table!", code AS "code!", coalesce(code_normalized, '') AS "normalized!: String", category_id AS "category_id!: i64",
                  subcategory_id AS "subcategory_id!: i64", inventory_id AS "inventory_id: i64", coalesce(ranking, 0) AS "ranking!: i64"
           FROM search
           WHERE coalesce(language_code, '') IN ('', ?1) AND (?2 = 0 OR subcategory_id = ?2)
             AND table_name IS NOT NULL AND code IS NOT NULL AND category_id IS NOT NULL AND subcategory_id IS NOT NULL
           ORDER BY id"#,
        lang,
        within
    )
    .fetch_all(ctx.pool)
    .await?
    .into_iter()
    .map(|r| keywords::Row {
        code_normalized: if r.normalized.is_empty() { keywords::normalize(&r.code) } else { r.normalized },
        table: r.table,
        code: r.code,
        category_id: r.category_id, subcategory_id: r.subcategory_id, inventory_id: r.inventory_id, ranking: r.ranking })
    .collect();
    let names: Vec<(i64, String)> = sqlx::query!(
        r#"SELECT record_id AS "id!: i64", name AS "name!" FROM content WHERE record_type = 'category' AND record_item = '' AND language_code IN ('EN', ?1)"#,
        lang
    )
    .fetch_all(ctx.pool)
    .await?
    .into_iter()
    .map(|r| (r.id, r.name))
    .collect();
    let categories = keywords::category_words(&names);
    let mut filter = keywords::Filter::new(&terms, &categories);
    let mut matched: Vec<&keywords::Row> = rows.iter().filter(|r| filter.matches(r)).collect();
    let mut corrected = String::new();
    if matched.is_empty() {
        let kw = keywords::keywords(rows.iter().filter(|r| matches!(r.table.as_str(), "category" | "subcategory" | "search_words")).map(|r| r.code.as_str()));
        let fixed = keywords::correct(&terms, &kw);
        if fixed != terms {
            filter = keywords::Filter::new(&fixed, &categories);
            matched = rows.iter().filter(|r| filter.matches(r)).collect();
            if !matched.is_empty() {
                corrected = fixed.join(" ");
            }
        }
    }
    struct Group {
        category_id: i64,
        subcategory_id: i64,
        table: String,
        ranking: i64,
    }
    let mut groups: Vec<Group> = Vec::new();
    for r in &matched {
        match groups.iter_mut().find(|g| g.category_id == r.category_id && g.subcategory_id == r.subcategory_id) {
            Some(g) => g.ranking = g.ranking.min(r.ranking),
            None => groups.push(Group { category_id: r.category_id, subcategory_id: r.subcategory_id, table: r.table.clone(), ranking: r.ranking }),
        }
    }
    groups.sort_by_key(|g| g.ranking);
    let mut out = String::new();
    if !corrected.is_empty() {
        out.push_str(&format!("<p class=center>Showing results for <b>{}</b></p>", crate::catalog::html::esc(&corrected)));
    }
    if groups.is_empty() {
        if let Some(hit) = best_match(ctx).await? {
            return open(ctx, hit).await;
        }
        return Ok(no_results(ctx));
    }
    let mut summary: home::Summary = Vec::new();
    let mut table_subcategory = false;
    for g in &groups {
        let ids: Vec<i64> = if g.table == "category" {
            table_subcategory = true;
            category_subcategories(ctx, g.category_id)
        } else {
            table_subcategory |= g.table == "subcategory";
            vec![g.subcategory_id]
        };
        match summary.iter_mut().find(|(c, _)| *c == g.category_id) {
            Some((_, list)) => list.extend(ids.into_iter().filter(|i| !list.contains(i)).collect::<Vec<_>>()),
            None => summary.push((g.category_id, ids)),
        }
    }
    if groups.len() > 1 || table_subcategory {
        out.push_str(&home::output_summary(ctx, &summary));
        return Ok(out);
    }
    let g = &groups[0];
    ctx.category_id = g.category_id;
    ctx.subcategory_id = g.subcategory_id;
    let mut parts: Vec<Part> = Vec::new();
    for id in matched.iter().filter_map(|r| r.inventory_id) {
        if parts.iter().any(|p| p.id == id) {
            continue;
        }
        if let Some(p) = model::part_by_id(ctx.pool, id).await? {
            parts.push(p);
        }
    }
    parts.sort_by_key(|p| (p.sort_order, p.sku.to_lowercase()));
    if parts.len() == 1 {
        ctx.req.action = "sku".into();
        let part = parts.remove(0);
        family::part_page(ctx, part).await?.write_to(&mut out);
    } else {
        family::page_listing(ctx, Some(parts)).await?.write_to(&mut out);
    }
    Ok(out)
}

/// category_subcategories(): a category's families in catalog order, Pending ones for administrators.
fn category_subcategories(ctx: &Ctx, category_id: i64) -> Vec<i64> {
    let mut list: Vec<&crate::catalog::model::Family> = ctx
        .catalog
        .families
        .values()
        .filter(|f| f.category_id == category_id && f.status.visible(ctx.viewer.sees_pending()))
        .collect();
    list.sort_by(|a, b| a.sort_order.cmp(&b.sort_order).then_with(|| a.table_name.cmp(&b.table_name)));
    list.into_iter().map(|f| f.id).collect()
}
