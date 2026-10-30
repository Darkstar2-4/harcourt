//! The holes of the chrome templates: what wp_head(), the header, the nav walker, WPML's switchers, Yoast and the
//! footer printed differently per page.

use super::{Ctx, Slot, View, ORIGIN};
use crate::cms::{
    html::esc,
    model::{Lang, Menu, MenuItem, Page, PostType, Template},
    url::urlencode,
};

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Part {
    Head,
    Header,
    Footer,
}

/// Per-page values the importer copies from the static export into cms_page_meta, verbatim. They are plugin
/// output (scripts and styles for the page's forms, Yoast's schema graph and social lines) that no editor changes.
pub const CHROME_PLACEHOLDERS: [&str; 15] = [
    "doctype_prefix",
    "robots",
    "title",
    "seo_og_type",
    "seo_article_twitter",
    "yoast_schema",
    "yoast_extra",
    "recaptcha_prefetch",
    "gform_head_css",
    "owl_css",
    "gform_head_js",
    "datalayer",
    "footer_scripts",
    "after_html",
    "footer_menu_id_suffix",
];

fn template(lang: Lang, part: Part) -> &'static str {
    match (lang, part) {
        (Lang::En, Part::Head) => include_str!("chrome/en/head.html"),
        (Lang::En, Part::Header) => include_str!("chrome/en/header.html"),
        (Lang::En, Part::Footer) => include_str!("chrome/en/footer.html"),
        (Lang::Fr, Part::Head) => include_str!("chrome/fr/head.html"),
        (Lang::Fr, Part::Header) => include_str!("chrome/fr/header.html"),
        (Lang::Fr, Part::Footer) => include_str!("chrome/fr/footer.html"),
    }
}

/// header.php's cache-buster for scscpq_wp.js, which an older static export may not have yet.
fn script_version() -> &'static str {
    let head = template(Lang::En, Part::Head);
    head.split_once("scscpq_wp.js?ver=").map(|(_, rest)| &rest[..rest.find('"').unwrap_or(0)]).unwrap_or("")
}

/// An exported head as the current theme would print it: the export predates the last scscpq_wp.js version.
pub fn with_current_script_version(head: &str) -> String {
    let Some((before, rest)) = head.split_once("scscpq_wp.js?ver=") else { return head.to_string() };
    let end = rest.find('"').unwrap_or(0);
    format!("{before}scscpq_wp.js?ver={}{}", script_version(), &rest[end..])
}

/// The part with every hole filled. In probe mode the holes the static export also has stay as `{{name}}`, so
/// the result can be matched against the export's own chrome template.
pub fn fill(c: &mut Ctx, part: Part) -> String {
    let tpl = template(c.lang(), part);
    let saved = std::mem::take(&mut c.out);
    let mut out = String::with_capacity(tpl.len() + 16 * 1024);
    let mut rest = tpl;
    while let Some(start) = rest.find("{{") {
        let Some(len) = rest[start + 2..].find("}}").filter(|&n| rest[start + 2..start + 2 + n].bytes().all(|b| b.is_ascii_alphanumeric() || b == b'_' || b == b':')) else {
            out.push_str(&rest[..start + 1]);
            rest = &rest[start + 1..];
            continue;
        };
        out.push_str(&rest[..start]);
        let name = &rest[start + 2..start + 2 + len];
        c.out.clear();
        hole(c, name);
        out.push_str(&c.out);
        rest = &rest[start + 4 + len..];
    }
    out.push_str(rest);
    c.out = saved;
    out
}

fn probing(c: &Ctx) -> bool {
    c.slots.is_some()
}

