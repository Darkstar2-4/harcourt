//! The markup `GFForms::get_form()` printed for these forms: the wrapper, fields, honeypot, footer and hidden
//! inputs, with the ids and classes the Gravity Forms stylesheet and scripts select on.

use std::collections::HashMap;

use super::model::{Field, Form, Honeypot, Kind, PhoneFormat};
use crate::orders::html::esc;

/// What the visitor typed, by input name (`input_1`, `input_14.3`).
pub type Values = HashMap<String, String>;
/// Validation messages by field id.
pub type FieldErrors = HashMap<i64, String>;

pub struct Render<'a> {
    pub form: &'a Form,
    pub page_uri: &'a str,
    pub ajax: bool,
    pub values: &'a Values,
    pub errors: &'a FieldErrors,
    /// The form-level message shown above the fields when the post was refused.
    pub summary: Option<&'a str>,
    pub token: Option<&'a str>,
    pub recaptcha: bool,
}

pub const SUMMARY: &str = "<h2 class=\"gform_submission_error hide_summary\"><span class=\"gform-icon gform-icon--circle-error\"></span>There was a problem with your submission. Please review the fields below.</h2>";
pub const HONEYPOT_NOTE: &str = "This field is for validation purposes and should be left unchanged.";

/// The honeypot takes the id after the highest field id, as Gravity Forms numbers it.
pub fn honeypot_id(form: &Form) -> i64 {
    form.fields.iter().map(|f| f.id).max().unwrap_or(0) + 1
}

fn required_mark(form: &Form) -> &'static str {
    if form.asterisk {
        "<span class=\"gfield_required\"><span class=\"gfield_required gfield_required_asterisk\">*</span></span>"
    } else {
        "<span class=\"gfield_required\"><span class=\"gfield_required gfield_required_text\">(Required)</span></span>"
    }
}

fn input(r: &Render, f: &Field, name: &str, id: &str, kind: &str, class: &str, extra: &str) -> String {
    let value = r.values.get(name).map_or(String::new(), |v| esc(v));
    let required = if f.required { " aria-required=\"true\"" } else { "" };
    let invalid = r.errors.contains_key(&f.id);
    let placeholder = if f.placeholder.is_empty() { String::new() } else { format!(" placeholder='{}'", esc(&f.placeholder)) };
    format!("<input name='{name}' id='{id}' type='{kind}' value='{value}' class='{class}'{placeholder}{extra}{required} aria-invalid=\"{invalid}\" />")
}

fn control(r: &Render, f: &Field) -> String {
    let fid = r.form.id;
    let name = format!("input_{}", f.id);
    let id = format!("input_{fid}_{}", f.id);
    let size = esc(&f.size);
    match &f.kind {
        Kind::Text => format!("<div class='ginput_container ginput_container_text'>{}</div>", input(r, f, &name, &id, "text", &size, "")),
        Kind::Email => format!("<div class='ginput_container ginput_container_email'>\n{}\n</div>", input(r, f, &name, &id, "email", &size, "")),
        Kind::Phone(_) => format!("<div class='ginput_container ginput_container_phone'>{}</div>", input(r, f, &name, &id, "tel", &size, " autocomplete=\"tel\"")),
        Kind::Date => format!(
            "<div class='ginput_container ginput_container_date'>{}<span id='{id}_date_format' class='screen-reader-text'>MM slash DD slash YYYY</span></div>",
            input(r, f, &name, &id, "text", &format!("datepicker gform-datepicker mdy datepicker_no_icon gdatepicker-no-icon {size}"), &format!(" aria-describedby=\"{id}_date_format\""))
        ),
        Kind::Textarea => {
            let value = r.values.get(&name).map_or(String::new(), |v| esc(v));
            let required = if f.required { " aria-required=\"true\"" } else { "" };
            let invalid = r.errors.contains_key(&f.id);
            format!("<div class='ginput_container ginput_container_textarea'><textarea name='{name}' id='{id}' class='textarea {size}'{required} aria-invalid=\"{invalid}\" rows='10' cols='50'>{value}</textarea></div>")
        }
        Kind::Address(inputs) => {
            let mut out = format!("<div class='ginput_complex ginput_container ginput_container--address has_street has_city has_country ginput_container_address gform-grid-row' id='{id}' >");
            for sub in inputs.iter().filter(|s| !s.hidden) {
                let n = sub.id.rsplit('.').next().unwrap_or("");
                let sub_name = format!("input_{}", sub.id);
                let sub_id = format!("{id}_{n}");
                let value = r.values.get(&sub_name).map_or(String::new(), |v| esc(v));
                out.push_str(&format!(
                    "<span class='ginput_full address_{n} ginput_address_{n} gform-grid-col' id='{sub_id}_container' ><input type='text' name='{sub_name}' id='{sub_id}' value='{value}'    /><label for='{sub_id}' id='{sub_id}_label' class='gform-field-label gform-field-label--type-sub '>{}</label></span>",
                    esc(&sub.label)
                ));
            }
            out.push_str("<div class='gf_clear gf_clear_complex'></div></div>");
            out
        }
        Kind::Html(_) | Kind::Unsupported(_) => String::new(),
    }
}

