//! What WordPress held for the public site (pages and their translations, ACF fields, menus, site-wide options,
//! media, redirects) and the code that renders it in the momentum-custom theme's markup, so no page needs
//! WordPress at runtime. `import` fills the tables from a WordPress dump and the static export; `theme` renders;
//! `edit` is what the staff dashboard calls to change page text and banners, keeping every save as a revision.
//!
//! # Forms (agreed with the orders area)
//!
//! Rendered pages mark each Gravity Form with `{{gform:N}}`, in render order, and list the forms in the page's
//! `islands` meta with the arguments WordPress embedded them with ([`FormEmbed`]). The orders area owns Gravity
//! Forms and provides, in `crate::forms`:
//!
//! ```ignore
//! /// The form's markup for this page: what GFForms::get_form() printed there (wrapper div through the closing
//! /// script), or None when the form is not active. Never the theme, never <html>.
//! pub async fn embed(core: &crate::api::Auth, form: &crate::cms::FormEmbed<'_>) -> Option<String>;
//! ```
//!
//! Until that exists, [`forms::Islands`] asks the WordPress island endpoint (mu-plugins/hx-form-island.php), as
//! the static pages do today.

pub mod edit;
pub mod forms;
pub mod html;
pub mod import;
pub mod media;
pub mod model;
pub mod theme;

pub use model::*;

/// One Gravity Form on one page, as WordPress embedded it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct FormEmbed<'a> {
    pub form: u32,
    pub lang: Lang,
    /// The page's request URI as WordPress saw it (path plus query, `?lang=fr` on French pages): Gravity Forms
    /// uses it for the form action.
    pub page_uri: &'a str,
    pub ajax: bool,
    /// The `data-form-theme` value, e.g. "gravity-theme" or "legacy".
    pub theme: &'a str,
    /// The `gform_style_settings` JSON.
    pub styles: &'a str,
    /// Rendered inside post content, where WordPress rewrites `]]>` as `]]&gt;`.
    pub in_content: bool,
}
