//! Form markup as includes/forms_rev2.php wrote it, so the pages keep the attributes, order and quoting the site's
//! CSS and scripts were written against. Values are escaped here; only event handlers are raw, and they can only
//! be `'static` text, so nothing a visitor sent can reach one.

/// PHP htmlspecialchars($s, ENT_QUOTES).
pub fn escape(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for c in s.chars() {
        match c {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            '"' => out.push_str("&quot;"),
            '\'' => out.push_str("&#039;"),
            c => out.push(c),
        }
    }
    out
}

enum Val {
    Text(String),
    Event(&'static str),
    On,
}

/// The `$options` array of forms_rev2.php: attributes in the order they were added. `options()` groups them as
/// the PHP did (name-like, value-like, events, booleans, data) and drops empty ones.
#[derive(Default)]
pub struct Opts(Vec<(&'static str, Val)>);

const NAME: [&str; 4] = ["name", "id", "class", "style"];
const EVENT: [&str; 9] = ["onfocus", "onfocusout", "onchange", "onclick", "onmouseover", "onmouseout", "onload", "onblur", "onsubmit"];

impl Opts {
    pub fn new() -> Opts {
        Opts(Vec::new())
    }

    pub fn attr(mut self, key: &'static str, value: impl Into<String>) -> Opts {
        self.set(key, Val::Text(value.into()));
        self
    }

    pub fn event(mut self, key: &'static str, js: &'static str) -> Opts {
        self.set(key, Val::Event(js));
        self
    }

    pub fn flag(mut self, key: &'static str, on: bool) -> Opts {
        self.set(key, if on { Val::On } else { Val::Text(String::new()) });
        self
    }

    fn has(&self, key: &str) -> bool {
        self.0.iter().any(|(k, _)| *k == key)
    }

    /// Like a PHP array assignment: an existing key keeps its place.
    fn set(&mut self, key: &'static str, v: Val) {
        match self.0.iter_mut().find(|(k, _)| *k == key) {
            Some(slot) => slot.1 = v,
            None => self.0.push((key, v)),
        }
    }

    fn or(mut self, key: &'static str, value: &str) -> Opts {
        if !self.has(key) {
            self.set(key, Val::Text(value.into()));
        }
        self
    }

    /// forms_rev2.php options($field, $options).
    fn render(mut self, field: &str) -> String {
        if !field.is_empty() {
            self.set("name", Val::Text(field.into()));
            if !self.has("id") {
                self.set("id", Val::Text(field.into()));
            }
        }
        let [mut name, mut value, mut event, mut boolean, mut html5, mut data] = Default::default();
        for (key, v) in self.0 {
            let bucket: &mut String = match (&v, key) {
                (Val::Text(t), _) if t.is_empty() => continue,
                (Val::Text(_), k) if k.starts_with("data-") => &mut data,
                (Val::Text(_), "placeholder") => &mut html5,
                (Val::Text(_), k) if NAME.contains(&k) => &mut name,
                (Val::Text(_), _) => &mut value,
                (Val::Event(_), k) if EVENT.contains(&k) => &mut event,
                (Val::Event(_), _) => continue,
                (Val::On, _) => &mut boolean,
            };
            match v {
                Val::Text(t) => bucket.push_str(&format!(" {key}='{}'", escape(&t))),
                Val::Event(js) => bucket.push_str(&format!(" {key}=\"javascript:{js}\"")),
                Val::On => bucket.push_str(&format!(" {key}")),
            }
        }
        [name, value, event, boolean, html5, data].concat()
    }
}

pub fn hidden(field: &str, value: &str, class: &str) -> String {
    let o = Opts::new().attr("name", field).attr("value", value).attr("class", class);
    format!("<input type=hidden {}>\n", o.render(field).trim())
}

pub fn text(field: &str, value: &str, size: u32, maxlength: Option<u32>, opts: Opts) -> String {
    input("text", field, value, size, maxlength, opts)
}

/// forms_rev2.php text() with its `$type` (text, email, password and the other HTML5 input types).
pub fn input(kind: &'static str, field: &str, value: &str, size: u32, maxlength: Option<u32>, opts: Opts) -> String {
    let mut o = opts.attr("size", size.to_string());
    if let Some(m) = maxlength.filter(|m| *m > 0) {
        o = o.attr("maxlength", m.to_string());
    }
    let o = o.attr("value", value);
    format!("<input type={kind} {}>\n", o.render(field).trim())
}

/// forms_rev2.php font(). Both parts are markup the caller built.
pub fn font(color: &str, inner: &str, after: &str) -> String {
    format!("<font color={color}>{inner}</font>{after}")
}

pub fn button(label: &str, opts: Opts) -> String {
    let o = opts.or("class", "nav_button").attr("value", label);
    format!("<input type=button {}>\n", o.render("").trim())
}

pub fn submit(label: &str, opts: Opts) -> String {
    let o = opts.or("class", "nav_button").attr("value", label);
    format!("<input type=submit {}>\n", o.render("").trim())
}

pub fn checkbox(field: &str, value: &str, checked: bool, opts: Opts) -> String {
    let o = if opts.has("checked") { opts } else { opts.flag("checked", checked) };
    let o = o.attr("value", value);
    format!("<input type=checkbox {}>", o.render(field).trim())
}

pub enum Blank<'a> {
    None,
    Empty,
    Label(&'a str),
}

/// forms_rev2.php select(): one `<option>` per (value, label), the matching value selected. Labels are escaped
/// here, which the PHP left to its callers.
pub fn select(field: &str, items: &[(String, String)], selected: &str, blank: Blank<'_>, opts: Opts) -> String {
    let mut lines = vec![format!("<select{}>", opts.render(field))];
    match blank {
        Blank::None => {}
        Blank::Empty => lines.push("<option></option>".into()),
        Blank::Label(l) => lines.push(format!("<option value=''>{}</option>", escape(l))),
    }
    for (value, label) in items {
        let sel = if value == selected { " selected" } else { "" };
        lines.push(format!("<option value='{}'{sel}>{}</option>", escape(value), escape(label)));
    }
    lines.push("</select>".into());
    lines.push(String::new());
    lines.join("\n")
}

/// forms_rev2.php open(): the page's one form, named scs_form, posting to `action`.
pub fn form_open(action: &str, opts: Opts) -> String {
    format!("<form {} method=post action='{}' accept-charset='utf-8' autocomplete=on>\n", opts.render("scs_form").trim(), escape(action))
}

pub const FORM_CLOSE: &str = "</form>";

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn escape_matches_htmlspecialchars_with_quotes() {
        assert_eq!(escape(r#"a&b<c>"d"'e'"#), "a&amp;b&lt;c&gt;&quot;d&quot;&#039;e&#039;");
        assert_eq!(escape("café"), "café");
    }

    #[test]
    fn attributes_come_out_grouped_as_the_php_wrote_them() {
        assert_eq!(hidden("action", "login", ""), "<input type=hidden name='action' id='action' value='login'>\n");
        assert_eq!(hidden("success_url", "", "scscpq_input"), "<input type=hidden name='success_url' class='scscpq_input' id='success_url'>\n");
        assert_eq!(
            text("unlock_code", "", 10, Some(10), Opts::new().attr("class", "scscpq_input")),
            "<input type=text class='scscpq_input' name='unlock_code' id='unlock_code' size='10' maxlength='10'>\n"
        );
        assert_eq!(
            button("Go", Opts::new().attr("class", "red-button").event("onclick", "go();")),
            "<input type=button class='red-button' value='Go' onclick=\"javascript:go();\">\n"
        );
        assert_eq!(submit("Go!", Opts::new()), "<input type=submit class='nav_button' value='Go!'>\n");
        assert_eq!(
            input("email", "profile_email", "a@b.co", 40, Some(60), Opts::new().attr("class", "scscpq_input").attr("placeholder", "Email")),
            "<input type=email class='scscpq_input' name='profile_email' id='profile_email' size='40' maxlength='60' value='a@b.co' placeholder='Email'>\n"
        );
        assert_eq!(
            button("Submit", Opts::new().attr("class", "g-recaptcha red-button").attr("id", "recaptcha_button").attr("data-sitekey", "K").attr("data-callback", "cb")),
            "<input type=button class='g-recaptcha red-button' id='recaptcha_button' value='Submit' data-sitekey='K' data-callback='cb'>\n"
        );
        assert_eq!(checkbox("visitor", "1", true, Opts::new()), "<input type=checkbox name='visitor' id='visitor' value='1' checked>");
        assert_eq!(checkbox("visitor", "1", false, Opts::new()), "<input type=checkbox name='visitor' id='visitor' value='1'>");
        assert_eq!(
            form_open("/newuser.php", Opts::new().event("onsubmit", "return false;")),
            "<form name='scs_form' id='scs_form' onsubmit=\"javascript:return false;\" method=post action='/newuser.php' accept-charset='utf-8' autocomplete=on>\n"
        );
    }

    #[test]
    fn visitor_text_cannot_break_out_of_an_attribute() {
        let out = text("email", "x' onmouseover='alert(1)", 50, None, Opts::new());
        assert!(out.contains("value='x&#039; onmouseover=&#039;alert(1)'"), "{out}");
    }

    #[test]
    fn select_marks_the_choice() {
        let items = vec![("EN".to_string(), "English".to_string()), ("FR".to_string(), "Français".to_string())];
        assert_eq!(
            select("lang", &items, "FR", Blank::None, Opts::new()),
            "<select name='lang' id='lang'>\n<option value='EN'>English</option>\n<option value='FR' selected>Français</option>\n</select>\n"
        );
    }
}
