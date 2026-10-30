//! The company pages' templates (history, why, about, career), transcribed from the momentum-custom theme like
//! templates.rs.

use super::{
    templates::{breadcrumbs, cta, id_list},
    Ctx, Slot,
};
use crate::cms::model::Lang;

/// layouts/template-history.php
pub fn history(c: &mut Ctx) {
    history_hero(c);
    history_content(c);
    c.push(" <section class=\"black-radial-gradient site-padding-bl\"> ");
    cta(c);
    c.push(" </section> ");
}

/// layouts/sections/history/hero.php
fn history_hero(c: &mut Ctx) {
    c.push("\n<section class=\"history-hero ");
    if c.notice() {
        c.push("add-margin");
    }
    c.push("\" style=\"background-image:url('");
    let bg = Ctx::media_url(c.media("hero_bg_image"));
    c.push(&bg);
    c.push("')\">\n  <div class=\"background-overlay site-padding \">\n    <div class=\"container\">\n      <div class=\"row\">\n\n        <div data-aos-duration=\"1500\" data-aos=\"fade-right\" class=\"col-lg-6 content-half\">\n          <div class=\"pr-md-5\">\n            ");
    breadcrumbs(c);
    c.push("\n            <div class=\"hero-content\">\n              ");
    c.html("hero_content");
    c.push("            </div>\n          </div>\n        </div>\n        <div class=\"col-lg-6 image-half\" data-aos-duration=\"1500\" data-aos=\"fade-left\">\n          ");
    let img = c.media("hero_image");
    c.image(img);
    c.push("        </div>\n\n      </div>\n    </div>\n  </div>\n</section>\n\n<style media=\"screen\">\n.history-hero { background-size: cover; }\n.history-hero .background-overlay { background: rgba(255,255,255,.95)}\n.history-hero h3 { margin-top: 2.5rem;}\n@media only screen and ( max-width : 991px ) {\n  .history-hero .content-half img {height: auto;}\n  .history-hero .image-half img { display: none; }\n}\n</style>\n");
}

/// layouts/sections/history/content.php
fn history_content(c: &mut Ctx) {
    c.push("<section class=\"history-images red-radial-gradient\">\n  <div class=\"grid-bg\"></div>\n  <div class=\"container\">\n    <div class=\"row\">\n      <div class=\"col-lg-10 mx-auto mb-0 mb-lg-5\">\n        <h2>");
    c.html("history_header");
    c.push("</h2>\n      </div>\n    </div>\n    <div class=\"row\">\n      <div class=\"col-lg-10 mx-auto\">\n        <div class=\"row\">\n          <div class=\"col-lg-6 img-col img-col-1\">\n            <div class=\"image-one image-container\">\n              ");
    history_image(c, "image_one", "fade-right", "up");
    c.push("            </div>\n          </div>\n          <div class=\"col-lg-6 img-col img-col-2\">\n            <div class=\"image-two image-container\">\n              ");
    history_image(c, "image_two", "fade-down", "down");
    c.push("            </div>\n            <div class=\"image-three image-container\">\n              ");
    history_image(c, "image_three", "fade-left", "up");
    c.push("            </div>\n          </div>\n        </div>\n      </div>\n    </div>\n  </div>\n</section>\n\n<style media=\"screen\">\n\n\n@media only screen and ( min-width : 991px ) {\n  .history-images {padding: 70px 0 170px; position: relative;}\n  .history-images .grid-bg{ position: absolute; left: 0; top: 0; height: 100%; width: 100%;background-size: cover; background-image: url(/wp-content/themes/momentum-custom/images/red-banner3.jpg); background-repeat: no-repeat;}\n  .history-images .image-one {padding-top: 23px;}\n  .history-images .image-container img {width: 100%;}\n  .history-images .image-container {position: relative;}\n  .history-images .caption {position: absolute;font-size: 1.25rem;font-style: italic;}\n  .history-images .image-one .caption {bottom: -130px;left: 40px;width: 70%;}\n  .history-images .image-two .caption {top: -130px;left: 0;width: 82%;}\n  .history-images .image-three .caption {bottom: -130px;text-align: right;right: 40px;left: auto;width: 90%;}\n  .history-images .arrow {position: absolute;}\n  .history-images .arrow img { width: auto}\n  .history-images .image-one .arrow {bottom: -50px;left: 100px;}\n  .history-images .image-two .arrow {top: -25px;left: 100px;}\n  .history-images .image-three .arrow {bottom: -50px;right: 100px;left: auto;}\n  .history-images .img-col {flex: 0 0 0;max-width: 100%;}\n  .history-images .img-col-1 {flex-basis: 49.75%;}\n  .history-images .img-col-2 {flex-basis: 42%;}\n  .history-images .image-two { padding-bottom: 15px; margin-left: -15px; }\n  .history-images .image-three { margin-left: -15px; }\n}\n\n@media only screen and ( max-width : 991px ) {\n  .history-images .arrow { display: none}\n  .history-images img { width: 100%; margin-bottom: 20px; margin-top: 60px;}\n  .history-images {padding: 40px 0 40px; position: relative;}\n}\n</style>\n");
}

