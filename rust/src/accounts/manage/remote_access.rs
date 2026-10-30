//! remote_access.php: which company domain signs people in from outside the office. Staff load a company by
//! name, see the addresses its active accounts signed in from, and give the domain to one of them (or to none).
//! A person who proves an address at that domain is then signed in as that account (accounts::admission).
//! Every call needs an Administrator.

use std::{net::IpAddr, sync::Arc};

use axum::{
    body::Bytes,
    extract::State,
    http::{HeaderMap, Method, StatusCode, Uri},
    response::{IntoResponse, Response},
    routing::MethodRouter,
};
use base64::{engine::general_purpose::STANDARD_NO_PAD, Engine};
use sqlx::SqlitePool;

use super::listed::{from_page_script, json};
use crate::{
    accounts::html::json_encode,
    api::{Administrator, Auth},
    staff::{
        csrf::Fields,
        html::{button, esc, hidden, href, Form as Widgets, Notes, Opts},
        shell, time, Staff,
    },
};

const TITLE: &str = "Company Remote Access";

const SCRIPT: &str = r##"<script>
function fn_domain_load() {
	jQuery.ajax({
        type: "post",
	    dataType: "json",
		data: { action: "load", company_name: jQuery("#company_name").val() },
	    success: function(response) {
	        for (var key in response) {
           		jQuery("#" + key).html(response[key]);
            }
            scscpq_ipc("scscpq_wp_iframe", "resize", jQuery("#scs_content").height());
        },
        error: function(xhr, response) {
			jQuery("#error_div").html(xhr.responseText);
        }
	});
}
function fn_resolve_host(id) {
	jQuery.ajax({
        type: "post",
	    dataType: "json",
		data: { action: "host", id: id },
	    success: function(response) {
        	jQuery("#" + id).html(response[id]);
        },
        error: function(xhr, response) {
			jQuery("#error_div").html(xhr.responseText);
        }
	});
}
function fn_update() {
	jQuery.ajax({
        type: "post",
	    dataType: "json",
		data: { action: "update", company_name: jQuery("#local_company").val(), domain: jQuery("#domain").val(), ip: jQuery("input[name=access]:checked").val() },
	    success: function(response) {
			jQuery("#error_div").html("&nbsp;");
	        for (var key in response) {
           		jQuery("#" + key).html(response[key]);
            }
        },
        error: function(xhr, response) {
			jQuery("#error_div").html(xhr.responseText);
        }
	});
}
jQuery(function() {
    jQuery('#company_name').autocomplete({
        source: '/scs_ajax.php?table=user&source=company_name',
        minLength: 2,
        select: function(event, ui) {
        },
        html: true,
        open: function(event, ui) {
        	jQuery('.ui-autocomplete').css('z-index', 1000);
        }
    });
});
</script>
"##;

const BLANK: &str = "&nbsp;";

async fn page(pool: &SqlitePool, staff: &Staff<Administrator>) -> Response {
    let notes = Notes::new(TITLE, "");
    let w = Widgets { notes: &notes };
    let mut body = notes.render();
    body.push_str(SCRIPT);
    body.push_str(&format!(
        "<p>Company Name: {} {} <span id=company_error></span> <span id=ajax_user_ip></span></p>",
        w.text("company_name", "", 40, 80, Opts::new()),
        button("Load", Opts::new().onclick("fn_domain_load();"))
    ));
    body.push_str("<div id=error_div></div><div id=domain_div></div><div id=results_div></div><div style='height: 800px;'>&nbsp;</div>");
    shell::page(pool, staff.view(), TITLE, &body).await
}

/// The id of a host cell: base64 of "ip", a tab and the address, without padding (fn_base64).
fn host_field(ip: &str) -> String {
    STANDARD_NO_PAD.encode(format!("ip\t{ip}"))
}

fn host_address(field: &str) -> Option<IpAddr> {
    let bytes = STANDARD_NO_PAD.decode(field.trim_end_matches('=')).ok()?;
    let text = String::from_utf8(bytes).ok()?;
    let (tag, ip) = text.split_once('\t')?;
    (tag == "ip").then(|| ip.parse().ok()).flatten()
}

