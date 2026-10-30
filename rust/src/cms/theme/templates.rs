//! Page bodies, transcribed from the momentum-custom theme (wp-content/themes/momentum-custom). Each literal is the
//! PHP file's text with PHP's own rule applied: a newline right after `?>` is not printed.

use super::{Ctx, Slot};
use crate::cms::model::{Lang, PageId, Status, Template};

pub type Body = fn(&mut Ctx);

pub fn body_fn(t: Template) -> Option<Body> {
    Some(match t {
        Template::ProductSingle => product_single,
        Template::Scscpq => scscpq,
        Template::ScscpqBeta => scscpq_beta,
        Template::ScscpqCart => scscpq_cart,
        Template::ScscpqCustomerPortal => scscpq_customer_portal,
        Template::Digest => digest,
        Template::Page => default_page,
        Template::History => super::company::history,
        Template::Why => super::company::why,
        Template::About => super::company::about,
        Template::Career => super::company::career,
        Template::ThankYou => super::simple::thank_you,
        Template::Faq => super::simple::faq,
        Template::Contact => super::contact::contact,
        Template::ContactUk => super::contact::contact_uk,
        Template::ContactFrance | Template::ContactIndia => super::contact::contact_region,
        Template::Locations => super::contact::locations,
        Template::Home => super::home::home,
        Template::Builder => super::builder::builder,
        _ => return None,
    })
}

/// page.php
fn default_page(c: &mut Ctx) {
    c.push("\n<div class=\"site-padding ");
    if c.notice() {
        c.push("add-margin");
    }
    c.push("\">\n  <main class=\"container\">\n    <div class=\"row\">\n\n      <div class=\"col-lg-8 pr-lg-5\">\n        <div id=\"content\" role=\"main\">\n          ");
    page_content(c);
    c.push("        </div><!-- /#content -->\n      </div>\n\n      ");
    sidebar(c);
    c.push("\n    </div><!-- /.row -->\n  </main><!-- /.container -->\n</div><!-- /.row -->\n\n");
}

/// loops/page-content.php
fn page_content(c: &mut Ctx) {
    let id = c.page.id;
    let mut classes = format!("post-{} page type-page status-publish", id.0);
    if c.field("@thumbnail").is_some_and(|f| super::truthy(&f.value)) {
        classes.push_str(" has-post-thumbnail");
    }
    classes.push_str(" hentry");
    c.push(&format!("\n  <article role=\"article\" id=\"post_{}\" class=\"{classes}\">\n      <div class=\"breadcrumb-section mb-4 border-bottom\">\n        ", id.0));
    c.breadcrumbs();
    c.push("      </div>\n      <h1 class=\"hc-title\"> ");
    c.the_title(id);
    c.push(" </h1>\n    <div>\n      ");
    c.html("content");
    c.push("      </div>\n  </article>\n");
}

/// sidebar.php, for pages (FAQ posts use another widget area). The widgets of the page's widget area are stored
/// with the page as WordPress printed them: their menus mark the current page.
pub(super) fn sidebar(c: &mut Ctx) {
    let fr = c.lang() == Lang::Fr;
    c.push("<div class=\"sidebar col-lg-4\" id=\"sidebar\" role=\"navigation\">\n\n  <div class=\"sidebar-widget-container black-radial-gradient\">\n\n\n\n            ");
    let id = c.page.id;
    c.slot(Slot::Meta(id, "sidebar".into()));
    c.push("    \n  </div>\n\n    <div class=\"scscpq_newuser mt-4 red-radial-gradient text-center p-5\">\n      <h3 class=\"mt-0 mb-4\">");
    c.push(if fr { "Accédez à l'ensemble de notre catalogue" } else { "Get Access to Our Full Catalog" });
    c.push("</h3>\n      <a class=\"standard-btn ignore-hover white mt-0 w-100\" href=\"");
    c.push(super::ORIGIN);
    c.push(if fr { "/?lang=frrequest-access\">Demander l'accès" } else { "/request-access\">Request Access" });
    c.push("</a>\n    </div>\n  </div>\n\n\n\n");
}

