//! What the search box opens (catalog.php search_source=search): the row the visitor picked, an exact part
//! number, or the keyword search over the `search` table.

use super::{family, home, Ctx};
use crate::catalog::{html::esc_text, model, model::Part};

/// A `search` row: what it points at.
pub struct Hit {
    pub table: String,
    pub category_id: i64,
    pub subcategory_id: i64,
    pub part: Option<Part>,
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
    let row = sqlx::query!(
        r#"SELECT table_name AS "table_name!", category_id AS "category_id: i64", subcategory_id AS "subcategory_id: i64", inventory_id AS "inventory_id: i64"
           FROM search WHERE table_name IN ('inventory', 'competitor_sku') AND code IN (?1, ?2, ?3) COLLATE NOCASE
           ORDER BY CASE WHEN table_name = 'inventory' THEN 0 ELSE 1 END, id LIMIT 1"#,
        a,
        b,
        c
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

/// search_source=search: with a search_id the row picked from the suggestions, without one an exact part number,
/// else the keyword results.
pub async fn search(ctx: &mut Ctx<'_>) -> sqlx::Result<String> {
    let id = ctx.req.search_id;
    let found = if id != 0 {
        let row = sqlx::query!(
            r#"SELECT table_name, category_id AS "category_id: i64", subcategory_id AS "subcategory_id: i64", inventory_id AS "inventory_id: i64" FROM search WHERE id = ?1"#,
            id
        )
        .fetch_optional(ctx.pool)
        .await?;
        hit(ctx, row.map(|r| (r.table_name.unwrap_or_default(), r.category_id, r.subcategory_id, r.inventory_id))).await?
    } else {
        let code = ctx.req.raw_search_code.clone();
        exact(ctx, &code).await?
    };
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

/// search_code(): every word of the search term in a `search` code, grouped by family.
async fn search_code(ctx: &mut Ctx<'_>) -> sqlx::Result<String> {
    let raw = ctx.req.raw_search_code.trim().to_lowercase();
    let words: Vec<String> = if raw.is_empty() { vec![String::new()] } else { raw.split_whitespace().map(str::to_string).collect() };
    let first = format!("%{}%", words[0]);
    let lang = ctx.viewer.lang.code();
    let within = ctx.subcategory_id;
    let rows = sqlx::query!(
        r#"SELECT table_name AS "table_name!", code AS "code!", category_id AS "category_id!: i64", subcategory_id AS "subcategory_id!: i64",
                  inventory_id AS "inventory_id: i64"
           FROM search
           WHERE code LIKE ?1 AND coalesce(language_code, '') IN ('', ?2) AND (?3 = 0 OR subcategory_id = ?3)
             AND table_name IS NOT NULL AND code IS NOT NULL AND category_id IS NOT NULL AND subcategory_id IS NOT NULL
           ORDER BY category_id, subcategory_id, id"#,
        first,
        lang,
        within
    )
    .fetch_all(ctx.pool)
    .await?;
    let rows: Vec<_> = rows.into_iter().filter(|r| words.iter().all(|w| r.code.to_lowercase().contains(w.as_str()))).collect();
    struct Group {
        category_id: i64,
        subcategory_id: i64,
        table: String,
    }
    let mut groups: Vec<Group> = Vec::new();
    for r in &rows {
        match groups.iter_mut().find(|g| g.category_id == r.category_id && g.subcategory_id == r.subcategory_id) {
            Some(_) => {}
            None => groups.push(Group { category_id: r.category_id, subcategory_id: r.subcategory_id, table: r.table_name.clone() }),
        }
    }
    if groups.is_empty() {
        if let Some(hit) = best_match(ctx).await? {
            return open(ctx, hit).await;
        }
        return Ok(no_results(ctx));
    }
    if groups.len() > 1 || groups.iter().any(|g| g.table == "subcategory") {
        let mut summary: home::Summary = Vec::new();
        for g in &groups {
            match summary.iter_mut().find(|(c, _)| *c == g.category_id) {
                Some((_, list)) => list.push(g.subcategory_id),
                None => summary.push((g.category_id, vec![g.subcategory_id])),
            }
        }
        return Ok(home::output_summary(ctx, &summary));
    }
    let g = &groups[0];
    ctx.category_id = g.category_id;
    ctx.subcategory_id = g.subcategory_id;
    let mut ids: Vec<i64> = rows.iter().filter_map(|r| r.inventory_id).collect();
    ids.dedup();
    let mut parts: Vec<Part> = Vec::new();
    for id in ids {
        if let Some(p) = model::part_by_id(ctx.pool, id).await? {
            if !parts.iter().any(|x| x.id == p.id) {
                parts.push(p);
            }
        }
    }
    parts.sort_by_key(|p| (p.sort_order, p.sku.to_lowercase()));
    let mut out = String::new();
    if parts.len() == 1 {
        ctx.req.action = "sku".into();
        let part = parts.remove(0);
        family::part_page(ctx, part).await?.write_to(&mut out);
    } else {
        family::page_listing(ctx, Some(parts)).await?.write_to(&mut out);
    }
    Ok(out)
}
