//! The markup `GFForms::get_form()` printed for these forms, as the oracle shows it: the legacy list markup for
//! older forms and the div-based "gravity-theme" markup for forms saved with markup version 2, the Akismet
//! fields, the honeypot, the footer and hidden inputs.

use std::collections::HashMap;

use super::{
    countries::COUNTRIES,
    model::{Field, Form, Honeypot, Kind, PhoneFormat},
};
use crate::orders::html::esc;

/// What the visitor typed, by input name (`input_1`, `input_14.3`).
pub type Values = HashMap<String, String>;
/// Validation messages by field id.
pub type FieldErrors = HashMap<i64, String>;

/// How the page embeds the form (the static export's `islands` entry for it).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Embed {
    pub ajax: bool,
    /// The `gform_style_settings` value: `[]` for a block, block styles as JSON, empty for the footer shortcode.
    pub styles: String,
    pub texturize: bool,
    pub in_content: bool,
    /// Akismet numbers its `ak_js_N` input per form rendered on the page, in page order.
    pub position: usize,
}

impl Embed {
    pub fn default_for(form_id: i64) -> Embed {
        let ajax = form_id == 1;
        Embed { ajax, styles: if ajax { String::new() } else { "[]".into() }, texturize: false, in_content: !ajax, position: 1 }
    }
}

pub struct Render<'a> {
    pub form: &'a Form,
    pub page_uri: &'a str,
    pub embed: &'a Embed,
    pub values: &'a Values,
    pub errors: &'a FieldErrors,
    /// The form-level message shown above the fields when the post was refused.
    pub summary: Option<Summary>,
    pub token: Option<&'a str>,
    pub recaptcha: bool,
    /// `GFCommon::get_browser_class()` for the requesting browser.
    pub browser: &'a str,
}

/// The class Gravity Forms gives the wrapper from WordPress's reading of the User-Agent (wp-includes/vars.php).
pub fn browser_class(ua: &str) -> String {
    let lower = ua.to_ascii_lowercase();
    let mut class = if ua.contains("Lynx") {
        "gf_browser_lynx"
    } else if ua.contains("Edg") {
        "gf_browser_unknown"
    } else if ua.contains("Opera") || ua.contains("OPR/") {
        "gf_browser_opera"
    } else if lower.contains("chrome") {
        if lower.contains("chromeframe") { "gf_browser_ie" } else { "gf_browser_chrome" }
    } else if lower.contains("safari") {
        "gf_browser_safari"
    } else if ((ua.contains("MSIE") || ua.contains("Trident")) && ua.contains("Win")) || (ua.contains("MSIE") && ua.contains("Mac")) {
        "gf_browser_ie"
    } else if ua.contains("Gecko") {
        "gf_browser_gecko"
    } else if ua.contains("Nav") && ua.contains("Mozilla/4.") {
        "gf_browser_ns4"
    } else {
        "gf_browser_unknown"
    }
    .to_string();
    if class == "gf_browser_ie" {
        for v in ["6", "7", "8", "9"] {
            if ua.contains(&format!("MSIE {v}")) {
                class.push_str(&format!(" gf_browser_ie{v}"));
            }
        }
    }
    if class == "gf_browser_safari" && lower.contains("mobile") {
        class.push_str(" gf_browser_iphone");
    }
    class
}

/// Gravity Forms' own words in the site's two languages (French from gravityforms-fr_FR.po).
pub struct Wording {
    pub required: &'static str,
    pub bad_email: &'static str,
    /// "Please enter a valid date in the format (", before the format and ")."
    pub bad_date: &'static str,
    pub problem: &'static str,
    /// The default confirmation, which spam got.
    pub thanks: &'static str,
    pub honeypot_note: &'static str,
    pub legend: &'static str,
    pub iframe_title: &'static str,
}

pub const ENGLISH: Wording = Wording {
    required: "This field is required.",
    bad_email: "The email address entered is invalid, please check the formatting (e.g. email@domain.com).",
    bad_date: "Please enter a valid date in the format (",
    problem: "There was a problem with your submission. Please review the fields below.",
    thanks: "Thanks for contacting us! We will get in touch with you shortly.",
    honeypot_note: "This field is for validation purposes and should be left unchanged.",
    legend: "&quot;<span class=\"gfield_required gfield_required_asterisk\">*</span>&quot; indicates required fields",
    iframe_title: "This iframe contains the logic required to handle Ajax powered Gravity Forms.",
};

pub const FRENCH: Wording = Wording {
    required: "Ce champ est nécessaire.",
    bad_email: "L\u{2019}adresse e-mail saisie n\u{2019}est pas valide, veuillez vérifier son formatage (p.\u{a0}ex.\u{a0}email@domaine.com).",
    bad_date: "Veuillez saisir une date valide au format (",
    problem: "Une erreur s\u{2019}est produite lors de votre envoi. Veuillez vérifier les champs ci-dessous.",
    thanks: "Merci de nous avoir contactés\u{a0}! Nous vous répondrons sous peu.",
    honeypot_note: "Ce champ n\u{2019}est utilisé qu\u{2019}à des fins de validation et devrait rester inchangé.",
    legend: "«\u{a0}<span class=\"gfield_required gfield_required_asterisk\">*</span>\u{a0}» indique les champs nécessaires",
    iframe_title: "Cette iframe contient la logique nécessaire pour manipuler Gravity Forms avec Ajax.",
};