struct Address {
    ip: String,
    last_login: i64,
    role: String,
    primary: bool,
}

/// The "load" call: the company's active accounts that have signed in, one row per address.
async fn load(pool: &SqlitePool, company: &str) -> sqlx::Result<Vec<(String, String)>> {
    let mut out = vec![
        ("company_error".to_string(), BLANK.to_string()),
        ("domain_div".to_string(), BLANK.to_string()),
        ("results_div".to_string(), BLANK.to_string()),
        ("error_div".to_string(), BLANK.to_string()),
    ];
    if company.is_empty() {
        out[0].1 = "Cannot be blank".into();
        return Ok(out);
    }
    let rows = sqlx::query!(
        r#"SELECT ip, domain, last_login, "type" AS role FROM "user"
           WHERE status = 'Active' COLLATE NOCASE AND company_name = ?1 COLLATE NOCASE AND last_login <> 0
           ORDER BY domain DESC, last_login DESC, ip"#,
        company
    )
    .fetch_all(pool)
    .await?;
    if rows.is_empty() {
        out[0].1 = format!("No {} users found", esc(company));
        return Ok(out);
    }
    let mut domain = String::new();
    let mut addresses: Vec<Address> = Vec::new();
    for r in rows {
        let own = r.domain.unwrap_or_default();
        if !own.is_empty() {
            domain = own.clone();
        }
        let ip = r.ip.unwrap_or_default();
        if !addresses.iter().any(|a| a.ip == ip) {
            addresses.push(Address { ip, last_login: r.last_login, role: r.role, primary: !own.is_empty() });
        }
    }
    let notes = Notes::new(TITLE, "");
    let w = Widgets { notes: &notes };
    let mut html = hidden("local_company", company);
    html.push_str("<table class='standard border tablesorter'><caption >&nbsp</caption>\n");
    html.push_str("<thead><tr><th width='10%'>Primary?</th><th width='20%'>IP Address</th><th width='50%'>Host Name</th><th width='10%'>Role</th><th width='10%'>Last Login</th></tr></thead>");
    let none = w.radio("access", "0.0.0.0", domain.is_empty(), Opts::new().class("remote_access"));
    html.push_str(&format!("<tr><td class=center>{none}</td><td colspan=3>No remote access</td></tr></tr><tbody>"));
    for a in &addresses {
        let field = host_field(&a.ip);
        html.push_str(&format!(
            "<tr><td class=center>{}</td><td>{}</td><td id={field}>{}</td><td>{}</td><td class=center>{}</td></tr>",
            w.radio("access", &a.ip, a.primary, Opts::new()),
            esc(&a.ip),
            href("Resolve Host", &format!("javascript:fn_resolve_host('{field}');"), Opts::new()),
            esc(&a.role),
            time::date("m/d/Y", a.last_login)
        ));
    }
    html.push_str("</tbody></table><script>jQuery('.tablesorter').tablesorter('destroy');jQuery('.tablesorter').tablesorter();</script>");
    out[2].1 = html;
    out[1].1 = format!(
        "Domain: {} {} <span id=domain_status><span>",
        w.text("domain", &domain, 40, 40, Opts::new()),
        button("Update domain", Opts::new().onclick("fn_update();"))
    );
    Ok(out)
}

/// A domain as the page accepted it: dot-separated labels of letters, digits and hyphens, the last of 2 to 63.
fn domain_format(domain: &str) -> bool {
    let labels: Vec<&str> = domain.split('.').collect();
    let word = |l: &str| !l.is_empty() && l.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'-');
    labels.len() >= 2 && labels.iter().all(|l| word(l)) && labels.last().is_some_and(|l| (2..=63).contains(&l.len()))
}

