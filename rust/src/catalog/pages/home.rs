//! The catalog home (catalog.php summary()): the hero, the unit filter and every visible family by category. Also
//! output_summary(), which search results share.

use super::{assets, Ctx};
use crate::{
    catalog::{
        html::{self, esc_text, href, href_with, Query},
        model::{Family, Status},
    },
    db::unix_now,
    identity::Role,
};

/// Families grouped by category, in display order.
pub type Summary = Vec<(i64, Vec<i64>)>;

/// menu_class: the families this viewer's menu lists, by status and, for an account tied to an industry or a
/// portal, by those.
pub fn menu_visible(ctx: &Ctx, family: &Family) -> bool {
    if !family.status.visible(ctx.viewer.sees_pending()) {
        return false;
    }
    let industry = ctx.viewer.industry_id.map(|i| i.to_string());
    let portal = ctx.viewer.portal_id.map(|p| p.to_string());
    if industry.is_none() && portal.is_none() {
        return true;
    }
    industry.is_some_and(|i| family.industry.contains(&i)) || portal.is_some_and(|p| family.portal.contains(&p))
}

/// default_unit_type(): visitors outside the US land on Metric; crawlers and visitors with no country do not.
pub fn default_unit_type(ctx: &Ctx) -> &'static str {
    if ctx.viewer.is_bot() || ctx.viewer.country_code.is_empty() || ctx.viewer.country_code == "US" {
        ""
    } else {
        "metric"
    }
}

