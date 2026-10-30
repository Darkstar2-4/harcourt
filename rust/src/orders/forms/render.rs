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
    pub summary: Option<&'a str>,
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

/// The message above a form that did not validate.
pub fn summary(form_id: i64) -> String {
    format!("<div class='gform_validation_errors' id='gform_{form_id}_validation_container' data-js='gform-focus-validation-error'><h2 class='gform_submission_error hide_summary'><span class='gform-icon gform-icon--close'></span>There was a problem with your submission. Please review the fields below.</h2></div>")
}

pub const HONEYPOT_NOTE: &str = "This field is for validation purposes and should be left unchanged.";
const HONEYPOT_NOTE_FR: &str = "Ce champ n\u{2019}est utilisé qu\u{2019}à des fins de validation et devrait rester inchangé.";
const LEGEND: &str = "&quot;<span class=\"gfield_required gfield_required_asterisk\">*</span>&quot; indicates required fields";
const LEGEND_FR: &str = "«\u{a0}<span class=\"gfield_required gfield_required_asterisk\">*</span>\u{a0}» indique les champs nécessaires";
const IFRAME_TITLE: &str = "This iframe contains the logic required to handle Ajax powered Gravity Forms.";
const IFRAME_TITLE_FR: &str = "Cette iframe contient la logique nécessaire pour manipuler Gravity Forms avec Ajax.";
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

