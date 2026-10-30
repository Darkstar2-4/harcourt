//! The sign-up form's address block: includes/functions.php address_class for the "profile" table, as
//! classes/profile.php enter() draws it and newuser.php checks it. Which fields appear, and which are required,
//! comes from the registry (`address.profile`), as in the PHP; the email is always required.

use std::collections::HashMap;

use serde::{Deserialize, Serialize};
use sqlx::SqlitePool;

use crate::accounts::{
    html::{self, escape, Blank, Opts},
    registry,
};

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Field {
    CompanyName,
    Name,
    Title,
    Address,
    City,
    State,
    Zip,
    CountryCode,
    Phone,
    Fax,
    Email,
}

/// address_class's field list, in its declaration order.
const FIELDS: [Field; 11] =
    [Field::CompanyName, Field::Name, Field::Title, Field::Address, Field::City, Field::State, Field::Zip, Field::CountryCode, Field::Phone, Field::Fax, Field::Email];

impl Field {
    fn key(self) -> &'static str {
        match self {
            Field::CompanyName => "company_name",
            Field::Name => "name",
            Field::Title => "title",
            Field::Address => "address",
            Field::City => "city",
            Field::State => "state",
            Field::Zip => "zip",
            Field::CountryCode => "country_code",
            Field::Phone => "phone",
            Field::Fax => "fax",
            Field::Email => "email",
        }
    }

    fn label(self) -> &'static str {
        match self {
            Field::CompanyName => "Company Name",
            Field::Name => "Name",
            Field::Title => "Title",
            Field::Address => "Address",
            Field::City => "City",
            Field::State => "State/Province",
            Field::Zip => "Zip/Postal Code",
            Field::CountryCode => "Country",
            Field::Phone => "Phone",
            Field::Fax => "Fax",
            Field::Email => "Email",
        }
    }

    /// The fields in `first`, then the rest in declaration order (address_class options_order()).
    fn order(first: &[Field]) -> Vec<Field> {
        let mut out = first.to_vec();
        out.extend(FIELDS.iter().copied().filter(|f| !first.contains(f)));
        out
    }
}

/// What the person typed or what the browser remembered (classes/profile.php profile_data_class). It is also the
/// `harcourt_profile` cookie the PHP pages read, so the names match theirs.
#[derive(Debug, Clone, Default, PartialEq, Serialize, Deserialize)]
#[serde(default)]
pub struct Profile {
    pub email: String,
    pub name_first: String,
    pub name_last: String,
    pub name: String,
    pub cad_code: String,
    pub cad_name: String,
    pub language_code: String,
    pub generate: i64,
    pub consent: i64,
    pub company_name: String,
    pub address: String,
    pub city: String,
    pub state: String,
    pub zip: String,
    pub country_code: String,
    #[serde(skip)]
    pub title: String,
    #[serde(skip)]
    pub phone: String,
    #[serde(skip)]
    pub fax: String,
}

pub const COOKIE: &str = "harcourt_profile";

impl Profile {
    /// The remembered profile, or an empty one. The cookie is the browser's to change; it only fills in a form.
    pub fn from_cookie(value: Option<&str>) -> Profile {
        value.and_then(|v| serde_json::from_str(v).ok()).unwrap_or_default()
    }

    pub fn to_cookie(&self) -> String {
        serde_json::to_string(self).unwrap_or_default()
    }

    fn get(&self, key: &str) -> &str {
        match key {
            "company_name" => &self.company_name,
            "name_first" => &self.name_first,
            "name_last" => &self.name_last,
            "name" => &self.name,
            "title" => &self.title,
            "address" => &self.address,
            "city" => &self.city,
            "state" | "state_us" | "state_ca" | "state_other" => &self.state,
            "zip" => &self.zip,
            "country_code" => &self.country_code,
            "phone" => &self.phone,
            "fax" => &self.fax,
            "email" => &self.email,
            _ => "",
        }
    }

