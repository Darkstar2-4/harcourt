//! currency.php: the currency codes, their exchange rate from US dollars and the adder on top of it.

use sqlx::SqlitePool;

use crate::{
    api::Administrator,
    staff::{
        check,
        html::{button, href, js, number, number_dp, text, Form, Notes, Opts},
        maintain::{Column, Saved, Table, Widget},
        time,
    },
};

pub struct Currency {
    code: String,
    name: String,
    plural: String,
    symbol: String,
    exchange_rate: f64,
    /// A fraction: 0.5 is a 50% adder.
    adder_pct: f64,
    update_timestamp: i64,
    active: bool,
}

/// currency_data_class: a new code starts active.
impl Default for Currency {
    fn default() -> Self {
        Currency {
            code: String::new(),
            name: String::new(),
            plural: String::new(),
            symbol: String::new(),
            exchange_rate: 0.0,
            adder_pct: 0.0,
            update_timestamp: 0,
            active: true,
        }
    }
}

const SCRIPT: &str = r##"
<script>
jQuery(document).ready(function() {
	fn_exchange_rate();
});
function fn_exchange_rate() {
	if (jQuery("#code").val() == "USD") {
    	jQuery(".exchange_rate_tr").hide();
	  } else {
    	jQuery(".exchange_rate_tr").show();
    	jQuery("#exchange_rate_currency").html(jQuery("#code").val());
    }
}
function fn_action(obj,action,id,name) {
	if ( (action=='delete') && (!confirm("Delete: #" + id + " " + name)) ) {
	    obj.checked=false;
	    return;
    }
	jQuery("#action").val(action);
	jQuery("#record_id").val(id);
	jQuery("#scs_form").submit();
}
</script>
"##;

/// The `decimal:N` text type: blank for zero, else number_format.
fn decimal(n: f64, places: usize) -> String {
    if n == 0.0 {
        String::new()
    } else {
        number_dp(n, places)
    }
}

fn exchange_net(rate: f64, adder: f64) -> f64 {
    (rate * (1.0 + adder) * 10_000.0).round() / 10_000.0
}

fn set_code(c: &mut Currency, v: &str, n: &mut Notes) {
    c.code = check::posted_text(v).to_uppercase();
    if c.code.is_empty() {
        n.error("code", Some("Must be 3 characters"));
    }
}

fn set_name(c: &mut Currency, v: &str, n: &mut Notes) {
    c.name = v.trim().to_string();
    if c.name.is_empty() {
        n.error("name", Some("Cannot be blank"));
    }
}

fn set_plural(c: &mut Currency, v: &str, n: &mut Notes) {
    c.plural = v.trim().to_string();
    if c.plural.is_empty() {
        n.error("plural", Some("Cannot be blank"));
    }
}

/// US dollars are the base: their rate is 1 whatever was posted, and their adder is left as it was.
fn set_exchange_rate(c: &mut Currency, v: &str, n: &mut Notes) {
    if c.code == "USD" {
        c.exchange_rate = 1.0;
        return;
    }
    c.exchange_rate = check::posted_number(v, 4).try_into().unwrap_or(0.0);
    if c.exchange_rate < 0.0 {
        n.error("exchange_rate", Some("Must be >= 0.0000"));
    }
}

fn set_adder_pct(c: &mut Currency, v: &str, n: &mut Notes) {
    if c.code == "USD" {
        return;
    }
    c.adder_pct = check::posted_number(&format!("{v}%"), 2).try_into().unwrap_or(0.0);
    if c.exchange_rate == 0.0 && c.adder_pct != 0.0 {
        n.error("adder_pct", Some("Must be 0.00% if no exchange rate"));
    } else if c.adder_pct <= -1.0 || c.adder_pct >= 10.0 {
        n.error("adder_pct", Some("Must between -100% and + 1000%"));
    }
}