pub fn wording(form: &Form) -> &'static Wording {
    if form.french { &FRENCH } else { &ENGLISH }
}

/// The form-level message above a refused post: Gravity Forms' own, or the reCAPTCHA gate's
/// (mu-plugins/registry-recaptcha-contact-forms.php, English only), which replaces it.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Summary {
    Problem,
    Blocked,
}

const BLOCKED: &str = "<div class='validation_error'>We were unable to verify your submission. Please try again, or contact us directly at <a href='mailto:sales@harcourt.co'>sales@harcourt.co</a> if the issue continues.</div>";

fn summary(f: &Form, s: Summary) -> String {
    let class = if f.gravity_theme { "gform_validation_errors" } else { "gform_validation_errors validation_error" };
    let inner = match s {
        Summary::Problem => format!("<h2 class='gform_submission_error hide_summary'><span class='gform-icon gform-icon--circle-error'></span>{}</h2>", wording(f).problem),
        Summary::Blocked => BLOCKED.to_string(),
    };
    format!("<div class=\"{class}\" id=\"gform_{}_validation_container\" data-js=\"gform-focus-validation-error\" autofocus>{inner}</div>", f.id)
}
pub const HONEYPOT_LABEL: &str = "Comments";
const ASTERISK: &str = "<span class=\"gfield_required\"><span class=\"gfield_required gfield_required_asterisk\">*</span></span>";

/// The honeypot takes the id after the highest field id, as Gravity Forms numbers it.
pub fn honeypot_id(form: &Form) -> i64 {
    form.fields.iter().map(|f| f.id).max().unwrap_or(0) + 1
}

fn width(f: &Field) -> &'static str {
    match f.grid_span {
        Some(12) => " gfield--width-full",
        Some(9) => " gfield--width-three-quarter",
        Some(8) => " gfield--width-two-thirds",
        Some(6) => " gfield--width-half",
        Some(4) => " gfield--width-third",
        Some(3) => " gfield--width-quarter",
        _ => "",
    }
}

fn input_type(f: &Field) -> String {
    match (&f.kind, f.input_type.as_str()) {
        (Kind::Date { .. }, "") => " gfield--input-type-datepicker".into(),
        (_, "") => String::new(),
        (_, t) => format!(" gfield--input-type-{}", esc(t)),
    }
}

fn value(r: &Render, name: &str) -> String {
    r.values.get(name).map_or(String::new(), |v| esc(v))
}

fn label(r: &Render, f: &Field) -> String {
    let mark = if f.required { ASTERISK } else { "" };
    format!("<label class='gfield_label gform-field-label' for='input_{}_{}'><span class='gform-field-label__text'>{}</span>{mark}</label>", r.form.id, f.id, esc(&f.label))
}

fn aria(r: &Render, f: &Field) -> String {
    let required = if f.required { " aria-required=\"true\"" } else { "" };
    format!("{required} aria-invalid=\"{}\"", r.errors.contains_key(&f.id))
}

/// `get_aria_describedby()`: a field that failed validation points at its message.
fn described(r: &Render, f: &Field) -> String {
    if r.errors.contains_key(&f.id) { format!(" aria-describedby=\"validation_message_{}_{}\"", r.form.id, f.id) } else { String::new() }
}

fn control(r: &Render, f: &Field) -> String {
    let fid = r.form.id;
    let name = format!("input_{}", f.id);
    let id = format!("input_{fid}_{}", f.id);
    let size = esc(&f.size);
    let v = value(r, &name);
    let aria = aria(r, f);
    let dby = described(r, f);
    match &f.kind {
        Kind::Text => format!("<div class='ginput_container ginput_container_text'><input name='{name}' id='{id}' type='text' value='{v}' class='{size}'{dby} {aria} /></div>"),
        Kind::Email => format!("<div class='ginput_container ginput_container_email'><input name='{name}' id='{id}' type='email' value='{v}' class='{size}' {aria}{dby} /></div>"),
        Kind::Phone(PhoneFormat::Standard) => format!(
            "<div class='ginput_container ginput_container_phone'><input name='{name}' id='{id}' type='tel' value='{v}' class='{size}' placeholder='(999) 999-9999'{aria}{dby} data-mask=\"(999) 999-9999\" /></div>"
        ),
        Kind::Phone(PhoneFormat::International) => format!("<div class='ginput_container ginput_container_phone'><input name='{name}' id='{id}' type='tel' value='{v}' class='{size}' {aria}{dby} /></div>"),
        Kind::Textarea => format!("<div class='ginput_container ginput_container_textarea'><textarea name='{name}' id='{id}' class='textarea {size}'{dby} {aria} rows='10' cols='50'>{v}</textarea></div>"),
        Kind::Date { dmy } => {
            let format = if *dmy { "dmy" } else { "mdy" };
            let placeholder = match (*dmy, r.form.french) {
                (true, true) => "jj/mm/aaaa",
                (true, false) => "dd/mm/yyyy",
                _ => "mm/dd/yyyy",
            };
            format!(
                "<div class='ginput_container ginput_container_date'><input placeholder='{placeholder}' id='{id}' class='datepicker gform-datepicker {format} datepicker_with_icon gdatepicker_with_icon' type='text' name='{name}' value='{v}'{dby} aria-invalid=\"{}\" data-mask=\"99/99/9999\" /><kbd id='keyboardHint_{id}' hidden class='down'></kbd><button type='button' id='datepicker_toggle_{id}' class='gform-datepicker-toggle gform-datepicker-toggle--default accCalendar aria-date-picker gform-button gform-theme-button gform-theme-button--simple gform-theme-button--simple-in-ctrl' aria-expanded='false' aria-controls='{id}' aria-label='{}: Choose date on calendar' ><span class=\"gform-calendar-icon gform-datepicker-toggle-icon gform-datepicker-toggle-icon--default dashicons dashicons-calendar-alt\" aria-hidden=\"true\"></span></button></div>",
                r.errors.contains_key(&f.id),
                esc(&f.label)
            )
        }
        Kind::Address { inputs, default_country } => address(r, f, inputs, default_country),
        Kind::Html(_) | Kind::Unsupported(_) => String::new(),
    }
}

