//! The markup of includes/forms_rev2.php, jquery_rev1.php and menu_group_class, as plain functions over strings, so
//! the staff pages keep the ids, classes and attribute order the site's CSS and scripts expect.
//!
//! Escaping is the one deliberate difference. Every value that comes from the database or the request goes through
//! `esc` (element text and attribute values) or `js` (a string literal inside an event attribute). The PHP printed
//! most of them raw. Attribute values given in `Opts` are markup the page wrote, built from literals and `js`.

use base64::{engine::general_purpose::STANDARD, Engine};

pub fn esc(s: &str) -> String {
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

/// A single-quoted JavaScript string literal that is also inert inside an HTML attribute or a <script> block.
/// Plain text comes out as PHP's fn_escape() wrote it: `'5'`, `'Acme'`.
pub fn js(s: &str) -> String {
    let mut out = String::with_capacity(s.len() + 2);
    out.push('\'');
    for c in s.chars() {
        match c {
            '\\' => out.push_str("\\\\"),
            '\'' | '"' | '<' | '>' | '&' | '`' => out.push_str(&format!("\\x{:02x}", c as u32)),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\u{2028}' => out.push_str("\\u2028"),
            '\u{2029}' => out.push_str("\\u2029"),
            c if (c as u32) < 0x20 => out.push_str(&format!("\\x{:02x}", c as u32)),
            c => out.push(c),
        }
    }
    out.push('\'');
    out
}

/// `fn_url_query`: `?k=v&...` with PHP's urlencode, skipping empty values.
pub fn url_query(pairs: &[(&str, &str)]) -> String {
    let parts: Vec<String> = pairs.iter().filter(|(_, v)| !v.is_empty()).map(|(k, v)| format!("{k}={}", urlencode(v))).collect();
    if parts.is_empty() {
        String::new()
    } else {
        format!("?{}", parts.join("&"))
    }
}

pub fn urlencode(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for b in s.bytes() {
        match b {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' => out.push(b as char),
            b' ' => out.push('+'),
            b => out.push_str(&format!("%{b:02X}")),
        }
    }
    out
}

/// Options passed to a widget, in the order the caller gives them, as in the PHP `$options` arrays.
#[derive(Default, Clone)]
pub struct Opts(Vec<(&'static str, String)>);

impl Opts {
    pub fn new() -> Self {
        Opts(Vec::new())
    }

    pub fn set(mut self, key: &'static str, value: impl Into<String>) -> Self {
        let value = value.into();
        match self.0.iter_mut().find(|(k, _)| *k == key) {
            Some(slot) => slot.1 = value,
            None => self.0.push((key, value)),
        }
        self
    }

    pub fn class(self, v: impl Into<String>) -> Self {
        self.set("class", v)
    }

    pub fn style(self, v: impl Into<String>) -> Self {
        self.set("style", v)
    }

    pub fn onclick(self, v: impl Into<String>) -> Self {
        self.set("onclick", v)
    }

    pub fn onchange(self, v: impl Into<String>) -> Self {
        self.set("onchange", v)
    }

    fn has(&self, key: &str) -> bool {
        self.0.iter().any(|(k, _)| *k == key)
    }

    fn push_default(&mut self, key: &'static str, value: impl Into<String>) {
        if !self.has(key) {
            self.0.push((key, value.into()));
        }
    }
}

const ERROR_STYLE: &str = "style='border: 1px solid red !important;'";

#[derive(Clone, Copy, PartialEq, Eq, PartialOrd, Ord)]
enum Group {
    Name,
    Src,
    Value,
    Event,
    Boolean,
    Html5,
    Manual,
    Data,
}

fn group(key: &str) -> Group {
    match key {
        "name" | "id" | "class" | "style" => Group::Name,
        "src" => Group::Src,
        "onfocus" | "onfocusout" | "onchange" | "onclick" | "onmouseover" | "onmouseout" | "onload" | "onblur" | "onsubmit" => Group::Event,
        "checked" | "selected" | "disabled" | "readonly" => Group::Boolean,
        "placeholder" => Group::Html5,
        "manual" => Group::Manual,
        "data" => Group::Data,
        _ => Group::Value,
    }
}

/// forms_class::options(): name and id default to the field, empty values are dropped, and the attributes come out
/// grouped (name, src, value, event, boolean, html5, manual, data) with the error style last.
fn attrs(field: &str, mut opts: Opts, error: bool) -> String {
    if !field.is_empty() {
        opts = opts.set("name", field);
        opts.push_default("id", field);
    }
    let mut out = String::new();
    for g in [Group::Name, Group::Src, Group::Value, Group::Event, Group::Boolean, Group::Html5, Group::Manual, Group::Data] {
        for (key, value) in opts.0.iter().filter(|(k, v)| group(k) == g && !v.is_empty()) {
            match g {
                Group::Event => out.push_str(&format!(" {key}=\"javascript:{value}\"")),
                Group::Boolean => out.push_str(&format!(" {key}")),
                Group::Manual | Group::Data => out.push_str(&format!(" {value}")),
                _ => out.push_str(&format!(" {key}='{value}'")),
            }
        }
    }
    if error {
        out.push(' ');
        out.push_str(ERROR_STYLE);
    }
    out
}

/// What a page tells the user, and which fields are wrong: `$forms->message`, `->error` and `->error_text`.
/// The first message is the page title, as forms_class::title() made it.
pub struct Notes {
    messages: Vec<String>,
    errors: Vec<(String, Option<String>)>,
}

impl Notes {
    pub fn new(title: &str, version: &str) -> Self {
        let first = if version.is_empty() { esc(title) } else { format!("{} ({})", esc(title), esc(version)) };
        Notes { messages: vec![first], errors: Vec::new() }
    }

    pub fn say(&mut self, text: &str) {
        self.messages.push(esc(text));
    }

    /// A message the page builds from markup and escaped values (`Document deleted: <b>…</b>`).
    pub fn say_html(&mut self, html: String) {
        self.messages.push(html);
    }

    pub fn error(&mut self, field: &str, text: Option<&str>) {
        self.mark(field, text.map(esc));
    }

    /// An error whose text the page built from markup and escaped values (lines joined with `<br>`).
    pub fn error_html(&mut self, field: &str, html: String) {
        self.mark(field, Some(html));
    }

    fn mark(&mut self, field: &str, text: Option<String>) {
        match self.errors.iter_mut().find(|(f, _)| f == field) {
            Some(slot) => {
                if text.is_some() {
                    slot.1 = text;
                }
            }
            None => self.errors.push((field.to_string(), text)),
        }
    }

    pub fn has_errors(&self) -> bool {
        !self.errors.is_empty()
    }

    pub fn is_error(&self, field: &str) -> bool {
        self.errors.iter().any(|(f, _)| f == field)
    }

    pub fn error_text(&self, field: &str) -> Option<&str> {
        self.errors.iter().find(|(f, _)| f == field).and_then(|(_, t)| t.as_deref())
    }

    /// `forms_class::message()`.
    pub fn render(&self) -> String {
        let mut messages = self.messages.clone();
        if !self.errors.is_empty() {
            messages.push(format!("Correct {} error(s)", self.errors.len()));
        }
        let mut out = String::from("<p class='message left'>");
        out.push_str(&messages[0]);
        if messages.len() > 1 {
            out.push_str("<span class=message2>");
            for m in &messages[1..] {
                out.push_str("<br>");
                out.push_str(m);
            }
            out.push_str("</span>");
        }
        out.push_str("</p>");
        out
    }

    /// A field's widget followed by its error text, as the pages print `implode("<br>", $text)`.
    pub fn with_error(&self, field: &str, widget: String) -> String {
        match self.error_text(field) {
            Some(t) => format!("{widget}<br>{t}"),
            None => widget,
        }
    }
}

/// The widgets of forms_class that mark a field in error.
pub struct Form<'a> {
    pub notes: &'a Notes,
}

impl Form<'_> {
    pub fn text(&self, field: &str, value: &str, size: u32, maxlength: u32, opts: Opts) -> String {
        self.input("text", field, value, size, maxlength, opts)
    }

    /// `text()` with a `date:ymd` type: the jQuery datepicker class.
    pub fn date(&self, field: &str, value: &str, opts: Opts) -> String {
        let mut opts = opts;
        let class = match opts.0.iter().find(|(k, _)| *k == "class") {
            Some((_, c)) => format!("{c} datepicker"),
            None => "datepicker".to_string(),
        };
        opts = opts.set("class", class);
        self.input("text", field, value, 10, 10, opts)
    }

    /// `text()` with an HTML input type the PHP passes through (password, email, number, url, tel, month).
    pub fn input(&self, kind: &str, field: &str, value: &str, size: u32, maxlength: u32, opts: Opts) -> String {
        let mut opts = opts.set("size", size.to_string());
        if maxlength > 0 {
            opts = opts.set("maxlength", maxlength.to_string());
        }
        let opts = opts.set("value", esc(value));
        format!("<input type={kind} {}>\n", attrs(field, opts, self.notes.is_error(field)).trim())
    }

    pub fn textarea(&self, field: &str, value: &str, rows: u32, cols: u32, opts: Opts) -> String {
        let cols = if cols == 0 { 60 } else { cols };
        let min_rows = value.len() as f64 / cols as f64;
        let rows = match () {
            _ if rows == 0 => php_float(min_rows + 3.0),
            _ if min_rows < rows as f64 => rows.to_string(),
            _ if min_rows + 3.0 > 30.0 => "30".to_string(),
            _ => php_float(min_rows + 3.0),
        };
        let opts = opts.set("cols", cols.to_string()).set("rows", rows);
        format!("<textarea{}>{}</textarea>\n", attrs(field, opts, self.notes.is_error(field)), esc_html401(value))
    }

    pub fn checkbox(&self, field: &str, value: &str, checked: bool, opts: Opts) -> String {
        let mut opts = opts;
        opts.push_default("checked", if checked { " checked" } else { "" });
        let opts = opts.set("value", esc(value));
        let html = format!("<input type=checkbox {}>", attrs(field, opts, false).trim());
        if self.notes.is_error(field) {
            format!("<span style='background-color: #ff0000'>{html}</span>\n")
        } else {
            html
        }
    }

    /// `checkbox_delete()`: the Del? box of a list row.
    pub fn checkbox_delete(&self, id: i64, name: &str, script: &str) -> String {
        let opts = Opts::new().onclick(format!("{script}('delete',{},{});", js(&id.to_string()), js(name)));
        self.checkbox(&format!("delete{id}"), "1", false, opts)
    }

    pub fn radio(&self, field: &str, value: &str, checked: bool, opts: Opts) -> String {
        let mut opts = opts;
        opts.push_default("id", format!("{field}_{value}"));
        let mut a = attrs(field, opts, self.notes.is_error(field)).trim().to_string();
        if checked {
            a.push_str(" checked");
        }
        format!("<input type=radio value='{}' {a}>\n", esc(value))
    }

    /// `select()` with a blank first option. Option labels are markup (`&nbsp;` indents); escape them with `esc`.
    pub fn select(&self, field: &str, options: &[(String, String)], selected: &str, blank: Blank, opts: Opts) -> String {
        let mut lines = vec![format!("<select{}>", attrs(field, opts, self.notes.is_error(field)))];
        match blank {
            Blank::Empty => lines.push("<option></option>".into()),
            Blank::Label(l) => lines.push(format!("<option value=''>{}</option>", esc(l))),
            Blank::None => {}
        }
        for (value, label) in options {
            let sel = if value == selected { " selected" } else { "" };
            lines.push(format!("<option value='{}'{sel}>{label}</option>", esc(value)));
        }
        lines.push("</select>".into());
        lines.push(String::new());
        lines.join("\n")
    }

    pub fn file(&self, field: &str, size: u32, opts: Opts) -> String {
        let opts = opts.set("size", size.to_string());
        format!("<input type=file {}>\n", attrs(field, opts, self.notes.is_error(field)).trim())
    }
}

#[derive(Clone, Copy)]
pub enum Blank {
    Empty,
    Label(&'static str),
    None,
}

/// A select whose values are its labels (`select($field, $list, ...)` on a plain PHP list).
pub fn list_options<S: AsRef<str>>(items: &[S]) -> Vec<(String, String)> {
    items.iter().map(|s| (s.as_ref().to_string(), esc(s.as_ref()))).collect()
}

pub fn hidden(field: &str, value: &str) -> String {
    let opts = Opts::new().set("name", field).set("value", esc(value));
    format!("<input type=hidden {}>\n", attrs(field, opts, false).trim())
}

pub fn button(text: &str, opts: Opts) -> String {
    let mut opts = opts;
    opts.push_default("class", "nav_button");
    let opts = opts.set("value", esc(text));
    format!("<input type=button {}>\n", attrs("", opts, false).trim())
}

pub fn submit(text: &str, opts: Opts) -> String {
    let mut opts = opts;
    opts.push_default("class", "nav_button");
    let opts = opts.set("value", esc(text));
    format!("<input type=submit {}>\n", attrs("", opts, false).trim())
}

/// `fn_href()`. `name` is markup; `url` is a path or a `javascript:` call the page built.
pub fn href(name: &str, url: &str, opts: Opts) -> String {
    if url.is_empty() {
        return format!("<b>{name}</b>");
    }
    let name = if name == "&nbsp;" { "" } else { name };
    format!("<a href=\"{}\" {}>{name}</a>", url.replace('"', "&quot;"), attrs("", opts, false).trim())
}

/// `$forms->open()`: the page's one form, posted back to itself, carrying the session's CSRF token.
pub fn open(path: &str, csrf: &str, multipart: bool) -> String {
    let enctype = if multipart { " enctype='multipart/form-data'" } else { "" };
    format!(
        "<form name='scs_form' id='scs_form' method=post action='{}' accept-charset='utf-8'{enctype} autocomplete=on>\n{}",
        esc(path),
        hidden(super::csrf::FIELD, csrf)
    )
}

pub fn close() -> &'static str {
    "</form>"
}

/// PHP's string form of a float (precision 14, no trailing zeros), for the textarea row count it computes.
fn php_float(v: f64) -> String {
    if v.fract() == 0.0 {
        return format!("{}", v as i64);
    }
    let digits = 14 - (v.abs().log10().floor() as i32 + 1);
    let s = format!("{:.*}", digits.max(0) as usize, v);
    s.trim_end_matches('0').trim_end_matches('.').to_string()
}

/// htmlspecialchars(ENT_HTML401) as textarea() uses it: quotes are left alone only for single quotes.
fn esc_html401(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for c in s.chars() {
        match c {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            '"' => out.push_str("&quot;"),
            c => out.push(c),
        }
    }
    out
}

/// jquery_tab_class: tabs of content, one landing tab, rendered with its loader, hidden fields and script.
pub struct Tabs {
    name: &'static str,
    tabs: Vec<(String, String)>,
    landing_name: Option<String>,
    landing: usize,
    lineheight: &'static str,
}

impl Tabs {
    pub fn new() -> Self {
        Tabs { name: "scs_jquery", tabs: Vec::new(), landing_name: None, landing: 0, lineheight: "content" }
    }

    /// `landing_tab($name, TRUE)`: open on the tab with this title (`?tab=` on the dashboard).
    pub fn land_on(&mut self, title: &str) {
        if self.landing_name.is_none() {
            self.landing_name = Some(title.trim().to_string());
        }
    }

    /// `landing_tab()`: the next tab added is the one that opens.
    pub fn land_next(&mut self) {
        self.landing = self.tabs.len();
    }

    /// `item($name, $contents)`. Title and content are markup; an empty content adds no tab.
    pub fn item(&mut self, title: &str, content: String) {
        if content.is_empty() {
            return;
        }
        self.tabs.push((title.to_string(), content));
        let index = self.tabs.len() - 1;
        if self.landing == 0 && self.landing_name.as_deref() == Some(title.trim()) {
            self.landing = index;
        }
    }

    pub fn is_empty(&self) -> bool {
        self.tabs.is_empty()
    }

    pub fn render(&self) -> String {
        if self.tabs.is_empty() {
            return String::new();
        }
        let n = self.name;
        let header = format!("{n}_header");
        let content_id = |i: usize| format!("{header}_{}", i + 1);
        let mut out: Vec<String> = Vec::new();
        for i in 0..self.tabs.len() {
            out.push(hidden(&format!("tab_{}_no", content_id(i)), &i.to_string()));
        }
        out.push(format!("<div id='{n}_loader' class='{n}_loader'><span class=jquery_loader></span><span class=jquery_loader_text>Loading ...</span></div>"));
        out.push(format!("<div id='{n}_div' class='{n}_div' style='display:none;'>"));
        out.push(format!("<div id='{header}' class='{header}'>"));
        out.push(format!("<ul id='{n}_tabs' class='{n}_tabs'>"));
        let mut landing_key = 0;
        for (i, (title, _)) in self.tabs.iter().enumerate() {
            let id = content_id(i);
            out.push(hidden(&format!("tab_{id}_content"), &i.to_string()));
            if self.landing != 0 && i == self.landing {
                landing_key = i;
            }
            out.push(format!(
                "<li id='tab_{id}' class='{n}_tabs'><a href='#{id}' id='href_{id}' class='{n}_tabs'>{title}</a></li>"
            ));
        }
        out.push("</ul>".into());
        out.push(format!("</div> <!-- {header} -->"));
        for (i, (title, content)) in self.tabs.iter().enumerate() {
            let tab_field = format!("jquerytab_{}", STANDARD.encode(title.trim()));
            out.push(format!("{}<div id='{}' class='{n}_content'>\n{}\n</div>\n", hidden(&tab_field, title), content_id(i), content.trim()));
        }
        out.push("</div> <!--  -->".into());
        out.push(String::new());
        out.push("<style>".into());
        out.push(".jquery_loader { display: inline-block; border: 4px solid #f3f3f3; border-radius: 50%; border-top: 4px solid #0981be; width: 20px; height: 20px;  -webkit-animation: spin 2s linear infinite; animation: spin 2s linear infinite; }".into());
        out.push(".jquery_loader_text { display: inline-block; vertical-align: top; margin-left: 10px; margin-top: 5px; }".into());
        out.push("</style>".into());
        out.push("<script>".into());
        out.push("var scscpq_tabs;".into());
        out.push("jQuery(document).ready(function() {".into());
        out.push(format!("    var tabs=jQuery(\"#{n}_loader\").hide();"));
        out.push(format!("    var tabs=jQuery(\"#{n}_div\").show();"));
        out.push(format!("    var tabs=jQuery(\"#{n}_div\").tabs( {{heightStyle: '{}',active: {landing_key} }} );", self.lineheight));
        out.push("    scscpq_tabs=tabs;".into());
        out.push("});".into());
        out.push("function tab_focus(name) {".into());
        out.push("    var field='#tab_' + name + '_no';".into());
        out.push(format!("    if (jQuery(field).length) jQuery(\"#{{{n}_div}}\").tabs({{ active: jQuery(field).val() }});"));
        out.push("}".into());
        out.push("function tab_visibility(visible, name) {".into());
        out.push("    var field='#tab_' + name;".into());
        out.push("    if (visible) {".into());
        out.push("        jQuery(field).show();".into());
        out.push("      } else {".into());
        out.push("        jQuery(field).hide();".into());
        out.push("    }".into());
        out.push("}".into());
        out.push("var jquery_active_tabs = new Array();".into());
        out.push("function fn_jquery_url(id,url) {".into());
        out.push("    if (jquery_active_tabs.indexOf(id) == -1) {".into());
        out.push("        jquery_active_tabs.push(id);".into());
        out.push("        document.getElementById(id).src=url;".into());
        out.push("    }".into());
        out.push("}".into());
        out.push("</script>".into());
        out.join("\n")
    }
}

impl Default for Tabs {
    fn default() -> Self {
        Tabs::new()
    }
}

/// menu_group_class: groups of links, each shown only to visitors at or above its role.
pub struct MenuGroup {
    class: &'static str,
    groups: Vec<(String, Vec<String>)>,
}

impl MenuGroup {
    pub fn new(class: &'static str) -> Self {
        MenuGroup { class, groups: Vec::new() }
    }

    pub fn group(&mut self, title: &str) {
        self.groups.push((esc(title), Vec::new()));
    }

    /// A link the caller has already decided this visitor may see.
    pub fn item(&mut self, name: &str, url: &str, opts: Opts) {
        let mut opts = opts;
        opts.push_default("class", "nobr");
        if let Some((_, items)) = self.groups.last_mut() {
            items.push(href(&esc(name), url, opts));
        }
    }

    pub fn render(&self) -> String {
        let mut out = Vec::new();
        for (title, items) in self.groups.iter().filter(|(_, items)| !items.is_empty()) {
            out.push(format!("<p class='{}'>", self.class));
            out.push(format!("<span style='font-size: 140%;'>{title}</span><br>"));
            out.push(items.join("\n | "));
            out.push("</p>".into());
        }
        out.join("\n")
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn quiet() -> Notes {
        Notes::new("T", "")
    }

    #[test]
    fn widgets_keep_the_php_attribute_order() {
        let notes = quiet();
        let f = Form { notes: &notes };
        assert_eq!(f.text("name", "Acme", 60, 60, Opts::new()), "<input type=text name='name' id='name' size='60' maxlength='60' value='Acme'>\n");
        assert_eq!(hidden("action", ""), "<input type=hidden name='action' id='action'>\n");
        assert_eq!(hidden("record_id", "5"), "<input type=hidden name='record_id' id='record_id' value='5'>\n");
        assert_eq!(f.checkbox("active", "1", true, Opts::new()), "<input type=checkbox name='active' id='active' value='1' checked>");
        assert_eq!(f.checkbox("active", "1", false, Opts::new()), "<input type=checkbox name='active' id='active' value='1'>");
        assert_eq!(f.textarea("address", "", 3, 60, Opts::new()), "<textarea name='address' id='address' cols='60' rows='3'></textarea>\n");
        assert_eq!(
            button("Update", Opts::new().onclick("fn_action(this,'update','5','');")),
            "<input type=button class='nav_button' value='Update' onclick=\"javascript:fn_action(this,'update','5','');\">\n"
        );
        assert_eq!(
            f.radio("delete", "7", false, Opts::new().onclick("x();")),
            "<input type=radio value='7' id='delete_7' name='delete' onclick=\"javascript:x();\">\n"
        );
        assert_eq!(href("Add new", "javascript:fn_action(this,'maintain','0','');", Opts::new()), "<a href=\"javascript:fn_action(this,'maintain','0','');\" >Add new</a>");
    }

    #[test]
    fn select_marks_the_chosen_option() {
        let notes = quiet();
        let f = Form { notes: &notes };
        let opts = list_options(&["txt", "csv"]);
        assert_eq!(
            f.select("output", &opts, "csv", Blank::Empty, Opts::new()),
            "<select name='output' id='output'>\n<option></option>\n<option value='txt'>txt</option>\n<option value='csv' selected>csv</option>\n</select>\n"
        );
    }

    #[test]
    fn fields_in_error_carry_the_red_border_and_their_text() {
        let mut notes = Notes::new("Supplier Codes", "");
        notes.error("name", None);
        notes.error("url", Some("Bad <url>"));
        let f = Form { notes: &notes };
        assert_eq!(f.text("name", "", 60, 60, Opts::new()), "<input type=text name='name' id='name' size='60' maxlength='60' style='border: 1px solid red !important;'>\n");
        assert_eq!(notes.with_error("url", "W".into()), "W<br>Bad &lt;url&gt;");
        assert_eq!(notes.render(), "<p class='message left'>Supplier Codes<span class=message2><br>Correct 2 error(s)</span></p>");
    }

    #[test]
    fn values_from_data_are_escaped_everywhere() {
        let notes = quiet();
        let f = Form { notes: &notes };
        assert!(f.text("x", "'><script>", 5, 0, Opts::new()).contains("value='&#039;&gt;&lt;script&gt;'"));
        assert!(f.textarea("x", "</textarea><b>", 1, 10, Opts::new()).contains("&lt;/textarea&gt;&lt;b&gt;</textarea>"));
        assert_eq!(js("O'Brien\"</script>"), "'O\\x27Brien\\x22\\x3c/script\\x3e'");
        assert_eq!(js("5"), "'5'");
        let mut n = Notes::new("<T>", "1.0");
        n.say("Deleted <b>");
        assert_eq!(n.render(), "<p class='message left'>&lt;T&gt; (1.0)<span class=message2><br>Deleted &lt;b&gt;</span></p>");
    }

    #[test]
    fn tabs_open_on_the_named_tab() {
        let mut t = Tabs::new();
        t.land_on("Orders");
        t.item("Menu", "a".into());
        t.item("Empty", String::new());
        t.item("Orders", "b".into());
        let html = t.render();
        assert!(html.contains("<li id='tab_scs_jquery_header_2' class='scs_jquery_tabs'><a href='#scs_jquery_header_2' id='href_scs_jquery_header_2' class='scs_jquery_tabs'>Orders</a></li>"));
        assert!(html.contains("active: 1 }"));
        assert!(html.contains("<input type=hidden name='jquerytab_T3JkZXJz' id='jquerytab_T3JkZXJz' value='Orders'>\n<div id='scs_jquery_header_2' class='scs_jquery_content'>\nb\n</div>\n"));
    }

    #[test]
    fn menu_groups_skip_empty_groups() {
        let mut m = MenuGroup::new("standard");
        m.group("Users");
        m.item("Maintain Users", "/user.php", Opts::new());
        m.group("Nothing here");
        assert_eq!(m.render(), "<p class='standard'>\n<span style='font-size: 140%;'>Users</span><br>\n<a href=\"/user.php\" class='nobr'>Maintain Users</a>\n</p>");
    }

    #[test]
    fn php_floats_and_queries() {
        assert_eq!(php_float(3.0), "3");
        assert_eq!(php_float(3.5), "3.5");
        assert_eq!(php_float(10.0 / 3.0 + 3.0), "6.3333333333333");
        assert_eq!(url_query(&[("tab", "Pending Users"), ("x", "")]), "?tab=Pending+Users");
    }
}
