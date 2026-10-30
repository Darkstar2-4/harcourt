//! The import/export pages built on map.rs: inventory_utility.php, inventory_no_price.php, inventory_price.php,
//! subcategory_utility.php, inventory_xref.php, user_utility.php and user_email.php. Each page is a small form;
//! the tables say which columns they have, which rows an export writes, and how an imported row is checked and
//! saved.
//!
//! As in the PHP, a review ("Review only") runs every check and saves nothing; "Update" saves the rows that pass.
//! The parsed upload waits in `staff_draft` between the two screens.

use std::{collections::HashMap, sync::Arc};

use axum::{extract::State, http::HeaderMap, response::Response, routing::get, Router};
use base64::{
    engine::general_purpose::{STANDARD, STANDARD_NO_PAD},
    Engine,
};
use sqlx::SqlitePool;

use super::{
    catalog, filesort,
    csrf::{php_intval, Fields, Posted, Upload},
    html::{button, close, esc, hidden, open, Blank, Form, Notes, Opts},
    inventory::{pricing, pricing_text, Break},
    map::{self, Carried, Counts, Draft, Export, Field, FileType, Header, Kind, Map, Rejected, Review},
    shell, Staff,
};
use crate::{
    api::{Administrator, Auth, Executive, MinRole},
    db::unix_now,
    identity::Role,
};

const PARAMETERS: usize = 20;
const BREAKS: usize = 10;
const INVENTORY_LEADTIME: [&str; 4] = ["Subcategory", "In Stock", "Weeks", "Call"];
const SUBCATEGORY_LEADTIME: [&str; 3] = ["In Stock", "Weeks", "Call"];

/// The step a utility form asks for: `function` and `action` together.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Step {
    Form,
    Cancel,
    Template,
    ListExport,
    Export,
    Upload,
    Import,
}

/// What the pages call each direction (`$map->function_list`).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Function {
    Download,
    Review,
    Upload,
}

impl Function {
    fn parse(s: &str) -> Option<Function> {
        match s {
            "download" => Some(Function::Download),
            "review" => Some(Function::Review),
            "upload" => Some(Function::Upload),
            _ => None,
        }
    }
}

fn step(f: &Fields) -> Step {
    let (action, function) = (f.get("action"), f.get("function"));
    match (action, Function::parse(function)) {
        ("cancel", _) => Step::Cancel,
        (_, None) if function == "template" => Step::Template,
        ("prelist", Some(Function::Download)) => Step::ListExport,
        ("update", Some(Function::Download)) => Step::Export,
        ("prelist", Some(_)) => Step::Upload,
        ("update", Some(_)) => Step::Import,
        _ => Step::Form,
    }
}

// ---------------------------------------------------------------------------------------------------------------
// Tables
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
    m.add(Field::new("temp_competitor_sku", Kind::Uppercase).name("Competitor SKU").width(60).comment("Comma separated"));
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

/// subcategory->map(): lead times and the PARTcommunity project of each subcategory.
fn subcategory_map() -> Map {
    let mut m = Map::new();
    m.add(Field::new("id", Kind::Id).name("Subcategory ID").required());
    m.add(Field::new("category_name", Kind::Alphanumeric).name("Category Name").width(60).export_only());
    m.add(Field::new("name", Kind::Alphanumeric).name("Subcategory Name").width(80).export_only());
    m.add(Field::new("leadtime_option", Kind::Alphanumeric).name("Leadtime Option").width(20).required());
    m.add(Field::new("leadtime_text", Kind::Alphanumeric).name("Leadtime Weeks").width(20).required());
    m.add(Field::new("status", Kind::Alphanumeric).name("Status"));
    m.add(Field::new("prj", Kind::Alphanumeric).name("PRJ").width(60));
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
    m.add(Field::new("state", Kind::Alphanumeric).name("State/Province").comment("US/CA must be valid name or ISO-Alpha2 codes"));
    m.add(Field::new("zip", Kind::Uppercase).name("ZIP/Postal Cost"));
    m.add(Field::new("country_code", Kind::Uppercase).name("Country Code").comment("Valid ISO-Alpha2"));
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

/// A row of an export in map order.
type Row = Vec<String>;

fn aligned(map: &Map, mut values: HashMap<&str, String>) -> Row {
    map.fields().iter().map(|f| values.remove(f.key.as_str()).unwrap_or_default()).collect()
}

fn opt(s: Option<String>) -> String {
    s.unwrap_or_default()
}

fn opt_i(v: Option<i64>) -> String {
    v.map(|n| n.to_string()).unwrap_or_default()
}

/// Which parts an inventory export takes: all, or one subcategory; with or without prices.
#[derive(Clone, Copy)]
struct InventoryFilter {
    subcategory_id: i64,
    no_price: bool,
}

/// inventory->export_query(): the parts in category, subcategory, sort order and SKU order.
async fn inventory_ids(pool: &SqlitePool, filter: InventoryFilter) -> sqlx::Result<Vec<i64>> {
    let mut keys = sqlx::query!(
        r#"SELECT i.id AS "id!: i64", c.name AS category, s.name AS subcategory, i.sort_order AS "sort_order!: i64", i.sku
           FROM inventory i LEFT JOIN subcategory s ON s.id = i.subcategory_id LEFT JOIN category c ON c.id = s.category_id
           WHERE (?1 = 0 OR i.subcategory_id = ?1) AND (?2 = 0 OR COALESCE(i.pricing, '') IN ('', 'a:0:{}'))
           ORDER BY i.id"#,
        filter.subcategory_id,
        filter.no_price
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
async fn inventory_rows(pool: &SqlitePool, map: &Map, ids: &[i64]) -> sqlx::Result<Vec<Row>> {
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
    let mut by_id: HashMap<i64, Row> = HashMap::new();
    for r in rows {
        let mut v: HashMap<&str, String> = HashMap::new();
        let competitor: Vec<String> = r.competitor_sku.filter(|c| !c.is_empty()).map(|c| c.split(',').map(str::to_string).collect()).unwrap_or_default();
        v.insert("sku", r.sku);
        v.insert("category_name", opt(r.category));
        v.insert("subcategory_name", opt(r.subcategory));
        v.insert("sort_order", r.sort_order.to_string());
        v.insert("new_date", r.new_date);
        v.insert("leadtime_option", r.leadtime_option);
        v.insert("leadtime_text", opt(r.leadtime_text));
        v.insert("temp_competitor_sku", competitor.join(", "));
        v.insert("active", opt_i(r.active));
        let texts: Vec<serde_json::Value> = serde_json::from_str(&r.parameters).unwrap_or_default();
        let numbers: Vec<serde_json::Value> = serde_json::from_str(&r.nparameters).unwrap_or_default();
        let mut extra: Vec<(String, String)> = Vec::new();
        for i in 0..PARAMETERS {
            extra.push((format!("parameter{i}"), texts.get(i).and_then(|t| t.as_str()).unwrap_or("").to_string()));
            extra.push((format!("nparameter{i}"), map::float_text(numbers.get(i).and_then(serde_json::Value::as_f64).unwrap_or(0.0))));
        }
        let breaks = r.pricing.as_deref().map(pricing).unwrap_or_default();
        for i in 0..BREAKS {
            let b = breaks.get(i);
            extra.push((format!("price{i}"), b.map(|b| b.price.to_string()).unwrap_or_default()));
            extra.push((format!("qty_break{i}"), b.map(|b| b.qty.to_string()).unwrap_or_default()));
        }
        let mut row = aligned(map, v);
        for (k, value) in extra {
            if let Some(i) = map.fields().iter().position(|f| f.key == k) {
                row[i] = value;
            }
        }
        by_id.insert(r.id, row);
    }
    Ok(ids.iter().filter_map(|id| by_id.remove(id)).collect())
}

async fn inventory_export(pool: &SqlitePool, map: &Map, filter: InventoryFilter) -> sqlx::Result<Vec<Row>> {
    let ids = inventory_ids(pool, filter).await?;
    let mut out = Vec::with_capacity(ids.len());
    for chunk in ids.chunks(500) {
        out.extend(inventory_rows(pool, map, chunk).await?);
    }
    Ok(out)
}

/// subcategory->export_query(): subcategories of real categories, by category and name.
async fn subcategory_export(pool: &SqlitePool, map: &Map, category_id: i64) -> sqlx::Result<Vec<Row>> {
    let mut rows = sqlx::query!(
        r#"SELECT s.id AS "id!: i64", c.name AS category, s.name, s.leadtime_option, s.leadtime_text, s.status, s.prj
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
                ]),
            )
        })
        .collect())
}

