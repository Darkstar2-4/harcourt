//! The singles of the post types other than pages, transcribed like templates.rs.

use super::{templates::sidebar, Ctx};
use crate::cms::model::{Lang, PostType, Status, Taxonomy};

/// post_class() of a post: its id, type, status and terms.
fn post_class(c: &Ctx) -> String {
    let p = c.page;
    let kind = p.kind.as_str();
    let mut classes = format!("post-{} {kind} type-{kind} status-publish hentry", p.id.0);
    for t in c.site.terms.iter().filter(|t| t.pages.contains(&p.id)) {
        classes.push_str(&format!(" {}-{}", t.taxonomy.as_str(), t.slug));
    }
    classes
}

/// single-faq.php around loops/single-post-faq.php: the FAQ, then a list of the FAQs of its first type in the
/// page's language, newest first, and the FAQ widget area.
pub(super) fn single_faq(c: &mut Ctx) {
    let fr = c.lang() == Lang::Fr;
    let id = c.page.id;
    if c.field("@thumbnail").is_some_and(|f| super::truthy(&f.value)) {
        c.unported("a featured image on an FAQ");
    }
    c.push("\n<main class=\"container\">\n  <div class=\"site-padding ");
    if c.notice() {
        c.push("add-margin");
    }
    c.push("\">\n    <div class=\"row\">\n\n      <div class=\"col-sm pr-5\">\n        <div id=\"content\" role=\"main\">\n          \n    <article itemscope itemtype=\"http://schema.org/Article\" role=\"article\" id=\"post_");
    let class = post_class(c);
    c.push(&format!("{}\" class=\"{class}\">\n\n    <div class=\"breadcrumb-section mb-4 border-bottom\">\n      ", id.0));
    c.breadcrumbs();
    c.push("    </div>\n\n    <h1 class=\"hc-title\"> ");
    c.the_title(id);
    c.push(" </h1>\n    <div class=\"header-meta text-muted\">\n          </div>\n\n    <main itemprop=\"articleBody\">\n\n      ");
    c.html("content");
    c.push("    </main>\n\n          <div class=\"faq-select site-padding-tl\">\n\n        <h4>");
    c.push(if fr {
        "Vous ne trouvez pas ce que vous cherchez ? Parcourez les autres FAQ sur ce sujet."
    } else {
        "Can't find what you're looking? Try browsing other FAQs for this topic."
    });
    c.push("</h4>\n\n        <div class=\"d-flex\">\n          <select id=\"faqSelect\" name=\"faq.select\">\n            <option value=\"\">");
    c.push(if fr { "Voir toutes les FAQ" } else { "View All FAQs" });
    c.push("</option>\n            ");
    let site = c.site;
    let term = site.terms.iter().find(|t| t.taxonomy == Taxonomy::FaqType && t.pages.contains(&id));
    let mut faqs: Vec<_> = term
        .map(|t| t.pages.iter().filter_map(|p| site.page(*p)).filter(|p| p.kind == PostType::Faq && p.status == Status::Publish && p.lang == c.lang()).collect())
        .unwrap_or_default();
    faqs.sort_by(|a, b| (b.published_at.as_str(), b.id).cmp(&(a.published_at.as_str(), a.id)));
    for f in faqs {
        c.push(&format!("<option value='{}'>", f.name));
        c.the_title(f.id);
        c.push("</option>");
    }
    c.push("          </select>\n          <a class=\"standard-btn red mt-0 ml-2 disabled\" id=\"selectButton\" href=\"/faq\">Go <i class=\"fa&nbsp;fa-angle-right\"><!--icon--></i></a>\n        </div>\n      </div>\n\n      <style media=\"screen\">\n      .faq-select select.form-control:not([size]):not([multiple]) { height: 71px; padding: 15px; }\n      .faq-select .standard-btn { width: 80px; }\n      </style>\n    \n  </article>\n          </div><!-- /#content -->\n      </div>\n\n      ");
    sidebar(c);
    c.push("\n    </div><!-- /.row -->\n  </div><!-- /.row -->\n</main><!-- /.container -->\n\n");
}
