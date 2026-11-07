//! The staff tables that move to and from desktop files, run as `hxweb export <table>` and `hxweb import <table>`
//! (cli.rs). They replace inventory_utility.php, inventory_no_price.php, inventory_price.php, subcategory_utility.php,
//! subcategory_order.php, inventory_xref.php, user_utility.php, user_email.php, pricing_utility.php,
//! inventory_prj.php and inventory_missing_prj.php. Each table says which columns its file has, which rows an export
//! writes, and how an imported line is checked and saved.
//!
//! As on the pages, a review runs every check and saves nothing; an update saves the lines that pass, or none of
//! them for a table that imports all or nothing (subcategories).

use std::{collections::HashMap, str::FromStr};

use rust_decimal::Decimal;
use sqlx::SqlitePool;

use super::{
    csrf::php_intval,
    filesort,
    map::{self, Field, FileType, Kind, Map, Record, Sheet},
    rules::{self, Lookups, Row, STATUSES},
    smart::{self, Module, RecordType},
    time,
};
use crate::{
    accounts::account,
    catalog::stored,
    db::unix_now,
    identity::{Email, Role},
};

const PARAMETERS: usize = 20;
const BREAKS: usize = 10;
const PRICES: usize = 5;

/// A table the commands move.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Table {
    Inventory,
    Erp,
    Subcategory,
    Xref,
    User,
    Email,
    Pricing,
    PrjMissing,
}

impl Table {
    pub const ALL: [Table; 8] = [Table::Inventory, Table::Erp, Table::Subcategory, Table::Xref, Table::User, Table::Email, Table::Pricing, Table::PrjMissing];

    pub fn name(self) -> &'static str {
        match self {
            Table::Inventory => "inventory",
            Table::Erp => "erp-price",
            Table::Subcategory => "subcategory",
            Table::Xref => "xref",
            Table::User => "user",
            Table::Email => "email",
            Table::Pricing => "pricing",
            Table::PrjMissing => "prj-missing",
        }
    }

    pub fn parse(s: &str) -> Option<Table> {
        Table::ALL.into_iter().find(|t| t.name() == s)
    }

    pub fn exports(self) -> bool {
        self != Table::Erp
    }

    pub fn imports(self) -> bool {
        !matches!(self, Table::Email | Table::PrjMissing)
    }
}

/// Which addresses the email export takes: all, or those first or last seen in the date range.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum EmailSource {
    #[default]
    All,
    First,
    Last,
}

/// What a command narrows a table to. Dates are `Y/m/d`.
#[derive(Debug, Clone, Default)]
pub struct Options {
    pub subcategory_id: i64,
    pub category_id: i64,
    pub no_price: bool,
    pub statuses: Vec<String>,
    pub roles: Vec<String>,
    pub industry_id: i64,
    pub from: String,
    pub thru: String,
    pub source: EmailSource,
    pub record_type: String,
    /// Xref: drop the stored lines the file does not hold. A rejected line keeps the stored lines of its part (or of
    /// its competitor number, when it names no part).
    pub replace: bool,
}

/// One price break: from this quantity up, this price.
#[derive(Debug, Clone, PartialEq)]
pub struct Break {
    pub qty: Decimal,
    pub price: Decimal,
}

fn decimal_of(v: Option<&serde_json::Value>) -> Decimal {
    v.map(stored::text).and_then(|s| Decimal::from_str(s.trim()).or_else(|_| Decimal::from_scientific(s.trim())).ok()).unwrap_or_default()
}

/// inventory.pricing: `[{"price": 12.5, "qty_break": 0}, ...]`.
pub fn pricing(json: &str) -> Vec<Break> {
    let breaks = stored::parse(Some(json));
    stored::entries(&breaks).into_iter().map(|(_, b)| Break { qty: decimal_of(b.get("qty_break")), price: decimal_of(b.get("price")) }).collect()
}

/// The breaks as stored: the first from quantity 0.
pub fn pricing_text(breaks: &[Break]) -> String {
    let number = |d: &Decimal| serde_json::Number::from_f64(d.normalize().to_string().parse().unwrap_or(0.0)).map_or(serde_json::Value::Null, serde_json::Value::Number);
    let list: Vec<serde_json::Value> = breaks
        .iter()
        .enumerate()
        .map(|(i, b)| serde_json::json!({ "price": number(&b.price), "qty_break": if i == 0 { 0.into() } else { number(&b.qty) } }))
        .collect();
    serde_json::Value::Array(list).to_string()
}

// ---------------------------------------------------------------------------------------------------------------
// Columns
// ---------------------------------------------------------------------------------------------------------------

/// inventory->map_inventory(): a part's catalog columns, parameters and price breaks.
fn inventory_map() -> Map {
    let mut m = Map::new();
    m.add(Field::new("sku", Kind::Uppercase).name("Harcourt SKU").required().width(15));
    m.add(Field::new("category_name", Kind::Alphanumeric).name("Category Name").group("subcategory").width(30));
    m.add(Field::new("subcategory_name", Kind::Alphanumeric).name("Subcategory Name").group("subcategory").width(30));
    m.add(Field::new("sort_order", Kind::Decimal(0)).name("Sort Order").width(6));
    m.add(Field::new("new_date", Kind::Date).name("New Date").width(10));
    m.add(Field::new("leadtime_option", Kind::Alphanumeric).name("Leadtime Option").group("leadtime").width(20));
    m.add(Field::new("leadtime_text", Kind::Alphanumeric).name("Leadtime Weeks").group("leadtime").width(20));
    m.add(Field::new("temp_competitor_sku", Kind::Uppercase).name("Competitor SKU").width(60));
    m.add(Field::new("active", Kind::Boolean).name("Active").width(6));
    for i in 0..PARAMETERS {
        m.add(Field::new(format!("parameter{i}"), Kind::Alphanumeric).name(format!("Parameter #{}", i + 1)).group("parameters"));
    }
    for i in 0..PARAMETERS {
        m.add(Field::new(format!("nparameter{i}"), Kind::Numeric).name(format!("Numeric Parameter #{}", i + 1)).group("parameters"));
    }
    for i in 0..BREAKS {
        if i == 0 {
            m.add(Field::new("price0", Kind::Decimal(2)).name("Base Price").group("pricing").width(12));
        } else {
            m.add(Field::new(format!("qty_break{i}"), Kind::Decimal(0)).name(format!("Qty Break #{i}")).group("pricing").width(10));
            m.add(Field::new(format!("price{i}"), Kind::Decimal(2)).name(format!("Price #{i}")).group("pricing").width(12));
        }
    }
    m
}

/// inventory->map_erp(): the price list the ERP exports.
fn erp_map() -> Map {
    let mut m = Map::new();
    m.add(Field::new("sku", Kind::Uppercase).name("Harcourt Part # in Inxsql").required());
    m.add(Field::new("_price_code", Kind::Uppercase).name("Price Code").required());
    m.add(Field::new("_price0", Kind::Decimal(4)).name("List Price").required());
    for i in 1..=BREAKS {
        m.add(Field::new(format!("_break{i}"), Kind::Decimal(0)).name(format!("iqty_quantity{i}")).required());
        m.add(Field::new(format!("_price{i}"), Kind::Decimal(4)).name(format!("iprc{i}")).required());
    }
    m
}

/// subcategory->map(): lead times, the PARTcommunity project and the sort order of each subcategory.
fn subcategory_map() -> Map {
    let mut m = Map::new();
    m.add(Field::new("id", Kind::Id).name("Subcategory ID").required());
    m.add(Field::new("category_name", Kind::Alphanumeric).name("Category Name").width(60).export_only());
    m.add(Field::new("name", Kind::Alphanumeric).name("Subcategory Name").width(80).export_only());
    m.add(Field::new("leadtime_option", Kind::Alphanumeric).name("Leadtime Option").width(20).required());
    m.add(Field::new("leadtime_text", Kind::Alphanumeric).name("Leadtime Weeks").width(20).required());
    m.add(Field::new("status", Kind::Alphanumeric).name("Status").export_only());
    m.add(Field::new("prj", Kind::Alphanumeric).name("PRJ").width(60));
    m.add(Field::new("sort_order", Kind::Decimal(0)).name("Sort Order").width(10));
    m
}

/// inventory_xref->map(): one competitor part number per line.
fn xref_map() -> Map {
    let mut m = Map::new();
    m.add(Field::new("sku", Kind::Uppercase).name("Item #").required());
    m.add(Field::new("competitor_sku", Kind::Uppercase).name("Xref").required());
    m
}