/// summary().
pub async fn page(ctx: &mut Ctx<'_>) -> sqlx::Result<String> {
    if !ctx.catalog.families.values().any(|f| menu_visible(ctx, f)) {
        return Ok(String::new());
    }
    if ctx.req.unit_type_given.as_deref().unwrap_or("").is_empty() {
        ctx.req.unit_type = default_unit_type(ctx).to_string();
    }
    let mut out = String::from("<div class=products_summary>");
    out.push_str(&hero(ctx).await?);
    out.push_str(&unit_filter(ctx));
    let (new_parts, has_parts) = if ctx.req.new_date {
        let cutoff = ctx.catalog.new_cutoff.replace('/', "-");
        let new_parts = sqlx::query_scalar!(r#"SELECT DISTINCT subcategory_id AS "id!: i64" FROM inventory WHERE active = 1 AND new_date >= ?1"#, cutoff)
            .fetch_all(ctx.pool)
            .await?;
        let has_parts = sqlx::query_scalar!(r#"SELECT DISTINCT subcategory_id AS "id!: i64" FROM inventory WHERE active = 1"#).fetch_all(ctx.pool).await?;
        (new_parts, has_parts)
    } else {
        (Vec::new(), Vec::new())
    };
    let active_category = |f: &Family| ctx.catalog.categories.get(&f.category_id).is_some_and(|c| c.active);
    let mut families: Vec<&Family> = ctx.catalog.families.values().filter(|f| menu_visible(ctx, f) && active_category(f)).collect();
    if ctx.req.new_date {
        families.retain(|f| new_parts.contains(&f.id) || (ctx.catalog.is_new_date(&f.new_date) && has_parts.contains(&f.id)));
    }
    let category = |id: i64| ctx.catalog.categories.get(&id).map(|c| (c.sort_order, c.name.to_lowercase())).unwrap_or_default();
    families.sort_by_key(|f| (category(f.category_id), f.category_id, sort_key(f)));
    let mut summary: Summary = Vec::new();
    for f in families {
        match summary.iter_mut().find(|(c, _)| *c == f.category_id) {
            Some((_, list)) => list.push(f.id),
            None => summary.push((f.category_id, vec![f.id])),
        }
    }
    out.push_str(&output_summary(ctx, &summary));
    out.push_str("</div> <!-- products_summary -->");
    Ok(out)
}

fn sort_key(f: &Family) -> (i64, String) {
    (f.sort_order, f.table_name.to_lowercase())
}

/// hero_class::output(): the newest effective hero for the viewer's portal, industry, language and role.
async fn hero(ctx: &Ctx<'_>) -> sqlx::Result<String> {
    let portal = ctx.viewer.portal_id.unwrap_or(0);
    let industry = ctx.viewer.industry_id.unwrap_or(0);
    let lang = ctx.viewer.lang.code();
    let today = crate::catalog::model::Day::from_unix(unix_now()).dashed();
    let roles: Vec<&str> = [Role::Customer, Role::External, Role::Internal, Role::Executive, Role::Administrator, Role::SuperUser]
        .into_iter()
        .filter(|r| ctx.viewer.role >= *r)
        .map(Role::as_str)
        .collect();
    let rows = sqlx::query!(
        r#"SELECT portal_id, industry_id, language_code, access AS "access!", effective_date, html, css, js FROM hero
           WHERE status = 'Active' AND coalesce(portal_id, 0) IN (0, ?1) AND coalesce(industry_id, 0) IN (0, ?2)
             AND language_code IN ('EN', ?3) AND effective_date <= ?4"#,
        portal,
        industry,
        lang,
        today
    )
    .fetch_all(ctx.pool)
    .await?;
    let best = rows
        .into_iter()
        .filter(|r| r.access.is_empty() || roles.contains(&r.access.as_str()))
        .min_by(|a, b| {
            let rank = |p: Option<i64>, i: Option<i64>| {
                if portal != 0 && p.unwrap_or(0) == portal {
                    0
                } else if i.unwrap_or(0) == industry {
                    1
                } else {
                    2
                }
            };
            (rank(a.portal_id, a.industry_id), a.language_code.as_deref() != Some(lang))
                .cmp(&(rank(b.portal_id, b.industry_id), b.language_code.as_deref() != Some(lang)))
                .then_with(|| b.effective_date.cmp(&a.effective_date))
        });
    let Some(h) = best else { return Ok(String::new()) };
    let mut out = String::new();
    out.push_str(h.js.as_deref().unwrap_or(""));
    out.push_str(h.css.as_deref().unwrap_or(""));
    out.push_str("<div id=scscpq_hero class=scscpq_hero_preview>");
    out.push_str(h.html.as_deref().unwrap_or(""));
    out.push_str("</div>");
    Ok(out)
}

/// output_unit_filter(): All / Metric / Imperial pills that fetch the fragment and swap the summary.
fn unit_filter(ctx: &Ctx) -> String {
    let mut base = Query::new().with("lang", ctx.lang_param());
    if !ctx.req.search_code.is_empty() {
        base.set("search_code", ctx.req.search_code.clone());
    }
    if ctx.req.new_date {
        base.set("new_date", "1");
    }
    let fetch_query = ctx.req.scscpq_wp.as_ref().map(|v| format!("scscpq_wp={}", html::urlencode(v))).unwrap_or_default();
    let mut out = vec![
        assets::UNIT_FILTER_SCRIPT.to_string(),
        format!(
            "<div class='catalog_unit_filter' data-fetch-path='{}' data-fetch-query='{}'>",
            html::esc(&ctx.req.script),
            html::esc_attr(&fetch_query)
        ),
        "<span class='catalog_unit_filter_label nobr'>Show:</span>".to_string(),
    ];
    for (value, label) in [("", "All"), ("metric", "Metric"), ("imperial", "Imperial")] {
        let q = base.clone().with("unit_type", if value.is_empty() { "all" } else { value });
        let class = if value == ctx.req.unit_type { "catalog_unit_filter_pill active" } else { "catalog_unit_filter_pill" };
        out.push(href_with(label, &html::url(ctx.php_self, &q), &[("class", class)]));
    }
    out.push("</div>".into());
    out.join("\n")
}

/// output_summary(): each category's families as links, Metric or Imperial only when a unit is chosen, and the
/// agreement overlay over a category this viewer cannot open.
pub fn output_summary(ctx: &Ctx, summary: &Summary) -> String {
    let mut q = Query::new().with("lang", ctx.lang_param());
    if ctx.req.debug {
        q.set("debug", "1");
    }
    q.set("action", "subcategory");
    if !ctx.req.search_code.is_empty() {
        q.set("search_code", ctx.req.search_code.clone());
    }
    if ctx.category_id != 0 {
        q.set("category_id", ctx.category_id.to_string());
    }
    q.set("subcategory_id", "0");
    if ctx.req.new_date {
        q.set("new_date", "1");
    }
    let mut out: Vec<String> = Vec::new();
    for (category_id, ids) in summary {
        let families: Vec<&Family> = ids
            .iter()
            .filter_map(|id| ctx.catalog.family(*id))
            .filter(|f| match ctx.req.unit_type.as_str() {
                "metric" => f.is_metric(),
                "imperial" => !f.is_metric(),
                _ => true,
            })
            .collect();
        if families.is_empty() {
            continue;
        }
        let blocked = ctx.blocked(*category_id);
        out.push(format!("<a class=anchor id=category{category_id}></a><h2>{}</h2>", esc_text(ctx.catalog.category_name(*category_id))));
        out.push("<div class='catalog_category_wrapper'>".into());
        out.push("<div class=catalog_subcategory_list>".into());
        out.push("<ul>".into());
        for f in families {
            q.set("subcategory_id", f.id.to_string());
            let (watermark, title) = if ctx.catalog.is_new_date(&f.new_date) {
                (true, "New Item!")
            } else if f.new_inventory {
                (true, "New Sizes!")
            } else {
                (false, "")
            };
            let style = if f.status == Status::Pending {
                "style='border: 1px solid #00b0f0;'"
            } else if watermark {
                "style='border: 1px solid #f00;'"
            } else {
                ""
            };
            let text = format!("<span class='subcat{}'></span>{}", f.id, esc_text(&f.name));
            let link = if blocked {
                format!("<span class='catalog_blocked_link'>{text}</span>")
            } else if title.is_empty() {
                href(&text, &html::url(ctx.php_self, &q))
            } else {
                href_with(&text, &html::url(ctx.php_self, &q), &[("title", title)])
            };
            out.push(format!("<li {style}>{link}</li>"));
        }
        out.push("</ul>".into());
        out.push("</div>".into());
        if blocked {
            out.push(ctx.overlay(*category_id));
        }
        out.push("</div>".into());
    }
    out.push("</div>".into());
    out.join("\n")
}
