//! builder-template.php: the page builder, an ACF flexible content field (`page_builder`) whose rows each print
//! one part of builder-page/. Transcribed like templates.rs; the theme uses PHP short tags (`<? endif; ?>`), which
//! prod runs as code. The carousel scripts it prints after get_footer() are the page's `after_html`.

use super::{templates::cta, Ctx, Slot};
use crate::cms::model::Kind;

pub fn builder(c: &mut Ctx) {
    c.push("\n<main>\n\n  ");
    let layouts: Vec<String> = serde_json::from_str(c.choice("page_builder")).unwrap_or_default();
    for (i, layout) in layouts.iter().enumerate() {
        let row = Row { i };
        match layout.as_str() {
            "two_column" => two_column(c, &row),
            "grid" => grid(c, &row),
            "list_column" => list_column(c, &row),
            "custom_html" => custom_html(c, &row),
            "cta" => builder_cta(c, &row),
            other => c.unported(&format!("builder layout {other}")),
        }
    }
    c.push("\n          </main>\n\n");
}

/// One row of the flexible content: its sub-fields are stored as `page_builder_N_name`.
struct Row {
    i: usize,
}

impl Row {
    fn f(&self, name: &str) -> String {
        format!("page_builder_{}_{name}", self.i)
    }

    /// A true/false sub-field, or ACF's default_value for it when the row was saved without it (conditional
    /// logic hid it in the editor).
    fn flag_or(&self, c: &Ctx, name: &str, default: bool) -> bool {
        c.field(&self.f(name)).map_or(default, |f| super::truthy(&f.value))
    }
}

/// `<img src="<?php echo esc_url($image['url']) ?>" alt="<?php echo esc_attr($image['alt']) ?>"` and `after`, when
/// the ACF image is set. The alt is kept with the page as `NAME_alt`, as printed: on a French page WPML swaps in the
/// translated attachment, whose alt is French.
fn img(c: &mut Ctx, name: &str, before: &str, after: &str) {
    let Some(m) = c.media(name) else { return };
    c.push(&format!("{before}<img src=\"{}\" alt=\"", Ctx::media_url(Some(m))));
    c.slot(Slot::Field(c.page.id, format!("{name}_alt")));
    c.push(after);
}

fn breadcrumb_block(c: &mut Ctx, open: &str, close: &str) {
    c.push(open);
    c.breadcrumbs();
    c.push(close);
}

/// builder-page/part-two-column.php
fn two_column(c: &mut Ctx, row: &Row) {
    let position = c.choice(&row.f("content_position")).to_string();
    let background = c.choice(&row.f("background")).to_string();
    let align = c.choice(&row.f("content_align")).to_string();
    let padding = row.flag_or(c, "section_padding", true);
    let smaller = c.flag(&row.f("smaller_image"));
    let contained = row.flag_or(c, "image_contained", true);
    let harcourt = background == "bg-harcourt";
    c.push("\n<section class=\"builder-block block-two-column ");
    c.push(if padding { "site-padding-60" } else { "no-padding" });
    c.push(" ");
    if smaller {
        c.push("smaller-image");
    }
    c.push(&format!(" {position} {background} "));
    if c.notice() {
        c.push("add-margin");
    }
    c.push("\" ");
    if contained {
        c.push("style=\"padding: 6rem 0;\"");
    }
    c.push(">\n");
    if harcourt {
        c.push("<div class=\"background-overlay ");
        if !padding {
            c.push("no-padding");
        }
        c.push("\">");
    }
    c.push("  <div class=\"container\">\n    <div class=\"row\" ");
    match (contained, smaller) {
        (false, true) => c.push("style=\"height: 550px;\""),
        (false, false) => c.push("style=\"height: 800px;\""),
        _ => {}
    }
    c.push(">\n      <div data-aos-duration=\"1500\" data-aos=\"fade-");
    c.push(if position == "left" { "right" } else { "left" });
    c.push("\" class=\"content-half col col-12 col-lg-6\">\n          ");
    if c.flag(&row.f("breadcrumbs")) {
        breadcrumb_block(c, &format!("            <div class=\"breadcrumb-section text-{align}\">\n              "), "            </div>\n          ");
    }
    c.push(&format!("          <div class=\"column-content-section text-{align} "));
    if c.flag(&row.f("lower_content_section")) {
        c.push("lower-content");
    }
    c.push("\">\n            ");
    if c.flag(&row.f("subtitle")) {
        c.push("              <span class=\"red-headline mt-3\">");
        c.html(&row.f("subtitle"));
        c.push("</span>\n            ");
    }
    c.push("            ");
    if c.flag(&row.f("h1_tag")) {
        c.push(" \n              <h1>");
        c.html(&row.f("title"));
        c.push("</h1>\n            ");
    } else {
        c.push("              <h2>");
        c.html(&row.f("title"));
        c.push("</h2>\n            ");
    }
    c.push("            <div class=\"description mt-2\">\n              ");
    c.html(&row.f("content"));
    c.push("            </div>\n          </div>\n      </div>\n      <div data-aos-duration=\"1500\" data-aos=\"fade-");
    c.push(if position == "left" { "left" } else { "right" });
    c.push("\" class=\"image-half col col-12 col-lg-6 ");
    if !contained {
        c.push("image-overlap");
    }
    c.push("\">\n        ");
    if row.flag_or(c, "media_type", true) {
        c.push(" \n          ");
        img(c, &row.f("image"), "            ", "\" />\n          ");
        c.push("        ");
    } else {
        c.push("          ");
        if c.flag(&row.f("video_embed")) {
            c.push("            <div class=\"video-content\">");
            c.html(&row.f("video_embed"));
            c.push("</div>\n          ");
        }
        c.push("        ");
    }
    c.push("      </div>\n    </div>\n  </div>\n");
    if harcourt {
        c.push("</div>");
    }
    c.push("</section>");
}