/// user->map(): the account columns.
fn user_map() -> Map {
    let mut m = Map::new();
    m.add(Field::new("id", Kind::Id).required());
    m.add(Field::new("ip", Kind::Alphanumeric).required());
    m.add(Field::new("company_name", Kind::Alphanumeric).name("Company Name"));
    m.add(Field::new("address", Kind::Alphanumeric).name("Address"));
    m.add(Field::new("city", Kind::Alphanumeric).name("City"));
    m.add(Field::new("state", Kind::Alphanumeric).name("State/Province"));
    m.add(Field::new("zip", Kind::Uppercase).name("ZIP/Postal Cost"));
    m.add(Field::new("country_code", Kind::Uppercase).name("Country Code"));
    m.add(Field::new("status", Kind::Alphanumeric));
    m.add(Field::new("type", Kind::Alphanumeric));
    m.add(Field::new("last_login", Kind::Timestamp).export_only());
    m.add(Field::new("customer_code", Kind::Uppercase));
    m.add(Field::new("catalog", Kind::Boolean));
    m.add(Field::new("cad", Kind::Boolean));
    m.add(Field::new("leadscore", Kind::Boolean));
    m.add(Field::new("ecommerce", Kind::Boolean));
    m.add(Field::new("price_type", Kind::Alphanumeric));
    m.add(Field::new("price_pct", Kind::Percent(4)));
    m.add(Field::new("login_document_id", Kind::Id));
    m.add(Field::new("landing_document_id", Kind::Id));
    m.add(Field::new("portal_id", Kind::Decimal(0)));
    m.add(Field::new("comment", Kind::Text));
    m.add(Field::new("language_code", Kind::Alphanumeric));
    m.add(Field::new("currency_code", Kind::Uppercase));
    m.add(Field::new("industry_name", Kind::Alphanumeric));
    m.add(Field::new("revenue", Kind::Alphanumeric));
    m.add(Field::new("employees", Kind::Alphanumeric));
    m
}

/// export->initialize_email(): every address the log has seen, with the account's address.
fn email_map() -> Map {
    let mut m = Map::new();
    m.add(Field::new("email", Kind::Alphanumeric).name("Email").width(40));
    m.add(Field::new("company_name", Kind::Alphanumeric).name("Company Name").width(40));
    m.add(Field::new("address", Kind::Alphanumeric).name("Address").width(40));
    m.add(Field::new("city", Kind::Alphanumeric).name("City").width(40));
    m.add(Field::new("state", Kind::Alphanumeric).name("State/Province"));
    m.add(Field::new("zip", Kind::Uppercase).name("ZIP/Postal Cost"));
    m.add(Field::new("country_code", Kind::Uppercase).name("Country Code"));
    m
}

/// pricing->map(): the columns of a smart subcategory's pricing file. Given a record type, its options and prices
/// name the code and price columns and the ones it does not use go.
fn pricing_map(record_type: Option<&RecordType>) -> Map {
    let mut m = Map::new();
    m.add(Field::new("subcategory_id", Kind::Lowercase).required());
    m.add(Field::new("record_type", Kind::Lowercase).required());
    for i in 0..PRICES {
        let field = Field::new(format!("code{i}"), Kind::Uppercase).required();
        match record_type.map(|r| r.fields.get(i)) {
            None => m.add(field),
            Some(Some(name)) => m.add(field.name(*name)),
            Some(None) => {}
        }
    }
    for i in 0..PRICES {
        let field = Field::new(format!("price{i}"), Kind::Decimal(2)).required();
        match record_type.map(|r| r.prices.get(i)) {
            None => m.add(field),
            Some(Some(name)) if name.to_lowercase().contains("price") => m.add(field.name(*name)),
            Some(Some(name)) => m.add(field.name(format!("{name} price"))),
            Some(None) => {}
        }
    }
    m
}

/// inventory_missing_prj.php: the parts without a PARTsolutions link.
fn prj_missing_map() -> Map {
    let mut m = Map::new();
    m.add(Field::new("category_name", Kind::Alphanumeric).name("Category Name").width(30));
    m.add(Field::new("subcategory_name", Kind::Alphanumeric).name("Subcategory Name").width(30));
    m.add(Field::new("sku", Kind::Uppercase).name("Harcourt SKU").width(15));
    m
}

fn aligned(map: &Map, mut values: HashMap<&str, String>) -> Record {
    map.fields().iter().map(|f| values.remove(f.key.as_str()).unwrap_or_default()).collect()
}

fn opt(s: Option<String>) -> String {
    s.unwrap_or_default()
}

// ---------------------------------------------------------------------------------------------------------------
// Export
// ---------------------------------------------------------------------------------------------------------------

/// The sheets an export writes: one for every table, one per record type for a smart subcategory's prices in Excel.
pub async fn export(pool: &SqlitePool, table: Table, o: &Options, file: FileType) -> Result<Vec<(String, Map, Vec<Record>)>, String> {
    let fail = |e: sqlx::Error| e.to_string();
    let one = |map: Map, rows: Vec<Record>| vec![(table.name().to_string(), map, rows)];
    Ok(match table {
        Table::Inventory => {
            let map = inventory_map();
            let rows = inventory_export(pool, &map, o.subcategory_id, o.no_price).await.map_err(fail)?;
            one(map, rows)
        }
        Table::Subcategory => {
            let map = subcategory_map();
            let rows = subcategory_export(pool, &map, o.category_id).await.map_err(fail)?;
            one(map, rows)
        }
        Table::Xref => {
            let map = xref_map();
            let rows = xref_export(pool, &map).await.map_err(fail)?;
            one(map, rows)
        }
        Table::User => {
            let map = user_map();
            let rows = user_export(pool, &map, o).await.map_err(fail)?;
            one(map, rows)
        }
        Table::Email => {
            let map = email_map();
            let rows = email_export(pool, &map, o).await.map_err(fail)?;
            one(map, rows)
        }
        Table::PrjMissing => {
            let map = prj_missing_map();
            let rows = prj_missing(pool, &map).await.map_err(fail)?;
            one(map, rows)
        }
        Table::Pricing => pricing_export(pool, o, file).await?,
        Table::Erp => return Err("erp-price is imported only".into()),
    })
}

/// inventory->export_query(): the parts in category, subcategory, sort order and SKU order.
async fn inventory_ids(pool: &SqlitePool, subcategory_id: i64, no_price: bool) -> sqlx::Result<Vec<i64>> {
    let mut keys = sqlx::query!(
        r#"SELECT i.id AS "id!: i64", c.name AS category, s.name AS subcategory, i.sort_order AS "sort_order!: i64", i.sku
           FROM inventory i LEFT JOIN subcategory s ON s.id = i.subcategory_id LEFT JOIN category c ON c.id = s.category_id
           WHERE (?1 = 0 OR i.subcategory_id = ?1) AND (?2 = 0 OR COALESCE(i.pricing, '') IN ('', '[]'))
           ORDER BY i.id"#,
        subcategory_id,
        no_price
    )
    .fetch_all(pool)
    .await?;
    filesort::sort(&mut keys, |a, b| {
        filesort::ci_opt(a.category.as_deref(), b.category.as_deref())
            .then_with(|| filesort::ci_opt(a.subcategory.as_deref(), b.subcategory.as_deref()))
            .then(a.sort_order.cmp(&b.sort_order))
            .then_with(|| filesort::ci(&a.sku, &b.sku))
    });
    Ok(keys.into_iter().map(|k| k.id).collect())
}