/// layouts/template-why.php. The carousel script it prints after get_footer() is the page's `after_html`.
pub fn why(c: &mut Ctx) {
    why_hero(c);
    why_content(c);
    testimonials(c);
    c.push(" <section class=\"black-radial-gradient site-padding-bl\"> ");
    cta(c);
    c.push(" </section> ");
}

/// layouts/sections/why/hero.php
fn why_hero(c: &mut Ctx) {
    c.push("\n<section class=\"why-hero ");
    if c.notice() {
        c.push("add-margin");
    }
    c.push("\" style=\"background-image:url('");
    let bg = Ctx::media_url(c.media("bg_image"));
    c.push(&bg);
    c.push("')\">\n  <div class=\"background-overlay\">\n    <div class=\"container\">\n      <div class=\"row\">\n\n        <div data-aos-duration=\"1500\" data-aos=\"fade-right\" class=\"col-lg-6 content-half\">\n          <div class=\"site-padding pr-md-5\">\n            ");
    breadcrumbs(c);
    c.push("            <h1 class=\"mt-4\">");
    c.html("hero_title");
    c.push("</h1>\n            <div class=\"description mt-4\">\n              ");
    c.html("hero_description");
    c.push("            </div>\n          </div>\n        </div>\n        <div class=\"col-lg-6 image-half\" data-aos-duration=\"1500\" data-aos=\"fade-left\">\n          ");
    let img = c.media("hero_image");
    c.image(img);
    c.push("        </div>\n\n      </div>\n    </div>\n  </div>\n</section>\n\n<style media=\"screen\">\n.why-hero .background-overlay { background: rgba(255,255,255,.95)}\n.why-hero .image-half img { position: absolute; left: 0; top: 0; width: 960px; height: auto; max-width: none; }\n.why-hero .content-half {height: 670px;}\n@media only screen and ( max-width : 991px ) {\n  .why-hero .content-half {height: auto;}\n  .why-hero .content-half img {height: auto;}\n  .why-hero .image-half img { display: none; }\n}\n</style>\n");
}

/// layouts/sections/why/content.php
fn why_content(c: &mut Ctx) {
    c.push("\n\n<section class=\"why-features\">\n  <div class=\"container-fluid text-center red-radial-gradient\">\n    <div class=\"row\">\n      ");
    for i in 0..c.count("why_features") {
        c.push("\n        <div class=\"col-lg-4 site-padding ");
        if i == 1 {
            c.push("has-overlay overlay");
        }
        c.push("\">\n          <div class=\"content-container\">\n            <h3 data-aos-duration=\"1500\" data-aos=\"fade-up\" class=\"white\">");
        c.html(&format!("why_features_{i}_title"));
        c.push("</h3>\n            <p data-aos-duration=\"1500\" data-aos=\"fade-up\" class=\"mb-0\">");
        c.html(&format!("why_features_{i}_description"));
        c.push("</p>\n          </div>\n        </div>\n\n        ");
    }
    c.push("\n\n  </div>\n</div>\n</section>\n\n\n<section class=\"why-configurator black-radial-gradient\">\n  <div class=\"container large\">\n\n    <div class=\"row align-items-center\">\n\n      <div data-aos-duration=\"1500\" data-aos=\"fade-right\" class=\"col-lg-5 py-4 py-lg-0\">\n        <h2 class=\"mt-0 white\">");
    c.html("why_config_title");
    c.push(" </h2>\n        <div class=\"icon-items\">\n          ");
    for i in 0..c.count("why_config_features") {
        c.push("\n            <div class=\"icon-item d-flex\">\n              <div class=\"image-container\">\n                ");
        let icon = c.media(&format!("why_config_features_{i}_icon"));
        c.image(icon);
        c.push("              </div>\n              <div class=\"content-container pl-5 pl-lg-0\">\n                <h3 class=\"white mt-0\">");
        c.html(&format!("why_config_features_{i}_title"));
        c.push("</h3>\n                <p class=\"mb-0\">");
        c.html(&format!("why_config_features_{i}_description"));
        c.push("</p>\n              </div>\n            </div>\n\n            ");
    }
    c.push("      </div>\n    </div>\n\n    <div class=\"offset-md-1 col-lg-6 site-padding parts-solution\">\n    </div>\n\n  </div>\n\n</div>\n</section>\n");
}