fn address(r: &Render, f: &Field, inputs: &[super::model::SubInput], default_country: &str) -> String {
    let id = format!("input_{}_{}", r.form.id, f.id);
    let part = |n: &str| inputs.iter().find(|s| s.id.rsplit('.').next() == Some(n));
    let shown = |n: &str| part(n).is_some_and(|s| !s.hidden);
    let has: Vec<&str> = [("1", "has_street"), ("2", "has_street2"), ("3", "has_city"), ("4", "has_state"), ("5", "has_zip"), ("6", "has_country")].iter().filter(|(n, _)| shown(n)).map(|(_, c)| *c).collect();
    let mut out = format!("<div class='ginput_complex ginput_container {} ginput_container_address gform-grid-row' id='{id}' >", has.join(" "));
    let sub_label = |sub_id: &str, text: &str| format!("<label for='{sub_id}' id='{sub_id}_label' class='gform-field-label gform-field-label--type-sub '>{}</label>", esc(text));
    for (n, kind, side) in [("1", "line1", "ginput_full"), ("2", "line2", "ginput_full"), ("3", "city", "ginput_left"), ("4", "state", "ginput_right"), ("5", "zip", "ginput_left"), ("6", "country", "ginput_right")] {
        let Some(sub) = part(n) else { continue };
        let name = format!("input_{}", sub.id);
        let sub_id = format!("{id}_{n}");
        if sub.hidden {
            if n == "4" {
                out.push_str(&format!("<input type='hidden' class='gform_hidden' name='{name}' id='{sub_id}' value='{}'/>", value(r, &name)));
            }
            continue;
        }
        let side = match n {
            "6" if !shown("4") => "ginput_left",
            "5" if !shown("4") => "ginput_right",
            _ => side,
        };
        out.push_str(&format!("<span class='{side} address_{kind} ginput_address_{kind} gform-grid-col' id='{sub_id}_container' >"));
        if n == "6" {
            let typed = r.values.get(&name).filter(|v| !v.is_empty()).cloned();
            let chosen = typed.unwrap_or_else(|| COUNTRIES.iter().find(|(_, name)| *name == default_country).map_or(String::new(), |(c, _)| c.to_string()));
            out.push_str(&format!("<select name='{name}' id='{sub_id}' aria-required='false' ><option value='' ></option>"));
            for (code, country) in COUNTRIES {
                let selected = if code == chosen { "selected='selected'" } else { "" };
                out.push_str(&format!("<option value='{code}' {selected}>{}</option>", esc(country)));
            }
            out.push_str("</select>");
        } else {
            out.push_str(&format!("<input type='text' name='{name}' id='{sub_id}' value='{}' aria-required='false' />", value(r, &name)));
        }
        out.push_str(&sub_label(&sub_id, &sub.label));
        out.push_str("</span>");
    }
    out.push_str("<div class='gf_clear gf_clear_complex'></div></div>");
    out
}

fn field(r: &Render, f: &Field, tag: &str) -> String {
    let fid = r.form.id;
    let error = r.errors.get(&f.id);
    let tail = " field_sublabel_below gfield--no-description field_description_below field_validation_below gfield_visibility_visible";
    match &f.kind {
        Kind::Unsupported(_) => return String::new(),
        Kind::Html(content) => {
            return format!("<{tag} id=\"field_{fid}_{}\" class=\"gfield gfield--type-html{}{} gfield_html gfield_html_formatted gfield_no_follows_desc{tail}\" >{content}</{tag}>", f.id, input_type(f), width(f));
        }
        _ => {}
    }
    let mut class = format!("gfield gfield--type-{}{}", f.type_name(), input_type(f));
    match &f.kind {
        Kind::Address { .. } if f.input_type.is_empty() => class.push_str(" gfield--input-type-address"),
        Kind::Date { .. } => class.push_str(" gfield--datepicker-default-icon"),
        Kind::Phone(PhoneFormat::Standard) => class.push_str(" gfield--phone-format-standard"),
        Kind::Phone(PhoneFormat::International) => class.push_str(" gfield--phone-format-international"),
        _ => {}
    }
    class.push_str(width(f));
    if error.is_some() {
        class.push_str(" gfield_error");
    }
    if !f.css_class.is_empty() {
        class.push(' ');
        class.push_str(&esc(&f.css_class));
    }
    if f.required {
        class.push_str(" gfield_contains_required");
    }
    class.push_str(tail);
    let message = error.map_or(String::new(), |m| format!("<div id='validation_message_{fid}_{}' class='gfield_description validation_message gfield_validation_message'>{}</div>", f.id, esc(m)));
    match f.kind {
        Kind::Address { .. } => format!(
            "<fieldset id=\"field_{fid}_{}\" class=\"{class}\" ><legend class='gfield_label gform-field-label gfield_label_before_complex' ><span class='gform-field-label__text'>{}</span>{}</legend>{}{message}</fieldset>",
            f.id,
            esc(&f.label),
            if f.required { ASTERISK } else { "" },
            control(r, f)
        ),
        _ => format!("<{tag} id=\"field_{fid}_{}\" class=\"{class}\" >{}{}{message}</{tag}>", f.id, label(r, f), control(r, f)),
    }
}

