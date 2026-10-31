//! The visitor's profile (classes/profile.php): who downloads CAD and in which format. accounts::profile loads it
//! as the PHP did (the visit's copy, else the `harcourt_profile` cookie, with the account's address); the catalog
//! keeps the fields it shows and its profile gate.

use super::{forms, html::esc_text, pages::Ctx, registry::Registry, strings::Strings, viewer::Viewer};
use crate::accounts::profile::{address_rows, legend, Layout, Profile as AddressProfile};

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
    pub fn field(&self, field: &str) -> String {
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
        registry.items("address", "profile").iter().all(|(field, value)| value != "required" || !self.field(field).is_empty())
    }

    /// profile_class::quick_cad_message().
    pub fn quick_cad_message(&self, viewer: &Viewer, s: &Strings) -> String {
        if !viewer.active() || !viewer.cad {
            return String::new();
        }
        if self.cad_name.is_empty() || self.email.is_empty() {
            return s.label("catalog_cad_profile_incomplete", "Please complete profile to download CAD", &[]);
        }
        let (email, format) = (esc_text(&self.email), esc_text(&self.cad_name));
        let delivery = if self.generate {
            s.label("catalog_cad_emailed_msg", "CAD emailed to {email} when complete.", &[("email", &email)])
        } else {
            s.label("catalog_cad_downloaded_msg", "CAD downloaded when complete.", &[])
        };
        let format = s.label("catalog_cad_format_msg", "CAD Format: {format}.", &[("format", &format)]);
        format!("{format} {delivery} {}", s.label("catalog_cad_click_profile", "Click PROFILE to change.", &[]))
    }

    /// The fields the address form draws, as accounts::profile has them.
    fn address(&self) -> AddressProfile {
        AddressProfile {
            email: set(&self.email),
            name_first: set(&self.name_first),
            name_last: set(&self.name_last),
            company_name: set(&self.company_name),
            address: set(&self.address),
            city: set(&self.city),
            state: set(&self.state),
            zip: set(&self.zip),
            country_code: set(&self.country_code),
            ..AddressProfile::default()
        }
    }
}

/// accounts::profile holds an unset field as None; this module holds it as an empty string.
fn set(value: &str) -> Option<String> {
    (!value.is_empty()).then(|| value.to_string())
}

impl From<&AddressProfile> for Profile {
    fn from(p: &AddressProfile) -> Profile {
        let text = |v: &Option<String>| v.as_deref().unwrap_or("").trim().to_string();
        Profile {
            email: text(&p.email),
            name_first: text(&p.name_first),
            name_last: text(&p.name_last),
            generate: p.generate != 0,
            cad_code: text(&p.cad_code),
            cad_name: text(&p.cad_name),
            company_name: text(&p.company_name),
            address: text(&p.address),
            city: text(&p.city),
            state: text(&p.state),
            zip: text(&p.zip),
            country_code: text(&p.country_code),
        }
    }
}

/// profile_class::address(): an account's own company is not asked for, and when the account lacks nothing the
/// address fields are left out as well.
fn omitted(p: &Profile, account: bool, layout: &Layout) -> Vec<&'static str> {
    let own = |v: &str| if account { !v.is_empty() } else { false };
    let mut omit = Vec::new();
    let mut lacking = !own(&p.company_name);
    if own(&p.company_name) {
        omit.push("company_name");
    }
    lacking |= !own(&p.country_code);
    lacking |= [("address", &p.address), ("city", &p.city), ("state", &p.state), ("zip", &p.zip)].iter().any(|(f, v)| layout.required(f) && !own(v));
    if !lacking {
        omit.extend(["address", "city", "state", "zip", "country_code"]);
    }
    omit
}

