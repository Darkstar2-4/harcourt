//! catalog.php's views through the router, on synthetic catalog rows.

mod catalog_support;

use axum::{
    body::Body,
    http::{Method, Request, StatusCode},
};
use catalog_support::{harness, send, signed_in, Account};
use sqlx::SqlitePool;

/// What the catalog page's script fetched, inside a visit.
fn get(path: &str, cookie: Option<&str>) -> Request<Body> {
    catalog_support::request(Method::GET, path, cookie, &[("x-requested-with", "XMLHttpRequest")], Body::empty())
}

const PARAMS: &str = r#"a:2:{i:0;O:27:"subcategory_parameter_class":4:{s:2:"id";i:0;s:4:"name";s:8:"Diameter";s:4:"type";s:9:"Decimal:2";s:9:"hyperlink";i:0;}i:1;O:27:"subcategory_parameter_class":4:{s:2:"id";i:1;s:4:"name";s:4:"Mate";s:4:"type";s:9:"Uppercase";s:9:"hyperlink";i:1;}}"#;

fn price(list: f64) -> String {
    format!(r#"a:1:{{i:0;O:23:"inventory_pricing_class":2:{{s:5:"price";d:{list};s:9:"qty_break";i:1;}}}}"#)
}

async fn exec(pool: &SqlitePool, sql: &str) {
    sqlx::query(sql).execute(pool).await.unwrap();
}

/// Two categories, a family of three parts in category 1, a Pending family, French names, document 157.
async fn seed(pool: &SqlitePool) {
    exec(pool, "INSERT INTO language (code, name, local_name, active, menu) VALUES ('EN', 'English', 'English', 1, NULL), ('FR', 'French', 'Français', 1, '{\"catalog_products\":\"Détails du produit\"}')").await;
    exec(pool, "INSERT INTO currency (code, name, plural, exchange_rate, adder_pct, active) VALUES ('USD', 'US Dollar', 'US Dollars', 1, 0, 1)").await;
    exec(pool, "INSERT INTO registry (domain, module, field, item, data) VALUES ('', 'local', 'configure_contact', '', 'Contact us for pricing'), ('', 'cad', 'configurator', '', 'https://cad.example.com/frame')").await;
    exec(pool, "INSERT INTO category (id, name, sort_order, active) VALUES (1, 'Pins', 1, 1), (2, 'Clamps', 2, 1)").await;
    sqlx::query("INSERT INTO subcategory (id, category_id, name, parameters, parameter_search, status, page_break, output, leadtime_option) VALUES (7, 1, 'Ball Lock Pins', ?1, '0^1', 'Active', 1, '', 'In Stock'), (8, 1, 'Secret Pins', ?1, '', 'Pending', 1, '', ''), (9, 2, 'Toggle Clamps (Metric)', NULL, '', 'Active', 1, '', '')")
        .bind(PARAMS)
        .execute(pool)
        .await
        .unwrap();
    for (id, sku, sub, d, mate, list) in [(1, "HBLT-1", 7, 0.25, "HB-A", 10.0), (2, "HBLT-2", 7, 0.5, "HB-B", 12.5), (3, "HBLT-3", 7, 0.5, "", 15.0), (4, "SP-1", 8, 1.0, "", 9.0)] {
        sqlx::query("INSERT INTO inventory (id, sku, category_id, subcategory_id, parameter0, nparameter0, parameter1, pricing, active, sort_order, leadtime_option) VALUES (?1, ?2, 1, ?3, ?4, ?5, ?6, ?7, 1, ?1, 'Subcategory')")
            .bind(id)
            .bind(sku)
            .bind(sub)
            .bind(format!("{d}"))
            .bind(d)
            .bind(mate)
            .bind(price(list))
            .execute(pool)
            .await
            .unwrap();
    }
    exec(pool, "INSERT INTO content (record_type, record_id, record_item, language_code, name, filetype, meta, html_content) VALUES ('subcategory', 7, '', 'FR', 'Goupilles à billes', '', NULL, NULL), ('subcategory', 7, 'overview:1', 'EN', 'Features', 'html', NULL, '<ul><li>Quick release</li></ul>'), ('document', 157, '', 'EN', 'Register', 'html', '{\"mime\":\"text/html\"}', '<p>Please request access</p>')").await;
}

fn page(path: &str) -> String {
    format!("/catalog.php?scscpq_wp=1&{path}")
}

#[tokio::test]
async fn anonymous_visitors_see_the_access_document() {
    let h = harness().await;
    seed(&h.pool).await;
    let (status, headers, body) = send(&h.app, get(&page("action=subcategory&subcategory_id=7"), None)).await;
    assert_eq!(status, StatusCode::OK);
    assert!(headers["content-type"].to_str().unwrap().starts_with("text/html"));
    assert!(headers.get("access-control-allow-origin").is_none());
    assert!(body.contains("<p>Please request access</p>"), "{body}");
    assert!(!body.contains("products_table"));
    assert!(body.contains("<div id='scs_body' >") && body.contains("<form name='scs_form' id='scs_form' method=post action='/catalog.php'"));
}

#[tokio::test]
async fn a_customer_sees_the_family_with_its_parts_and_tabs() {
    let h = harness().await;
    seed(&h.pool).await;
    let cookie = signed_in(&h.pool, &Account::customer(20)).await;
    let (status, _, body) = send(&h.app, get(&page("lang=en&action=subcategory&subcategory_id=7"), Some(&cookie))).await;
    assert_eq!(status, StatusCode::OK);
    assert!(body.contains("<div id=products_header>\n<h1>Ball Lock Pins</h1>\n</div>"), "{body}");
    assert!(body.contains("<table class='products_table'>"));
    assert!(body.contains("<th>Part#</th>\n<th>Diameter</th>\n<th>Mate</th>\n</tr>"));
    assert!(!body.contains("<th>Price</th>"), "no price terms, no price column");
    assert!(body.contains("<a href=\"/catalog/?lang=en&action=sku&subcategory_id=7&sku=SEJMVC0x&scs_page=0\" >HBLT-1</a>"), "{body}");
    assert!(body.contains("title='Related Part#'>HB-A</a>"));
    assert!(body.contains("<li id='tab_catalog_overview' class='products_jquery_tabs'><a href='#catalog_overview' id='href_catalog_overview' class='products_jquery_tabs'>Overview</a></li>"));
    assert!(body.contains("<ul><li>Quick release</li></ul>"));
    assert!(body.contains("id='href_catalog_products' class='products_jquery_tabs'>Product Details</a>"));
    assert!(body.contains("<div class=center id=quick_cad_status></div>"));
    assert!(body.contains("id='href_catalog_profile'"));
    assert!(body.contains("<select name='parameter0' id='parameter0' onchange="), "two diameters give a select");
    assert!(body.contains("<option value='0.25'>0.25</option>\n<option value='0.50'>0.50</option>"));
}

#[tokio::test]
async fn a_search_selection_narrows_the_parts() {
    let h = harness().await;
    seed(&h.pool).await;
    let cookie = signed_in(&h.pool, &Account::customer(21)).await;
    let (_, _, body) = send(&h.app, get(&page("action=subcategory&subcategory_id=7&parameter0=0.50"), Some(&cookie))).await;
    assert!(body.contains(">HBLT-2</a>") && body.contains(">HBLT-3</a>"));
    assert!(!body.contains(">HBLT-1</a>"));
    assert!(body.contains("<option value='0.50' selected>0.50</option>"));
}

#[tokio::test]
async fn prices_and_the_cart_columns_follow_the_account() {
    let h = harness().await;
    seed(&h.pool).await;
    let mut a = Account::customer(22);
    a.price_type = "discount";
    a.price_pct = 0.2;
    a.ecommerce = true;
    let cookie = signed_in(&h.pool, &a).await;
    let (_, _, body) = send(&h.app, get(&page("action=subcategory&subcategory_id=7"), Some(&cookie))).await;
    assert!(body.contains("<th>Price</th>"));
    assert!(body.contains("<td class='right nobr'>$8.00</td>"), "{body}");
    assert!(body.contains("<th class=nobr><font color=red>*</font> Typical<br>Lead Time</th>"));
    assert!(body.contains("<td class=center>In Stock</td>"));
    assert!(body.contains("javascript:cart_update(this,'bGlzdAlIQkxULTE','c2t1CUhCTFQtMQ')"));

    let (_, _, part) = send(&h.app, get(&page("action=sku&subcategory_id=7&sku=SEJMVC0y"), Some(&cookie))).await;
    assert!(part.contains("Part number: HBLT-2<br>\nCategory: Pins<br>\nSubcategory: Ball Lock Pins</p>"), "{part}");
    assert!(part.contains("Harcourt List Price: $12.50<br><font color=red><b><i>Your Discounted Price: $10.00</i></b></font>"));
    assert!(part.contains("<b>Specifications:</b><br>Diameter: <b>0.5</b><br>Mate: <b>"));
    assert!(part.contains("value='Add to Cart'"));
}

#[tokio::test]
async fn pending_families_list_parts_for_administrators_only() {
    let h = harness().await;
    seed(&h.pool).await;
    let customer = signed_in(&h.pool, &Account::customer(23)).await;
    let mut admin = Account::customer(24);
    admin.role = "Administrator";
    let admin = signed_in(&h.pool, &admin).await;
    let (_, _, body) = send(&h.app, get(&page("action=subcategory&subcategory_id=8"), Some(&customer))).await;
    assert!(body.contains("<h1>Secret Pins</h1>") && !body.contains(">SP-1</a>"));
    let (_, _, body) = send(&h.app, get(&page("action=subcategory&subcategory_id=8"), Some(&admin))).await;
    assert!(body.contains(">SP-1</a>"));
}

#[tokio::test]
async fn a_blocked_category_shows_the_agreement_overlay() {
    let h = harness().await;
    seed(&h.pool).await;
    let cookie = signed_in(&h.pool, &Account::customer(25)).await;
    exec(&h.pool, "INSERT INTO user_category (user_id, category_id) VALUES (25, 1)").await;
    let (_, _, body) = send(&h.app, get(&page("action=subcategory&subcategory_id=7"), Some(&cookie))).await;
    assert!(body.contains("<div class='catalog_category_overlay_message'>Integrator Agreement Required for Access. <a href=\"/contact\" >Contact Us</a> for more information.</div>"), "{body}");
    assert!(!body.contains("products_table"));
}

#[tokio::test]
async fn french_pages_use_french_names_and_links() {
    let h = harness().await;
    seed(&h.pool).await;
    let cookie = signed_in(&h.pool, &Account::customer(26)).await;
    let (_, _, body) = send(&h.app, get(&page("lang=fr&action=subcategory&subcategory_id=7"), Some(&cookie))).await;
    assert!(!body.contains("<h1>Goupilles à billes</h1>"), "the link's lang= is not the session's language");
    let french = format!("{cookie}; hx_lang=FR");
    let (_, _, body) = send(&h.app, get(&page("lang=fr&action=subcategory&subcategory_id=7"), Some(&french))).await;
    assert!(body.contains("<h1>Goupilles à billes</h1>"), "{body}");
    assert!(body.contains("Détails du produit</a>"));
    assert!(body.contains("href=\"/catalogue/?lang=fr&action=sku"));
}

#[tokio::test]
async fn a_document_navigation_to_the_fragment_goes_to_the_catalog_page() {
    let h = harness().await;
    let req = catalog_support::request(axum::http::Method::GET, "/catalog.php?scscpq_wp=1&action=subcategory&subcategory_id=7", None, &[("sec-fetch-dest", "document")], axum::body::Body::empty());
    let (status, headers, _) = send(&h.app, req).await;
    assert_eq!(status, StatusCode::FOUND);
    assert_eq!(headers["location"], "/catalog/?action=subcategory&subcategory_id=7");
}

#[tokio::test]
async fn a_lookup_opens_the_family_or_says_not_found() {
    let h = harness().await;
    seed(&h.pool).await;
    exec(&h.pool, "INSERT INTO search (language_code, active, table_name, code, category_id, subcategory_id, inventory_id) VALUES ('', 1, 'inventory', 'HBLT-2', 1, 7, 2)").await;
    let cookie = signed_in(&h.pool, &Account::customer(27)).await;
    let (_, _, body) = send(&h.app, get(&page("search_source=lookup&search_code=hblt-2"), Some(&cookie))).await;
    assert!(body.contains("<h1>Ball Lock Pins</h1>"));
    assert!(body.contains("<input type=hidden name='category_id' id='category_id' value='1'>\n<input type=hidden name='subcategory_id' id='subcategory_id' value='7'>"), "the form carries the part's family: {body}");
    let (_, _, body) = send(&h.app, get(&page("search_source=lookup&search_code=nope%3Cb%3E"), Some(&cookie))).await;
    assert!(body.contains("<p class=center>Part# <b>NOPE&lt;B></b> not found</p>"), "{body}");
}

#[tokio::test]
async fn a_visit_that_begins_on_the_fragment_goes_where_scs_header_sent_it() {
    let h = harness().await;
    seed(&h.pool).await;
    let first = |path: &str, cookie: Option<&str>| catalog_support::request(Method::GET, path, cookie, &[], Body::empty());
    let location = |headers: &axum::http::HeaderMap| headers.get("location").map(|v| v.to_str().unwrap().to_string());
    let (status, headers, _) = send(&h.app, first("/catalog.php?scscpq_wp=1&action=subcategory&subcategory_id=7", None)).await;
    assert_eq!((status, location(&headers).as_deref()), (StatusCode::FOUND, Some("/")));
    let (_, headers, _) = send(&h.app, first("/catalog.php", None)).await;
    assert_eq!(location(&headers).as_deref(), Some("/scs_index.php"));
    let cookie = signed_in(&h.pool, &Account::customer(28)).await;
    let (_, headers, _) = send(&h.app, first("/catalog.php?scscpq_wp=1", Some(&cookie))).await;
    assert_eq!(location(&headers).as_deref(), Some("/catalog/"));
    let (status, _, _) = send(&h.app, first("/catalog.php", Some(&cookie))).await;
    assert_eq!(status, StatusCode::OK, "an active customer asked for the catalog itself");
}

#[tokio::test]
async fn the_home_lists_visible_families_by_category_with_the_unit_filter() {
    let h = harness().await;
    seed(&h.pool).await;
    exec(&h.pool, "INSERT INTO hero (effective_date, portal_id, industry_id, language_code, html, access, status) VALUES ('2020-01-01', 0, 0, 'EN', '<h2>Spring sale</h2>', '', 'Active'), ('2099-01-01', 0, 0, 'EN', '<h2>Not yet</h2>', '', 'Active')").await;
    let cookie = signed_in(&h.pool, &Account::customer(30)).await;
    let (_, _, body) = send(&h.app, get(&page("lang=en"), Some(&cookie))).await;
    assert!(body.contains("<div class=products_summary><div id=scscpq_hero class=scscpq_hero_preview><h2>Spring sale</h2></div>"), "{body}");
    assert!(body.contains("<div class='catalog_unit_filter' data-fetch-path='/catalog.php' data-fetch-query='scscpq_wp=1'>"));
    assert!(body.contains("<a href=\"/catalog/?lang=en&unit_type=all\" class='catalog_unit_filter_pill active'>All</a>"));
    assert!(body.contains("<a class=anchor id=category1></a><h2>Pins</h2>\n<div class='catalog_category_wrapper'>\n<div class=catalog_subcategory_list>\n<ul>"));
    assert!(body.contains("<li ><a href=\"/catalog/?lang=en&action=subcategory&subcategory_id=7\" ><span class='subcat7'></span>Ball Lock Pins</a></li>"));
    assert!(!body.contains("subcat8"), "Pending families are for administrators");
    assert!(body.contains("subcat9"));

    let mut admin = Account::customer(31);
    admin.role = "Administrator";
    let admin = signed_in(&h.pool, &admin).await;
    let (_, _, body) = send(&h.app, get(&page("lang=en"), Some(&admin))).await;
    assert!(body.contains("<li style='border: 1px solid #00b0f0;'><a href=\"/catalog/?lang=en&action=subcategory&subcategory_id=8\" >"));
}

#[tokio::test]
async fn visitors_outside_the_us_land_on_metric() {
    let h = harness().await;
    seed(&h.pool).await;
    let mut a = Account::customer(32);
    a.country_code = "CA";
    let cookie = signed_in(&h.pool, &a).await;
    let (_, _, body) = send(&h.app, get(&page("lang=en"), Some(&cookie))).await;
    assert!(body.contains("class='catalog_unit_filter_pill active'>Metric</a>"));
    assert!(body.contains("subcat9") && !body.contains("subcat7"));
    let (_, _, body) = send(&h.app, get(&page("lang=en&unit_type=all"), Some(&cookie))).await;
    assert!(body.contains("subcat9") && body.contains("subcat7"));
}

#[tokio::test]
async fn search_results_open_the_pick_or_list_the_matches() {
    let h = harness().await;
    seed(&h.pool).await;
    exec(&h.pool, "INSERT INTO search (id, language_code, active, table_name, code, category_id, subcategory_id, inventory_id) VALUES (500, '', 1, 'inventory', 'HBLT-2', 1, 7, 2), (501, '', 1, 'inventory', 'HBLT-1', 1, 7, 1), (502, '', 1, 'subcategory', 'Clamp', 2, 9, 0)").await;
    let cookie = signed_in(&h.pool, &Account::customer(33)).await;
    let (_, _, body) = send(&h.app, get(&page("search_source=search&search_id=500&search_code="), Some(&cookie))).await;
    assert!(body.contains("Part number: HBLT-2"), "{body}");
    let (_, _, body) = send(&h.app, get(&page("search_source=search&search_id=0&search_code=hblt"), Some(&cookie))).await;
    assert!(body.contains(">HBLT-1</a>") && body.contains(">HBLT-2</a>") && !body.contains(">HBLT-3</a>"), "{body}");
    let (_, _, body) = send(&h.app, get(&page("search_source=search&search_id=0&search_code=hblt-1"), Some(&cookie))).await;
    assert!(body.contains("Part number: HBLT-1"), "an exact part number opens the part");
    let (_, _, body) = send(&h.app, get(&page("search_source=search&search_id=0&search_code=l"), Some(&cookie))).await;
    assert!(body.contains("<h2>Pins</h2>") && body.contains("<h2>Clamps</h2>"), "matches in several families list them");
    let (_, _, body) = send(&h.app, get(&page("search_source=search&search_id=0&search_code=zzz"), Some(&cookie))).await;
    assert!(body.contains("<p class=center>No results for search term: <b>zzz</b></p>"));
}