fn hole(c: &mut Ctx, name: &str) {
    let ours = matches!(name, "header_height" | "body_padding" | "notification" | "main_menu" | "footer_menu" | "copyright" | "pdf1" | "pdf2" | "terms" | "privacy" | "legal");
    if probing(c) && !ours || name.starts_with("gform:") {
        c.push(&format!("{{{{{name}}}}}"));
        return;
    }
    let page = c.page;
    if c.view == View::NotFound && !ours {
        let v = not_found_value(c, name);
        c.push(&v);
        return;
    }
    let meta = |n: &str| c.site.meta(page.id).get(n).cloned().unwrap_or_default();
    match name {
        "hreflang" => {
            let s = hreflang(c);
            c.push(&s);
        }
        "seo_description_canonical" => {
            let (d, canonical) = (meta("description"), meta("canonical"));
            if !d.is_empty() {
                c.push(&format!("\t<meta name=\"description\" content=\"{d}\" />\n"));
            }
            if !canonical.is_empty() {
                c.push(&format!("\t<link rel=\"canonical\" href=\"{canonical}\" />\n"));
            }
        }
        "seo_og_description_url" => {
            let (d, canonical) = (meta("og_description"), meta("og_url"));
            if !d.is_empty() {
                c.push(&format!("\t<meta property=\"og:description\" content=\"{d}\" />\n"));
            }
            if !canonical.is_empty() {
                c.push(&format!("\t<meta property=\"og:url\" content=\"{canonical}\" />\n"));
            }
        }
        "rest_link" => {
            let base = match page.kind {
                PostType::Page => "pages",
                PostType::Post => "posts",
                other => other.as_str(),
            };
            c.push(&format!("<link rel=\"alternate\" title=\"JSON\" type=\"application/json\" href=\"{ORIGIN}/wp-json/wp/v2/{base}/{}\" />", page.id.0));
        }
        "shortlink_oembed" => {
            let s = shortlink_oembed(page);
            c.push(&s);
        }
        "body_class" => {
            let s = body_class(c);
            c.push(&s);
        }
        "wpml_hidden_switcher" => {
            let s = hidden_switcher(c);
            c.push(&s);
        }
        "logo_link" => {
            if is_front(page) {
                c.push(" aria-current=\"page\"");
            }
        }
        "header_height" => c.push(if c.notice() { "height: 200px;" } else { "height: 125px;" }),
        "body_padding" => c.push(if c.notice() { "padding-top: 20px;" } else { "padding-top: 125px;" }),
        "notification" => {
            if c.notice() {
                c.push("  <div class=\"legacy-website p-3\"><p class=\"m-0 small\">");
                c.option_html("website_notification");
                c.push("</p></div>\n");
            }
        }
        "main_menu" => main_menu(c),
        "footer_menu" => footer_menu(c),
        "copyright" | "pdf1" | "pdf2" | "terms" | "privacy" | "legal" => c.option_html(name),
        other => {
            let v = meta(other);
            c.push(&v);
        }
    }
}

/// A hole of WordPress's 404 page, as the import recorded it for the language, unless the view gives its own.
pub fn not_found_value(c: &Ctx, name: &str) -> String {
    if let Some(v) = c.holes.and_then(|h| h.get(name)) {
        return v.clone();
    }
    c.site.options(c.lang()).get(&format!("{}{name}", super::NOT_FOUND_OPTION)).map(|f| f.value.clone()).unwrap_or_default()
}

fn is_front(page: &Page) -> bool {
    page.path == "/"
}

fn translations<'a>(c: &Ctx<'a>) -> Vec<&'a Page> {
    Lang::ALL
        .iter()
        .filter_map(|&l| c.site.translation(c.page, l))
        .filter(|p| p.status == crate::cms::model::Status::Publish)
        .collect()
}

fn hreflang(c: &Ctx) -> String {
    let pages = translations(c);
    let mut out = String::new();
    for p in &pages {
        out.push_str(&format!("<link rel=\"alternate\" hreflang=\"{}\" href=\"{}\" />\n", p.lang.code(), esc(&p.url(ORIGIN))));
    }
    if let Some(en) = pages.iter().find(|p| p.lang == Lang::En) {
        out.push_str(&format!("<link rel=\"alternate\" hreflang=\"x-default\" href=\"{}\" />\n", esc(&en.url(ORIGIN))));
    }
    out
}

