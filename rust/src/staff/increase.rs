//! inventory_increase.php: raise every price break of the parts in the chosen subcategories, by a fixed amount or a
//! percentage. It cannot be rolled back. Each subcategory is raised in one transaction and stamped with the time.

use std::{sync::Arc, time::Instant};

use axum::{extract::State, response::Response, routing::get, Router};
use rust_decimal::{Decimal, RoundingStrategy};
use sqlx::SqlitePool;

use super::{
    check::posted_number,
    csrf::{Fields, Posted},
    html::{amount, button, close, esc, js, number, number_dp, open, text, Blank, Form, Notes, Opts},
    inventory::{pricing, pricing_text},
    shell, time, Staff,
};
use crate::{
    api::{Administrator, Auth, ClientIp},
    db::unix_now,
};

const PATH: &str = "/inventory_increase.php";
const TITLE: &str = "Inventory Price Increase";
const REVISED: &str = "12/19/2023";

#[derive(Debug, Clone, Copy, PartialEq)]
enum Increase {
    Fixed(Decimal),
    Percent(Decimal),
}

impl Increase {
    fn kind(self) -> &'static str {
        match self {
            Increase::Fixed(_) => "Fixed Amount",
            Increase::Percent(_) => "Percent",
        }
    }

    fn apply(self, price: Decimal) -> Decimal {
        match self {
            Increase::Fixed(add) => price + add,
            Increase::Percent(pct) => price + (price * pct / Decimal::ONE_HUNDRED).round_dp_with_strategy(2, RoundingStrategy::MidpointAwayFromZero),
        }
    }

    fn describe(self) -> String {
        match self {
            Increase::Fixed(d) => format!("${}", number_dp_decimal(d)),
            Increase::Percent(d) => format!("{}%", number_dp_decimal(d)),
        }
    }
}

fn number_dp_decimal(d: Decimal) -> String {
    number_dp(rust_decimal::prelude::ToPrimitive::to_f64(&d).unwrap_or(0.0), 2)
}

/// What was posted: the increase if it is valid, and the subcategories ticked.
struct Input {
    action: String,
    percent: Decimal,
    fixed: Decimal,
    subcategories: Vec<i64>,
}

fn read_input(f: &Fields, notes: &mut Notes) -> (Input, Option<Increase>) {
    let mut input = Input { action: f.get("action").to_string(), percent: Decimal::ZERO, fixed: Decimal::ZERO, subcategories: Vec::new() };
    if input.action.is_empty() {
        return (input, None);
    }
    let increase = if input.action == "percent" {
        input.percent = posted_number(f.get("percent"), 2);
        if input.percent <= Decimal::ZERO || input.percent >= Decimal::ONE_HUNDRED {
            notes.error_html("percent", "Must be > 0.00% and < 100.00%".into());
        }
        Increase::Percent(input.percent)
    } else {
        input.fixed = posted_number(f.get("fixed"), 2);
        if input.fixed <= Decimal::ZERO {
            notes.error_html("fixed", "Must be > $0.00".into());
        }
        Increase::Fixed(input.fixed)
    };
    input.subcategories = f.iter().filter_map(|(k, _)| k.split('_').collect::<Vec<_>>().get(..2).filter(|p| p[0] == "subcategory").map(|p| super::csrf::php_intval(p[1]))).collect();
    if input.subcategories.is_empty() {
        notes.error("subcategory_id", Some("No subcategories selected"));
    }
    let ok = !notes.has_errors();
    (input, ok.then_some(increase))
}

