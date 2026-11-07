//! What the search box opens (catalog.php search_source=search): the row the visitor picked, an exact part
//! number, or the keyword search over the `search` table.

use super::{family, home, Ctx};
use crate::catalog::{html::esc_text, keywords, model, model::Part, search};

/// A `search` row: what it points at.
pub struct Hit {
    pub table: String,
    pub category_id: i64,
    pub subcategory_id: i64,
    pub part: Option<Part>,
}

/// lookup()'s first step: the part number as typed (`search::as_typed`) or without separators, matched against
/// parts (by the number each has now) and competitor numbers as the box matches them (`search::same_code`,
/// `search::normalize_code`). The first the viewer is shown (`shown`), in the search box's order: a code as typed,
/// then parts before competitor numbers, then ranking. SQLite compares ASCII only, so it picks the candidates (those it can match, and every code
/// with a character past ASCII) and the comparison is made here.
pub async fn exact(ctx: &Ctx<'_>, code: &str) -> sqlx::Result<Option<Hit>> {
    let codes = search::as_typed(code);
    let variant = |i: usize| codes.get(i).unwrap_or(&codes[0]).clone();
    let (a, b, c) = (variant(0), variant(1), variant(2));
    let normalized = search::normalize_code(&codes[0]);
    let candidates = sqlx::query!(
        r#"SELECT s.id AS "id!: i64", s.table_name AS "table_name!: String", s.code AS "code!: String", s.category_id AS "category_id?: i64",
                  s.subcategory_id AS "subcategory_id?: i64", s.inventory_id AS "inventory_id?: i64", coalesce(s.ranking, 0) AS "ranking!: i64",
                  coalesce(i.active, 0) AS "part_active!: i64", i.subcategory_id AS "part_family?: i64"
           FROM search_named s LEFT JOIN inventory i ON i.id = s.inventory_id
           WHERE s.table_name IN ('inventory', 'competitor_sku') AND s.code <> ''
             AND (s.code IN (?1, ?2, ?3) COLLATE NOCASE OR upper(s.code_normalized) = ?4 OR s.code GLOB '*[^ -~]*')"#,
        a,
        b,
        c,
        normalized
    )
    .fetch_all(ctx.pool)
    .await?;
    let row = candidates
        .into_iter()
        .filter_map(|r| {
            let typed = codes.iter().any(|c| search::same_code(c, &r.code));
            let matched = typed || (!normalized.is_empty() && search::normalize_code(&r.code) == normalized);
            let visible = shown(ctx, &r.table_name, r.category_id.unwrap_or(0), r.subcategory_id.unwrap_or(0), r.part_family, r.part_active != 0).is_some();
            (matched && visible).then_some((!typed, r))
        })
        .min_by(|(x, r), (y, s)| (x, r.table_name != "inventory", r.ranking, &r.code, r.id).cmp(&(y, s.table_name != "inventory", s.ranking, &s.code, s.id)))
        .map(|(_, r)| (r.table_name, r.category_id, r.subcategory_id, r.inventory_id));
    hit(ctx, row).await
}

/// A `search` row as read: table, category, family and part ids.
type Row = (String, Option<i64>, Option<i64>, Option<i64>);

async fn hit(ctx: &Ctx<'_>, row: Option<Row>) -> sqlx::Result<Option<Hit>> {
    let Some((table, category_id, subcategory_id, inventory_id)) = row else { return Ok(None) };
    let part = match inventory_id.filter(|_| table != "subcategory") {
        Some(id) => model::part_by_id(ctx.pool, id).await?,
        None => None,
    };
    let Some((category_id, subcategory_id)) =
        shown(ctx, &table, category_id.unwrap_or(0), subcategory_id.unwrap_or(0), part.as_ref().map(|p| p.subcategory_id), part.as_ref().is_some_and(|p| p.active))
    else {
        return Ok(None);
    };
    Ok(Some(Hit { table, category_id, subcategory_id, part }))
}

/// A part number or a competitor's number for one: the row stands for an inventory part.
fn names_part(table: &str) -> bool {
    matches!(table, "inventory" | "competitor_sku")
}

