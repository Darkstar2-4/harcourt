//! cart.php: the cart page, its update/clear/checkout actions, and the AJAX call the catalog's Add to Cart and the
//! cart's delete buttons make.

use std::sync::Arc;

use axum::{
    body::Bytes,
    extract::State,
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use rust_decimal::Decimal;
use serde::Serialize;
use sqlx::{SqliteConnection, SqliteExecutor, SqlitePool};

use super::{fn_base64, number_format, quoted, shopper, CartKey, Labels, MoneyFormat, Shopper};
use crate::{
    api::ApiError,
    db::unix_now,
    orders::{
        form_params,
        html::{self, esc, esc_text, js_str},
        pricing::Price,
        Orders,
    },
    visitor::Visitor,
};

/// A cart line as stored: what the shopper chose, not what it costs.
#[derive(Debug, PartialEq, Eq)]
pub struct Item {
    pub sku: String,
    pub quantity: u32,
    pub comment: String,
}

/// A cart line priced for this shopper now.
pub struct Line {
    pub sku: String,
    pub description: String,
    pub quantity: u32,
    pub comment: String,
    pub price: Price,
    pub thumbnail: Option<String>,
}

pub async fn items(pool: &SqlitePool, key: &CartKey) -> sqlx::Result<Vec<Item>> {
    touch(pool, key, unix_now()).await?;
    let hash = key.as_slice();
    let rows = sqlx::query!(
        r#"SELECT sku, quantity AS "quantity!: i64", comment FROM cart_item WHERE session_hash = ?1 ORDER BY added_at, rowid"#,
        hash
    )
    .fetch_all(pool)
    .await?;
    Ok(rows.into_iter().map(|r| Item { sku: r.sku, quantity: u32::try_from(r.quantity).unwrap_or(0), comment: r.comment }).collect())
}

/// How long a cart kept in a PHP session lasts after its last use; a session's cart goes with the session.
const PHP_CART_SECS: i64 = 86400;

/// The shopper uses the cart: carts without a session left unused longer than a PHP session lasted go, each as a
/// whole, and this one is used now.
async fn touch(pool: &SqlitePool, key: &CartKey, now: i64) -> sqlx::Result<()> {
    let stale = now - PHP_CART_SECS;
    sqlx::query!("DELETE FROM cart_item WHERE touched_at < ?1 AND session_hash NOT IN (SELECT token_hash FROM session)", stale).execute(pool).await?;
    sqlx::query!("DELETE FROM cart_takeover WHERE taken_at < ?1", stale).execute(pool).await?;
    let hash = key.as_slice();
    sqlx::query!("UPDATE cart_item SET touched_at = ?2 WHERE session_hash = ?1", hash, now).execute(pool).await?;
    Ok(())
}

/// Moves the lines of `from` into `into`, adding the quantities of a part both hold, whose comment is `into`'s
/// unless it has none. A cart left unused longer than a PHP session lasted is not taken. Every takeover is counted,
/// held lines or not, so that a request which found `from` before it adds nothing to it after.
pub(super) async fn adopt(pool: &SqlitePool, from: &[u8; 32], into: &CartKey, now: i64) -> sqlx::Result<()> {
    let (from, into) = (from.as_slice(), into.as_slice());
    let stale = now - PHP_CART_SECS;
    let most = i64::from(u32::MAX);
    let mut tx = pool.begin().await?;
    sqlx::query!(
        "INSERT INTO cart_item (session_hash, sku, quantity, comment, added_at, touched_at)
         SELECT ?2, sku, quantity, comment, added_at, ?3 FROM cart_item WHERE session_hash = ?1 AND touched_at >= ?4
         ON CONFLICT (session_hash, sku) DO UPDATE SET quantity = min(cart_item.quantity + excluded.quantity, ?5),
             comment = CASE WHEN cart_item.comment = '' THEN excluded.comment ELSE cart_item.comment END",
        from,
        into,
        now,
        stale,
        most
    )
    .execute(&mut *tx)
    .await?;
    sqlx::query!("DELETE FROM cart_item WHERE session_hash = ?1", from).execute(&mut *tx).await?;
    sqlx::query!(
        "INSERT INTO cart_takeover (session_hash, takeovers, taken_at) VALUES (?1, 1, ?2)
         ON CONFLICT (session_hash) DO UPDATE SET takeovers = cart_takeover.takeovers + 1, taken_at = excluded.taken_at",
        from,
        now
    )
    .execute(&mut *tx)
    .await?;
    tx.commit().await
}

/// How many times a session has taken over the cart kept by company address under `hash`.
pub(super) async fn takeovers(pool: &SqlitePool, hash: &[u8; 32]) -> sqlx::Result<i64> {
    let hash = hash.as_slice();
    let n = sqlx::query_scalar!(r#"SELECT takeovers AS "n!: i64" FROM cart_takeover WHERE session_hash = ?1"#, hash).fetch_optional(pool).await?;
    Ok(n.unwrap_or(0))
}

/// Sets the quantity, and the comment when one is given, keeping the line's place in the cart. Nothing is written
/// to a cart a session has taken over since it was found.
async fn put(pool: &SqlitePool, key: &CartKey, sku: &str, quantity: u32, comment: Option<&str>, now: i64) -> sqlx::Result<()> {
    touch(pool, key, now).await?;
    let hash = key.as_slice();
    let quantity = i64::from(quantity);
    let initial = comment.unwrap_or("");
    let replace_comment = comment.is_some();
    let takeovers = key.takeovers;
    sqlx::query!(
        "INSERT INTO cart_item (session_hash, sku, quantity, comment, added_at, touched_at)
         SELECT ?1, ?2, ?3, ?4, ?5, ?5 WHERE coalesce((SELECT takeovers FROM cart_takeover WHERE session_hash = ?1), 0) = ?7
         ON CONFLICT (session_hash, sku) DO UPDATE SET quantity = excluded.quantity,
             comment = CASE WHEN ?6 THEN excluded.comment ELSE cart_item.comment END",
        hash,
        sku,
        quantity,
        initial,
        now,
        replace_comment,
        takeovers
    )
    .execute(pool)
    .await?;
    Ok(())
}

async fn remove(pool: &SqlitePool, key: &CartKey, sku: &str) -> sqlx::Result<()> {
    touch(pool, key, unix_now()).await?;
    let hash = key.as_slice();
    sqlx::query!("DELETE FROM cart_item WHERE session_hash = ?1 AND sku = ?2", hash, sku).execute(pool).await?;
    Ok(())
}

pub async fn clear<'c>(db: impl SqliteExecutor<'c>, key: &CartKey) -> sqlx::Result<()> {
    let hash = key.as_slice();
    sqlx::query!("DELETE FROM cart_item WHERE session_hash = ?1", hash).execute(db).await?;
    Ok(())
}

/// Empties the cart and returns what it held, in one statement, in the caller's transaction.
pub async fn take(conn: &mut SqliteConnection, key: &CartKey) -> sqlx::Result<Vec<Item>> {
    let hash = key.as_slice();
    let mut rows = sqlx::query!(
        r#"DELETE FROM cart_item WHERE session_hash = ?1
           RETURNING sku AS "sku!", quantity AS "quantity!: i64", comment AS "comment!", added_at AS "added_at!: i64", rowid AS "rowid!: i64""#,
        hash
    )
    .fetch_all(&mut *conn)
    .await?;
    rows.sort_by_key(|r| (r.added_at, r.rowid));
    Ok(rows.into_iter().map(|r| Item { sku: r.sku, quantity: u32::try_from(r.quantity).unwrap_or(0), comment: r.comment }).collect())
}

/// The shopper's cart as stored, empty without a cart.
pub async fn stored(pool: &SqlitePool, shopper: &Shopper) -> sqlx::Result<Vec<Item>> {
    match &shopper.cart {
        Some(key) => items(pool, key).await,
        None => Ok(Vec::new()),
    }
}

/// Prices every line. A SKU the catalog no longer offers to this account is left out.
pub async fn priced(state: &Orders, shopper: &Shopper, items: &[Item]) -> sqlx::Result<Vec<Line>> {
    let pool = &state.core.pool;
    let mut out = Vec::new();
    for item in items {
        if let Some(priced) = state.pricing.price(pool, &shopper.viewer, &item.sku, item.quantity).await? {
            let thumbnail = thumbnail(state, &item.sku).await?;
            out.push(Line { sku: item.sku.clone(), description: priced.description, quantity: item.quantity, comment: item.comment.clone(), price: priced.price, thumbnail });
        }
    }
    Ok(out)
}

/// The shopper's cart, priced.
pub async fn lines(state: &Orders, shopper: &Shopper) -> sqlx::Result<Vec<Line>> {
    priced(state, shopper, &stored(&state.core.pool, shopper).await?).await
}

/// The subcategory thumbnail `$forms->img()` shows: a local file only when it exists under the document root.
pub(super) async fn thumbnail(state: &Orders, sku: &str) -> sqlx::Result<Option<String>> {
    let url = sqlx::query_scalar!(
        r#"SELECT s.thumbnail_url AS "url!: String" FROM inventory i JOIN subcategory s ON s.id = i.subcategory_id
           WHERE i.sku = ?1 AND s.thumbnail_url IS NOT NULL AND s.thumbnail_url <> ''"#,
        sku
    )
    .fetch_optional(&state.core.pool)
    .await?;
    let Some(url) = url else { return Ok(None) };
    if url.starts_with("http") {
        return Ok(Some(url));
    }
    let path = if url.starts_with('/') { url } else { format!("/{url}") };
    let local = !path.split('/').any(|p| p == "..") && state.docroot.join(path.trim_start_matches('/')).is_file();
    Ok(local.then_some(path))
}

/// What `$database->orderln->cart()` prints. `entry` adds the delete buttons, the quantity and comment inputs and
/// the cart buttons.
pub fn table(lines: &[Line], entry: bool, money: &MoneyFormat, labels: &Labels, thumb_size: &str) -> String {
    if lines.is_empty() {
        return String::new();
    }
    let mut r: Vec<String> = vec!["<br>".into(), "<table class='cart' id='scs_cart'>".into(), "<tr>".into()];
    let mut colspan = 4;
    if entry {
        r.push("<th>Delete</th>".into());
        colspan += 1;
    }
    r.extend(["<th>Part #</th>", "<th colspan=2>Description</th>", "<th>Quantity</th>", "<th>Unit Price</th>", "<th>Extended</th>", "</tr>"].map(String::from));
    let mut total = Decimal::ZERO;
    let mut missing = false;
    for line in lines {
        let row_id = fn_base64(&["row", &line.sku]);
        let quantity_field = fn_base64(&["quantity", &line.sku]);
        let comment_field = fn_base64(&["comment", &line.sku]);
        r.push(format!("<tr style='border-bottom: 2px solid #444;' id={row_id}>"));
        if entry {
            let onclick = format!("cart_delete({},{});", js_str(&line.sku), js_str(&row_id));
            r.push(format!("<td class=center>{}</td>", html::button("X", &onclick, "cart_delete")));
        }
        r.push(format!("<td class=nobr>{}</td>", esc_text(&line.sku)));
        let img = line.thumbnail.as_ref().map_or(String::new(), |src| format!("<img src='{}' width='{thumb_size}' height='{thumb_size}' border='0'>", esc(src)));
        r.push(format!("<td class=center>{img}</td>"));
        let mut content = vec![esc_text(&line.description).replace('\n', "<br>")];
        if entry {
            content.push(format!("<br>{}", html::text_input("text", &comment_field, &line.comment, 40, 60, "Comment (optional)", false)));
        } else if !line.comment.is_empty() {
            content.push(format!("<br>Comment: {}", esc_text(&line.comment)));
        }
        r.push(format!("<td>{}</td>", content.join("\n")));
        r.push("</td>".into());
        if entry {
            r.push(format!("<td class=center>{}</td>", html::text_input("text", &quantity_field, &line.quantity.to_string(), 6, 8, "", false)));
        } else {
            r.push(format!("<td class=right>{}</td>", number_format(Decimal::from(line.quantity), 0)));
        }
        match quoted(&line.price) {
            Some((unit, extended)) => {
                total += extended;
                r.push(format!("<td class=right>{}</td>", money.price(unit)));
                r.push(format!("<td class=right>{}</td>", money.price(extended)));
            }
            None => {
                missing = true;
                r.push("<td class=center colspan=2>Pricing available upon request</td>".into());
            }
        }
        r.push("</tr>".into());
    }
    r.push("<tr>".into());
    r.push(format!("<td colspan={colspan}>{}</td>", money.name()));
    if missing {
        r.push("<td class=center colspan=2>Pricing available upon request</td>".into());
    } else {
        r.push("<td class=right>Total:</td>".into());
        r.push(format!("<td class=right><span id=cart_extended>{}</span></td>", money.price(total)));
    }
    r.push("</tr>".into());
    r.push("</table>".into());
    if entry {
        r.push("<table class='noborder'>".into());
        r.push("<caption >&nbsp;</caption>\n".into());
        r.push("</tr>".into());
        let buttons = [html::button(labels.get("cart_update"), "fn_action('update');", "cart_button"), html::button(labels.get("cart_clear"), "fn_action('clear');", "cart_button")];
        r.push(format!("<td class=left>{}</td>", buttons.join("\n")));
        r.push(format!("<td class=right>{}</td>", html::button(&format!("{} >>", labels.get("cart_checkout")), "fn_action('checkout');", "cart_button")));
        r.push("</tr>".into());
        r.push("</table>".into());
    }
    r.join("\n")
}

/// `fn_number($text, 0)`: digits, point and minus kept, the leading number read, rounded half away from zero.
fn fn_number(text: &str) -> Decimal {
    let kept: String = text.chars().filter(|c| c.is_ascii_digit() || *c == '.' || *c == '-').collect();
    let mut end = 0;
    let bytes = kept.as_bytes();
    if bytes.first() == Some(&b'-') {
        end = 1;
    }
    let digits_from = end;
    while end < bytes.len() && bytes[end].is_ascii_digit() {
        end += 1;
    }
    if end < bytes.len() && bytes[end] == b'.' {
        end += 1;
        while end < bytes.len() && bytes[end].is_ascii_digit() {
            end += 1;
        }
    }
    let number = &kept[..end];
    if end == digits_from || number == "-" || number == "." || number == "-." {
        return Decimal::ZERO;
    }
    number.trim_end_matches('.').parse::<Decimal>().map(|d| d.round_dp_with_strategy(0, rust_decimal::RoundingStrategy::MidpointAwayFromZero)).unwrap_or_default()
}

/// A quantity a cart line can hold: a whole number above zero that fits the order line column.
fn quantity(d: Decimal) -> Option<u32> {
    use rust_decimal::prelude::ToPrimitive;
    d.abs().to_u32().filter(|q| *q > 0)
}

/// What a quantity box asks of a cart line, on Add to Cart and on the cart page alike.
#[derive(Debug, PartialEq, Eq)]
enum Typed {
    /// A blank box changes nothing.
    Blank,
    /// A 0 removes the line.
    Remove,
    Set(u32),
    /// A number too large for a quantity.
    TooLarge,
}

fn typed(text: &str) -> Typed {
    if text.trim().is_empty() {
        return Typed::Blank;
    }
    let n = fn_number(text).abs();
    if n.is_zero() {
        Typed::Remove
    } else {
        quantity(n).map_or(Typed::TooLarge, Typed::Set)
    }
}

#[derive(Serialize)]
struct AjaxAnswer {
    json_code: String,
    constant: Option<String>,
    sku: Option<String>,
    #[serde(serialize_with = "php_float")]
    quantity: Decimal,
    status: String,
    cart_size: usize,
    cart_extended: String,
}

/// PHP's quantity is a float, which json_encode writes without a fraction when it has none (`5`, `0.5`).
fn php_float<S: serde::Serializer>(d: &Decimal, s: S) -> Result<S::Ok, S::Error> {
    use rust_decimal::prelude::ToPrimitive;
    match d.fract().is_zero().then(|| d.to_i64()).flatten() {
        Some(whole) => s.serialize_i64(whole),
        None => s.serialize_f64(d.to_f64().unwrap_or(0.0)),
    }
}

async fn ajax(state: &Orders, shopper: &Shopper, params: &std::collections::HashMap<String, String>) -> Result<Response, ApiError> {
    use base64::{engine::general_purpose::STANDARD_NO_PAD, Engine};
    let pool = &state.core.pool;
    let json_code = params.get("json_code").cloned().unwrap_or_default();
    let decoded = STANDARD_NO_PAD.decode(json_code.trim().trim_end_matches('=')).unwrap_or_default();
    let decoded = String::from_utf8_lossy(&decoded).into_owned();
    let mut parts = decoded.split('\t').map(str::to_string);
    let constant = parts.next();
    let sku = parts.next();
    let text = params.get("json_quantity").map_or("", String::as_str);
    let requested = fn_number(text).abs();
    let sku_text = sku.clone().unwrap_or_default();
    let status = match (typed(text), &shopper.cart) {
        (Typed::Blank, _) => format!("{sku_text} cart unchanged"),
        (Typed::Remove, key) => {
            if let Some(key) = key {
                remove(pool, key, &sku_text).await?;
            }
            format!("{sku_text} removed from cart")
        }
        (Typed::Set(q), Some(key)) if !sku_text.is_empty() && state.pricing.price(pool, &shopper.viewer, &sku_text, q).await?.is_some() => {
            put(pool, key, &sku_text, q, None, unix_now()).await?;
            format!("{sku_text} cart updated")
        }
        _ => format!("{sku_text} invalid"),
    };
    let lines = lines(state, shopper).await?;
    let mut total = Decimal::ZERO;
    let mut missing = false;
    for line in &lines {
        match quoted(&line.price) {
            Some((_, extended)) => total += extended,
            None => {
                missing = true;
                break;
            }
        }
    }
    let cart_extended = if missing { "Pricing available upon request.".to_string() } else { shopper.money(&shopper.currency).price(total) };
    let answer = AjaxAnswer { json_code, constant, sku, quantity: requested, status, cart_size: lines.len(), cart_extended };
    let body = php_json(&serde_json::to_string(&answer).map_err(|_| ApiError::Internal)?);
    Ok(html::page_response(body))
}

/// json_encode's defaults on top of serde's output: `\/` for slashes and `\uXXXX` for anything beyond ASCII.
pub fn php_json(json: &str) -> String {
    let mut out = String::with_capacity(json.len());
    for c in json.chars() {
        match c {
            '/' => out.push_str("\\/"),
            c if c.is_ascii() => out.push(c),
            c => {
                let mut buf = [0u16; 2];
                for unit in c.encode_utf16(&mut buf) {
                    out.push_str(&format!("\\u{unit:04x}"));
                }
            }
        }
    }
    out
}

const SCRIPT: &str = "<script>\nfunction fn_action(action) {\n    if (action=='clear') {\n        if (!confirm('Clear Shopping Cart?')) return;\n    }\n    document.scs_form.action.value=action;\n    document.scs_form.submit();\n}\n</script>\n";

pub(super) fn home(visitor: &Visitor) -> Response {
    html::redirect(if visitor.framed { "/" } else { "/scs_index.php" })
}

/// GET: the cart as it stands.
pub async fn view(State(state): State<Arc<Orders>>, visitor: Visitor, headers: HeaderMap) -> Result<Response, ApiError> {
    let Some(shopper) = shopper(&state.core.pool, &visitor, &headers).await? else { return Ok(home(&visitor)) };
    page(&state, &shopper, visitor.framed, vec!["Shopping Cart".to_string()]).await
}

/// POST: the AJAX add and delete, and the form's update, clear and checkout, read from the body alone.
pub async fn action(State(state): State<Arc<Orders>>, visitor: Visitor, headers: HeaderMap, body: Bytes) -> Result<Response, ApiError> {
    let pool = &state.core.pool;
    let params = form_params(None, &body);
    let shopper = shopper(pool, &visitor, &headers).await?;
    if headers.contains_key("x-requested-with") {
        return match shopper {
            Some(s) => ajax(&state, &s, &params).await,
            None => Ok(html::page_response(String::new())),
        };
    }
    let Some(shopper) = shopper else { return Ok(home(&visitor)) };
    let mut messages = vec!["Shopping Cart".to_string()];
    match (params.get("action").map(String::as_str), &shopper.cart) {
        (Some("checkout"), _) => return Ok(html::redirect("/checkout.php")),
        (Some("clear"), key) => {
            if let Some(key) = key {
                clear(pool, key).await?;
            }
            messages.push("Shopping Cart Cleared".into());
        }
        (Some("update"), key) => {
            if let Some(key) = key {
                for item in items(pool, key).await? {
                    let text = params.get(&fn_base64(&["quantity", &item.sku])).map_or("", String::as_str);
                    let comment = params.get(&fn_base64(&["comment", &item.sku])).map(|c| c.trim().to_string());
                    match typed(text) {
                        Typed::Remove => remove(pool, key, &item.sku).await?,
                        Typed::Set(q) => put(pool, key, &item.sku, q, comment.as_deref(), unix_now()).await?,
                        Typed::Blank | Typed::TooLarge => put(pool, key, &item.sku, item.quantity, comment.as_deref(), unix_now()).await?,
                    }
                }
            }
            messages.push("Shopping Cart Updated".into());
        }
        _ => {}
    }
    page(&state, &shopper, visitor.framed, messages).await
}

async fn page(state: &Orders, shopper: &Shopper, framed: bool, messages: Vec<String>) -> Result<Response, ApiError> {
    let pool = &state.core.pool;
    let lines = lines(state, shopper).await?;
    let labels = Labels::load(pool, &shopper.language).await?;
    let thumb = crate::orders::mailform::Registry::load(pool, "local").await?.get("thumbnail").to_string();
    let money = shopper.money(&shopper.currency);
    let chrome = html::Chrome::load(pool).await?;
    let head = html::Head { title: "Shopping Cart", description: None, js: &[], framed, dashboard: shopper.user.role >= crate::identity::Role::Executive, catalog_url: "/catalog.php" };
    let mut out = html::open(&chrome, &head);
    out.push_str(&html::message(&messages, 0));
    out.push_str(SCRIPT);
    out.push_str(&html::form_open("/cart.php"));
    out.push_str(&html::hidden("action", ""));
    if lines.is_empty() {
        out.push_str("<p>Your shopping cart is empty</p>");
    } else {
        out.push_str(&table(&lines, true, &money, &labels, &thumb));
    }
    out.push_str(html::FORM_CLOSE);
    out.push_str(html::close());
    Ok(html::page_response(out).into_response())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn quantity_boxes_read_one_way() {
        assert_eq!(typed("4"), Typed::Set(4));
        assert_eq!(typed("2.0"), Typed::Set(2));
        assert_eq!(typed("2.5"), Typed::Set(3));
        assert_eq!(typed("2 pcs"), Typed::Set(2));
        assert_eq!(typed("-3"), Typed::Set(3));
        assert_eq!(typed(""), Typed::Blank);
        assert_eq!(typed(" "), Typed::Blank);
        assert_eq!(typed("0"), Typed::Remove);
        assert_eq!(typed("99999999999"), Typed::TooLarge);
    }

    #[tokio::test]
    async fn a_cart_found_before_a_takeover_takes_no_lines_after_it() {
        let pool = crate::db::open_memory().await.unwrap();
        let now = unix_now();
        let address = [7u8; 32];
        let session = CartKey::session([8u8; 32]);
        let found = CartKey { hash: address, takeovers: takeovers(&pool, &address).await.unwrap() };
        put(&pool, &found, "SKU-1", 2, None, now).await.unwrap();
        adopt(&pool, &address, &session, now).await.unwrap();
        put(&pool, &found, "SKU-2", 1, None, now).await.unwrap();
        assert!(items(&pool, &found).await.unwrap().is_empty());
        assert_eq!(items(&pool, &session).await.unwrap().iter().map(|i| i.sku.as_str()).collect::<Vec<_>>(), ["SKU-1"]);
        let again = CartKey { hash: address, takeovers: takeovers(&pool, &address).await.unwrap() };
        put(&pool, &again, "SKU-2", 1, None, now).await.unwrap();
        assert_eq!(items(&pool, &again).await.unwrap().len(), 1, "a request that finds the cart after the takeover uses it");
    }

    #[test]
    fn fn_number_reads_like_php() {
        let n = |s: &str| fn_number(s).to_string();
        assert_eq!(n("5"), "5");
        assert_eq!(n("1,234"), "1234");
        assert_eq!(n("2.5"), "3");
        assert_eq!(n("-3"), "-3");
        assert_eq!(n("abc"), "0");
        assert_eq!(n(""), "0");
        assert_eq!(n("7x8"), "78");
    }

    #[test]
    fn quantities_are_whole_and_positive() {
        assert_eq!(quantity(Decimal::from(5)), Some(5));
        assert_eq!(quantity(Decimal::ZERO), None);
        assert_eq!(quantity(Decimal::from(5_000_000_000_i64)), None);
    }

    #[test]
    fn quantities_print_as_php_json_does() {
        #[derive(Serialize)]
        struct Q(#[serde(serialize_with = "php_float")] Decimal);
        assert_eq!(serde_json::to_string(&Q(Decimal::from(2))).unwrap(), "2");
        assert_eq!(serde_json::to_string(&Q(Decimal::ZERO)).unwrap(), "0");
        assert_eq!(serde_json::to_string(&Q(Decimal::new(5, 1))).unwrap(), "0.5");
    }

    #[test]
    fn json_escapes_like_php() {
        let expected = concat!(r#"{"s":"a\/b "#, "\\", r#"u00e9"}"#);
        assert_eq!(php_json("{\"s\":\"a/b \u{e9}\"}"), expected);
    }
}