/// The page the product configurator block reads its features from, in the page's language (content.php).
const WHY_PAGE: PageId = PageId(214);

/// layouts/sections/global/breadcrumbs.php
pub(super) fn breadcrumbs(c: &mut Ctx) {
    c.push("<div class=\"breadcrumb-section\">\n  ");
    c.breadcrumbs();
    c.push("</div>\n");
}

/// layouts/sections/global/cta.php
pub(super) fn cta(c: &mut Ctx) {
    c.push("\n<section class=\"cta-section scscpq_newuser\">\n\n  <div class=\"container\">\n\n    <div class=\"row\">\n\n      <div class=\"background-image has-overlay\">\n\n        <div class=\"cta-content-container\">\n\n          <div data-aos-offset=\"400\" data-aos-duration=\"1000\" data-aos=\"fade-up\" class=\"cta-content\">\n\n            <div class=\"heading d-flex align-items-center justify-content-center\">\n              <h2>");
    c.option_html("cta_title");
    c.push("</h2>\n              ");
    let logo = c.option_media("cta_logo");
    c.image(logo);
    c.push("            </div>\n\n            <ul class=\"list\">\n\n              ");
    let rows = c.option("cta_list").and_then(|f| f.value.parse::<usize>().ok()).unwrap_or(0);
    for i in 0..rows {
        c.push("<li>");
        c.option_html(&format!("cta_list_{i}_item"));
        c.push("</li>");
    }
    c.push("\n            </ul>\n\n            <div class=\"text-center\">\n                            ");
    if c.page.template == Template::Request {
        c.push("<a class=\"standard-btn red\" href=\"#signup\"> New User Sign Up </a>");
    } else {
        c.push("<a class=\"standard-btn red\" href=\"/request-access\"> ");
        c.option_html("cta_btn");
        c.push(" </a>");
    }
    c.push("\n              <!-- <button type=\"button\" class=\"standard-btn red\" data-toggle=\"modal\" data-target=\"#formPopout\">\n            </button> -->\n          </div>\n        </div>\n\n      </div>\n\n      <div data-aos-duration=\"1000\" data-aos=\"fade-up\" class=\"absolute-overlay overlay-gradient\"></div>\n\n      <div class=\"cta-img-bg\" data-aos-duration=\"1000\" data-aos=\"fade-up\">\n        ");
    let bg = c.option_media("cta_bg_img");
    c.image(bg);
    c.push("      </div>\n\n    </div>\n\n  </div>\n\n</div>\n\n</section>\n");
}

/// layouts/template-product-single.php
fn product_single(c: &mut Ctx) {
    product_hero(c);
    product_content(c);
    c.push(" <section class=\"product-cta black-radial-gradient\"> ");
    cta(c);
    c.push(" </section> ");
}

/// layouts/sections/product-single/hero.php
fn product_hero(c: &mut Ctx) {
    c.push("\n<section class=\"product-hero ");
    if c.notice() {
        c.push("add-margin");
    }
    c.push("\" style=\"background-image:url('");
    let bg = c.image_url("bg_image");
    c.push(&bg);
    c.push("')\">\n  <div class=\"background-overlay\">\n    <div class=\"container\">\n      <div class=\"row\">\n\n        <div data-aos-duration=\"1500\" data-aos=\"fade-right\" class=\"col-lg-6 content-half\">\n          <div class=\"site-padding pr-md-5\">\n            ");
    breadcrumbs(c);
    c.push("            <span class=\"red-headline mt-5\">");
    c.html("hero_headline");
    c.push("</span>\n            <h1 class=\"mt-5\">");
    c.html("hero_title");
    c.push("</h1>\n            <div class=\"description mt-2\">\n              ");
    c.html("hero_description");
    c.push("            </div>\n          </div>\n        </div>\n        <div class=\"col-lg-6 image-half\" data-aos-duration=\"1500\" data-aos=\"fade-left\">\n          ");
    let img = c.media("hero_image");
    c.image(img);
    c.push("        </div>\n\n      </div>\n    </div>\n  </div>\n</section>\n\n\n<style media=\"screen\">\n.product-hero .background-overlay { background: rgba(255,255,255,.95)}\n.product-hero .row { height: 680px;}\n.product-hero .image-half img { position: absolute; left: 0; top: 0; height: auto; max-width: none; }\n\n@media only screen and ( max-width : 991px ) {\n  .product-hero .content-half img {height: auto;}\n  .product-hero .image-half img { display: none; }\n}\n</style>\n");
}