/// The export rows of these parts, as export_data() filled them.
async fn inventory_rows(pool: &SqlitePool, map: &Map, ids: &[i64]) -> sqlx::Result<Vec<Record>> {
    let list = serde_json::to_string(ids).unwrap_or_default();
    let rows = sqlx::query!(
        r#"SELECT i.id AS "id!: i64", i.sku, c.name AS category, s.name AS subcategory, i.sort_order AS "sort_order!: i64", i.new_date,
                  i.leadtime_option, i.leadtime_text, i.competitor_sku, i.active, i.pricing,
                  json_array(i.parameter0, i.parameter1, i.parameter2, i.parameter3, i.parameter4, i.parameter5, i.parameter6, i.parameter7,
                             i.parameter8, i.parameter9, i.parameter10, i.parameter11, i.parameter12, i.parameter13, i.parameter14,
                             i.parameter15, i.parameter16, i.parameter17, i.parameter18, i.parameter19) AS "parameters!: String",
                  json_array(i.nparameter0, i.nparameter1, i.nparameter2, i.nparameter3, i.nparameter4, i.nparameter5, i.nparameter6,
                             i.nparameter7, i.nparameter8, i.nparameter9, i.nparameter10, i.nparameter11, i.nparameter12, i.nparameter13,
                             i.nparameter14, i.nparameter15, i.nparameter16, i.nparameter17, i.nparameter18, i.nparameter19) AS "nparameters!: String"
           FROM inventory i LEFT JOIN subcategory s ON s.id = i.subcategory_id LEFT JOIN category c ON c.id = s.category_id
           WHERE i.id IN (SELECT value FROM json_each(?1))"#,
        list
    )
    .fetch_all(pool)
    .await?;
    let mut by_id: HashMap<i64, Record> = HashMap::new();
    for r in rows {
        let competitor: Vec<&str> = r.competitor_sku.as_deref().filter(|c| !c.is_empty()).map(|c| c.split(',').collect()).unwrap_or_default();
        let mut v: HashMap<&str, String> = HashMap::from([
            ("sku", r.sku),
            ("category_name", opt(r.category)),
            ("subcategory_name", opt(r.subcategory)),
            ("sort_order", r.sort_order.to_string()),
            ("new_date", r.new_date),
            ("leadtime_option", r.leadtime_option),
            ("leadtime_text", opt(r.leadtime_text)),
            ("temp_competitor_sku", competitor.join(", ")),
            ("active", r.active.map(|n| n.to_string()).unwrap_or_default()),
        ]);
        let texts: Vec<serde_json::Value> = serde_json::from_str(&r.parameters).unwrap_or_default();
        let numbers: Vec<serde_json::Value> = serde_json::from_str(&r.nparameters).unwrap_or_default();
        let mut keyed: Vec<(String, String)> = Vec::new();
        for i in 0..PARAMETERS {
            keyed.push((format!("parameter{i}"), texts.get(i).and_then(|t| t.as_str()).unwrap_or("").to_string()));
            keyed.push((format!("nparameter{i}"), map::float_text(numbers.get(i).and_then(serde_json::Value::as_f64).unwrap_or(0.0))));
        }
        let breaks = r.pricing.as_deref().map(pricing).unwrap_or_default();
        for i in 0..BREAKS {
            let b = breaks.get(i);
            keyed.push((format!("price{i}"), b.map(|b| b.price.to_string()).unwrap_or_default()));
            keyed.push((format!("qty_break{i}"), b.map(|b| b.qty.to_string()).unwrap_or_default()));
        }
        for (k, value) in &keyed {
            v.insert(k.as_str(), value.clone());
        }
        by_id.insert(r.id, aligned(map, v));
    }
    Ok(ids.iter().filter_map(|id| by_id.remove(id)).collect())
}

async fn inventory_export(pool: &SqlitePool, map: &Map, subcategory_id: i64, no_price: bool) -> sqlx::Result<Vec<Record>> {
    let ids = inventory_ids(pool, subcategory_id, no_price).await?;
    let mut out = Vec::with_capacity(ids.len());
    for chunk in ids.chunks(500) {
        out.extend(inventory_rows(pool, map, chunk).await?);
    }
    Ok(out)
}

/// subcategory->export_query(): subcategories of real categories, by category and name.
async fn subcategory_export(pool: &SqlitePool, map: &Map, category_id: i64) -> sqlx::Result<Vec<Record>> {
    let mut rows = sqlx::query!(
        r#"SELECT s.id AS "id!: i64", c.name AS category, s.name, s.leadtime_option, s.leadtime_text, s.status, s.prj, COALESCE(s.sort_order, 0) AS "sort_order!: i64"
           FROM subcategory s LEFT JOIN category c ON c.id = s.category_id
           WHERE c.id <> 0 AND (?1 = 0 OR s.category_id = ?1) ORDER BY s.id"#,
        category_id
    )
    .fetch_all(pool)
    .await?;
    filesort::sort(&mut rows, |a, b| filesort::ci_opt(a.category.as_deref(), b.category.as_deref()).then_with(|| filesort::ci_opt(a.name.as_deref(), b.name.as_deref())));
    Ok(rows
        .into_iter()
        .map(|r| {
            aligned(
                map,
                HashMap::from([
                    ("id", r.id.to_string()),
                    ("category_name", opt(r.category)),
                    ("name", opt(r.name)),
                    ("leadtime_option", r.leadtime_option),
                    ("leadtime_text", opt(r.leadtime_text)),
                    ("status", r.status),
                    ("prj", opt(r.prj)),
                    ("sort_order", r.sort_order.to_string()),
                ]),
            )
        })
        .collect())
}

async fn xref_export(pool: &SqlitePool, map: &Map) -> sqlx::Result<Vec<Record>> {
    let mut rows = sqlx::query!(r#"SELECT sku, competitor, competitor_sku FROM inventory_xref ORDER BY sku, competitor, competitor_sku"#).fetch_all(pool).await?;
    filesort::sort(&mut rows, |a, b| filesort::ci(&a.sku, &b.sku).then_with(|| filesort::ci(&a.competitor, &b.competitor)));
    Ok(rows.into_iter().map(|r| aligned(map, HashMap::from([("sku", r.sku), ("competitor_sku", r.competitor_sku)]))).collect())
}

/// The accounts user_utility.php exported: by status, role, industry and last sign-in (every status and role when
/// none is named).
async fn user_export(pool: &SqlitePool, map: &Map, o: &Options) -> sqlx::Result<Vec<Record>> {
    let all = |given: &[String], every: Vec<String>| if given.is_empty() { every } else { given.to_vec() };
    let statuses = serde_json::to_string(&all(&o.statuses, STATUSES.map(String::from).to_vec())).unwrap_or_default();
    let roles = serde_json::to_string(&all(&o.roles, Role::ALL.iter().map(|r| r.as_str().to_string()).collect())).unwrap_or_default();
    let mut rows = sqlx::query!(
        r#"SELECT u.id AS "id!: i64", u.ip, u.company_name, u.address, u.city, u.state, u.zip, u.country_code, u.status, u."type" AS kind,
                  u.last_login, u.customer_code, u.catalog, u.cad, u.leadscore, u.ecommerce, u.price_type, u.price_pct, u.login_document_id,
                  u.landing_document_id, u.portal_id, u.comment, u.language_code, u.currency_code, i.name AS industry, u.revenue, u.employees
           FROM "user" u LEFT JOIN industry i ON i.id = u.industry_id
           WHERE u.status IN (SELECT value FROM json_each(?1)) AND u."type" IN (SELECT value FROM json_each(?2))
             AND (?3 = 0 OR u.industry_id = ?3)
           ORDER BY u.id"#,
        statuses,
        roles,
        o.industry_id
    )
    .fetch_all(pool)
    .await?;
    rows.retain(|r| {
        let day = time::date("Y/m/d", r.last_login);
        (o.from.is_empty() || day >= o.from) && (o.thru.is_empty() || day <= o.thru)
    });
    filesort::sort(&mut rows, |a, b| filesort::ci_opt(a.ip.as_deref(), b.ip.as_deref()));
    Ok(rows
        .into_iter()
        .map(|r| {
            aligned(
                map,
                HashMap::from([
                    ("id", r.id.to_string()),
                    ("ip", opt(r.ip)),
                    ("company_name", opt(r.company_name)),
                    ("address", opt(r.address)),
                    ("city", opt(r.city)),
                    ("state", opt(r.state)),
                    ("zip", opt(r.zip)),
                    ("country_code", opt(r.country_code)),
                    ("status", r.status),
                    ("type", r.kind),
                    ("last_login", r.last_login.to_string()),
                    ("customer_code", opt(r.customer_code)),
                    ("catalog", r.catalog.to_string()),
                    ("cad", r.cad.to_string()),
                    ("leadscore", r.leadscore.to_string()),
                    ("ecommerce", r.ecommerce.to_string()),
                    ("price_type", r.price_type),
                    ("price_pct", map::float_text(r.price_pct)),
                    ("login_document_id", r.login_document_id.to_string()),
                    ("landing_document_id", r.landing_document_id.to_string()),
                    ("portal_id", r.portal_id.to_string()),
                    ("comment", opt(r.comment)),
                    ("language_code", r.language_code),
                    ("currency_code", r.currency_code),
                    ("industry_name", opt(r.industry)),
                    ("revenue", r.revenue),
                    ("employees", r.employees),
                ]),
            )
        })
        .collect())
}

/// Every address seen signed in, once in whatever case it was written, dated by its own first and last sighting,
/// with the company of the account it belongs to now (`account::for_email`), or else of the account its latest
/// sighting was under.
async fn email_export(pool: &SqlitePool, map: &Map, o: &Options) -> sqlx::Result<Vec<Record>> {
    let mut seen = sqlx::query!(
        r#"SELECT email AS "email!: String", first_seen AS "first_seen!: i64", last_seen AS "last_seen!: i64", user_id AS "last_user_id!: i64"
           FROM (SELECT lower(trim(email)) AS email, user_id, min(date_time) OVER address AS first_seen, max(date_time) OVER address AS last_seen,
                        row_number() OVER (address ORDER BY date_time DESC, id DESC) AS latest
                 FROM log WHERE trim(email) <> '' AND user_id <> 0
                 WINDOW address AS (PARTITION BY lower(trim(email))))
           WHERE latest = 1"#
    )
    .fetch_all(pool)
    .await?;
    seen.retain(|r| {
        let at = match o.source {
            EmailSource::All => return true,
            EmailSource::First => r.first_seen,
            EmailSource::Last => r.last_seen,
        };
        let day = time::date("Y/m/d", at);
        (o.from.is_empty() || day >= o.from) && (o.thru.is_empty() || day <= o.thru)
    });
    let mut rows = Vec::with_capacity(seen.len());
    for r in seen {
        let owner = match Email::parse(&r.email) {
            Some(email) => account::for_email(pool, &email).await?,
            None => None,
        };
        let id = owner.map_or(r.last_user_id, |u| u.0);
        let company = sqlx::query!(
            r#"SELECT company_name AS "company_name!: String", address, city, state, zip, country_code
               FROM "user" WHERE id = ?1 AND COALESCE(company_name, '') <> ''"#,
            id
        )
        .fetch_optional(pool)
        .await?;
        if let Some(c) = company {
            rows.push((r.email, c));
        }
    }
    filesort::sort(&mut rows, |a, b| filesort::ci(&a.0, &b.0));
    Ok(rows
        .into_iter()
        .map(|(email, c)| {
            aligned(
                map,
                HashMap::from([
                    ("email", email),
                    ("company_name", c.company_name),
                    ("address", opt(c.address)),
                    ("city", opt(c.city)),
                    ("state", opt(c.state)),
                    ("zip", opt(c.zip)),
                    ("country_code", opt(c.country_code)),
                ]),
            )
        })
        .collect())
}

async fn prj_missing(pool: &SqlitePool, map: &Map) -> sqlx::Result<Vec<Record>> {
    let rows = sqlx::query!(
        r#"SELECT i.sku,
                  CASE WHEN c.id IS NULL THEN 'Missing Category #' || s.category_id ELSE c.name END AS "category?: String",
                  s.name AS "subcategory?: String"
           FROM inventory i LEFT JOIN subcategory s ON s.id = i.subcategory_id LEFT JOIN category c ON c.id = s.category_id
           WHERE i.prj = ''
           ORDER BY c.sort_order, s.sort_order, i.sku"#
    )
    .fetch_all(pool)
    .await?;
    Ok(rows.into_iter().map(|r| aligned(map, HashMap::from([("category_name", opt(r.category)), ("subcategory_name", opt(r.subcategory)), ("sku", r.sku)]))).collect())
}

fn query_options(stored: &str) -> Vec<(String, String)> {
    form_urlencoded::parse(stored.as_bytes()).into_owned().collect()
}

fn smart_module(o: &Options) -> Result<&'static Module, String> {
    smart::module(o.subcategory_id).ok_or_else(|| format!("subcategory {} has no smart pricing", o.subcategory_id))
}

