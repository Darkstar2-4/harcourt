//! The catalog's rows, parsed once: categories, product families (the `subcategory` table) with their parameters,
//! and parts (the `inventory` table). Names and descriptions are in the viewer's language where `content` has them.

use std::collections::{BTreeMap, HashSet};

use sqlx::SqlitePool;

use super::{
    php::{self, Value},
    registry::Registry,
    viewer::Lang,
};

pub const PARAMETERS: usize = 20;

#[derive(Debug, Clone, PartialEq)]
pub struct Category {
    pub id: i64,
    pub name: String,
    pub sort_order: i64,
    pub active: bool,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Status {
    Active,
    Pending,
    Inactive,
}

impl Status {
    fn parse(s: &str) -> Status {
        match s {
            "Active" => Status::Active,
            "Pending" => Status::Pending,
            _ => Status::Inactive,
        }
    }

    /// Shown to everyone when Active, to Administrators when Pending too.
    pub fn visible(self, sees_pending: bool) -> bool {
        self == Status::Active || (sees_pending && self == Status::Pending)
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ParamKind {
    Text,
    Number,
    Decimal(usize),
}

/// subcategory_parameter_class.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Parameter {
    pub id: usize,
    pub name: String,
    pub kind: ParamKind,
    pub hyperlink: bool,
}

impl Parameter {
    fn parse(v: &Value) -> Option<Parameter> {
        let id = usize::try_from(v.get("id")?.int()).ok().filter(|i| *i < PARAMETERS)?;
        let ty = v.get("type").map(Value::text).unwrap_or_else(|| "Alphanumeric".into());
        let kind = match ty.as_str() {
            "Alphanumeric" | "Uppercase" | "Lowercase" => ParamKind::Text,
            "Numeric" => ParamKind::Number,
            other => ParamKind::Decimal(other.chars().last().and_then(|c| c.to_digit(10)).unwrap_or(0) as usize),
        };
        Some(Parameter {
            id,
            name: v.get("name").map(Value::text).unwrap_or_default().trim().to_string(),
            kind,
            hyperlink: v.get("hyperlink").is_some_and(|h| h.int() != 0),
        })
    }

    pub fn numeric(&self) -> bool {
        self.kind != ParamKind::Text
    }

    /// subcategory_parameter_class::output() for a stored numeric value.
    pub fn output(&self, n: f64) -> String {
        match self.kind {
            ParamKind::Text | ParamKind::Number => php_float(n),
            ParamKind::Decimal(places) => format!("{n:.places$}"),
        }
    }
}

/// How PHP prints a float: integral values without a point, others in their shortest form.
pub fn php_float(n: f64) -> String {
    if n.fract() == 0.0 && n.abs() < 1e15 {
        format!("{}", n as i64)
    } else {
        let s = format!("{n}");
        if s.len() > 17 {
            format!("{:.14}", n).trim_end_matches('0').to_string()
        } else {
            s
        }
    }
}

/// A product family: one `subcategory` row.
#[derive(Debug, Clone, PartialEq)]
pub struct Family {
    pub id: i64,
    pub category_id: i64,
    pub sort_order: i64,
    pub name: String,
    /// The English name in the table; Metric is read from it whatever the page language.
    pub table_name: String,
    pub description: String,
    pub prj: String,
    pub image_url: String,
    pub thumbnail_url: String,
    pub sketch_url: String,
    pub parameters: Vec<Parameter>,
    pub parameter_search: Vec<usize>,
    pub download: Vec<(String, String)>,
    pub sku_label: Option<String>,
    pub price_label: Option<String>,
    /// "YYYY/MM/DD", or empty for no date.
    pub new_date: String,
    pub status: Status,
    pub page_break: bool,
    pub prj_landing_page: bool,
    /// subcategory.output: "" lists parts; "configurator" and "smart" show only the CAD configurator.
    pub output: String,
    pub industry: Vec<String>,
    pub portal: Vec<String>,
    pub leadtime_option: String,
    pub leadtime_text: String,
    pub currency: Vec<(String, f64)>,
    pub lookup: bool,
    pub new_inventory: bool,
}

impl Family {
    pub fn is_metric(&self) -> bool {
        is_metric_name(&self.table_name)
    }

