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
        self.attachment(media, uploads, "attachment-full size-full")
    }

    /// the_post_thumbnail(): the featured image at its full size (the theme sets no post-thumbnail size).
    pub fn post_thumbnail(&mut self, media: Option<&Media>, uploads: &str) -> String {
        self.attachment(media, uploads, "attachment-post-thumbnail size-post-thumbnail wp-post-image")
    }

    /// wp_filter_content_tags() on `<img>` tags of rendered content: an image of an attachment that exists gets its
    /// width and height (from the file its src names) and its srcset and sizes; every image gets the loading
    /// attributes for its place in the page, which need the dimensions beyond `decoding`.
    pub fn filter_content_tags(&mut self, html: &str, media: &std::collections::HashMap<super::model::MediaId, Media>, uploads: &str) -> String {
        let mut out = String::with_capacity(html.len() + 512);
        let mut rest = html;
        while let Some(at) = rest.find("<img") {
            let tail = &rest[at..];
            let Some(end) = tail.find('>').filter(|_| tail[4..].starts_with(|c: char| c.is_ascii_whitespace())) else {
                out.push_str(&rest[..at + 4]);
                rest = &rest[at + 4..];
                continue;
            };
            out.push_str(&rest[..at]);
            out.push_str(&self.content_image(&tail[..=end], media, uploads));
            rest = &tail[end + 1..];
        }
        out.push_str(rest);
        out
    }

    fn content_image(&mut self, tag: &str, media: &std::collections::HashMap<super::model::MediaId, Media>, uploads: &str) -> String {
        let attr = |tag: &str, name: &str| {
            let key = format!(" {name}=\"");
            tag.find(&key).map(|i| &tag[i + key.len()..]).and_then(|v| v.find('"').map(|e| v[..e].to_string()))
        };
        let mut tag = tag.to_string();
        let id = tag.to_ascii_lowercase().find("wp-image-").map(|i| tag[i + 9..].bytes().take_while(u8::is_ascii_digit).fold(0i64, |n, d| n.saturating_mul(10).saturating_add(i64::from(d - b'0'))));
        let attachment = id.filter(|id| *id > 0).and_then(|id| media.get(&super::model::MediaId(id)));
        let src = attr(&tag, "src").map(|s| s.split('?').next().unwrap_or("").to_string()).unwrap_or_default();
        if let Some(m) = attachment.filter(|_| !src.is_empty()) {
            if !tag.contains(" width=") && !tag.contains(" height=") {
                if let Some((w, h)) = src_dimensions(m, &src) {
                    tag = tag.replacen("<img", &format!("<img width=\"{w}\" height=\"{h}\""), 1);
                }
            }
            if !tag.contains(" srcset=") {
                let dims = match (attr(&tag, "width").and_then(|w| w.parse::<i64>().ok()), attr(&tag, "height").and_then(|h| h.parse::<i64>().ok())) {
                    (Some(w), Some(h)) if w > 0 && h > 0 => Some((w, h)),
                    _ => src_dimensions(m, &src),
                };
                if let Some((w, _)) = dims {
                    if let Some(srcset) = srcset(m, &src, uploads) {
                        let head = tag.trim_end_matches('>').trim_end_matches(['/', ' ']);
                        tag = format!("{head} srcset=\"{}\" sizes=\"(max-width: {w}px) 100vw, {w}px\" />", esc(&srcset));
                    }
                }
            }
        }
        if !tag.contains(" src=\"") {
            return tag;
        }
        let width = attr(&tag, "width").and_then(|w| w.parse::<i64>().ok()).unwrap_or(0);
        let height = attr(&tag, "height").and_then(|h| h.parse::<i64>().ok()).unwrap_or(0);
        let loading = self.loading(width, height);
        for (name, value) in loading {
            if attr(&tag, name).is_none() {
                tag = tag.replacen("<img", &format!("<img {name}=\"{value}\""), 1);
            }
        }
        // wp_img_tag_add_auto_sizes(): a lazy image's sizes start with auto.
        if tag.contains(" loading=\"lazy\"") && !tag.contains(" sizes=\"auto") {
            tag = tag.replacen(" sizes=\"", " sizes=\"auto, ", 1);
        }
        tag
    }

    fn attachment(&mut self, media: Option<&Media>, uploads: &str, class: &str) -> String {
        let Some(m) = media else { return String::new() };
        let src = format!("{uploads}{}", m.file);
        let mut attrs: Vec<(&str, String)> = vec![("src", src.clone()), ("class", class.into()), ("alt", m.alt.trim().to_string())];
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

/// wp_image_src_get_dimensions(): the size of the attachment's file a src names, the full image first.
fn src_dimensions(m: &Media, src: &str) -> Option<(i64, i64)> {
    let full = m.file.rsplit('/').next().unwrap_or(&m.file);
    if !full.is_empty() && src.contains(full) {
        return Some((m.width, m.height)).filter(|(w, h)| *w > 0 && *h > 0);
    }
    let name = src.rsplit('/').next().unwrap_or(src);
    m.sizes.iter().find(|s| s.file == name).map(|s| (s.width, s.height)).filter(|(w, h)| *w > 0 && *h > 0)
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
            name: String::new(),
            parent_id: None,
            lang: None,
            group_id: None,
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
    fn content_images_get_their_attachment_sizes_and_loading_attributes() {
        let m = media("2022/03/example.png", 1094, 770, &[("example-426x300.png", 426, 300), ("example-285x150.png", 285, 150), ("example-768x541.png", 768, 541)]);
        let library: std::collections::HashMap<_, _> = [(m.id, m)].into_iter().collect();
        let mut images = Images::default();
        images.set_phase(Phase::Loop);
        let html = "<figure><img src=\"https://www.harcourt.co/wp-content/uploads/2022/03/example.png\" alt=\"\" class=\"wp-image-1\"/></figure><p>x</p><figure><img src=\"https://www.harcourt.co/wp-content/uploads/2022/03/gone.png\" alt=\"\" class=\"wp-image-2\"/></figure>";
        assert_eq!(
            images.filter_content_tags(html, &library, UP),
            "<figure><img fetchpriority=\"high\" decoding=\"async\" width=\"1094\" height=\"770\" src=\"https://www.harcourt.co/wp-content/uploads/2022/03/example.png\" alt=\"\" class=\"wp-image-1\" srcset=\"https://www.harcourt.co/wp-content/uploads/2022/03/example.png 1094w, https://www.harcourt.co/wp-content/uploads/2022/03/example-426x300.png 426w, https://www.harcourt.co/wp-content/uploads/2022/03/example-768x541.png 768w\" sizes=\"(max-width: 1094px) 100vw, 1094px\" /></figure><p>x</p><figure><img decoding=\"async\" src=\"https://www.harcourt.co/wp-content/uploads/2022/03/gone.png\" alt=\"\" class=\"wp-image-2\"/></figure>"
        );
    }

    #[test]
    fn ratio_matching_allows_a_pixel_of_rounding() {
        assert!(matches_ratio(1398, 614, 683, 300));
        assert!(!matches_ratio(1398, 614, 150, 150));
        assert_eq!(edit_hash("tools-e1589056220106.png"), Some("-e1589056220106"));
        assert_eq!(edit_hash("tools.png"), None);
    }
}
