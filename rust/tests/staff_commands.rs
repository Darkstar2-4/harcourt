//! The staff commands (`hxweb export`, `hxweb import`, `hxweb job`, `hxweb remote-grants`) against an in-memory
//! database with synthetic rows.

use std::path::PathBuf;

use hxweb::{
    db,
    staff::cli::{run_on, Done, Fail},
};
use sqlx::SqlitePool;

async fn exec(pool: &SqlitePool, sql: &str) {
    sqlx::query(sql).execute(pool).await.unwrap();
}

async fn seeded() -> SqlitePool {
    let pool = db::open_memory().await.unwrap();
    exec(&pool, "INSERT INTO category (id, name, active, sort_order) VALUES (1, 'Clamps', 1, 1), (2, 'Pins', 1, 2)").await;
    exec(&pool, "INSERT INTO subcategory (id, category_id, name, status, leadtime_option, output) VALUES (10, 1, 'Toggle Clamps', 'Active', 'Call', ''), (11, 2, 'Ball Lock Pins', 'Pending', 'In Stock', '')").await;
    exec(
        &pool,
        "INSERT INTO inventory (id, sku, category_id, subcategory_id, sort_order, active, leadtime_option, new_date, pricing, parameter0, nparameter0, prj)
         VALUES (1, 'TC-100', 1, 10, 2, 1, 'Subcategory', '2024-03-05', 'a:2:{i:0;O:23:\"inventory_pricing_class\":2:{s:5:\"price\";d:12.5;s:9:\"qty_break\";i:0;}i:1;O:23:\"inventory_pricing_class\":2:{s:5:\"price\";d:11;s:9:\"qty_break\";d:10;}}', 'Steel', 1.5, ''),
                (2, 'TC-200', 1, 10, 1, 0, 'Weeks', '0000-00-00', '', 'Brass', 0, 'prjCatalog=x'),
                (3, 'BL-1', 2, 11, 0, 1, 'Call', '0000-00-00', 'a:0:{}', '', 0, '')",
    )
    .await;
    pool
}

/// A file of this test's own, removed when it goes out of scope.
struct Scratch(PathBuf);

impl Scratch {
    fn new(name: &str, body: &[u8]) -> Scratch {
        let path = std::env::temp_dir().join(format!("hx_staff_cmd_{}_{name}", std::process::id()));
        std::fs::write(&path, body).unwrap();
        Scratch(path)
    }

    fn path(&self) -> &str {
        self.0.to_str().unwrap()
    }

    fn text(&self) -> String {
        String::from_utf8_lossy(&std::fs::read(&self.0).unwrap()).trim_start_matches('\u{feff}').to_string()
    }
}

impl Drop for Scratch {
    fn drop(&mut self) {
        let _ = std::fs::remove_file(&self.0);
    }
}

async fn count(pool: &SqlitePool, sql: &str) -> i64 {
    sqlx::query_scalar(sql).fetch_one(pool).await.unwrap()
}

fn printed(r: Result<Done, Fail>) -> String {
    match r {
        Ok(Done::Ok(s) | Done::Rejected(s)) => s,
        Err(e) => panic!("{e:?}"),
    }
}

#[tokio::test]
async fn an_inventory_export_writes_every_column_in_catalog_order() {
    let pool = seeded().await;
    let out = Scratch::new("parts.csv", b"");
    let done = run_on(&pool, &["export", "inventory", "--subcategory", "10", "--out", out.path()]).await;
    assert!(printed(done).ends_with("Rows: 2\n"));
    let text = out.text();
    let mut lines = text.lines();
    assert!(lines.next().unwrap().starts_with("\"Harcourt SKU\",\"Category Name\",\"Subcategory Name\",\"Sort Order\",\"New Date\","));
    assert!(lines.next().unwrap().starts_with("TC-200,Clamps,\"Toggle Clamps\",1,,Weeks,"), "sort order 1 comes first");
    let second = lines.next().unwrap();
    assert!(second.starts_with("TC-100,Clamps,\"Toggle Clamps\",2,03/05/2024,Subcategory,,,Y,Steel,"), "{second}");
    assert!(second.ends_with(&format!(",12.50,10,11.00{}", ",0".repeat(16))), "eight more empty breaks: {second}");
    assert_eq!(count(&pool, "SELECT count(*) FROM log WHERE type = 'Export Table' AND subtype = 'inventory'").await, 1);

    let none = Scratch::new("none.xlsx", b"");
    run_on(&pool, &["export", "inventory", "--no-price", "--out", none.path()]).await.unwrap();
    let sheet = hxweb::staff::map::read_upload("none.xlsx", &std::fs::read(&none.0).unwrap(), None).ok().unwrap();
    let skus: Vec<&str> = sheet.rows[1..].iter().map(|r| r[0].as_str()).collect();
    assert_eq!(skus, ["TC-200", "BL-1"], "parts with no price breaks");
}

