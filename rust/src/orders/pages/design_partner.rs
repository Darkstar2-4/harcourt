//! design_partner_agreement.php: which categories a company may not see until it signs a Design Partner Agreement
//! (`company_category_block`). Administrators only; the page loads and saves over its own AJAX calls.

use std::{collections::HashMap, sync::Arc};

use axum::{
    body::Bytes,
    extract::State,
    http::{HeaderMap, Uri},
    response::Response,
};
use sqlx::SqlitePool;

use crate::{
    api::{Administrator, ApiError, ClientIp},
    db::unix_now,
    orders::{
        form_params,
        html::{self, esc},
        shop::cart::php_json,
        Orders, Staff,
    },
};

const SCRIPT: &str = r##"<style>
#dpa_message.dpa-message-success { background-color:#d4edda; color:#155724; padding:8px 10px; border-radius:4px; }
#dpa_message.dpa-message-error { background-color:#f8d7da; color:#721c24; padding:8px 10px; border-radius:4px; }
ul.ui-autocomplete { max-height:220px !important; overflow-y:auto !important; overflow-x:hidden !important; }
</style>
<script>
function design_partner_agreement_flash_message(status) {
    var el = jQuery("#dpa_message");
    el.stop(true, true).show();
    el.removeClass("dpa-message-success dpa-message-error");
    jQuery(document).off(".dpaMessage");
    if (status != "success" && status != "error") return;
    el.addClass(status == "success" ? "dpa-message-success" : "dpa-message-error");
    var shown_at = Date.now();
    var min_visible_ms = 1200;
    jQuery(document).on("mousemove.dpaMessage click.dpaMessage keydown.dpaMessage scroll.dpaMessage", function() {
        if (Date.now() - shown_at < min_visible_ms) return;
        jQuery(document).off(".dpaMessage");
        el.fadeOut(600, function() {
            el.html("&nbsp;").removeClass("dpa-message-success dpa-message-error").show();
        });
    });
}
function design_partner_agreement_load() {
    var company = jQuery("#dpa_company_name").val().trim();
    jQuery.ajax({
        type: "post",
        dataType: "json",
        data: { action: "load", company_name: company },
        success: function(response) {
            jQuery("#dpa_message").html(response['message']);
            jQuery("#dpa_content").html(response['content']);
            scscpq_ipc("scscpq_wp_iframe", "resize", jQuery("#scs_content").height());
        },
        error: function(xhr) {
            console.log(xhr);
        }
    });
}
function design_partner_agreement_save() {
    var data = { action: "save", company_name: jQuery("#dpa_company_name").val().trim() };
    jQuery("#dpa_content input[type=checkbox]").each(function() {
        data[jQuery(this).attr("name")] = jQuery(this).is(":checked") ? 1 : 0;
    });
    jQuery.ajax({
        type: "post",
        dataType: "json",
        data: data,
        success: function(response) {
            jQuery("#dpa_message").html(response['message']);
            design_partner_agreement_flash_message(response['status']);
            jQuery("#dpa_content").html(response['content']);
            scscpq_ipc("scscpq_wp_iframe", "resize", jQuery("#scs_content").height());
        },
        error: function(xhr) {
            console.log(xhr);
        }
    });
}
jQuery(function() {
    scscpq_ipc("scscpq_wp_iframe", "resize", jQuery("#scs_content").height());
    jQuery('#dpa_company_name').autocomplete({
        source: '/scs_ajax.php?table=user&source=company_name',
        minLength: 2,
        select: function(event, ui) {
            jQuery('#dpa_company_name').val(ui.item.value);
            design_partner_agreement_load();
            return false;
        },
        html: true,
        open: function(event, ui) {
            jQuery('.ui-autocomplete').css('z-index', 1000);
            var input_bottom = jQuery('#dpa_company_name').offset().top + jQuery('#dpa_company_name').outerHeight();
            scscpq_ipc("scscpq_wp_iframe", "resize", input_bottom + 240);
        }
    });
});
</script>
"##;

