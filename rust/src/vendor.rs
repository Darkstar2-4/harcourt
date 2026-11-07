//! Third-party scripts and stylesheets served from our own copies (vendor_assets.py fills <docroot>/hx-vendor).
//! Only URLs with a local copy are rewritten, so a CDN file nobody copied keeps loading from its CDN.
//!
//! Also drops tags for WordPress code stylesheets and scripts that are not on disk. The theme links four Gravity
//! Forms files the plugin no longer ships (css/formsmain, readyclass, browsers .min.css and js/datepicker.js); each
//! came back as WordPress's 98 KB 404 page after 300-700 ms, and the browser does not paint until every stylesheet
//! and head script has answered. A file that 404s is never applied, so its tag can go with nothing changing on screen.
//! Only the code directories nginx answers from disk (plugins, themes, wp-includes) and only a file that is
//! certainly absent: any other error reading it keeps the tag.
//!
//! And starts the theme's fade-in sections when the page is parsed. The theme loads aos@next, now 3.0.0-beta.6,
//! which waits for the window load event when AOS.init runs while the page is still parsing, as the theme's inline
//! call at the end of <body> always does: every section stayed hidden until the last image on the page had loaded
//! (home: posters and photos, 1-4 s). The same call now runs once the page is parsed and the pictures at or above
//! the fold have loaded. AOS marks a section revealed for good (once: true) by where it sits at init, so init has to
//! wait for those pictures: one without width/height is 0 px tall until it loads, and a section below it would be
//! taken as on screen, never to fade in when scrolled to. Pictures further down only move what is already below.
//!
//! The theme's Google Fonts stylesheet is render-blocking from a third-party host: when fonts.googleapis.com is slow
//! the whole page stays blank (seen once at 1.9 s on the bench). It is served from our copy (vendor_assets.py
//! FONT_CSS); the font files stay on Google's CDN. Font Awesome's stylesheets stay third-party: copying them (54 KB)
//! cost ~13 ms on every first visit (attempts.tsv A33).

use std::{
    collections::HashSet,
    io::ErrorKind,
    path::{Path, PathBuf},
};

use regex::Regex;

/// Calls `start` once the page is parsed and every rendered picture whose top is above the bottom of the window has
/// loaded (or failed), and at window load at the latest, which is when the theme's own call took effect. ES5, like the
/// theme's inline scripts.
const AOS_START: &str = "(function (start) { var started = false; \
var go = function () { if (!started) { started = true; start(); } }; addEventListener('load', go); \
document.addEventListener('DOMContentLoaded', function () { \
var wait = [].filter.call(document.images, function (i) { return !i.complete && i.getClientRects().length && i.getBoundingClientRect().top < innerHeight; }); \
var left = wait.length; if (!left) return go(); \
var done = function () { if (--left === 0) go(); }; \
wait.forEach(function (i) { i.addEventListener('load', done); i.addEventListener('error', done); }); }); })";

/// (repeat, original): a script tag for `repeat` goes when `original` was the last script the page loaded before it.
/// The theme head loads jQuery UI 1.12.1 twice in a row onto the same jQuery: Google's minified build, then
/// code.jquery.com's unminified copy of the same release (same 2016-09-14 build and module list, 520 KB to parse
/// and run again). The catalog's own jQuery UI tag follows a different jQuery, so it is not a repeat.
const REPEATS: [(&str, &str); 1] = [("code.jquery.com/ui/1.12.1/jquery-ui.js", "ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js")];

pub struct Vendor {
    files: HashSet<String>,
    url: Regex,
    docroot: PathBuf,
    local: Regex,
    aos: Regex,
    font_css: Regex,
}

