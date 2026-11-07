//! The Request Access page's template (request-access, demande-dacces), transcribed from the momentum-custom theme
//! like templates.rs. The sign-up form itself is newuser.php, which the scscpq loader puts in the hero's
//! `scscpq_page` div.

use super::{
    templates::{breadcrumbs, cta},
    Ctx,
};
use crate::cms::model::{Kind, Lang};

/// layouts/template-request.php
pub fn request(c: &mut Ctx) {
    hero(c);
    content(c);
}

/// layouts/sections/request/hero.php
fn hero(c: &mut Ctx) {
    c.push("<section id=\"signup\" class=\"signup-hero ");
    if c.notice() {
        c.push("add-margin");
    }
    c.push("\" style=\"background-image:url('");
    let bg = background_image(c);
    c.push(&bg);
    c.push("')\">\n  <div class=\"background-overlay site-padding\">\n    <div class=\"container large\">\n      <div class=\"row align-items-start\">\n        <div data-aos-duration=\"1500\" data-aos=\"fade-right\" class=\"col-lg-6 pr-lg-4\">\n          ");
    breadcrumbs(c);
    c.push("          <h1 class=\"mt-4\">");
    c.html("hero_title");
    c.push(" </h1>\n          <div class=\"description mt-4\">\n            ");
    c.html("hero_description");
    c.push("          </div>\n          <div class=\"list mt-4\">\n            <ul>\n\n              ");
    for i in 0..c.count("hero_list") {
        c.push("<li>");
        c.html(&format!("hero_list_{i}_item"));
        c.push("</li>");
    }
    c.push("            </ul>\n          </div>\n        </div>\n        <div class=\"col-lg-6\">\n          <div id=\"scscpq_page\"><div class=\"noshow\">newuser</div></div>\n        </div>\n      </div>\n    </div>\n  </div>\n</section>\n\n<style media=\"screen\">\n.signup-hero .background-overlay { background: rgba(255,255,255,.95)}\n.signup-hero .parts-solution {background: #F3F3F3; padding-left: 20px; padding-right: 20px;}\n.signup-hero .parts-solution iframe {border: none; padding: 5px;}\n</style>\n");
}

/// `background_image` as hero.php prints it: an image array's url, an attachment id's URL, or the value itself.
fn background_image(c: &Ctx) -> String {
    match c.field("background_image") {
        Some(f) if f.kind == Kind::Media || f.value.trim().parse::<i64>().is_ok() => Ctx::media_url(c.media("background_image")),
        Some(f) => f.value.clone(),
        None => String::new(),
    }
}

/// layouts/sections/request/content.php
fn content(c: &mut Ctx) {
    block(c, "right", "block_one", "black-radial-gradient");
    catalog_bar(c);
    block(c, "left", "block_two", "light");
    c.push("<section class=\"black-radial-gradient\">\n\n  ");
    block(c, "right", "block_three", "");
    cta(c);
    c.push("\n</section>\n");
}

/// content.php's build_content().
fn block(c: &mut Ctx, side: &str, prefix: &str, background: &str) {
    c.push(&format!("<section class='content-section site-padding {side} {background}'><div class='container-fluid'><div class='row align-items-center'><div class='col-lg-6 content-half' data-aos-duration='1500' data-aos='fade-{side}'><div class='content-container'><span class='red-headline'>"));
    c.html(&format!("{prefix}_headline"));
    c.push("</span><h2>");
    c.html(&format!("{prefix}_title"));
    c.push("</h2><p>");
    c.html(&format!("{prefix}_description"));
    c.push(&format!("</p></div></div><div class='col-lg-6 image-half' data-aos-duration='1500' data-aos='fade-{side}'>"));
    let image = c.media(&format!("{prefix}_image"));
    c.image(image);
    c.push("</div></div></div></section>");
}

/// layouts/sections/global/catalog-bar.php, prod's copy.
fn catalog_bar(c: &mut Ctx) {
    let fr = c.lang() == Lang::Fr;
    c.push("<section class=\"catalog-bar red-linear-vert-gradient-reverse py-5\">\n  <div class=\"container\">\n    <div class=\"row align-items-center\">\n\n      <div class=\"offset-md-2 col-md-5 text-center\">\n        <h2 class=\"white my-0\">");
    c.push(if fr { "Accédez à l'ensemble de notre catalogue" } else { "Get Access to Our Full Catalog" });
    c.push("</h2>\n      </div>\n\n      <div class=\"offset-md-1 col-md-4 text-center\">\n        <div class=\"button-container\">\n          <a class=\"standard-btn white mt-md-0\" href=\"#signup\">");
    c.push(if fr { "Inscription nouvel utilisateur" } else { "New User Sign Up" });
    c.push("</a>\n        </div>\n      </div>\n\n    </div>\n  </div>\n</section>\n");
}