    fn set(&mut self, key: &str, value: String) {
        let slot = match key {
            "company_name" => &mut self.company_name,
            "name_first" => &mut self.name_first,
            "name_last" => &mut self.name_last,
            "title" => &mut self.title,
            "address" => &mut self.address,
            "city" => &mut self.city,
            "state" => &mut self.state,
            "zip" => &mut self.zip,
            "phone" => &mut self.phone,
            "fax" => &mut self.fax,
            "email" => &mut self.email,
            _ => return,
        };
        *slot = value;
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Need {
    Required,
    Allowed,
}

/// The registry's `address.profile` settings: which fields the form has and which must be filled.
pub struct Layout {
    fields: HashMap<&'static str, Need>,
    jquery: bool,
}

impl Layout {
    pub async fn load(pool: &SqlitePool) -> sqlx::Result<Layout> {
        let mut fields = HashMap::new();
        for (item, data) in registry::items(pool, "address", "profile").await? {
            if let Some(f) = FIELDS.iter().find(|f| f.key() == item) {
                fields.insert(f.key(), if data == "required" { Need::Required } else { Need::Allowed });
            }
        }
        fields.insert("email", Need::Required);
        let jquery = registry::get(pool, "www", "jquery").await?.is_some();
        Ok(Layout { fields, jquery })
    }

    fn need(&self, f: Field) -> Option<Need> {
        self.fields.get(f.key()).copied()
    }
}

/// A row of the country table, as country->select() lists it.
struct Place {
    code: String,
    name: String,
}

async fn states(pool: &SqlitePool, country: &str) -> sqlx::Result<Vec<Place>> {
    let rows = sqlx::query!(r#"SELECT state AS "code!", name FROM country WHERE country = ?1 AND state <> '' ORDER BY name"#, country)
        .fetch_all(pool)
        .await?;
    Ok(rows.into_iter().map(|r| Place { code: r.code, name: r.name.unwrap_or_default() }).collect())
}

async fn countries(pool: &SqlitePool) -> sqlx::Result<Vec<Place>> {
    let rows = sqlx::query!(
        r#"SELECT country AS "code!", name FROM country WHERE state = '' AND active = 1
           ORDER BY CASE country WHEN 'US' THEN 1 WHEN 'CA' THEN 2 ELSE 3 END, name"#
    )
    .fetch_all(pool)
    .await?;
    Ok(rows.into_iter().map(|r| Place { code: r.code, name: r.name.unwrap_or_default() }).collect())
}

fn pairs(places: &[Place]) -> Vec<(String, String)> {
    places.iter().map(|p| (p.code.clone(), p.name.clone())).collect()
}

fn field_name(key: &str) -> String {
    format!("profile_{key}")
}

fn opts(placeholder: &str) -> Opts {
    let o = Opts::new().attr("class", "scscpq_input");
    if placeholder.is_empty() {
        o
    } else {
        o.attr("placeholder", placeholder)
    }
}

/// address_class input_field(): one table row, with the red star when the field is required.
fn row(layout: &Layout, f: Field, title: &str, content: &str) -> String {
    let Some(need) = layout.need(f) else { return String::new() };
    let star = if need == Need::Required { html::font("red", "<b>*</b>", "") } else { "&nbsp;".into() };
    format!("<tr ><td width='20%'>{star}{title}</td><td width='80%'>{content}</td></tr>")
}

/// classes/profile.php enter(array("register"=>TRUE)): the address block of the sign-up form.
pub async fn form(pool: &SqlitePool, layout: &Layout, p: &Profile) -> sqlx::Result<String> {
    let mut out = vec![ENTER_CSS.to_string(), ENTER_JS.to_string()];
    out.push(html::hidden("success_url", "", "scscpq_input"));
    out.push(html::hidden("profile_tab", "", "scscpq_input"));
    if !p.company_name.is_empty() {
        out.push(format!("<p class=profile_welcome>Welcome {}!</p>", escape(&p.company_name)));
    }
    out.push("<div id=profile_message></div>".into());
    out.push("<table class=noborder>".into());
    let text = |key: &str, size: u32, max: u32, placeholder: &str| html::text(&field_name(key), p.get(key), size, Some(max), opts(placeholder));
    for f in Field::order(&[Field::Email, Field::Name]) {
        let cell = match f {
            Field::CompanyName => row(layout, f, "Company Name", &text("company_name", 40, 40, "Company Name")),
            Field::Name => {
                row(layout, f, "First Name", &text("name_first", 40, 40, "First Name")) + &row(layout, f, "Last Name", &text("name_last", 40, 40, "Last Name"))
            }
            Field::Title => row(layout, f, "Title", &text("title", 40, 40, "Title")),
            Field::Address => row(layout, f, "Address", &text("address", 40, 100, "Address")),
            Field::City => row(layout, f, "City", &text("city", 40, 40, "City")),
            Field::State => {
                let us = html::select(&field_name("state_us"), &pairs(&states(pool, "US").await?), &p.state, Blank::Label("Select State"), opts(""));
                let ca = html::select(&field_name("state_ca"), &pairs(&states(pool, "CA").await?), &p.state, Blank::Label("Select Province"), opts(""));
                let other = text("state_other", 40, 40, "Province/State");
                row(layout, f, "<span id=profile_state_text></span>", &[us, ca, other].join(" "))
            }
            Field::Zip => row(layout, f, "<span id=profile_zip_text></span>", &html::text(&field_name("zip"), &p.zip, 25, Some(40), opts("Zip/Postal Code/Zip Code"))),
            Field::CountryCode => {
                let o = opts("").event("onchange", "fn_country_address('profile');");
                let select = html::select(&field_name("country_code"), &pairs(&countries(pool).await?), &p.country_code, Blank::Label("Select Country"), o);
                let mut cell = row(layout, f, "Country", &select);
                if layout.need(f).is_none() {
                    cell.push_str(&html::hidden("country_code", "US", ""));
                }
                cell
            }
            Field::Phone => row(layout, f, "Phone", &text("phone", 40, 40, "Phone")),
            Field::Fax => row(layout, f, "Fax", &text("fax", 40, 40, "Fax")),
            Field::Email => row(layout, f, "Email", &html::input("email", &field_name("email"), &p.email, 40, Some(60), opts("Email"))),
        };
        out.push(cell);
    }
    out.push(format!("\n<script>\n\nfn_country_address('profile');\n{}\n\n</script>\n", country_js(layout.jquery)));
    out.push(format!("<tr >{}</tr>", format_args!("<td colspan=2>{}</td>", html::font("red", "<b>*</b>", "Indicates mandatory fields"))));
    out.push("</table>".into());
    out.push("<p>Note: This should be a one-time verification only. Your email address will be kept strictly confidential and will not be disclosed to a third party. We respect your privacy and trust in Harcourt - <b>we will not spam you.</b></p>".into());
    Ok(out.concat())
}

/// includes/functions.php fn_text(): trimmed, with a `<script ...>` run removed.
fn fn_text(s: &str) -> String {
    let lower = s.to_ascii_lowercase();
    let cleaned = match (lower.find("<script"), lower.rfind('>')) {
        (Some(start), Some(end)) if end > start => format!("{}{}", &s[..start], &s[end + 1..]),
        _ => s.to_string(),
    };
    cleaned.trim().to_string()
}

/// includes/functions.php email_class: the address lower-cased, or the PHP's message for what is wrong with it.
pub fn check_email(raw: &str, required: bool) -> Result<String, &'static str> {
    let address = raw.trim().to_lowercase();
    if address.is_empty() {
        return if required { Err("Email address cannot be blank") } else { Ok(address) };
    }
    if address.chars().any(|c| " /\\,?!:;".contains(c)) {
        return Err("Address contains invalid characters");
    }
    let parts: Vec<&str> = address.split('@').collect();
    let [account, domain] = parts[..] else { return Err("Invalid email format") };
    if account.is_empty() {
        return Err("Account missing");
    }
    let labels: Vec<&str> = domain.split('.').collect();
    if domain.is_empty() || labels[0].is_empty() || labels.last().map_or(0, |l| l.len()) < 2 {
        return Err("Invalid domain");
    }
    let word = |s: &str, extra: &[u8]| !s.is_empty() && s.bytes().all(|c| c.is_ascii_alphanumeric() || c == b'-' || extra.contains(&c));
    let account_ok = account.split('.').all(|w| word(w, b"_"));
    let (host, tld) = domain.rsplit_once('.').unwrap_or((domain, ""));
    let domain_ok = host.split('.').all(|w| word(w, b"")) && tld.len() >= 2 && tld.bytes().all(|c| c.is_ascii_alphabetic());
    if !(account_ok && domain_ok) {
        return Err("Email format error");
    }
    Ok(address)
}

/// address_class verify() over the posted form: the profile it describes and the errors, keyed by input name.
pub fn verify(layout: &Layout, posted: &[(String, String)], mut p: Profile) -> (Profile, Vec<(String, String)>) {
    let input = |key: &str| posted.iter().find(|(k, _)| *k == field_name(key)).map_or("", |(_, v)| v.as_str()).to_string();
    let mut errors = Vec::new();
    p.country_code = fn_text(&input("country_code"));
    let state_field = match p.country_code.as_str() {
        "US" => "state_us",
        "CA" => "state_ca",
        _ => "state_other",
    };
    let mut check = |f: Field, data_key: &str, input_key: &str, p: &mut Profile| {
        let Some(need) = layout.need(f) else {
            p.set(data_key, String::new());
            return;
        };
        let raw = input(input_key);
        let value = match f {
            Field::Zip => fn_text(&raw).to_uppercase(),
            Field::Email => match check_email(&raw, need == Need::Required) {
                Ok(a) => a,
                Err(e) => {
                    p.set(data_key, raw.trim().to_lowercase());
                    errors.push((field_name(input_key), e.to_string()));
                    return;
                }
            },
            _ => fn_text(&raw),
        };
        if need == Need::Required && value.is_empty() {
            let label = match (f, input_key) {
                (Field::Name, "name_first") => "First Name",
                (Field::Name, _) => "Last Name",
                _ => f.label(),
            };
            errors.push((field_name(input_key), format!("{label} cannot be blank")));
        }
        p.set(data_key, value);
    };
    check(Field::CompanyName, "company_name", "company_name", &mut p);
    check(Field::Name, "name_first", "name_first", &mut p);
    check(Field::Name, "name_last", "name_last", &mut p);
    p.name = format!("{} {}", p.name_first, p.name_last).trim().to_string();
    check(Field::Title, "title", "title", &mut p);
    check(Field::Address, "address", "address", &mut p);
    check(Field::City, "city", "city", &mut p);
    check(Field::State, "state", state_field, &mut p);
    check(Field::Zip, "zip", "zip", &mut p);
    check(Field::Phone, "phone", "phone", &mut p);
    check(Field::Fax, "fax", "fax", &mut p);
    check(Field::Email, "email", "email", &mut p);
    (p, errors)
}

/// address_class output("html:array") with the order `email` first: "Label: value" for each filled field, with
/// the state and country spelled out. Values are escaped; these lines go into a staff mail.
pub async fn summary(pool: &SqlitePool, layout: &Layout, p: &Profile) -> sqlx::Result<Vec<String>> {
    let state_name = sqlx::query_scalar!("SELECT name FROM country WHERE country = ?1 AND state = ?2 AND state <> ''", p.country_code, p.state)
        .fetch_optional(pool)
        .await?
        .flatten();
    let country_name = sqlx::query_scalar!("SELECT name FROM country WHERE country = ?1 AND state = ''", p.country_code).fetch_optional(pool).await?.flatten();
    let mut lines = Vec::new();
    for f in Field::order(&[Field::Email]) {
        if layout.need(f).is_none() {
            continue;
        }
        let text = match f {
            Field::State => state_name.clone().unwrap_or_else(|| p.state.clone()),
            Field::CountryCode => country_name.clone().unwrap_or_else(|| p.country_code.clone()),
            _ => p.get(f.key()).to_string(),
        };
        if !text.is_empty() {
            lines.push(format!("{}: {}", f.label(), escape(&text)));
        }
    }
    Ok(lines)
}

fn country_js(jquery: bool) -> &'static str {
    if jquery {
        COUNTRY_JS_JQUERY
    } else {
        COUNTRY_JS_PLAIN
    }
}

const COUNTRY_JS_JQUERY: &str = "function fn_country_address(table) {
jQuery('#' + table + '_state_us').hide();
jQuery('#' + table + '_state_ca').hide();
jQuery('#' + table + '_state_other').hide();
jQuery('#' + table + '_state_us_div').hide();
jQuery('#' + table + '_state_ca_div').hide();
jQuery('#' + table + '_state_other_div').hide();
var state;
var zip='Postal Code';
country_code=jQuery('#' + table + '_country_code').val();
switch (true) {
case (country_code == 'US'):
jQuery('#' + table + '_state_us').show();
jQuery('#' + table + '_state_us_div').show();
state='State';
zip='ZIP Code';
break;
case (country_code == 'CA'):
jQuery('#' + table + '_state_ca').show();
jQuery('#' + table + '_state_ca_div').show();
state='Province';
break;
default:
jQuery('#' + table + '_state_other').show();
jQuery('#' + table + '_state_other_div').show();
state='Province/State';
}
jQuery('#' + table + '_state_text').html(state);
jQuery('#' + table + '_zip_text').html(zip);
}";