/// wp_get_shortlink(): `/?p=N`, or the home URL for the front page.
fn shortlink_oembed(page: &Page) -> String {
    let short = match (is_front(page), page.lang) {
        (true, Lang::En) => format!("{ORIGIN}/"),
        (true, Lang::Fr) => format!("{ORIGIN}/?lang=fr"),
        (false, Lang::En) => format!("{ORIGIN}/?p={}", page.id.0),
        (false, Lang::Fr) => format!("{ORIGIN}/?p={}&#038;lang=fr", page.id.0),
    };
    let url = urlencode(&page.url(ORIGIN));
    format!(
        "<link rel='shortlink' href='{short}' />\n<link rel=\"alternate\" title=\"oEmbed (JSON)\" type=\"application/json+oembed\" href=\"{ORIGIN}/wp-json/oembed/1.0/embed?url={url}\" />\n<link rel=\"alternate\" title=\"oEmbed (XML)\" type=\"text/xml+oembed\" href=\"{ORIGIN}/wp-json/oembed/1.0/embed?url={url}&#038;format=xml\" />\n"
    )
}

/// sanitize_html_class().
fn class_name(s: &str) -> String {
    s.chars().filter(|c| c.is_ascii_alphanumeric() || *c == '_' || *c == '-').collect()
}

fn body_class(c: &Ctx) -> String {
    let page = c.page;
    let site = c.site;
    if page.kind != PostType::Page {
        let kind = page.kind.as_str();
        let format = if page.kind == PostType::Post { " single-format-standard" } else { "" };
        return format!("wp-singular {kind}-template-default single single-{kind} postid-{}{format} wp-custom-logo wp-theme-momentum-custom", page.id.0);
    }
    let mut classes: Vec<String> = Vec::new();
    if is_front(page) {
        classes.push("home".into());
    }
    let privacy = site.options(Lang::En).get("@privacy_page").and_then(|f| f.value.parse::<i64>().ok());
    if privacy.and_then(|id| site.page(crate::cms::PageId(id))).is_some_and(|p| p.group_id == page.group_id) {
        classes.push("privacy-policy".into());
    }
    classes.push("wp-singular".into());
    let file = page.template.file();
    if page.template == Template::Page {
        classes.push("page-template-default".into());
    } else {
        classes.push("page-template".into());
        for part in file.split('/') {
            classes.push(format!("page-template-{}", class_name(&part.trim_end_matches(".php").replace(['.', '/'], "-"))));
        }
        classes.push(format!("page-template-{}", class_name(&file.replace('.', "-"))));
    }
    classes.push("page".into());
    classes.push(format!("page-id-{}", page.id.0));
    if site.pages.iter().any(|p| p.parent_id == Some(page.id) && p.status == crate::cms::model::Status::Publish) {
        classes.push("page-parent".into());
    }
    if let Some(parent) = page.parent_id {
        classes.push("page-child".into());
        classes.push(format!("parent-pageid-{}", parent.0));
    }
    classes.push("wp-custom-logo".into());
    classes.push("wp-theme-momentum-custom".into());
    if page.template == Template::Builder {
        classes.push("landing-page".into());
    }
    classes.join(" ")
}

struct LangText {
    nav: &'static str,
    toggle: &'static str,
    switch_to: &'static str,
    en: &'static str,
    fr: &'static str,
}

fn lang_text(lang: Lang) -> LangText {
    match lang {
        Lang::En => LangText {
            nav: "Language Switcher",
            toggle: "Language switcher, click to open then tab to navigate",
            switch_to: "Switch to",
            en: "English",
            fr: "French",
        },
        Lang::Fr => LangText {
            nav: "Sélecteur de langue",
            toggle: "Sélecteur de langue, cliquez pour ouvrir puis appuyez sur tabulation pour naviguer",
            switch_to: "Passer à",
            en: "Anglais",
            fr: "Français",
        },
    }
}

