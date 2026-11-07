//! Gravity Forms: the form definitions read from JSON, the markup drawn from them with a visitor's values, the
//! validation of a post, and the page surgery around an answer.

use std::collections::HashMap;

use hxweb::orders::forms::{
    model::{self, Form, PhoneFormat},
    render::{self, Embed, Render, Summary},
    submit,
};
use hxweb_fuzz::{config, gen, within, PURE};
use proptest::prelude::*;
use serde_json::{json, Value};

const TYPES: &[&str] = &["text", "email", "phone", "textarea", "date", "html", "address", "name", "select", ""];

fn json_value() -> impl Strategy<Value = Value> {
    let leaf = prop_oneof![
        Just(Value::Null),
        any::<bool>().prop_map(Value::Bool),
        any::<i64>().prop_map(|n| json!(n)),
        any::<f64>().prop_filter("finite", |f| f.is_finite()).prop_map(|f| json!(f)),
        gen::text(16).prop_map(Value::String),
    ];
    leaf.prop_recursive(4, 40, 5, |inner| {
        prop_oneof![
            proptest::collection::vec(inner.clone(), 0..5).prop_map(Value::Array),
            proptest::collection::vec((prop_oneof![gen::text(8), "[a-zA-Z]{1,12}".boxed()], inner), 0..5).prop_map(|kv| Value::Object(kv.into_iter().collect())),
        ]
    })
}

/// A field as the form editor stores it, every property present or not, with any value.
fn field() -> impl Strategy<Value = Value> {
    (
        proptest::sample::select(TYPES),
        prop_oneof![(-3i64..40).prop_map(|n| json!(n)), (0i64..40).prop_map(|n| json!(n.to_string())), any::<i64>().prop_map(|n| json!(n))],
        gen::text(24),
        any::<bool>(),
        proptest::option::of(json_value()),
        proptest::option::of(gen::text(12)),
    )
        .prop_map(|(kind, id, label, required, extra, placeholder)| {
            let mut f = json!({ "type": kind, "id": id, "label": label, "isRequired": required, "size": "large",
                "phoneFormat": if required { "standard" } else { "international" }, "dateFormat": if required { "dmy" } else { "mdy" },
                "inputs": [{"id": "1.1", "label": "Street"}, {"id": "1.3", "label": "City", "isHidden": required}], "content": "<p>Fixed text</p>" });
            if let Some(e) = extra {
                f["cssClass"] = e;
            }
            if let Some(p) = placeholder {
                f["placeholder"] = json!(p);
            }
            f
        })
}

fn form_json() -> impl Strategy<Value = (String, String, String)> {
    (proptest::collection::vec(field(), 0..6), gen::text(20), any::<bool>(), any::<bool>(), json_value()).prop_map(|(fields, title, honeypot, theme, extra)| {
        let meta = json!({ "id": 3, "title": title, "button": {"text": "Send"}, "enableHoneypot": honeypot, "honeypotAction": "spam",
            "markupVersion": if theme { 2 } else { 1 }, "fields": fields, "cssClass": extra });
        let notifications = json!({ "a": { "isActive": true, "event": "form_submission", "toType": "field", "to": "10", "subject": "{Name:1}", "message": "{all_fields}" } });
        let confirmations = json!({ "c": { "isDefault": true, "type": "message", "message": "Thanks" } });
        (meta.to_string(), notifications.to_string(), confirmations.to_string())
    })
}

fn edited(s: &str, at: usize, with: char, cut: bool) -> String {
    let mut chars: Vec<char> = s.chars().collect();
    if chars.is_empty() {
        return String::new();
    }
    let at = at % chars.len();
    if cut {
        chars.truncate(at);
    } else {
        chars[at] = with;
    }
    chars.into_iter().collect()
}

/// A visitor's post: values for the fields' inputs and some that name nothing.
fn values() -> impl Strategy<Value = HashMap<String, String>> {
    proptest::collection::hash_map(
        prop_oneof![(0i64..40).prop_map(|n| format!("input_{n}")), (0i64..40, 1..9u8).prop_map(|(n, s)| format!("input_{n}_{s}")), gen::key()],
        gen::text(40).prop_map(|v| format!("Zq{v}qZ")),
        0..12,
    )
}

