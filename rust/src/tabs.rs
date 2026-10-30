//! The catalog tabs, sent already in the state jQuery UI leaves them.
//!
//! catalog.php sends a "Loading ..." spinner and the tabs container hidden (display:none); at DOMContentLoaded its
//! script hides the spinner, shows the container (every panel visible at that instant) and calls .tabs(), which
//! hides the inactive panels. So nothing of the catalog paints until the whole page has parsed, and the reveal
//! makes Chrome style every element of every panel, the hidden 1,054-row parts table included (24k elements,
//! ~120 ms), before tabs hides them again. Here the markup arrives as jQuery UI 1.12.1 tabs (heightStyle 'content')
//! leaves it: spinner hidden, container visible with the widget classes, the active tab marked, inactive panels
//! display:none. The script still runs at DOMContentLoaded and finds nothing left to change. Anything that does not
//! match exactly what that code generates is left alone.
//!
//! The tabs now show before jQuery UI is attached to them (until DOMContentLoaded, ~150-200 ms on the big pages).
//! EARLY_CLICK holds a tab click made in that window and replays it once the tabs work. Its ready callback runs
//! after the catalog's own (jQuery runs them in order), and the flag lives on this view's tab list, so a view
//! swapped in by hx-nav.js does not see the previous view's tabs as ready.

use std::sync::OnceLock;

use regex::Regex;

const LOADER: &str = "<div id='products_jquery_loader' class='products_jquery_loader'>";
const LOADER_DONE: &str = "<div id='products_jquery_loader' class='products_jquery_loader' style='display: none;'>";
const DIV: &str = "<div id='products_jquery_div' class='products_jquery_div' style='display:none;'>";
const DIV_DONE: &str = "<div id='products_jquery_div' class='products_jquery_div ui-tabs ui-corner-all ui-widget ui-widget-content'>";
const UL: &str = "<ul id='products_jquery_tabs' class='products_jquery_tabs'>";
const UL_DONE: &str = "<ul id='products_jquery_tabs' class='products_jquery_tabs ui-tabs-nav ui-corner-all ui-helper-reset ui-helper-clearfix ui-widget-header' role='tablist'>";

const EARLY_CLICK: &str = "\n<script>(function () { var held = null, ul = document.getElementById('products_jquery_tabs');\n\
  function hold(e) { var a = e.target.closest && e.target.closest('a'); if (a && !ul.hxReady) { e.preventDefault(); held = a; } }\n\
  ul.addEventListener('click', hold);\n\
  jQuery(function () { ul.hxReady = true; ul.removeEventListener('click', hold); if (held) jQuery(held).trigger('click'); }); })();</script>";

struct Res {
    init: Regex,
    tab: Regex,
    panel: Regex,
}

fn res() -> &'static Res {
    static R: OnceLock<Res> = OnceLock::new();
    R.get_or_init(|| Res {
        init: Regex::new(r##"jQuery\("#products_jquery_div"\)\.tabs\( \{heightStyle: 'content',active: (\d+) \} \);"##).unwrap(),
        tab: Regex::new(r"<li id='tab_([a-z_]+)' class='products_jquery_tabs'><a href='#([a-z_]+)' id='href_([a-z_]+)' class='products_jquery_tabs'>").unwrap(),
        panel: Regex::new(r"<div id='([a-z_]+)' class='([a-z_]+)'>").unwrap(),
    })
}

/// The markup with the tabs in their initialised state, or None to send it as it came. The tab list must hold
/// exactly the generated tabs (every <li> in it matched), the spinner and container must come before it and each
/// tab's panel must follow it exactly once; nothing outside those three places is touched.
pub fn preinit(markup: &str) -> Option<String> {
    let r = res();
    let mut inits = r.init.captures_iter(markup);
    let active: usize = inits.next()?[1].parse().ok()?;
    if inits.next().is_some() || [LOADER, DIV, UL].iter().any(|t| markup.matches(t).count() != 1) {
        return None;
    }
    let ul_start = markup.find(UL)?;
    let ul_end = ul_start + markup[ul_start..].find("</ul>")?;
    let (head, list, rest) = (&markup[..ul_start], &markup[ul_start..ul_end], &markup[ul_end..]);
    if !head.contains(LOADER) || !head.contains(DIV) {
        return None;
    }
    let tabs: Vec<_> = r.tab.captures_iter(list).collect();
    if tabs.is_empty() || list.matches("<li ").count() + list.matches("<li>").count() != tabs.len() || active >= tabs.len() || tabs.iter().any(|c| c[1] != c[2] || c[2] != c[3]) {
        return None;
    }
    let ids: Vec<String> = tabs.iter().map(|c| c[1].to_string()).collect();
    let on = ids[active].clone();
    let panels: Vec<String> = r.panel.captures_iter(rest).filter(|c| ids.contains(&c[1].to_string())).map(|c| c[1].to_string()).collect();
    if panels.len() != ids.len() || ids.iter().any(|id| !panels.contains(id)) {
        return None;
    }

    let head = head.replacen(LOADER, LOADER_DONE, 1).replacen(DIV, DIV_DONE, 1);
    let list = r.tab.replace_all(&list.replacen(UL, UL_DONE, 1), |c: &regex::Captures| {
        let id = &c[1];
        let sel = id == on;
        format!(
            "<li id='tab_{id}' class='products_jquery_tabs ui-tabs-tab ui-corner-top ui-state-default ui-tab{}' role='tab' tabindex='{}' aria-controls='{id}' aria-labelledby='href_{id}' aria-selected='{sel}' aria-expanded='{sel}'><a href='#{id}' id='href_{id}' class='products_jquery_tabs ui-tabs-anchor' role='presentation' tabindex='-1'>",
            if sel { " ui-tabs-active ui-state-active" } else { "" },
            if sel { 0 } else { -1 },
        )
    })
    .into_owned();
    let rest = r.panel.replace_all(rest, |c: &regex::Captures| {
        let (id, class) = (&c[1], &c[2]);
        if !ids.iter().any(|i| i == id) {
            return c[0].to_string();
        }
        let sel = id == on;
        format!(
            "<div id='{id}' class='{class} ui-tabs-panel ui-corner-bottom ui-widget-content' aria-labelledby='href_{id}' role='tabpanel' aria-hidden='{}'{}>",
            !sel,
            if sel { "" } else { " style='display: none;'" },
        )
    });
    let mut html = format!("{head}{list}{rest}");
    let at = r.init.find(&html)?.end();
    let at = at + html[at..].find("</script>")? + "</script>".len();
    html.insert_str(at, EARLY_CLICK);
    Some(html)
}