fn field(r: &Render, f: &Field) -> String {
    let fid = r.form.id;
    let error = r.errors.get(&f.id);
    match &f.kind {
        Kind::Unsupported(_) => return String::new(),
        Kind::Html(content) => {
            return format!(
                "<div id=\"field_{fid}_{}\" class=\"gfield gfield--type-html gfield--width-full gfield_html gfield_html_formatted gfield_no_follows_desc field_sublabel_below gfield--no-description field_description_below field_validation_below gfield_visibility_visible\" >{content}</div>",
                f.id
            );
        }
        _ => {}
    }
    let mut class = format!("gfield gfield--type-{} gfield--input-type-{}", f.type_name(), f.type_name());
    if !f.css_class.is_empty() {
        class.push(' ');
        class.push_str(&esc(&f.css_class));
    }
    class.push_str(" gfield--width-full");
    if f.required {
        class.push_str(" gfield_contains_required");
    }
    class.push_str(" field_sublabel_below gfield--no-description field_description_below field_validation_below gfield_visibility_visible");
    if error.is_some() {
        class.push_str(" gfield_error");
    }
    let mark = if f.required { required_mark(r.form) } else { "" };
    let label = match f.kind {
        Kind::Address(_) => format!("<legend class='gfield_label gform-field-label gfield_label_before_complex' >{}{mark}</legend>", esc(&f.label)),
        _ => format!("<label class='gfield_label gform-field-label' for='input_{fid}_{}'>{}{mark}</label>", f.id, esc(&f.label)),
    };
    let message = error.map_or(String::new(), |m| format!("<div id='validation_message_{fid}_{}' class='gfield_description validation_message gfield_validation_message'>{}</div>", f.id, esc(m)));
    let tag = if matches!(f.kind, Kind::Address(_)) { "fieldset" } else { "div" };
    format!("<{tag} id=\"field_{fid}_{}\" class=\"{class}\" >{label}{}{message}</{tag}>", f.id, control(r, f))
}

fn honeypot(r: &Render) -> String {
    let fid = r.form.id;
    let hid = honeypot_id(r.form);
    format!(
        "<div id=\"field_{fid}_{hid}\" class=\"gfield gfield--type-honeypot gform_validation_container field_sublabel_below gfield--has-description field_description_below field_validation_below gfield_visibility_visible\" ><label class='gfield_label gform-field-label' for='input_{fid}_{hid}'>Comments</label><div class='ginput_container'><input name='input_{hid}' id='input_{fid}_{hid}' type='text' value='' autocomplete='new-password'/></div><div class='gfield_description' id='gfield_description_{fid}_{hid}'>{HONEYPOT_NOTE}</div></div>"
    )
}

