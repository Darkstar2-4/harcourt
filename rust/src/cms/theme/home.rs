//! The front page, layouts/template-home.php (prod's copy) and its sections, transcribed like templates.rs. The
//! logo carousel script it prints after get_footer() is the page's `after_html`.

use super::{
    templates::{cta, grid_items},
    Ctx, Slot,
};
use crate::cms::model::Lang;

pub fn home(c: &mut Ctx) {
    let fr = c.lang() == Lang::Fr;
    hero(c);
    innovations(c);
    products(c);
    industries(c);
    tradition(c);
    c.push("\n<section class=\"resource-bar red-radial-gradient\">\n  <div class=\"container\">\n    <span data-aos-duration=\"1000\" data-aos=\"fade-right\" class=\"slash-heading h4\">");
    c.push(if fr { "Réseaux sociaux" } else { "Social Feed" });
    c.push("</span>\n  </div>\n</section>\n\n<section class=\"black-radial-gradient site-padding-bl\">\n  ");
    social_feed(c);
    red_box(c);
    cta(c);
    c.push("\n  \n</section>\n\n");
}

/// layouts/sections/home/hero.php
fn hero(c: &mut Ctx) {
    c.push("<section class=\"home-hero ");
    if c.notice() {
        c.push("add-margin");
    }
    c.push("\">\n\t<video playsinline=\"\" autoplay=\"\" muted=\"\" loop=\"\" poster=\"/wp-content/uploads/2023/07/first-frame-image.png\" id=\"bgvideo\" width=\"x\" height=\"y\">\n    <source src=\"");
    c.push(super::THEME);
    c.push("/videos/hero-video.webm\" type=\"video/webm\">\n    <source src=\"");
    c.push(super::THEME);
    c.push("/videos/hero-video.mp4\" type=\"video/mp4\">\n  </video>\n  <div class=\"background-overlay\">\n    <div class=\"container\">\n      <div class=\"row\">\n        <div data-aos-duration=\"1500\" data-aos=\"fade-right\" class=\"col-lg-6 hero-col-1\">\n          <span class=\"red-headline\">");
    c.html("hero_headline");
    c.push("</span>\n          <h1>");
    c.html("hero_title");
    c.push(" </h1>\n          <a class=\"standard-btn red scscpq_newuser\" href=\"");
    c.link("hero_button", "url");
    c.push("\">");
    c.link("hero_button", "title");
    c.push("</a>\n        </div>\n\n        <div data-aos-duration=\"2000\" data-aos=\"fade-left\" data-aos-easing=\"ease-in-sine\" class=\"col-lg-6 hero-col-2 image-half\">\n          ");
    let img = c.media("hero_image");
    c.image(img);
    c.push("        </div>\n      </div>\n    </div>\n  </div>\n</section>\n");
}

/// A button printed from an ACF link field: `href`, `target` and the title.
fn button(c: &mut Ctx, class: &str, name: &str) {
    c.push(&format!("<a class=\"{class}\" href=\""));
    c.link(name, "url");
    c.push("\" target=\"");
    c.link(name, "target");
    c.push("\">");
    c.link(name, "title");
    c.push("</a>");
}

/// layouts/sections/home/innovations.php
fn innovations(c: &mut Ctx) {
    c.push("\n<section class=\"innovations-section black-radial-gradient site-padding\">\n  <div class=\"container\">\n    <div class=\"row\">\n      <div data-aos=\"fade-up\" data-aos-duration=\"1000\" class=\"col-lg-8 text-center mx-auto\">\n        <span class=\"red-headline\">");
    c.html("inno_headline");
    c.push("</span>\n        <h2>");
    c.html("inno_title");
    c.push("</h2>\n        <p>");
    c.html("inno_description");
    c.push("</p>\n      </div>\n    </div>\n    ");
    grid_items(c);
    c.push("\n    <div data-aos=\"fade-up\" data-aos-duration=\"1000\" class=\"text-center\">\n      ");
    button(c, "standard-btn red large", "inno_btn");
    c.push("\n    </div>\n\n  </div>\n</section>\n");
}

/// layouts/sections/home/products.php
fn products(c: &mut Ctx) {
    c.push("\n<section class=\"products-section has-bg-img\" style=\"background-image:url(");
    let bg = c.image_url("products_bg_img");
    c.push(&bg);
    c.push(");\">\n  <div class=\"container-fluid\">\n    <div class=\"row has-overlay\">\n      <div class=\"col-lg-6 overlay image-half site-padding d-none d-lg-flex align-items-center justify-content-center\">\n        <div data-aos=\"fade-right\" data-aos-duration=\"1000\" class=\"image\">");
    let img = c.media("products_image");
    c.image(img);
    c.push("</div>\n      </div>\n      <div class=\"col-lg-6 overlay content-half site-padding\">\n        <div data-aos=\"fade-left\" data-aos-duration=\"1000\" class=\"content\">\n          <span class=\"red-headline\">");
    c.html("products_headline");
    c.push("</span>\n          <h2>");
    c.html("products_title");
    c.push("</h2>\n          <p>");
    c.html("products_description");
    c.push("</p>\n          <div class=\"list\">");
    c.html("products_list");
    c.push("</div>\n          <div class=\"text-left\">\n            ");
    button(c, "standard-btn red", "products_btn");
    c.push("\n          </div>\n        </div>\n      </div>\n    </div>\n\n\n  </div>\n</section>\n");
}