/// builder-page/part-grid.php
fn grid(c: &mut Ctx, row: &Row) {
    let background = c.choice(&row.f("background")).to_string();
    let harcourt = background == "bg-harcourt";
    c.push(&format!("\n<section class=\"builder-block block-grid site-padding-60-bl {background}\">\n"));
    if harcourt {
        c.push("<div class=\"background-overlay\">");
    }
    c.push("  <div class=\"container");
    if c.flag(&row.f("full_width")) {
        c.push("-fluid");
    }
    c.push("\">\n    ");
    if row.flag_or(c, "content_above", true) {
        c.push(" \n      ");
        if c.flag(&row.f("breadcrumbs")) {
            breadcrumb_block(c, "        <div class=\"breadcrumb-section\">\n          ", "        </div>\n      ");
        }
        c.push("    <div class=\"above-content ");
        if c.flag(&row.f("center_above_content")) {
            c.push("text-center");
        }
        c.push("\">\n      ");
        if c.flag(&row.f("above_title")) {
            c.push("        ");
            let tag = if c.flag(&row.f("h1_tag")) { "h1" } else { "h2" };
            if tag == "h1" {
                c.push(" \n          <h1>");
            } else {
                c.push("          <h2>");
            }
            c.html(&row.f("above_title"));
            c.push(&format!("</{tag}>\n        "));
            c.push("      ");
        }
        c.push("      ");
        if c.flag(&row.f("above_description")) {
            c.push("        <p>");
            c.html(&row.f("above_description"));
            c.push("</p>\n      ");
        }
        c.push("    </div>\n    ");
    }
    c.push("    <div class=\"row align-items-start\">\n      ");
    let columns = c.field(&row.f("columns")).map_or("4".to_string(), |f| f.value.clone());
    let center = c.flag(&row.f("center_text"));
    let items = c.field(&row.f("grid_items")).and_then(|f| f.value.parse::<usize>().ok()).unwrap_or(0);
    for j in 0..items {
        let item = |n: &str| row.f(&format!("grid_items_{j}_{n}"));
        c.push(&format!("        <div class='grid-item-content col col-12 col-lg-{columns} "));
        if center {
            c.push("center-text");
        }
        c.push("'>\n        \n        ");
        let link = match c.field(&item("link")) {
            Some(f) if f.kind == Kind::Link => !c.link_value(&item("link"), "url").is_empty(),
            Some(f) => super::truthy(&f.value),
            None => false,
        };
        if link {
            c.push("<a class='grid-item-link' href='");
            c.html(&item("link"));
            c.push("'>");
        }
        c.push("\n        <div class='grid-image'>");
        img(c, &item("image"), "          ", "\" />\n        ");
        c.push("</div>        \n        ");
        if c.flag(&item("title")) {
            c.push("<h3>");
            c.html(&item("title"));
            c.push("</h3>");
        }
        c.push("\n        ");
        if c.flag(&item("content")) {
            c.push("<div class='grid-content'>");
            c.html(&item("content"));
            c.push("</div>");
        }
        c.push("\n        ");
        if link {
            c.push("</a>");
        }
        c.push("</div>");
    }
    c.push("    </div>\n  </div>\n  ");
    if harcourt {
        c.push("</div>");
    }
    c.push("</section>\n\n\n");
}

