//! The content model and the in-memory snapshot pages render from. Database text is parsed into these types once,
//! when a snapshot loads; a row this code cannot parse is skipped and logged, never rendered.

use std::{
    collections::{BTreeMap, HashMap},
    sync::Arc,
};

use serde::{Deserialize, Serialize};
use sqlx::SqlitePool;
use tokio::sync::RwLock;

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, PartialOrd, Ord)]
pub enum Lang {
    En,
    Fr,
}

impl Lang {
    pub const ALL: [Lang; 2] = [Lang::En, Lang::Fr];

    pub fn code(self) -> &'static str {
        match self {
            Lang::En => "en",
            Lang::Fr => "fr",
        }
    }

    pub fn parse(s: &str) -> Option<Lang> {
        match s {
            "en" => Some(Lang::En),
            "fr" => Some(Lang::Fr),
            _ => None,
        }
    }

    /// The query string WPML appends to a French URL. English URLs carry none.
    pub fn query(self) -> &'static str {
        match self {
            Lang::En => "",
            Lang::Fr => "?lang=fr",
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, PartialOrd, Ord, Serialize, Deserialize)]
pub struct PageId(pub i64);

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Serialize, Deserialize)]
pub struct MediaId(pub i64);

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Status {
    Publish,
    Draft,
}

impl Status {
    pub fn as_str(self) -> &'static str {
        match self {
            Status::Publish => "publish",
            Status::Draft => "draft",
        }
    }

    pub fn parse(s: &str) -> Option<Status> {
        match s {
            "publish" => Some(Status::Publish),
            "draft" => Some(Status::Draft),
            _ => None,
        }
    }
}

/// WordPress's public post types on this site, in the order of their names, which is the order MySQL's
/// type_status_date index reads them in.
#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord, Hash)]
pub enum PostType {
    CaseStudy,
    Faq,
    Page,
    Post,
    Testimonials,
}

impl PostType {
    pub fn as_str(self) -> &'static str {
        match self {
            PostType::CaseStudy => "case_study",
            PostType::Faq => "faq",
            PostType::Page => "page",
            PostType::Post => "post",
            PostType::Testimonials => "testimonials",
        }
    }

    pub fn parse(s: &str) -> Option<PostType> {
        [PostType::CaseStudy, PostType::Faq, PostType::Page, PostType::Post, PostType::Testimonials].into_iter().find(|t| t.as_str() == s)
    }

    /// The path prefix of the type's permalinks (its rewrite slug); "" for pages and blog posts.
    pub fn base(self) -> &'static str {
        match self {
            PostType::CaseStudy => "/case-study",
            PostType::Faq => "/faq",
            PostType::Testimonials => "/testimonials",
            PostType::Page | PostType::Post => "",
        }
    }
}

/// How the theme uses a field value. Only `Html` values are page text that staff edit.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "lowercase")]
pub enum Kind {
    /// Text or markup, printed exactly as stored.
    Html,
    /// ACF true/false: "1" or "".
    Flag,
    /// Number of rows of a repeater or flexible content field.
    Count,
    /// Attachment id, or "".
    Media,
    /// A select, radio or checkbox value, or another raw value the template reads.
    Choice,
    /// ACF link: JSON {"url", "title", "target"}.
    Link,
}

impl Kind {
    pub fn as_str(self) -> &'static str {
        match self {
            Kind::Html => "html",
            Kind::Flag => "flag",
            Kind::Count => "count",
            Kind::Media => "media",
            Kind::Choice => "choice",
            Kind::Link => "link",
        }
    }

    pub fn parse(s: &str) -> Option<Kind> {
        Some(match s {
            "html" => Kind::Html,
            "flag" => Kind::Flag,
            "count" => Kind::Count,
            "media" => Kind::Media,
            "choice" => Kind::Choice,
            "link" => Kind::Link,
            _ => return None,
        })
    }
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct Field {
    pub kind: Kind,
    pub value: String,
}

pub type Fields = BTreeMap<String, Field>;