fn honeypot(r: &Render, tag: &str) -> String {
    let fid = r.form.id;
    let hid = honeypot_id(r.form);
    format!(
        "<{tag} id=\"field_{fid}_{hid}\" class=\"gfield gfield--type-honeypot gform_validation_container field_sublabel_below gfield--has-description field_description_below field_validation_below gfield_visibility_visible\" ><label class='gfield_label gform-field-label' for='input_{fid}_{hid}'><span class='gform-field-label__text'>{HONEYPOT_LABEL}</span></label><div class='ginput_container'><input name='input_{hid}' id='input_{fid}_{hid}' type='text' value='' autocomplete='new-password'/></div><div class='gfield_description' id='gfield_description_{fid}_{hid}'>{}</div></{tag}>",
        wording(r.form).honeypot_note
    )
}

fn akismet(position: usize) -> String {
    format!(
        "<div style=\"display: none !important;\" class=\"akismet-fields-container gf_invisible\" data-prefix=\"ak_\"><label>&#916;<textarea name=\"ak_hp_textarea\" cols=\"45\" rows=\"8\" maxlength=\"100\"></textarea></label><input type=\"hidden\" id=\"ak_js_{position}\" name=\"ak_js\" value=\"0\" /><script type=\"text/javascript\">\n/* <![CDATA[ */\ndocument.getElementById( \"ak_js_{position}\" ).setAttribute( \"value\", ( new Date() ).getTime() );\n/* ]]> */\n</script>\n</div>"
    )
}

/// The `gform_ajax` value. Gravity Forms signs the shortcode attributes with the WordPress salts so a post cannot
/// change them; these are fixed and never read back, so the hash only keeps the field's shape.
pub fn gform_ajax(form_id: i64, theme: &str) -> String {
    use sha2::{Digest, Sha256};
    let args = format!("form_id={form_id}&title=&description=&tabindex=0&theme={theme}");
    let hash = hex::encode(&Sha256::digest(args.as_bytes())[..16]);
    format!("{args}&hash={hash}")
}

/// The form, empty or with what was posted and its errors.
pub fn form(r: &Render) -> String {
    let f = r.form;
    let fid = f.id;
    let theme = theme(f);
    let wrapper_class = if f.gravity_theme { "gravity-theme" } else { "gform_legacy_markup_wrapper" };
    let invalid = if r.summary.is_some() { " gform_validation_error" } else { "" };
    let mut out = format!("<div class='{} gform_wrapper {wrapper_class} gform-theme--no-framework{invalid}' data-form-theme='{theme}' data-form-index='0' id='gform_wrapper_{fid}' >", r.browser);
    if r.embed.ajax {
        out.push_str(&format!("<div id='gf_{fid}' class='gform_anchor' tabindex='-1'></div>"));
    }
    if let Some(s) = r.summary {
        out.push_str(&summary(f, s));
    }
    if f.gravity_theme {
        out.push_str(&format!("<div class='gform_heading'><p class='gform_required_legend'>{}</p></div>", wording(f).legend));
    }
    let (target, anchor) = if r.embed.ajax { (format!(" target='gform_ajax_frame_{fid}'"), format!("#gf_{fid}")) } else { (String::new(), String::new()) };
    let css = if f.css_class.is_empty() { String::new() } else { format!(" class='{}'", esc(&f.css_class)) };
    out.push_str(&format!("<form method='post' enctype='multipart/form-data'{target} id='gform_{fid}'{css} action='{}' data-formid='{fid}' novalidate>", form_action(&format!("{}{anchor}", r.page_uri))));
    if r.recaptcha {
        out.push_str("<input type='hidden' class='registry_recaptcha_response' name='registry_recaptcha_response' value=''/>");
    }
    out.push_str(&akismet(r.embed.position));
    let (list, item) = if f.gravity_theme { ("div", "div") } else { ("ul", "li") };
    out.push_str(&format!("<div class='gform-body gform_body'><{list} id='gform_fields_{fid}' class='gform_fields top_label form_sublabel_below description_below validation_below'>"));
    if f.honeypot != Honeypot::Off {
        out.push_str(&honeypot(r, item));
    }
    for each in &f.fields {
        out.push_str(&field(r, each, item));
    }
    out.push_str(&format!("</{list}></div>"));
    out.push_str(&format!(
        "<div class='gform-footer gform_footer top_label'> <button type='submit' id='gform_submit_button_{fid}' class='gform_button button' onclick='gform.submission.handleButtonClick(this);' data-submission-type='submit' >{}</button> ",
        esc(&f.button)
    ));
    if r.embed.ajax {
        out.push_str(&format!("<input type='hidden' name='gform_ajax' value='{}' />", esc(&gform_ajax(fid, theme))));
    }
    let token = r.token.map(esc).unwrap_or_default();
    out.push_str(&format!(
        "<input type='hidden' class='gform_hidden' name='gform_submission_method' data-js='gform_submission_method_{fid}' value='{}' /><input type='hidden' class='gform_hidden' name='gform_theme' data-js='gform_theme_{fid}' id='gform_theme_{fid}' value='{theme}' /><input type='hidden' class='gform_hidden' name='gform_style_settings' data-js='gform_style_settings_{fid}' id='gform_style_settings_{fid}' value='{}' /><input type='hidden' class='gform_hidden' name='is_submit_{fid}' value='1' /><input type='hidden' class='gform_hidden' name='gform_submit' value='{fid}' /><input type='hidden' class='gform_hidden' name='gform_currency' data-currency='USD' value='{token}' /><input type='hidden' class='gform_hidden' name='gform_unique_id' value='' /><input type='hidden' class='gform_hidden' name='state_{fid}' value='{token}' /><input type='hidden' autocomplete='off' class='gform_hidden' name='gform_target_page_number_{fid}' id='gform_target_page_number_{fid}' value='0' /><input type='hidden' autocomplete='off' class='gform_hidden' name='gform_source_page_number_{fid}' id='gform_source_page_number_{fid}' value='1' /><input type='hidden' name='gform_field_values' value='' /></div></form></div>",
        if r.embed.ajax { "iframe" } else { "postback" },
        esc(&r.embed.styles)
    ));
    // A form drawn again for a refused post goes into the iframe the page already has.
    if r.embed.ajax && r.summary.is_none() {
        out.push_str(&format!(
            "<iframe style='display:none;width:0px;height:0px;' src='about:blank' name='gform_ajax_frame_{fid}' id='gform_ajax_frame_{fid}' title='{}'></iframe>",
            wording(f).iframe_title
        ));
        out.push_str(&super::ajax_script::script(fid));
    }
    finish(out, r.embed)
}

