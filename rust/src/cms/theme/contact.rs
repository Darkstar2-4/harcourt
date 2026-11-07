//! The contact templates (contact, the UK, France and India variants, locations), transcribed from prod's copy of
//! the momentum-custom theme like templates.rs. A region's widget area is stored with the page as `sidebar`, as
//! WordPress printed it.

use super::{
    templates::{cta, sidebar},
    Ctx, Slot,
};
use crate::cms::model::Lang;

/// layouts/sections/contact/hero.php
fn hero(c: &mut Ctx) {
    c.push("\n<section class=\"contact-hero ");
    if c.notice() {
        c.push("add-margin");
    }
    c.push("\" style=\"background-image:url('");
    let bg = c.image_url("hero_bg_img");
    c.push(&bg);
    c.push("')\">\n  <div class=\"background-overlay\">\n    <div class=\"container\">\n      <div class=\"row\">\n        <div data-aos-duration=\"1500\" data-aos=\"fade-right\" class=\"col-lg-8 mx-auto text-center\">\n          <div class=\"site-padding pr-md-5\">\n            ");
    super::templates::breadcrumbs(c);
    c.push("            <h1 class=\"mt-5\">");
    c.html("hero_title");
    c.push("</h1>\n            <div class=\"description mt-2\">\n              ");
    c.html("hero_description");
    c.push("            </div>\n          </div>\n        </div>\n      </div>\n    </div>\n  </div>\n</section>\n\n\n<style media=\"screen\">\n.contact-hero { background-size: cover; background-repeat: no-repeat;}\n.contact-hero .background-overlay { background: rgba(255,255,255,.95)}\n.contact-hero .row { height: auto;}\n.contact-hero .image-half img { position: absolute; left: 0; top: 0; height: auto; max-width: none; }\n\n@media only screen and ( max-width : 991px ) {\n  .contact-hero .content-half img {height: auto;}\n  .contact-hero .image-half img { display: none; }\n}\n</style>\n");
}

/// The page's article: `the_ID()`, `post_class()` and `the_content()`.
fn article(c: &mut Ctx) {
    let id = c.page.id.0;
    c.push(&format!("\n<section class=\"site-padding\">\n  <main class=\"container\">\n    <div class=\"row\">\n\n      <div class=\"col-lg-8 pr-lg-5\">\n        <div id=\"content\" role=\"main\">\n\n          <article role=\"article\" id=\"post_{id}\" class=\"post-{id} page type-page status-publish"));
    if c.field("@thumbnail").is_some_and(|f| super::truthy(&f.value)) {
        c.push(" has-post-thumbnail");
    }
    c.push(" hentry\">\n            ");
    c.html("content");
    c.push("          </article>\n\n        </div><!-- /#content -->\n      </div>\n");
}

fn closing(c: &mut Ctx) {
    c.push("\n    </div><!-- /.row -->\n  </main><!-- /.container -->\n</section><!-- /.row -->\n\n\n\n<section class=\"black-radial-gradient site-padding-bl\">\n  ");
    cta(c);
    c.push("\n</section>\n\n\n\n");
}

/// layouts/template-contact.php
pub fn contact(c: &mut Ctx) {
    hero(c);
    article(c);
    c.push("\n      ");
    sidebar(c);
    closing(c);
}

/// layouts/template-contact-uk.php, -france.php and -india.php: the same page with the region's widget area. The
/// UK file's whitespace differs from the other two.
fn region(c: &mut Ctx, uk: bool) {
    let fr = c.lang() == Lang::Fr;
    hero(c);
    article(c);
    c.push(if uk { "      \n" } else { "\n" });
    c.push("      <div class=\"sidebar col-lg-4\" id=\"sidebar\" role=\"navigation\">\n\n          <div class=\"sidebar-widget-container black-radial-gradient\">\n              ");
    c.slot(Slot::Meta(c.page.id, "sidebar".into()));
    c.push("          </div>\n\n          \n            <div class=\"scscpq_newuser mt-4 red-radial-gradient text-center p-5\">\n                  <h3 class=\"mt-0 mb-4\">");
    c.push(if fr { "Accédez à l'ensemble de notre catalogue" } else { "Get Access to Our Full Catalog" });
    c.push("</h3>\n                  <a class=\"standard-btn ignore-hover white mt-0 w-100\" href=\"");
    c.push(super::ORIGIN);
    c.push(if fr { "/?lang=frrequest-access\">Demander l'accès" } else { "/request-access\">Request Access" });
    c.push(if uk { "</a>\n            </div>\n      </div>\n\n" } else { "</a>\n            </div>\n      </div>\n      \n" });
    if !uk {
        c.push("\n");
    }
    closing(c);
}

pub fn contact_uk(c: &mut Ctx) {
    region(c, true);
}

pub fn contact_region(c: &mut Ctx) {
    region(c, false);
}

/// layouts/template-locations.php (prod's copy). The map is an image-hotspot shortcode, kept as printed.
pub fn locations(c: &mut Ctx) {
    let fr = c.lang() == Lang::Fr;
    hero(c);
    c.push("\n<section id=\"locationMap\" class=\"site-padding\" style=\"padding-bottom:0px;\">\n    <main class=\"container\">\n        <div class=\"map-hotspot\">");
    c.html("map_shortcode");
    c.push("</div>\n    </main>\n</section>\n\n<section id=\"locationList\" class=\"site-padding\">\n    <main class=\"container\">\n       ");
    let rows = c.count("location");
    if rows > 0 {
        c.push("       <div class=\"row\">\n        ");
        for i in 0..rows {
            let f = |n: &str| format!("location_{i}_{n}");
            c.push("            <div class=\"col-12 col-lg-4 location-item\">\n               <div class=\"location-container\">\n                    <h3>");
            c.html(&f("name"));
            c.push("</h3>\n                    <p><i class=\"fas fa-map-marker-alt\"></i> ");
            c.html(&f("address"));
            c.push("</p>\n\t\t   \t\t    <p><i class=\"fas fa-phone\"></i> <a href=\"tel:");
            c.html(&f("phonef"));
            c.push("\">");
            c.html(&f("phone"));
            c.push("</a></p>\n                    <p><i class=\"fas fa-envelope\"></i> <a href=\"mailto:");
            c.html(&f("email"));
            c.push("\">");
            c.html(&f("email"));
            c.push("</a></p>\n                    <div class=\"text-center\">\n                        <a class=\"standard-btn red large\" href=\"");
            c.html(&f("form_link"));
            c.push("\">");
            c.push(if fr { "Nous contacter" } else { "Contact Us" });
            c.push("</a>\n                    </div>\n                </div>\n           </div>\n        ");
        }
        c.push("        </div>\n       ");
    }
    c.push("    </main>\n</section>\n\n<!-- <section id=\"integratorList\" class=\"site-padding\">\n    <main class=\"container\">\n      <h2>Harcourt Integrators</h2>\n              <div class=\"row\">\n                    <div class=\"col-12 col-lg-6 integrator-item\">\n               <div class=\"integrator-container\">\n                    <h3><i class=\"fas fa-map-marker-alt\"></i> </h3>\n                </div>\n           </div>\n                </div>\n           </main>\n</section>\n\n       --> \n<section class=\"black-radial-gradient site-padding-bl\">\n  ");
    cta(c);
    c.push("\n</section>\n\n\n\n");
}
