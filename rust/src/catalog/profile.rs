//! The visitor's profile (classes/profile.php): who downloads CAD and in which format. The PHP kept it in the
//! session and the `harcourt_profile` cookie, which the browser holds; here it is read from that cookie for display
//! and the catalog's profile gate only, together with the account's address fields.

use axum::http::{header, HeaderMap};
use sqlx::SqlitePool;

use super::{forms, html::{esc, esc_attr, esc_text}, pages::Ctx, registry::Registry, viewer::Viewer};

#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Profile {
    pub email: String,
    pub name_first: String,
    pub name_last: String,
    pub generate: bool,
    pub cad_code: String,
    pub cad_name: String,
    pub company_name: String,
    pub address: String,
    pub city: String,
    pub state: String,
    pub zip: String,
    pub country_code: String,
}

impl Profile {
    /// profile_class::field values by registry field name; a field the profile has no value for is empty.
    fn value(&self, field: &str) -> String {
        match field {
            "email" => self.email.clone(),
            "name" => format!("{} {}", self.name_first, self.name_last),
            "name_first" => self.name_first.clone(),
            "name_last" => self.name_last.clone(),
            "cad_code" => self.cad_code.clone(),
            "cad_name" => self.cad_name.clone(),
            "company_name" => self.company_name.clone(),
            "address" => self.address.clone(),
            "city" => self.city.clone(),
            "state" => self.state.clone(),
            "zip" => self.zip.clone(),
            "country_code" => self.country_code.clone(),
            _ => String::new(),
        }
    }

    /// profile_class::forced(): every field the registry marks required for profiles has a value.
    pub fn forced(&self, registry: &Registry) -> bool {
        registry.items("address", "profile").iter().all(|(field, value)| value != "required" || !self.value(field).is_empty())
    }

    /// profile_class::quick_cad_message().
    pub fn quick_cad_message(&self, viewer: &Viewer) -> String {
        if !viewer.active || !viewer.cad {
            return String::new();
        }
        if self.cad_name.is_empty() || self.email.is_empty() {
            return "Please complete profile to download CAD".into();
        }
        let delivery = if self.generate { format!("CAD emailed to {} when complete.", esc_text(&self.email)) } else { "CAD downloaded when complete.".into() };
        format!("CAD Format: {}. {delivery} Click PROFILE to change.", esc_text(&self.cad_name))
    }
}

fn cookie(headers: &HeaderMap, name: &str) -> Option<String> {
    headers
        .get_all(header::COOKIE)
        .iter()
        .filter_map(|v| v.to_str().ok())
        .flat_map(|v| v.split(';'))
        .filter_map(|pair| pair.trim().split_once('='))
        .find(|(k, _)| *k == name)
        .map(|(_, v)| percent_encoding::percent_decode_str(v).decode_utf8_lossy().into_owned())
}

pub async fn load(pool: &SqlitePool, viewer: &Viewer, headers: &HeaderMap, email: Option<&str>) -> sqlx::Result<Profile> {
    let json: serde_json::Value = cookie(headers, "harcourt_profile").and_then(|c| serde_json::from_str(&c).ok()).unwrap_or_default();
    let text = |k: &str| match json.get(k) {
        Some(serde_json::Value::String(s)) => s.trim().to_string(),
        Some(serde_json::Value::Number(n)) => n.to_string(),
        _ => String::new(),
    };
    let mut p = Profile {
        email: Some(text("email")).filter(|e| !e.is_empty()).or_else(|| email.map(str::to_string)).unwrap_or_default(),
        name_first: text("name_first"),
        name_last: text("name_last"),
        generate: json.get("generate").is_some_and(|g| g.as_i64().unwrap_or(0) != 0 || g.as_str().is_some_and(|s| s.trim() != "0" && !s.trim().is_empty())),
        company_name: text("company_name"),
        address: text("address"),
        city: text("city"),
        state: text("state"),
        zip: text("zip"),
        country_code: text("country_code"),
        ..Profile::default()
    };
    let code = text("cad_code");
    if !code.is_empty() {
        if let Some(name) = sqlx::query_scalar!(r#"SELECT name AS "name!" FROM cad WHERE code = ?1 LIMIT 1"#, code).fetch_optional(pool).await? {
            p.cad_code = code;
            p.cad_name = name;
        }
    }
    if let Some(id) = viewer.account_id() {
        if let Some(r) = sqlx::query!(r#"SELECT company_name, address, city, state, zip, country_code FROM "user" WHERE id = ?1"#, id.0).fetch_optional(pool).await? {
            p.company_name = r.company_name.unwrap_or_default();
            p.address = r.address.unwrap_or_default();
            p.city = r.city.unwrap_or_default();
            p.state = r.state.unwrap_or_default();
            p.zip = r.zip.unwrap_or_default();
            p.country_code = r.country_code.unwrap_or_default();
        }
    }
    Ok(p)
}