    pub fn parameter(&self, id: usize) -> Option<&Parameter> {
        self.parameters.iter().find(|p| p.id == id)
    }

    /// Parameters that get a search select, in parameter_search order.
    pub fn searchable(&self) -> impl Iterator<Item = &Parameter> {
        self.parameters.iter().filter(|p| self.parameter_search.contains(&p.id))
    }

    /// subcategory_class::leadtime().
    pub fn leadtime(&self) -> String {
        leadtime(&self.leadtime_option, &self.leadtime_text, None)
    }
}

pub fn leadtime(option: &str, text: &str, family: Option<&Family>) -> String {
    match (option, family) {
        ("Subcategory", Some(f)) => f.leadtime(),
        ("Weeks", _) if !text.is_empty() => format!("{text} Week(s)"),
        ("Weeks", _) => String::new(),
        (other, _) => other.to_string(),
    }
}

/// catalog.php is_metric_name(): "(Metric" anywhere, or ISO as a whole upper-case word.
pub fn is_metric_name(name: &str) -> bool {
    name.to_ascii_lowercase().contains("(metric")
        || name.split(|c: char| !c.is_alphanumeric() && c != '_').any(|w| w == "ISO")
}

/// fn_date($d, "mysql"): "2024-05-01" as "2024/05/01", the zero date as "".
pub fn php_date(s: &str) -> String {
    let s = s.trim();
    if s.is_empty() || s == "0" || s == "0000-00-00" || s == "0000/00/00" {
        return String::new();
    }
    let parts: Vec<&str> = s.split(|c| " /.-".contains(c)).collect();
    format!("{}/{}/{}", parts.first().unwrap_or(&""), parts.get(1).unwrap_or(&""), parts.get(2).unwrap_or(&""))
}

/// One `inventory` row.
#[derive(Debug, Clone, PartialEq)]
pub struct Part {
    pub id: i64,
    pub sku: String,
    pub category_id: i64,
    pub subcategory_id: i64,
    pub prj: String,
    pub pricing: String,
    pub sort_order: i64,
    pub new_date: String,
    pub competitor_sku: Vec<String>,
    pub leadtime_option: String,
    pub leadtime_text: String,
    pub parameter: [String; PARAMETERS],
    pub nparameter: [f64; PARAMETERS],
}

impl Part {
    pub fn leadtime(&self, family: &Family) -> String {
        leadtime(&self.leadtime_option, &self.leadtime_text, Some(family))
    }
}

/// Everything the catalog views read about categories and families, for one language.
#[derive(Debug, Clone, Default)]
pub struct Catalog {
    pub categories: BTreeMap<i64, Category>,
    pub families: BTreeMap<i64, Family>,
    /// inventory_class::new_date(): items dated on or after this ("YYYY/MM/DD") are new.
    pub new_cutoff: String,
}

impl Catalog {
    pub fn is_new_date(&self, date: &str) -> bool {
        !date.is_empty() && date >= self.new_cutoff.as_str()
    }

    pub fn family(&self, id: i64) -> Option<&Family> {
        self.families.get(&id)
    }