/// The theme's page templates, by the file name WordPress records in `_wp_page_template`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Template {
    /// page.php: no template recorded, or "default".
    Page,
    Builder,
    About,
    Career,
    Contact,
    ContactFrance,
    ContactIndia,
    ContactUk,
    Digest,
    Faq,
    History,
    Home,
    Locations,
    ProductBoxjoint,
    ProductSingle,
    Request,
    /// single.php: blog posts, case studies, testimonials.
    Single,
    /// single-faq.php.
    SingleFaq,
    Scscpq,
    ScscpqBeta,
    ScscpqCart,
    ScscpqCustomerPortal,
    ScscpqDashboard,
    ThankYou,
    Why,
}

const TEMPLATE_FILES: [(Template, &str); 24] = [
    (Template::Single, "single.php"),
    (Template::SingleFaq, "single-faq.php"),
    (Template::Builder, "builder-template.php"),
    (Template::About, "layouts/template-about.php"),
    (Template::Career, "layouts/template-career.php"),
    (Template::Contact, "layouts/template-contact.php"),
    (Template::ContactFrance, "layouts/template-contact-france.php"),
    (Template::ContactIndia, "layouts/template-contact-india.php"),
    (Template::ContactUk, "layouts/template-contact-uk.php"),
    (Template::Digest, "layouts/template-digest.php"),
    (Template::Faq, "layouts/template-faq.php"),
    (Template::History, "layouts/template-history.php"),
    (Template::Home, "layouts/template-home.php"),
    (Template::Locations, "layouts/template-locations.php"),
    (Template::ProductBoxjoint, "layouts/template-product-boxjoint.php"),
    (Template::ProductSingle, "layouts/template-product-single.php"),
    (Template::Request, "layouts/template-request.php"),
    (Template::Scscpq, "layouts/template-scscpq.php"),
    (Template::ScscpqBeta, "layouts/template-scscpq_beta.php"),
    (Template::ScscpqCart, "layouts/template-scscpq-cart.php"),
    (Template::ScscpqCustomerPortal, "layouts/template-scscpq-customer_portal.php"),
    (Template::ScscpqDashboard, "layouts/template-scscpq-dashboard.php"),
    (Template::ThankYou, "layouts/template-thank-you.php"),
    (Template::Why, "layouts/template-why.php"),
];

impl Template {
    pub fn parse(file: &str) -> Option<Template> {
        if file.is_empty() || file == "default" {
            return Some(Template::Page);
        }
        TEMPLATE_FILES.iter().find(|(_, f)| *f == file).map(|(t, _)| *t)
    }

    /// A WordPress shell another area fills in the browser (scscpq_wp.js): WordPress read nothing from its query.
    pub fn is_scscpq_shell(self) -> bool {
        matches!(self, Template::Scscpq | Template::ScscpqBeta | Template::ScscpqCustomerPortal | Template::ScscpqDashboard)
    }