/// builder-page/part-list-column.php
fn list_column(c: &mut Ctx, row: &Row) {
    let position = c.choice(&row.f("content_position")).to_string();
    let background = c.choice(&row.f("background")).to_string();
    let align = c.choice(&row.f("content_align")).to_string();
    let left = position == "left";
    c.push(&format!("\n<section class=\"builder-block block-list-column why-configurator {position} {background}\">\n    <div class=\"container large\">\n        <div class=\"row align-items-center\">\n            <div data-aos-duration=\"1500\" data-aos=\"fade-"));
    c.push(if left { "left" } else { "right" });
    c.push(&format!("\" class=\"col-lg-5 py-4 py-lg-0 aos-init aos-animate text-{align} "));
    if !left {
        c.push("offset-md-1");
    }
    c.push("\">\n                ");
    if c.flag(&row.f("breadcrumbs")) {
        breadcrumb_block(c, &format!("                    <div class=\"breadcrumb-section text-{align}\">\n                        "), "                    </div>\n                ");
    }
    c.push("                ");
    if c.flag(&row.f("subtitle")) {
        c.push("                    <span class=\"red-headline mt-3\">");
        c.html(&row.f("subtitle"));
        c.push("</span>\n                ");
    }
    c.push("                ");
    if c.flag(&row.f("h1_tag")) {
        c.push(" \n                    <h1 class=\"mt-0\">");
        c.html(&row.f("title"));
        c.push("</h1>\n                ");
    } else {
        c.push("                    <h2 class=\"mt-0\">");
        c.html(&row.f("title"));
        c.push("</h2>\n                ");
    }
    c.push("                ");
    let items = c.field(&row.f("list_col_items")).and_then(|f| f.value.parse::<usize>().ok()).unwrap_or(0);
    if items > 0 {
        c.push("    \n                    <div class=\"icon-items\">\n                        ");
        for j in 0..items {
            let item = |n: &str| row.f(&format!("list_col_items_{j}_{n}"));
            c.push("                            ");
            c.push("                            <div class=\"icon-item d-flex\">\n                                <div class=\"image-container\">\n                                    ");
            img(c, &item("list_image"), "                                        ", "\" class=\"attachment-full size-full\" />\n                                    ");
            c.push("                                </div>\n                                <div class=\"content-container pl-5 pl-lg-0\">\n                                    <h3 class=\"mt-0\">");
            c.html(&item("list_title"));
            c.push("</h3>\n                                    <p class=\"mb-0\">");
            c.html(&item("list_description"));
            c.push("</p>\n                                </div>\n                            </div>\n                        ");
        }
        c.push("                    </div>\n                ");
    }
    c.push("            </div>\n            <div class=\"");
    if left {
        c.push("offset-md-1");
    }
    c.push(" col-lg-6 site-padding list-col\">\n                ");
    c.html(&row.f("custom_code"));
    c.push("            </div>\n        </div>\n    </div>\n</section>");
}

/// builder-page/part-custom-html.php
fn custom_html(c: &mut Ctx, row: &Row) {
    let background = c.choice(&row.f("background")).to_string();
    let harcourt = background == "bg-harcourt";
    c.push(&format!("\n<section class=\"builder-block block-custom-html site-padding-60 aos-init aos-animate {background} "));
    if c.notice() {
        c.push("add-margin");
    }
    c.push("\">\n");
    if harcourt {
        c.push("<div class=\"background-overlay\">");
    }
    c.push("    <div class=\"container\">\n        ");
    if c.flag(&row.f("breadcrumbs")) {
        breadcrumb_block(c, "            <div class=\"breadcrumb-section\">\n                ", "            </div>\n        ");
    }
    c.push("        <div class=\"custom-content\">\n            ");
    c.html(&row.f("custom_content"));
    c.push("        </div>\n  </div>\n");
    if harcourt {
        c.push("</div>");
    }
    c.push("</section>");
}

/// builder-page/part-cta.php: a custom call to action, or the theme's global one.
fn builder_cta(c: &mut Ctx, row: &Row) {
    c.push("\n");
    if !c.flag(&row.f("custom_cta")) {
        c.push("  <section class=\"product-cta black-radial-gradient\">\n    ");
        cta(c);
        c.push("  </section> \n");
        return;
    }
    let background = c.choice(&row.f("background")).to_string();
    c.push(&format!(" \n  <section class=\"builder-block block-call-to-action site-padding-60 {background}\" style=\"padding: 80px 15px;\">\n    <div class=\"container\">\n      <div class=\"row\">\n        <div class=\"cta-content-container\">\n          <div data-aos-offset=\"400\" data-aos-duration=\"1000\" data-aos=\"fade-up\" class=\"cta-content aos-init aos-animate\">\n            <div class=\"row\">\n              <div class=\"heading justify-content-center col-12 col-lg-3\">\n                "));
    if c.flag(&row.f("subtitle")) {
        c.push("                  <span class=\"red-headline\">");
        c.html(&row.f("subtitle"));
        c.push("</span>\n                ");
    }
    c.push("                <h2>");
    c.html(&row.f("title"));
    c.push("</h2>\n                <div class=\"cta-btn\">\n                ");
    let link = row.f("link");
    let url = c.link_value(&link, "url");
    if !url.is_empty() || !c.link_value(&link, "title").is_empty() {
        let target = c.link_value(&link, "target");
        let target = if target.is_empty() { "_self".to_string() } else { target };
        let title = c.link_value(&link, "title");
        let esc = super::super::html::esc;
        c.push(&format!("                  <a class=\"standard-btn red\" href=\"{}\" target=\"{}\">{}</a>\n                ", esc(&url), esc(&target), esc(&title)));
    }
    c.push("                </div>\n              </div>\n              <div class=\"cta-description col-12 col-lg-9\">\n                ");
    c.html(&row.f("description"));
    c.push("              </div>\n            </div>\n          </div>\n        </div>\n      </div>\n    </div>\n  </section>\n");
}