/// layouts/sections/product-single/content.php
fn product_content(c: &mut Ctx) {
    c.push("<!-- Block One -->\n\n\n");
    if c.flag("block_one") {
        c.push("  <section class=\"product-block-one black-radial-gradient\">\n    <div class=\"container\">\n      <div class=\"row\">\n\n        <div class=\"col-lg-7 image-half\" data-aos-duration=\"2500\" data-aos=\"fade-right\">\n          ");
        let img = c.media("block_one_image");
        c.image(img);
        c.push("        </div>\n\n        <div data-aos-duration=\"1500\" data-aos=\"fade-left\" class=\"col-lg-5 content-half\">\n          <div class=\"site-padding-bl\">\n            <span class=\"red-headline mt-5\">");
        c.html("block_one_headline");
        c.push("</span>\n            <h1 class=\"mt-5\">");
        c.html("block_one_title");
        c.push("</h1>\n            <div class=\"description mt-2\">\n              ");
        c.html("block_one_description");
        c.push("            </div>\n          </div>\n        </div>\n\n      </div>\n    </div>\n  </section>\n");
    }
    c.push("\n<!-- Block Simple -->\n\n\n");
    if c.flag("block_video") {
        c.push("\n  \n  <section class=\"product-block-simple black-radial-gradient site-padding\">\n    <div class=\"container\">\n      <div class=\"row align-items-center\">\n\n        <div class=\"col-lg-8 mx-auto text-center\">\n          ");
        c.html("video_embed");
        c.push("        </div>\n\n      </div>\n    </div>\n  </section>\n\n");
    }
    c.push("\n<!-- Block configurator -->\n\n\n");
    if c.flag("product_config") {
        let why = c.site.page(WHY_PAGE).and_then(|p| c.site.translation(p, c.lang())).map(|p| p.id).unwrap_or(WHY_PAGE);
        c.push("\n\n  \n  <section class=\"why-configurator black-radial-gradient\">\n    <div class=\"container large\">\n\n      <div class=\"row align-items-center\">\n\n        <div data-aos-duration=\"1500\" data-aos=\"fade-right\" class=\"col-lg-5 py-4 py-lg-0\">\n          <h2 class=\"mt-0 white\">");
        c.slot(Slot::Field(why, "why_config_title".into()));
        c.push(" </h2>\n          <div class=\"icon-items\">\n            ");
        let rows = c.field_of(why, "why_config_features").and_then(|f| f.value.parse::<usize>().ok()).unwrap_or(0);
        for i in 0..rows {
            c.push("\n              <div class=\"icon-item d-flex\">\n                <div class=\"image-container\">\n                  ");
            let icon = c.media_of(why, &format!("why_config_features_{i}_icon"));
            c.image(icon);
            c.push("                </div>\n                <div class=\"content-container pl-5 pl-lg-0\">\n                  <h3 class=\"white mt-0\">");
            c.slot(Slot::Field(why, format!("why_config_features_{i}_title")));
            c.push("</h3>\n                  <p class=\"mb-0\">");
            c.slot(Slot::Field(why, format!("why_config_features_{i}_description")));
            c.push("</p>\n                </div>\n              </div>\n\n              ");
        }
        c.push("        </div>\n      </div>\n\n      <div class=\"offset-md-1 col-lg-6 site-padding parts-solution\">\n      </div>\n\n    </div>\n\n  </div>\n  </section>\n\n");
    }
    c.push("\n<!-- Block Simple -->\n\n\n");
    if c.flag("block_simple") {
        c.push("\n  \n  <section class=\"product-block-simple black-radial-gradient site-padding\">\n    <div class=\"container\">\n      <div class=\"row align-items-center\">\n\n        <div class=\"col-lg-7\" data-aos-duration=\"2500\" data-aos=\"fade-right\">\n          ");
        let img = c.media("block_simple_image");
        c.image(img);
        c.push("        </div>\n\n        <div data-aos-duration=\"1500\" data-aos=\"fade-left\" class=\"col-lg-5 pl-5 content-half\">\n          <div>\n            <span class=\"red-headline mt-5\">");
        c.html("block_simple_headline");
        c.push("</span>\n            <h1 class=\"mt-5\">");
        c.html("block_simple_title");
        c.push("</h1>\n            <div class=\"description mt-2\">\n              ");
        c.html("block_simple_description");
        c.push("            </div>\n          </div>\n        </div>\n\n      </div>\n    </div>\n  </section>\n\n");
    }
    c.push("\n<!-- Block Icons -->\n\n");
    if c.flag("block_icons") {
        c.unported("product-single block_icons");
    }
    c.push("\n\n<!-- Block two -->\n\n");
    if c.flag("block_two") {
        c.push("\n  \n  <section class=\"product-block-two\">\n    <div class=\"container\">\n      <div class=\"row\">\n        <div class=\"col-lg-12 text-center mb-5\">\n          <h2>");
        c.html("block_two_title");
        c.push("</h2>\n        </div>\n      </div>\n\n      <div class=\"row mt-5\">\n        ");
        for i in 0..c.count("block_two_items") {
            let classes = if i + 1 == 2 { "solution-item bordered" } else { "solution-item" };
            c.push(&format!("          <div class=\"col-lg-4 solution-item {classes}\">\n            "));
            let image = c.media(&format!("block_two_items_{i}_image"));
            if image.is_some() {
                c.push("              <div class=\"solution-image mb-4\">");
                c.image(image);
                c.push("</div>\n            ");
            }
            c.push("            <h3>");
            c.html(&format!("block_two_items_{i}_title"));
            c.push("</h3>\n            <p>");
            c.html(&format!("block_two_items_{i}_description"));
            c.push("</p>\n          </div>\n\n          ");
        }
        c.push("      </div>\n    </div>\n  </section>\n\n");
    }
    c.push("\n");
    if c.flag("catalog_bar") {
        c.unported("product-single catalog_bar");
    }
    c.push("\n");
    if c.flag("block_three") {
        c.unported("product-single block_three");
    }
}