/// profile_class::enter(): the profile form, as the catalog shows it in its Profile tab and at the gate.
pub async fn enter(ctx: &Ctx<'_>, notice: bool) -> sqlx::Result<String> {
    let p = &ctx.profile;
    let mut out = String::new();
    out.push_str(ENTER_CSS);
    out.push_str(ENTER_JS);
    out.push_str(&forms::hidden_class("success_url", if notice { "/catalog/" } else { "" }, "scscpq_input"));
    out.push_str(&forms::hidden_class("profile_tab", "", "scscpq_input"));
    if !p.company_name.is_empty() {
        out.push_str(&format!("<p class=profile_welcome>Welcome {}!</p>", esc_text(&p.company_name)));
    }
    out.push_str("<div class=profile_box><div id=profile_message></div><table class=noborder>");
    if notice {
        out.push_str(&format!("<div>{}</div>", ctx.registry.get("local", "profile_notice")));
    }
    let row = |label: &str, field: &str, value: &str, size: &str, max: &str| {
        let input = forms::text(&format!("profile_{field}"), value, size, max, &[("class", "scscpq_input"), ("placeholder", label)]);
        format!("<tr><td width='20%'>{label}</td><td width='80%'>{input}</td></tr>")
    };
    out.push_str(&row("Email", "email", &p.email, "40", "60"));
    out.push_str(&row("First Name", "name_first", &p.name_first, "40", "40"));
    out.push_str(&row("Last Name", "name_last", &p.name_last, "40", "40"));
    if ctx.viewer.account_id().is_none() || p.company_name.is_empty() {
        out.push_str(&row("Company Name", "company_name", &p.company_name, "40", "40"));
    }
    out.push_str("<tr><td>Email CAD? ");
    out.push_str(&forms::checkbox("profile_generate", "1", if p.generate { "1" } else { "0" }, &[("class", "scscpq_input")]));
    out.push_str("</td><td>Email CAD Checked:<br>&bull; Continue browsing immediately, CAD will be emailed to you.<br>&bull; Check your spam folder for messages from partserver@partcommunity.com<br>&bull; Best used when you can wait for CAD.<br><br>Email CAD Unchecked:<br>&bull; CAD will be downloaded when complete.<br>&bull; Pause browsing until generated.<br>&bull; Best used when you need CAD <b>now</b><br><br>CAD generations takes around 30 seconds to over 2 minutes to complete.</td></tr>");
    out.push_str("<tr><td><span id=cad_code_error>CAD Format</span></td><td>");
    out.push_str(&cad_picklist(ctx, &p.cad_code).await?);
    out.push_str("</td></tr>");
    out.push_str(&format!("<tr><td colspan=2>{}</td></tr>", forms::font("red", "<b>*</b>", "Indicates mandatory fields")));
    out.push_str("</table>");
    let label = if ctx.viewer.account_id().is_some() { "Update Profile" } else { "Submit" };
    out.push_str(&format!("<p>{}</p>", forms::button(label, &[("class", "small_button"), ("onclick", "profile_ajax('profile');")])));
    out.push_str("<p>Note: This should be a one-time verification only. Your email address will be kept strictly confidential and will not be disclosed to a third party. We respect your privacy and trust in Harcourt - <b>we will not spam you.</b></p></div>");
    Ok(out)
}

