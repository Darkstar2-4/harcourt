//! Pages in the momentum-custom theme's markup, from a [`Site`] snapshot. Pure: no I/O, so the same code serves
//! requests and, in probe mode, recovers field values from pages WordPress rendered (see `probe`).
//!
//! A page is head + header + body + footer. The chrome (head, header, footer) is WordPress's output with the
//! per-page parts as `{{name}}` holes (chrome/{en,fr}/*.html, made by tools/cms_chrome.py); `chrome` computes each
//! hole. The body comes from the page's template in `templates`, a transcription of the theme's PHP.

mod chrome;
pub mod probe;
mod templates;

use super::{
    media::{Images, Phase},
    model::{Field, Kind, Lang, Media, MediaId, Page, PageId, Site, Template},
};

pub const ORIGIN: &str = "https://www.harcourt.co";
pub const UPLOADS: &str = "https://www.harcourt.co/wp-content/uploads/";

/// A value a template prints, by where it is stored.
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub enum Slot {
    /// cms_field of a page (not always the page being rendered: grids print their children's excerpts).
    Field(PageId, String),
    /// cms_page_meta of a page, e.g. its title as `the_title()` prints it.
    Meta(PageId, String),
    Option(Lang, String),
    MenuLabel(i64),
}

pub struct Ctx<'a> {
    pub site: &'a Site,
    pub page: &'a Page,
    /// The request URI WordPress saw: path plus query.
    pub uri: &'a str,
    pub out: String,
    pub images: Images,
    /// In probe mode, the slot behind each sentinel written so far.
    slots: Option<Vec<Slot>>,
    /// Set when the page uses a part of the theme not transcribed yet; such a page is not rendered.
    unported: Option<String>,
}

impl<'a> Ctx<'a> {
    pub fn new(site: &'a Site, page: &'a Page, uri: &'a str) -> Ctx<'a> {
        Ctx { site, page, uri, out: String::with_capacity(64 * 1024), images: Images::default(), slots: None, unported: None }
    }

    pub fn unported(&mut self, what: &str) {
        self.unported.get_or_insert_with(|| what.to_string());
    }

    pub fn probing(site: &'a Site, page: &'a Page, uri: &'a str) -> Ctx<'a> {
        Ctx { slots: Some(Vec::new()), ..Ctx::new(site, page, uri) }
    }

    pub fn into_slots(self) -> (String, Vec<Slot>) {
        (self.out, self.slots.unwrap_or_default())
    }

    pub fn lang(&self) -> Lang {
        self.page.lang
    }

    pub fn push(&mut self, s: &str) {
        self.out.push_str(s);
    }

    /// Escaped text in an attribute or element.
    pub fn text(&mut self, s: &str) {
        self.out.push_str(&super::html::esc(s));
    }

    /// Prints a stored value verbatim, or its sentinel when probing.
    pub fn slot(&mut self, slot: Slot) {
        if let Some(slots) = self.slots.as_mut() {
            self.out.push_str(&probe::sentinel(slots.len()));
            slots.push(slot);
            return;
        }
        let value = self.stored(&slot).unwrap_or_default().to_string();
        let value = expand_shortcodes(&value);
        self.images.note_html(&value);
        self.out.push_str(&value);
    }

    fn stored(&self, slot: &Slot) -> Option<&'a str> {
        let site = self.site;
        match slot {
            Slot::Field(id, name) => site.fields(*id).get(name).map(|f| f.value.as_str()),
            Slot::Meta(id, name) => site.meta(*id).get(name).map(String::as_str),
            Slot::Option(lang, name) => site.options(*lang).get(name).map(|f| f.value.as_str()),
            Slot::MenuLabel(id) => site.menus.iter().find(|m| m.id == *id).map(|m| m.label.as_str()),
        }
    }

    /// `echo get_field($name)` for the page.
    pub fn html(&mut self, name: &str) {
        self.slot(Slot::Field(self.page.id, name.to_string()));
    }

    /// `echo get_field($name, 'options')`.
    pub fn option_html(&mut self, name: &str) {
        self.slot(Slot::Option(self.lang(), name.to_string()));
    }

    /// `the_title()` of a page.
    pub fn the_title(&mut self, id: PageId) {
        self.slot(Slot::Meta(id, "the_title".into()));
    }

    pub fn field_of(&self, id: PageId, name: &str) -> Option<&'a Field> {
        self.site.fields(id).get(name)
    }

