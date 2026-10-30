//! `<img>` tags as WordPress 6.8 prints them for `wp_get_attachment_image($id, 'full')`: srcset from the generated
//! sizes, and the loading attributes that depend on where on the page the image falls (wp-includes/media.php,
//! wp_get_loading_optimization_attributes: the first three images in the loop load eagerly, the first large image
//! in view gets fetchpriority="high", everything else is lazy).

use super::html::esc;
use super::model::Media;

/// Images smaller than this (width x height) never get fetchpriority="high" (wp_min_priority_img_pixels).
const MIN_PRIORITY_PIXELS: i64 = 50_000;
/// wp_omit_loading_attr_threshold().
const EAGER_IN_LOOP: u32 = 3;
const MAX_SRCSET_WIDTH: i64 = 2048;

/// Where the template is in the page, which decides an image's loading attributes.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Phase {
    /// After get_header(), before the loop: header images.
    Header,
    /// Inside the main loop (`while (have_posts()): the_post()`).
    Loop,
    /// After the loop: footer, sidebars.
    After,
}

/// Per-page state of WordPress's loading optimization.
#[derive(Debug, Clone)]
pub struct Images {
    phase: Phase,
    content_count: u32,
    high_priority_free: bool,
}

impl Default for Images {
    fn default() -> Self {
        Images { phase: Phase::Header, content_count: 0, high_priority_free: true }
    }
}

impl Images {
    pub fn set_phase(&mut self, phase: Phase) {
        self.phase = phase;
    }

    /// `wp_get_attachment_image($id, 'full')`. Empty when there is no such attachment.
    pub fn full(&mut self, media: Option<&Media>, uploads: &str) -> String {
        let Some(m) = media else { return String::new() };
        let src = format!("{uploads}{}", m.file);
        let mut attrs: Vec<(&str, String)> = vec![("src", src.clone()), ("class", "attachment-full size-full".into()), ("alt", m.alt.trim().to_string())];
        let loading = self.loading(m.width, m.height);
        attrs.extend(loading.iter().map(|(k, v)| (*k, v.to_string())));
        if let Some(srcset) = srcset(m, &src, uploads) {
            let mut sizes = format!("(max-width: {0}px) 100vw, {0}px", m.width);
            if loading.iter().any(|(k, v)| *k == "loading" && *v == "lazy") {
                sizes = format!("auto, {sizes}");
            }
            attrs.push(("srcset", srcset));
            attrs.push(("sizes", sizes));
        }
        let mut out = String::from("<img");
        if m.width > 0 {
            out.push_str(&format!(" width=\"{}\"", m.width));
        }
        if m.height > 0 {
            out.push_str(&format!(" height=\"{}\"", m.height));
        }
        for (k, v) in attrs {
            out.push_str(&format!(" {k}=\"{}\"", esc(&v)));
        }
        out.push_str(" />");
        out
    }

    /// Accounts for `<img>` tags WordPress already processed inside stored content (ACF WYSIWYG, post content),
    /// which counted towards the page's totals when WordPress rendered it.
    pub fn note_html(&mut self, html: &str) {
        if self.phase != Phase::Loop {
            return;
        }
        for tag in html.match_indices("<img").filter_map(|(i, _)| html[i..].find('>').map(|e| &html[i..i + e])) {
            if tag.contains(" width=") && tag.contains(" height=") {
                self.content_count += 1;
            }
            if tag.contains("fetchpriority=\"high\"") {
                self.high_priority_free = false;
            }
        }
    }

    fn loading(&mut self, width: i64, height: i64) -> Vec<(&'static str, &'static str)> {
        let mut out = vec![("decoding", "async")];
        if width == 0 || height == 0 {
            return out;
        }
        let (in_view, count, count_if_large) = match self.phase {
            Phase::Loop => (self.content_count < EAGER_IN_LOOP, true, false),
            Phase::Header => (true, false, true),
            Phase::After => (false, false, false),
        };
        let large = width * height >= MIN_PRIORITY_PIXELS;
        if in_view {
            if self.high_priority_free && large {
                out.push(("fetchpriority", "high"));
                self.high_priority_free = false;
            }
        } else {
            out.push(("loading", "lazy"));
        }
        if count || (count_if_large && large) {
            self.content_count += 1;
        }
        out
    }
}