async fn xref_export(pool: &SqlitePool, map: &Map) -> sqlx::Result<Vec<Row>> {
    let mut rows = sqlx::query!(r#"SELECT sku, competitor, competitor_sku FROM inventory_xref ORDER BY sku, competitor, competitor_sku"#).fetch_all(pool).await?;
    filesort::sort(&mut rows, |a, b| filesort::ci(&a.sku, &b.sku).then_with(|| filesort::ci(&a.competitor, &b.competitor)));
    Ok(rows.into_iter().map(|r| aligned(map, HashMap::from([("sku", r.sku), ("competitor_sku", r.competitor_sku)]))).collect())
}

/// The accounts user_utility.php exports: by status, role, industry and last sign-in.
struct UserFilter {
    statuses: Vec<String>,
    roles: Vec<String>,
    industry_id: i64,
    /// `Y/m/d` bounds of the last sign-in, inclusive.
    from: String,
    thru: String,
}

async fn user_export(pool: &SqlitePool, map: &Map, f: &UserFilter) -> sqlx::Result<Vec<Row>> {
    let (statuses, roles) = (serde_json::to_string(&f.statuses).unwrap_or_default(), serde_json::to_string(&f.roles).unwrap_or_default());
    let mut rows = sqlx::query!(
        r#"SELECT u.id AS "id!: i64", u.ip, u.company_name, u.address, u.city, u.state, u.zip, u.country_code, u.status, u."type" AS kind,
                  u.last_login, u.customer_code, u.catalog, u.cad, u.leadscore, u.ecommerce, u.price_type, u.price_pct, u.login_document_id,
                  u.landing_document_id, u.portal_id, u.comment, u.language_code, u.currency_code, i.name AS industry, u.revenue, u.employees,
                  strftime('%Y/%m/%d', u.last_login, 'unixepoch', 'localtime') AS "login_day!: String"
           FROM "user" u LEFT JOIN industry i ON i.id = u.industry_id
           WHERE u.status IN (SELECT value FROM json_each(?1)) AND u."type" IN (SELECT value FROM json_each(?2))
             AND (?3 = 0 OR u.industry_id = ?3)
           ORDER BY u.id"#,
        statuses,
        roles,
        f.industry_id
    )
    .fetch_all(pool)
    .await?;
    rows.retain(|r| {
        let day = super::time::date("Y/m/d", r.last_login);
        (f.from.is_empty() || day >= f.from) && (f.thru.is_empty() || day <= f.thru) && !r.login_day.is_empty()
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

/// Which addresses user_email.php exports: all, or those first or last seen in a date range.
#[derive(Clone, Copy, PartialEq, Eq)]
enum EmailSource {
    All,
    First,
    Last,
}

async fn email_export(pool: &SqlitePool, map: &Map, source: EmailSource, from: &str, thru: &str) -> sqlx::Result<Vec<Row>> {
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
        let day = match source {
            EmailSource::All => return true,
            EmailSource::First => &r.first_day,
            EmailSource::Last => &r.last_day,
        };
        (from.is_empty() || day.as_str() >= from) && (thru.is_empty() || day.as_str() <= thru)
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

// ---------------------------------------------------------------------------------------------------------------
// Import
// ---------------------------------------------------------------------------------------------------------------

/// The table an import writes, with what it looked up once for every row (`$this->map->constant`, `->subcategory`).
enum Target {
    Inventory { subcategory_id: i64, subcategory: Option<(i64, i64)>, names: HashMap<String, (i64, i64)> },
    Erp,
    Subcategory { ids: Vec<i64> },
    Xref { stamp: i64 },
    User { lookups: Box<UserLookups>, viewer: Role },
}

struct UserLookups {
    currencies: Vec<String>,
    languages: Vec<String>,
    portals: Vec<i64>,
    documents: Vec<i64>,
    industries: Vec<(i64, String)>,
}

/// A line of an import: field key to the value read.
type Line = HashMap<String, String>;

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

type Errors = Vec<(String, String)>;

impl Target {
    /// import_update() for one line. `complete`: the file has every column of the map, so a new record may be added.
    async fn line(&self, tx: &mut sqlx::SqliteConnection, line: &Line, update: bool, complete: bool) -> sqlx::Result<Errors> {
        match self {
            Target::Inventory { subcategory_id, subcategory, names } => inventory_line(tx, line, update, *subcategory_id, *subcategory, names, complete).await,
            Target::Erp => erp_line(tx, line, update).await,
            Target::Subcategory { ids } => subcategory_line(tx, line, update, ids).await,
            Target::Xref { stamp } => xref_line(tx, line, update, *stamp).await,
            Target::User { lookups, viewer } => user_line(tx, line, update, lookups, *viewer).await,
        }
    }

    /// import_complete(): what an update does after the last row.
    async fn finish(&self, tx: &mut sqlx::SqliteConnection) -> sqlx::Result<()> {
        if let Target::Xref { stamp } = self {
            if *stamp != 0 {
                sqlx::query!("DELETE FROM inventory_xref WHERE update_timestamp <> ?1", stamp).execute(&mut *tx).await?;
            }
        }
        Ok(())
    }
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

#[allow(clippy::too_many_arguments)]
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
    let found = sqlx::query!(r#"SELECT id AS "id!: i64", category_id, subcategory_id, pricing FROM inventory WHERE sku = ?1"#, sku).fetch_optional(&mut *tx).await?;
    let mut errors: Errors = Vec::new();
    if sku.is_empty() || (found.is_none() && !complete) {
        errors.push(("sku".into(), format!("Invalid SKU: {}", esc(sku))));
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
            errors.push(("0".into(), format!("Invalid subcategory: {}:{}", esc(category), esc(name))));
        }
    }
    let competitor = has(line, "temp_competitor_sku").then(|| competitor_list(val(line, "temp_competitor_sku")));
    if let Some((_, Some(e))) = &competitor {
        errors.push(("temp_competitor_sku".into(), e.to_string()));
    }
    let leadtime = has(line, "leadtime_option").then(|| (import_match(val(line, "leadtime_option"), &INVENTORY_LEADTIME), val(line, "leadtime_text")));
    if let Some((option, text)) = leadtime {
        if option.is_empty() {
            errors.push(("leadtime_option".into(), format!("Allowed values: {}", INVENTORY_LEADTIME.join(", "))));
        } else if option == "Weeks" && text.is_empty() {
            errors.push(("leadtime_text".into(), "Cannot be blank".into()));
        } else if option != "Weeks" && !text.is_empty() {
            errors.push(("leadtime_text".into(), "Must be blank".into()));
        }
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
                        errors.push((format!("qty_break{i}"), format!("{} <= {}", super::html::number(qty as i64), super::html::number(last_qty as i64))));
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

fn decimal(v: f64) -> rust_decimal::Decimal {
    rust_decimal::prelude::FromPrimitive::from_f64(v).unwrap_or_default()
}

/// import_update_erp(): the ERP's breaks replace the part's prices. A break applies from one above the quantity
/// before it; a break that does not lower the price is skipped.
async fn erp_line(tx: &mut sqlx::SqliteConnection, line: &Line, update: bool) -> sqlx::Result<Errors> {
    let sku = val(line, "sku");
    let found = sqlx::query_scalar!(r#"SELECT id AS "id!: i64" FROM inventory WHERE sku = ?1"#, sku).fetch_optional(&mut *tx).await?;
    let mut errors: Errors = Vec::new();
    if found.is_none() {
        errors.push(("sku".into(), format!("Invalid SKU: {}", esc(sku))));
    }
    let breaks = erp_breaks(line);
    if let (Some(id), true, true) = (found, errors.is_empty(), update) {
        let text = pricing_text(&breaks);
        sqlx::query!("UPDATE inventory SET pricing = ?2 WHERE id = ?1", id, text).execute(&mut *tx).await?;
    }
    Ok(errors)
}

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
    let found = sqlx::query_scalar!(r#"SELECT id AS "id!: i64" FROM subcategory WHERE id = ?1"#, id).fetch_optional(&mut *tx).await?;
    if found.is_none() || !ids.contains(&id) {
        return Ok(vec![("id".into(), format!("Not found: {}", esc(val(line, "id"))))]);
    }
    let mut errors: Errors = Vec::new();
    let option = import_match(val(line, "leadtime_option"), &SUBCATEGORY_LEADTIME);
    let text = val(line, "leadtime_text");
    let prj = has(line, "prj").then(|| val(line, "prj"));
    if let Some(p) = prj.filter(|p| !prj_ok(p)) {
        errors.push(("prj".into(), format!("Malformed PRJ {}", esc(p))));
    }
    if option.is_empty() {
        errors.push(("leadtime_option".into(), format!("Allowed values: {}", SUBCATEGORY_LEADTIME.join(", "))));
    } else if option == "Weeks" && text.is_empty() {
        errors.push(("leadtime_text".into(), "Cannot be blank".into()));
    } else if option != "Weeks" && !text.is_empty() {
        errors.push(("leadtime_text".into(), "Must be blank".into()));
    }
    if update && errors.is_empty() {
        sqlx::query!("UPDATE subcategory SET leadtime_option = ?2, leadtime_text = ?3 WHERE id = ?1", id, option, text).execute(&mut *tx).await?;
        if let Some(p) = prj {
            let p = (!p.is_empty()).then_some(p);
            if sqlx::query!("UPDATE subcategory SET prj = ?2 WHERE id = ?1", id, p).execute(&mut *tx).await.is_err() {
                errors.push(("prj".into(), "Duplicate PRJ".into()));
            }
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

const ROLES: [Role; 6] = [Role::Customer, Role::External, Role::Internal, Role::Executive, Role::Administrator, Role::SuperUser];
const STATUSES: [&str; 3] = ["Pending", "Active", "Denied"];

/// user->import_update(): an account row checked against the lookups, then written whole. A role above the
/// importer's own is refused.
async fn user_line(tx: &mut sqlx::SqliteConnection, line: &Line, update: bool, l: &UserLookups, viewer: Role) -> sqlx::Result<Errors> {
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
        e.push(("id".into(), format!("Not found: {}", esc(val(line, "id")))));
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
        e.push(("ip".into(), format!("Invalid: {}", esc(ip))));
    }
    let text = |k: &str| val(line, k).to_string();
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
            e.push(("country_code".into(), format!("Invalid: {}", esc(&given))));
        }
    }
    if has(line, "state") {
        u.state = find_state(tx, &u.country_code, val(line, "state")).await?;
        if !val(line, "state").is_empty() && u.country_code.is_empty() {
            e.push(("state".into(), format!("Invalid: {}", esc(val(line, "state")))));
        }
    }
    if has(line, "zip") {
        u.zip = text("zip");
    }
    if has(line, "status") {
        u.status = import_match(val(line, "status"), &STATUSES).to_string();
        if u.status.is_empty() {
            e.push(("status".into(), format!("Invalid: {}", esc(val(line, "status")))));
        }
    }
    if has(line, "type") {
        let allowed: Vec<&str> = ROLES.iter().filter(|r| **r <= viewer).map(|r| r.as_str()).collect();
        u.kind = import_match(val(line, "type"), &allowed).to_string();
        if u.kind.is_empty() {
            e.push(("type".into(), format!("Invalid: {}", esc(val(line, "type")))));
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
            e.push(("price_type".into(), format!("Invalid: {}", esc(&u.price_type))));
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
                e.push((key.into(), format!("Invalid: {}", esc(val(line, key)))));
            }
        }
    }
    if has(line, "language_code") {
        let langs: Vec<&str> = l.languages.iter().map(String::as_str).collect();
        u.language_code = import_match(val(line, "language_code"), &langs).to_string();
        if u.language_code.is_empty() {
            e.push(("language_code".into(), format!("Invalid: {}", esc(val(line, "language_code")))));
        }
    }
    if has(line, "currency_code") {
        u.currency_code = text("currency_code");
        if !l.currencies.contains(&u.currency_code) {
            e.push(("currency_code".into(), format!("Invalid: {}", esc(&u.currency_code))));
        }
    }
    if has(line, "industry_name") {
        let name = val(line, "industry_name");
        u.industry_id = l.industries.iter().find(|(_, n)| n.eq_ignore_ascii_case(name)).map_or(0, |(id, _)| *id);
        if !name.is_empty() && u.industry_id == 0 {
            e.push(("industry_name".into(), format!("Invalid: {}", esc(name))));
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

// ---------------------------------------------------------------------------------------------------------------
// The two steps of an import
// ---------------------------------------------------------------------------------------------------------------

/// What an upload screen shows after upload() read the file.
struct Uploaded {
    header: Header,
    counts: Counts,
    external_file: String,
    file_type: FileType,
}

/// upload(): read the posted file, check its header, keep its rows for the update. `None` when the page shows its
/// form again; the messages say why.
async fn upload(pool: &SqlitePool, session: &crate::token::TokenHash, page: &str, map: &Map, posted: &Posted, notes: &mut Notes, update: bool) -> sqlx::Result<Option<Uploaded>> {
    let file: Option<&Upload> = posted.uploads.iter().find(|u| u.field == "upload");
    let name = file.map_or("", |f| f.file_name.as_str());
    notes.say_html(format!("Upload file: <b>{}</b>", esc(name)));
    let Some(file) = file.filter(|f| !f.file_name.is_empty() && !f.bytes.is_empty()) else {
        notes.say("File mising or empty");
        return Ok(None);
    };
    let sheet = match map::read_upload(&file.file_name, &file.bytes, None) {
        Ok(s) => s,
        Err(message) => {
            notes.say_html(message);
            return Ok(None);
        }
    };
    if let Some(w) = &sheet.worksheet {
        notes.say_html(format!("Worksheet: <b>{}</b>", esc(w)));
    }
    let mut rows = sheet.rows.into_iter();
    let names: Vec<String> = rows.next().unwrap_or_default().iter().map(|s| s.trim().to_string()).collect();
    let header = map::match_header(map, &names);
    let columns: Vec<&Field> = map.fields().iter().filter(|f| header.column.contains_key(&f.key)).collect();
    let mut counts = Counts { lines: 1, ..Counts::default() };
    let mut kept: Vec<Vec<String>> = Vec::new();
    for row in rows {
        counts.lines += 1;
        let cells: Vec<&str> = row.iter().map(|c| c.trim()).collect();
        if cells.iter().all(|c| c.is_empty()) {
            counts.empty += 1;
            kept.push(Vec::new());
            continue;
        }
        counts.accepted += 1;
        kept.push(columns.iter().map(|f| map::import_value(f, cells.get(header.column[&f.key]).copied().unwrap_or(""), sheet.file_type)).collect());
    }
    if !header.errors.is_empty() || columns.is_empty() {
        notes.say_html(header.errors.iter().map(|e| esc(e)).collect::<Vec<_>>().join("<br>"));
        return Ok(None);
    }
    if counts.accepted == 0 {
        notes.say("No records accepted for import");
        return Ok(None);
    }
    notes.say_html(if update { "Import update -> review" } else { "Import preview -> review" }.to_string());
    let draft = Draft { external_file: file.file_name.clone(), columns: columns.iter().map(|f| f.key.clone()).collect(), rows: kept };
    draft.save(pool, session, page).await?;
    Ok(Some(Uploaded { header, counts, external_file: file.file_name.clone(), file_type: sheet.file_type }))
}

/// What import() reports.
struct Imported {
    message: String,
    summary: Vec<String>,
    rejected: Vec<Rejected>,
    /// The upload's name, as posted back; empty when there was nothing to import.
    external_file: String,
}

/// import(): apply the kept rows, saving them when `update`, in one transaction that a failed row rolls back when
/// the page asks for that (`transaction`).
#[allow(clippy::too_many_arguments)]
async fn import(pool: &SqlitePool, staff: &Staff<impl MinRole>, page: &str, table: &str, group: &str, map: &Map, target: &Target, update: bool, transaction: bool, external_file: &str) -> sqlx::Result<Imported> {
    let Some(draft) = Draft::take(pool, &staff.session, page).await? else {
        return Ok(Imported { message: "Internal file missing".into(), summary: Vec::new(), rejected: Vec::new(), external_file: String::new() });
    };
    let complete = map.fields().iter().all(|f| draft.columns.contains(&f.key));
    let mut tx = pool.begin().await?;
    let (mut line_no, mut updated, mut empty) = (1usize, 0usize, 0usize);
    let mut rejected: Vec<Rejected> = Vec::new();
    for row in &draft.rows {
        line_no += 1;
        if row.is_empty() {
            empty += 1;
            continue;
        }
        let line: Line = draft.columns.iter().cloned().zip(row.iter().cloned()).collect();
        let errors = target.line(&mut tx, &line, update, complete).await?;
        if errors.is_empty() {
            updated += 1;
        } else {
            rejected.push(Rejected { line: line_no, errors });
        }
    }
    let mut summary = vec![format!("Table: {table}")];
    if map::truthy(group) {
        summary.push(format!("Group: {}", esc(group)));
    }
    summary.push(format!("File: {}", esc(external_file)));
    let names: Vec<String> = draft.columns.iter().filter_map(|k| map.field(k)).map(|f| esc(&f.name)).collect();
    summary.push(format!("Fields: {}", names.join(", ")));
    if updated > 0 {
        summary.push(format!("Updated records: {}", super::html::number(updated as i64)));
    }
    if empty > 0 {
        summary.push(format!("Empty records: {}", super::html::number(empty as i64)));
    }
    if !rejected.is_empty() {
        summary.push(format!("Rejected records: {}", super::html::number(rejected.len() as i64)));
    }
    let keep = update && !(transaction && !rejected.is_empty());
    if keep {
        target.finish(&mut tx).await?;
        tx.commit().await?;
        map::log(pool, staff, "Import Table", table, &summary.iter().map(|s| html_text(s)).collect::<Vec<_>>()).await?;
    } else {
        tx.rollback().await?;
    }
    let mut message = vec![if update { "Update complete" } else { "Review complete" }];
    if !rejected.is_empty() {
        message.push("/ Errors detected");
    }
    if update && !rejected.is_empty() && transaction {
        message.push("(Transaction Rolled Back)");
    }
    Ok(Imported { message: message.join(" "), summary, rejected, external_file: external_file.to_string() })
}

/// The log keeps the summary as text.
fn html_text(s: &str) -> String {
    s.replace("&amp;", "&").replace("&lt;", "<").replace("&gt;", ">").replace("&#039;", "'").replace("&quot;", "\"")
}

// ---------------------------------------------------------------------------------------------------------------
// Pages
// ---------------------------------------------------------------------------------------------------------------

const IMPORT_SCREEN: &str = r##"<script type='text/javascript'>
jQuery(document).ready(function() {
    import_screen();
});
function import_screen() {
	file_type_row=0;
    upload_row=0;
	switch ( jQuery("#function").val() ) {
      case "":
      	break;
	  case "download":
		file_type_row=1;
		break;
      default:
		upload_row=1;
	}
    if (file_type_row) {
		jQuery("#file_type_row").show();
	  } else {
		jQuery("#file_type_row").hide();
	}
    if (upload_row) {
		jQuery("#upload_row").show();
	  } else {
		jQuery("#upload_row").hide();
	}
}
function review_overwrite() {
     if (confirm('Replace all records with import file?')) {
     	fn_action('update');
     }
}
function fn_action(action) {
	document.scs_form.action.value=action;
	document.scs_form.submit();
}
</script>
"##;

const FN_ACTION: &str = "<script type='text/javascript'>
function review_overwrite() {
     if (confirm('Replace all records with import file?')) {
     \tfn_action('update');
     }
}
function fn_action(action) {
\tdocument.scs_form.action.value=action;
\tdocument.scs_form.submit();
}
</script>
";

fn functions(download: bool, upload: bool) -> Vec<(String, String)> {
    let mut out = Vec::new();
    if download {
        out.push(("download".to_string(), "WWW => Desktop".to_string()));
    }
    if upload {
        out.push(("review".to_string(), "Desktop => WWW (Review only)".to_string()));
        out.push(("upload".to_string(), "Desktop => WWW (Update)".to_string()));
    }
    out
}

/// What the import/export pages share: the posted report and the session, and the answer they build.
struct Utility<'a, R: MinRole> {
    pool: &'a SqlitePool,
    staff: &'a Staff<R>,
    posted: Option<&'a Posted>,
    fields: Fields,
    notes: Notes,
    path: &'static str,
}

impl<'a, R: MinRole> Utility<'a, R> {
    fn new(pool: &'a SqlitePool, staff: &'a Staff<R>, posted: Option<&'a Posted>, path: &'static str, title: &str, version: &str) -> Self {
        let fields = posted.map(|p| p.fields.clone()).unwrap_or_default();
        Utility { pool, staff, posted, fields, notes: Notes::new(title, version), path }
    }

    fn file_type(&self) -> FileType {
        FileType::posted(self.fields.get("file_type"))
    }

    async fn export(&self, table: &str, group: &str, map: &Map, rows: &[Row]) -> sqlx::Result<Export> {
        let chosen: Vec<usize> = map.chosen(&self.fields).iter().filter_map(|c| map.fields().iter().position(|f| f.key == c.key)).collect();
        let fields: Vec<&Field> = chosen.iter().map(|&i| &map.fields()[i]).collect();
        if rows.is_empty() {
            return Ok(Export::Empty);
        }
        let records: Vec<map::Record> = rows.iter().map(|r| chosen.iter().map(|&i| r[i].clone()).collect()).collect();
        let name = map::file_name(table, group, "", self.file_type());
        let answer = map::export_file(&name, self.file_type(), &fields, &records);
        if let Export::File(_) = answer {
            let mut lines = vec![format!("Table: {table}")];
            if map::truthy(group) {
                lines.push(format!("Group: {group}"));
            }
            lines.push(format!("File: {name}"));
            lines.push(format!("Rows: {}", super::html::number(rows.len() as i64)));
            map::log(self.pool, self.staff, "Export Table", table, &lines).await?;
        }
        Ok(answer)
    }

    fn export_failed(&mut self, answer: Export) -> Option<Response> {
        match answer {
            Export::File(r) => Some(r),
            Export::Empty => {
                self.notes.error_html("null", String::new());
                self.notes.say("No records found in selected range");
                None
            }
            Export::TooBig(n) => {
                self.notes.say_html(format!(
                    "Number of rows found ({}) excedes Excel capacity of 1,000,000 rows<br>Please break your export into {} files or use the txt/csv format",
                    super::html::number(n as i64),
                    n.div_ceil(1_000_000)
                ));
                self.notes.error_html("null", String::new());
                None
            }
        }
    }

    async fn page(&self, body: String) -> Response {
        shell::page(self.pool, self.staff.view(), self.notes_title(), &body).await
    }

    fn notes_title(&self) -> &'static str {
        match self.path {
            INVENTORY_PATH => "Inventory Import/Export",
            NO_PRICE_PATH => "Inventory w/Missing Prices",
            ERP_PATH => "ERP Price Import",
            SUBCATEGORY_PATH => "Subcategory Import/Export",
            XREF_PATH => "Competitor SKU Utility",
            USER_PATH => "User Table Utility",
            _ => "Email Export",
        }
    }
}

const INVENTORY_PATH: &str = "/inventory_utility.php";
const NO_PRICE_PATH: &str = "/inventory_no_price.php";
const ERP_PATH: &str = "/inventory_price.php";
const SUBCATEGORY_PATH: &str = "/subcategory_utility.php";
const XREF_PATH: &str = "/inventory_xref.php";
const USER_PATH: &str = "/user_utility.php";
const EMAIL_PATH: &str = "/user_email.php";

/// The report keys a page carries through its screens, in the order the PHP set them.
fn carried(f: &Fields, keys: &[&'static str], file_type: FileType, extra: &[(&'static str, String)]) -> Vec<(&'static str, Carried)> {
    let mut out: Vec<(&'static str, Carried)> = keys
        .iter()
        .map(|k| {
            let v = if *k == "file_type" { file_type.suffix().to_string() } else { f.get(k).to_string() };
            (*k, Carried::One(v))
        })
        .collect();
    out.extend(extra.iter().map(|(k, v)| (*k, Carried::One(v.clone()))));
    out
}

fn upload_screen(u: &Uploaded, map: &Map, report: &[(&'static str, Carried)], add_option: bool, update: bool) -> String {
    let mut fields = vec![("external_file", Carried::One(u.external_file.clone()))];
    for (k, v) in report {
        let v = match v {
            Carried::One(_) if *k == "file_type" => Carried::One(u.file_type.suffix().to_string()),
            Carried::One(s) => Carried::One(s.clone()),
            Carried::List(l) => Carried::List(l.clone()),
        };
        fields.push((k, v));
    }
    let hidden = map::hidden_fields(&fields, &["action"]);
    let errors = HashMap::new();
    map::upload_html(&hidden, &Review { map, header: &u.header, counts: u.counts, errors: &errors, update, add_option, omit: &[] })
}

fn import_screen(i: &Imported, map: &Map, report: &[(&'static str, Carried)]) -> String {
    let mut fields = vec![("external_file", Carried::One(i.external_file.clone()))];
    for (k, v) in report {
        fields.push((k, match v {
            Carried::One(s) => Carried::One(s.clone()),
            Carried::List(l) => Carried::List(l.clone()),
        }));
    }
    map::import_html(&map::hidden_fields(&fields, &["action"]), map, &i.summary, &i.rejected)
}

async fn subcategory_title(pool: &SqlitePool, id: i64) -> sqlx::Result<String> {
    if id == 0 {
        return Ok("All records".into());
    }
    let r = sqlx::query!(r#"SELECT c.name AS category, s.name FROM subcategory s LEFT JOIN category c ON c.id = s.category_id WHERE s.id = ?1"#, id).fetch_optional(pool).await?;
    Ok(r.map_or_else(|| ": ".to_string(), |r| format!("{}: {}", opt(r.category), opt(r.name))))
}

/// inventory_utility.php.
async fn inventory_page(u: &mut Utility<'_, Administrator>) -> sqlx::Result<Response> {
    let f = u.fields.clone();
    let subcategory_id = php_intval(f.get("subcategory_id"));
    let title = subcategory_title(u.pool, subcategory_id).await?;
    let map = inventory_map();
    let report = carried(&f, &["function", "subcategory_id", "file_type"], u.file_type(), &[("title", title.clone())]);
    let filter = InventoryFilter { subcategory_id, no_price: false };
    let group = f.get("subcategory_id").to_string();
    let mut count = 0;
    let mut view: Option<String> = None;
    match step(&f) {
        Step::Cancel => u.notes.say("Request Cancelled"),
        Step::Template => return Ok(template_file("inventory", &group, u.file_type(), &map)),
        Step::ListExport => {
            count = inventory_ids(u.pool, filter).await?.len();
            if count == 0 {
                u.notes.error("type", None);
                u.notes.say("No records found");
            }
        }
        Step::Export => {
            let rows = inventory_export(u.pool, &map, filter).await?;
            let answer = u.export("inventory", &group, &map, &rows).await?;
            if let Some(r) = u.export_failed(answer) {
                return Ok(r);
            }
        }
        Step::Upload => {
            let update = f.get("function") == "upload";
            let posted = u.posted;
            match posted {
                Some(p) => match upload(u.pool, &u.staff.session, INVENTORY_PATH, &map, p, &mut u.notes, update).await? {
                    Some(up) => view = Some(upload_screen(&up, &map, &report, false, update)),
                    None => u.notes.error("upload", None),
                },
                None => u.notes.error("upload", None),
            }
        }
        Step::Import => {
            let update = f.get("function") == "upload";
            let target = inventory_target(u.pool, subcategory_id).await?;
            let i = import(u.pool, u.staff, INVENTORY_PATH, "inventory", &group, &map, &target, update, false, f.get("external_file")).await?;
            u.notes.say(&i.message);
            view = Some(import_screen(&i, &map, &report));
        }
        Step::Form => {}
    }
    let mut body = u.notes.render();
    body.push_str(IMPORT_SCREEN);
    body.push_str(&open(INVENTORY_PATH, u.staff.csrf.as_str(), true));
    body.push_str(&hidden("action", ""));
    let form = Form { notes: &u.notes };
    let mut printed = vec!["action"];
    match view {
        Some(v) => {
            body.push_str(&v);
            printed.extend(["function", "file_type"]);
        }
        None if step(&f) == Step::ListExport && !u.notes.has_errors() => {
            body.push_str(&map::export_html(&form, &map, &map::hidden_fields(&report, &printed), &title, count));
            printed.extend(["function", "file_type"]);
        }
        None => {
            body.push_str("<table class='standard noborder'>");
            body.push_str(&format!(
                "<tr><td width=30%>Function</td><td width=70%>{}</td></tr>",
                form.select("function", &functions(true, true), f.get("function"), Blank::Empty, Opts::new().onchange("import_screen();"))
            ));
            body.push_str(&format!("<tr><td>Subcategory</td><td>{}</td></tr>", form.optgroup("subcategory_id", &catalog::groups(u.pool).await?, f.get("subcategory_id"), true, Opts::new())));
            body.push_str(&format!("<tr id='file_type_row'><td>File type</td><td>{}</td></tr>", form.select("file_type", &map::file_type_options(), f.get("file_type"), Blank::None, Opts::new())));
            body.push_str(&format!("<tr id='upload_row'><td>Upload File</td><td>{}</td></tr>", form.file("upload", 40, Opts::new())));
            body.push_str(&format!("<tr><td>&nbsp;</td><td>{}</td></tr>", button("Go!", Opts::new().onclick("fn_action('prelist');"))));
            body.push_str("</table>");
            printed.extend(["function", "file_type"]);
        }
    }
    let tail = [("function", Carried::One(f.get("function").to_string())), ("file_type", Carried::One(f.get("file_type").to_string()))];
    body.push_str(&map::hidden_fields(&tail, &printed));
    body.push_str(close());
    Ok(u.page(body).await)
}

/// template(): the header row alone, for filling in.
fn template_file(table: &str, group: &str, file: FileType, map: &Map) -> Response {
    let mut name = table.to_string();
    if map::truthy(group) {
        name.push('_');
        name.push_str(&group.to_lowercase());
    }
    name.push_str("_template");
    name.push_str(file.suffix());
    let fields: Vec<&Field> = map.fields().iter().collect();
    match map::export_file(&name, if file == FileType::Xlsx { FileType::Xlsx } else { file }, &fields, &[]) {
        Export::File(r) => r,
        _ => map::download(&name, "text/plain;charset=UTF-8", Vec::new()),
    }
}

async fn show_inventory(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>) -> Response {
    let mut u = Utility::new(&auth.pool, &staff, None, INVENTORY_PATH, "Inventory Import/Export", "");
    inventory_page(&mut u).await.unwrap_or_else(shell::internal)
}

async fn post_inventory(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, posted: Posted) -> Response {
    let mut u = Utility::new(&auth.pool, &staff, Some(&posted), INVENTORY_PATH, "Inventory Import/Export", "");
    inventory_page(&mut u).await.unwrap_or_else(shell::internal)
}

/// inventory_no_price.php: parts with no price breaks, for export only.
async fn no_price_page(u: &mut Utility<'_, Administrator>) -> sqlx::Result<Response> {
    let f = u.fields.clone();
    let subcategory_id = php_intval(f.get("subcategory_id"));
    let map = inventory_map();
    let filter = InventoryFilter { subcategory_id, no_price: true };
    let report = carried(&f, &["function", "subcategory_id", "file_type"], u.file_type(), &[("no_price", "1".into())]);
    let mut count = 0;
    match step(&f) {
        Step::Cancel => u.notes.say("Request Cancelled"),
        Step::ListExport => {
            count = inventory_ids(u.pool, filter).await?.len();
            if count == 0 {
                u.notes.error("type", None);
                u.notes.say("No records found");
            }
        }
        Step::Export => {
            let rows = inventory_export(u.pool, &map, filter).await?;
            let answer = u.export("inventory", "", &map, &rows).await?;
            if let Some(r) = u.export_failed(answer) {
                return Ok(r);
            }
        }
        _ => {}
    }
    let form = Form { notes: &u.notes };
    let mut body = u.notes.render();
    body.push_str(&open(NO_PRICE_PATH, u.staff.csrf.as_str(), false));
    body.push_str(&hidden("action", ""));
    body.push_str("<script>\nfunction fn_action(action) {\n\tdocument.scs_form.action.value=action;\n\tdocument.scs_form.submit();\n}\n</script>\n");
    if step(&f) == Step::ListExport && !u.notes.has_errors() {
        body.push_str(&map::export_html(&form, &map, &map::hidden_fields(&report, &["action"]), "", count));
    } else {
        body.push_str(&hidden("function", "download"));
        body.push_str("<table class='standard noborder'><tr><tr>");
        body.push_str(&format!(
            "<td width='30%'>Subcategory</td><td width='70%'>{}</td></tr>",
            form.optgroup("subcategory_id", &catalog::groups(u.pool).await?, f.get("subcategory_id"), true, Opts::new())
        ));
        body.push_str(&format!("<tr><td>File type</td><td>{}</td></tr>", form.select("file_type", &map::file_type_options(), f.get("file_type"), Blank::None, Opts::new())));
        body.push_str(&format!("<tr><td>&nbsp;</td><td>{}</td></tr></table>", button("Go!", Opts::new().onclick("fn_action('prelist');"))));
    }
    body.push_str(close());
    Ok(u.page(body).await)
}

/// inventory_price.php: the ERP's price list. The function list offers "review", which the page never handled
/// (it looked for "preview"), so only "Update" imports.
async fn erp_page(u: &mut Utility<'_, Administrator>) -> sqlx::Result<Response> {
    let f = u.fields.clone();
    let map = erp_map();
    let function = f.get("function");
    let handled = matches!(function, "preview" | "upload");
    let update = function == "upload";
    let file_type = FileType::Txt;
    let report = carried(&f, &["function"], file_type, &[("erp", "1".into())]);
    let mut report: Vec<(&'static str, Carried)> = report;
    let mut view = None;
    match f.get("action") {
        "cancel" => u.notes.say("Request Cancelled"),
        "prelist" if handled => match u.posted {
            Some(p) => match upload(u.pool, &u.staff.session, ERP_PATH, &map, p, &mut u.notes, update).await? {
                Some(up) => {
                    report.push(("file_type", Carried::One(up.file_type.suffix().into())));
                    view = Some(upload_screen(&up, &map, &report, false, update));
                }
                None => u.notes.error("upload", None),
            },
            None => u.notes.error("upload", None),
        },
        "update" if handled => {
            report.push(("file_type", Carried::One(f.get("file_type").to_string())));
            let i = import(u.pool, u.staff, ERP_PATH, "inventory", "", &map, &Target::Erp, update, false, f.get("external_file")).await?;
            u.notes.say(&i.message);
            view = Some(import_screen(&i, &map, &report));
        }
        _ => {}
    }
    let form = Form { notes: &u.notes };
    let mut body = u.notes.render();
    body.push_str(&open(ERP_PATH, u.staff.csrf.as_str(), true));
    body.push_str(&hidden("action", ""));
    body.push_str(FN_ACTION);
    match view {
        Some(v) => {
            body.push_str(&v);
            body.push_str(&hidden("function", function));
        }
        None => {
            body.push_str("<table class='standard noborder'>\n<tr>\n<td width=30%>Function</td>\n");
            body.push_str(&format!("<td width=70%>{}</td>\n</tr>\n<tr>\n", form.select("function", &functions(false, true), function, Blank::Empty, Opts::new())));
            body.push_str(&format!("<tr id='upload_row'>\n<td>Upload File</td>\n<td>{}</td>\n</tr>\n", form.file("upload", 40, Opts::new())));
            body.push_str(&format!("<tr>\n<td>&nbsp;</td>\n<td>{}</td>\n</tr>\n</table>\n", submit_go()));
        }
    }
    body.push_str(close());
    Ok(u.page(body).await)
}

fn submit_go() -> String {
    super::html::submit("Go!", Opts::new().onclick("fn_action('prelist');"))
}

/// subcategory_utility.php: lead times and PRJ links of the subcategories. A download starts at once; an update runs
/// in one transaction that any rejected line rolls back.
async fn subcategory_page(u: &mut Utility<'_, Administrator>) -> sqlx::Result<Response> {
    let f = u.fields.clone();
    let category_id = php_intval(f.get("category_id"));
    let title = if category_id == 0 {
        "All records".to_string()
    } else {
        sqlx::query_scalar!("SELECT name FROM category WHERE id = ?1", category_id).fetch_optional(u.pool).await?.flatten().unwrap_or_default()
    };
    let map = subcategory_map();
    let group = f.get("category_id").to_string();
    let report = carried(&f, &["function", "category_id", "file_type"], u.file_type(), &[("add", String::new()), ("title", title)]);
    let mut view = None;
    match step(&f) {
        Step::Cancel => u.notes.say("Request Cancelled"),
        Step::ListExport | Step::Export | Step::Template if f.get("function") == "download" => {
            let rows = subcategory_export(u.pool, &map, category_id).await?;
            let answer = u.export("subcategory", &group, &map, &rows).await?;
            if let Some(r) = u.export_failed(answer) {
                return Ok(r);
            }
        }
        Step::Upload => {
            let update = f.get("function") == "upload";
            match u.posted {
                Some(p) => match upload(u.pool, &u.staff.session, SUBCATEGORY_PATH, &map, p, &mut u.notes, update).await? {
                    Some(up) => view = Some(upload_screen(&up, &map, &report, true, update)),
                    None => u.notes.error("upload", None),
                },
                None => u.notes.error("upload", None),
            }
        }
        Step::Import => {
            let update = f.get("function") == "upload";
            let ids = subcategory_export(u.pool, &map, category_id).await?.into_iter().map(|r| php_intval(&r[0])).collect();
            let i = import(u.pool, u.staff, SUBCATEGORY_PATH, "subcategory", &group, &map, &Target::Subcategory { ids }, update, true, f.get("external_file")).await?;
            u.notes.say(&i.message);
            view = Some(import_screen(&i, &map, &report));
        }
        _ => {}
    }
    let form = Form { notes: &u.notes };
    let mut body = u.notes.render();
    body.push_str(IMPORT_SCREEN);
    body.push_str(&open(SUBCATEGORY_PATH, u.staff.csrf.as_str(), true));
    body.push_str(&hidden("action", ""));
    match view {
        Some(v) => body.push_str(&v),
        None => {
            body.push_str("<table class='standard noborder'>");
            body.push_str(&format!(
                "<tr><td width=30%>Function</td><td width=70%>{}</td></tr>",
                form.select("function", &functions(true, true), f.get("function"), Blank::Empty, Opts::new().onchange("import_screen();"))
            ));
            body.push_str(&format!("<tr><td>Category</td><td>{}</td></tr>", form.select("category_id", &super::choices::categories(u.pool).await?, f.get("category_id"), Blank::Empty, Opts::new())));
            body.push_str(&format!("<tr id='file_type_row'><td>File type</td><td>{}</td></tr>", form.select("file_type", &map::file_type_options(), f.get("file_type"), Blank::None, Opts::new())));
            body.push_str(&format!("<tr id='upload_row'><td>Upload File</td><td>{}</td></tr>", form.file("upload", 40, Opts::new())));
            body.push_str(&format!("<tr><td>&nbsp;</td><td>{}</td></tr></table>", button("Go!", Opts::new().onclick("fn_action('prelist');"))));
        }
    }
    body.push_str(close());
    Ok(u.page(body).await)
}

/// inventory_xref.php: competitor part numbers. As on the PHP page only "Update" imports ("review" is offered but
/// was never handled). An XR file (one column per competitor) is turned into Item #/Xref lines first.
async fn xref_page(u: &mut Utility<'_, Administrator>) -> sqlx::Result<Response> {
    let f = u.fields.clone();
    let map = xref_map();
    let action = f.get("action");
    let function = if action.is_empty() { "upload" } else { f.get("function") };
    let handled = matches!(function, "preview" | "upload");
    let update = function == "upload";
    let report = carried(&f, &["function", "custom_format", "update_timestamp", "file_type"], u.file_type(), &[]);
    let mut view = None;
    let mut listed = false;
    match (action, function) {
        ("cancel", _) => u.notes.say("Request Cancelled"),
        ("prelist", "download") => listed = true,
        ("update", "download") => {
            let rows = xref_export(u.pool, &map).await?;
            let answer = u.export("inventory_xref", "", &map, &rows).await?;
            if let Some(r) = u.export_failed(answer) {
                return Ok(r);
            }
        }
        ("prelist", _) if handled => match u.posted {
            Some(p) => {
                let converted = if f.checked("custom_format") { p.uploads.iter().find(|x| x.field == "upload").map(xr_lines) } else { None };
                let source = converted.as_ref().map(|(name, bytes)| Posted { fields: p.fields.clone(), uploads: vec![Upload { field: "upload".into(), file_name: name.clone(), bytes: bytes.clone().into() }] });
                match upload(u.pool, &u.staff.session, XREF_PATH, &map, source.as_ref().unwrap_or(p), &mut u.notes, update).await? {
                    Some(up) => view = Some(upload_screen(&up, &map, &report, false, update)),
                    None => u.notes.error("upload", None),
                }
            }
            None => u.notes.error("upload", None),
        },
        ("update", _) if handled => {
            let stamp = if f.checked("update_timestamp") { unix_now() } else { 0 };
            let i = import(u.pool, u.staff, XREF_PATH, "inventory_xref", "", &map, &Target::Xref { stamp }, update, false, f.get("external_file")).await?;
            u.notes.say(&i.message);
            view = Some(import_screen(&i, &map, &report));
        }
        _ => {}
    }
    let form = Form { notes: &u.notes };
    let mut body = u.notes.render();
    body.push_str(FN_ACTION);
    body.push_str(&open(XREF_PATH, u.staff.csrf.as_str(), true));
    body.push_str(&hidden("action", ""));
    let default_page = view.is_none() && !(listed && !u.notes.has_errors());
    match view {
        Some(v) => body.push_str(&v),
        None if listed && !u.notes.has_errors() => body.push_str(&map::export_html(&form, &map, &map::hidden_fields(&report, &["action"]), "", 0)),
        None => {
            let rows = [
                format!("<tr>\n<td width=30%>Function</td>\n<td width=70%>{}</td>\n</tr>\n", form.select("function", &functions(true, true), function, Blank::None, Opts::new().onchange("import_screen();"))),
                format!("<tr id='row_file_type'>\n<td>File type (export only)</td>\n<td>{}</td>\n</tr>\n", form.select("file_type", &map::file_type_options(), f.get("file_type"), Blank::None, Opts::new())),
                format!("<tr id='row_xr'>\n<td>XR File?</td>\n<td>{}</td>\n</tr>\n", form.checkbox("custom_format", "1", f.checked("custom_format"), Opts::new())),
                format!("<tr id='row_update_timestamp'>\n<td>Remove existing records?</td>\n<td>{}</td>\n</tr>\n", form.checkbox("update_timestamp", "1", f.checked("update_timestamp"), Opts::new())),
                format!("<tr id='row_update'>\n<td>Upload File</td>\n<td>{}</td>\n</tr>\n", form.file("upload", 40, Opts::new())),
                format!("<tr>\n<td>&nbsp;</td>\n<td>{}</td>\n</tr>\n", button("Go!", Opts::new().onclick("fn_action('prelist');"))),
            ];
            body.push_str(&format!("<table class='standard noborder'>\n{}</table>\n", rows.concat()));
            body.push_str(XREF_SCREEN);
        }
    }
    if !default_page {
        body.push_str(&hidden("function", function));
        body.push_str(&hidden("file_type", f.get("file_type")));
    }
    body.push_str(close());
    Ok(u.page(body).await)
}

const XREF_SCREEN: &str = "<script type='text/javascript'>
function import_screen() {
\tvar row_file_type=0;
\tvar row_xr=0;
\tvar row_update_timestamp=0;
\tvar row_upload=0;
\tswitch (document.getElementById('function').value ) {
\t  case 'download':
\t\trow_file_type=1;
\t\tbreak;
      default:
\t\trow_xr=1;
\t\trow_update_timestamp=1;
\t\trow_upload=1;
\t}
    div_show('row_file_type',row_file_type,'');
    div_show('row_xr',row_xr,'');
    div_show('row_update_timestamp',row_update_timestamp,'');
    div_show('row_upload',row_upload,'');
}
import_screen();
</script>
";

/// An XR file: SKU, price, then one column per competitor named in the header row ("-" for none). As tab-separated
/// Item #/Xref lines, under the upload's own name.
fn xr_lines(upload: &Upload) -> (String, Vec<u8>) {
    let name = if upload.file_name.to_ascii_lowercase().ends_with(".csv") { upload.file_name.clone() } else { format!("{}.txt", upload.file_name) };
    let delimiter = if name.to_ascii_lowercase().ends_with(".csv") { ',' } else { '\t' };
    let text = String::from_utf8_lossy(&upload.bytes);
    let mut lines = text.lines();
    let names: Vec<String> = lines.next().map(|h| map::parse_csv_line(h, delimiter)).unwrap_or_default().iter().map(|n| n.trim().replace('\n', " ")).collect();
    let mut out = map::csv_line(&["Item #".into(), "Xref".into()], delimiter as u8);
    for line in lines {
        let cells = map::parse_csv_line(line, delimiter);
        let sku = cells.first().map(|s| s.trim()).unwrap_or("");
        if sku.is_empty() {
            continue;
        }
        for (i, value) in cells.iter().enumerate().skip(2) {
            let value = value.trim();
            if !value.is_empty() && value != "-" && names.get(i).is_some_and(|n| !n.is_empty()) {
                out.extend(map::csv_line(&[sku.to_string(), value.to_string()], delimiter as u8));
            }
        }
    }
    (name, out)
}

const USER_SCRIPT: &str = "<script type='text/javascript'>
function fn_action(action) {
\tif (action=='update') {
\t    if (!confirm(\"Continue?\")) {
\t        obj.checked=false;
            return;
\t    }
    }
\tdocument.scs_form.action.value=action;
\tdocument.scs_form.submit();
}
function function_change() {
\tswitch (document.getElementById('function').value) {
\t  case '':
\t\tdownload=0;
\t\tupload=0;
        break;
\t  case 'download':
\t\tdownload=1;
\t\tupload=0;
        break;
\t  default:
      \tdownload=0;
\t\tupload=1;
    }
    div_show('row_industry',download,'');
    div_show('row_type',download,'');
    div_show('row_status',download,'');
    div_show('row_login_date',download,'');
    div_show('row_file_type',download,'');
    div_show('row_upload',upload,'');
    div_show('utility_button',upload + download,'');
}
</script>
";

/// fn_date($text, "mdy"): a typed `m/d/y` date as `Y/m/d`, "" when it is not a date.
fn mdy(s: &str) -> String {
    let parts: Vec<i64> = s.split([' ', '/', '.', '-']).map(php_intval).collect();
    if s.trim().is_empty() || parts.len() < 3 {
        return String::new();
    }
    let (m, d, mut y) = (parts[0], parts[1], parts[2]);
    y += match y {
        0..=49 => 2000,
        50..=99 => 1900,
        _ => 0,
    };
    let ok = (1800..=2200).contains(&y) && chrono::NaiveDate::from_ymd_opt(y as i32, m as u32, d as u32).is_some();
    if ok {
        format!("{y:04}/{m:02}/{d:02}")
    } else {
        String::new()
    }
}

/// user_utility.php: accounts out by role, status, industry and last sign-in; back in with every column checked.
async fn user_page(u: &mut Utility<'_, Administrator>) -> sqlx::Result<Response> {
    let f = u.fields.clone();
    let action = f.get("action");
    let viewer = u.staff.who.user.role;
    let roles: Vec<&str> = ROLES.iter().map(|r| r.as_str()).collect();
    let (mut statuses, mut kinds): (Vec<String>, Vec<String>) = (Vec::new(), Vec::new());
    let (mut from, mut thru) = (String::new(), String::new());
    if action.is_empty() {
        statuses = STATUSES.iter().map(|s| s.to_string()).collect();
        kinds = roles.iter().map(|s| s.to_string()).collect();
    } else {
        for (k, _) in f.iter() {
            let decoded = STANDARD_NO_PAD.decode(k.trim_end_matches('='));
            let Ok(decoded) = decoded else { continue };
            let text = String::from_utf8_lossy(&decoded).to_string();
            match text.split_once('\t') {
                Some(("status", code)) if !code.is_empty() => statuses.push(code.to_string()),
                Some(("type", code)) if !code.is_empty() => kinds.push(code.to_string()),
                _ => {}
            }
        }
        if statuses.is_empty() {
            u.notes.error("status", None);
        }
        if kinds.is_empty() {
            u.notes.error("type", None);
        }
        from = mdy(f.get("login_date_from"));
        thru = mdy(f.get("login_date_thru"));
    }
    let map = user_map();
    let file_type = u.file_type();
    let mut report: Vec<(&'static str, Carried)> = vec![
        ("function", Carried::One(f.get("function").into())),
        ("file_type", Carried::One(file_type.suffix().into())),
        ("industry_id", Carried::One(f.get("industry_id").into())),
        ("status", Carried::List(statuses.clone())),
        ("type", Carried::List(kinds.clone())),
    ];
    if !action.is_empty() {
        report.push(("login_date_from", Carried::One(from.clone())));
        report.push(("login_date_thru", Carried::One(thru.clone())));
    }
    let allowed: Vec<String> = kinds.iter().filter(|k| Role::parse(k).is_some_and(|r| r <= viewer)).cloned().collect();
    let filter = UserFilter { statuses: statuses.clone(), roles: allowed, industry_id: php_intval(f.get("industry_id")), from: from.clone(), thru: thru.clone() };
    let mut view = None;
    let mut listed = 0;
    match step(&f) {
        _ if action.is_empty() || f.get("function").is_empty() => {}
        Step::Cancel => u.notes.say("Request Cancelled"),
        Step::ListExport => {
            if !thru.is_empty() && from > thru {
                u.notes.error("login_date_thru", None);
            }
            if !u.notes.has_errors() {
                listed = user_export(u.pool, &map, &filter).await?.len();
                if listed == 0 {
                    u.notes.error_html("null", String::new());
                    u.notes.say("No records found");
                }
            }
        }
        Step::Export => {
            let rows = user_export(u.pool, &map, &filter).await?;
            let answer = u.export("user", "", &map, &rows).await?;
            if let Some(r) = u.export_failed(answer) {
                return Ok(r);
            }
        }
        Step::Upload => {
            let update = f.get("function") == "upload";
            match u.posted {
                Some(p) => match upload(u.pool, &u.staff.session, USER_PATH, &map, p, &mut u.notes, update).await? {
                    Some(up) => view = Some(upload_screen(&up, &map, &report, false, update)),
                    None => u.notes.error("upload", None),
                },
                None => u.notes.error("upload", None),
            }
        }
        Step::Import => {
            let update = f.get("function") == "upload";
            let target = Target::User { lookups: Box::new(user_lookups(u.pool).await?), viewer };
            let i = import(u.pool, u.staff, USER_PATH, "user", "", &map, &target, update, false, f.get("external_file")).await?;
            u.notes.say(&i.message);
            view = Some(import_screen(&i, &map, &report));
        }
        _ => {}
    }
    let form = Form { notes: &u.notes };
    let mut body = u.notes.render();
    body.push_str(USER_SCRIPT);
    body.push_str(&open(USER_PATH, u.staff.csrf.as_str(), true));
    body.push_str(&hidden("action", ""));
    match view {
        Some(v) => body.push_str(&v),
        None if step(&f) == Step::ListExport && !u.notes.has_errors() => {
            body.push_str(&map::export_html(&form, &map, &map::hidden_fields(&report, &["action"]), "User Export Utility", listed));
        }
        None => {
            let yellow = |field: &str| if u.notes.is_error(field) { "style='background-color: #ffff00;'" } else { "" };
            let boxes = |key: &str, list: &[&str], chosen: &[String]| -> String {
                list.iter()
                    .map(|v| format!("{} {v}? ", form.checkbox(&STANDARD.encode(format!("{key}\t{v}")), "1", chosen.iter().any(|c| c == v), Opts::new())))
                    .collect::<Vec<_>>()
                    .join("<br>")
            };
            body.push_str("<table class='standard noborder'>\n");
            body.push_str(&format!(
                "<tr>\n<td width='30%'>Action</td>\n<td width='70%'>{}</td>\n</tr>\n",
                form.select("function", &functions(true, true), f.get("function"), Blank::Empty, Opts::new().onchange("function_change();"))
            ));
            body.push_str(&format!("<tr id='row_industry'>\n<td>Selected industry</td>\n<td>{}</td>\n</tr>\n", form.select("industry_id", &super::choices::industries(u.pool).await?, f.get("industry_id"), Blank::Empty, Opts::new())));
            body.push_str(&format!("<tr id='row_type'>\n<td>User Roles</td>\n<td {}>{}</td>\n</tr>\n", yellow("type"), boxes("type", &roles, &kinds)));
            body.push_str(&format!("<tr id='row_status'>\n<td>User Status</td>\n<td {}>{}</td>\n</tr>\n", yellow("status"), boxes("status", &STATUSES, &statuses)));
            body.push_str(&format!(
                "<tr id='row_login_date'>\n<td>Last login date</td>\n<td>{} - {}</td>\n</tr>\n",
                form.date("login_date_from", &ymd_shown(&from), Opts::new()),
                form.date("login_date_thru", &ymd_shown(&thru), Opts::new())
            ));
            body.push_str(&format!("<tr id='row_file_type'>\n<td>Export file type</td>\n<td>{}</td>\n</tr>\n", form.select("file_type", &map::file_type_options(), f.get("file_type"), Blank::None, Opts::new())));
            body.push_str(&format!("<tr id='row_upload'>\n<td>Import File</td>\n<td>{}</td>\n</tr>\n</table>\n", form.file("upload", 40, Opts::new())));
            body.push_str(&format!("<p>{}</p>\n", button("Go!", Opts::new().onclick("fn_action('prelist');").set("id", "utility_button"))));
            body.push_str("<script type='text/javascript'>\nfunction_change();\n</script>\n");
        }
    }
    body.push_str(close());
    Ok(u.page(body).await)
}

/// A `Y/m/d` date as the date fields show it (`m/d/Y`).
fn ymd_shown(ymd: &str) -> String {
    let p: Vec<&str> = ymd.split('/').collect();
    match p[..] {
        [y, m, d] => format!("{m}/{d}/{y}"),
        _ => String::new(),
    }
}

const EMAIL_SCRIPT: &str = "
<script>
jQuery(document).ready(function() {
\tfn_date_range();
});
function fn_date_range() {
    if (jQuery(\"#source\").val() == \"\") {
        jQuery(\"#date_range\").hide();
      } else {
        jQuery(\"#date_range\").show();
    }
}
</script>";

/// user_email.php: every address in the event log, with the address of its account.
async fn email_page(pool: &SqlitePool, staff: &Staff<Executive>, posted: Option<&Posted>) -> sqlx::Result<Response> {
    let f = posted.map(|p| p.fields.clone()).unwrap_or_default();
    let mut notes = Notes::new("Email Export", "12/03/2024");
    let (from, thru) = (mdy(f.get("from_date")), mdy(f.get("thru_date")));
    let source = match f.get("source") {
        "first" => EmailSource::First,
        "last" => EmailSource::Last,
        _ => EmailSource::All,
    };
    if !f.get("action").is_empty() && source != EmailSource::All && !thru.is_empty() && from > thru {
        notes.error("date", Some("Invalid date range"));
    }
    if !f.get("action").is_empty() && !notes.has_errors() {
        let map = email_map();
        let rows = email_export(pool, &map, source, &from, &thru).await?;
        let file = FileType::posted(f.get("file_type"));
        let name = map::file_name("export", "email", "", file);
        let fields: Vec<&Field> = map.fields().iter().collect();
        if rows.is_empty() {
            notes.error_html("null", String::new());
            notes.say("No records found in selected range");
        } else if let Export::File(r) = map::export_file(&name, file, &fields, &rows) {
            let lines = vec!["Table: export".to_string(), "Group: email".into(), format!("File: {name}"), format!("Rows: {}", super::html::number(rows.len() as i64))];
            map::log(pool, staff, "Export Table", "export", &lines).await?;
            return Ok(r);
        }
    }
    let form = Form { notes: &notes };
    let mut body = notes.render();
    body.push_str(EMAIL_SCRIPT);
    body.push_str(&open(EMAIL_PATH, staff.csrf.as_str(), false));
    body.push_str(&hidden("action", "export"));
    let sources = [("", "All Dates"), ("first", "First Date"), ("last", "Last Date")].map(|(v, l)| (v.to_string(), l.to_string()));
    let mut range = vec![format!("From: {}", form.date("from_date", &ymd_shown(&from), Opts::new())), format!("Thru: {}", form.date("thru_date", &ymd_shown(&thru), Opts::new()))];
    if let Some(e) = notes.error_text("date") {
        range.push(format!("<b>{e}</b>"));
    }
    body.push_str(&format!(
        "<table class='noborder'><tr><td width='20%'>{}</td><td width='80%' id=date_range>{}</td></tr>",
        form.select("source", &sources, f.get("source"), Blank::None, Opts::new().onchange("fn_date_range();")),
        range.join(" ")
    ));
    body.push_str(&format!("<tr><td width='20%'>Export file type</td><td>{}</td></tr></table>", form.select("file_type", &map::file_type_options(), f.get("file_type"), Blank::None, Opts::new())));
    body.push_str(&format!("<p>{}</p>", super::html::submit("Go", Opts::new())));
    body.push_str(close());
    Ok(shell::page(pool, staff.view(), "Email Export", &body).await)
}

macro_rules! utility_routes {
    ($show:ident, $post:ident, $path:expr, $title:expr, $page:ident) => {
        async fn $show(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>) -> Response {
            let mut u = Utility::new(&auth.pool, &staff, None, $path, $title, "");
            $page(&mut u).await.unwrap_or_else(shell::internal)
        }

        async fn $post(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, posted: Posted) -> Response {
            let mut u = Utility::new(&auth.pool, &staff, Some(&posted), $path, $title, "");
            $page(&mut u).await.unwrap_or_else(shell::internal)
        }
    };
}

utility_routes!(show_no_price, post_no_price, NO_PRICE_PATH, "Inventory w/Missing Prices", no_price_page);
utility_routes!(show_erp, post_erp, ERP_PATH, "ERP Price Import", erp_page);
utility_routes!(show_subcategory, post_subcategory, SUBCATEGORY_PATH, "Subcategory Import/Export", subcategory_page);
utility_routes!(show_xref, post_xref, XREF_PATH, "Competitor SKU Utility", xref_page);
utility_routes!(show_user, post_user, USER_PATH, "User Table Utility", user_page);

/// The page's script posts nothing back; an AJAX request got an empty object.
fn ajax(headers: &HeaderMap) -> Option<Response> {
    headers.contains_key("x-requested-with").then(|| shell::html_response(axum::http::StatusCode::OK, "{}".into()))
}

async fn show_email(State(auth): State<Arc<Auth>>, staff: Staff<Executive>, headers: HeaderMap) -> Response {
    if let Some(r) = ajax(&headers) {
        return r;
    }
    email_page(&auth.pool, &staff, None).await.unwrap_or_else(shell::internal)
}

async fn post_email(State(auth): State<Arc<Auth>>, staff: Staff<Executive>, headers: HeaderMap, posted: Posted) -> Response {
    if let Some(r) = ajax(&headers) {
        return r;
    }
    email_page(&auth.pool, &staff, Some(&posted)).await.unwrap_or_else(shell::internal)
}

pub fn routes() -> Router<Arc<Auth>> {
    Router::new()
        .route(INVENTORY_PATH, get(show_inventory).post(post_inventory))
        .route(NO_PRICE_PATH, get(show_no_price).post(post_no_price))
        .route(ERP_PATH, get(show_erp).post(post_erp))
        .route(SUBCATEGORY_PATH, get(show_subcategory).post(post_subcategory))
        .route(XREF_PATH, get(show_xref).post(post_xref))
        .route(USER_PATH, get(show_user).post(post_user))
        .route(EMAIL_PATH, get(show_email).post(post_email))
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
    fn steps_follow_function_and_action() {
        assert_eq!(step(&Fields::of(&[("action", "prelist"), ("function", "download")])), Step::ListExport);
        assert_eq!(step(&Fields::of(&[("action", "update"), ("function", "review")])), Step::Import);
        assert_eq!(step(&Fields::of(&[("action", "cancel"), ("function", "upload")])), Step::Cancel);
        assert_eq!(step(&Fields::default()), Step::Form);
    }

    #[test]
    fn prj_needs_info() {
        assert!(prj_ok(""));
        assert!(prj_ok("info=harcourt/x.prj&x=1"));
        assert!(!prj_ok("x=1"));
    }
}