impl Table for Currency {
    type Role = Administrator;
    const PATH: &'static str = "/currency.php";
    const TITLE: &'static str = "Currency Codes";
    const COLUMNS: &'static [Column<Self>] = &[
        Column { name: "code", label: "Code", widget: Widget::Text { size: 3, max: 3 }, get: |c| c.code.clone(), set: set_code },
        Column { name: "name", label: "Name", widget: Widget::Text { size: 60, max: 60 }, get: |c| c.name.clone(), set: set_name },
        Column { name: "plural", label: "Plural", widget: Widget::Text { size: 60, max: 60 }, get: |c| c.plural.clone(), set: set_plural },
        Column { name: "symbol", label: "Currency Symbol", widget: Widget::Text { size: 20, max: 20 }, get: |c| c.symbol.clone(), set: |c, v, _| c.symbol = v.trim().to_string() },
        Column {
            name: "exchange_rate",
            label: "Base Exchange Rate",
            widget: Widget::Text { size: 12, max: 12 },
            get: |c| decimal(c.exchange_rate, 4),
            set: set_exchange_rate,
        },
        Column { name: "adder_pct", label: "Adder", widget: Widget::Text { size: 6, max: 6 }, get: |c| decimal(c.adder_pct * 100.0, 2), set: set_adder_pct },
        Column { name: "active", label: "Active?", widget: Widget::Checkbox, get: |c| u8::from(c.active).to_string(), set: |c, v, _| c.active = !matches!(v.trim(), "" | "0") },
    ];

    fn script() -> &'static str {
        SCRIPT
    }

    fn buttons(id: i64) -> String {
        let update = button("Update", Opts::new().onclick(format!("fn_action(this,'update',{},'');", js(&id.to_string()))));
        let cancel = button("Cancel", Opts::new().onclick("fn_action(this,'cancel','','');"));
        format!("<p class='standard'>{update}\n{cancel}</p>\n")
    }

    fn deleted(&self, notes: &mut Notes) {
        notes.say(&format!("Currency {} deleted", self.code));
    }

    fn edit(&self, form: &Form<'_>, _wp: bool) -> String {
        let field = |name: &str, value: &str, size: u32| form.notes.with_error(name, form.text(name, value, size, size, Opts::new()));
        let mut out = String::from("<table class='noborder'>");
        out.push_str(&format!("<tr><td width='30%'>Code</td><td width='70%'>{}</td></tr>", field("code", &self.code, 3)));
        out.push_str(&format!("<tr><td>Name</td><td>{}</td></tr>", field("name", &self.name, 60)));
        out.push_str(&format!("<tr><td>Plural</td><td>{}</td></tr>", field("plural", &self.plural, 60)));
        let label = if self.symbol.len() > 1 { format!("Currency Symbol: {}", text(&self.symbol)) } else { "Currency Symbol".to_string() };
        out.push_str(&format!("<tr><td>{label}</td><td>{}</td></tr>", form.text("symbol", &self.symbol, 20, 20, Opts::new())));
        let rate = form.notes.with_error("exchange_rate", format!("$1.00 USD = {} <span id=exchange_rate_currency></span>", form.text("exchange_rate", &decimal(self.exchange_rate, 4), 12, 12, Opts::new())));
        out.push_str(&format!("<tr class=exchange_rate_tr><td>Base Exchange Rate</td><td>{rate}</td></tr>"));
        let adder = form.notes.with_error("adder_pct", format!("{}%", form.text("adder_pct", &decimal(self.adder_pct * 100.0, 2), 6, 6, Opts::new())));
        out.push_str(&format!("<tr class=exchange_rate_tr><td>Adder</td><td>{adder}</td></tr>"));
        if self.update_timestamp != 0 {
            out.push_str(&format!("<tr><td>Revised</td><td>{}</td></tr>", time::date("m/d/Y h:i A", self.update_timestamp)));
        }
        out.push_str(&format!("<tr><td>Active?</td><td>{}</td></tr>", form.checkbox("active", "1", self.active, Opts::new())));
        out.push_str("</table>");
        out
    }

    async fn read(pool: &SqlitePool, id: i64) -> sqlx::Result<Option<Self>> {
        let row = sqlx::query!(
            r#"SELECT code, name, plural, symbol, exchange_rate, adder_pct, update_timestamp, active FROM currency WHERE id = ?1"#,
            id
        )
        .fetch_optional(pool)
        .await?;
        Ok(row.map(|r| Currency {
            code: r.code.unwrap_or_default(),
            name: r.name.unwrap_or_default(),
            plural: r.plural.unwrap_or_default(),
            symbol: r.symbol.unwrap_or_default(),
            exchange_rate: r.exchange_rate.unwrap_or(0.0),
            adder_pct: r.adder_pct.unwrap_or(0.0),
            update_timestamp: r.update_timestamp.unwrap_or(0),
            active: r.active.unwrap_or(0) != 0,
        }))
    }

    async fn save(&self, pool: &SqlitePool, id: i64) -> sqlx::Result<Saved> {
        let taken = sqlx::query!(
            r#"SELECT code = ?2 AS "code!: bool", plural = ?4 AS "plural!: bool" FROM currency
               WHERE id <> ?1 AND (code = ?2 OR name = ?3 OR plural = ?4) LIMIT 1"#,
            id,
            self.code,
            self.name,
            self.plural
        )
        .fetch_optional(pool)
        .await?;
        if let Some(t) = taken {
            return Ok(match (t.code, t.plural) {
                (true, _) => Saved::Conflict { field: "code", text: "Duplicate code" },
                (false, true) => Saved::Conflict { field: "plural", text: "Duplicate plural" },
                (false, false) => Saved::Conflict { field: "name", text: "Duplicate name" },
            });
        }
        let stamp = if self.exchange_rate != 0.0 { crate::db::unix_now() } else { 0 };
        let symbol = (!self.symbol.is_empty()).then_some(self.symbol.as_str());
        let active = i64::from(self.active);
        if id == 0 {
            sqlx::query!(
                "INSERT INTO currency (code, name, plural, symbol, exchange_rate, adder_pct, update_timestamp, active) VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8)",
                self.code, self.name, self.plural, symbol, self.exchange_rate, self.adder_pct, stamp, active
            )
            .execute(pool)
            .await?;
        } else {
            sqlx::query!(
                "UPDATE currency SET code = ?2, name = ?3, plural = ?4, symbol = ?5, exchange_rate = ?6, adder_pct = ?7, update_timestamp = ?8, active = ?9 WHERE id = ?1",
                id, self.code, self.name, self.plural, symbol, self.exchange_rate, self.adder_pct, stamp, active
            )
            .execute(pool)
            .await?;
        }
        Ok(Saved::Done)
    }

    async fn delete(pool: &SqlitePool, id: i64) -> sqlx::Result<()> {
        sqlx::query!("DELETE FROM currency WHERE id = ?1", id).execute(pool).await?;
        Ok(())
    }

    async fn list(pool: &SqlitePool, form: &Form<'_>) -> sqlx::Result<String> {
        let rows = sqlx::query!(
            r#"SELECT id AS "id!: i64", code, name, symbol, exchange_rate, adder_pct, update_timestamp, active FROM currency
               ORDER BY CASE WHEN code = 'USD' THEN 1 WHEN exchange_rate > 0 THEN 2 ELSE 3 END, name"#
        )
        .fetch_all(pool)
        .await?;
        let mut out = String::from("<table class='standard border tablesorter'><caption>&nbsp;</caption><thead><tr><th>Code</th><th>Name</th><th>Symbol</th><th>Exchange Rate</th><th>Adder%</th><th>$1.00 USD Buys</th><th>Revised</th><th>Del?</th></tr></thead>");
        out.push_str(&format!("<tr><td colspan=8>{}</td></tr><tbody>", href("Add new", "javascript:fn_action(this,'maintain','0','');", Opts::new())));
        let count = rows.len();
        for r in rows {
            let id = r.id.to_string();
            let name = r.name.unwrap_or_default();
            let maintain = format!("javascript:fn_action(this,'maintain',{},'');", js(&id));
            out.push_str(&format!("<tr><td>{}</td><td>{}</td>", href(&text(&r.code.unwrap_or_default()), &maintain, Opts::new()), text(&name)));
            out.push_str(&format!("<td class=center>{}</td>", text(&r.symbol.unwrap_or_default())));
            let (rate, adder) = (r.exchange_rate.unwrap_or(0.0), r.adder_pct.unwrap_or(0.0));
            if rate > 0.0 {
                out.push_str(&format!(
                    "<td class=center>{}</td><td class=center>{}%</td><td class=center>{}</td><td class=center>{}</td>",
                    number_dp(rate, 4),
                    number_dp(100.0 * adder, 2),
                    number_dp(exchange_net(rate, adder), 4),
                    time::date("m/d/Y", r.update_timestamp.unwrap_or(0))
                ));
            } else {
                out.push_str("<td colspan=4>&nbsp;</td>");
            }
            let delete = if r.active.unwrap_or(0) != 0 {
                String::new()
            } else {
                form.radio("delete", &id, false, Opts::new().onclick(format!("fn_action(this,'delete',{},{});", js(&id), js(&name))))
            };
            out.push_str(&format!("<td class=center>{delete}</td></tr>"));
        }
        out.push_str(&format!("</tbody></table><p class=center>{} Currency Codes Found</p>", number(count as i64)));
        Ok(out)
    }
}