fn lang_name(t: &LangText, l: Lang) -> &'static str {
    match l {
        Lang::En => t.en,
        Lang::Fr => t.fr,
    }
}

fn flag(l: Lang) -> &'static str {
    match l {
        Lang::En => "american-uk-flags-small.svg",
        Lang::Fr => "french-flag-small.svg",
    }
}

fn flag_img(l: Lang, alt: &str, size: u32) -> String {
    format!(
        "<img\n            class=\"wpml-ls-flag\"\n            src=\"{ORIGIN}/wp-content/uploads/flags/{}\"\n            alt=\"{alt}\"\n            loading=\"lazy\"\n            width={size}\n            height={size}\n    />",
        flag(l)
    )
}

/// The page's translations as WPML links them.
fn alternates(c: &Ctx) -> Vec<(Lang, String)> {
    translations(c).iter().map(|p| (p.lang, p.url(ORIGIN))).collect()
}

fn lang_switcher(c: &Ctx) -> String {
    menu_switcher(c.lang(), &alternates(c))
}

/// WPML's menu language switcher (the items it appends to the navbar menu) on a page in `me` whose versions are at
/// `items`.
pub fn menu_switcher(me: Lang, items: &[(Lang, String)]) -> String {
    let t = lang_text(me);
    let n = items.len();
    let items: Vec<String> = items
        .iter()
        .enumerate()
        .map(|(i, (lang, url))| {
            let code = lang.code();
            let current = *lang == me;
            let mut class = format!("menu-item wpml-ls-slot-2 wpml-ls-item wpml-ls-item-{code}");
            if current {
                class.push_str(" wpml-ls-current-language");
            }
            class.push_str(" wpml-ls-menu-item");
            if i == 0 {
                class.push_str(" wpml-ls-first-item");
            }
            if i + 1 == n {
                class.push_str(" wpml-ls-last-item");
            }
            class.push_str(&format!(" menu-item-type-wpml_ls_menu_item menu-item-object-wpml_ls_menu_item nav-item nav-item-wpml-ls-2-{code}"));
            let name = lang_name(&t, *lang);
            let title = if current { String::new() } else { format!(" title=\"{} {name}\"", t.switch_to) };
            format!(
                "<li  id=\"menu-item-wpml-ls-2-{code}\" class=\"{class}\"><a{title} href=\"{}\" class=\"nav-link\">{}</a><span></span></li>",
                esc(url),
                flag_img(*lang, name, 50)
            )
        })
        .collect();
    items.join("\n")
}

fn hidden_switcher(c: &Ctx) -> String {
    dropdown_switcher(c.lang(), &alternates(c))
}

