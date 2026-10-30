//! configure.php: the staff pricing and CAD lookup for a part number. Internal staff and above; anyone else is
//! sent home, as `user->access("Internal")` did. The form opens on the signed-in account's own price terms and
//! currency.

use std::sync::Arc;

use axum::{extract::State, response::Response, routing::get, Router};
use sqlx::SqlitePool;

use crate::{
    api::{Auth, Internal},
    identity::Role,
    staff::{
        choices,
        html::{submit, Blank, Form, Notes, Opts, Tabs},
        shell, Staff,
    },
    visitor::Visitor,
};

const PATH: &str = "/configure.php";
/// The page title as forms_class printed it: the message line unescaped, the <title> escaped by the shell.
const TITLE: &str = "Pricing & CAD (08/22/2024)";

pub fn router() -> Router<Arc<Auth>> {
    Router::new().route(PATH, get(show))
}

/// What the form holds: the account's own terms until something is posted.
struct Input {
    sku: String,
    quantity: i64,
    price_type: &'static str,
    /// A fraction: 0.15 is 15%.
    price_pct: f64,
    currency_code: String,
}

fn price_options() -> Vec<(String, String)> {
    [("", "No pricing"), ("base", "Base price"), ("discount", "Discount"), ("plus", "Base plus")].iter().map(|(v, l)| (v.to_string(), l.to_string())).collect()
}

/// html_enter(): the entry table and its button. Administrators also choose the currency and may debug.
async fn enter(pool: &SqlitePool, input: &Input, role: Role) -> sqlx::Result<String> {
    let notes = Notes::new("", "");
    let form = Form { notes: &notes };
    let mut out = vec!["<table class='standard noborder'>".to_string(), "<tr>".into(), "<td width='20%'>SKU</td>".into()];
    out.push(format!("<td width='80%'>{}</td>", form.textarea("sku", &input.sku, 3, 90, Opts::new().set("placeholder", "Enter SKU"))));
    out.extend(["</tr>".into(), "<tr>".into(), "<td>Quantity</td>".into()]);
    out.push(format!("<td>{}</td>", form.text("quantity", &input.quantity.to_string(), 6, 6, Opts::new())));
    out.extend(["</tr>".into(), "<tr>".into()]);
    out.push(format!("<td>{}</td>", form.select("price_type", &price_options(), input.price_type, Blank::None, Opts::new().onchange("$('#price_pct').val('');"))));
    let pct = (input.price_pct * 100.0 * 100.0).round() / 100.0;
    let pct = if pct == 0.0 { String::new() } else { format!("{pct:.2}") };
    out.push(format!("<td>{}%</td>", form.text("price_pct", &pct, 6, 6, Opts::new())));
    out.push("</tr>".into());
    if role >= Role::Administrator {
        out.extend(["<tr>".into(), "<td>Currency</td>".into()]);
        out.push(format!("<td>{}</td>", form.optgroup("currency_code", &choices::currencies(pool).await?, &input.currency_code, false, Opts::new())));
        out.extend(["</tr>".into(), "<tr>".into(), "<td>Debug?</td>".into()]);
        out.push(format!("<td>{}</td>", form.checkbox("debug", "1", false, Opts::new())));
        out.push("</tr>".into());
    }
    out.push("</table>".into());
    out.push(format!("<p>{}</p>", submit("Enter", Opts::new().class("configure_button"))));
    Ok(out.concat())
}

async fn show(State(core): State<Arc<Auth>>, staff: Staff<Internal>, visitor: Visitor) -> Response {
    let account = visitor.account();
    let input = Input {
        sku: String::new(),
        quantity: 1,
        price_type: account.map_or("", |a| a.price_type.as_str()),
        price_pct: account.map_or(0.0, |a| a.price_pct),
        currency_code: account.map_or_else(|| "USD".to_string(), |a| a.currency_code.clone()),
    };
    let wp = staff.wp || visitor.framed;
    let body = async {
        let mut tabs = Tabs::named("standard");
        tabs.in_wordpress(wp);
        tabs.item("Enter", enter(&core.pool, &input, staff.who.user.role).await?);
        let open = crate::staff::html::open(PATH, staff.csrf.as_str(), false);
        Ok::<_, sqlx::Error>(format!(
            "<p class='message left'>{TITLE}</p><script>\n\n\n</script>{open}{}{}{}",
            crate::staff::html::hidden("action", "list"),
            tabs.render(),
            crate::staff::html::close()
        ))
    }
    .await;
    match body {
        Ok(body) => shell::page(&core.pool, shell::View { role: staff.who.user.role, wp }, TITLE, &body).await,
        Err(e) => shell::internal(e),
    }
}