/// Where a `search` row points now: a part row to the part's current family (none when the part is gone), and any
/// family to its current category. The table is filled from those and can lag behind them.
fn place(ctx: &Ctx, table: &str, category_id: i64, subcategory_id: i64, part_family: Option<i64>) -> (i64, i64) {
    if table == "category" {
        return (category_id, subcategory_id);
    }
    let subcategory_id = if names_part(table) { part_family.unwrap_or(0) } else { subcategory_id };
    (ctx.catalog.family(subcategory_id).map_or(category_id, |f| f.category_id), subcategory_id)
}

/// Where a `search` row points now (`place`), when the catalog shows this viewer what it names: an active category,
/// or a family the catalog lists (`Catalog::lists`) and, for a part, an active part. A blocked category still shows,
/// as its overlay.
fn shown(ctx: &Ctx, table: &str, category_id: i64, subcategory_id: i64, part_family: Option<i64>, part_active: bool) -> Option<(i64, i64)> {
    let (category_id, subcategory_id) = place(ctx, table, category_id, subcategory_id, part_family);
    let shows = if table == "category" {
        ctx.catalog.categories.get(&category_id).is_some_and(|c| c.active)
    } else {
        ctx.catalog.family(subcategory_id).is_some_and(|f| ctx.catalog.lists(f, ctx.viewer.sees_pending())) && (part_active || !names_part(table))
    };
    shows.then_some((category_id, subcategory_id))
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

/// search_source=search: the resolved row's page, else the keyword results for the typed term. A picked suggestion
/// the catalog no longer shows, with no term typed, has nothing to search for.
pub async fn search(ctx: &mut Ctx<'_>, found: Option<Hit>) -> sqlx::Result<String> {
    match found {
        Some(hit) => open(ctx, hit).await,
        None if ctx.req.search_id != 0 && ctx.req.raw_search_code.trim().is_empty() => Ok(no_results(ctx)),
        None => search_code(ctx).await,
    }
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
    let Some(id) = search::best(&cache, ctx.pool, ctx.viewer, ctx.req.raw_search_code.trim()).await? else { return Ok(None) };
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
        r#"SELECT s.table_name AS "table!: String", s.code AS "code!: String", s.category_id AS "category_id!: i64",
                  s.subcategory_id AS "subcategory_id!: i64", s.inventory_id AS "inventory_id?: i64", coalesce(s.ranking, 0) AS "ranking!: i64",
                  coalesce(i.active, 0) AS "part_active!: i64", i.subcategory_id AS "part_family?: i64"
           FROM search_named s LEFT JOIN inventory i ON i.id = s.inventory_id
           WHERE coalesce(s.language_code, '') IN ('', ?1)
             AND s.table_name IS NOT NULL AND s.code IS NOT NULL AND s.category_id IS NOT NULL AND s.subcategory_id IS NOT NULL
           ORDER BY s.id"#,
        lang
    )
    .fetch_all(ctx.pool)
    .await?
    .into_iter()
    .filter_map(|r| {
        let (category_id, subcategory_id) = shown(ctx, &r.table, r.category_id, r.subcategory_id, r.part_family, r.part_active != 0)?;
        (within == 0 || subcategory_id == within).then(|| keywords::Row {
            code_normalized: search::normalize_code(&r.code),
            table: r.table,
            code: r.code,
            category_id, subcategory_id, inventory_id: r.inventory_id, ranking: r.ranking })
    })
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

/// category_subcategories(): a category's families the catalog lists, in catalog order.
fn category_subcategories(ctx: &Ctx, category_id: i64) -> Vec<i64> {
    let mut list: Vec<&crate::catalog::model::Family> = ctx
        .catalog
        .families
        .values()
        .filter(|f| f.category_id == category_id && ctx.catalog.lists(f, ctx.viewer.sees_pending()))
        .collect();
    list.sort_by(|a, b| a.sort_order.cmp(&b.sort_order).then_with(|| a.table_name.cmp(&b.table_name)));
    list.into_iter().map(|f| f.id).collect()
}