/// The legacy dropdown switcher header.php prints inside an HTML comment, on a page in `me` whose versions are at
/// `items`.
pub fn dropdown_switcher(me: Lang, items: &[(Lang, String)]) -> String {
    let t = lang_text(me);
    let n = items.len();
    let my_index = items.iter().position(|(l, _)| *l == me).unwrap_or(0);
    let edge = |i: usize| {
        let mut s = String::new();
        if i == 0 {
            s.push_str(" wpml-ls-first-item");
        }
        if i + 1 == n {
            s.push_str(" wpml-ls-last-item");
        }
        s
    };
    let code = me.code();
    let name = lang_name(&t, me);
    let mut out = format!(
        "<div role=\"navigation\" aria-label=\"{}\" class=\"wpml-ls-statics-shortcode_actions wpml-ls wpml-ls-legacy-dropdown-click js-wpml-ls-legacy-dropdown-click\">\n\t<ul>\n\n\t\t<li class=\"wpml-ls-slot-shortcode_actions wpml-ls-item wpml-ls-item-{code} wpml-ls-current-language{} wpml-ls-item-legacy-dropdown-click\">\n\n\t\t\t\t\t\t\t\t\t<a href=\"#\" hreflang=\"{code}\" lang=\"{code}\" class=\"js-wpml-ls-item-toggle wpml-ls-item-toggle\" aria-current=\"page\" aria-expanded=\"false\" aria-controls=\"wpml-ls-submenu-click-default\" aria-haspopup=\"true\" aria-label=\"{}\" tabindex=\"0\" role=\"button\" title=\"{} {name}\">\n\t\t\t\t                                    {}</a>\n\n\t\t\t<ul id=\"wpml-ls-submenu-click-default\" class=\"js-wpml-ls-sub-menu wpml-ls-sub-menu\">\n",
        t.nav,
        edge(my_index),
        t.toggle,
        t.switch_to,
        flag_img(me, name, 30)
    );
    for (i, (lang, url)) in items.iter().enumerate().filter(|(_, (l, _))| *l != me) {
        let pcode = lang.code();
        let pname = lang_name(&t, *lang);
        out.push_str(&format!(
            "\t\t\t\t\n\t\t\t\t\t<li class=\"wpml-ls-slot-shortcode_actions wpml-ls-item wpml-ls-item-{pcode}{}\">\n\t\t\t\t\t\t<a href=\"{}\" hreflang=\"{pcode}\" lang=\"{pcode}\" class=\"wpml-ls-link\" aria-label=\"{} {pname}\" title=\"{} {pname}\">\n\t\t\t\t\t\t\t                                    {}</a>\n\t\t\t\t\t</li>\n\n",
            edge(i),
            esc(url),
            t.switch_to,
            t.switch_to,
            flag_img(*lang, pname, 30)
        ));
    }
    out.push_str("\t\t\t\t\t\t\t</ul>\n\n\t\t</li>\n\n\t</ul>\n</div>\n");
    out
}

/// _wp_menu_item_classes_by_context() for one menu, then each item's classes as the walker prints them.
struct Classes {
    by_item: Vec<(i64, Vec<String>, bool, bool)>,
}

fn menu_classes(c: &Ctx, items: &[&MenuItem]) -> Classes {
    let page = c.page;
    let ancestors: Vec<i64> = c.site.ancestors(page).iter().map(|p| p.id.0).collect();
    let parent_of = |id: i64| items.iter().find(|m| m.id == id).and_then(|m| m.parent_id);
    let request = c.uri.trim_end_matches('/');
    let mut active_ancestors: Vec<i64> = Vec::new();
    let mut active_parent_items: Vec<i64> = Vec::new();
    let mut active_parent_objects: Vec<i64> = Vec::new();
    let mut first: Vec<(i64, Vec<String>, bool)> = Vec::new();
    for m in items {
        let mut classes: Vec<String> = m.classes.split_whitespace().map(str::to_string).collect();
        let (kind, object) = if m.page_id.is_some() { ("post_type", "page") } else { ("custom", "custom") };
        classes.push("menu-item".into());
        classes.push(format!("menu-item-type-{kind}"));
        classes.push(format!("menu-item-object-{object}"));
        let mut current = false;
        if let Some(target) = m.page_id {
            if c.site.page(target).is_some_and(|p| p.path == "/") {
                classes.push("menu-item-home".into());
            }
            if target == page.id {
                current = true;
                classes.push("current-menu-item".into());
                classes.extend(["page_item".to_string(), format!("page-item-{}", target.0), "current_page_item".to_string()]);
            }
        } else {
            let raw = m.url.find('#').filter(|&i| i > 0).map(|i| &m.url[..i]).unwrap_or(&m.url);
            let item = raw.trim_end_matches('/');
            let full = format!("{ORIGIN}{request}");
            if !raw.is_empty() && (item == full || item == request) {
                current = true;
                classes.push("current-menu-item".into());
            }
        }
        if current {
            let mut a = m.parent_id;
            while let Some(id) = a.filter(|id| !active_ancestors.contains(id)) {
                active_ancestors.push(id);
                a = parent_of(id);
            }
            active_parent_items.extend(m.parent_id);
            if let Some(parent) = m.page_id.and_then(|p| c.site.page(p)).and_then(|p| p.parent_id) {
                active_parent_objects.push(parent.0);
            }
        }
        first.push((m.id, classes, current));
    }
    let mut by_item = Vec::new();
    for (id, mut classes, current) in first {
        let m = items.iter().find(|m| m.id == id).expect("item");
        let object = m.page_id.map(|p| p.0);
        if object.is_some_and(|o| ancestors.contains(&o) && o != page.id.0) {
            classes.push("current-page-ancestor".into());
        }
        let ancestor = active_ancestors.contains(&id);
        if ancestor {
            classes.push("current-menu-ancestor".into());
        }
        if active_parent_items.contains(&id) {
            classes.push("current-menu-parent".into());
        }
        if object.is_some_and(|o| active_parent_objects.contains(&o)) {
            classes.push("current-page-parent".into());
        }
        if object.is_some() {
            if classes.iter().any(|c| c == "current-menu-parent") {
                classes.push("current_page_parent".into());
            }
            if ancestor {
                classes.push("current_page_ancestor".into());
            }
        }
        let mut seen = std::collections::HashSet::new();
        classes.retain(|c| seen.insert(c.clone()));
        let has_children = items.iter().any(|x| x.parent_id == Some(id));
        if has_children {
            classes.push("menu-item-has-children".into());
        }
        by_item.push((id, classes, current, ancestor));
    }
    Classes { by_item }
}