#[cfg(test)]
mod tests {
    use super::*;

    const PAGE: &str = concat!(
        "<div id='products_jquery_loader' class='products_jquery_loader'><span class=jquery_loader></span></div>\n",
        "<div id='products_jquery_div' class='products_jquery_div' style='display:none;'>\n",
        "<div id='products_jquery_header' class='products_jquery_header'>\n",
        "<ul id='products_jquery_tabs' class='products_jquery_tabs'>\n",
        "<li id='tab_catalog_overview' class='products_jquery_tabs'><a href='#catalog_overview' id='href_catalog_overview' class='products_jquery_tabs'>Overview</a></li>\n",
        "<li id='tab_catalog_products' class='products_jquery_tabs'><a href='#catalog_products' id='href_catalog_products' class='products_jquery_tabs'>Standards</a></li>\n",
        "</ul>\n</div>\n",
        "<div id='catalog_overview' class='products_content'><div id='other' class='x'>a</div></div>\n",
        "<div id='catalog_products' class='products_content'>b</div>\n",
        "</div>\n<script>jQuery(document).ready(function() {\n",
        "    var tabs=jQuery(\"#products_jquery_div\").tabs( {heightStyle: 'content',active: 1 } );\n});</script>",
    );

    #[test]
    fn second_tab_active() {
        let out = preinit(PAGE).unwrap();
        assert!(out.contains(LOADER_DONE) && out.contains(DIV_DONE) && out.contains(UL_DONE));
        assert!(out.contains("<li id='tab_catalog_products' class='products_jquery_tabs ui-tabs-tab ui-corner-top ui-state-default ui-tab ui-tabs-active ui-state-active' role='tab' tabindex='0' aria-controls='catalog_products' aria-labelledby='href_catalog_products' aria-selected='true' aria-expanded='true'><a href='#catalog_products' id='href_catalog_products' class='products_jquery_tabs ui-tabs-anchor' role='presentation' tabindex='-1'>Standards"));
        assert!(out.contains("tabindex='-1' aria-controls='catalog_overview'"));
        assert!(out.contains("<div id='catalog_overview' class='products_content ui-tabs-panel ui-corner-bottom ui-widget-content' aria-labelledby='href_catalog_overview' role='tabpanel' aria-hidden='true' style='display: none;'>"));
        assert!(out.contains("<div id='catalog_products' class='products_content ui-tabs-panel ui-corner-bottom ui-widget-content' aria-labelledby='href_catalog_products' role='tabpanel' aria-hidden='false'>b"));
        assert!(out.contains("<div id='other' class='x'>a"));
        assert!(out.ends_with(&format!("}});</script>{EARLY_CLICK}")));
    }

    #[test]
    fn anything_unexpected_is_left_alone() {
        assert!(preinit(&PAGE.replace("active: 1", "active: 2")).is_none());
        assert!(preinit(&PAGE.replace("<div id='catalog_products' class='products_content'>", "<div id='catalog_products' class='products_content' title='x'>")).is_none());
        assert!(preinit(&PAGE.replace("heightStyle: 'content'", "heightStyle: 'auto'")).is_none());
        assert!(preinit(&format!("{PAGE}{PAGE}")).is_none());
        assert!(preinit("<div>no tabs</div>").is_none());
        // an extra or differently written tab in the list
        assert!(preinit(&PAGE.replace("</ul>", "<li id='tab_x' class='products_jquery_tabs' style='display:none'><a href='#x'>X</a></li>\n</ul>")).is_none());
        // a tab-shaped item inside a panel is not part of the widget and stays as it is
        let inner = "<li id='tab_catalog_cad' class='products_jquery_tabs'><a href='#catalog_cad' id='href_catalog_cad' class='products_jquery_tabs'>";
        let out = preinit(&PAGE.replace("<div id='other' class='x'>a</div>", &format!("<div id='other' class='x'>{inner}</div>"))).unwrap();
        assert!(out.contains(inner));
    }
}
