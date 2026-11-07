//! What a `user`, `subcategory` or `inventory` row may hold: one check per table, which the import (data.rs) and
//! the table admin (admin.rs) both run before they save. A check reads the row as it would be stored, puts what it
//! reads into the form the import stores (a country's code for its name, a currency code in capitals, a lead time
//! as listed, a family's category on its parts) and says what is wrong, by column.

use std::collections::{HashMap, HashSet};

use sqlx::SqlitePool;

use super::{csrf::php_intval, map};
use crate::{identity::Role, pricing};

pub(super) const INVENTORY_LEADTIME: [&str; 4] = ["Subcategory", "In Stock", "Weeks", "Call"];
pub(super) const SUBCATEGORY_LEADTIME: [&str; 3] = ["In Stock", "Weeks", "Call"];
pub const STATUSES: [&str; 3] = ["Pending", "Active", "Denied"];
const PRICE_TYPES: [&str; 4] = ["", "base", "discount", "plus"];

/// What is wrong with a row: the column (or, for a rule over several, the import's name for the rule) and why.
pub(super) type Errors = Vec<(&'static str, String)>;

/// A row by column as text, `None` for NULL: the stored row with what the save sets over it, and which columns the
/// save sets. A check normalizes the columns it reads in place, and a column it fills in counts as set.
#[derive(Debug, Default, Clone)]
pub(super) struct Row {
    values: HashMap<String, Option<String>>,
    given: HashSet<String>,
}

impl Row {
    /// A stored value the save leaves as it is.
    pub fn keep(&mut self, col: &str, value: Option<String>) {
        self.values.insert(col.to_string(), value);
    }

    /// A value the save sets.
    pub fn give(&mut self, col: &str, value: Option<String>) {
        self.values.insert(col.to_string(), value);
        self.given.insert(col.to_string());
    }

    pub fn given(&self, col: &str) -> bool {
        self.given.contains(col)
    }

    pub fn value(&self, col: &str) -> Option<&str> {
        self.values.get(col).and_then(|v| v.as_deref())
    }

    /// The value, "" for NULL or a column the row does not have.
    pub fn get(&self, col: &str) -> &str {
        self.value(col).unwrap_or("")
    }

    pub fn int(&self, col: &str) -> i64 {
        php_intval(self.get(col))
    }

    pub fn real(&self, col: &str) -> f64 {
        map::floatval(self.get(col))
    }

    pub fn flag(&self, col: &str) -> bool {
        map::truthy(self.get(col))
    }

    /// The columns the save sets, with their values.
    pub fn sets(&self) -> impl Iterator<Item = (&str, Option<&str>)> {
        self.given.iter().map(|c| (c.as_str(), self.value(c)))
    }

    /// Sets the column to its normalized value; a value that reads the same (NULL for "") is left as it is.
    fn set(&mut self, col: &str, value: impl Into<String>) {
        let value = value.into();
        if self.get(col) != value {
            self.values.insert(col.to_string(), Some(value));
        }
        self.given.insert(col.to_string());
    }

    fn clear(&mut self, col: &str) {
        self.values.insert(col.to_string(), None);
        self.given.insert(col.to_string());
    }
}

/// One row of the `country` table: a country (`state` "") or a US or Canadian state.
#[derive(Debug, Clone)]
struct Place {
    country: String,
    state: String,
    name: String,
    alias: String,
}

/// What the checks look up: the codes and ids a row may name. Only what the table's check reads is loaded.
#[derive(Debug, Default)]
pub(super) struct Lookups {
    currencies: Vec<String>,
    languages: Vec<String>,
    portals: Vec<i64>,
    documents: Vec<i64>,
    industries: Vec<(i64, String)>,
    places: Vec<Place>,
    categories: Vec<i64>,
    /// Each family's category, 0 for none.
    families: HashMap<i64, i64>,
}

impl Lookups {
    pub async fn load(pool: &SqlitePool, table: &str) -> sqlx::Result<Lookups> {
        let mut l = Lookups::default();
        match table {
            "user" => {
                l.currencies = sqlx::query_scalar!(r#"SELECT code AS "code!: String" FROM currency"#).fetch_all(pool).await?;
                l.languages = sqlx::query_scalar!(r#"SELECT code AS "code!: String" FROM language"#).fetch_all(pool).await?;
                l.portals = std::iter::once(0).chain(sqlx::query_scalar!(r#"SELECT id AS "id!: i64" FROM portal"#).fetch_all(pool).await?).collect();
                l.documents = std::iter::once(0)
                    .chain(sqlx::query_scalar!(r#"SELECT id AS "id!: i64" FROM document WHERE parent_id <> 0 AND active = 1"#).fetch_all(pool).await?)
                    .collect();
                l.industries = sqlx::query!(r#"SELECT id AS "id!: i64", name FROM industry"#).fetch_all(pool).await?.into_iter().map(|r| (r.id, r.name)).collect();
                l.places = sqlx::query!(r#"SELECT country, state, COALESCE(name, '') AS "name!: String", COALESCE(alias, '') AS "alias!: String" FROM country"#)
                    .fetch_all(pool)
                    .await?
                    .into_iter()
                    .map(|r| Place { country: r.country, state: r.state, name: r.name, alias: r.alias })
                    .collect();
            }
            "subcategory" => l.categories = sqlx::query_scalar!(r#"SELECT id AS "id!: i64" FROM category"#).fetch_all(pool).await?,
            "inventory" => {
                l.families = sqlx::query!(r#"SELECT id AS "id!: i64", COALESCE(category_id, 0) AS "category_id!: i64" FROM subcategory"#)
                    .fetch_all(pool)
                    .await?
                    .into_iter()
                    .map(|r| (r.id, r.category_id))
                    .collect();
            }
            _ => {}
        }
        Ok(l)
    }

    /// country->find_country(): the country by code, name or one of its aliases (stored `^alias^alias^`), in any
    /// case; "" for none.
    fn country(&self, code: &str) -> String {
        if code.is_empty() || code.contains('^') {
            return String::new();
        }
        let alias = format!("^{}^", code.to_lowercase());
        let countries = || self.places.iter().filter(|p| p.state.is_empty());
        let found = countries()
            .find(|p| p.country.eq_ignore_ascii_case(code))
            .or_else(|| countries().find(|p| p.name.eq_ignore_ascii_case(code)))
            .or_else(|| countries().find(|p| p.alias.to_ascii_lowercase().contains(&alias)));
        found.map(|p| p.country.clone()).unwrap_or_default()
    }

    /// country->find_state(): a US or Canadian state by code or name in any case, "" for none; elsewhere the text
    /// as given.
    fn state(&self, country: &str, code: &str) -> String {
        if code.is_empty() {
            return String::new();
        }
        if !["US", "CA"].contains(&country) {
            return code.to_string();
        }
        let states = || self.places.iter().filter(|p| p.country == country && !p.state.is_empty());
        let found = states().find(|p| p.state.eq_ignore_ascii_case(code)).or_else(|| states().find(|p| p.name.eq_ignore_ascii_case(code)));
        found.map(|p| p.state.clone()).unwrap_or_default()
    }
}

/// import_match(): the list's spelling of a value, ignoring case; "" when it is not in the list.
pub(super) fn import_match<'a>(value: &str, list: &[&'a str]) -> &'a str {
    list.iter().find(|l| l.eq_ignore_ascii_case(value)).copied().unwrap_or("")
}

/// prj_verify(): empty, or a query string with `info`.
pub(super) fn prj_ok(prj: &str) -> bool {
    prj.is_empty() || form_urlencoded::parse(prj.as_bytes()).any(|(k, _)| k == "info")
}

/// competitor_sku(…, TRUE): the comma list as sorted, unique part numbers, and what is wrong with it.
pub(super) fn competitor_list(input: &str) -> (Vec<String>, Option<&'static str>) {
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

/// What is wrong with a lead time, and the column it is on: the option must be one of `allowed` (never blank),
/// Weeks needs a text and the other options none.
pub(super) fn leadtime_error(option: &str, text: &str, allowed: &[&str]) -> Option<(&'static str, String)> {
    if !allowed.contains(&option) {
        Some(("leadtime_option", format!("Allowed values: {}", allowed.join(", "))))
    } else if option == "Weeks" && text.is_empty() {
        Some(("leadtime_text", "Cannot be blank".into()))
    } else if option != "Weeks" && !text.is_empty() {
        Some(("leadtime_text", "Must be blank".into()))
    } else {
        None
    }
}

/// An address the account is found by: IPv4 written as `long2ip(ip2long())` writes it back.
fn canonical_ip(ip: &str) -> bool {
    ip.parse::<std::net::Ipv4Addr>().is_ok_and(|a| !a.is_unspecified() && a.to_string() == ip)
}

fn invalid(raw: &str) -> String {
    format!("Invalid: {raw}")
}

/// The table's check, for the tables that have one.
pub(super) fn check(table: &str, r: &mut Row, l: &Lookups) -> Errors {
    match table {
        "user" => account(r, l),
        "subcategory" => family(r, l),
        "inventory" => part(r, l),
        _ => Errors::new(),
    }
}

pub(super) fn checks(table: &str) -> bool {
    matches!(table, "user" | "subcategory" | "inventory")
}

/// user->import_update(): an account's columns against the lookups and each other. A rule over several columns
/// runs when the save sets any of them.
pub(super) fn account(r: &mut Row, l: &Lookups) -> Errors {
    let mut e = Errors::new();
    if r.given("ip") && !r.get("ip").is_empty() && !canonical_ip(r.get("ip")) {
        e.push(("ip", invalid(r.get("ip"))));
    }
    if r.given("company_name") && r.get("company_name").is_empty() {
        e.push(("company_name", "Required".into()));
    }
    let mut unknown_country = false;
    if r.given("country_code") {
        let raw = r.get("country_code").to_string();
        let code = l.country(&raw);
        unknown_country = !raw.is_empty() && code.is_empty();
        if unknown_country {
            e.push(("country_code", invalid(&raw)));
        }
        r.set("country_code", code);
    }
    if r.given("country_code") || r.given("state") {
        let raw = r.get("state").to_string();
        let state = l.state(r.get("country_code"), &raw);
        if !raw.is_empty() && (state.is_empty() || unknown_country) {
            e.push(("state", invalid(&raw)));
        }
        r.set("state", state);
    }
    for col in ["zip", "customer_code"] {
        if r.given(col) {
            let upper = r.get(col).to_uppercase();
            r.set(col, upper);
        }
    }
    let roles: Vec<&str> = Role::ALL.iter().map(|r| r.as_str()).collect();
    for (col, list) in [("status", &STATUSES[..]), ("type", &roles[..])] {
        if r.given(col) {
            let found = import_match(r.get(col), list);
            if found.is_empty() {
                e.push((col, invalid(r.get(col))));
            }
            r.set(col, found);
        }
    }
    if r.given("price_type") && !PRICE_TYPES.contains(&r.get("price_type")) {
        e.push(("price_type", invalid(r.get("price_type"))));
    }
    for (col, allowed) in [("login_document_id", &l.documents), ("landing_document_id", &l.documents), ("portal_id", &l.portals)] {
        if r.given(col) {
            let id = r.int(col);
            if !allowed.contains(&id) {
                e.push((col, invalid(r.get(col))));
            }
            r.set(col, id.to_string());
        }
    }
    if r.given("language_code") {
        let languages: Vec<&str> = l.languages.iter().map(String::as_str).collect();
        let found = import_match(r.get("language_code"), &languages);
        if found.is_empty() {
            e.push(("language_code", invalid(r.get("language_code"))));
        }
        r.set("language_code", found);
    }
    if r.given("currency_code") {
        let code = r.get("currency_code").to_uppercase();
        if !l.currencies.contains(&code) {
            e.push(("currency_code", invalid(r.get("currency_code"))));
        }
        r.set("currency_code", code);
    }
    if r.given("industry_name") {
        let name = r.get("industry_name").to_string();
        let id = l.industries.iter().find(|(_, n)| n.eq_ignore_ascii_case(&name)).map_or(0, |(id, _)| *id);
        if !name.is_empty() && id == 0 {
            e.push(("industry_name", invalid(&name)));
        }
        r.set("industry_id", id.to_string());
    } else if r.given("industry_id") {
        let id = r.int("industry_id");
        if id != 0 && !l.industries.iter().any(|(i, _)| *i == id) {
            e.push(("industry_id", invalid(r.get("industry_id"))));
        }
        r.set("industry_id", id.to_string());
    }
    let any = |cols: &[&str]| cols.iter().any(|c| r.given(c));
    let catalog = r.flag("catalog");
    if any(&["catalog", "price_type"]) && !catalog && !r.get("price_type").is_empty() {
        e.push(("pricing", "Pricing requires catalog access".into()));
    }
    if any(&["catalog", "cad"]) && !catalog && r.flag("cad") {
        e.push(("cad", "CAD requires catalog access".into()));
    }
    if any(&["portal_id", "login_document_id", "landing_document_id"]) && r.int("portal_id") != 0 && (r.int("login_document_id") != 0 || r.int("landing_document_id") != 0) {
        e.push(("portal_id", "Landing documents not allowed when portal is selected".into()));
    }
    let terms = match (r.given("price_type"), r.given("price_pct")) {
        (false, false) => None,
        (false, true) => Some(("price_type", "Required field")),
        (true, false) => Some(("price_pct", "Required field")),
        (true, true) => pricing::terms_error(r.get("price_type"), r.real("price_pct")).map(|m| ("price_pct", m)),
    };
    e.extend(terms.map(|(c, m)| (c, m.to_string())));
    e
}

/// The option as listed and its text, held to `leadtime_error`.
fn leadtime(r: &mut Row, allowed: &[&'static str], e: &mut Errors) {
    let option = import_match(r.get("leadtime_option"), allowed);
    r.set("leadtime_option", option);
    e.extend(leadtime_error(option, r.get("leadtime_text"), allowed));
}

fn whole_number(r: &mut Row, col: &str) {
    if r.given(col) {
        let n = r.int(col);
        r.set(col, n.to_string());
    }
}

/// subcategory->import_update() and the family page: a project with `info` (none is NULL), a lead time, a whole
/// sort order (blank is 0) and a category.
pub(super) fn family(r: &mut Row, l: &Lookups) -> Errors {
    let mut e = Errors::new();
    if r.given("prj") {
        if !prj_ok(r.get("prj")) {
            e.push(("prj", format!("Malformed PRJ {}", r.get("prj"))));
        }
        if r.get("prj").is_empty() {
            r.clear("prj");
        }
    }
    if r.given("leadtime_option") {
        leadtime(r, &SUBCATEGORY_LEADTIME, &mut e);
    }
    whole_number(r, "sort_order");
    if r.given("category_id") {
        match r.int("category_id") {
            0 => e.push(("category_id", "Required".into())),
            id if !l.categories.contains(&id) => e.push(("category_id", invalid(r.get("category_id")))),
            id => r.set("category_id", id.to_string()),
        }
    }
    e
}

/// inventory->import_update_inventory() and the part page: a family (whose category the part takes), competitor
/// part numbers as a sorted list, a lead time and a whole sort order.
pub(super) fn part(r: &mut Row, l: &Lookups) -> Errors {
    let mut e = Errors::new();
    if r.given("subcategory_id") {
        let id = r.int("subcategory_id");
        match l.families.get(&id) {
            _ if id == 0 => e.push(("subcategory_id", "Required".into())),
            None => e.push(("subcategory_id", format!("Invalid subcategory: {id}"))),
            Some(&category) => {
                r.set("subcategory_id", id.to_string());
                r.set("category_id", category.to_string());
            }
        }
    }
    if r.given("competitor_sku") {
        let (list, short) = competitor_list(r.get("competitor_sku"));
        r.set("competitor_sku", list.join(","));
        e.extend(short.map(|m| ("competitor_sku", m.to_string())));
    }
    if r.given("leadtime_option") {
        leadtime(r, &INVENTORY_LEADTIME, &mut e);
    }
    whole_number(r, "sort_order");
    e
}

#[cfg(test)]
mod tests {
    use super::*;

    fn row(given: &[(&str, &str)]) -> Row {
        let mut r = Row::default();
        for (c, v) in given {
            r.give(c, Some(v.to_string()));
        }
        r
    }

    fn lookups() -> Lookups {
        let place = |country: &str, state: &str, name: &str, alias: &str| Place { country: country.into(), state: state.into(), name: name.into(), alias: alias.into() };
        Lookups {
            currencies: vec!["USD".into(), "CAD".into()],
            languages: vec!["EN".into(), "FR".into()],
            portals: vec![0, 4],
            documents: vec![0, 9],
            industries: vec![(3, "Aerospace".into())],
            places: vec![place("US", "", "United States", "^usa^"), place("CA", "", "Canada", ""), place("CA", "ON", "Ontario", ""), place("US", "MI", "Michigan", "")],
            categories: vec![1],
            families: HashMap::from([(10, 1), (11, 0)]),
        }
    }

    #[test]
    fn competitor_lists_sort_and_flag_short_numbers() {
        assert_eq!(competitor_list("b-200, a-100,,A-100"), (vec!["A-100".to_string(), "B-200".to_string()], None));
        assert_eq!(competitor_list("ab").1, Some("Must be at least 3 characters"));
    }

    #[test]
    fn prj_needs_info() {
        assert!(prj_ok(""));
        assert!(prj_ok("info=harcourt/x.prj&x=1"));
        assert!(!prj_ok("x=1"));
    }

    #[test]
    fn an_account_takes_codes_for_names_and_capitals_for_codes() {
        let l = lookups();
        let mut r = row(&[
            ("ip", "192.0.2.7"),
            ("country_code", "usa"),
            ("state", "michigan"),
            ("zip", "k1a 0b1"),
            ("customer_code", "ab1"),
            ("status", "active"),
            ("type", "super user"),
            ("language_code", "fr"),
            ("currency_code", "cad"),
            ("portal_id", "4"),
            ("landing_document_id", "0"),
        ]);
        assert_eq!(account(&mut r, &l), []);
        let got: Vec<&str> = ["country_code", "state", "zip", "customer_code", "status", "type", "language_code", "currency_code"].iter().map(|c| r.get(c)).collect();
        assert_eq!(got, ["US", "MI", "K1A 0B1", "AB1", "Active", "Super User", "FR", "CAD"]);
    }

    #[test]
    fn an_account_refuses_what_the_import_refuses() {
        let l = lookups();
        let errors = |pairs: &[(&str, &str)]| account(&mut row(pairs), &l).into_iter().map(|(c, m)| format!("{c}: {m}")).collect::<Vec<_>>();
        assert_eq!(errors(&[("ip", "010.0.0.1")]), ["ip: Invalid: 010.0.0.1"]);
        assert_eq!(errors(&[("ip", "0.0.0.0")]), ["ip: Invalid: 0.0.0.0"]);
        assert_eq!(errors(&[("ip", "")]), Vec::<String>::new(), "no company address");
        assert_eq!(errors(&[("company_name", "")]), ["company_name: Required"]);
        assert_eq!(errors(&[("country_code", "Atlantis"), ("state", "MI")]), ["country_code: Invalid: Atlantis", "state: Invalid: MI"]);
        assert_eq!(errors(&[("country_code", "CA"), ("state", "Nowhere")]), ["state: Invalid: Nowhere"]);
        assert_eq!(errors(&[("status", "Archived"), ("type", "Boss")]), ["status: Invalid: Archived", "type: Invalid: Boss"]);
        assert_eq!(errors(&[("language_code", "DE"), ("currency_code", "eur")]), ["language_code: Invalid: DE", "currency_code: Invalid: eur"]);
        assert_eq!(errors(&[("login_document_id", "8")]), ["login_document_id: Invalid: 8"]);
        assert_eq!(errors(&[("portal_id", "5")]), ["portal_id: Invalid: 5"]);
        assert_eq!(errors(&[("portal_id", "4"), ("landing_document_id", "9")]), ["portal_id: Landing documents not allowed when portal is selected"]);
        assert_eq!(errors(&[("industry_id", "8")]), ["industry_id: Invalid: 8"]);
        assert_eq!(errors(&[("industry_name", "Mining")]), ["industry_name: Invalid: Mining"]);
        assert_eq!(errors(&[("catalog", "0"), ("price_type", "base"), ("price_pct", "0"), ("cad", "1")]), ["pricing: Pricing requires catalog access", "cad: CAD requires catalog access"]);
        assert_eq!(errors(&[("catalog", "1"), ("price_type", "markup"), ("price_pct", "0")]), ["price_type: Invalid: markup"]);
        assert_eq!(errors(&[("catalog", "1"), ("price_type", "discount"), ("price_pct", "0.1")]), Vec::<String>::new());
        assert_eq!(errors(&[("catalog", "1"), ("price_type", "discount"), ("price_pct", "10")]), ["price_pct: Must be > 0% and < 100%"]);
        assert_eq!(errors(&[("price_type", "")]), ["price_pct: Required field"]);
    }

    #[test]
    fn a_rule_over_columns_the_save_leaves_reads_them_as_stored() {
        let l = lookups();
        let mut r = row(&[("price_type", "base"), ("price_pct", "0")]);
        r.keep("catalog", Some("0".into()));
        assert_eq!(account(&mut r, &l), [("pricing", "Pricing requires catalog access".to_string())]);
        let mut r = row(&[("state", "ontario")]);
        r.keep("country_code", Some("CA".into()));
        assert_eq!(account(&mut r, &l), []);
        assert_eq!(r.get("state"), "ON");
        let mut r = row(&[("country_code", "usa")]);
        r.keep("state", Some("ON".into()));
        assert_eq!(account(&mut r, &l), [("state", "Invalid: ON".to_string())]);
        let mut r = row(&[("country_code", "canada")]);
        r.keep("state", Some("on".into()));
        assert_eq!(account(&mut r, &l), []);
        assert_eq!((r.get("country_code"), r.get("state")), ("CA", "ON"));
        let mut r = row(&[("country_code", "CA")]);
        r.keep("state", None);
        assert_eq!(account(&mut r, &l), []);
        assert_eq!(r.value("state"), None);
    }

    #[test]
    fn a_family_needs_a_category_and_a_project_with_info() {
        let l = lookups();
        let mut r = row(&[("prj", ""), ("leadtime_option", "weeks"), ("leadtime_text", "4"), ("sort_order", ""), ("category_id", "1")]);
        assert_eq!(family(&mut r, &l), []);
        assert_eq!((r.value("prj"), r.get("leadtime_option"), r.get("sort_order")), (None, "Weeks", "0"));
        let mut r = row(&[("prj", "x=1"), ("leadtime_option", "Subcategory"), ("category_id", "")]);
        let errors: Vec<&str> = family(&mut r, &l).into_iter().map(|(c, _)| c).collect();
        assert_eq!(errors, ["prj", "leadtime_option", "category_id"]);
        assert_eq!(family(&mut row(&[("category_id", "7")]), &l), [("category_id", "Invalid: 7".to_string())]);
        assert_eq!(family(&mut row(&[("sort_order", "-3")]), &l), []);
    }

    #[test]
    fn a_part_takes_its_familys_category() {
        let l = lookups();
        let mut r = row(&[("subcategory_id", "10"), ("competitor_sku", "b-200, a-100"), ("leadtime_option", "in stock"), ("leadtime_text", "")]);
        r.keep("category_id", Some("4".into()));
        assert_eq!(part(&mut r, &l), []);
        assert_eq!((r.get("category_id"), r.get("competitor_sku"), r.get("leadtime_option")), ("1", "A-100,B-200", "In Stock"));
        assert!(r.given("category_id"), "the category is saved with the family");
        assert_eq!(part(&mut row(&[("subcategory_id", "")]), &l), [("subcategory_id", "Required".to_string())]);
        assert_eq!(part(&mut row(&[("subcategory_id", "12")]), &l), [("subcategory_id", "Invalid subcategory: 12".to_string())]);
        assert_eq!(part(&mut row(&[("competitor_sku", "ab")]), &l), [("competitor_sku", "Must be at least 3 characters".to_string())]);
    }

    #[test]
    fn an_unchanged_blank_stays_null() {
        let l = lookups();
        let mut r = Row::default();
        r.give("zip", None);
        r.give("competitor_sku", None);
        assert!(account(&mut r, &l).is_empty() && part(&mut r, &l).is_empty());
        assert_eq!((r.value("zip"), r.value("competitor_sku")), (None, None));
    }
}