fn record_type(module: &'static Module, code: &str) -> Result<Option<&'static RecordType>, String> {
    if code.is_empty() {
        return Ok(None);
    }
    module.record_type(code).map(Some).ok_or_else(|| format!("record type {code} is not one of the subcategory's"))
}

/// pricing_utility.php's export: a smart subcategory's price rows. Every record type into one Excel file gets a
/// worksheet each.
async fn pricing_export(pool: &SqlitePool, o: &Options, file: FileType) -> Result<Vec<(String, Map, Vec<Record>)>, String> {
    let module = smart_module(o)?;
    let only = record_type(module, &o.record_type)?;
    let sub = o.subcategory_id.to_string();
    let rows = sqlx::query!(
        r#"SELECT record_type, options, price0, price1, price2, price3, price4 FROM pricing
           WHERE subcategory_id = ?1 AND (?2 = '' OR record_type = ?2) ORDER BY record_type, options"#,
        sub,
        o.record_type
    )
    .fetch_all(pool)
    .await
    .map_err(|e| e.to_string())?;
    let record = |map: &Map, row: &(String, String, [f64; PRICES])| -> Record {
        let r = module.record_type(&row.0);
        let options = query_options(&row.1);
        map.fields()
            .iter()
            .map(|f| match (f.key.as_str(), f.key.strip_prefix("code"), f.key.strip_prefix("price")) {
                ("subcategory_id", ..) => sub.clone(),
                ("record_type", ..) => row.0.clone(),
                (_, Some(i), _) => r
                    .and_then(|r| r.fields.get(php_intval(i) as usize))
                    .and_then(|name| options.iter().find(|(k, _)| k == name))
                    .map_or(String::new(), |(_, v)| v.clone()),
                (_, _, Some(i)) => row.2.get(php_intval(i) as usize).map_or(String::new(), |p| map::float_text(*p)),
                _ => String::new(),
            })
            .collect()
    };
    let rows: Vec<(String, String, [f64; PRICES])> = rows.into_iter().map(|r| (r.record_type, r.options, [r.price0, r.price1, r.price2, r.price3, r.price4])).collect();
    if file == FileType::Xlsx && only.is_none() {
        return Ok(module
            .record_types
            .iter()
            .filter_map(|r| {
                let map = pricing_map(Some(r));
                let records: Vec<Record> = rows.iter().filter(|row| row.0 == r.code).map(|row| record(&map, row)).collect();
                (!records.is_empty()).then(|| (r.name.to_string(), map, records))
            })
            .collect());
    }
    let map = pricing_map(only);
    let records = rows.iter().map(|row| record(&map, row)).collect();
    Ok(vec![("pricing".to_string(), map, records)])
}

// ---------------------------------------------------------------------------------------------------------------
// Import
// ---------------------------------------------------------------------------------------------------------------

/// A line of an import: field key to the value read.
type Line = HashMap<String, String>;
/// What is wrong with a line: the field and the message.
pub type Errors = Vec<(String, String)>;

fn has(line: &Line, key: &str) -> bool {
    line.contains_key(key)
}

fn val<'a>(line: &'a Line, key: &str) -> &'a str {
    line.get(key).map_or("", String::as_str)
}

/// The table an import writes, with what it looked up once for every line.
enum Target {
    Inventory(Box<Families>),
    Erp,
    Subcategory { ids: Vec<i64>, lookups: Lookups },
    Xref { stamp: i64 },
    User(Box<Lookups>),
    Pricing { module: &'static Module, subcategory_id: String, only: Option<&'static RecordType> },
}

/// The families an inventory line may name: the one the import is narrowed to, each by its lower-cased
/// `category:name` (None when the name fits more than one), and the lookups of the part check.
struct Families {
    subcategory_id: i64,
    subcategory: Option<(i64, i64)>,
    names: HashMap<String, Option<(i64, i64)>>,
    lookups: Lookups,
}

impl Target {
    async fn load(pool: &SqlitePool, table: Table, o: &Options) -> Result<Target, String> {
        let fail = |e: sqlx::Error| e.to_string();
        Ok(match table {
            Table::Inventory => inventory_target(pool, o.subcategory_id).await.map_err(fail)?,
            Table::Erp => Target::Erp,
            Table::Subcategory => {
                let ids = subcategory_export(pool, &subcategory_map(), o.category_id).await.map_err(fail)?;
                let lookups = Lookups::load(pool, "subcategory").await.map_err(fail)?;
                Target::Subcategory { ids: ids.iter().map(|r| php_intval(&r[0])).collect(), lookups }
            }
            Table::Xref => Target::Xref { stamp: if o.replace { unix_now() } else { 0 } },
            Table::User => Target::User(Box::new(Lookups::load(pool, "user").await.map_err(fail)?)),
            Table::Pricing => {
                let module = smart_module(o)?;
                Target::Pricing { module, subcategory_id: o.subcategory_id.to_string(), only: record_type(module, &o.record_type)? }
            }
            Table::Email | Table::PrjMissing => return Err(format!("{} is exported only", table.name())),
        })
    }