#[tokio::test]
async fn an_import_reviews_unless_asked_to_update() {
    let pool = seeded().await;
    let file = Scratch::new("parts.txt", b"Harcourt SKU\tSort Order\tActive\ntc-100\t7\tN\n\nNO-SUCH-SKU\t1\tY\n");
    let review = run_on(&pool, &["import", "inventory", file.path()]).await.unwrap();
    let Done::Rejected(text) = review else { panic!("a rejected line exits 1") };
    assert!(text.contains("Lines: 2 (1 empty)\nLines that pass: 1\nRejected records: 1\n  line 4: sku: Invalid SKU: NO-SUCH-SKU\n"), "{text}");
    assert!(text.ends_with("Review complete: nothing saved (add --update to save)\n"));
    let (sort, active): (i64, i64) = sqlx::query_as("SELECT sort_order, active FROM inventory WHERE sku = 'TC-100'").fetch_one(&pool).await.unwrap();
    assert_eq!((sort, active), (2, 1));

    let text = printed(run_on(&pool, &["import", "inventory", file.path(), "--update"]).await);
    assert!(text.contains("Updated records: 1\n") && text.ends_with("Update complete\n"), "{text}");
    let (sort, active): (i64, i64) = sqlx::query_as("SELECT sort_order, active FROM inventory WHERE sku = 'TC-100'").fetch_one(&pool).await.unwrap();
    assert_eq!((sort, active), (7, 0));
    assert_eq!(count(&pool, "SELECT count(*) FROM log WHERE type = 'Import Table' AND subtype = 'inventory'").await, 1);
}

#[tokio::test]
async fn a_rejected_line_rolls_back_a_subcategory_update_and_the_sort_order_is_a_column() {
    let pool = seeded().await;
    let bad = Scratch::new("lead.txt", b"Subcategory ID\tLeadtime Option\tLeadtime Weeks\tSort Order\n10\tweeks\t4\t15\n11\tsoon\t\t5\n");
    let text = printed(run_on(&pool, &["import", "subcategory", bad.path(), "--update"]).await);
    assert!(text.contains("  line 3: leadtime_option: Allowed values: In Stock, Weeks, Call\n"), "{text}");
    assert!(text.ends_with("Update rolled back: every line must pass for this table\n"));
    let row: (String, i64) = sqlx::query_as("SELECT leadtime_option, sort_order FROM subcategory WHERE id = 10").fetch_one(&pool).await.unwrap();
    assert_eq!(row, ("Call".to_string(), 0));

    let good = Scratch::new("order.csv", b"Subcategory ID,Leadtime Option,Leadtime Weeks,Sort Order\n10,Weeks,4,15\n11,Call,,5\n");
    assert!(matches!(run_on(&pool, &["import", "subcategory", good.path(), "--update"]).await, Ok(Done::Ok(_))));
    let rows: Vec<(i64, String, i64)> = sqlx::query_as("SELECT id, leadtime_option, sort_order FROM subcategory ORDER BY id").fetch_all(&pool).await.unwrap();
    assert_eq!(rows, [(10, "Weeks".to_string(), 15), (11, "Call".to_string(), 5)]);
}

#[tokio::test]
async fn erp_prices_replace_the_breaks_and_xref_lines_can_replace_the_list() {
    let pool = seeded().await;
    let mut header = vec!["Harcourt Part # in Inxsql".to_string(), "Price Code".into(), "List Price".into()];
    for i in 1..=10 {
        header.push(format!("iqty_quantity{i}"));
        header.push(format!("iprc{i}"));
    }
    let mut line = vec!["BL-1".to_string(), "A".into(), "9".into(), "10".into(), "9".into(), "999999".into(), "8".into()];
    line.resize(header.len(), "0".into());
    let erp = Scratch::new("erp.txt", format!("{}\n{}\n", header.join("\t"), line.join("\t")).as_bytes());
    printed(run_on(&pool, &["import", "erp-price", erp.path(), "--update"]).await);
    let pricing: String = sqlx::query_scalar("SELECT pricing FROM inventory WHERE sku = 'BL-1'").fetch_one(&pool).await.unwrap();
    let breaks = hxweb::staff::data::pricing(&pricing);
    assert_eq!(breaks.iter().map(|b| (b.qty.to_string(), b.price.to_string())).collect::<Vec<_>>(), [("0".to_string(), "9".to_string()), ("11".into(), "8".into())]);

    exec(&pool, "INSERT INTO inventory_xref (sku, competitor, competitor_sku, update_timestamp) VALUES ('TC-100', '', 'OLD-1', 1)").await;
    let xr = Scratch::new("xr.txt", b"SKU\tPrice\tRival\tOther\nTC-100\t5\tR-100\t-\n");
    printed(run_on(&pool, &["import", "xref", xr.path(), "--xr", "--replace", "--update"]).await);
    let rows: Vec<(String, String)> = sqlx::query_as("SELECT sku, competitor_sku FROM inventory_xref").fetch_all(&pool).await.unwrap();
    assert_eq!(rows, [("TC-100".to_string(), "R-100".to_string())]);
}