/// layouts/template-scscpq.php: the shell other areas fill (catalog, cart, ...).
fn scscpq(c: &mut Ctx) {
    scscpq_shell(c, "   ", "catalog");
}

/// layouts/template-scscpq_beta.php
fn scscpq_beta(c: &mut Ctx) {
    scscpq_shell(c, "    ", "catalog");
}

/// layouts/template-scscpq-cart.php
fn scscpq_cart(c: &mut Ctx) {
    scscpq_shell(c, "    ", "cart");
}

/// layouts/template-scscpq-customer_portal.php
fn scscpq_customer_portal(c: &mut Ctx) {
    scscpq_shell(c, "     ", "customer_portal");
}

/// The scscpq templates differ only in their indent and the view they name.
fn scscpq_shell(c: &mut Ctx, indent: &str, view: &str) {
    c.push("\n");
    c.push(indent);
    c.push("<div ");
    if c.notice() {
        c.push("class=add-margin");
    }
    c.push(" id=scscpq_page>");
    c.push(view);
    c.push("</div>\n\n\n");
}

/// layouts/template-digest.php
fn digest(c: &mut Ctx) {
    c.push("\n<section class=\"digest-grid site-padding-bl ");
    if c.notice() {
        c.push("add-margin");
    }
    c.push("\">\n  <div class=\"container\">\n    <div class=\"row\">\n      <div class=\"col-lg-8\">\n        <div class=\"breadcrumb-section\">\n          ");
    c.breadcrumbs();
    c.push("        </div>\n        <h1>");
    let id = c.page.id;
    c.the_title(id);
    c.push("</h1>\n        ");
    c.html("content");
    c.push("      </div>\n    </div>\n  </div>\n</section>\n<hr>\n<section class=\"digest-grid site-padding\">\n  <div class=\"container\">\n    ");
    grid_items(c);
    c.push("  </div>\n</section>\n\n<section class=\"black-radial-gradient site-padding-bl\">\n  ");
    cta(c);
    c.push("</section>\n\n");
}