/// The form's action as Gravity Forms wrote it: `remove_query_arg('gf_token')` over the request URI, which parses the
/// query and writes it again (values urlencoded, keys as parsed, `=` dropped from empty values), then `esc_url()`.
fn form_action(uri: &str) -> String {
    let (before_fragment, fragment) = uri.split_once('#').map_or((uri, ""), |(u, f)| (u, f));
    let rebuilt = match before_fragment.split_once('?') {
        None => before_fragment.to_string(),
        Some((path, query)) => {
            let mut pairs: Vec<(String, String)> = Vec::new();
            for (k, v) in form_urlencoded::parse(query.as_bytes()) {
                let k = k.replace([' ', '.'], "_");
                if k == "gf_token" {
                    continue;
                }
                match pairs.iter_mut().find(|(seen, _)| *seen == k) {
                    Some(slot) => slot.1 = v.into_owned(),
                    None => pairs.push((k, v.into_owned())),
                }
            }
            let query: Vec<String> = pairs.iter().map(|(k, v)| if v.is_empty() { k.clone() } else { format!("{k}={}", php_urlencode(v)) }).collect();
            if query.is_empty() { path.to_string() } else { format!("{path}?{}", query.join("&")) }
        }
    };
    let url = if fragment.is_empty() { rebuilt } else { format!("{rebuilt}#{fragment}") };
    esc_url(&url)
}

/// PHP's urlencode(): everything but letters, digits and `-_.` as %XX, spaces as `+`.
fn php_urlencode(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for b in s.bytes() {
        match b {
            b'a'..=b'z' | b'A'..=b'Z' | b'0'..=b'9' | b'-' | b'_' | b'.' => out.push(b as char),
            b' ' => out.push('+'),
            _ => out.push_str(&format!("%{b:02X}")),
        }
    }
    out
}

/// WordPress's esc_url() for an attribute: spaces as %20, characters a URL may not hold dropped, `&` and `'` as
/// entities. An `&amp;` already in the URL is one ampersand, as wp_kses_normalize_entities() read it.
fn esc_url(url: &str) -> String {
    let allowed = |c: char| c.is_ascii_alphanumeric() || "-~+_.?#=!&;,/:%@$|*'()[]".contains(c) || !c.is_ascii();
    let mut out = String::with_capacity(url.len() + 16);
    let mut rest = url.trim_start();
    while let Some(c) = rest.chars().next() {
        rest = &rest[c.len_utf8()..];
        match c {
            ' ' => out.push_str("%20"),
            '&' => {
                out.push_str("&#038;");
                rest = rest.strip_prefix("amp;").unwrap_or(rest);
            }
            '\'' => out.push_str("&#039;"),
            c if allowed(c) => out.push(c),
            _ => {}
        }
    }
    out
}

/// What WordPress did to a form printed inside post content: curly apostrophes where the export recorded
/// texturizing, and `]]>` written as `]]&gt;`.
fn finish(html: String, embed: &Embed) -> String {
    let html = if embed.texturize { texturize(&html) } else { html };
    if embed.in_content { html.replace("]]>", "]]&gt;") } else { html }
}