/// The "update" call: give the domain to the account at `ip` in this company, or take it from all of them.
async fn update(pool: &SqlitePool, company: &str, domain: &str, ip: &str) -> sqlx::Result<String> {
    let holder = sqlx::query_scalar!(r#"SELECT company_name FROM "user" WHERE domain = ?1 COLLATE NOCASE LIMIT 1"#, domain)
        .fetch_optional(pool)
        .await?
        .flatten();
    let refused = match () {
        _ if company.is_empty() || ip.is_empty() => Some("Company name/IP missing".to_string()),
        _ if domain.is_empty() && ip != "0.0.0.0" => Some("Domain name missing".to_string()),
        _ if domain.is_empty() => None,
        _ if !domain_format(domain) => Some("Domain format must be domain.com".to_string()),
        _ if ip == "0.0.0.0" => Some("Domain name must be blank".to_string()),
        _ => holder
            .filter(|h| !h.eq_ignore_ascii_case(company))
            .map(|h| format!("Domain {} already assigned to {}", esc(domain), esc(&h))),
    };
    if let Some(r) = refused {
        return Ok(r);
    }
    let mut tx = pool.begin().await?;
    sqlx::query!(r#"UPDATE "user" SET domain = NULL WHERE company_name = ?1 COLLATE NOCASE"#, company).execute(&mut *tx).await?;
    let mut text = vec![format!("Company name: {}", esc(company))];
    if domain.is_empty() {
        text.push("Remote access denied".into());
    } else {
        sqlx::query!(
            r#"UPDATE "user" SET domain = ?1 WHERE id = (SELECT id FROM "user" WHERE ip = ?2 AND company_name = ?3 COLLATE NOCASE LIMIT 1)"#,
            domain,
            ip,
            company
        )
        .execute(&mut *tx)
        .await?;
        text.push(format!("Domain: {}", esc(domain)));
        text.push(format!("IP Address: {}", esc(ip)));
    }
    tx.commit().await?;
    Ok(text.join(" "))
}

async fn call(core: &Auth, method: &Method, fields: &Fields) -> sqlx::Result<Response> {
    let pool = &core.pool;
    let out = match fields.get("action") {
        "load" => load(pool, fields.get("company_name").trim()).await?,
        "host" => {
            let id = fields.get("id");
            let Some(ip) = host_address(id) else {
                let body = format!("{{{}:false}}", json_encode(&id));
                return Ok(([(axum::http::header::CONTENT_TYPE, crate::visitor::PHP_CONTENT_TYPE)], body).into_response());
            };
            vec![(id.to_string(), core.accounts.dns.confirmed_name(ip).await.unwrap_or_else(|| ip.to_string()))]
        }
        "update" if method == Method::POST => {
            let status = update(pool, fields.get("company_name").trim(), &fields.get("domain").trim().to_lowercase(), fields.get("ip").trim()).await?;
            vec![("domain_status".to_string(), status)]
        }
        _ => return Ok(json_list()),
    };
    Ok(json(&out))
}

/// json_encode(array()): an empty PHP array.
fn json_list() -> Response {
    ([(axum::http::header::CONTENT_TYPE, crate::visitor::PHP_CONTENT_TYPE)], "[]").into_response()
}

async fn handle(State(auth): State<Arc<Auth>>, staff: Staff<Administrator>, method: Method, uri: Uri, headers: HeaderMap, body: Bytes) -> Response {
    if !headers.contains_key("x-requested-with") {
        return page(&auth.pool, &staff).await;
    }
    if !from_page_script(&headers, &auth.public_origin) {
        return StatusCode::FORBIDDEN.into_response();
    }
    let fields = Fields::posted(form_urlencoded::parse(&body).into_owned().collect()).with_query(&Fields::from_query(uri.query()));
    call(&auth, &method, &fields).await.unwrap_or_else(shell::internal)
}

pub fn route() -> MethodRouter<Arc<Auth>> {
    axum::routing::get(handle).post(handle)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn host_cells_name_their_address() {
        assert_eq!(host_field("198.51.100.11"), "aXAJMTk4LjUxLjEwMC4xMQ");
        assert_eq!(host_address("aXAJMTk4LjUxLjEwMC4xMQ"), Some("198.51.100.11".parse().unwrap()));
        assert_eq!(host_address("bm90IGFuIGFkZHJlc3M"), None);
    }

    #[test]
    fn domains_need_a_dot_and_a_real_last_label() {
        assert!(domain_format("example.com"));
        assert!(domain_format("mail.example-co.uk"));
        assert!(!domain_format("example"));
        assert!(!domain_format("example.c"));
        assert!(!domain_format("ex ample.com"));
    }
}
