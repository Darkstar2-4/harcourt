//! cad_lookup.php, the Configurator Toolbox, through the router.

mod catalog_support;

use axum::http::{header, StatusCode};
use catalog_support::{get, harness, send, signed_in, Account};
use sqlx::SqlitePool;

async fn seed(pool: &SqlitePool) {
    for sql in [
        "INSERT INTO registry (domain, module, field, item, data) VALUES ('', 'cad', 'configurator', '', 'https://cad.example.com/models/'), ('', 'cad', 'catalog', '', 'demo'), ('', 'cad', 'apikey', '', 'k-0000'), ('', 'cad', 'partserver_reversemap', '', '1')",
        "INSERT INTO cad (id, code, name, type, direct) VALUES (1, 'STEP-214', 'STEP AP214', '3D', 0), (2, 'PDFDATASHEET', 'PDF Datasheet', '2D', 0), (3, 'DIRECT1', 'Direct format', '3D', 1)",
    ] {
        sqlx::query(sql).execute(pool).await.unwrap();
    }
}

fn staff(id: i64, role: &'static str) -> Account {
    Account { role, company_name: Some("Harcourt".into()), ..Account::customer(id) }
}

#[tokio::test]
async fn customers_are_sent_home_and_staff_get_the_toolbox() {
    let h = harness().await;
    seed(&h.pool).await;
    let customer = signed_in(&h.pool, &Account::customer(60)).await;
    let (status, headers, _) = send(&h.app, get("/cad_lookup.php?scscpq_wp=1", Some(&customer))).await;
    assert_eq!(status, StatusCode::FOUND);
    assert_eq!(headers[header::LOCATION], "/");
    assert!(headers.get_all(header::SET_COOKIE).iter().any(|c| c.to_str().unwrap().starts_with("harcourt_profile=")), "the profile is written before the role check");

    let internal = signed_in(&h.pool, &staff(61, "Internal")).await;
    let (status, _, body) = send(&h.app, get("/cad_lookup.php?action=ipc&source=prj&prj=a.prj&sku=SYN-1", Some(&internal))).await;
    assert_eq!(status, StatusCode::OK);
    assert!(body.contains("<title>Configurator Toolbox (10/27/2025)</title>"), "{body}");
    assert!(body.contains("value='https://cad.example.com/models/'"));
    assert!(body.contains("<option value='prj' selected>PRJ</option>"));
    assert!(body.contains(">a.prj</textarea>"));
    assert!(body.contains("Lookup error detected for SKU: <b>SYN-1</b>"));
    assert!(body.contains("<optgroup label='3D'>\n<option value='STEP-214'>STEP AP214</option>"));
    assert!(body.contains("<option value='PDFDATASHEET' selected>PDF Datasheet</option>"));
    assert!(!body.contains("DIRECT1"), "direct formats only with Part2CAD");
    assert!(!body.contains("k-0000"), "the key stays on the server");
    assert!(!body.contains("Hide Portlets"));

    let admin = signed_in(&h.pool, &staff(62, "Administrator")).await;
    let (_, _, body) = send(&h.app, get("/cad_lookup.php", Some(&admin))).await;
    assert!(body.contains("Hide Portlets") && body.contains("value='Varset -&gt; Editor'"));
    assert!(!body.contains("Lookup error detected"));
}