/// wptexturize's apostrophe rule, in text between tags only.
pub fn texturize(html: &str) -> String {
    let mut out = String::with_capacity(html.len());
    let mut in_tag = false;
    let mut prev = ' ';
    for c in html.chars() {
        match c {
            '<' => in_tag = true,
            '>' => in_tag = false,
            _ => {}
        }
        if c == '\'' && !in_tag && prev.is_alphanumeric() {
            out.push_str("&rsquo;");
        } else {
            out.push(c);
        }
        prev = c;
    }
    out
}

/// A form's answer to a post, in the page that holds the form.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Answer {
    pub form: u32,
    /// What takes the form's place: the form again with its errors, or the confirmation.
    pub html: String,
    pub confirmed: bool,
    /// The post made an entry that is not spam.
    pub stored: bool,
}

impl Answer {
    /// The page as WordPress printed it around this answer, the form being the page's `position`th (from 1). A
    /// confirmed form is no longer drawn: the footer has none of its scripts, and Akismet numbers the forms after it
    /// one lower.
    pub fn page(&self, page: &str, position: usize) -> String {
        let page = with_duplicate_guard(page, self.stored);
        if !self.confirmed {
            return page;
        }
        renumber_akismet(&without_form_scripts(&page, self.form), position)
    }
}

/// Removes what Gravity Forms and Zero Spam print in the footer for a drawn form: its init scripts and its Zero
/// Spam entry (with the Zero Spam script when no form is left).
fn without_form_scripts(page: &str, form: u32) -> String {
    let markers = [format!("if(formId == {form})"), format!("formId: \"{form}\", currentPage")];
    let mut out = String::with_capacity(page.len());
    let mut rest = page;
    let mut zero_spam_gone = false;
    while let Some(start) = rest.find("<script") {
        let Some(len) = rest[start..].find("</script>").map(|e| e + "</script>".len()) else { break };
        let element = &rest[start..start + len];
        out.push_str(&rest[..start]);
        if element.contains("var gfZeroSpamConfig") {
            match zero_spam_without(element, form) {
                Some(kept) => out.push_str(&kept),
                None => zero_spam_gone = true,
            }
        } else if !(markers.iter().any(|m| element.contains(m.as_str())) || zero_spam_gone && element.contains("id=\"gf-zero-spam-js\"")) {
            out.push_str(element);
        }
        rest = &rest[start + len..];
    }
    out.push_str(rest);
    out
}

/// The Zero Spam config without `form`'s entry, or None when it listed no other form.
fn zero_spam_without(element: &str, form: u32) -> Option<String> {
    let (head, tail) = element.split_once("\"forms\":[{")?;
    let (list, after) = tail.split_once("}]")?;
    let own = format!("\"formId\":{form},");
    let kept: Vec<&str> = list.split("},{").filter(|entry| !entry.contains(own.as_str())).collect();
    (!kept.is_empty()).then(|| format!("{head}\"forms\":[{{{}}}]{after}", kept.join("},{")))
}

/// Akismet numbered its `ak_js_N` field per form it drew; with the form at `position` gone, later ones move down.
fn renumber_akismet(page: &str, position: usize) -> String {
    let mut out = String::with_capacity(page.len());
    let mut rest = page;
    while let Some(at) = rest.find("ak_js_") {
        let digits = rest[at + 6..].bytes().take_while(u8::is_ascii_digit).count();
        let n: usize = rest[at + 6..at + 6 + digits].parse().unwrap_or(0);
        out.push_str(&rest[..at + 6]);
        out.push_str(&(if n > position { n - 1 } else { n }).to_string());
        rest = &rest[at + 6 + digits..];
    }
    out.push_str(rest);
    out
}

/// A page that answers a Gravity Forms post carries the duplicate-submissions guard after the Gravity Forms
/// script, as WordPress enqueued it; `stored` says the post made an entry.
fn with_duplicate_guard(page: &str, stored: bool) -> String {
    const AFTER: &str = "id=\"gform_gravityforms-js\"></script>";
    let Some(at) = page.find(AFTER).map(|i| i + AFTER.len()) else { return page.to_string() };
    let guard = format!(
        "\n<script type=\"text/javascript\" id=\"gform_duplicate_submissions-js-extra\">\n/* <![CDATA[ */\nvar gf_duplicate_submissions = {{\"is_gf_submission\":\"{}\",\"safari_redirect_param\":\"gf_protect_submission\"}};\n/* ]]> */\n</script>\n<script type=\"text/javascript\" defer='defer' src=\"https://www.harcourt.co/wp-content/plugins/gravityforms/js/duplicate-submissions.min.js?ver=1\" id=\"gform_duplicate_submissions-js\"></script>",
        u8::from(stored)
    );
    format!("{}{guard}{}", &page[..at], &page[at..])
}