/// An ACF relationship value: a JSON list of ids, or one bare id (acf_get_array()).
pub(super) fn id_list(v: &str) -> Vec<i64> {
    match serde_json::from_str::<Vec<String>>(v) {
        Ok(list) => list.iter().filter_map(|s| s.parse().ok()).collect(),
        Err(_) => v.trim().parse().ok().into_iter().collect(),
    }
}

/// layouts/sections/global/grid-items.php
pub(super) fn grid_items(c: &mut Ctx) {
    c.push("\n<div class=\"grid-items\" id=\"");
    c.html("grid_id");
    c.push("\">\n  <div class=\"row ");
    if c.flag("scroll") {
        c.push("scroll");
    }
    c.push("\">\n\n    ");
    let ids = id_list(c.choice("grid_items"));
    let site = c.site;
    let lang = c.lang();
    // WPML runs the grid's queries in the page's language: ids of the other language stand for their translation.
    let in_lang = |id: i64| site.page(PageId(id)).and_then(|p| site.translation(p, lang)).map(|p| p.id).unwrap_or(PageId(id));
    let ids: Vec<PageId> = ids.into_iter().map(in_lang).collect();
    let items: Vec<PageId> = match c.choice("grid_type") {
        "select" => ids.iter().copied().filter(|&i| site.page(i).is_some_and(|p| p.status == Status::Publish)).collect(),
        "post" => {
            c.unported("grid-items post type grid");
            Vec::new()
        }
        _ => {
            let parent = ids.first().copied();
            let mut children: Vec<_> = site.pages.iter().filter(|p| p.parent_id.is_some() && p.parent_id == parent && p.status == Status::Publish && p.lang == lang).collect();
            children.sort_by_key(|p| p.menu_order);
            children.iter().map(|p| p.id).collect()
        }
    };
    for id in items {
        let cut_out = c.field_of(id, "cover_type").is_some_and(|f| f.value == "cut-out");
        let thumb = c.media_of(id, "@thumbnail");
        c.push("        <div class=\"col-lg-3\">\n          <div data-aos=\"fade-up\" data-aos-duration=\"1000\" class=\"grid-item\">\n\n            ");
        if cut_out {
            c.push("\n              <div class=\"grid-img\">\n                <div class=\"grid-img-container\">\n                  ");
            let tag = c.images.full(thumb, super::UPLOADS).replacen("class=\"attachment-full size-full\"", "class=\"attachment-full size-full wp-post-image\"", 1);
            c.push(&tag);
            c.push("                </div>\n              </div>\n\n            ");
        } else {
            c.push("\n              <div class=\"grid-img\" style=\"background-image:url(");
            c.push(&Ctx::media_url(thumb));
            c.push("); background-size: cover;\">\n                <div class=\"grid-img-container\">\n                </div>\n              </div>\n\n            ");
        }
        c.push("\n            <div class=\"grid-content\">\n              <h3>");
        c.the_title(id);
        c.push("</h3>\n              <p>");
        c.slot(Slot::Field(id, "excerpt".into()));
        c.push("</p>\n              <a class=\"inline-btn\" href=\"");
        let url = site.page(id).map(|p| p.url(super::ORIGIN)).unwrap_or_default();
        c.push(&url);
        c.push(if lang == Lang::Fr { "\">En savoir plus</a>" } else { "\">Learn More</a>" });
        c.push("\n            </div>\n          </div>\n        </div>\n        ");
    }
    c.push("  </div>\n</div>\n");
}