impl Vendor {
    pub fn load(docroot: &Path, site: &str) -> Self {
        let dir = docroot.join("hx-vendor");
        let dir = dir.as_path();
        let mut files = HashSet::new();
        let mut stack = vec![dir.to_path_buf()];
        while let Some(d) = stack.pop() {
            for e in std::fs::read_dir(&d).into_iter().flatten().flatten() {
                let p = e.path();
                if p.is_dir() {
                    stack.push(p);
                } else if let Ok(rel) = p.strip_prefix(dir) {
                    files.insert(rel.to_string_lossy().into_owned());
                }
            }
        }
        tracing::info!("vendored files: {}", files.len());
        Vendor {
            files,
            url: Regex::new(r#"(?:https:)?//((?:cdnjs\.cloudflare\.com|unpkg\.com|ajax\.googleapis\.com|code\.jquery\.com)/[^'"?\s)]+)"#).unwrap(),
            docroot: docroot.to_path_buf(),
            local: Regex::new(&format!(
                r#"(?i)<(?:link\b[^>]*\bhref|script\b[^>]*\bsrc)=["'](?:(?:https?:)?//{})?(/wp-(?:content/(?:plugins|themes)|includes)/[^"'?#]+\.(?:css|js))(?:[?#][^"']*)?["'][^>]*>(?:\s*</script>)?\n?"#,
                regex::escape(site)
            ))
            .unwrap(),
            aos: Regex::new(r"<script>(\s*)AOS\.init\((\{[^<]*?\})\);(\s*)</script>").unwrap(),
            font_css: Regex::new(r#"https://fonts\.googleapis\.com/css\?family=([^&'"\s]+)([^'"\s]*)"#).unwrap(),
        }
    }

    pub fn localize(&self, html: &str) -> String {
        let html = if crate::on("repeats") { drop_repeats(html) } else { html.to_string() };
        let html = if crate::on("aos") {
            static WARNED: std::sync::atomic::AtomicBool = std::sync::atomic::AtomicBool::new(false);
            if html.contains("AOS.init(") && !self.aos.is_match(&html) && !WARNED.swap(true, std::sync::atomic::Ordering::Relaxed) {
                tracing::warn!("page calls AOS.init in a shape the aos rewrite does not know; its fade-ins wait for load");
            }
            self.aos.replace_all(&html, format!("<script>${{1}}{AOS_START}(function () {{ AOS.init(${{2}}); }});${{3}}</script>").as_str()).into_owned()
        } else {
            html
        };
        let html = self.local.replace_all(&html, |c: &regex::Captures| {
            if crate::on("dead-assets") && !c[1].contains("..") && self.missing(&c[1]) {
                tracing::debug!("dropped tag for missing {}", &c[1]);
                String::new()
            } else {
                c[0].to_string()
            }
        });
        let html = self.url.replace_all(&html, |c: &regex::Captures| {
            if self.files.contains(&c[1]) { format!("/hx-vendor/{}", &c[1]) } else { c[0].to_string() }
        });
        if !crate::on("fonts-css") {
            return html.into_owned();
        }
        self.font_css
            .replace_all(&html, |c: &regex::Captures| {
                let display = c[2].split(['&', ';']).find_map(|kv| kv.strip_prefix("display=")).unwrap_or("");
                let rel = format!("fonts.googleapis.com/css/{}", font_css_name(&c[1], display));
                if self.files.contains(&rel) { format!("/hx-vendor/{rel}") } else { c[0].to_string() }
            })
            .into_owned()
    }

    fn missing(&self, path: &str) -> bool {
        matches!(std::fs::metadata(self.docroot.join(&path[1..])), Err(e) if e.kind() == ErrorKind::NotFound)
    }
}

/// Same rule as vendor_assets.py font_css_name: the family and display parameters as the page writes them.
fn font_css_name(family: &str, display: &str) -> String {
    let raw = format!("{family}_{display}");
    let mut name = String::new();
    for part in raw.split(|ch: char| !ch.is_ascii_alphanumeric()).filter(|p| !p.is_empty()) {
        if !name.is_empty() {
            name.push('_');
        }
        name.push_str(part);
    }
    name + ".css"
}

fn drop_repeats(html: &str) -> String {
    let mut html = html.to_string();
    for (repeat, original) in REPEATS {
        let Some(at) = html.find(repeat) else { continue };
        let Some(start) = html[..at].rfind("<script") else { continue };
        // the URL must be inside that tag (its src), not in script text or another element
        if html[start..at].contains('>') {
            continue;
        }
        let Some(len) = html[at..].find("</script>") else { continue };
        let end = at + len + "</script>".len();
        let before = &html[..start];
        let previous = before.rfind("<script").map(|p| &before[p..]).unwrap_or("");
        let previous_tag = &previous[..previous.find('>').unwrap_or(previous.len())];
        if previous_tag.contains(original) && previous.matches("<script").count() == 1 {
            let end = if html[end..].starts_with('\n') { end + 1 } else { end };
            html.replace_range(start..end, "");
        }
    }
    html
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_copied_files() {
        let v = Vendor { files: ["cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js".to_string()].into(), ..Vendor::load(Path::new("/nonexistent"), "www.harcourt.co") };
        let html = r#"<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js?ver=3.3.1"></script><script src='//cdnjs.cloudflare.com/ajax/libs/other.js'></script>"#;
        assert_eq!(
            v.localize(html),
            r#"<script src="/hx-vendor/cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js?ver=3.3.1"></script><script src='//cdnjs.cloudflare.com/ajax/libs/other.js'></script>"#
        );
    }

    #[test]
    fn repeats_only_right_after_their_twin() {
        let twin = "<script type=\"text/javascript\" src=\"//ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js\"></script>\n";
        let repeat = "<script src=\"https://code.jquery.com/ui/1.12.1/jquery-ui.js\"></script>\n";
        let style = "<style media=\"screen\">a{}</style>\n";
        assert_eq!(drop_repeats(&format!("{twin}{style}{repeat}<p>")), format!("{twin}{style}<p>"));
        let other = "<script src=\"/hx-vendor/ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js\"></script>\n";
        let kept = format!("{twin}{other}{repeat}");
        assert_eq!(drop_repeats(&kept), kept);
        assert_eq!(drop_repeats(repeat), repeat);
        // the URLs only mentioned in script text: nothing goes
        let text = "<script>var a = 'ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js';</script>\n<script>load('code.jquery.com/ui/1.12.1/jquery-ui.js');</script>\n";
        assert_eq!(drop_repeats(text), text);
    }

    #[test]
    fn google_fonts_css_from_copy() {
        let name = "fonts.googleapis.com/css/Open_Sans_3A400_2C700_7CRajdhani_3A400_2C600_2C700_swap.css";
        let v = Vendor { files: [name.to_string()].into(), ..Vendor::load(Path::new("/nonexistent"), "www.harcourt.co") };
        let theme = "<link rel='stylesheet' id='google-fonts-css' href='https://fonts.googleapis.com/css?family=Open+Sans%3A400%2C700%7CRajdhani%3A400%2C600%2C700&#038;display=swap&#038;ver=6.8.2' type='text/css' media='all' />";
        assert_eq!(v.localize(theme), format!("<link rel='stylesheet' id='google-fonts-css' href='/hx-vendor/{name}' type='text/css' media='all' />"));
        let catalog = "<link href=\"https://fonts.googleapis.com/css?family=Rajdhani:400,600,700|Open+Sans:400italic|Oswald:400,700\" rel=\"stylesheet\">";
        assert_eq!(v.localize(catalog), catalog);
    }

    #[test]
    fn aos_starts_when_parsed() {
        let v = Vendor::load(Path::new("/nonexistent"), "www.harcourt.co");
        let theme = "</div>\n<script>\nAOS.init({\n  disable: 'mobile',\n  once: true,\n});\n</script>\n</body>";
        assert_eq!(
            v.localize(theme),
            format!("</div>\n<script>\n{AOS_START}(function () {{ AOS.init({{\n  disable: 'mobile',\n  once: true,\n}}); }});\n</script>\n</body>")
        );
        let other = "<script>AOS.init(); foo();</script><script>x.AOS.init({});</script>";
        assert_eq!(v.localize(other), other);
    }

    #[test]
    fn drops_missing_wordpress_files() {
        let root = std::env::temp_dir().join("hxweb-vendor-test");
        std::fs::create_dir_all(root.join("wp-content/plugins/x")).unwrap();
        std::fs::write(root.join("wp-content/plugins/x/here.css"), "").unwrap();
        let v = Vendor::load(&root, "www.harcourt.co");
        let html = concat!(
            "<link rel='stylesheet' id='a'  href='/wp-content/plugins/x/here.css' type='text/css' media='all' />\n",
            "<link rel='stylesheet' id='b'  href='/wp-content/plugins/x/gone.min.css' type='text/css' media='all' />\n",
            "<script type=\"text/javascript\" src=\"https://www.harcourt.co/wp-content/plugins/x/gone.js?ver=6.8.2\" id=\"c\"></script>\n",
            "<link rel='stylesheet' href='/wp-content/uploads/generated/gone.css'>\n",
            "<script src=\"/scs_scripts/elsewhere.js\"></script>\n",
            "<link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/y/1/y.css'>\n",
        );
        assert_eq!(
            v.localize(html),
            concat!(
                "<link rel='stylesheet' id='a'  href='/wp-content/plugins/x/here.css' type='text/css' media='all' />\n",
                "<link rel='stylesheet' href='/wp-content/uploads/generated/gone.css'>\n",
                "<script src=\"/scs_scripts/elsewhere.js\"></script>\n",
                "<link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/y/1/y.css'>\n",
            )
        );
    }

    #[cfg(unix)]
    #[test]
    fn unreadable_is_not_missing() {
        use std::os::unix::fs::PermissionsExt;
        let root = std::env::temp_dir().join("hxweb-vendor-test-perm");
        let locked = root.join("wp-content/plugins/locked");
        std::fs::create_dir_all(&locked).unwrap();
        std::fs::write(locked.join("a.css"), "").unwrap();
        std::fs::set_permissions(&locked, std::fs::Permissions::from_mode(0o000)).unwrap();
        let v = Vendor::load(&root, "www.harcourt.co");
        let tag = "<link rel='stylesheet' href='/wp-content/plugins/locked/a.css'>\n";
        let out = v.localize(tag);
        std::fs::set_permissions(&locked, std::fs::Permissions::from_mode(0o755)).unwrap();
        // root can read anything, so only assert when the test runs unprivileged
        if std::fs::metadata("/root/.").is_err() {
            assert_eq!(out, tag);
        }
    }
}
