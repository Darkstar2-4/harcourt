//! includes/jquery_rev1.php jquery_tab_class, as catalog.php uses it ("products_jquery"): tabs added in the order
//! the page builds them, listed in the visitor-sortable order first, rendered with their hidden index fields, the
//! loading spinner and the script that starts jQuery UI tabs.
//!
//! The PHP counts every `item()` call, also those it drops for having no content, and lists tabs by that count
//! while it stores them by position. A dropped tab therefore shifts the tabs after it; this keeps that order, since
//! it is what visitors see.

use super::{forms::hidden, html::{esc_attr, esc_text}, strings::PRODUCT_SORTABLE};

const NAME: &str = "products_jquery";

struct Tab {
    name: String,
    content_id: String,
}

pub struct Tabs {
    tabs: Vec<Tab>,
    contents: Vec<String>,
    calls: usize,
    sortable: Vec<(&'static str, Option<usize>)>,
    landing: usize,
}

impl Default for Tabs {
    fn default() -> Self {
        Tabs::new()
    }
}

impl Tabs {
    pub fn new() -> Tabs {
        Tabs { tabs: Vec::new(), contents: Vec::new(), calls: 0, sortable: PRODUCT_SORTABLE.iter().map(|k| (*k, None)).collect(), landing: 0 }
    }

    /// landing_tab(): the next tab added opens first.
    pub fn land_next(&mut self) {
        self.landing = self.tabs.len();
    }

    /// item(): a tab with this label and content (the PHP's array joined with newlines). No content adds nothing but
    /// still counts.
    pub fn item(&mut self, label: &str, content: Option<String>, id: Option<&str>, class: Option<&str>) {
        if let Some(id) = id {
            if let Some(entry) = self.sortable.iter_mut().find(|(k, _)| *k == id) {
                if entry.1.is_some() {
                    return;
                }
                entry.1 = Some(self.calls);
            }
        }
        self.calls += 1;
        let Some(content) = content else { return };
        let content_id = id.map_or_else(|| format!("{NAME}_header_{}", self.calls), str::to_string);
        let class = class.unwrap_or("products_content").to_string();
        self.contents.push(format!(
            "{}<div id='{}' class='{}'>\n{}\n</div>\n",
            hidden(&tab_field(label), label),
            esc_attr(&content_id),
            esc_attr(&class),
            content
        ));
        self.tabs.push(Tab { name: label.to_string(), content_id });
    }

    pub fn is_empty(&self) -> bool {
        self.tabs.is_empty()
    }