/// profile_class::enter(): the profile form, as the catalog shows it in its Profile tab and, with the page's own
/// address to return to, at the gate.
pub async fn enter(ctx: &Ctx<'_>, gate: Option<&str>) -> sqlx::Result<String> {
    let p = &ctx.profile;
    let mut out = String::new();
    let s = &ctx.strings;
    let error = super::loader::php_json(&serde_json::Value::from(s.label("catalog_profile_internal_error", "Internal Error Detected", &[])));
    out.push_str(&ENTER_CSS.replace('\n', "\r\n"));
    out.push_str(&ENTER_JS.replace('\n', "\r\n").replace("\"Internal Error Detected\"", &error));
    out.push_str(&forms::hidden_class("success_url", gate.unwrap_or(""), "scscpq_input"));
    out.push_str(&forms::hidden_class("profile_tab", "", "scscpq_input"));
    if !p.company_name.is_empty() {
        out.push_str(&format!("<p class=profile_welcome>{}</p>", s.label("catalog_profile_welcome", "Welcome {company}!", &[("company", &esc_text(&p.company_name))])));
    }
    out.push_str("<div class=profile_box><div id=profile_message></div><table class=noborder>");
    if gate.is_some() {
        out.push_str(&format!("<div>{}</div>", ctx.registry.get("local", "profile_notice")));
    }
    let layout = Layout::load(ctx.pool).await?;
    out.push_str(&address_rows(ctx.pool, &layout, &p.address(), &omitted(p, ctx.viewer.account_id().is_some(), &layout)).await?);
    out.push_str(&format!("<tr><td>{} ", s.label("catalog_profile_email_cad", "Email CAD?", &[])));
    out.push_str(&forms::checkbox("profile_generate", "1", if p.generate { "1" } else { "0" }, &[("class", "scscpq_input")]));
    let lines = [
        s.label("catalog_profile_checked", "Email CAD Checked:", &[]),
        s.label("catalog_profile_checked_1", "&bull; Continue browsing immediately, CAD will be emailed to you.", &[]),
        s.label("catalog_profile_checked_2", "&bull; Check your spam folder for messages from partserver@partcommunity.com", &[]),
        s.label("catalog_profile_checked_3", "&bull; Best used when you can wait for CAD.", &[]),
        String::new(),
        s.label("catalog_profile_unchecked", "Email CAD Unchecked:", &[]),
        s.label("catalog_profile_unchecked_1", "&bull; CAD will be downloaded when complete.", &[]),
        s.label("catalog_profile_unchecked_2", "&bull; Pause browsing until generated.", &[]),
        s.label("catalog_profile_unchecked_3", "&bull; Best used when you need CAD <b>now</b>", &[]),
        String::new(),
        s.label("catalog_profile_duration", "CAD generations takes around 30 seconds to over 2 minutes to complete.", &[]),
    ];
    out.push_str(&format!("</td><td>{}</td></tr>", lines.join("<br>")));
    out.push_str(&format!("<tr><td><span id=cad_code_error>{}</span></td><td>", s.label("catalog_profile_cad_format", "CAD Format", &[])));
    out.push_str(&cad_picklist(ctx, &p.cad_code).await?);
    out.push_str("</td></tr>");
    out.push_str(&legend());
    out.push_str("</table>");
    let label = if ctx.viewer.account_id().is_some() { s.label("catalog_profile_update", "Update Profile", &[]) } else { s.label("catalog_profile_submit", "Submit", &[]) };
    out.push_str(&format!("<p>{}</p>", forms::button(&label, &[("class", "small_button"), ("onclick", "profile_ajax('profile');")])));
    let note = "Note: This should be a one-time verification only. Your email address will be kept strictly confidential and will not be disclosed to a third party. We respect your privacy and trust in Harcourt - <b>we will not spam you.</b>";
    out.push_str(&format!("<p>{}</p></div>", s.label("catalog_profile_note", note, &[])));
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
        let radio = format!("{} {}", forms::radio("cad_code", &r.code, selected, &[("class", "scscpq_input"), ("id", &id)]), esc_text(&r.name));
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
    use crate::{
        catalog::viewer::{Kind, Lang},
        identity::{Status, UserId},
    };

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
        let mut v = Viewer { kind: Kind::Account(UserId(1)), status: Status::Active, cad: true, ..Viewer::anonymous(Lang::En) };
        let mut p = Profile { email: "someone@example.com".into(), ..Profile::default() };
        let s = Strings::from_menu(Some(Some(r#"{"catalog_cad_click_profile":"Cliquez sur PROFIL."}"#)));
        assert_eq!(p.quick_cad_message(&v, &s), "Please complete profile to download CAD");
        p.cad_name = "STEP AP214".into();
        assert_eq!(p.quick_cad_message(&v, &s), "CAD Format: STEP AP214. CAD downloaded when complete. Cliquez sur PROFIL.");
        p.generate = true;
        assert_eq!(p.quick_cad_message(&v, &s), "CAD Format: STEP AP214. CAD emailed to someone@example.com when complete. Cliquez sur PROFIL.");
        v.cad = false;
        assert_eq!(p.quick_cad_message(&v, &s), "");
    }
}