/// wp_calculate_image_srcset() for the full-size image.
fn srcset(m: &Media, src: &str, uploads: &str) -> Option<String> {
    if m.sizes.is_empty() || m.file.len() < 4 || m.width < 1 {
        return None;
    }
    let (dir, base) = match m.file.rfind('/') {
        Some(i) => (&m.file[..=i], &m.file[i + 1..]),
        None => ("", m.file.as_str()),
    };
    let mut candidates: Vec<(&str, i64, i64)> = m.sizes.iter().map(|s| (s.file.as_str(), s.width, s.height)).collect();
    if m.mime == "image/gif" {
        if src.contains(&m.file) {
            return None;
        }
    } else {
        candidates.push((base, m.width, m.height));
    }
    let src_base = src.rsplit('/').next().unwrap_or(src);
    let edit_hash = edit_hash(src_base);
    let mut sources: Vec<(i64, String)> = Vec::new();
    let mut src_matched = false;
    for (file, width, height) in candidates {
        let mut is_src = false;
        if !src_matched && src.contains(&format!("{dir}{file}")) {
            src_matched = true;
            is_src = true;
        }
        if let Some(hash) = edit_hash {
            if !file.get(1..).is_some_and(|f| f.contains(hash)) {
                continue;
            }
        }
        if width > MAX_SRCSET_WIDTH && !is_src {
            continue;
        }
        if !matches_ratio(m.width, m.height, width, height) {
            continue;
        }
        let url = format!("{uploads}{dir}{file}");
        if is_src {
            sources.retain(|(w, _)| *w != width);
            sources.insert(0, (width, url));
        } else if let Some(slot) = sources.iter_mut().find(|(w, _)| *w == width) {
            slot.1 = url;
        } else {
            sources.push((width, url));
        }
    }
    if !src_matched || sources.len() < 2 {
        return None;
    }
    Some(sources.iter().map(|(w, url)| format!("{} {w}w", url.replace(' ', "%20"))).collect::<Vec<_>>().join(", "))
}

/// The `-e1234567890123` suffix WordPress gives an edited image's files.
fn edit_hash(name: &str) -> Option<&str> {
    let b = name.as_bytes();
    (0..b.len().saturating_sub(14)).find_map(|i| {
        (b[i] == b'-' && b[i + 1] == b'e' && b[i + 2..i + 15].iter().all(u8::is_ascii_digit)).then(|| &name[i..i + 15])
    })
}

/// wp_image_matches_ratio().
fn matches_ratio(source_w: i64, source_h: i64, target_w: i64, target_h: i64) -> bool {
    let (constrained, expected) = if source_w > target_w {
        (constrain(source_w, source_h, target_w, 0), (target_w, target_h))
    } else {
        (constrain(target_w, target_h, source_w, 0), (source_w, source_h))
    };
    (constrained.0 - expected.0).abs() <= 1 && (constrained.1 - expected.1).abs() <= 1
}

/// wp_constrain_dimensions().
fn constrain(w: i64, h: i64, max_w: i64, max_h: i64) -> (i64, i64) {
    if max_w == 0 && max_h == 0 {
        return (w, h);
    }
    let (mut wr, mut hr, mut did_w, mut did_h) = (1.0f64, 1.0f64, false, false);
    if max_w > 0 && w > 0 && w > max_w {
        wr = max_w as f64 / w as f64;
        did_w = true;
    }
    if max_h > 0 && h > 0 && h > max_h {
        hr = max_h as f64 / h as f64;
        did_h = true;
    }
    let (smaller, larger) = (wr.min(hr), wr.max(hr));
    let ratio = if php_round(w as f64 * larger) > max_w || php_round(h as f64 * larger) > max_h { smaller } else { larger };
    let mut out_w = php_round(w as f64 * ratio).max(1);
    let mut out_h = php_round(h as f64 * ratio).max(1);
    if did_w && out_w == max_w - 1 {
        out_w = max_w;
    }
    if did_h && out_h == max_h - 1 {
        out_h = max_h;
    }
    (out_w, out_h)
}