/// layouts/sections/global/testimonials.php (prod's copy, with the French heading). Each quote is a testimonials
/// post; its text is kept with the page that shows it, as `testimonials_N_quote` and `testimonials_N_customer`.
pub(super) fn testimonials(c: &mut Ctx) {
    c.push("<section class=\"customers-section has-bg-img d-flex align-items-center justify-content-center\" style=\"background-image: url(");
    let bg = Ctx::media_url(c.option_media("industries_bg_img"));
    c.push(&bg);
    c.push(")\">\n  <div class=\"container-fluid\">\n    <div class=\"headline\">\n      <h2 data-aos-duration=\"1500\" data-aos=\"fade-up\" class=\"white text-center\">");
    c.push(if c.lang() == Lang::Fr { "Ce que disent nos clients" } else { "What Our Customers Say" });
    c.push("</h2>\n    </div>\n    <div class=\"owl-carousel customers-items text-center white\">\n\n      ");
    let n = id_list(c.choice("testimonials")).len();
    if n > 0 {
        c.push("      ");
        for i in 0..n {
            c.push("        ");
            c.push("        <div class=\"customer-item col-lg-6 mx-auto data-aos-duration=\"1500\" data-aos=\"fade-up\"\">\n          <div class=\"quote\">");
            c.html(&format!("testimonials_{i}_quote"));
            c.push("</div>\n          <div class=\"customer mt-3\">");
            c.html(&format!("testimonials_{i}_customer"));
            c.push("</div>\n        </div>\n      ");
        }
        c.push("      ");
        c.push("    ");
    }
    c.push("\n  </div>\n</div>\n</section>\n");
}

/// layouts/template-about.php
pub fn about(c: &mut Ctx) {
    about_hero(c);
    about_advantage(c);
    about_mid(c);
    c.push("<section class=\"black-radial-gradient\">\n");
    community_award(c);
    cta(c);
    c.push("\n</section>\n\n");
}

/// layouts/sections/about/hero.php
fn about_hero(c: &mut Ctx) {
    c.push("\n<section class=\"about-hero ");
    if c.notice() {
        c.push("add-margin");
    }
    c.push("\" style=\"background-image:url(");
    let bg = Ctx::media_url(c.media("hero_bg_img"));
    c.push(&bg);
    c.push(");\">\n  <div class=\"container-fluid background-container pr-0\">\n    <div class=\"row pr-0\">\n      <div class=\"col-lg-5\"></div>\n      <div class=\"col-lg-7 right-bg-image pr-0\" style=\"background-image:url(");
    let right = Ctx::media_url(c.media("hero_bg_img_right"));
    c.push(&right);
    c.push(")\"></div>\n    </div>\n  </div>\n  <div class=\"container hero-content-container\">\n    <div class=\"row align-items-center\">\n      <div class=\"col-lg-6\">\n        ");
    breadcrumbs(c);
    c.push("        <div data-aos-duration=\"1500\" data-aos=\"fade-right\"  class=\"red-radial-gradient hero-content\">\n          <h1>");
    c.html("hero_title");
    c.push("</h1>\n          <div class=\"column-description\">\n            ");
    c.html("hero_description");
    c.push("          </div>\n        </div>\n      </div>\n    </div>\n  </div>\n</section>\n\n\n<style media=\"screen\">\n.about-hero { position: relative; background-size: cover;}\n.about-hero .container-fluid.background-container { position: absolute; left: 0; top: 0; width: 100%; height: 840px; }\n.about-hero .container-fluid.background-container .row,\n.about-hero .container.hero-content-container > .row { height: 840px; z-index: 30;position: relative; }\n.about-hero .hero-content { padding:80px 60px }\n.about-hero .container-fluid.background-container:after {content: '';width: 100%;height: 100%;position: absolute;left: 0;top: 0;z-index: 10;background: rgba(255, 255, 255, .9);}\n.about-hero .container-fluid .right-bg-image {z-index: 20;position: relative; background-size: cover;}\n.about-hero .breadcrumb-section { position: absolute; top: -100px; left: 15px; }\n</style>\n");
}