/// A message confirmation, as Gravity Forms wraps it for the form's theme. `anchor`: Gravity Forms' own ajax post,
/// whose answer begins with the form's anchor.
pub fn confirmation(form: &Form, message: &str, anchor: bool) -> String {
    let id = form.id;
    let (theme, classes) = if form.gravity_theme {
        ("gravity-theme", "gravity-theme gform-theme--no-framework")
    } else {
        ("legacy", "gform_legacy_markup_wrapper gform_legacy_confirmation_markup_wrapper gform-theme--no-framework")
    };
    let anchor = if anchor { format!("<div id='gf_{id}' class='gform_anchor' tabindex='-1'></div>") } else { String::new() };
    format!(
        "{anchor}<div id='gform_confirmation_wrapper_{id}' data-form-theme='{theme}' class='gform_confirmation_wrapper {classes} {}'><div id='gform_confirmation_message_{id}' class='gform_confirmation_message_{id} gform_confirmation_message'>{message}</div></div>",
        esc(&form.css_class)
    )
}

/// The page an ajax form's hidden iframe receives; the Gravity Forms script moves its body into the page. `in_page`:
/// printed inside a WordPress page, whose every body tag Complianz marks.
pub fn ajax_postback(inner: &str, in_page: bool) -> String {
    let cmplz = if in_page { " data-cmplz=1" } else { "" };
    format!("<!DOCTYPE html><html><head><meta charset='UTF-8' /></head><body{cmplz} class='GF_AJAX_POSTBACK'>{inner}</body></html>")
}

/// Gravity Forms' own ajax post sends back the `gform_ajax` value the form was drawn with; anything else was
/// tampered with.
pub fn ajax_args_valid(posted: &str, form: &Form) -> bool {
    posted == gform_ajax(form.id, theme(form))
}

/// What Gravity Forms answered a tampered ajax post: its default confirmation, for no form, and nothing kept.
pub fn tampered(form: &Form) -> String {
    format!(
        "<div id='gf_0' class='gform_anchor' tabindex='-1'></div><div id='gform_confirmation_wrapper_0' class='gform_confirmation_wrapper '><div id='gform_confirmation_message_0' class='gform_confirmation_message_0 gform_confirmation_message'>{}</div></div>",
        wording(form).thanks
    )
}

fn theme(f: &Form) -> &'static str {
    if f.gravity_theme { "gravity-theme" } else { "legacy" }
}