fn draw(form: &Form, values: &HashMap<String, String>, errors: &HashMap<i64, String>, ajax: bool, summary: bool) -> String {
    let embed = Embed { ajax, ..Embed::default_for(form.id) };
    let r = Render {
        form,
        page_uri: "/contact/?lang=fr&a=1",
        embed: &embed,
        values,
        errors,
        summary: summary.then_some(Summary::Problem),
        token: Some("t0k3n"),
        recaptcha: summary,
        browser: "gf_browser_chrome",
    };
    within(PURE, "render::form", || render::form(&r))
}

proptest! {
    #![proptest_config(config(4096))]

    #[test]
    fn form_definitions_parse_or_are_refused(meta in prop_oneof![gen::text(200), json_value().prop_map(|v| v.to_string())], n in gen::text(40), c in json_value()) {
        if let Some(form) = within(PURE, "model::parse", || model::parse(&meta, &n, &c.to_string())) {
            draw(&form, &HashMap::new(), &HashMap::new(), false, false);
        }
    }

    #[test]
    fn edited_definitions_parse_or_are_refused((meta, n, c) in form_json(), at in any::<usize>(), with in any::<char>(), cut in any::<bool>()) {
        let meta = edited(&meta, at, with, cut);
        if let Some(form) = model::parse(&meta, &n, &c) {
            draw(&form, &HashMap::new(), &HashMap::new(), cut, false);
        }
    }

    /// Whatever a visitor posts comes back in the form escaped: no value that holds markup appears as written.
    #[test]
    fn a_visitors_values_come_back_escaped((meta, n, c) in form_json(), values in values(), french in any::<bool>(), ajax in any::<bool>(), summary in any::<bool>()) {
        let form = model::parse(&meta, &n, &c).expect("a generated form parses");
        let form = model::localize(form, if french { model::Lang::Fr } else { model::Lang::En });
        let errors = within(PURE, "validate", || submit::validate(&form, &values));
        let html = draw(&form, &values, &errors, ajax, summary);
        for v in values.values().filter(|v| v.contains(['<', '>', '"', '\''])) {
            prop_assert!(!html.contains(v.as_str()), "{:?} drawn raw", v);
        }
    }

    #[test]
    fn small_helpers_are_total(ua in gen::text(120), phone in gen::text(30)) {
        within(PURE, "browser_class", || render::browser_class(&ua));
        render::phone_ok(PhoneFormat::Standard, &phone);
        render::phone_ok(PhoneFormat::International, &phone);
    }

    /// The page around a form's answer: script elements dropped and Akismet's fields renumbered, whatever the page
    /// holds.
    #[test]
    fn answer_pages_are_total(
        pieces in proptest::collection::vec(prop_oneof![
            Just("<script>".to_string()), Just("</script>".to_string()), Just("<script type=\"text/javascript\">if(formId == 3){}".to_string()),
            Just("var gfZeroSpamConfig = {\"forms\":[{\"formId\":3,\"x\":1},{\"formId\":4}]};".to_string()), Just("\"forms\":[{".to_string()), Just("}]".to_string()),
            Just("ak_js_".to_string()), Just("id=\"gform_gravityforms-js\"></script>".to_string()), Just("id=\"gf-zero-spam-js\"".to_string()),
            "[0-9]{1,3}".boxed(), gen::text(10).boxed(),
        ], 0..30),
        form in 0u32..6, position in 0usize..6, confirmed in any::<bool>(), stored in any::<bool>(),
    ) {
        let page: String = pieces.concat();
        let answer = render::Answer { form, html: String::new(), confirmed, stored };
        within(PURE, "Answer::page", || answer.page(&page, position));
        let f = model::parse(r#"{"id":3,"fields":[]}"#, "", "").unwrap();
        render::confirmation(&f, &page, stored);
        render::ajax_postback(&page, confirmed);
        render::texturize(&page);
    }
}