/// layouts/sections/about/advantage.php
fn about_advantage(c: &mut Ctx) {
    c.push("\n<section class=\"about-advantage site-padding-bl black-radial-gradient\">\n\n  <div class=\"container\">\n    <div class=\"row\">\n\n      <div class=\"col-lg-6 pr-5\">\n\n        <span data-aos-duration='1500' data-aos='fade-down' class='red-headline'>");
    c.html("advantage_headline");
    c.push("</span>");
    for i in 0..c.count("advantage_items") {
        c.push("<div class='advantage-item' data-aos-duration='1500' data-aos='fade-right'><h2>");
        c.html(&format!("advantage_items_{i}_title"));
        c.push("</h2><div class='advantage-description'>");
        c.html(&format!("advantage_items_{i}_description"));
        c.push("</div></div>");
    }
    c.push("    </div>\n\n    <div class=\"col-lg-6 text-center\" data-aos-duration='1500' data-aos='fade-left'>\n      ");
    let img = c.media("advantage_image");
    c.image(img);
    c.push("    </div>\n\n  </div> <!-- end row -->\n</div> <!-- end container -->\n\n</section>\n");
}

/// layouts/sections/about/mid-section.php
fn about_mid(c: &mut Ctx) {
    c.push("\n<section class=\"about-mid-section site-padding has-overlay\" style=\"background-image:url('");
    let bg = Ctx::media_url(c.media("mid_bg_img"));
    c.push(&bg);
    c.push("');\">\n  <div class=\"absolute-overlay red-radial-gradient\"> </div>\n    <div class=\"container\">\n      <div class=\"row\">\n        <div class=\"col-lg-8 mx-auto text-center bold\" data-aos-duration='1500' data-aos='fade-down'>\n          ");
    c.html("mid_description");
    c.push("        </div>\n      </div>\n    </div>\n</section>\n");
}

/// layouts/sections/about/community-award.php
fn community_award(c: &mut Ctx) {
    c.push("<div class=\"site-padding\">\n\n  <div class=\"container\">\n\n    <div class=\"row community-section\">\n\n      <div class=\"col-lg-6 image mb-md-0 mb-4 \" data-aos-duration='1500' data-aos='fade-right'>\n        ");
    let img = c.media("community_image");
    c.image(img);
    c.push("      </div>\n\n      <div class=\"col-lg-6 content pl-lg-5\">\n\n        <span data-aos-duration='1500' data-aos='fade-down' class=\"red-headline\">");
    c.html("community_headline");
    c.push("</span>\n        <h2 data-aos-duration='1500' data-aos='fade-left'>");
    c.html("community_title");
    c.push("</h2>\n        <div data-aos-duration='1500' data-aos='fade-left' class=\"column-description\">");
    c.html("community_description");
    c.push("</div>\n\n      </div>\n    </div>\n    \n    <div class=\"row mt-5 pt-5\">\n\n      <div class=\"col-lg-6 content pr-lg-5\" data-aos-duration='1500' data-aos='fade-right'>\n\n        <span data-aos-duration='1500' data-aos='fade-down' class=\"red-headline\">");
    c.html("award_headline");
    c.push("</span>\n        <h2 data-aos-duration='1500' data-aos='fade-right'>");
    c.html("award_title");
    c.push("</h2>\n        <div  data-aos-duration='1500' data-aos='fade-right' class=\"column-description\">");
    c.html("award_description");
    c.push("</div>\n        <div class=\"row award-section mt-5 d-none d-sm-flex\">\n          <div class=\"col-lg-12\">\n            <h4 data-aos-duration='1500' data-aos='fade-down'>Boeing Supplier Relations Awards</h4>\n          </div>\n          ");
    let rows = c.option("awards").and_then(|f| f.value.parse::<usize>().ok()).unwrap_or(0);
    let icon = c.option_media("award_icon");
    for i in 0..rows {
        c.push("<div class='col-sm-6 col-lg-3 d-flex award-item my-4 align-items-center'><div class='award-image pr-4' data-aos-duration='1500' data-aos='fade-down'>");
        c.image(icon);
        c.push("</div><div class='award-content'>");
        if c.option(&format!("awards_{i}_label")).is_some_and(|f| super::truthy(&f.value)) {
            c.push("<div class='award-label'>");
            c.option_html(&format!("awards_{i}_label"));
            c.push("</div>");
        }
        c.push("<div class='award-year'>");
        c.option_html(&format!("awards_{i}_year"));
        c.push("</div></div></div>");
    }
    c.push("      </div>\n      </div>\n\n      <div class=\"col-lg-6 image mt-md-0 mt-4 \">\n        ");
    let img = c.media("award_image");
    c.image(img);
    c.push("      </div>\n\n\n    </div>\n\n\n</div>\n</div>\n");
}