fn main_menu(c: &mut Ctx) {
    let items: Vec<&MenuItem> = c.site.menu(Menu::Main, c.lang()).collect();
    let classes = menu_classes(c, &items);
    walk(c, &items, &classes, None, 0);
    if probing(c) {
        c.push("{{lang_switcher}}");
    } else if c.view == View::NotFound {
        let s = not_found_value(c, "lang_switcher");
        c.push(&s);
    } else {
        let s = lang_switcher(c);
        c.push(&s);
    }
}

/// The theme's momentumst_walker_nav_menu, depth 3.
fn walk(c: &mut Ctx, items: &[&MenuItem], classes: &Classes, parent: Option<i64>, depth: usize) {
    if depth >= 3 {
        return;
    }
    let level: Vec<&MenuItem> = items.iter().copied().filter(|m| m.parent_id == parent).collect();
    for m in level {
        let has_children = depth + 1 < 3 && items.iter().any(|x| x.parent_id == Some(m.id));
        let (_, base, current, ancestor) = classes.by_item.iter().find(|(id, ..)| *id == m.id).expect("classes");
        c.push(&"\t".repeat(depth));
        c.push(&format!("<li  id=\"menu-item-{}\" class=\"", m.id));
        if probing(c) {
            c.push(&format!("{{{{menu_{}}}}}", m.id));
        } else {
            let mut all = base.clone();
            if has_children {
                all.push("dropdown".into());
            }
            if *current || *ancestor {
                all.push("active".into());
            }
            all.push("nav-item".into());
            all.push(format!("nav-item-{}", m.id));
            if depth > 0 && has_children {
                all.push("dropdown-menu dropdown-menu-right".into());
            }
            c.text(&all.join(" "));
        }
        c.push("\">");
        let mut attrs = String::new();
        if !m.target.is_empty() {
            attrs.push_str(&format!(" target=\"{}\"", esc(&m.target)));
        }
        if !m.url.is_empty() {
            attrs.push_str(&format!(" href=\"{}\"", esc(&m.url)));
        }
        if has_children {
            attrs.push_str(&format!(" href=\"{}\" class=\"nav-link dropdown-toggle\" aria-expanded=\"false\"", esc(&m.url)));
        } else {
            attrs.push_str(" class=\"nav-link\"");
        }
        if depth > 0 {
            c.push(&format!("<a class=\"dropdown-item dropdown-item-depth-0\"{attrs}>"));
        } else {
            c.push(&format!("<a{attrs}>"));
        }
        c.slot(Slot::MenuLabel(m.id));
        c.push("</a><span></span>");
        if has_children {
            let indent = "\t".repeat(depth);
            let sub = if depth > 0 { " sub-menu" } else { "" };
            c.push(&format!("\n{indent}<ul class=\"dropdown-menu{sub} depth_{depth}\">\n"));
            walk(c, items, classes, Some(m.id), depth + 1);
            c.push(&format!("{indent}</ul>\n"));
        }
        c.push("</li>\n");
    }
}