/// The JSON answer, keys in the order the PHP set them.
#[derive(Default)]
struct Answer(Vec<(&'static str, String)>);

impl Answer {
    fn with(mut self, key: &'static str, value: impl Into<String>) -> Self {
        self.0.push((key, value.into()));
        self
    }

    fn json(&self) -> String {
        if self.0.is_empty() {
            return "[]".into();
        }
        let parts: Vec<String> = self.0.iter().map(|(k, v)| format!("\"{k}\":{}", serde_json::Value::String(v.clone()))).collect();
        php_json(&format!("{{{}}}", parts.join(",")))
    }
}

const REQUIRED: &str = "<span style='color:red;'>Company name is required</span>";

async fn company_exists(pool: &SqlitePool, company: &str) -> sqlx::Result<bool> {
    Ok(sqlx::query_scalar!(r#"SELECT id AS "id!: i64" FROM "user" WHERE company_name = ?1 LIMIT 1"#, company).fetch_optional(pool).await?.is_some())
}

/// Replaces the company's blocked categories with the active categories ticked in the post, in one transaction.
async fn save(pool: &SqlitePool, company: &str, posted: &HashMap<String, String>) -> sqlx::Result<()> {
    let mut tx = pool.begin().await?;
    sqlx::query!("DELETE FROM company_category_block WHERE company_name = ?1", company).execute(&mut *tx).await?;
    let ids = sqlx::query_scalar!(r#"SELECT id AS "id!: i64" FROM category WHERE active = 1"#).fetch_all(&mut *tx).await?;
    for id in ids {
        let ticked = posted.get(&format!("company_category_{id}")).is_some_and(|v| !v.is_empty() && v != "0");
        if ticked {
            sqlx::query!("INSERT INTO company_category_block (company_name, category_id) VALUES (?1, ?2)", company, id).execute(&mut *tx).await?;
        }
    }
    tx.commit().await
}

async fn output(pool: &SqlitePool, company: &str) -> sqlx::Result<String> {
    let blocked = sqlx::query_scalar!(r#"SELECT category_id AS "id!: i64" FROM company_category_block WHERE company_name = ?1"#, company).fetch_all(pool).await?;
    let categories = sqlx::query!(r#"SELECT id AS "id!: i64", coalesce(name, '') AS "name!: String" FROM category WHERE active = 1 ORDER BY sort_order, name"#).fetch_all(pool).await?;
    let mut r = vec![
        "<table class='standard noborder'>".to_string(),
        format!("<tr><td colspan=2>Check to restrict {}'s access to a category (Design Partner Agreement required)</td></tr>", esc(company)),
    ];
    for c in categories {
        let checked = if blocked.contains(&c.id) { " checked" } else { "" };
        r.push("<tr>".into());
        r.push(format!(
            "<td><label style='display:flex;align-items:center;gap:8px;cursor:pointer;'><input type=checkbox name='company_category_{0}' id='company_category_{0}' value='1'{checked}>{1}</label></td>",
            c.id,
            esc(&c.name)
        ));
        r.push("</tr>".into());
    }
    r.push("</table>".into());
    r.push(format!("<p>{}</p>", html::button("Save", "design_partner_agreement_save();", "nav_button")));
    Ok(r.concat())
}

async fn ajax(pool: &SqlitePool, ip: &str, staff_id: i64, posted: &HashMap<String, String>) -> Result<Answer, ApiError> {
    let company = posted.get("company_name").map_or("", |s| s.trim());
    Ok(match posted.get("action").map(String::as_str) {
        Some("load") if company.is_empty() => Answer::default().with("message", REQUIRED).with("content", "&nbsp;"),
        Some("load") if !company_exists(pool, company).await? => Answer::default()
            .with("message", format!("<span style='color:red;'>No users found for company &ldquo;{}&rdquo;</span>", esc(company)))
            .with("content", "&nbsp;"),
        Some("load") => Answer::default().with("message", "&nbsp;").with("content", output(pool, company).await?),
        Some("save") if company.is_empty() => Answer::default().with("message", REQUIRED).with("status", "error"),
        Some("save") => {
            save(pool, company, posted).await?;
            let comment = format!("Design Partner Agreement categories updated: {company}");
            let now = unix_now();
            if let Err(e) = sqlx::query!("INSERT INTO log (ip, date_time, user_id, type, subtype, comment) VALUES (?1, ?2, ?3, 'DesignPartnerAgreement', 'Update', ?4)", ip, now, staff_id, comment).execute(pool).await {
                tracing::warn!("design partner log: {e}");
            }
            Answer::default().with("message", format!("{} categories updated", esc(company))).with("status", "success").with("content", output(pool, company).await?)
        }
        _ => Answer::default(),
    })
}

pub async fn page(State(state): State<Arc<Orders>>, Staff(staff): Staff<Administrator>, ClientIp(ip): ClientIp, headers: HeaderMap, uri: Uri, body: Bytes) -> Result<Response, ApiError> {
    let pool = &state.core.pool;
    if headers.contains_key("x-requested-with") && !body.is_empty() {
        let answer = ajax(pool, &ip, staff.user.id.0, &form_params(None, &body)).await?;
        return Ok(html::page_response(answer.json()));
    }
    let chrome = html::Chrome::load(pool).await?;
    let head = html::Head { title: "Design Partner Agreement", description: None, js: &[], framed: html::framed(uri.query(), &headers), dashboard: true, catalog_url: "/catalog.php" };
    let mut out = html::open(&chrome, &head);
    out.push_str(&html::message(&["Design Partner Agreement".to_string()], 0));
    out.push_str(SCRIPT);
    out.push_str(&html::form_open("/design_partner_agreement.php"));
    out.push_str(&format!("<p>Company Name: {} {}</p>", html::text_input("text", "dpa_company_name", "", 40, 100, "", false), html::button("Load", "design_partner_agreement_load();", "nav_button")));
    out.push_str("<div id=dpa_message style='padding:10px 0;'></div><div id=dpa_content></div>");
    out.push_str(html::FORM_CLOSE);
    out.push_str(html::close());
    Ok(html::page_response(out))
}