/// layouts/template-career.php
pub fn career(c: &mut Ctx) {
    career_hero(c);
    c.push("<section class=\"black-radial-gradient\">\n");
    job_listing(c);
    cta(c);
    c.push("\n</section>\n\n");
}

/// layouts/sections/career/hero.php
fn career_hero(c: &mut Ctx) {
    c.push("\n<section class=\"career-hero ");
    if c.notice() {
        c.push("add-margin");
    }
    c.push("\" style=\"background-image:url('");
    let bg = Ctx::media_url(c.media("bg_image"));
    c.push(&bg);
    c.push("')\">\n\n  <div class=\"background-overlay\">\n    <div class=\"container\">\n      <div class=\"row\">\n\n        <div data-aos-duration=\"1500\" data-aos=\"fade-right\" class=\"col-lg-8 mx-auto text-center\">\n          <div class=\"site-padding\">\n            ");
    breadcrumbs(c);
    c.push("            <span class=\"red-headline mt-5\">");
    c.html("hero_headline");
    c.push("</span>\n            <h1 class=\"mt-5\">");
    c.html("hero_title");
    c.push("</h1>\n            <div class=\"description mt-2\">\n              ");
    c.html("hero_description");
    c.push("            </div>\n          </div>\n        </div>\n\n\n      </div>\n    </div>\n  </div>\n\n</section>\n\n\n<style media=\"screen\">\n.career-hero { background-size: cover; background-repeat: no-repeat}\n.career-hero .background-overlay { background: rgba(255,255,255,.95)}\n.career-hero .row { height: auto;}\n.career-hero .image-half img { position: absolute; left: 0; top: 0; height: auto; max-width: none; }\n\n@media only screen and ( max-width : 991px ) {\n  .career-hero .content-half img {height: auto;}\n  .career-hero .image-half img { display: none; }\n}\n</style>\n");
}

/// layouts/sections/career/job-listing.php. The `[jobs]` shortcode's output (WP Job Manager), which the theme
/// prints inside an HTML comment, is kept as the page's `jobs` meta.
fn job_listing(c: &mut Ctx) {
    c.push("\n<section class=\"job-listings site-padding\">\n\n  <div class=\"container\">\n\n    <div class=\"row\">\n\n      <div data-aos-duration=\"1500\" data-aos=\"fade-right\" class=\"col-lg-8 mx-auto\">\n\n        <div class=\"additional-content mb-4\">\n          ");
    c.html("additional_content");
    c.push("        </div>\n\n        <!-- ");
    c.slot(Slot::Meta(c.page.id, "jobs".into()));
    c.push(" -->\n\n      </div>\n\n    </div>\n\n\n  </div>\n\n</section>\n");
}

/// One captioned photo of the history section: an ACF group of `image` and `caption`.
fn history_image(c: &mut Ctx, group: &str, fade: &str, arrow: &str) {
    let img = c.media(&format!("{group}_image"));
    c.image(img);
    c.push(&format!("              <span data-aos-duration=\"1500\" data-aos=\"{fade}\" class=\"caption\">"));
    c.html(&format!("{group}_caption"));
    c.push(&format!("</span>\n              <span data-aos-duration=\"1500\" data-aos=\"{fade}\" class=\"arrow\"><img src=\"/wp-content/themes/momentum-custom/images/arrow-{arrow}-white.png\" alt=\"\"></span>\n"));
}