    fn map(&self) -> Map {
        match self {
            Target::Inventory(_) => inventory_map(),
            Target::Erp => erp_map(),
            Target::Subcategory { .. } => subcategory_map(),
            Target::Xref { .. } => xref_map(),
            Target::User(_) => user_map(),
            Target::Pricing { only, .. } => pricing_map(*only),
        }
    }

    /// A rejected line rolls back the whole update.
    fn all_or_nothing(&self) -> bool {
        matches!(self, Target::Subcategory { .. })
    }

    /// import_update() for one line. `complete`: the file has every column of the map, so a new record may be added.
    async fn line(&self, tx: &mut sqlx::SqliteConnection, line: &Line, update: bool, complete: bool) -> sqlx::Result<Errors> {
        match self {
            Target::Inventory(families) => inventory_line(tx, line, update, families, complete).await,
            Target::Erp => erp_line(tx, line, update).await,
            Target::Subcategory { ids, lookups } => subcategory_line(tx, line, update, ids, lookups).await,
            Target::Xref { stamp } => xref_line(tx, line, update, *stamp).await,
            Target::User(lookups) => user_line(tx, line, update, lookups).await,
            Target::Pricing { module, subcategory_id, only } => pricing_line(tx, line, update, module, subcategory_id, *only).await,
        }
    }

    /// import_complete(): what an update does after the last line.
    async fn finish(&self, tx: &mut sqlx::SqliteConnection) -> sqlx::Result<()> {
        if let Target::Xref { stamp } = self {
            if *stamp != 0 {
                sqlx::query!("DELETE FROM inventory_xref WHERE update_timestamp <> ?1", stamp).execute(&mut *tx).await?;
            }
        }
        Ok(())
    }
}

/// What an import did: the counts, each rejected line with its errors, and whether the update was kept.
#[derive(Debug, Default)]
pub struct Report {
    pub accepted: usize,
    pub empty: usize,
    pub saved: usize,
    pub rejected: Vec<(usize, Errors)>,
    pub kept: bool,
}

/// upload() and import(): match the file's header to the table, check every line, and save the lines that pass
/// when `update`, in one transaction.
pub async fn import(pool: &SqlitePool, table: Table, o: &Options, sheet: Sheet, update: bool) -> Result<Report, String> {
    let target = Target::load(pool, table, o).await?;
    let map = target.map();
    let mut rows = sheet.rows.into_iter();
    let names: Vec<String> = rows.next().unwrap_or_default().iter().map(|s| s.trim().to_string()).collect();
    let header = map::match_header(&map, &names);
    let columns: Vec<&Field> = map.fields().iter().filter(|f| header.column.contains_key(&f.key)).collect();
    if !header.errors.is_empty() || columns.is_empty() {
        let mut errors = header.errors;
        if columns.is_empty() {
            errors.push("No column of the file matches the table".into());
        }
        return Err(errors.join("\n"));
    }
    let fail = |e: sqlx::Error| e.to_string();
    let mut tx = pool.begin().await.map_err(fail)?;
    let mut report = Report::default();
    for (i, row) in rows.enumerate() {
        let cells: Vec<&str> = row.iter().map(|c| c.trim()).collect();
        if cells.iter().all(|c| c.is_empty()) {
            report.empty += 1;
            continue;
        }
        report.accepted += 1;
        let (mut line, mut errors) = (Line::new(), Errors::new());
        for f in &columns {
            let cell = cells.get(header.column[&f.key]).copied().unwrap_or("");
            match map::import_value(f, cell, sheet.file_type) {
                Ok(value) => line.insert(f.key.clone(), value),
                Err(e) => {
                    errors.push((f.key.clone(), e));
                    line.insert(f.key.clone(), String::new())
                }
            };
        }
        let checked = target.line(&mut tx, &line, update && errors.is_empty(), header.complete).await.map_err(fail)?;
        let unread: Vec<String> = errors.iter().map(|(k, _)| k.clone()).collect();
        errors.extend(checked.into_iter().filter(|(k, _)| !unread.contains(k)));
        if errors.is_empty() {
            report.saved += 1;
        } else {
            report.rejected.push((i + 2, errors));
        }
    }
    report.kept = update && !(target.all_or_nothing() && !report.rejected.is_empty());
    if report.kept {
        target.finish(&mut tx).await.map_err(fail)?;
        tx.commit().await.map_err(fail)?;
    } else {
        tx.rollback().await.map_err(fail)?;
    }
    Ok(report)
}

async fn inventory_target(pool: &SqlitePool, subcategory_id: i64) -> sqlx::Result<Target> {
    let rows = sqlx::query!(
        r#"SELECT s.id AS "id!: i64", s.category_id, c.name AS category, s.name FROM subcategory s LEFT JOIN category c ON c.id = s.category_id
           WHERE (?1 = 0 OR s.id = ?1)"#,
        subcategory_id
    )
    .fetch_all(pool)
    .await?;
    let mut names = HashMap::new();
    let mut subcategory = None;
    for r in rows {
        let ids = (r.category_id.unwrap_or(0), r.id);
        if r.id == subcategory_id {
            subcategory = Some(ids);
        }
        names.entry(format!("{}:{}", opt(r.category), opt(r.name)).to_lowercase()).and_modify(|found| *found = None).or_insert(Some(ids));
    }
    Ok(Target::Inventory(Box::new(Families { subcategory_id, subcategory, names, lookups: Lookups::load(pool, "inventory").await? })))
}

fn decimal(v: f64) -> Decimal {
    rust_decimal::prelude::FromPrimitive::from_f64(v).unwrap_or_default()
}