fn control(r: &Render, f: &Field) -> String {
    let fid = r.form.id;
    let name = format!("input_{}", f.id);
    let id = format!("input_{fid}_{}", f.id);
    let size = esc(&f.size);
    let v = value(r, &name);
    let aria = aria(r, f);
    match &f.kind {
        Kind::Text => format!("<div class='ginput_container ginput_container_text'><input name='{name}' id='{id}' type='text' value='{v}' class='{size}' {aria} /></div>"),
        Kind::Email => format!("<div class='ginput_container ginput_container_email'><input name='{name}' id='{id}' type='email' value='{v}' class='{size}' {aria} /></div>"),
        Kind::Phone(PhoneFormat::Standard) => format!(
            "<div class='ginput_container ginput_container_phone'><input name='{name}' id='{id}' type='tel' value='{v}' class='{size}' placeholder='(999) 999-9999'{aria} data-mask=\"(999) 999-9999\" /></div>"
        ),
        Kind::Phone(PhoneFormat::International) => format!("<div class='ginput_container ginput_container_phone'><input name='{name}' id='{id}' type='tel' value='{v}' class='{size}' {aria} /></div>"),
        Kind::Textarea => format!("<div class='ginput_container ginput_container_textarea'><textarea name='{name}' id='{id}' class='textarea {size}' {aria} rows='10' cols='50'>{v}</textarea></div>"),
        Kind::Date { dmy } => {
            let format = if *dmy { "dmy" } else { "mdy" };
            let placeholder = match (*dmy, r.form.french) {
                (true, true) => "jj/mm/aaaa",
                (true, false) => "dd/mm/yyyy",
                _ => "mm/dd/yyyy",
            };
            format!(
                "<div class='ginput_container ginput_container_date'><input placeholder='{placeholder}' id='{id}' class='datepicker gform-datepicker {format} datepicker_with_icon gdatepicker_with_icon' type='text' name='{name}' value='{v}' aria-invalid=\"{}\" data-mask=\"99/99/9999\" /><kbd id='keyboardHint_{id}' hidden class='down'></kbd><button type='button' id='datepicker_toggle_{id}' class='gform-datepicker-toggle gform-datepicker-toggle--default accCalendar aria-date-picker gform-button gform-theme-button gform-theme-button--simple gform-theme-button--simple-in-ctrl' aria-expanded='false' aria-controls='{id}' aria-label='{}: Choose date on calendar' ><span class=\"gform-calendar-icon gform-datepicker-toggle-icon gform-datepicker-toggle-icon--default dashicons dashicons-calendar-alt\" aria-hidden=\"true\"></span></button></div>",
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
            let typed = r.values.get(&name).cloned();
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
    if !f.css_class.is_empty() {
        class.push(' ');
        class.push_str(&esc(&f.css_class));
    }
    class.push_str(width(f));
    if f.required {
        class.push_str(" gfield_contains_required");
    }
    class.push_str(tail);
    if error.is_some() {
        class.push_str(" gfield_error");
    }
    let message = error.map_or(String::new(), |m| format!("<div class='gfield_description validation_message gfield_validation_message'>{}</div>", esc(m)));
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
        if r.form.french { HONEYPOT_NOTE_FR } else { HONEYPOT_NOTE }
    )
}

fn akismet(position: usize) -> String {
    format!(
        "<div style=\"display: none !important;\" class=\"akismet-fields-container gf_invisible\" data-prefix=\"ak_\"><label>&#916;<textarea name=\"ak_hp_textarea\" cols=\"45\" rows=\"8\" maxlength=\"100\"></textarea></label><input type=\"hidden\" id=\"ak_js_{position}\" name=\"ak_js\" value=\"0\" /><script type=\"text/javascript\">\n/* <![CDATA[ */\ndocument.getElementById( \"ak_js_{position}\" ).setAttribute( \"value\", ( new Date() ).getTime() );\n/* ]]> */\n</script>\n</div>"
    )
}

/// The `gform_ajax` value. Gravity Forms signs the shortcode attributes with the WordPress salts so a post cannot
/// change them; these are fixed and never read back, so the hash only keeps the field's shape.
fn gform_ajax(form_id: i64, theme: &str) -> String {
    use sha2::{Digest, Sha256};
    let args = format!("form_id={form_id}&title=&description=&tabindex=0&theme={theme}");
    let hash = hex::encode(&Sha256::digest(args.as_bytes())[..16]);
    format!("{args}&hash={hash}")
}

/// The form, empty or with what was posted and its errors.
pub fn form(r: &Render) -> String {
    let f = r.form;
    let fid = f.id;
    let theme = if f.gravity_theme { "gravity-theme" } else { "legacy" };
    let wrapper_class = if f.gravity_theme { "gravity-theme" } else { "gform_legacy_markup_wrapper" };
    let invalid = if r.summary.is_some() { " gform_validation_error" } else { "" };
    let mut out = format!("<div class='{} gform_wrapper {wrapper_class}{invalid} gform-theme--no-framework' data-form-theme='{theme}' data-form-index='0' id='gform_wrapper_{fid}' >", r.browser);
    if r.embed.ajax {
        out.push_str(&format!("<div id='gf_{fid}' class='gform_anchor' tabindex='-1'></div>"));
    }
    if f.gravity_theme {
        out.push_str(&format!("<div class='gform_heading'><p class='gform_required_legend'>{}</p></div>", if f.french { LEGEND_FR } else { LEGEND }));
    }
    if let Some(summary) = r.summary {
        out.push_str(summary);
    }
    let (target, anchor) = if r.embed.ajax { (format!(" target='gform_ajax_frame_{fid}'"), format!("#gf_{fid}")) } else { (String::new(), String::new()) };
    let css = if f.css_class.is_empty() { String::new() } else { format!(" class='{}'", esc(&f.css_class)) };
    out.push_str(&format!("<form method='post' enctype='multipart/form-data'{target} id='gform_{fid}'{css} action='{}{anchor}' data-formid='{fid}' novalidate>", crate::cms::url::form_action(r.page_uri)));
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
    if r.embed.ajax {
        out.push_str(&format!(
            "<iframe style='display:none;width:0px;height:0px;' src='about:blank' name='gform_ajax_frame_{fid}' id='gform_ajax_frame_{fid}' title='{}'></iframe>",
            if f.french { IFRAME_TITLE_FR } else { IFRAME_TITLE }
        ));
        out.push_str(&super::ajax_script::script(fid));
    }
    finish(out, r.embed)
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

/// A message confirmation, as Gravity Forms wraps it.
pub fn confirmation(form_id: i64, message: &str) -> String {
    format!("<div id='gform_confirmation_wrapper_{form_id}' class='gform_confirmation_wrapper '><div id='gform_confirmation_message_{form_id}' class='gform_confirmation_message_{form_id} gform_confirmation_message'>{message}</div></div>")
}

/// The page an ajax form's hidden iframe receives; the Gravity Forms script moves its body into the page.
pub fn ajax_postback(inner: &str) -> String {
    format!("<!DOCTYPE html><html><head><meta charset='UTF-8' /></head><body class='GF_AJAX_POSTBACK'>{inner}</body></html>")
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
    fn the_form_carries_the_gravity_forms_names() {
        let f = parse(META, NOTIFICATIONS, CONFIRMATIONS).unwrap();
        let values = Values::from([("input_1".to_string(), "Pat <b>".to_string())]);
        let errors = FieldErrors::from([(10, "This field is required.".to_string())]);
        let embed = Embed::default_for(3);
        let html = form(&Render { form: &f, page_uri: "/contact-us/?a=1&b=2", embed: &embed, values: &values, errors: &errors, summary: Some("<div class='validation_error'>x</div>"), token: Some("1.ab"), recaptcha: true, browser: "gf_browser_unknown" });
        for part in [
            "id='gform_wrapper_3'",
            "action='/contact-us/?a=1&#038;b=2'",
            "<ul id='gform_fields_3'",
            "name='input_1' id='input_3_1' type='text' value='Pat &lt;b&gt;'",
            "<li id=\"field_3_10\" class=\"gfield gfield--type-email gfield_contains_required field_sublabel_below gfield--no-description field_description_below field_validation_below gfield_visibility_visible gfield_error\" >",
            "<div class='gfield_description validation_message gfield_validation_message'>This field is required.</div>",
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