const COUNTRY_JS_PLAIN: &str = "function fn_country_address(table) {
 country_obj=document.getElementById(table + 'country_code');
 switch (true) {
   case (!country_obj):
   return;
  case (country_obj.value == 'US'):
   state='State';
   zip='ZIP Code';
   div_show(table + '_state_us',1);
   div_show(table + 'state_ca',0);
   div_show(table + 'state_other',0);
   break;
  case (country_obj.value == 'CA'):
   state='Province';
   zip='Postal Code';
   div_show(table + 'state_us',0);
   div_show(table + 'state_ca',1);
   div_show(table + 'state_other',0);
   break;
  default:
   state='Province/State';
   zip='Postal Code';
   div_show(table + 'state_us',0);
   div_show(table + 'state_ca',0);
   div_show(table + 'state_other',1);
 }
 state_obj=document.getElementById(table + 'state_text');
 if (state_obj) state_obj.innerHTML=state;
 zip_obj=document.getElementById(table + 'zip_text');
 if (zip_obj) zip_obj.innerHTML=zip;
}";

const ENTER_JS: &str = r##"<script>
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
</script>"##;

const ENTER_CSS: &str = "<style>
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
</style>";

#[cfg(test)]
mod tests {
    use super::*;

    fn layout(fields: &[(&'static str, Need)]) -> Layout {
        let mut map: HashMap<&'static str, Need> = fields.iter().copied().collect();
        map.insert("email", Need::Required);
        Layout { fields: map, jquery: true }
    }

    #[test]
    fn email_messages_match_email_class() {
        assert_eq!(check_email(" Pat@Example.COM ", true), Ok("pat@example.com".into()));
        assert_eq!(check_email("", true), Err("Email address cannot be blank"));
        assert_eq!(check_email("", false), Ok(String::new()));
        assert_eq!(check_email("a b@example.com", true), Err("Address contains invalid characters"));
        assert_eq!(check_email("a@b@example.com", true), Err("Invalid email format"));
        assert_eq!(check_email("@example.com", true), Err("Account missing"));
        assert_eq!(check_email("a@example.c", true), Err("Invalid domain"));
        assert_eq!(check_email("a@.example.com", true), Err("Invalid domain"));
        assert_eq!(check_email("a'b@example.com", true), Err("Email format error"));
        assert_eq!(check_email("first.last_x@sub.example.co", true), Ok("first.last_x@sub.example.co".into()));
    }

    #[test]
    fn verify_reports_blank_required_fields_by_input_name() {
        let l = layout(&[("company_name", Need::Required), ("name", Need::Required), ("city", Need::Allowed), ("state", Need::Required), ("country_code", Need::Required)]);
        let posted = vec![
            ("action".to_string(), "register".to_string()),
            ("profile_email".to_string(), "Pat@Example.com".to_string()),
            ("profile_name_first".to_string(), " Pat <script>x</script> ".to_string()),
            ("profile_country_code".to_string(), "CA".to_string()),
            ("profile_state_ca".to_string(), "ON".to_string()),
            ("profile_title".to_string(), "ignored".to_string()),
        ];
        let (p, errors) = verify(&l, &posted, Profile::default());
        assert_eq!(p.email, "pat@example.com");
        assert_eq!(p.name_first, "Pat");
        assert_eq!((p.state.as_str(), p.title.as_str()), ("ON", ""));
        assert_eq!(
            errors,
            vec![
                ("profile_company_name".to_string(), "Company Name cannot be blank".to_string()),
                ("profile_name_last".to_string(), "Last Name cannot be blank".to_string()),
            ]
        );
    }

    #[test]
    fn the_profile_cookie_is_the_php_shape_and_tolerates_junk() {
        let p = Profile { email: "pat@example.com".into(), name_first: "Pat".into(), ..Default::default() };
        let json = p.to_cookie();
        assert!(json.starts_with(r#"{"email":"pat@example.com","name_first":"Pat","#), "{json}");
        assert_eq!(Profile::from_cookie(Some(&json)), p);
        assert_eq!(Profile::from_cookie(Some("not json")), Profile::default());
    }

    #[tokio::test]
    async fn the_form_draws_only_the_registry_fields_with_escaped_values() {
        let pool = crate::db::open_memory().await.unwrap();
        let l = layout(&[("company_name", Need::Required), ("name", Need::Allowed)]);
        let p = Profile { email: "x'@example.com".into(), ..Default::default() };
        let out = form(&pool, &l, &p).await.unwrap();
        assert!(out.contains("<tr ><td width='20%'><font color=red><b>*</b></font>Email</td><td width='80%'><input type=email class='scscpq_input' name='profile_email' id='profile_email' size='40' maxlength='60' value='x&#039;@example.com' placeholder='Email'>\n</td></tr>"), "{out}");
        assert!(out.contains("<td width='20%'>&nbsp;First Name</td>"));
        assert!(!out.contains("profile_city"));
        assert!(out.contains("<input type=hidden name='country_code' id='country_code' value='US'>"));
    }
}
