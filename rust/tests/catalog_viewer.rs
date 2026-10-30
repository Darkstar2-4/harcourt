//! The catalog's view of a visitor, loaded from synthetic account rows.

mod catalog_support;

use catalog_support::{harness, seed_account, Account};
use hxweb::{
    catalog::viewer::{self, Kind, Lang},
    identity::{Role, Status, User, UserId},
    pricing::PriceTerms,
    visitor::Visitor,
};

fn visitor(id: i64, status: Status, role: Role) -> Visitor {
    Visitor::SignedIn { user: User { id: UserId(id), role, status, company_name: None }, email: None }
}

#[tokio::test]
async fn an_active_account_brings_its_flags_prices_and_blocks() {
    let h = harness().await;
    let mut a = Account::customer(21);
    a.ecommerce = true;
    a.price_type = "discount";
    a.price_pct = 0.2;
    a.country_code = " ca ";
    a.company_name = Some("Blocked Co".into());
    seed_account(&h.pool, &a).await;
    sqlx::query("INSERT INTO user_category (user_id, category_id) VALUES (21, 4), (22, 9)").execute(&h.pool).await.unwrap();
    sqlx::query("INSERT INTO company_category_block (company_name, category_id) VALUES ('Blocked Co', 6), ('Other Co', 8)").execute(&h.pool).await.unwrap();

    let v = viewer::load(&h.pool, &visitor(21, Status::Active, Role::Customer), Lang::Fr).await.unwrap();
    assert_eq!(v.kind, Kind::Account(UserId(21)));
    assert!(v.may_browse() && v.ecommerce && !v.cad);
    assert_eq!(v.price, Some(PriceTerms::Discount(0.2)));
    assert_eq!(v.country_code, "CA");
    assert_eq!(v.lang, Lang::Fr);
    assert_eq!(v.blocked.ids(), vec![4, 6]);
    assert!(v.blocked.contains(6) && !v.blocked.contains(8) && !v.blocked.contains(9));
}

#[tokio::test]
async fn an_account_that_is_not_active_has_no_catalog_cad_or_cart() {
    let h = harness().await;
    let mut a = Account::customer(30);
    a.status = "Pending";
    a.cad = true;
    a.ecommerce = true;
    seed_account(&h.pool, &a).await;
    let v = viewer::load(&h.pool, &visitor(30, Status::Pending, Role::Customer), Lang::En).await.unwrap();
    assert!(!v.may_browse() && !v.catalog && !v.cad && !v.ecommerce);
}

#[tokio::test]
async fn no_session_or_a_vanished_account_is_anonymous() {
    let h = harness().await;
    let anon = viewer::load(&h.pool, &Visitor::Anonymous, Lang::En).await.unwrap();
    assert_eq!(anon.kind, Kind::Anonymous);
    let gone = viewer::load(&h.pool, &visitor(99, Status::Active, Role::Administrator), Lang::En).await.unwrap();
    assert_eq!(gone.kind, Kind::Anonymous);
    assert!(!gone.sees_pending() && !gone.at_least(Role::Customer));
}
