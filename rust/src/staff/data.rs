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
    smart::{self, Module, RecordType},
    time,
};
use crate::{catalog::stored, db::unix_now, identity::Role};

const PARAMETERS: usize = 20;
const BREAKS: usize = 10;
const PRICES: usize = 5;
const INVENTORY_LEADTIME: [&str; 4] = ["Subcategory", "In Stock", "Weeks", "Call"];
const SUBCATEGORY_LEADTIME: [&str; 3] = ["In Stock", "Weeks", "Call"];
const ROLES: [Role; 6] = [Role::Customer, Role::External, Role::Internal, Role::Executive, Role::Administrator, Role::SuperUser];
pub const STATUSES: [&str; 3] = ["Pending", "Active", "Denied"];

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
    /// Xref: drop the stored lines the file does not hold.
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
    m.add(Field::new("status", Kind::Alphanumeric).name("Status"));
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
    let roles = serde_json::to_string(&all(&o.roles, ROLES.iter().map(|r| r.as_str().to_string()).collect())).unwrap_or_default();
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

async fn email_export(pool: &SqlitePool, map: &Map, o: &Options) -> sqlx::Result<Vec<Record>> {
    let mut rows = sqlx::query!(
        r#"SELECT e.email AS "email!: String", e.first_day AS "first_day!: String", e.last_day AS "last_day!: String",
                  u.company_name, u.address, u.city, u.state, u.zip, u.country_code
           FROM (SELECT email, min(user_id) AS user_id,
                        strftime('%Y/%m/%d', min(date_time), 'unixepoch', 'localtime') AS first_day,
                        strftime('%Y/%m/%d', max(date_time), 'unixepoch', 'localtime') AS last_day
                 FROM log WHERE email <> '' AND user_id <> 0 GROUP BY email) e
           JOIN "user" u ON u.id = e.user_id
           WHERE COALESCE(u.company_name, '') <> ''
           ORDER BY e.email"#
    )
    .fetch_all(pool)
    .await?;
    rows.retain(|r| {
        let day = match o.source {
            EmailSource::All => return true,
            EmailSource::First => &r.first_day,
            EmailSource::Last => &r.last_day,
        };
        (o.from.is_empty() || day.as_str() >= o.from.as_str()) && (o.thru.is_empty() || day.as_str() <= o.thru.as_str())
    });
    filesort::sort(&mut rows, |a, b| filesort::ci(&a.email, &b.email));
    Ok(rows
        .into_iter()
        .map(|r| {
            aligned(
                map,
                HashMap::from([
                    ("email", r.email),
                    ("company_name", opt(r.company_name)),
                    ("address", opt(r.address)),
                    ("city", opt(r.city)),
                    ("state", opt(r.state)),
                    ("zip", opt(r.zip)),
                    ("country_code", opt(r.country_code)),
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

/// import_match(): the list's spelling of a value, ignoring case; "" when it is not in the list.
fn import_match<'a>(value: &str, list: &[&'a str]) -> &'a str {
    list.iter().find(|l| l.eq_ignore_ascii_case(value)).copied().unwrap_or("")
}

/// prj_verify(): empty, or a query string with `info`.
fn prj_ok(prj: &str) -> bool {
    prj.is_empty() || form_urlencoded::parse(prj.as_bytes()).any(|(k, _)| k == "info")
}

/// The table an import writes, with what it looked up once for every line.
enum Target {
    Inventory { subcategory_id: i64, subcategory: Option<(i64, i64)>, names: HashMap<String, (i64, i64)> },
    Erp,
    Subcategory { ids: Vec<i64> },
    Xref { stamp: i64 },
    User(Box<UserLookups>),
    Pricing { module: &'static Module, subcategory_id: String, only: Option<&'static RecordType> },
}

struct UserLookups {
    currencies: Vec<String>,
    languages: Vec<String>,
    portals: Vec<i64>,
    documents: Vec<i64>,
    industries: Vec<(i64, String)>,
}

impl Target {
    async fn load(pool: &SqlitePool, table: Table, o: &Options) -> Result<Target, String> {
        let fail = |e: sqlx::Error| e.to_string();
        Ok(match table {
            Table::Inventory => inventory_target(pool, o.subcategory_id).await.map_err(fail)?,
            Table::Erp => Target::Erp,
            Table::Subcategory => {
                let ids = subcategory_export(pool, &subcategory_map(), o.category_id).await.map_err(fail)?;
                Target::Subcategory { ids: ids.iter().map(|r| php_intval(&r[0])).collect() }
            }
            Table::Xref => Target::Xref { stamp: if o.replace { unix_now() } else { 0 } },
            Table::User => Target::User(Box::new(user_lookups(pool).await.map_err(fail)?)),
            Table::Pricing => {
                let module = smart_module(o)?;
                Target::Pricing { module, subcategory_id: o.subcategory_id.to_string(), only: record_type(module, &o.record_type)? }
            }
            Table::Email | Table::PrjMissing => return Err(format!("{} is exported only", table.name())),
        })
    }

    fn map(&self) -> Map {
        match self {
            Target::Inventory { .. } => inventory_map(),
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
            Target::Inventory { subcategory_id, subcategory, names } => inventory_line(tx, line, update, *subcategory_id, *subcategory, names, complete).await,
            Target::Erp => erp_line(tx, line, update).await,
            Target::Subcategory { ids } => subcategory_line(tx, line, update, ids).await,
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
        let line: Line = columns.iter().map(|f| (f.key.clone(), map::import_value(f, cells.get(header.column[&f.key]).copied().unwrap_or(""), sheet.file_type))).collect();
        let errors = target.line(&mut tx, &line, update, header.complete).await.map_err(fail)?;
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
        names.entry(format!("{}:{}", opt(r.category), opt(r.name)).to_lowercase()).or_insert(ids);
    }
    Ok(Target::Inventory { subcategory_id, subcategory, names })
}

/// competitor_sku(…, TRUE): the comma list as sorted, unique part numbers, and what is wrong with it.
fn competitor_list(input: &str) -> (Vec<String>, Option<&'static str>) {
    let mut out: Vec<String> = Vec::new();
    let mut short = false;
    for item in input.split(',') {
        let item = item.trim().to_uppercase();
        if item.is_empty() {
            continue;
        }
        short |= item.len() < 3;
        if !out.contains(&item) {
            out.push(item);
        }
    }
    out.sort();
    (out, short.then_some("Must be at least 3 characters"))
}

/// A lead time and what is wrong with it: Weeks needs a text, the other options none.
fn leadtime_errors(option: &str, text: &str, allowed: &[&str], errors: &mut Errors) {
    if option.is_empty() {
        errors.push(("leadtime_option".into(), format!("Allowed values: {}", allowed.join(", "))));
    } else if option == "Weeks" && text.is_empty() {
        errors.push(("leadtime_text".into(), "Cannot be blank".into()));
    } else if option != "Weeks" && !text.is_empty() {
        errors.push(("leadtime_text".into(), "Must be blank".into()));
    }
}

fn decimal(v: f64) -> Decimal {
    rust_decimal::prelude::FromPrimitive::from_f64(v).unwrap_or_default()
}

async fn inventory_line(
    tx: &mut sqlx::SqliteConnection,
    line: &Line,
    update: bool,
    subcategory_id: i64,
    subcategory: Option<(i64, i64)>,
    names: &HashMap<String, (i64, i64)>,
    complete: bool,
) -> sqlx::Result<Errors> {
    let sku = val(line, "sku");
    let found = sqlx::query!(r#"SELECT id AS "id!: i64", category_id, subcategory_id FROM inventory WHERE sku = ?1"#, sku).fetch_optional(&mut *tx).await?;
    let mut errors: Errors = Vec::new();
    if sku.is_empty() || (found.is_none() && !complete) {
        errors.push(("sku".into(), format!("Invalid SKU: {sku}")));
    }
    let (mut category_id, mut sub_id) = found.as_ref().map_or((0, 0), |f| (f.category_id.unwrap_or(0), f.subcategory_id.unwrap_or(0)));
    if has(line, "category_name") {
        let (category, name) = (val(line, "category_name"), val(line, "subcategory_name"));
        if !category.is_empty() {
            (category_id, sub_id) = names.get(&format!("{category}:{name}").to_lowercase()).copied().unwrap_or((0, 0));
        } else if subcategory_id != 0 {
            (category_id, sub_id) = subcategory.unwrap_or((0, 0));
        }
        if sub_id == 0 {
            errors.push(("subcategory_name".into(), format!("Invalid subcategory: {category}:{name}")));
        }
    }
    let competitor = has(line, "temp_competitor_sku").then(|| competitor_list(val(line, "temp_competitor_sku")));
    if let Some((_, Some(e))) = &competitor {
        errors.push(("temp_competitor_sku".into(), e.to_string()));
    }
    let leadtime = has(line, "leadtime_option").then(|| (import_match(val(line, "leadtime_option"), &INVENTORY_LEADTIME), val(line, "leadtime_text")));
    if let Some((option, text)) = leadtime {
        leadtime_errors(option, text, &INVENTORY_LEADTIME, &mut errors);
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
    if has(line, "sort_order") {
        let n = php_intval(val(line, "sort_order"));
        sqlx::query!("UPDATE inventory SET sort_order = ?2 WHERE id = ?1", id, n).execute(&mut *tx).await?;
    }
    if has(line, "new_date") {
        let d = val(line, "new_date").replace('/', "-");
        sqlx::query!("UPDATE inventory SET new_date = ?2 WHERE id = ?1", id, d).execute(&mut *tx).await?;
    }
    if let Some((option, text)) = leadtime {
        sqlx::query!("UPDATE inventory SET leadtime_option = ?2, leadtime_text = ?3 WHERE id = ?1", id, option, text).execute(&mut *tx).await?;
    }
    if let Some((list, _)) = &competitor {
        let joined = list.join(",");
        sqlx::query!("UPDATE inventory SET competitor_sku = ?2 WHERE id = ?1", id, joined).execute(&mut *tx).await?;
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

/// A break applies from one above the quantity before it; a break that does not lower the price is skipped.
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
    steps.into_iter().map(|(q, p)| Break { qty: decimal(if q == 0.0 { 0.0 } else { q + 1.0 }), price: decimal(p) }).collect()
}

async fn subcategory_line(tx: &mut sqlx::SqliteConnection, line: &Line, update: bool, ids: &[i64]) -> sqlx::Result<Errors> {
    let id = php_intval(val(line, "id"));
    if !ids.contains(&id) {
        return Ok(vec![("id".into(), format!("Not found: {}", val(line, "id")))]);
    }
    let mut errors: Errors = Vec::new();
    let option = import_match(val(line, "leadtime_option"), &SUBCATEGORY_LEADTIME);
    let text = val(line, "leadtime_text");
    let prj = has(line, "prj").then(|| val(line, "prj"));
    if let Some(p) = prj.filter(|p| !prj_ok(p)) {
        errors.push(("prj".into(), format!("Malformed PRJ {p}")));
    }
    leadtime_errors(option, text, &SUBCATEGORY_LEADTIME, &mut errors);
    if update && errors.is_empty() {
        sqlx::query!("UPDATE subcategory SET leadtime_option = ?2, leadtime_text = ?3 WHERE id = ?1", id, option, text).execute(&mut *tx).await?;
        if let Some(p) = prj {
            let p = (!p.is_empty()).then_some(p);
            if sqlx::query!("UPDATE subcategory SET prj = ?2 WHERE id = ?1", id, p).execute(&mut *tx).await.is_err() {
                errors.push(("prj".into(), "Duplicate PRJ".into()));
            }
        }
        if has(line, "sort_order") {
            let n = php_intval(val(line, "sort_order"));
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

async fn user_lookups(pool: &SqlitePool) -> sqlx::Result<UserLookups> {
    Ok(UserLookups {
        currencies: sqlx::query_scalar!(r#"SELECT code AS "code!: String" FROM currency"#).fetch_all(pool).await?,
        languages: sqlx::query_scalar!(r#"SELECT code AS "code!: String" FROM language"#).fetch_all(pool).await?,
        portals: std::iter::once(0).chain(sqlx::query_scalar!(r#"SELECT id AS "id!: i64" FROM portal"#).fetch_all(pool).await?).collect(),
        documents: std::iter::once(0)
            .chain(sqlx::query_scalar!(r#"SELECT id AS "id!: i64" FROM document WHERE parent_id <> 0 AND active = 1"#).fetch_all(pool).await?)
            .collect(),
        industries: sqlx::query!(r#"SELECT id AS "id!: i64", name FROM industry"#).fetch_all(pool).await?.into_iter().map(|r| (r.id, r.name)).collect(),
    })
}

/// user->import_update(): an account line checked against the lookups, then written whole.
async fn user_line(tx: &mut sqlx::SqliteConnection, line: &Line, update: bool, l: &UserLookups) -> sqlx::Result<Errors> {
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
    let mut u = found.map_or_else(UserRow::new, |r| UserRow {
        company_name: opt(r.company_name),
        address: opt(r.address),
        city: opt(r.city),
        state: opt(r.state),
        zip: opt(r.zip),
        country_code: opt(r.country_code),
        status: r.status,
        kind: r.kind,
        customer_code: opt(r.customer_code),
        catalog: r.catalog != 0,
        cad: r.cad != 0,
        leadscore: r.leadscore != 0,
        ecommerce: r.ecommerce != 0,
        price_type: r.price_type,
        price_pct: r.price_pct,
        comment: opt(r.comment),
        login_document_id: r.login_document_id,
        landing_document_id: r.landing_document_id,
        portal_id: r.portal_id,
        language_code: r.language_code,
        currency_code: r.currency_code,
        industry_id: r.industry_id,
        revenue: r.revenue,
        employees: r.employees,
    });
    let ip = val(line, "ip");
    if !ip.parse::<std::net::Ipv4Addr>().is_ok_and(|a| !a.is_unspecified() && a.to_string() == ip) {
        e.push(("ip".into(), format!("Invalid: {ip}")));
    }
    let text = |k: &str| val(line, k).to_string();
    let invalid = |k: &str| (k.to_string(), format!("Invalid: {}", val(line, k)));
    if has(line, "company_name") {
        u.company_name = text("company_name");
        if u.company_name.is_empty() {
            e.push(("company_name".into(), "Required".into()));
        }
    }
    if has(line, "address") {
        u.address = text("address");
    }
    if has(line, "city") {
        u.city = text("city");
    }
    if has(line, "country_code") {
        let given = if val(line, "country_code").is_empty() { "US".to_string() } else { text("country_code") };
        u.country_code = find_country(tx, &given).await?;
        if u.country_code.is_empty() {
            e.push(("country_code".into(), format!("Invalid: {given}")));
        }
    }
    if has(line, "state") {
        u.state = find_state(tx, &u.country_code, val(line, "state")).await?;
        if !val(line, "state").is_empty() && u.country_code.is_empty() {
            e.push(invalid("state"));
        }
    }
    if has(line, "zip") {
        u.zip = text("zip");
    }
    if has(line, "status") {
        u.status = import_match(val(line, "status"), &STATUSES).to_string();
        if u.status.is_empty() {
            e.push(invalid("status"));
        }
    }
    if has(line, "type") {
        let roles: Vec<&str> = ROLES.iter().map(|r| r.as_str()).collect();
        u.kind = import_match(val(line, "type"), &roles).to_string();
        if u.kind.is_empty() {
            e.push(invalid("type"));
        }
    }
    if has(line, "customer_code") {
        u.customer_code = text("customer_code");
    }
    for (key, slot) in [("catalog", &mut u.catalog), ("cad", &mut u.cad), ("ecommerce", &mut u.ecommerce), ("leadscore", &mut u.leadscore)] {
        if has(line, key) {
            *slot = map::truthy(val(line, key));
        }
    }
    if has(line, "price_type") {
        u.price_type = text("price_type");
        if !["", "base", "discount", "plus"].contains(&u.price_type.as_str()) {
            e.push(invalid("price_type"));
        }
    }
    if has(line, "price_pct") {
        u.price_pct = map::floatval(val(line, "price_pct"));
    }
    if has(line, "comment") {
        u.comment = text("comment");
    }
    for (key, slot, allowed) in [
        ("login_document_id", &mut u.login_document_id, &l.documents),
        ("landing_document_id", &mut u.landing_document_id, &l.documents),
        ("portal_id", &mut u.portal_id, &l.portals),
    ] {
        if has(line, key) {
            *slot = php_intval(val(line, key));
            if !allowed.contains(slot) {
                e.push(invalid(key));
            }
        }
    }
    if has(line, "language_code") {
        let langs: Vec<&str> = l.languages.iter().map(String::as_str).collect();
        u.language_code = import_match(val(line, "language_code"), &langs).to_string();
        if u.language_code.is_empty() {
            e.push(invalid("language_code"));
        }
    }
    if has(line, "currency_code") {
        u.currency_code = text("currency_code");
        if !l.currencies.contains(&u.currency_code) {
            e.push(invalid("currency_code"));
        }
    }
    if has(line, "industry_name") {
        let name = val(line, "industry_name");
        u.industry_id = l.industries.iter().find(|(_, n)| n.eq_ignore_ascii_case(name)).map_or(0, |(id, _)| *id);
        if !name.is_empty() && u.industry_id == 0 {
            e.push(invalid("industry_name"));
        }
    }
    if (has(line, "catalog") || has(line, "price_type")) && !u.catalog && !u.price_type.is_empty() {
        e.push(("pricing".into(), "Pricing requires catalog access".into()));
    }
    if (has(line, "catalog") || has(line, "cad")) && !u.catalog && u.cad {
        e.push(("cad".into(), "CAD requires catalog access".into()));
    }
    if (has(line, "portal_id") || has(line, "login_document_id") || has(line, "landing_document_id"))
        && u.portal_id != 0
        && (u.login_document_id != 0 || u.landing_document_id != 0)
    {
        e.push(("portal_id".into(), "Landing documents not allowed when portal is selected".into()));
    }
    let (has_type, has_pct) = (has(line, "price_type"), has(line, "price_pct"));
    let pct_error = match () {
        _ if !has_type && !has_pct => None,
        _ if !has_type => Some(("price_type", "Required field")),
        _ if !has_pct => Some(("price_pct", "Required field")),
        _ if u.price_pct < 0.0 => Some(("price_pct", "Less than 0%")),
        _ if ["", "base"].contains(&u.price_type.as_str()) && u.price_pct != 0.0 => Some(("price_pct", "Must be 0%")),
        _ if u.price_type == "discount" && (u.price_pct == 0.0 || u.price_pct >= 1.0) => Some(("price_pct", "Must be > 0% and < 100%")),
        _ if u.price_type == "plus" && (u.price_pct == 0.0 || u.price_pct >= 5.0) => Some(("price_pct", "Must be > 0% and < 500%")),
        _ => None,
    };
    if let Some((k, m)) = pct_error {
        e.push((k.into(), m.into()));
    }
    if has(line, "revenue") {
        u.revenue = text("revenue");
    }
    if has(line, "employees") {
        u.employees = text("employees");
    }
    if update && e.is_empty() {
        u.save(tx, id, ip).await?;
    }
    Ok(e)
}

struct UserRow {
    company_name: String,
    address: String,
    city: String,
    state: String,
    zip: String,
    country_code: String,
    status: String,
    kind: String,
    customer_code: String,
    catalog: bool,
    cad: bool,
    leadscore: bool,
    ecommerce: bool,
    price_type: String,
    price_pct: f64,
    comment: String,
    login_document_id: i64,
    landing_document_id: i64,
    portal_id: i64,
    language_code: String,
    currency_code: String,
    industry_id: i64,
    revenue: String,
    employees: String,
}

impl UserRow {
    fn new() -> UserRow {
        UserRow {
            company_name: String::new(),
            address: String::new(),
            city: String::new(),
            state: String::new(),
            zip: String::new(),
            country_code: String::new(),
            status: "Pending".into(),
            kind: "Customer".into(),
            customer_code: String::new(),
            catalog: false,
            cad: false,
            leadscore: true,
            ecommerce: false,
            price_type: String::new(),
            price_pct: 0.0,
            comment: String::new(),
            login_document_id: 0,
            landing_document_id: 0,
            portal_id: 0,
            language_code: "EN".into(),
            currency_code: "USD".into(),
            industry_id: 0,
            revenue: String::new(),
            employees: String::new(),
        }
    }

    async fn save(&self, tx: &mut sqlx::SqliteConnection, id: i64, ip: &str) -> sqlx::Result<()> {
        let (catalog, cad, leadscore, ecommerce) = (i64::from(self.catalog), i64::from(self.cad), i64::from(self.leadscore), i64::from(self.ecommerce));
        let id = if id == 0 {
            let now = unix_now();
            sqlx::query_scalar!(r#"INSERT INTO "user" (ip, last_login) VALUES (?1, ?2) RETURNING id AS "id!: i64""#, ip, now).fetch_one(&mut *tx).await?
        } else {
            id
        };
        sqlx::query!(
            r#"UPDATE "user" SET ip = ?2, company_name = ?3, address = ?4, city = ?5, state = ?6, zip = ?7, country_code = ?8, status = ?9, "type" = ?10,
                      customer_code = ?11, catalog = ?12, cad = ?13, leadscore = ?14, ecommerce = ?15, price_type = ?16, price_pct = ?17,
                      comment = ?18, login_document_id = ?19, landing_document_id = ?20, portal_id = ?21, language_code = ?22,
                      currency_code = ?23, industry_id = ?24, revenue = ?25, employees = ?26
               WHERE id = ?1"#,
            id,
            ip,
            self.company_name,
            self.address,
            self.city,
            self.state,
            self.zip,
            self.country_code,
            self.status,
            self.kind,
            self.customer_code,
            catalog,
            cad,
            leadscore,
            ecommerce,
            self.price_type,
            self.price_pct,
            self.comment,
            self.login_document_id,
            self.landing_document_id,
            self.portal_id,
            self.language_code,
            self.currency_code,
            self.industry_id,
            self.revenue,
            self.employees
        )
        .execute(&mut *tx)
        .await?;
        Ok(())
    }
}

/// country->find_country(): the country by code, name, or a word of its aliases.
async fn find_country(tx: &mut sqlx::SqliteConnection, code: &str) -> sqlx::Result<String> {
    if code.is_empty() {
        return Ok(String::new());
    }
    let like = format!("%{code}%");
    let found = sqlx::query_scalar!(
        r#"SELECT country AS "country!: String" FROM country WHERE state = '' AND (country = ?1 OR name = ?1 OR alias LIKE ?2)
           ORDER BY CASE WHEN country = ?1 THEN 1 WHEN name = ?1 THEN 2 ELSE 3 END LIMIT 1"#,
        code,
        like
    )
    .fetch_optional(&mut *tx)
    .await?;
    Ok(found.unwrap_or_default())
}

/// country->find_state(): a US or Canadian state by code or name; elsewhere the text as given.
async fn find_state(tx: &mut sqlx::SqliteConnection, country: &str, code: &str) -> sqlx::Result<String> {
    if code.is_empty() {
        return Ok(String::new());
    }
    if !["US", "CA"].contains(&country) {
        return Ok(code.to_string());
    }
    let found = sqlx::query_scalar!(
        r#"SELECT state AS "state!: String" FROM country WHERE country = ?1 AND state <> '' AND (state = ?2 OR name = ?2)
           ORDER BY CASE WHEN state = ?2 THEN 1 ELSE 2 END LIMIT 1"#,
        country,
        code
    )
    .fetch_optional(&mut *tx)
    .await?;
    Ok(found.unwrap_or_default())
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
    fn competitor_lists_sort_and_flag_short_numbers() {
        assert_eq!(competitor_list("b-200, a-100,,A-100"), (vec!["A-100".to_string(), "B-200".to_string()], None));
        assert_eq!(competitor_list("ab").1, Some("Must be at least 3 characters"));
    }

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
    fn prj_needs_info() {
        assert!(prj_ok(""));
        assert!(prj_ok("info=harcourt/x.prj&x=1"));
        assert!(!prj_ok("x=1"));
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