async fn inventory_line(
    tx: &mut sqlx::SqliteConnection,
    line: &Line,
    update: bool,
    families: &Families,
    complete: bool,
) -> sqlx::Result<Errors> {
    let Families { subcategory_id, subcategory, names, lookups } = families;
    let sku = val(line, "sku");
    let found = sqlx::query!(r#"SELECT id AS "id!: i64", category_id, subcategory_id FROM inventory WHERE sku = ?1"#, sku).fetch_optional(&mut *tx).await?;
    let mut errors: Errors = Vec::new();
    if sku.is_empty() || (found.is_none() && !complete) {
        errors.push(("sku".into(), format!("Invalid SKU: {sku}")));
    }
    let (mut category_id, mut sub_id) = found.as_ref().map_or((0, 0), |f| (f.category_id.unwrap_or(0), f.subcategory_id.unwrap_or(0)));
    let mut r = Row::default();
    if has(line, "category_name") {
        let (category, name) = (val(line, "category_name"), val(line, "subcategory_name"));
        let mut ambiguous = false;
        if !category.is_empty() {
            let family = names.get(&format!("{category}:{name}").to_lowercase());
            ambiguous = matches!(family, Some(None));
            (category_id, sub_id) = family.copied().flatten().unwrap_or((0, 0));
        } else if *subcategory_id != 0 {
            (category_id, sub_id) = subcategory.unwrap_or((0, 0));
        }
        if ambiguous {
            errors.push(("subcategory_name".into(), format!("More than one subcategory is named {category}:{name}")));
        } else if sub_id == 0 {
            errors.push(("subcategory_name".into(), format!("Invalid subcategory: {category}:{name}")));
        } else {
            r.give("subcategory_id", Some(sub_id.to_string()));
        }
    }
    for (key, col) in [("temp_competitor_sku", "competitor_sku"), ("leadtime_option", "leadtime_option"), ("leadtime_text", "leadtime_text"), ("sort_order", "sort_order")] {
        if has(line, key) {
            r.give(col, Some(val(line, key).to_string()));
        }
    }
    let checked = owned(rules::part(&mut r, lookups));
    errors.extend(checked.into_iter().map(|(k, m)| (if k == "competitor_sku" { "temp_competitor_sku".into() } else { k }, m)));
    if r.given("category_id") {
        (category_id, sub_id) = (r.int("category_id"), r.int("subcategory_id"));
    }
    if has(line, "price0") && val(line, "price0").is_empty() {
        errors.push(("price0".into(), "Cannot be blank".into()));
    }
    let breaks = has(line, "price0").then(|| {
        let mut out: Vec<Break> = Vec::new();
        for i in 0..BREAKS {
            let price = map::floatval(val(line, &format!("price{i}")));
            let qty = map::floatval(val(line, &format!("qty_break{i}")));
            if price == 0.0 {
                continue;
            }
            let qty = match out.last() {
                Some(last) => {
                    let last_qty = rust_decimal::prelude::ToPrimitive::to_f64(&last.qty).unwrap_or(0.0);
                    if qty <= last_qty {
                        errors.push((format!("qty_break{i}"), format!("{qty} <= {last_qty}")));
                    }
                    qty
                }
                None => 0.0,
            };
            out.push(Break { qty: decimal(qty), price: decimal(price) });
        }
        out
    });
    if !errors.is_empty() || !update {
        return Ok(errors);
    }
    let id = match &found {
        Some(f) => f.id,
        None => sqlx::query_scalar!(r#"INSERT INTO inventory (sku) VALUES (?1) RETURNING id AS "id!: i64""#, sku).fetch_one(&mut *tx).await?,
    };
    sqlx::query!("UPDATE inventory SET category_id = ?2, subcategory_id = ?3 WHERE id = ?1", id, category_id, sub_id).execute(&mut *tx).await?;
    if r.given("sort_order") {
        let n = r.int("sort_order");
        sqlx::query!("UPDATE inventory SET sort_order = ?2 WHERE id = ?1", id, n).execute(&mut *tx).await?;
    }
    if has(line, "new_date") {
        let d = val(line, "new_date").replace('/', "-");
        sqlx::query!("UPDATE inventory SET new_date = ?2 WHERE id = ?1", id, d).execute(&mut *tx).await?;
    }
    if has(line, "leadtime_option") {
        let (option, text) = (r.get("leadtime_option"), r.get("leadtime_text"));
        sqlx::query!("UPDATE inventory SET leadtime_option = ?2, leadtime_text = ?3 WHERE id = ?1", id, option, text).execute(&mut *tx).await?;
    }
    if r.given("competitor_sku") {
        let list = r.get("competitor_sku");
        sqlx::query!("UPDATE inventory SET competitor_sku = ?2 WHERE id = ?1", id, list).execute(&mut *tx).await?;
    }
    if has(line, "active") {
        let active = i64::from(map::truthy(val(line, "active")));
        sqlx::query!("UPDATE inventory SET active = ?2 WHERE id = ?1", id, active).execute(&mut *tx).await?;
    }
    if has(line, "parameter0") {
        let p: Vec<&str> = (0..PARAMETERS).map(|i| val(line, &format!("parameter{i}"))).collect();
        let n: Vec<f64> = (0..PARAMETERS).map(|i| map::floatval(val(line, &format!("nparameter{i}")))).collect();
        let (p, n) = (serde_json::to_string(&p).unwrap_or_default(), serde_json::to_string(&n).unwrap_or_default());
        sqlx::query!(
            "UPDATE inventory SET parameter0 = ?2->>0, parameter1 = ?2->>1, parameter2 = ?2->>2, parameter3 = ?2->>3, parameter4 = ?2->>4,
                 parameter5 = ?2->>5, parameter6 = ?2->>6, parameter7 = ?2->>7, parameter8 = ?2->>8, parameter9 = ?2->>9, parameter10 = ?2->>10,
                 parameter11 = ?2->>11, parameter12 = ?2->>12, parameter13 = ?2->>13, parameter14 = ?2->>14, parameter15 = ?2->>15,
                 parameter16 = ?2->>16, parameter17 = ?2->>17, parameter18 = ?2->>18, parameter19 = ?2->>19,
                 nparameter0 = ?3->>0, nparameter1 = ?3->>1, nparameter2 = ?3->>2, nparameter3 = ?3->>3, nparameter4 = ?3->>4,
                 nparameter5 = ?3->>5, nparameter6 = ?3->>6, nparameter7 = ?3->>7, nparameter8 = ?3->>8, nparameter9 = ?3->>9,
                 nparameter10 = ?3->>10, nparameter11 = ?3->>11, nparameter12 = ?3->>12, nparameter13 = ?3->>13, nparameter14 = ?3->>14,
                 nparameter15 = ?3->>15, nparameter16 = ?3->>16, nparameter17 = ?3->>17, nparameter18 = ?3->>18, nparameter19 = ?3->>19
             WHERE id = ?1",
            id,
            p,
            n
        )
        .execute(&mut *tx)
        .await?;
    }
    if let Some(breaks) = breaks {
        let text = pricing_text(&breaks);
        sqlx::query!("UPDATE inventory SET pricing = ?2 WHERE id = ?1", id, text).execute(&mut *tx).await?;
    }
    Ok(errors)
}

/// import_update_erp(): the ERP's breaks replace the part's prices.
async fn erp_line(tx: &mut sqlx::SqliteConnection, line: &Line, update: bool) -> sqlx::Result<Errors> {
    let sku = val(line, "sku");
    let Some(id) = sqlx::query_scalar!(r#"SELECT id AS "id!: i64" FROM inventory WHERE sku = ?1"#, sku).fetch_optional(&mut *tx).await? else {
        return Ok(vec![("sku".into(), format!("Invalid SKU: {sku}"))]);
    };
    if update {
        let text = pricing_text(&erp_breaks(line));
        sqlx::query!("UPDATE inventory SET pricing = ?2 WHERE id = ?1", id, text).execute(&mut *tx).await?;
    }
    Ok(Vec::new())
}

/// A break applies from one above the quantity before it; a break that does not lower the price is skipped. A part
/// with no quantity break keeps its list price from quantity 0.
fn erp_breaks(line: &Line) -> Vec<Break> {
    let mut steps: Vec<(f64, f64)> = Vec::new();
    let (mut last_price, mut last_break) = (999_999.0, 0.0);
    for i in 1..=BREAKS {
        let qty = map::floatval(val(line, &format!("_break{i}")));
        let price = map::floatval(val(line, &format!("_price{i}")));
        if qty == 0.0 || price == 0.0 {
            break;
        }
        if qty > last_break && price < last_price {
            match steps.iter_mut().find(|(q, _)| *q == last_break) {
                Some(s) => s.1 = price,
                None => steps.push((last_break, price)),
            }
            last_break = qty;
            last_price = price;
        }
        if qty == 999_999.0 {
            break;
        }
    }
    let list = map::floatval(val(line, "_price0"));
    if steps.is_empty() && list > 0.0 {
        steps.push((0.0, list));
    }
    steps.into_iter().map(|(q, p)| Break { qty: decimal(if q == 0.0 { 0.0 } else { q + 1.0 }), price: decimal(p) }).collect()
}

async fn subcategory_line(tx: &mut sqlx::SqliteConnection, line: &Line, update: bool, ids: &[i64], lookups: &Lookups) -> sqlx::Result<Errors> {
    let id = php_intval(val(line, "id"));
    if !ids.contains(&id) {
        return Ok(vec![("id".into(), format!("Not found: {}", val(line, "id")))]);
    }
    let mut r = Row::default();
    for col in ["leadtime_option", "leadtime_text"] {
        r.give(col, Some(val(line, col).to_string()));
    }
    for col in ["prj", "sort_order"] {
        if has(line, col) {
            r.give(col, Some(val(line, col).to_string()));
        }
    }
    let mut errors = owned(rules::family(&mut r, lookups));
    if update && errors.is_empty() {
        let (option, text) = (r.get("leadtime_option"), r.get("leadtime_text"));
        sqlx::query!("UPDATE subcategory SET leadtime_option = ?2, leadtime_text = ?3 WHERE id = ?1", id, option, text).execute(&mut *tx).await?;
        if r.given("prj") {
            let p = r.value("prj");
            if sqlx::query!("UPDATE subcategory SET prj = ?2 WHERE id = ?1", id, p).execute(&mut *tx).await.is_err() {
                errors.push(("prj".into(), "Duplicate PRJ".into()));
            }
        }
        if r.given("sort_order") {
            let n = r.int("sort_order");
            sqlx::query!("UPDATE subcategory SET sort_order = ?2 WHERE id = ?1", id, n).execute(&mut *tx).await?;
        }
    }
    Ok(errors)
}

async fn xref_line(tx: &mut sqlx::SqliteConnection, line: &Line, update: bool, stamp: i64) -> sqlx::Result<Errors> {
    let (sku, other) = (val(line, "sku"), val(line, "competitor_sku"));
    let mut errors: Errors = Vec::new();
    if sku.is_empty() {
        errors.push(("sku".into(), "Cannot be blank".into()));
    }
    if other.is_empty() {
        errors.push(("competitor_sku".into(), "Cannot be blank".into()));
    } else if sku == other {
        errors.push(("competitor_sku".into(), "Cannot be same as Item #".into()));
    }
    if update && errors.is_empty() {
        sqlx::query!("INSERT OR REPLACE INTO inventory_xref (sku, competitor, competitor_sku, update_timestamp) VALUES (?1, '', ?2, ?3)", sku, other, stamp)
            .execute(&mut *tx)
            .await?;
    } else if update && stamp != 0 {
        sqlx::query!(
            "UPDATE inventory_xref SET update_timestamp = ?3 WHERE (?1 <> '' AND sku = ?1) OR (?1 = '' AND ?2 <> '' AND competitor_sku = ?2)",
            sku,
            other,
            stamp
        )
        .execute(&mut *tx)
        .await?;
    }
    Ok(errors)
}

/// An XR file: SKU, price, then one column per competitor named in the header row ("-" for none), as the rows of an
/// Item #/Xref file.
pub fn xr_rows(sheet: Sheet) -> Sheet {
    let mut rows = sheet.rows.into_iter();
    let names: Vec<String> = rows.next().unwrap_or_default().iter().map(|n| n.trim().to_string()).collect();
    let mut out = vec![vec!["Item #".to_string(), "Xref".to_string()]];
    for cells in rows {
        let sku = cells.first().map_or("", |s| s.trim());
        if sku.is_empty() {
            continue;
        }
        for (i, value) in cells.iter().enumerate().skip(2) {
            let value = value.trim();
            if !value.is_empty() && value != "-" && names.get(i).is_some_and(|n| !n.is_empty()) {
                out.push(vec![sku.to_string(), value.to_string()]);
            }
        }
    }
    Sheet { file_type: sheet.file_type, rows: out, worksheet: sheet.worksheet }
}

/// The columns of an account the import does not name, as a new account has them.
const NEW_ACCOUNT: [(&str, &str); 5] = [("status", "Pending"), ("type", "Customer"), ("leadscore", "1"), ("language_code", "EN"), ("currency_code", "USD")];

/// user->import_update(): the stored account (or a new one) with the line over it, held to `rules::account`, then
/// written whole.
async fn user_line(tx: &mut sqlx::SqliteConnection, line: &Line, update: bool, l: &Lookups) -> sqlx::Result<Errors> {
    let id = php_intval(val(line, "id"));
    let found = sqlx::query!(
        r#"SELECT company_name, address, city, state, zip, country_code, status, "type" AS kind, customer_code, catalog, cad, leadscore, ecommerce,
                  price_type, price_pct, comment, login_document_id, landing_document_id, portal_id, language_code, currency_code, industry_id,
                  revenue, employees
           FROM "user" WHERE id = ?1"#,
        id
    )
    .fetch_optional(&mut *tx)
    .await?;
    let mut e: Errors = Vec::new();
    if id != 0 && found.is_none() {
        e.push(("id".into(), format!("Not found: {}", val(line, "id"))));
    }
    let mut r = Row::default();
    match found {
        Some(s) => {
            let stored = [
                ("company_name", s.company_name),
                ("address", s.address),
                ("city", s.city),
                ("state", s.state),
                ("zip", s.zip),
                ("country_code", s.country_code),
                ("status", Some(s.status)),
                ("type", Some(s.kind)),
                ("customer_code", s.customer_code),
                ("catalog", Some(s.catalog.to_string())),
                ("cad", Some(s.cad.to_string())),
                ("leadscore", Some(s.leadscore.to_string())),
                ("ecommerce", Some(s.ecommerce.to_string())),
                ("price_type", Some(s.price_type)),
                ("price_pct", Some(s.price_pct.to_string())),
                ("comment", s.comment),
                ("login_document_id", Some(s.login_document_id.to_string())),
                ("landing_document_id", Some(s.landing_document_id.to_string())),
                ("portal_id", Some(s.portal_id.to_string())),
                ("language_code", Some(s.language_code)),
                ("currency_code", Some(s.currency_code)),
                ("industry_id", Some(s.industry_id.to_string())),
                ("revenue", Some(s.revenue)),
                ("employees", Some(s.employees)),
            ];
            for (col, value) in stored {
                r.keep(col, value);
            }
        }
        None => {
            for (col, value) in NEW_ACCOUNT {
                r.keep(col, Some(value.to_string()));
            }
        }
    }
    for (key, value) in line {
        r.give(key, Some(value.clone()));
    }
    e.extend(owned(rules::account(&mut r, l)));
    if update && e.is_empty() {
        save_account(tx, id, &r).await?;
    }
    Ok(e)
}

/// Writes the account whole, adding it when `id` is 0. An empty `ip` is an account with no company address:
/// self-registered accounts have none until staff set one.
async fn save_account(tx: &mut sqlx::SqliteConnection, id: i64, r: &Row) -> sqlx::Result<()> {
    let ip = r.value("ip").filter(|ip| !ip.is_empty());
    let id = if id == 0 {
        let now = unix_now();
        sqlx::query_scalar!(r#"INSERT INTO "user" (ip, last_login) VALUES (?1, ?2) RETURNING id AS "id!: i64""#, ip, now).fetch_one(&mut *tx).await?
    } else {
        id
    };
    let [company_name, address, city, state, zip, country_code, status, kind, customer_code, price_type, comment, language_code, currency_code, revenue, employees] = [
        "company_name", "address", "city", "state", "zip", "country_code", "status", "type", "customer_code", "price_type", "comment", "language_code",
        "currency_code", "revenue", "employees",
    ]
    .map(|col| r.get(col));
    let flag = |col: &str| i64::from(r.flag(col));
    let (catalog, cad, leadscore, ecommerce) = (flag("catalog"), flag("cad"), flag("leadscore"), flag("ecommerce"));
    let (price_pct, login_document_id, landing_document_id, portal_id, industry_id) =
        (r.real("price_pct"), r.int("login_document_id"), r.int("landing_document_id"), r.int("portal_id"), r.int("industry_id"));
    sqlx::query!(
        r#"UPDATE "user" SET ip = ?2, company_name = ?3, address = ?4, city = ?5, state = ?6, zip = ?7, country_code = ?8, status = ?9, "type" = ?10,
                  customer_code = ?11, catalog = ?12, cad = ?13, leadscore = ?14, ecommerce = ?15, price_type = ?16, price_pct = ?17,
                  comment = ?18, login_document_id = ?19, landing_document_id = ?20, portal_id = ?21, language_code = ?22,
                  currency_code = ?23, industry_id = ?24, revenue = ?25, employees = ?26
           WHERE id = ?1"#,
        id,
        ip,
        company_name,
        address,
        city,
        state,
        zip,
        country_code,
        status,
        kind,
        customer_code,
        catalog,
        cad,
        leadscore,
        ecommerce,
        price_type,
        price_pct,
        comment,
        login_document_id,
        landing_document_id,
        portal_id,
        language_code,
        currency_code,
        industry_id,
        revenue,
        employees
    )
    .execute(&mut *tx)
    .await?;
    Ok(())
}

/// A check's errors as the import reports them.
fn owned(errors: rules::Errors) -> Errors {
    errors.into_iter().map(|(k, m)| (k.to_string(), m)).collect()
}

/// import_update() for one line of a pricing file: the subcategory and record type must be the ones asked for, and
/// the record type's option must be there (no record type has two). An update replaces the row with the same
/// options.
async fn pricing_line(tx: &mut sqlx::SqliteConnection, line: &Line, update: bool, module: &'static Module, subcategory_id: &str, only: Option<&'static RecordType>) -> sqlx::Result<Errors> {
    let r = module.record_type(val(line, "record_type"));
    if val(line, "subcategory_id") != subcategory_id {
        return Ok(vec![("subcategory_id".into(), val(line, "subcategory_id").into())]);
    }
    let Some(r) = r.filter(|r| only.is_none_or(|o| o.code == r.code)) else {
        return Ok(vec![("record_type".into(), val(line, "record_type").into())]);
    };
    let code = val(line, "code0");
    if !r.fields.is_empty() && code.is_empty() {
        return Ok(vec![("code0".into(), "Invalid: ".into())]);
    }
    if let Some(i) = (0..r.prices.len()).find(|i| val(line, &format!("price{i}")).is_empty()) {
        return Ok(vec![(format!("price{i}"), "Cannot be blank".into())]);
    }
    if !update {
        return Ok(Vec::new());
    }
    let options: String = form_urlencoded::Serializer::new(String::new()).extend_pairs(r.fields.iter().map(|f| (*f, code))).finish();
    let price = |i: usize| if i < r.prices.len() { map::floatval(val(line, &format!("price{i}"))) } else { 0.0 };
    let (p0, p1, p2, p3, p4) = (price(0), price(1), price(2), price(3), price(4));
    let stamp = unix_now();
    sqlx::query!(
        "INSERT OR REPLACE INTO pricing (subcategory_id, record_type, options, price_type, price0, price1, price2, price3, price4, update_timestamp)
         VALUES (?1, ?2, ?3, '', ?4, ?5, ?6, ?7, ?8, ?9)",
        subcategory_id,
        r.code,
        options,
        p0,
        p1,
        p2,
        p3,
        p4,
        stamp
    )
    .execute(&mut *tx)
    .await?;
    Ok(Vec::new())
}

// ---------------------------------------------------------------------------------------------------------------
// PARTsolutions links
// ---------------------------------------------------------------------------------------------------------------

/// A PARTsolutions export: `;`-separated, a header line, the SKU (before any `_`) in the fourth column and the part's
/// URL in the fifth. Each SKU keeps the query of its first URL that has one.
pub fn prj_export_rows(file: &str) -> Vec<(String, String)> {
    let mut out: Vec<(String, String)> = Vec::new();
    for line in file.split('\n').skip(1) {
        let cells = split_csv(line, ';');
        let sku = cells.get(3).map_or("", |c| c.trim()).split('_').next().unwrap_or("").to_string();
        let url = cells.get(4).map_or("", |c| c.trim());
        let query = url.split_once('?').map_or("", |(_, q)| q.split('#').next().unwrap_or(""));
        if !sku.is_empty() && !query.is_empty() && !out.iter().any(|(s, _)| *s == sku) {
            out.push((sku, query.to_string()));
        }
    }
    out
}

/// str_getcsv(): fields split on `delimiter`, double quotes around a field and doubled inside it.
fn split_csv(line: &str, delimiter: char) -> Vec<String> {
    let mut out = vec![String::new()];
    let mut quoted = false;
    let mut chars = line.trim_end_matches('\r').chars().peekable();
    while let Some(c) = chars.next() {
        match c {
            '"' if quoted && chars.peek() == Some(&'"') => {
                chars.next();
                if let Some(f) = out.last_mut() {
                    f.push('"');
                }
            }
            '"' => quoted = !quoted,
            c if c == delimiter && !quoted => out.push(String::new()),
            c => {
                if let Some(f) = out.last_mut() {
                    f.push(c);
                }
            }
        }
    }
    out
}

/// The link stored for a part: the four PARTsolutions keys of the query, decoded, in the query's order. `None`
/// unless all four have a value.
fn prj_link(query: &str) -> Option<String> {
    let mut kept: Vec<(String, String)> = Vec::new();
    for (k, v) in form_urlencoded::parse(query.as_bytes()) {
        match kept.iter_mut().find(|(key, _)| *key == k) {
            Some(slot) => slot.1 = v.into_owned(),
            None => kept.push((k.into_owned(), v.into_owned())),
        }
    }
    let parts: Vec<String> = kept.iter().filter(|(k, v)| ["prjCatalog", "prjNode", "prjName", "values"].contains(&k.as_str()) && !v.is_empty()).map(|(k, v)| format!("{k}={v}")).collect();
    (parts.len() == 4).then(|| parts.join("&"))
}

/// What a PARTsolutions import changed, left as it was, and could not find.
#[derive(Debug, Default)]
pub struct PrjOutcome {
    pub changed: Vec<String>,
    pub not_changed: Vec<String>,
    pub missing: Vec<String>,
}

/// inventory_prj.php: each SKU's link from the export, saved when `update`.
pub async fn prj_import(pool: &SqlitePool, rows: &[(String, String)], update: bool) -> sqlx::Result<PrjOutcome> {
    let mut out = PrjOutcome::default();
    let mut tx = pool.begin().await?;
    for (sku, query) in rows {
        let Some(prj) = prj_link(query) else { continue };
        let stored = sqlx::query_scalar!(r#"SELECT COALESCE(prj, '') AS "prj!: String" FROM inventory WHERE sku = ?1"#, sku).fetch_all(&mut *tx).await?;
        if stored.is_empty() {
            if !out.missing.contains(sku) {
                out.missing.push(sku.clone());
            }
        } else if stored.iter().all(|s| *s == prj) {
            out.not_changed.push(sku.clone());
        } else {
            sqlx::query!("UPDATE inventory SET prj = ?1 WHERE sku = ?2", prj, sku).execute(&mut *tx).await?;
            out.changed.push(sku.clone());
        }
    }
    if update {
        tx.commit().await?;
    } else {
        tx.rollback().await?;
    }
    out.not_changed.sort();
    out.not_changed.dedup();
    Ok(out)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn erp_breaks_start_one_above_the_quantity_before() {
        let line: Line = [("_break1", "10"), ("_price1", "5"), ("_break2", "50"), ("_price2", "4"), ("_break3", "999999"), ("_price3", "3")]
            .iter()
            .map(|(k, v)| (k.to_string(), v.to_string()))
            .collect();
        let b = erp_breaks(&line);
        assert_eq!(b.iter().map(|b| (b.qty.to_string(), b.price.to_string())).collect::<Vec<_>>(), [("0".into(), "5".into()), ("11".into(), "4".into()), ("51".into(), "3".into())]);
    }

    #[test]
    fn erp_lines_with_no_break_keep_the_list_price() {
        let line = |cells: &[(&str, &str)]| -> Line { cells.iter().map(|(k, v)| (k.to_string(), v.to_string())).collect() };
        let b = erp_breaks(&line(&[("_price0", "12.3400"), ("_break1", "0"), ("_price1", "0")]));
        assert_eq!(b, [Break { qty: Decimal::ZERO, price: Decimal::new(1234, 2) }]);
        assert!(erp_breaks(&line(&[("_price0", "0"), ("_break1", "0"), ("_price1", "0")])).is_empty());
        let b = erp_breaks(&line(&[("_price0", "9"), ("_break1", "10"), ("_price1", "8")]));
        assert_eq!(b, [Break { qty: Decimal::ZERO, price: Decimal::new(8, 0) }], "the breaks, when there are any");
    }

    #[test]
    fn breaks_round_trip_through_the_stored_json() {
        let breaks = vec![Break { qty: Decimal::new(5, 0), price: Decimal::new(1250, 2) }, Break { qty: Decimal::new(10, 0), price: Decimal::new(1100, 2) }];
        let text = pricing_text(&breaks);
        assert_eq!(text, r#"[{"price":12.5,"qty_break":0},{"price":11.0,"qty_break":10.0}]"#);
        assert_eq!(pricing(&text), [Break { qty: Decimal::ZERO, ..breaks[0].clone() }, breaks[1].clone()]);
        assert_eq!(pricing(r#"[{"price":284.92,"qty_break":0}]"#)[0].price.to_string(), "284.92");
        assert!(pricing("").is_empty());
    }

    #[test]
    fn exports_give_each_sku_its_first_query() {
        let file = "Name;x;y;Part;URL\r\na;b;c;\"BLP-3_1\";https://example.com/p?prjCatalog=harcourt&prjNode=n&prjName=BLP-3&values={A=1}#top\n;;;BLP-3_2;https://example.com/p?other=1\n;;;NOURL;https://example.com/p\n";
        assert_eq!(prj_export_rows(file), [("BLP-3".to_string(), "prjCatalog=harcourt&prjNode=n&prjName=BLP-3&values={A=1}".to_string())]);
        assert_eq!(split_csv("a;\"b;c\";\"d\"\"e\"", ';'), ["a", "b;c", "d\"e"]);
    }

    #[test]
    fn links_need_all_four_keys() {
        assert_eq!(prj_link("prjCatalog=h&x=1&prjNode=n&prjName=a%20b&values=%7BA%3D1%7D").as_deref(), Some("prjCatalog=h&prjNode=n&prjName=a b&values={A=1}"));
        assert_eq!(prj_link("prjCatalog=h&prjNode=n&prjName=&values=v"), None);
    }

    #[test]
    fn xr_files_become_one_line_per_competitor_number() {
        let rows = [["SKU", "Price", "Rival A", "", "Rival B"], ["AB-1", "2", "R-100", "X-9", "-"], ["", "1", "R-200", "", ""]];
        let sheet = Sheet { file_type: FileType::Txt, rows: rows.iter().map(|r| r.map(String::from).to_vec()).collect(), worksheet: None };
        assert_eq!(xr_rows(sheet).rows, [["Item #", "Xref"], ["AB-1", "R-100"]]);
    }

    #[test]
    fn tables_name_their_direction() {
        for t in Table::ALL {
            assert_eq!(Table::parse(t.name()), Some(t));
            assert!(t.imports() || t.exports());
        }
        assert!(!Table::Erp.exports() && !Table::Email.imports());
    }
}