/// cad_class::picklist(): the CAD formats as radio buttons, grouped by type, direct formats only for those allowed.
async fn cad_picklist(ctx: &Ctx<'_>, selected: &str) -> sqlx::Result<String> {
    let all = ctx.viewer.sees_pending() || !ctx.registry.get("cad", "part2cad").is_empty();
    let rows = sqlx::query!(
        r#"SELECT code AS "code!", name AS "name!", "type" AS "ty!", direct AS "direct!: i64" FROM cad
           WHERE code IS NOT NULL AND name IS NOT NULL AND "type" IS NOT NULL AND direct IS NOT NULL
           ORDER BY CASE WHEN direct = 1 THEN 1 WHEN "type" = '3D' THEN 2 ELSE 3 END, id"#
    )
    .fetch_all(ctx.pool)
    .await?;
    let mut groups: Vec<(String, Vec<String>)> = Vec::new();
    for r in rows.into_iter().filter(|r| all || r.direct == 0) {
        let id = super::html::base64_fields(&["cad_code", &r.code]);
        let radio = format!(
            "<input type=radio value='{}' name='cad_code' class='scscpq_input' id='{}'{}>\n {}",
            esc(&r.code),
            esc_attr(&id),
            if r.code == selected { " checked" } else { "" },
            esc_text(&r.name)
        );
        match groups.iter_mut().find(|(t, _)| *t == r.ty) {
            Some((_, list)) => list.push(radio),
            None => groups.push((r.ty, vec![radio])),
        }
    }
    let style = "-webkit-column-count: 2; -moz-column-count: 2; column-count: 2; -webkit-column-gap: 10px; -moz-column-gap: 10px; column-gap: 10px;";
    Ok(groups.into_iter().map(|(ty, items)| format!("<div><b>{}</b></div><div style='{style}'>{}</div>", esc_text(&ty), items.join("<br>\n"))).collect())
}

const ENTER_JS: &str = r###"<script>
function profile_ajax(action) {
    data=scscpq_class_data('scscpq_input');
    data.action=action;
	jQuery.ajax({
		url: "/scscpq_api.php",
        type: "post",
	    dataType: "json",
		data: data,
        error: jQuery("#profile_message").html("Internal Error Detected"),
	    success: function(response) {
            if (response['profile_tab'].length) jQuery('#products_jquery_div').tabs({ active: response['profile_tab'] });
            if (response['success_url'].length) window.location.href=response['success_url'];
            scscpq_fill(response['html']);
            error=response['error'];
            for (var field in error) {
                if (error[field] == 1) {
                    jQuery("#" + field).removeClass('profile_error').addClass('profile_error');
                } else {
                    jQuery("#" + field).removeClass('profile_error');
                }
            }
            jQuery("#profile_email").focus();
        }
    })
}
</script>"###;

const ENTER_CSS: &str = r###"<style>
.profile_error {
    border: 1px solid red !important;
}
.profile_welcome {
    color: #000;
    font-family: 'Rajdhani';
    font-size: 22px;
    font-weight: 700;
    margin-top: 2em;
}
#profile_message {
    color: #000;
    font-size: 1.1em;
    font-weight: 700;
}
.profile_box {
    max-width: 1000px;
    border: 1px solid black;
    padding: 1em;
    margin: 1em;
}
.small_button {
    color: #FFF;
    cursor: pointer;
    border-radius: 4px;
    font-family: 'Rajdhani';
    font-size: 1.25rem;
    font-weight: 600;
    background: linear-gradient(0deg, rgba(115,14,28,1) 0%, rgba(229,28,56,1) 150%);
    border-radius: 4px;
    padding: 0 1em;
}
</style>"###;

#[cfg(test)]
mod tests {
    use super::*;
    use crate::{catalog::viewer::{Kind, Lang}, identity::UserId};

    #[test]
    fn the_gate_asks_for_every_required_field() {
        let reg = Registry::from_rows([("", "address", "profile", "email", Some("required")), ("", "address", "profile", "phone", Some("optional"))]);
        let mut p = Profile::default();
        assert!(!p.forced(&reg));
        p.email = "someone@example.com".into();
        assert!(p.forced(&reg));
        let phone = Registry::from_rows([("", "address", "profile", "phone", Some("required"))]);
        assert!(!p.forced(&phone));
        assert!(p.forced(&Registry::default()));
    }

    #[test]
    fn quick_cad_message_follows_the_profile() {
        let mut v = Viewer { kind: Kind::Account(UserId(1)), active: true, cad: true, ..Viewer::anonymous(Lang::En) };
        let mut p = Profile { email: "someone@example.com".into(), ..Profile::default() };
        assert_eq!(p.quick_cad_message(&v), "Please complete profile to download CAD");
        p.cad_name = "STEP AP214".into();
        assert_eq!(p.quick_cad_message(&v), "CAD Format: STEP AP214. CAD downloaded when complete. Click PROFILE to change.");
        p.generate = true;
        assert_eq!(p.quick_cad_message(&v), "CAD Format: STEP AP214. CAD emailed to someone@example.com when complete. Click PROFILE to change.");
        v.cad = false;
        assert_eq!(p.quick_cad_message(&v), "");
    }
}
