//! cart.php: the cart page, its update/clear/checkout actions, and the AJAX call the catalog's Add to Cart and the
//! cart's delete buttons make.

use std::sync::Arc;

use axum::{
    body::Bytes,
    extract::State,
    http::{HeaderMap, Uri},
    response::{IntoResponse, Response},
};
use rust_decimal::Decimal;
use serde::Serialize;
use sqlx::SqlitePool;

use super::{fn_base64, number_format, quoted, shopper, Labels, MoneyFormat, Shopper};
use crate::{
    api::ApiError,
    db::unix_now,
    orders::{
        form_params,
        html::{self, esc, js_str},
        pricing::Price,
        Orders,
    },
    token::TokenHash,
    visitor::Visitor,
};

/// A cart line as stored: what the shopper chose, not what it costs.
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

pub async fn items(pool: &SqlitePool, session: &TokenHash) -> sqlx::Result<Vec<Item>> {
    let hash = session.as_slice();
    let rows = sqlx::query!(
        r#"SELECT sku, quantity AS "quantity!: i64", comment FROM cart_item WHERE session_hash = ?1 ORDER BY added_at, rowid"#,
        hash
    )
    .fetch_all(pool)
    .await?;
    Ok(rows.into_iter().map(|r| Item { sku: r.sku, quantity: u32::try_from(r.quantity).unwrap_or(0), comment: r.comment }).collect())
}

/// Sets the quantity, and the comment when one is given, keeping the line's place in the cart.
async fn put(pool: &SqlitePool, session: &TokenHash, sku: &str, quantity: u32, comment: Option<&str>, now: i64) -> sqlx::Result<()> {
    let hash = session.as_slice();
    let quantity = i64::from(quantity);
    let initial = comment.unwrap_or("");
    let replace_comment = comment.is_some();
    sqlx::query!(
        "INSERT INTO cart_item (session_hash, sku, quantity, comment, added_at) VALUES (?1, ?2, ?3, ?4, ?5)
         ON CONFLICT (session_hash, sku) DO UPDATE SET quantity = excluded.quantity,
             comment = CASE WHEN ?6 THEN excluded.comment ELSE cart_item.comment END",
        hash,
        sku,
        quantity,
        initial,
        now,
        replace_comment
    )
    .execute(pool)
    .await?;
    Ok(())
}

async fn remove(pool: &SqlitePool, session: &TokenHash, sku: &str) -> sqlx::Result<()> {
    let hash = session.as_slice();
    sqlx::query!("DELETE FROM cart_item WHERE session_hash = ?1 AND sku = ?2", hash, sku).execute(pool).await?;
    Ok(())
}

pub async fn clear(pool: &SqlitePool, session: &TokenHash) -> sqlx::Result<()> {
    let hash = session.as_slice();
    sqlx::query!("DELETE FROM cart_item WHERE session_hash = ?1", hash).execute(pool).await?;
    Ok(())
}

/// Prices every line. A SKU the catalog no longer offers to this account leaves the cart.
pub async fn lines(state: &Orders, shopper: &Shopper) -> sqlx::Result<Vec<Line>> {
    let pool = &state.core.pool;
    let mut out = Vec::new();
    for item in items(pool, &shopper.session).await? {
        match state.pricing.price(pool, shopper.user.id, &item.sku, item.quantity).await? {
            Some(priced) => {
                let thumbnail = thumbnail(state, &item.sku).await?;
                out.push(Line { sku: item.sku, description: priced.description, quantity: item.quantity, comment: item.comment, price: priced.price, thumbnail });
            }
            None => remove(pool, &shopper.session, &item.sku).await?,
        }
    }
    Ok(out)
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
        r.push(format!("<td class=nobr>{}</td>", esc(&line.sku)));
        let img = line.thumbnail.as_ref().map_or(String::new(), |src| format!("<img src='{}' width='{thumb_size}' height='{thumb_size}' border='0'>", esc(src)));
        r.push(format!("<td class=center>{img}</td>"));
        let mut content = vec![esc(&line.description).replace('\n', "<br>")];
        if entry {
            content.push(format!("<br>{}", html::text_input("text", &comment_field, &line.comment, 40, 60, "Comment (optional)", false)));
        } else if !line.comment.is_empty() {
            content.push(format!("<br>Comment: {}", esc(&line.comment)));
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

/// PHP answers the quantity as a float (`5.0`); it is a count, never money.
fn php_float<S: serde::Serializer>(d: &Decimal, s: S) -> Result<S::Ok, S::Error> {
    use rust_decimal::prelude::ToPrimitive;
    s.serialize_f64(d.to_f64().unwrap_or(0.0))
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
    let requested = fn_number(params.get("json_quantity").map_or("", String::as_str)).abs();
    let sku_text = sku.clone().unwrap_or_default();
    let status = match quantity(requested) {
        None if requested.is_zero() => {
            remove(pool, &shopper.session, &sku_text).await?;
            format!("{sku_text} removed from cart")
        }
        Some(q) if !sku_text.is_empty() && state.pricing.price(pool, shopper.user.id, &sku_text, q).await?.is_some() => {
            put(pool, &shopper.session, &sku_text, q, None, unix_now()).await?;
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
    let cart_extended = if missing { "Pricing available upon request.".to_string() } else { format!("${}", number_format(total, 2)) };
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

pub async fn page(State(state): State<Arc<Orders>>, visitor: Visitor, headers: HeaderMap, uri: Uri, body: Bytes) -> Result<Response, ApiError> {
    let pool = &state.core.pool;
    let framed = html::framed(uri.query(), &headers);
    let params = form_params(uri.query(), &body);
    let shopper = shopper(pool, visitor).await?;
    if headers.contains_key("x-requested-with") {
        return match shopper {
            Some(s) => ajax(&state, &s, &params).await,
            None => Ok(html::page_response(String::new())),
        };
    }
    let Some(shopper) = shopper else { return Ok(html::redirect(if framed { "/" } else { "/scs_index.php" })) };
    let mut messages = vec!["Shopping Cart".to_string()];
    match params.get("action").map(String::as_str) {
        Some("checkout") => return Ok(html::redirect("/checkout.php")),
        Some("clear") => {
            clear(pool, &shopper.session).await?;
            messages.push("Shopping Cart Cleared".into());
        }
        Some("update") => {
            for item in items(pool, &shopper.session).await? {
                let typed = params.get(&fn_base64(&["quantity", &item.sku])).map_or("", String::as_str);
                let comment = params.get(&fn_base64(&["comment", &item.sku])).map(|c| c.trim().to_string());
                match typed.trim().parse::<u32>().ok().filter(|q| *q > 0) {
                    Some(q) => put(pool, &shopper.session, &item.sku, q, comment.as_deref(), unix_now()).await?,
                    None => remove(pool, &shopper.session, &item.sku).await?,
                }
            }
            messages.push("Shopping Cart Updated".into());
        }
        _ => {}
    }
    let lines = lines(&state, &shopper).await?;
    let labels = Labels::load(pool, &shopper.language).await?;
    let thumb = crate::orders::mailform::Registry::load(pool, "local").await?.get("thumbnail").to_string();
    let money = MoneyFormat { order: &shopper.currency, viewer: &shopper.currency.code };
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
    fn json_escapes_like_php() {
        let expected = concat!(r#"{"s":"a\/b "#, "\\", r#"u00e9"}"#);
        assert_eq!(php_json("{\"s\":\"a/b \u{e9}\"}"), expected);
    }
}