    pub fn category_name(&self, id: i64) -> &str {
        self.categories.get(&id).map_or("", |c| c.name.as_str())
    }
}

fn list_of(v: Option<Value>) -> Vec<(String, String)> {
    v.map(|v| v.entries().iter().map(|(k, v)| (k.as_string(), v.text())).collect()).unwrap_or_default()
}

fn carets(s: Option<&str>) -> Vec<String> {
    match s {
        Some(s) if s.len() >= 2 => s[1..s.len() - 1].split('^').map(str::to_string).collect(),
        _ => Vec::new(),
    }
}

pub async fn load(pool: &SqlitePool, lang: Lang, registry: &Registry, today: Day) -> sqlx::Result<Catalog> {
    let new_days: i64 = registry.get("local", "new_days").trim().parse().unwrap_or(0);
    let cutoff = today.minus_days(new_days);
    let cutoff_sql = cutoff.dashed();
    let mut catalog = Catalog { new_cutoff: cutoff.slashed(), ..Catalog::default() };

    for r in sqlx::query!(r#"SELECT id AS "id!: i64", name, sort_order AS "sort_order!: i64", active AS "active: i64" FROM category"#)
        .fetch_all(pool)
        .await?
    {
        catalog.categories.insert(r.id, Category { id: r.id, name: r.name.unwrap_or_default(), sort_order: r.sort_order, active: r.active.unwrap_or(0) != 0 });
    }

    let new_inventory: HashSet<i64> = sqlx::query_scalar!(
        r#"SELECT subcategory_id AS "id!: i64" FROM inventory WHERE new_date >= ?1 AND active = 1"#,
        cutoff_sql
    )
    .fetch_all(pool)
    .await?
    .into_iter()
    .collect();

    for r in sqlx::query!(
        r#"SELECT id AS "id!: i64", category_id AS "category_id: i64", name, prj, image_url, thumbnail_url, sketch_url, parameters,
                  parameter_search, download, options, new_date AS "new_date!", status AS "status!", page_break AS "page_break: i64",
                  prj_landing_page AS "prj_landing_page: i64", output AS "output!", industry, portal, leadtime_option AS "leadtime_option!",
                  leadtime_text, currency, lookup AS "lookup: i64", sort_order AS "sort_order: i64"
           FROM subcategory"#
    )
    .fetch_all(pool)
    .await?
    {
        let parameters: Vec<Parameter> = r
            .parameters
            .as_deref()
            .and_then(php::unserialize)
            .map(|v| v.entries().iter().filter_map(|(_, p)| Parameter::parse(p)).collect())
            .unwrap_or_default();
        let options = r.options.as_deref().filter(|s| !s.is_empty()).and_then(php::unserialize);
        let label = |key: &str| options.as_ref().and_then(|o| o.get(key)).map(Value::text);
        let currency = r
            .currency
            .as_deref()
            .filter(|s| !s.is_empty())
            .and_then(|s| serde_json::from_str::<serde_json::Map<String, serde_json::Value>>(s).ok())
            .map(|m| m.into_iter().map(|(k, v)| (k, v.as_f64().or_else(|| v.as_str().and_then(|s| s.parse().ok())).unwrap_or(0.0))).collect())
            .unwrap_or_default();
        let name = r.name.unwrap_or_default();
        catalog.families.insert(
            r.id,
            Family {
                id: r.id,
                category_id: r.category_id.unwrap_or(0),
                sort_order: r.sort_order.unwrap_or(0),
                name: name.clone(),
                table_name: name,
                description: String::new(),
                prj: r.prj.unwrap_or_default(),
                image_url: r.image_url.unwrap_or_default(),
                thumbnail_url: r.thumbnail_url.unwrap_or_default(),
                sketch_url: r.sketch_url.unwrap_or_default(),
                parameters,
                parameter_search: r
                    .parameter_search
                    .as_deref()
                    .filter(|s| !s.is_empty())
                    .map(|s| s.split('^').filter_map(|p| p.trim().parse().ok()).collect())
                    .unwrap_or_default(),
                download: list_of(r.download.as_deref().filter(|s| !s.is_empty()).and_then(php::unserialize)),
                sku_label: label("sku"),
                price_label: label("price"),
                new_date: php_date(&r.new_date),
                status: Status::parse(&r.status),
                page_break: r.page_break.unwrap_or(1) != 0,
                prj_landing_page: r.prj_landing_page.unwrap_or(0) != 0,
                output: r.output,
                industry: carets(r.industry.as_deref()),
                portal: carets(r.portal.as_deref()),
                leadtime_option: r.leadtime_option,
                leadtime_text: r.leadtime_text.unwrap_or_default(),
                currency,
                lookup: r.lookup.unwrap_or(1) != 0,
                new_inventory: new_inventory.contains(&r.id),
            },
        );
    }

    let code = lang.code();
    let rows = sqlx::query!(
        r#"SELECT record_type AS "record_type!", record_id AS "record_id!: i64", language_code AS "language_code!", name AS "name!", description
           FROM content
           WHERE record_type IN ('category', 'subcategory') AND record_item = '' AND language_code IN ('EN', ?1)
           ORDER BY record_type, CASE WHEN language_code = 'EN' THEN 2 ELSE 1 END, id"#,
        code
    )
    .fetch_all(pool)
    .await?;
    let mut seen: HashSet<(String, i64)> = HashSet::new();
    for r in rows {
        if !seen.insert((r.record_type.clone(), r.record_id)) {
            continue;
        }
        match r.record_type.as_str() {
            "category" => {
                if let Some(c) = catalog.categories.get_mut(&r.record_id) {
                    c.name = r.name;
                }
            }
            _ => {
                if let Some(f) = catalog.families.get_mut(&r.record_id) {
                    f.name = r.name;
                    f.description = r.description.unwrap_or_default();
                }
            }
        }
    }
    Ok(catalog)
}

macro_rules! parts_query {
    ($pool:expr, $sql_where:literal, $($arg:expr),*) => {
        sqlx::query!(
            r#"SELECT id AS "id!: i64", sku, category_id, subcategory_id, prj, pricing, sort_order AS "sort_order!: i64",
                      new_date AS "new_date!", competitor_sku, leadtime_option AS "leadtime_option!", leadtime_text,
                      parameter0, parameter1, parameter2, parameter3, parameter4, parameter5, parameter6, parameter7, parameter8,
                      parameter9, parameter10, parameter11, parameter12, parameter13, parameter14, parameter15, parameter16,
                      parameter17, parameter18, parameter19,
                      nparameter0 AS n0, nparameter1 AS n1, nparameter2 AS n2, nparameter3 AS n3, nparameter4 AS n4,
                      nparameter5 AS n5, nparameter6 AS n6, nparameter7 AS n7, nparameter8 AS n8, nparameter9 AS n9,
                      nparameter10 AS n10, nparameter11 AS n11, nparameter12 AS n12, nparameter13 AS n13, nparameter14 AS n14,
                      nparameter15 AS n15, nparameter16 AS n16, nparameter17 AS n17, nparameter18 AS n18, nparameter19 AS n19
               FROM inventory "# + $sql_where,
            $($arg),*
        )
        .fetch_all($pool)
        .await
        .map(|rows| {
            rows.into_iter()
                .map(|r| Part {
                    id: r.id,
                    sku: r.sku,
                    category_id: r.category_id.unwrap_or(0),
                    subcategory_id: r.subcategory_id.unwrap_or(0),
                    prj: r.prj.unwrap_or_default(),
                    pricing: r.pricing.unwrap_or_default(),
                    sort_order: r.sort_order,
                    new_date: php_date(&r.new_date),
                    competitor_sku: r
                        .competitor_sku
                        .filter(|s| !s.is_empty())
                        .map(|s| s.split(',').map(str::to_string).collect())
                        .unwrap_or_default(),
                    leadtime_option: r.leadtime_option,
                    leadtime_text: r.leadtime_text.unwrap_or_default(),
                    parameter: [
                        r.parameter0, r.parameter1, r.parameter2, r.parameter3, r.parameter4, r.parameter5, r.parameter6,
                        r.parameter7, r.parameter8, r.parameter9, r.parameter10, r.parameter11, r.parameter12, r.parameter13,
                        r.parameter14, r.parameter15, r.parameter16, r.parameter17, r.parameter18, r.parameter19,
                    ],
                    nparameter: [
                        r.n0, r.n1, r.n2, r.n3, r.n4, r.n5, r.n6, r.n7, r.n8, r.n9, r.n10, r.n11, r.n12, r.n13, r.n14, r.n15,
                        r.n16, r.n17, r.n18, r.n19,
                    ]
                    .map(|n| n.unwrap_or(0.0)),
                })
                .collect::<Vec<Part>>()
        })
    };
}

/// The active parts of a family in catalog order (sort_order, then part number).
pub async fn family_parts(pool: &SqlitePool, family_id: i64) -> sqlx::Result<Vec<Part>> {
    parts_query!(pool, "WHERE subcategory_id = ?1 AND active = 1 ORDER BY sort_order, sku COLLATE NOCASE", family_id)
}

/// inventory_class::read($sku, "sku"): the part with this number, active or not.
pub async fn part_by_sku(pool: &SqlitePool, sku: &str) -> sqlx::Result<Option<Part>> {
    Ok(parts_query!(pool, "WHERE sku = ?1 COLLATE NOCASE ORDER BY id LIMIT 1", sku)?.into_iter().next())
}

pub async fn part_by_id(pool: &SqlitePool, id: i64) -> sqlx::Result<Option<Part>> {
    Ok(parts_query!(pool, "WHERE id = ?1", id)?.into_iter().next())
}

/// A calendar day, counted from 1970-01-01, for the "new item" cut-off the PHP took from date().
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Day(i64);

impl Day {
    pub fn from_unix(secs: i64) -> Day {
        Day(secs.div_euclid(86_400))
    }

    pub fn minus_days(self, n: i64) -> Day {
        Day(self.0 - n)
    }

    fn civil(self) -> (i64, i64, i64) {
        let z = self.0 + 719_468;
        let era = z.div_euclid(146_097);
        let doe = z - era * 146_097;
        let yoe = (doe - doe / 1460 + doe / 36_524 - doe / 146_096) / 365;
        let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
        let mp = (5 * doy + 2) / 153;
        let d = doy - (153 * mp + 2) / 5 + 1;
        let m = if mp < 10 { mp + 3 } else { mp - 9 };
        (yoe + era * 400 + i64::from(m <= 2), m, d)
    }

    pub fn slashed(self) -> String {
        let (y, m, d) = self.civil();
        format!("{y:04}/{m:02}/{d:02}")
    }

    pub fn dashed(self) -> String {
        let (y, m, d) = self.civil();
        format!("{y:04}-{m:02}-{d:02}")
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn metric_names() {
        assert!(is_metric_name("Ball Lock Pins (Metric)"));
        assert!(is_metric_name("Shoulder Bolts ISO"));
        assert!(!is_metric_name("Isolation Mounts"));
        assert!(!is_metric_name("Ball Lock Pins"));
    }

    #[test]
    fn parameters_parse_from_the_serialized_column() {
        let v = php::unserialize(r#"a:2:{i:0;O:27:"subcategory_parameter_class":6:{s:2:"id";i:0;s:4:"name";s:9:" Diameter";s:4:"type";s:9:"Decimal:3";s:7:"numeric";b:1;s:7:"decimal";s:1:"3";s:9:"hyperlink";i:0;}i:3;O:27:"subcategory_parameter_class":6:{s:2:"id";i:3;s:4:"name";s:4:"Mate";s:4:"type";s:9:"Uppercase";s:7:"numeric";b:0;s:7:"decimal";i:0;s:9:"hyperlink";i:1;}}"#).unwrap();
        let ps: Vec<Parameter> = v.entries().iter().filter_map(|(_, p)| Parameter::parse(p)).collect();
        assert_eq!(ps[0], Parameter { id: 0, name: "Diameter".into(), kind: ParamKind::Decimal(3), hyperlink: false });
        assert_eq!(ps[1], Parameter { id: 3, name: "Mate".into(), kind: ParamKind::Text, hyperlink: true });
        assert_eq!(ps[0].output(0.5), "0.500");
        let n = Parameter { id: 1, name: "L".into(), kind: ParamKind::Number, hyperlink: false };
        assert_eq!(n.output(12.0), "12");
        assert_eq!(n.output(0.25), "0.25");
    }

    #[test]
    fn dates_and_lead_times() {
        assert_eq!(php_date("2024-05-01"), "2024/05/01");
        assert_eq!(php_date("0000-00-00"), "");
        assert_eq!(Day::from_unix(1_714_521_600).slashed(), "2024/05/01");
        assert_eq!(Day::from_unix(1_714_521_600).minus_days(31).dashed(), "2024-03-31");
        assert_eq!(leadtime("Weeks", "3", None), "3 Week(s)");
        assert_eq!(leadtime("In Stock", "", None), "In Stock");
        assert_eq!(leadtime("Weeks", "", None), "");
    }
}