    /// The file name, "" for page.php.
    pub fn file(self) -> &'static str {
        TEMPLATE_FILES.iter().find(|(t, _)| *t == self).map(|(_, f)| *f).unwrap_or("")
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Page {
    pub id: PageId,
    pub kind: PostType,
    /// The slug (post_name).
    pub name: String,
    pub group_id: i64,
    pub lang: Lang,
    pub path: String,
    pub template: Template,
    pub status: Status,
    pub title: String,
    pub parent_id: Option<PageId>,
    pub menu_order: i64,
    pub published_at: String,
    pub modified_at: String,
    pub author_id: Option<i64>,
}

impl Page {
    /// The URL WordPress links this page by.
    pub fn url(&self, origin: &str) -> String {
        format!("{origin}{}{}", self.path, self.lang.query())
    }
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct MediaSize {
    pub name: String,
    pub file: String,
    pub width: i64,
    pub height: i64,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Media {
    pub id: MediaId,
    /// Under wp-content/uploads.
    pub file: String,
    pub mime: String,
    pub width: i64,
    pub height: i64,
    pub alt: String,
    pub sizes: Vec<MediaSize>,
    /// The attachment's slug (post_name).
    pub name: String,
    /// The post it is attached to.
    pub parent_id: Option<PageId>,
    /// WPML's language and translation group, when WPML records them.
    pub lang: Option<Lang>,
    pub group_id: Option<i64>,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Menu {
    Main,
    Footer,
}

impl Menu {
    pub fn as_str(self) -> &'static str {
        match self {
            Menu::Main => "main",
            Menu::Footer => "footer",
        }
    }

    pub fn parse(s: &str) -> Option<Menu> {
        match s {
            "main" => Some(Menu::Main),
            "footer" => Some(Menu::Footer),
            _ => None,
        }
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct MenuItem {
    pub id: i64,
    pub menu: Menu,
    pub lang: Lang,
    pub parent_id: Option<i64>,
    pub position: i64,
    pub label: String,
    pub url: String,
    pub page_id: Option<PageId>,
    pub classes: String,
    pub target: String,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Redirect {
    pub target: String,
    pub status: u16,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord, Hash)]
pub enum Taxonomy {
    Category,
    FaqType,
}

impl Taxonomy {
    pub fn as_str(self) -> &'static str {
        match self {
            Taxonomy::Category => "category",
            Taxonomy::FaqType => "faq_type",
        }
    }

    pub fn parse(s: &str) -> Option<Taxonomy> {
        [Taxonomy::Category, Taxonomy::FaqType].into_iter().find(|t| t.as_str() == s)
    }

    /// The post type the taxonomy files.
    pub fn of(self) -> PostType {
        match self {
            Taxonomy::Category => PostType::Post,
            Taxonomy::FaqType => PostType::Faq,
        }
    }
}

/// A blog category or FAQ type and the posts filed under it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Term {
    pub id: i64,
    pub taxonomy: Taxonomy,
    pub slug: String,
    pub name: String,
    pub lang: Option<Lang>,
    pub group_id: Option<i64>,
    /// Yoast's lastmod for the term: the newest change to anything filed under it.
    pub modified_at: String,
    pub pages: Vec<PageId>,
}

impl Term {
    pub fn path(&self) -> String {
        format!("/{}/{}/", self.taxonomy.as_str(), self.slug)
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Author {
    pub id: i64,
    pub nicename: String,
    pub display_name: String,
    /// Unix seconds.
    pub profile_updated: i64,
}

/// Everything the public pages render from, loaded at one content version.
#[derive(Debug, Default)]
pub struct Site {
    pub version: i64,
    pub pages: Vec<Page>,
    by_id: HashMap<PageId, usize>,
    by_path: HashMap<(Lang, String), usize>,
    pub fields: HashMap<PageId, Fields>,
    pub meta: HashMap<PageId, BTreeMap<String, String>>,
    pub options: HashMap<Lang, Fields>,
    /// Ordered by menu, language and position.
    pub menus: Vec<MenuItem>,
    pub media: HashMap<MediaId, Media>,
    pub redirects: HashMap<String, Redirect>,
    /// Ordered by taxonomy and name.
    pub terms: Vec<Term>,
    pub authors: HashMap<i64, Author>,
}

static EMPTY_FIELDS: Fields = BTreeMap::new();
static EMPTY_META: BTreeMap<String, String> = BTreeMap::new();

impl Site {
    pub fn new(pages: Vec<Page>) -> Site {
        let mut site = Site { pages, ..Site::default() };
        site.index();
        site
    }

    fn index(&mut self) {
        self.by_id = self.pages.iter().enumerate().map(|(i, p)| (p.id, i)).collect();
        self.by_path = self.pages.iter().enumerate().map(|(i, p)| ((p.lang, p.path.clone()), i)).collect();
    }

    pub fn page(&self, id: PageId) -> Option<&Page> {
        self.by_id.get(&id).map(|&i| &self.pages[i])
    }

    pub fn find(&self, lang: Lang, path: &str) -> Option<&Page> {
        self.by_path.get(&(lang, path.to_string())).map(|&i| &self.pages[i])
    }

    /// The page of `page`'s translation group in `lang`, published or not.
    pub fn translation<'a>(&'a self, page: &'a Page, lang: Lang) -> Option<&'a Page> {
        if page.lang == lang {
            return Some(page);
        }
        self.pages.iter().find(|p| p.group_id == page.group_id && p.lang == lang)
    }

    pub fn fields(&self, id: PageId) -> &Fields {
        self.fields.get(&id).unwrap_or(&EMPTY_FIELDS)
    }

    pub fn meta(&self, id: PageId) -> &BTreeMap<String, String> {
        self.meta.get(&id).unwrap_or(&EMPTY_META)
    }

    pub fn options(&self, lang: Lang) -> &Fields {
        self.options.get(&lang).unwrap_or(&EMPTY_FIELDS)
    }

    /// Ancestors from the root down, not including `page`.
    pub fn ancestors(&self, page: &Page) -> Vec<&Page> {
        let mut out = Vec::new();
        let mut next = page.parent_id;
        while let Some(id) = next {
            let Some(p) = self.page(id) else { break };
            if p.id == page.id || out.iter().any(|q: &&Page| q.id == p.id) {
                break;
            }
            out.push(p);
            next = p.parent_id;
        }
        out.reverse();
        out
    }

    pub fn menu(&self, menu: Menu, lang: Lang) -> impl Iterator<Item = &MenuItem> {
        self.menus.iter().filter(move |m| m.menu == menu && m.lang == lang)
    }

    /// Reads every cms table. Rows that do not parse are logged and left out.
    pub async fn load(pool: &SqlitePool) -> sqlx::Result<Site> {
        let version = version(pool).await?;
        let mut pages = Vec::new();
        for r in sqlx::query!(
            r#"SELECT id AS "id!", post_type, name, group_id, lang, path, template, status, title, parent_id, menu_order, published_at, modified_at, author_id
               FROM cms_page ORDER BY id"#
        )
        .fetch_all(pool)
        .await?
        {
            let (Some(kind), Some(lang), Some(template), Some(status)) =
                (PostType::parse(&r.post_type), Lang::parse(&r.lang), Template::parse(&r.template), Status::parse(&r.status))
            else {
                tracing::warn!("cms_page {} has an unknown type, language, template or status; not served", r.id);
                continue;
            };
            pages.push(Page {
                id: PageId(r.id),
                kind,
                name: r.name,
                group_id: r.group_id,
                lang,
                path: r.path,
                template,
                status,
                title: r.title,
                parent_id: r.parent_id.map(PageId),
                menu_order: r.menu_order,
                published_at: r.published_at,
                modified_at: r.modified_at,
                author_id: r.author_id,
            });
        }
        let mut site = Site::new(pages);
        site.version = version;
        for r in sqlx::query!("SELECT page_id, name, kind, value FROM cms_field").fetch_all(pool).await? {
            if let Some(kind) = Kind::parse(&r.kind) {
                site.fields.entry(PageId(r.page_id)).or_default().insert(r.name, Field { kind, value: r.value });
            }
        }
        for r in sqlx::query!("SELECT page_id, name, value FROM cms_page_meta").fetch_all(pool).await? {
            site.meta.entry(PageId(r.page_id)).or_default().insert(r.name, r.value);
        }
        for r in sqlx::query!("SELECT lang, name, kind, value FROM cms_option").fetch_all(pool).await? {
            if let (Some(lang), Some(kind)) = (Lang::parse(&r.lang), Kind::parse(&r.kind)) {
                site.options.entry(lang).or_default().insert(r.name, Field { kind, value: r.value });
            }
        }
        for r in sqlx::query!(
            r#"SELECT id AS "id!", menu, lang, parent_id, position, label, url, page_id, classes, target
               FROM cms_menu_item ORDER BY menu, lang, position, id"#
        )
        .fetch_all(pool)
        .await?
        {
            let (Some(menu), Some(lang)) = (Menu::parse(&r.menu), Lang::parse(&r.lang)) else { continue };
            site.menus.push(MenuItem {
                id: r.id,
                menu,
                lang,
                parent_id: r.parent_id,
                position: r.position,
                label: r.label,
                url: r.url,
                page_id: r.page_id.map(PageId),
                classes: r.classes,
                target: r.target,
            });
        }
        for r in sqlx::query!(r#"SELECT id AS "id!", file, mime, width, height, alt, sizes, name, parent_id, lang, group_id FROM cms_media"#)
            .fetch_all(pool)
            .await?
        {
            let sizes = serde_json::from_str(&r.sizes).unwrap_or_else(|e| {
                tracing::warn!("cms_media {} sizes: {e}", r.id);
                Vec::new()
            });
            site.media.insert(
                MediaId(r.id),
                Media {
                    id: MediaId(r.id),
                    file: r.file,
                    mime: r.mime,
                    width: r.width,
                    height: r.height,
                    alt: r.alt,
                    sizes,
                    name: r.name,
                    parent_id: r.parent_id.map(PageId),
                    lang: r.lang.as_deref().and_then(Lang::parse),
                    group_id: r.group_id,
                },
            );
        }
        for r in sqlx::query!("SELECT path, target, status FROM cms_redirect").fetch_all(pool).await? {
            if let Ok(status) = u16::try_from(r.status) {
                site.redirects.insert(r.path, Redirect { target: r.target, status });
            }
        }
        for r in sqlx::query!(r#"SELECT id AS "id!", taxonomy, slug, name, lang, group_id, modified_at FROM cms_term ORDER BY taxonomy, name, id"#).fetch_all(pool).await? {
            let Some(taxonomy) = Taxonomy::parse(&r.taxonomy) else { continue };
            let lang = r.lang.as_deref().and_then(Lang::parse);
            site.terms.push(Term { id: r.id, taxonomy, slug: r.slug, name: r.name, lang, group_id: r.group_id, modified_at: r.modified_at, pages: Vec::new() });
        }
        for r in sqlx::query!("SELECT term_id, page_id FROM cms_term_page ORDER BY term_id, page_id").fetch_all(pool).await? {
            if let Some(t) = site.terms.iter_mut().find(|t| t.id == r.term_id) {
                t.pages.push(PageId(r.page_id));
            }
        }
        for r in sqlx::query!(r#"SELECT id AS "id!", nicename, display_name, profile_updated FROM cms_author"#).fetch_all(pool).await? {
            site.authors.insert(r.id, Author { id: r.id, nicename: r.nicename, display_name: r.display_name, profile_updated: r.profile_updated });
        }
        Ok(site)
    }
}

/// The content version: the newest revision id. Every import and every save adds a revision.
pub async fn version(pool: &SqlitePool) -> sqlx::Result<i64> {
    sqlx::query_scalar!(r#"SELECT coalesce(max(id), 0) AS "v!: i64" FROM cms_revision"#).fetch_one(pool).await
}

/// The snapshot pages render from, reloaded when the content version moves.
pub struct Content {
    pool: SqlitePool,
    current: RwLock<Option<Arc<Site>>>,
}

impl Content {
    pub fn new(pool: SqlitePool) -> Content {
        Content { pool, current: RwLock::new(None) }
    }

    pub fn pool(&self) -> &SqlitePool {
        &self.pool
    }

    pub async fn site(&self) -> sqlx::Result<Arc<Site>> {
        let v = version(&self.pool).await?;
        if let Some(site) = self.current.read().await.as_ref().filter(|s| s.version == v) {
            return Ok(site.clone());
        }
        let mut slot = self.current.write().await;
        if let Some(site) = slot.as_ref().filter(|s| s.version == v) {
            return Ok(site.clone());
        }
        let site = Arc::new(Site::load(&self.pool).await?);
        *slot = Some(site.clone());
        Ok(site)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn templates_round_trip_through_their_file_names() {
        for (t, f) in TEMPLATE_FILES {
            assert_eq!(Template::parse(f), Some(t));
            assert_eq!(t.file(), f);
        }
        assert_eq!(Template::parse(""), Some(Template::Page));
        assert_eq!(Template::parse("default"), Some(Template::Page));
        assert_eq!(Template::parse("layouts/template-blog.php"), None);
    }

    #[test]
    fn ancestors_stop_at_a_cycle() {
        let page = |id: i64, parent: Option<i64>| Page {
            id: PageId(id),
            kind: PostType::Page,
            name: String::new(),
            group_id: id,
            lang: Lang::En,
            path: format!("/{id}/"),
            template: Template::Page,
            status: Status::Publish,
            title: String::new(),
            parent_id: parent.map(PageId),
            menu_order: 0,
            published_at: String::new(),
            modified_at: String::new(),
            author_id: None,
        };
        let site = Site::new(vec![page(1, Some(3)), page(2, Some(1)), page(3, Some(2))]);
        let ids: Vec<i64> = site.ancestors(site.page(PageId(3)).unwrap()).iter().map(|p| p.id.0).collect();
        assert_eq!(ids, vec![1, 2]);
    }
}