#[tokio::test]
async fn account_imports_check_every_column_against_the_lookups() {
    let pool = seeded().await;
    exec(&pool, "INSERT INTO currency (code, name, exchange_rate) VALUES ('USD', 'US Dollar', 1)").await;
    exec(&pool, "INSERT INTO language (code, name, local_name, priority) VALUES ('EN', 'English', 'English', 1)").await;
    exec(&pool, "INSERT INTO country (country, state, name) VALUES ('US', '', 'United States')").await;
    exec(&pool, r#"INSERT INTO "user" (id, ip, status, "type", company_name, country_code) VALUES (5, '192.0.2.5', 'Pending', 'Customer', 'Example Co', 'US')"#).await;
    let file = Scratch::new("users.txt", b"id\tip\ttype\tstatus\tCompany Name\n5\t192.0.2.5\tBoss\tActive\tExample Co\n5\t192.0.2.5\tCustomer\tActive\tExample Co\n");
    let text = printed(run_on(&pool, &["import", "user", file.path(), "--update"]).await);
    assert!(text.contains("  line 2: type: Invalid: Boss\n"), "{text}");
    let status: String = sqlx::query_scalar(r#"SELECT status FROM "user" WHERE id = 5"#).fetch_one(&pool).await.unwrap();
    assert_eq!(status, "Active");

    let out = Scratch::new("users.csv", b"");
    printed(run_on(&pool, &["export", "user", "--status", "Active", "--role", "Customer", "--out", out.path()]).await);
    assert!(out.text().lines().nth(1).unwrap().starts_with("5,192.0.2.5,\"Example Co\""));
    assert_eq!(run_on(&pool, &["export", "user", "--status", "Denied", "--out", out.path()]).await, Err(Fail::Run("No records found".into())));
}

#[tokio::test]
async fn prj_links_import_from_a_partsolutions_export_and_missing_ones_export() {
    let pool = seeded().await;
    let export = Scratch::new("export.csv", b"Name;a;b;Part;URL\n;;;TC-100_1;https://example.com/p?prjCatalog=h&prjNode=n&prjName=TC&values=v\n;;;NO-SUCH;https://example.com/p?prjCatalog=h&prjNode=n&prjName=NS&values=v\n");
    let text = printed(run_on(&pool, &["import", "prj", export.path()]).await);
    assert!(text.contains("Unique records: 2\nWould change: 1\n  TC-100\nNot Changed: 0\nMissing/Invalid SKU: 1\n  NO-SUCH\n"), "{text}");
    assert_eq!(count(&pool, "SELECT count(*) FROM inventory WHERE prj = ''").await, 2);
    printed(run_on(&pool, &["import", "prj", export.path(), "--update"]).await);
    let prj: String = sqlx::query_scalar("SELECT prj FROM inventory WHERE sku = 'TC-100'").fetch_one(&pool).await.unwrap();
    assert_eq!(prj, "prjCatalog=h&prjNode=n&prjName=TC&values=v");

    let missing = Scratch::new("missing.txt", b"");
    printed(run_on(&pool, &["export", "prj-missing", "--out", missing.path()]).await);
    assert_eq!(missing.text(), "\"Category Name\"\t\"Subcategory Name\"\t\"Harcourt SKU\"\nPins\t\"Ball Lock Pins\"\tBL-1\n");
}

#[tokio::test]
async fn a_price_increase_raises_every_break_of_the_named_subcategories() {
    let pool = seeded().await;
    let text = printed(run_on(&pool, &["job", "price_increase", "--percent", "10", "10"]).await);
    assert_eq!(text, "Subcategories: 1\nIncrease: 10.00%\nSKUs updated: 1 SKUs skipped: 1\n");
    let pricing: String = sqlx::query_scalar("SELECT pricing FROM inventory WHERE id = 1").fetch_one(&pool).await.unwrap();
    assert!(pricing.contains("d:13.75;") && pricing.contains("d:12.1;"), "{pricing}");
    assert!(count(&pool, "SELECT price_increase FROM subcategory WHERE id = 10").await > 0);
    assert_eq!(count(&pool, "SELECT count(*) FROM log WHERE type = 'Import Table' AND subtype = 'Price Increase: Percent'").await, 1);
    assert!(matches!(run_on(&pool, &["job", "price_increase", "--percent", "10", "11"]).await, Err(Fail::Run(_))), "a pending subcategory is not raised");
    assert!(matches!(run_on(&pool, &["job", "price_increase", "--fixed", "1"]).await, Err(Fail::Usage(_))), "name the subcategories or --all");
}

#[tokio::test]
async fn remote_grants_are_minted_listed_and_revoked_in_a_staff_accounts_name() {
    let pool = seeded().await;
    for (id, role) in [(1, "Administrator"), (2, "Executive"), (5, "Customer"), (6, "Super User")] {
        sqlx::query(r#"INSERT INTO "user" (id, status, "type", company_name) VALUES (?1, 'Active', ?2, ?3)"#).bind(id).bind(role).bind(format!("Test company {id}")).execute(&pool).await.unwrap();
    }
    assert!(matches!(run_on(&pool, &["remote-grants", "mint", "5"]).await, Err(Fail::Usage(_))), "--by is required");
    assert!(matches!(run_on(&pool, &["remote-grants", "mint", "5", "--by", "2"]).await, Err(Fail::Run(_))), "an Executive does not grant");
    assert!(matches!(run_on(&pool, &["remote-grants", "mint", "6", "--by", "1"]).await, Err(Fail::Run(_))), "not above the granter's role");
    assert_eq!(count(&pool, "SELECT count(*) FROM remote_grant").await, 0);

    let text = printed(run_on(&pool, &["remote-grants", "mint", "5", "--by", "1"]).await);
    let code = text.lines().last().unwrap();
    assert!(hxweb::token::Token::parse(code).is_some(), "{text}");
    let list = printed(run_on(&pool, &["remote-grants", "list"]).await);
    assert!(list.contains("\t5\tTest company 5\t1 Test company 1\t") && !list.contains(code));

    let id: i64 = sqlx::query_scalar("SELECT rowid FROM remote_grant").fetch_one(&pool).await.unwrap();
    let id = id.to_string();
    assert_eq!(printed(run_on(&pool, &["remote-grants", "revoke", &id, "--by", "1"]).await), format!("Grant {id} revoked\n"));
    assert!(hxweb::remote_grant::redeem(&pool, &hxweb::token::Token::parse(code).unwrap(), db::unix_now()).await.unwrap().is_none());
    assert_eq!(count(&pool, "SELECT count(*) FROM log WHERE subtype IN ('remote_granted', 'remote_revoked', 'remote_grant_denied')").await, 4);
}

#[tokio::test]
async fn commands_name_their_tables_and_directions() {
    let pool = seeded().await;
    let any = Scratch::new("any.txt", b"x\n");
    for args in [
        vec!["export", "erp-price"],
        vec!["export", "nothing"],
        vec!["import", "email", any.path()],
        vec!["export", "inventory", "--out", "parts.pdf"],
        vec!["export", "inventory", "--update"],
        vec!["job", "cleanup"],
    ] {
        assert!(matches!(run_on(&pool, &args).await, Err(Fail::Usage(_))), "{args:?}");
    }
    assert!(matches!(run_on(&pool, &["import", "inventory", any.path()]).await, Err(Fail::Run(_))), "a header with no column of the table");
}

#[tokio::test]
async fn cad_formats_replace_the_table_and_the_job_needs_its_registry_settings() {
    let pool = seeded().await;
    exec(&pool, "INSERT INTO cad (code, name) VALUES ('OLD', 'Old format')").await;
    let xml = r#"<formats><format type="neutral" cad="step"><qualifier>STEP-AP214</qualifier><name>STEP AP214</name><version/></format></formats>"#;
    let formats = hxweb::staff::jobs::cad_formats(xml).unwrap();
    hxweb::staff::jobs::replace_cad(&pool, &formats).await.unwrap();
    let rows: Vec<(String, String, String, i64)> = sqlx::query_as(r#"SELECT code, name, "type", active FROM cad"#).fetch_all(&pool).await.unwrap();
    assert_eq!(rows, [("STEP-AP214".to_string(), "STEP AP214".to_string(), "STEP".to_string(), 1)]);
    assert_eq!(run_on(&pool, &["job", "cad_formats"]).await, Err(Fail::Run("registry cad.portal or cad.catalog is not set".into())));
}