/// Raises one subcategory's parts. Returns (updated, skipped): a part with no base price is skipped.
async fn raise(pool: &SqlitePool, subcategory_id: i64, increase: Increase, now: i64) -> sqlx::Result<(i64, i64)> {
    let mut tx = pool.begin().await?;
    let parts = sqlx::query!(r#"SELECT id AS "id!: i64", pricing FROM inventory WHERE subcategory_id = ?1"#, subcategory_id).fetch_all(&mut *tx).await?;
    let (mut updated, mut skipped) = (0, 0);
    for part in parts {
        let mut breaks = part.pricing.as_deref().map(pricing).unwrap_or_default();
        if breaks.first().is_none_or(|b| b.price.is_zero()) {
            skipped += 1;
            continue;
        }
        for b in &mut breaks {
            b.price = increase.apply(b.price);
        }
        let stored = pricing_text(&breaks);
        sqlx::query!("UPDATE inventory SET pricing = ?2 WHERE id = ?1", part.id, stored).execute(&mut *tx).await?;
        updated += 1;
    }
    sqlx::query!("UPDATE subcategory SET price_increase = ?2 WHERE id = ?1", subcategory_id, now).execute(&mut *tx).await?;
    tx.commit().await?;
    Ok((updated, skipped))
}

struct Line {
    category_id: i64,
    category: String,
    subcategory_id: i64,
    subcategory: String,
    price_increase: i64,
    count: i64,
}

async fn lines(pool: &SqlitePool) -> sqlx::Result<Vec<Line>> {
    let rows = sqlx::query!(
        r#"SELECT c.id AS "category_id!: i64", c.name AS "category?: String", s.id AS "subcategory_id!: i64", s.name AS "subcategory?: String",
                  s.price_increase AS "price_increase?: i64", count(*) AS "count!: i64"
           FROM inventory i LEFT JOIN category c ON c.id = i.category_id LEFT JOIN subcategory s ON s.id = i.subcategory_id
           WHERE s.output <> 'smart' AND s.status = 'Active' COLLATE NOCASE AND c.id <> 0 AND s.id <> 0
           GROUP BY c.id, s.id ORDER BY c.name COLLATE NOCASE, s.name COLLATE NOCASE"#
    )
    .fetch_all(pool)
    .await?;
    Ok(rows
        .into_iter()
        .map(|r| Line {
            category_id: r.category_id,
            category: r.category.unwrap_or_default(),
            subcategory_id: r.subcategory_id,
            subcategory: r.subcategory.unwrap_or_default(),
            price_increase: r.price_increase.unwrap_or(0),
            count: r.count,
        })
        .collect())
}

const SCRIPT: &str = r##"
<script>
jQuery(document).ready(function() {
    utility_screen();
});
function select_group(group, checked) {
    jQuery("." + group).prop('checked',checked);
    select_count();
}
function utility_screen() {
    jQuery(".fixed_class").hide();
    jQuery(".percent_class").hide();
    switch (jQuery("#action").val()) {
        case "percent":
        jQuery(".percent_class").show();
        break;
        case "fixed":
        jQuery(".fixed_class").show();
        break;
    }
    select_count();
}
function select_count() {
    total=0;
    count=scscpq_class_data('count');
    jQuery('.input').each(function() {
        if (this.checked) total = total + parseInt(count["count_" + this.id]);
    });
    jQuery("#button_id").val((total) ? "Update Price: " + total.toLocaleString() + " SKUs" : "No records selected");
    if ( (total) && (jQuery("#action")[0].selectedIndex) ) {
        jQuery("#button_id").prop("disabled",false);
        } else {
        jQuery("#button_id").prop("disabled",true);
    }
}
function fn_action() {
    if (!confirm("I understand that price increases cannot be rolled back.")) return;
    jQuery("#button_div").html("Working ...");
    jQuery("#scs_form").submit();
}
</script>"##;

async fn run(pool: &SqlitePool, staff: &Staff<Administrator>, ip: &str, f: &Fields) -> sqlx::Result<Response> {
    let mut notes = Notes::new(TITLE, "");
    notes.say(&format!("Revised {REVISED}"));
    let (input, increase) = read_input(f, &mut notes);
    if let Some(increase) = increase {
        let started = Instant::now();
        let now = unix_now();
        let (mut updated, mut skipped) = (0, 0);
        for id in &input.subcategories {
            let (u, s) = raise(pool, *id, increase, now).await?;
            updated += u;
            skipped += s;
        }
        let comment = [
            format!("Subcategories: {}", number(input.subcategories.len() as i64)),
            format!("Increase: {}", increase.describe()),
            format!("SKUs updated: {} SKUs skipped: {}", number(updated), number(skipped)),
            format!("Elapsed time: {} seconds", number_dp(started.elapsed().as_secs_f64(), 2)),
        ];
        let subtype = format!("Price Increase: {}", increase.kind());
        let joined = comment.join("\n");
        sqlx::query!(
            "INSERT INTO log (ip, date_time, user_id, email, type, subtype, comment) VALUES (?1, ?2, ?3, ?4, 'Import Table', ?5, ?6)",
            ip,
            now,
            staff.who.user.id.0,
            staff.who.email,
            subtype,
            joined
        )
        .execute(pool)
        .await?;
        notes.say_html(format!("<br>{}", comment.iter().map(|c| esc(c)).collect::<Vec<_>>().join("<br>")));
    }
    let form = Form { notes: &notes };
    let mut body = notes.render();
    body.push_str(SCRIPT);
    body.push_str(&open(PATH, staff.csrf.as_str(), false));
    body.push_str("<p class=large><b>Price increase cannot be rolled back.</b></p>");
    body.push_str("<table class='border'><tr><th width='20%'>Category</th><th width='40%'>Subcategory</th><th width='10%'># SKUs</th><th width='40%'>Last Price Increase</th></tr>");
    let all = form.checkbox("all", "1", false, Opts::new().onclick("jQuery('.subcategory_all').prop('checked',this.checked);"));
    body.push_str(&format!("<tr><td colspan=4>{all} All categories/subcategories?</td></tr>"));
    let rows = lines(pool).await?;
    let mut start = 0;
    while start < rows.len() {
        let category_id = rows[start].category_id;
        let end = rows[start..].iter().position(|r| r.category_id != category_id).map_or(rows.len(), |n| start + n);
        let category_field = format!("category_{category_id}");
        for (i, r) in rows[start..end].iter().enumerate() {
            let field = format!("subcategory_{}", r.subcategory_id);
            body.push_str(&format!("<input type=hidden name='count_{field}' class='count' id='count_{field}' value='{}'>\n", r.count));
            body.push_str("<tr>");
            if i == 0 {
                let group = form.checkbox(&category_field, "1", false, Opts::new().class("subcategory_all").onclick(format!("select_group({},this.checked);", js(&category_field))));
                body.push_str(&format!("<td rowspan={}>{group} {}</td>", end - start, text(&r.category)));
            }
            let checked = notes.has_errors() && input.subcategories.contains(&r.subcategory_id);
            let tick = form.checkbox(&field, "1", checked, Opts::new().class(format!("subcategory_all {category_field} input")).onclick("select_count();"));
            let last = if r.price_increase != 0 { time::date("m/d/Y h:i A", r.price_increase) } else { "Never".into() };
            body.push_str(&format!("<td>{tick}{}</td><td class=right>{}</td><td>{last}</td></tr>", text(&r.subcategory), number(r.count)));
        }
        start = end;
    }
    let kinds = [("", "Select Increase Type"), ("fixed", "Fixed Amount"), ("percent", "Percent")].map(|(v, l)| (v.to_string(), l.to_string()));
    let select = form.select("action", &kinds, &input.action, Blank::None, Opts::new().onchange("utility_screen();"));
    let mut cell = format!("<span class=percent_class>{}%</span>", form.text("percent", &amount(&input.percent, 2), 6, 6, Opts::new()));
    if let Some(e) = notes.error_text("percent") {
        cell.push_str(&format!("<span class=percent_class>{e}</span>"));
    }
    cell.push_str(&format!("<span class=fixed_class>${}</span>", form.text("fixed", &amount(&input.fixed, 2), 8, 8, Opts::new())));
    if let Some(e) = notes.error_text("fixed") {
        cell.push_str(&format!("<span class=fixed_class>{e}</span>"));
    }
    body.push_str(&format!("<tr><td>{select}</td><td colspan=3>{cell}</td></tr></table>"));
    body.push_str(&format!("<p>{}</p>", button("Update Price", Opts::new().set("id", "button_id").onclick("fn_action();"))));
    body.push_str(close());
    Ok(shell::page(pool, staff.view(), TITLE, &body).await)
}

async fn show(State(auth): State<Arc<Auth>>, ClientIp(ip): ClientIp, staff: Staff<Administrator>) -> Response {
    run(&auth.pool, &staff, &ip, &Fields::default()).await.unwrap_or_else(shell::internal)
}

async fn post(State(auth): State<Arc<Auth>>, ClientIp(ip): ClientIp, staff: Staff<Administrator>, posted: Posted) -> Response {
    run(&auth.pool, &staff, &ip, &posted.fields).await.unwrap_or_else(shell::internal)
}

pub fn routes() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(show).post(post))
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::str::FromStr;

    #[test]
    fn increases_round_each_break_to_the_cent() {
        let d = |s: &str| Decimal::from_str(s).unwrap();
        assert_eq!(Increase::Percent(d("2.5")).apply(d("12.30")), d("12.61"));
        assert_eq!(Increase::Percent(d("10")).apply(d("0.05")), d("0.06"));
        assert_eq!(Increase::Fixed(d("1.25")).apply(d("12.30")), d("13.55"));
        assert_eq!(Increase::Percent(d("12.5")).describe(), "12.50%");
    }

    #[test]
    fn the_increase_and_a_subcategory_are_required() {
        let mut notes = Notes::new(TITLE, "");
        let (_, inc) = read_input(&Fields::of(&[("action", "percent"), ("percent", "100")]), &mut notes);
        assert!(inc.is_none());
        assert_eq!(notes.error_text("percent"), Some("Must be > 0.00% and < 100.00%"));
        assert!(notes.is_error("subcategory_id"));
        let mut notes = Notes::new(TITLE, "");
        let (input, inc) = read_input(&Fields::of(&[("action", "fixed"), ("fixed", "$1.50"), ("subcategory_12", "1"), ("subcategory_30", "1")]), &mut notes);
        assert_eq!(inc, Some(Increase::Fixed(Decimal::from_str("1.50").unwrap())));
        assert_eq!(input.subcategories, [12, 30]);
    }
}