    pub fn field(&self, name: &str) -> Option<&'a Field> {
        self.field_of(self.page.id, name)
    }

    pub fn option(&self, name: &str) -> Option<&'a Field> {
        self.site.options(self.lang()).get(name)
    }

    /// An ACF true/false, or any value PHP would find truthy.
    pub fn flag(&self, name: &str) -> bool {
        self.field(name).is_some_and(|f| truthy(&f.value))
    }

    pub fn count(&self, name: &str) -> usize {
        self.field(name).filter(|f| f.kind == Kind::Count).and_then(|f| f.value.parse().ok()).unwrap_or(0)
    }

    pub fn choice(&self, name: &str) -> &'a str {
        self.field(name).map(|f| f.value.as_str()).unwrap_or("")
    }

    pub fn media_of(&self, id: PageId, name: &str) -> Option<&'a Media> {
        let id = self.field_of(id, name)?.value.trim().parse::<i64>().ok()?;
        self.site.media.get(&MediaId(id))
    }

    pub fn media(&self, name: &str) -> Option<&'a Media> {
        self.media_of(self.page.id, name)
    }

    pub fn option_media(&self, name: &str) -> Option<&'a Media> {
        let id = self.option(name)?.value.trim().parse::<i64>().ok()?;
        self.site.media.get(&MediaId(id))
    }

    /// `echo wp_get_attachment_image($id, 'full')`.
    pub fn image(&mut self, media: Option<&Media>) {
        let tag = self.images.full(media, UPLOADS);
        self.out.push_str(&tag);
    }

    /// An ACF image array's `url`: wp_get_attachment_url().
    pub fn media_url(media: Option<&Media>) -> String {
        media.map(|m| format!("{UPLOADS}{}", m.file)).unwrap_or_default()
    }

    /// The header notice banner is set (`get_field('website_notification', 'options')`).
    pub fn notice(&self) -> bool {
        self.option("website_notification").is_some_and(|f| truthy(&f.value))
    }

    /// Where Gravity Form `id` goes; filled after rendering.
    pub fn form(&mut self, id: u32) {
        self.out.push_str(&format!("{{{{gform:{id}}}}}"));
    }

    pub fn set_phase(&mut self, phase: Phase) {
        self.images.set_phase(phase);
    }

    /// `yoast_breadcrumb('<p id="breadcrumbs">', '</p>')`.
    pub fn breadcrumbs(&mut self) {
        self.push("<p id=\"breadcrumbs\"><span><span><a href=\"");
        self.push(ORIGIN);
        self.push("/\">");
        self.slot(Slot::Option(self.lang(), "@breadcrumbs_home".into()));
        self.push("</a></span>");
        for p in self.site.ancestors(self.page) {
            self.push(" » <span><a href=\"");
            self.text(&p.url(ORIGIN));
            self.push("\">");
            self.slot(Slot::Meta(p.id, "breadcrumb_title".into()));
            self.push("</a></span>");
        }
        self.push(" » <span class=\"breadcrumb_last\" aria-current=\"page\">");
        self.slot(Slot::Meta(self.page.id, "breadcrumb_title".into()));
        self.push("</span></span></p>");
    }
}

/// PHP truthiness of a stored string.
pub fn truthy(v: &str) -> bool {
    !v.is_empty() && v != "0"
}

/// The shortcodes stored values may hold that WordPress expanded on every render: `[year]` (functions.php).
fn expand_shortcodes(v: &str) -> std::borrow::Cow<'_, str> {
    if v.contains("[year]") {
        std::borrow::Cow::Owned(v.replace("[year]", &chrome::current_year().to_string()))
    } else {
        std::borrow::Cow::Borrowed(v)
    }
}

/// Whether Rust renders pages of this template. The others stay on the static export until ported.
pub fn ported(t: Template) -> bool {
    templates::body_fn(t).is_some()
}

/// The page body, or None when its template, or a part of it this page uses, is not ported.
pub fn body(c: &mut Ctx) -> Option<()> {
    let f = templates::body_fn(c.page.template)?;
    c.set_phase(Phase::Loop);
    c.push("\n");
    f(c);
    c.set_phase(Phase::After);
    if let Some(what) = &c.unported {
        tracing::debug!("page {} uses {what}, which is not ported", c.page.id.0);
        return None;
    }
    Some(())
}

/// The full page with `{{gform:N}}` holes left for the forms. None when the template is not ported.
pub fn render(site: &Site, page: &Page, uri: &str) -> Option<String> {
    render_parts(site, page, uri).map(|parts| parts.concat())
}

/// Head, header, body and footer.
pub fn render_parts(site: &Site, page: &Page, uri: &str) -> Option<[String; 4]> {
    let mut c = Ctx::new(site, page, uri);
    c.set_phase(Phase::Header);
    let mut body_ctx = Ctx::new(site, page, uri);
    body(&mut body_ctx)?;
    let head = chrome::fill(&mut c, chrome::Part::Head);
    let header = chrome::fill(&mut c, chrome::Part::Header);
    c.images = body_ctx.images.clone();
    let footer = chrome::fill(&mut c, chrome::Part::Footer);
    Some([head, header, body_ctx.out, footer])
}

/// A page of another area (catalog, cart, ...) inside this page's chrome: what WordPress served for the
/// scscpq templates, with `body` in place of the template's placeholder div.
pub fn shell(site: &Site, page: &Page, uri: &str, body: &str) -> String {
    let mut c = Ctx::new(site, page, uri);
    c.set_phase(Phase::Header);
    let head = chrome::fill(&mut c, chrome::Part::Head);
    let header = chrome::fill(&mut c, chrome::Part::Header);
    c.set_phase(Phase::After);
    let footer = chrome::fill(&mut c, chrome::Part::Footer);
    [head, header, body.to_string(), footer].concat()
}

/// Probe-mode body: the skeleton and the slot of each sentinel.
pub fn probe_body(site: &Site, page: &Page, uri: &str) -> Option<(String, Vec<Slot>)> {
    let mut c = Ctx::probing(site, page, uri);
    body(&mut c)?;
    Some(c.into_slots())
}

/// Probe-mode chrome part, for recovering the site-wide values (menu labels, footer texts) it prints.
pub fn probe_chrome(site: &Site, page: &Page, uri: &str, part: chrome::Part) -> (String, Vec<Slot>) {
    let mut c = Ctx::probing(site, page, uri);
    let text = chrome::fill(&mut c, part);
    (text, c.slots.unwrap_or_default())
}

pub use chrome::{with_current_script_version, Part, CHROME_PLACEHOLDERS};
