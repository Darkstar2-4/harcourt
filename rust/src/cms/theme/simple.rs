//! The one-section templates (thank-you, FAQ), transcribed from the momentum-custom theme like templates.rs.

use super::{templates::cta, Ctx, Slot};

/// layouts/template-thank-you.php. The style block it prints after get_footer() is the page's `after_html`.
pub fn thank_you(c: &mut Ctx) {
    c.push("<div class=\"black-radial-gradient\">\n\t\n<section class=\"site-padding thank-section ");
    if c.notice() {
        c.push("add-margin");
    }
    c.push("\" style=\"background-image:url('");
    let thumb = c.image_url("@thumbnail");
    c.push(&thumb);
    c.push("')\">\n  <div class=\"container\">\n    <div class=\"row\">\n      <div class=\"col-lg-8 mx-auto text-center\">\n\n        <h1>");
    c.the_title(c.page.id);
    c.push("</h1>\n        <div class=\"success\">");
    c.html("content");
    c.push("</div>\n\n      </div>\n    </div>\n  </div>\n</section>\n\n</div>\n");
}

/// layouts/template-faq.php. Each section lists the FAQ posts of its faq_type term; that list is kept with the
/// page as `faq_sections_N_questions`, as the page showed it. The icon is an ACF Font Awesome field: its stored
/// value is the class, printed as an `<i>` element.
pub fn faq(c: &mut Ctx) {
    c.push("\n<section class=\"site-padding-bl mb-5 ");
    if c.notice() {
        c.push("add-margin");
    }
    c.push("\">\n  <div class=\"container\">\n    <div class=\"row\">\n      <div class=\"mb-4 col-lg-12\">\n        <div class=\"breadcrumb-section\">\n          ");
    c.breadcrumbs();
    c.push("        </div>\n        <h1 class=\"hc-title\"> ");
    c.the_title(c.page.id);
    c.push(" </h1>\n      </div>\n    </div>\n  </div>\n</section>\n\n<section class=\"faq-section site-padding-tl\">\n  <div class=\"container\">\n    <div class=\"row\">\n\n      ");
    for i in 0..c.count("faq_sections") {
        c.push("<div class=\"col-lg-6 mb-5\"><h3>");
        let icon = c.field(&format!("faq_sections_{i}_icon")).map(|f| f.value.clone()).unwrap_or_default();
        c.push("<i class=\"");
        c.text(&icon);
        c.push("\" aria-hidden=\"true\"></i> ");
        c.html(&format!("faq_sections_{i}_title"));
        c.push("</h3><hr>");
        c.slot(Slot::Field(c.page.id, format!("faq_sections_{i}_questions")));
        c.push("</div>");
    }
    c.push("\n    </div>\n  </div>\n</section>\n\n<section class=\"black-radial-gradient site-padding-bl\">\n  ");
    cta(c);
    c.push("\n</section>\n\n");
}