    /// output(), or nothing when no tab has content.
    pub fn render(&self) -> String {
        if self.tabs.is_empty() {
            return String::new();
        }
        let mut order: Vec<usize> = self.sortable.iter().filter_map(|(_, i)| *i).collect();
        for i in 0..self.calls {
            if !order.contains(&i) {
                order.push(i);
            }
        }
        let mut out: Vec<String> = Vec::new();
        for (i, tab) in self.tabs.iter().enumerate() {
            out.push(hidden(&format!("tab_{}_no", tab.content_id), &i.to_string()));
        }
        out.push(format!("<div id='{NAME}_loader' class='{NAME}_loader'><span class=jquery_loader></span><span class=jquery_loader_text>Loading ...</span></div>"));
        out.push(format!("<div id='{NAME}_div' class='{NAME}_div' style='display:none;'>"));
        out.push(format!("<div id='{NAME}_header' class='{NAME}_header'>"));
        out.push(format!("<ul id='{NAME}_tabs' class='{NAME}_tabs'>"));
        let mut active = 0;
        for (position, &item) in order.iter().enumerate() {
            let tab = self.tabs.get(item);
            let content_id = tab.map_or("", |t| t.content_id.as_str());
            out.push(hidden(&format!("tab_{content_id}_content"), &item.to_string()));
            if self.landing != 0 && item == self.landing {
                active = position;
            }
            match tab {
                Some(t) => out.push(format!(
                    "<li id='tab_{id}' class='{NAME}_tabs'><a href='#{id}' id='href_{id}' class='{NAME}_tabs'>{}</a></li>",
                    esc_text(&t.name),
                    id = esc_attr(&t.content_id)
                )),
                None => out.push(format!("<li class='{NAME}_tabs'></li>")),
            }
        }
        out.push("</ul>".into());
        out.push(format!("</div> <!-- {NAME}_header -->"));
        out.extend(self.contents.iter().cloned());
        out.push("</div> <!--  -->".into());
        out.push(String::new());
        out.push("<style>".into());
        out.push(".jquery_loader { display: inline-block; border: 4px solid #f3f3f3; border-radius: 50%; border-top: 4px solid #0981be; width: 20px; height: 20px;  -webkit-animation: spin 2s linear infinite; animation: spin 2s linear infinite; }".into());
        out.push(".jquery_loader_text { display: inline-block; vertical-align: top; margin-left: 10px; margin-top: 5px; }".into());
        out.push("</style>".into());
        out.push("<script>".into());
        out.push("var scscpq_tabs;".into());
        out.push("jQuery(document).ready(function() {".into());
        out.push(format!("    var tabs=jQuery(\"#{NAME}_loader\").hide();"));
        out.push(format!("    var tabs=jQuery(\"#{NAME}_div\").show();"));
        out.push(format!("    var tabs=jQuery(\"#{NAME}_div\").tabs( {{heightStyle: 'content',active: {active} }} );"));
        out.push("    scscpq_tabs=tabs;".into());
        out.push("});".into());
        out.push("function tab_focus(name) {".into());
        out.push("    var field='#tab_' + name + '_no';".into());
        out.push(format!("    if (jQuery(field).length) jQuery(\"#{{{NAME}_div\").tabs({{ active: jQuery(field).val() }});"));
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

/// tab_name(): the hidden field naming a tab, "jquerytab_" and the base64 of its label.
fn tab_field(label: &str) -> String {
    use base64::Engine;
    format!("jquerytab_{}", base64::engine::general_purpose::STANDARD.encode(label.trim()))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn sortable_tabs_lead_and_dropped_tabs_shift_the_rest() {
        let mut t = Tabs::new();
        t.item("Overview", Some("<p>o</p>".into()), Some("catalog_overview"), None);
        t.item("Product Details", None, Some("catalog_products"), None);
        t.item("Configure", Some("<p>c</p>".into()), Some("catalog_cad"), Some("products_cad"));
        t.item("Download", Some("<p>d</p>".into()), Some("catalog_download"), None);
        t.item("Profile", Some("<p>p</p>".into()), Some("catalog_profile"), None);
        let html = t.render();
        let lis: Vec<&str> = html.lines().filter(|l| l.starts_with("<li")).collect();
        assert_eq!(
            lis,
            [
                "<li id='tab_catalog_overview' class='products_jquery_tabs'><a href='#catalog_overview' id='href_catalog_overview' class='products_jquery_tabs'>Overview</a></li>",
                "<li id='tab_catalog_cad' class='products_jquery_tabs'><a href='#catalog_cad' id='href_catalog_cad' class='products_jquery_tabs'>Configure</a></li>",
                "<li id='tab_catalog_profile' class='products_jquery_tabs'><a href='#catalog_profile' id='href_catalog_profile' class='products_jquery_tabs'>Profile</a></li>",
                "<li id='tab_catalog_download' class='products_jquery_tabs'><a href='#catalog_download' id='href_catalog_download' class='products_jquery_tabs'>Download</a></li>",
                "<li class='products_jquery_tabs'></li>",
            ]
        );
        assert!(html.contains("<div id='catalog_cad' class='products_cad'>\n<p>c</p>\n</div>"));
        assert!(html.contains("<input type=hidden name='jquerytab_T3ZlcnZpZXc=' id='jquerytab_T3ZlcnZpZXc=' value='Overview'>"));
        assert!(html.contains("active: 0 }"));
    }

    #[test]
    fn the_landing_tab_is_the_one_added_after_the_mark() {
        let mut t = Tabs::new();
        t.item("Overview", Some("<p>o</p>".into()), Some("catalog_overview"), None);
        t.land_next();
        t.item("Part#", Some("<p>s</p>".into()), Some("catalog_sku"), None);
        assert!(t.render().contains(".tabs( {heightStyle: 'content',active: 1 } );"));
        assert_eq!(Tabs::new().render(), "");
    }
}