/// PHP round(): half away from zero.
fn php_round(x: f64) -> i64 {
    x.round() as i64
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::cms::model::{MediaId, MediaSize};

    const UP: &str = "https://www.harcourt.co/wp-content/uploads/";

    fn media(file: &str, w: i64, h: i64, sizes: &[(&str, i64, i64)]) -> Media {
        Media {
            id: MediaId(1),
            file: file.into(),
            mime: "image/png".into(),
            width: w,
            height: h,
            alt: String::new(),
            sizes: sizes.iter().map(|(f, w, h)| MediaSize { name: String::new(), file: f.to_string(), width: *w, height: *h }).collect(),
        }
    }

    #[test]
    fn srcset_lists_the_full_image_first_then_sizes_with_the_same_ratio() {
        let m = media("2019/10/cta-bg.png", 1398, 614, &[("cta-bg-683x300.png", 683, 300), ("cta-bg-150x150.png", 150, 150), ("cta-bg-768x337.png", 768, 337), ("cta-bg-1110x488.png", 1110, 488)]);
        let mut images = Images::default();
        images.set_phase(Phase::After);
        assert_eq!(
            images.full(Some(&m), UP),
            "<img width=\"1398\" height=\"614\" src=\"https://www.harcourt.co/wp-content/uploads/2019/10/cta-bg.png\" class=\"attachment-full size-full\" alt=\"\" decoding=\"async\" loading=\"lazy\" srcset=\"https://www.harcourt.co/wp-content/uploads/2019/10/cta-bg.png 1398w, https://www.harcourt.co/wp-content/uploads/2019/10/cta-bg-683x300.png 683w, https://www.harcourt.co/wp-content/uploads/2019/10/cta-bg-768x337.png 768w, https://www.harcourt.co/wp-content/uploads/2019/10/cta-bg-1110x488.png 1110w\" sizes=\"auto, (max-width: 1398px) 100vw, 1398px\" />"
        );
    }

    #[test]
    fn an_edited_image_only_offers_sizes_of_the_edit() {
        let m = media("2020/05/tools-e1589056220106.png", 1000, 732, &[("tools-300x220.png", 300, 220)]);
        let mut images = Images::default();
        images.set_phase(Phase::Loop);
        assert_eq!(
            images.full(Some(&m), UP),
            "<img width=\"1000\" height=\"732\" src=\"https://www.harcourt.co/wp-content/uploads/2020/05/tools-e1589056220106.png\" class=\"attachment-full size-full\" alt=\"\" decoding=\"async\" fetchpriority=\"high\" />"
        );
    }

    #[test]
    fn the_fourth_loop_image_is_lazy_and_only_the_first_large_one_is_high_priority() {
        let big = media("a.png", 1000, 700, &[]);
        let small = media("b.png", 189, 79, &[]);
        let mut images = Images::default();
        images.set_phase(Phase::Header);
        assert!(!images.full(Some(&small), UP).contains("fetchpriority"));
        images.set_phase(Phase::Loop);
        assert!(images.full(Some(&big), UP).contains("fetchpriority=\"high\""));
        let second = images.full(Some(&big), UP);
        assert!(!second.contains("fetchpriority") && !second.contains("loading"));
        assert!(!images.full(Some(&small), UP).contains("loading"));
        assert!(images.full(Some(&big), UP).contains("loading=\"lazy\""));
        assert_eq!(images.full(None, UP), "");
    }

    #[test]
    fn ratio_matching_allows_a_pixel_of_rounding() {
        assert!(matches_ratio(1398, 614, 683, 300));
        assert!(!matches_ratio(1398, 614, 150, 150));
        assert_eq!(edit_hash("tools-e1589056220106.png"), Some("-e1589056220106"));
        assert_eq!(edit_hash("tools.png"), None);
    }
}