/// The form, empty or with what was posted and its errors.
pub fn form(r: &Render) -> String {
    let f = r.form;
    let fid = f.id;
    let mut out = format!("<div class='gf_browser_unknown gform_wrapper gravity-theme gform-theme--no-framework' data-form-theme='gravity-theme' data-form-index='0' id='gform_wrapper_{fid}' >");
    if r.ajax {
        out.push_str(&format!("<div id='gf_{fid}' class='gform_anchor' tabindex='-1'></div>"));
    }
    if let Some(summary) = r.summary {
        out.push_str(&format!("<div class=\"gform_validation_errors\" id=\"gform_{fid}_validation_container\" data-js=\"gform-focus-validation-error\">{summary}</div>"));
    }
    let target = if r.ajax { format!(" target='gform_ajax_frame_{fid}'") } else { String::new() };
    let css = if f.css_class.is_empty() { String::new() } else { format!(" class='{}'", esc(&f.css_class)) };
    out.push_str(&format!("<form method='post' enctype='multipart/form-data'{target} id='gform_{fid}'{css} action='{}' data-formid='{fid}' novalidate>", esc(r.page_uri)));
    if r.recaptcha {
        out.push_str("<input type='hidden' class='registry_recaptcha_response' name='registry_recaptcha_response' value=''/>");
    }
    out.push_str(&format!("\n<div class='gform-body gform_body'><div id='gform_fields_{fid}' class='gform_fields top_label form_sublabel_below description_below validation_below'>"));
    for each in &f.fields {
        out.push_str(&field(r, each));
    }
    if f.honeypot != Honeypot::Off {
        out.push_str(&honeypot(r));
    }
    out.push_str("</div></div>\n");
    out.push_str(&format!(
        "<div class='gform-footer gform_footer top_label'> <input type='submit' id='gform_submit_button_{fid}' class='gform_button button' onclick='gform.submission.handleButtonClick(this);' data-submission-type='submit' value='{}'  /> ",
        esc(&f.button)
    ));
    if r.ajax {
        out.push_str(&format!("<input type='hidden' name='gform_ajax' value='form_id={fid}&amp;title=&amp;description=&amp;tabindex=0&amp;theme=gravity-theme' />"));
    }
    let token = r.token.map(esc).unwrap_or_default();
    out.push_str(&format!(
        "\n<input type='hidden' class='gform_hidden' name='gform_submission_method' data-js='gform_submission_method_{fid}' value='{}' />\n<input type='hidden' class='gform_hidden' name='gform_theme' data-js='gform_theme_{fid}' id='gform_theme_{fid}' value='gravity-theme' />\n<input type='hidden' class='gform_hidden' name='is_submit_{fid}' value='1' />\n<input type='hidden' class='gform_hidden' name='gform_submit' value='{fid}' />\n<input type='hidden' class='gform_hidden' name='gform_unique_id' value='' />\n<input type='hidden' class='gform_hidden' name='state_{fid}' value='{token}' />\n<input type='hidden' autocomplete='off' class='gform_hidden' name='gform_target_page_number_{fid}' id='gform_target_page_number_{fid}' value='0' />\n<input type='hidden' autocomplete='off' class='gform_hidden' name='gform_source_page_number_{fid}' id='gform_source_page_number_{fid}' value='1' />\n<input type='hidden' name='gform_field_values' value='' />\n</div>\n</form>\n</div>",
        if r.ajax { "iframe" } else { "postback" }
    ));
    if r.ajax {
        out.push_str(&format!("<iframe style='display:none;width:0px;height:0px;' src='about:blank' name='gform_ajax_frame_{fid}' id='gform_ajax_frame_{fid}' title='This iframe contains the logic required to handle Ajax powered Gravity Forms.'></iframe>"));
    }
    if let Some(t) = r.token {
        out.push_str(&format!(
            "<script>(function(){{var f=document.getElementById('gform_{fid}');if(!f)return;f.addEventListener('submit',function(){{if(f.querySelector('input[name=gf_zero_spam_token]'))return;var i=document.createElement('input');i.type='hidden';i.name='gf_zero_spam_token';i.value='{}';f.appendChild(i);}});}})();</script>",
            esc(t)
        ));
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
            let digits: Vec<char> = value.chars().filter(char::is_ascii_digit).collect();
            let others = value.chars().filter(|c| !c.is_ascii_digit()).count();
            digits.len() == 10 && others <= 4 && value.chars().next().is_some_and(|c| c.is_ascii_digit() || c == '(' || c == '+' || c == ' ')
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
        let html = form(&Render { form: &f, page_uri: "/contact-us/?a=1&b=2", ajax: false, values: &values, errors: &errors, summary: Some(SUMMARY), token: Some("1.ab"), recaptcha: true });
        for part in [
            "id='gform_wrapper_3'",
            "action='/contact-us/?a=1&amp;b=2'",
            "name='input_1' id='input_3_1' type='text' value='Pat &lt;b&gt;'",
            "class=\"gfield gfield--type-email gfield--input-type-email gfield--width-full gfield_contains_required field_sublabel_below gfield--no-description field_description_below field_validation_below gfield_visibility_visible gfield_error\"",
            "<div id='validation_message_3_10' class='gfield_description validation_message gfield_validation_message'>This field is required.</div>",
            "name='input_21' id='input_3_21' type='text' value='' autocomplete='new-password'",
            "name='registry_recaptcha_response'",
            "<input type='hidden' class='gform_hidden' name='gform_submit' value='3' />",
            "i.name='gf_zero_spam_token';i.value='1.ab'",
            "This site is protected by reCAPTCHA",
        ] {
            assert!(html.contains(part), "{part}\n{html}");
        }
        assert!(!html.contains("Odd"), "unsupported fields render nothing");
    }

    #[test]
    fn standard_phone_numbers_need_ten_digits() {
        assert!(phone_ok(PhoneFormat::Standard, "(555) 010-0100"));
        assert!(phone_ok(PhoneFormat::Standard, "555-010-0100"));
        assert!(!phone_ok(PhoneFormat::Standard, "12345"));
        assert!(phone_ok(PhoneFormat::International, "+44 20 7946 0000"));
    }
}