/// The footer-widget-area nav_menu widget, through WordPress's default walker.
fn footer_menu(c: &mut Ctx) {
    let items: Vec<&MenuItem> = c.site.menu(Menu::Footer, c.lang()).collect();
    let classes = menu_classes(c, &items);
    let slug = if c.lang() == Lang::Fr { "footer-french" } else { "footer" };
    let suffix = c
        .holes
        .and_then(|h| h.get("footer_menu_id_suffix"))
        .or_else(|| c.site.meta(c.page.id).get("footer_menu_id_suffix"))
        .cloned()
        .unwrap_or_default();
    c.push(&format!("<div class=\"menu-{slug}-container\"><ul id=\"menu-{slug}"));
    if probing(c) {
        c.push("{{footer_menu_id_suffix}}");
    } else {
        c.push(&suffix);
    }
    c.push("\" class=\"menu\">");
    for m in items.iter().filter(|m| m.parent_id.is_none()) {
        let (_, base, current, _) = classes.by_item.iter().find(|(id, ..)| *id == m.id).expect("classes");
        c.push("<li");
        if probing(c) {
            c.push(&format!("{{{{footer_menu_{}}}}}", m.id));
        } else {
            let mut all = base.clone();
            all.push(format!("menu-item-{}", m.id));
            if suffix.is_empty() {
                c.push(&format!(" id=\"menu-item-{}\"", m.id));
            }
            c.push(&format!(" class=\"{}\"", esc(&all.join(" "))));
        }
        c.push("><a");
        if !m.target.is_empty() {
            c.push(&format!(" target=\"{}\"", esc(&m.target)));
        }
        c.push(&format!(" href=\"{}\"", esc(&m.url)));
        if probing(c) {
            c.push(&format!("{{{{footer_menu_{}_link}}}}", m.id));
        } else if *current {
            c.push(" aria-current=\"page\"");
        }
        c.push(">");
        c.slot(Slot::MenuLabel(m.id));
        c.push("</a></li>\n");
    }
    c.push("</ul></div>");
}

/// The current year in UTC, which is what PHP's date('Y') gives under WordPress.
pub fn current_year() -> i64 {
    let days = crate::db::unix_now().div_euclid(86_400);
    civil_year(days)
}

fn civil_year(days: i64) -> i64 {
    let z = days + 719_468;
    let era = z.div_euclid(146_097);
    let doe = z - era * 146_097;
    let yoe = (doe - doe / 1460 + doe / 36_524 - doe / 146_096) / 365;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let month = if mp < 10 { mp + 3 } else { mp - 9 };
    yoe + era * 400 + i64::from(month <= 2)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn years_from_days() {
        assert_eq!(civil_year(0), 1970);
        assert_eq!(civil_year(20_454), 2026);
        assert_eq!(civil_year(20_453), 2025);
        assert_eq!(urlencode("https://www.harcourt.co/a b/?lang=fr"), "https%3A%2F%2Fwww.harcourt.co%2Fa+b%2F%3Flang%3Dfr");
        assert_eq!(class_name("layouts/template-x_y-php"), "layoutstemplate-x_y-php");
    }
}