/// layouts/sections/home/industries.php (prod's copy): the customer logo carousel, from the English options (see
/// `Ctx::default_option`); prod's copy takes the French headline when it is set.
fn industries(c: &mut Ctx) {
    c.push("<section class=\"industries-section has-bg-img d-flex align-items-center justify-content-center\" style=\"background-image: url(");
    let bg = Ctx::media_url(c.default_option_media("industries_bg_img"));
    c.push(&bg);
    c.push(")\">\n  <div class=\"container-fluid\">\n    <div class=\"headline\">\n      <span data-aos=\"fade-up\" data-aos-duration=\"1000\" class=\"white-headline\">");
    let fr_headline = c.lang() == Lang::Fr && c.option("industries_headline").is_some_and(|f| super::truthy(&f.value));
    c.slot(Slot::Option(if fr_headline { Lang::Fr } else { Lang::En }, "industries_headline".into()));
    c.push("</span>\n    </div>\n    <div class=\"logos\">\n      <div class=\"owl-carousel logo-carousel logo-container\"  data-aos-offset=\"250\" data-aos=\"fade-up\" data-aos-duration=\"2000\" >\n        ");
    let rows = c.default_option("customer_logos").and_then(|f| f.value.parse::<usize>().ok()).unwrap_or(0);
    for i in 0..rows {
        c.push("          <div class=\"image\">");
        let logo = c.default_option_media(&format!("customer_logos_{i}_image"));
        c.image(logo);
        c.push("</div>\n\n          ");
    }
    c.push("      </div>\n    </div>\n  </div>\n</section>\n");
}

/// layouts/sections/home/tradition.php
fn tradition(c: &mut Ctx) {
    c.push("\n<section class=\"tradition-section site-padding\">\n  <div class=\"container\">\n    <div class=\"row align-items-center \">\n      <div class=\"col-lg-6 mb-4 mb-md-0\">\n        <div data-aos=\"fade-right\" data-aos-duration=\"1000\" class=\"image\">");
    let img = c.media("tradition_image");
    c.image(img);
    c.push("</div>\n      </div>\n      <div class=\"col-lg-6 p-left\">\n        <div data-aos=\"fade-left\" data-aos-duration=\"1000\"  class=\"content\">\n          <span class=\"red-headline\">");
    c.html("tradition_headline");
    c.push("</span>\n          <h2>");
    c.html("tradition_title");
    c.push("</h2>\n          <p>");
    c.html("tradition_description");
    c.push("</p>\n          <div class=\"button-group text-left\">\n            ");
    button(c, "standard-btn red", "tradition_btn");
    c.push("\n            ");
    button(c, "standard-btn white", "tradition_btn_2");
    c.push("\n          </div>\n        </div>\n      </div>\n    </div>\n  </div>\n</section>\n");
}

/// layouts/sections/home/social-feed.php. Its second button is commented out in both HTML and PHP, so the HTML
/// comment carries empty attributes.
fn social_feed(c: &mut Ctx) {
    c.push("\n<section class=\"tradition-section site-padding social-feed\">\n  <div class=\"container\">\n    <div class=\"row align-items-center \">\n      <div class=\"col-lg-6 mb-4 mb-md-0\">\n        <div data-aos=\"fade-right\" data-aos-duration=\"1000\" class=\"image\">");
    let img = c.media("socialFeed_image");
    c.image(img);
    c.push("</div>\n      </div>\n      <div class=\"col-lg-6 p-left\">\n        <div data-aos=\"fade-left\" data-aos-duration=\"1000\"  class=\"content\">\n          <span class=\"red-headline\">");
    c.html("socialFeed_headline");
    c.push("</span>\n          <h2>");
    c.html("socialFeed_title");
    c.push("</h2>\n          <p>");
    c.html("socialFeed_description");
    c.push("</p>\n          <div class=\"button-group text-left\">\n            ");
    button(c, "standard-btn red", "socialFeed_btn");
    c.push("\n            <!--<a class=\"standard-btn white\" href=\"\" target=\"\"></a> -->\n          </div> \n        </div>\n      </div>\n    </div>\n  </div>\n</section>\n");
}

/// layouts/sections/home/red-box.php
fn red_box(c: &mut Ctx) {
    c.push("\n\n<section class=\"tradition-section site-padding single-card\">\n<div class=\"container\">\n<div data-aos-duration=\"1000\" data-aos=\"fade-right\" class=\"ll-item has-overlay has-bg-img\" style=\"background-image:url('");
    let img = c.image_url("red_box2_image");
    c.push(&img);
    c.push("');\">\n  <div class=\"overlay\">\n    <a class=\"absolute-link\" href=\"");
    c.link("red_box2_link", "url");
    c.push("\"></a>\n    <div class=\"ll-content\">\n      <h3>");
    c.html("red_box2_title");
    c.push("</h3>\n      <p>");
    c.html("red_box2_description");
    c.push("</p>\n      ");
    button(c, "standard-btn white", "red_box2_btn");
    c.push("\n    </div>\n  </div>\n</div>\n</div>\n</div>\n</section>\n");
}