pub fn phone_ok(format: PhoneFormat, value: &str) -> bool {
    match format {
        PhoneFormat::International => true,
        PhoneFormat::Standard => {
            let digits = value.chars().filter(char::is_ascii_digit).count();
            let others = value.chars().filter(|c| !c.is_ascii_digit()).count();
            digits == 10 && others <= 4 && value.chars().next().is_some_and(|c| c.is_ascii_digit() || c == '(' || c == '+' || c == ' ')
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::orders::forms::model::{parse, tests::*};

    #[test]
    fn the_action_is_the_page_query_as_wordpress_rebuilt_it() {
        assert_eq!(form_action("/catalog/"), "/catalog/");
        assert_eq!(form_action("/catalogue/?lang=fr"), "/catalogue/?lang=fr");
        assert_eq!(
            form_action("/catalog/?action=subcategory&subcategory_id=440&page=1&parameter0=7/16-14"),
            "/catalog/?action=subcategory&#038;subcategory_id=440&#038;page=1&#038;parameter0=7%2F16-14"
        );
        assert_eq!(form_action("/catalog/?search_source=search&search_id=21304&search_code="), "/catalog/?search_source=search&#038;search_id=21304&#038;search_code");
        assert_eq!(form_action("/catalog/?search_source=search&amp%3Bsearch_id=698&amp%3Bsearch_code="), "/catalog/?search_source=search&#038;search_id=698&#038;search_code");
        assert_eq!(form_action("/a/?x=1&x=2&y.z=a b&gf_token=t&l[]=p&l[]=q"), "/a/?x=2&#038;y_z=a+b&#038;l%5B0%5D=p&#038;l%5B1%5D=q");
    }

    #[test]
    fn the_form_carries_the_gravity_forms_names() {
        let f = parse(META, NOTIFICATIONS, CONFIRMATIONS).unwrap();
        let values = Values::from([("input_1".to_string(), "Pat <b>".to_string())]);
        let errors = FieldErrors::from([(10, "This field is required.".to_string())]);
        let embed = Embed::default_for(3);
        let html = form(&Render { form: &f, page_uri: "/contact-us/?a=1&b=2", embed: &embed, values: &values, errors: &errors, summary: Some(Summary::Blocked), token: Some("1.ab"), recaptcha: true, browser: "gf_browser_unknown" });
        for part in [
            "id='gform_wrapper_3'",
            "action='/contact-us/?a=1&#038;b=2'",
            "<ul id='gform_fields_3'",
            "name='input_1' id='input_3_1' type='text' value='Pat &lt;b&gt;'",
            "<div class=\"gform_validation_errors validation_error\" id=\"gform_3_validation_container\" data-js=\"gform-focus-validation-error\" autofocus><div class='validation_error'>We were unable",
            "<li id=\"field_3_10\" class=\"gfield gfield--type-email gfield_error gfield_contains_required field_sublabel_below gfield--no-description field_description_below field_validation_below gfield_visibility_visible\" >",
            "type='email' value='' class='medium'  aria-required=\"true\" aria-invalid=\"true\" aria-describedby=\"validation_message_3_10\" />",
            "<div id='validation_message_3_10' class='gfield_description validation_message gfield_validation_message'>This field is required.</div>",
            "name='input_21' id='input_3_21' type='text' value='' autocomplete='new-password'",
            "name='registry_recaptcha_response'",
            "<input type='hidden' class='gform_hidden' name='gform_submit' value='3' />",
            "name='state_3' value='1.ab'",
            "/* ]]&gt; */",
        ] {
            assert!(html.contains(part), "{part}\n{html}");
        }
        assert!(!html.contains("Odd"), "unsupported fields render nothing");
    }

    #[test]
    fn browsers_are_named_as_wordpress_reads_them() {
        assert_eq!(browser_class("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36"), "gf_browser_chrome");
        assert_eq!(browser_class("Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1"), "gf_browser_safari gf_browser_iphone");
        assert_eq!(browser_class("Mozilla/5.0 (Windows NT 10.0; rv:130.0) Gecko/20100101 Firefox/130.0"), "gf_browser_gecko");
        assert_eq!(browser_class("hxweb/0.1 island"), "gf_browser_unknown");
    }

    #[test]
    fn the_ajax_value_ends_in_a_hash_like_gravity_forms() {
        let value = gform_ajax(1, "legacy");
        let (args, hash) = value.split_once("&hash=").unwrap();
        assert_eq!(args, "form_id=1&title=&description=&tabindex=0&theme=legacy");
        assert!(hash.len() == 32 && hash.bytes().all(|b| b.is_ascii_hexdigit()), "{hash}");
    }

    #[test]
    fn the_duplicate_guard_follows_the_gravity_forms_script() {
        let page = "<script src=\"g.js\" id=\"gform_gravityforms-js\"></script>\n<script id=\"next\"></script>";
        let guarded = with_duplicate_guard(page, true);
        assert!(guarded.starts_with("<script src=\"g.js\" id=\"gform_gravityforms-js\"></script>\n<script type=\"text/javascript\" id=\"gform_duplicate_submissions-js-extra\">"));
        assert!(guarded.contains("{\"is_gf_submission\":\"1\",") && guarded.ends_with("id=\"gform_duplicate_submissions-js\"></script>\n<script id=\"next\"></script>"));
        assert_eq!(with_duplicate_guard("<p>no forms script</p>", false), "<p>no forms script</p>");
    }

    #[test]
    fn a_confirmed_form_leaves_no_footer_scripts_and_later_forms_renumber() {
        let page = "<p id=\"ak_js_1\"></p><p id=\"ak_js_2\"></p>\n<script type='text/javascript'>var gfZeroSpamConfig = {\"forms\":[{\"formId\":11,\"timeout\":3000},{\"formId\":9,\"timeout\":3000}],\"debug\":false};</script>\n<script src=\"z.js\" id=\"gf-zero-spam-js\"></script>\n<script>if(formId == 11) {}</script><script>[{ formId: \"11\", currentPage: \"1\" }]</script><script>if(formId == 9) {}</script>";
        let answer = Answer { form: 11, html: String::new(), confirmed: true, stored: false };
        let out = answer.page(page, 1);
        assert_eq!(
            out,
            "<p id=\"ak_js_1\"></p><p id=\"ak_js_1\"></p>\n<script type='text/javascript'>var gfZeroSpamConfig = {\"forms\":[{\"formId\":9,\"timeout\":3000}],\"debug\":false};</script>\n<script src=\"z.js\" id=\"gf-zero-spam-js\"></script>\n<script>if(formId == 9) {}</script>"
        );
        let only = "<script>var gfZeroSpamConfig = {\"forms\":[{\"formId\":11,\"timeout\":3000}]};</script><script src=\"z.js\" id=\"gf-zero-spam-js\"></script><script src=\"a.js\"></script>";
        assert_eq!(answer.page(only, 1), "<script src=\"a.js\"></script>");
        let again = Answer { confirmed: false, ..answer };
        assert_eq!(again.page(page, 1), page, "a form drawn again keeps its scripts");
    }

    #[test]
    fn the_action_is_the_request_uri_as_gravity_forms_rebuilt_it() {
        assert_eq!(form_action("/contact/#gf_1"), "/contact/#gf_1");
        assert_eq!(form_action("/contact/?utm_source=Master+Screw,+return...+%28Mass%29&utm_medium=Email#gf_1"), "/contact/?utm_source=Master+Screw%2C+return...+%28Mass%29&#038;utm_medium=Email#gf_1");
        assert_eq!(form_action("/contact/?a=&gf_token=x&b=1&b=2"), "/contact/?a&#038;b=2");
        assert_eq!(form_action("/p/?q=<x>'"), "/p/?q=%3Cx%3E%27");
        assert_eq!(form_action("/catalog/?amp;action=subcategory&amp;subcategory_id=7#gf_1"), "/catalog/?amp;action=subcategory&#038;subcategory_id=7#gf_1");
    }

    #[test]
    fn texturize_curls_apostrophes_in_text_only() {
        assert_eq!(texturize("<a title='x'>d'utilisation</a>"), "<a title='x'>d&rsquo;utilisation</a>");
    }

    #[test]
    fn standard_phone_numbers_need_ten_digits() {
        assert!(phone_ok(PhoneFormat::Standard, "(555) 010-0100"));
        assert!(phone_ok(PhoneFormat::Standard, "555-010-0100"));
        assert!(!phone_ok(PhoneFormat::Standard, "12345"));
        assert!(phone_ok(PhoneFormat::International, "+44 20 7946 0000"));
    }
}
