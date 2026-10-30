//! The posts of a list (an archive, the blog listing WordPress runs under a 404) as loops/index-post.php prints
//! each, and the_content() of the block content this port renders.

use crate::{
    catalog::wp_search::texturize::texturize,
    cms::{
        html::esc,
        media::Images,
        model::{Lang, MediaId, Page, PostType, Site},
        yoast,
    },
};

use super::{ORIGIN, UPLOADS};

/// The inner HTML do_blocks() prints for block content made only of `allowed` blocks (and the text between blocks
/// as it is): None for anything else (other or nested blocks, shortcodes, classic content), which this port does
/// not render. The block editor's markup needs no wpautop(), which do_blocks() turns off.
pub fn blocks(raw: &str, allowed: &[&str]) -> Option<String> {
    if !raw.contains("<!-- wp:") || raw.contains('[') {
        return None;
    }
    let mut out = String::with_capacity(raw.len());
    let mut rest = raw;
    while let Some(start) = rest.find("<!-- wp:") {
        out.push_str(&rest[..start]);
        let after = &rest[start + "<!-- wp:".len()..];
        let end = after.find("-->")?;
        let opener = after[..end].trim_end();
        let name = opener.split_whitespace().next()?;
        if !allowed.contains(&name) || opener.ends_with('/') {
            return None;
        }
        let close = format!("<!-- /wp:{name} -->");
        let inner = &after[end + 3..];
        let stop = inner.find(&close)?;
        if inner[..stop].contains("<!-- wp:") {
            return None;
        }
        out.push_str(&inner[..stop]);
        rest = &inner[stop + close.len()..];
    }
    out.push_str(rest);
    Some(out)
}

/// the_content() of paragraph blocks: their inner HTML, wptexturize()d.
pub fn paragraphs(raw: &str, lang: Lang) -> Option<String> {
    let html = blocks(raw, &["paragraph"])?;
    (!html.contains("<img")).then(|| texturize(&html, lang))
}

/// the_content() of a post of paragraphs and images, at its place in the page (the image loading attributes).
fn post_content(site: &Site, p: &Page, images: &mut Images) -> Option<String> {
    let raw = &site.fields(p.id).get("@post_content")?.value;
    let html = texturize(&blocks(raw, &["paragraph", "image"])?, p.lang);
    Some(images.filter_content_tags(&html, &site.media, super::UPLOADS))
}

const MONTHS_EN: [&str; 12] = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
const MONTHS_FR: [&str; 12] = ["janvier", "février", "mars", "avril", "mai", "juin", "juillet", "août", "septembre", "octobre", "novembre", "décembre"];

/// Days since 1970-01-01 of a civil date (Howard Hinnant's algorithm).
fn days_from_civil(y: i64, m: i64, d: i64) -> i64 {
    let y = if m <= 2 { y - 1 } else { y };
    let era = if y >= 0 { y } else { y - 399 } / 400;
    let yoe = y - era * 400;
    let doy = (153 * (if m > 2 { m - 3 } else { m + 9 }) + 2) / 5 + d - 1;
    let doe = yoe * 365 + yoe / 4 - yoe / 100 + doy;
    era * 146_097 + doe - 719_468
}

fn civil_from_days(z: i64) -> (i64, i64, i64) {
    let z = z + 719_468;
    let era = if z >= 0 { z } else { z - 146_096 } / 146_097;
    let doe = z - era * 146_097;
    let yoe = (doe - doe / 1460 + doe / 36_524 - doe / 146_096) / 365;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = doy - (153 * mp + 2) / 5 + 1;
    let m = if mp < 10 { mp + 3 } else { mp - 9 };
    (if m <= 2 { yoe + era * 400 + 1 } else { yoe + era * 400 }, m, d)
}

/// The site's timezone setting (timezone_string, which the dump does not carry): America/Detroit, US Eastern
/// time with daylight time from the second Sunday of March to the first Sunday of November, 2:00 local.
fn eastern_offset_hours(utc_secs: i64) -> i64 {
    let (y, _, _) = civil_from_days(utc_secs.div_euclid(86_400));
    let nth_sunday = |month: i64, n: i64| {
        let first = days_from_civil(y, month, 1);
        let weekday = (first + 4).rem_euclid(7);
        first + (7 - weekday) % 7 + 7 * (n - 1)
    };
    let starts = nth_sunday(3, 2) * 86_400 + 7 * 3600;
    let ends = nth_sunday(11, 1) * 86_400 + 6 * 3600;
    if (starts..ends).contains(&utc_secs) {
        -4
    } else {
        -5
    }
}

/// A stored GMT time as the site's local time: `date('c')` and the date in `lang`'s format.
fn local(gmt: &str, lang: Lang) -> Option<(String, String)> {
    let num = |s: Option<&str>| s.and_then(|s| s.parse::<i64>().ok());
    let mut parts = gmt.split([' ', '-', ':']);
    let (y, mo, d, h, mi, s) = (num(parts.next())?, num(parts.next())?, num(parts.next())?, num(parts.next())?, num(parts.next())?, num(parts.next())?);
    let utc = days_from_civil(y, mo, d) * 86_400 + h * 3600 + mi * 60 + s;
    let offset = eastern_offset_hours(utc);
    let t = utc + offset * 3600;
    let (ly, lm, ld) = civil_from_days(t.div_euclid(86_400));
    let secs = t.rem_euclid(86_400);
    let iso = format!("{ly:04}-{lm:02}-{ld:02}T{:02}:{:02}:{:02}{:+03}:00", secs / 3600, secs % 3600 / 60, secs % 60, offset);
    let month = usize::try_from(lm - 1).ok()?;
    let shown = match lang {
        Lang::En => format!("{} {ld}, {ly}", MONTHS_EN.get(month)?),
        Lang::Fr => format!("{ld} {} {ly}", MONTHS_FR.get(month)?),
    };
    Some((iso, shown))
}

/// momentumst_post_date(): the publication and update times of a post; nothing for other types.
fn post_date(p: &Page) -> Option<String> {
    if p.kind != PostType::Post {
        return Some(String::new());
    }
    let (published, shown) = local(&p.published_at, p.lang)?;
    if p.published_at == p.modified_at {
        return Some(format!("<time itemprop=\"datePublished\" class=\"entry-date published\" datetime=\"{published}\" content=\"{shown}\" >{shown}</time>"));
    }
    let (modified, modified_shown) = local(&p.modified_at, p.lang)?;
    Some(format!(
        "<time itemprop=\"datePublished\" class=\"entry-date published\" datetime=\"{published}\" content=\"{shown}\">{shown}</time> <time itemprop=\"dateModified\" class=\"updated\" datetime=\"{modified}\" content=\"{modified_shown}\">(updated {modified_shown})</time>"
    ))
}

/// A post's featured image, when it has one.
fn thumbnail(site: &Site, p: &Page) -> Option<MediaId> {
    site.fields(p.id).get("@thumbnail").and_then(|f| f.value.trim().parse::<i64>().ok()).filter(|id| *id > 0).map(MediaId)
}

/// post_class() of a post in a loop.
pub fn post_class(site: &Site, p: &Page) -> String {
    let kind = p.kind.as_str();
    let mut classes = vec![format!("post-{}", p.id.0), kind.to_string(), format!("type-{kind}"), "status-publish".to_string()];
    if p.kind == PostType::Post {
        classes.push("format-standard".into());
    }
    if thumbnail(site, p).is_some() {
        classes.push("has-post-thumbnail".into());
    }
    classes.push("hentry".into());
    for t in site.terms.iter().filter(|t| t.pages.contains(&p.id)) {
        classes.push(format!("{}-{}", t.taxonomy.as_str(), t.slug));
    }
    classes.join(" ")
}

/// loops/index-post.php for an FAQ or a post: the title, the post's dates, the author's link, the comments link,
/// the featured image and the content as the_content() prints it. None for a post this port does not render.
pub fn index_post(site: &Site, p: &Page, images: &mut Images) -> Option<String> {
    let content = match p.kind {
        // The content the import recorded with the FAQ's head.
        PostType::Faq if site.meta(p.id).contains_key("title") => site.fields(p.id).get("content")?.value.clone(),
        PostType::Post => String::new(),
        _ => return None,
    };
    let name = yoast::author_name(site, p.author_id)?;
    let slug = yoast::author_slug(site, p.author_id)?;
    let date = post_date(p)?;
    let thumb = match thumbnail(site, p) {
        Some(id) => images.post_thumbnail(site.media.get(&id), UPLOADS),
        None => String::new(),
    };
    let content = if p.kind == PostType::Post { post_content(site, p, images)? } else { content };
    let url = esc(&p.url(ORIGIN));
    let posts_by = match p.lang {
        Lang::En => "Posts by",
        Lang::Fr => "Articles par",
    };
    Some(format!(
        "\n  \n<article role=\"article\" id=\"post_{id}\" class=\"mb-5 {class}\" >\n  <header>\n    <h2>\n      <a href=\"{url}\">\n        {title}      </a>\n    </h2>\n    <p class=\"text-muted\">\n      <i class=\"far fa-calendar-alt\"></i>&nbsp;{date}&nbsp;|\n      <i class=\"far fa-user\"></i>&nbsp; By <a href=\"{ORIGIN}/author/{slug}/{q}\" title=\"{posts_by} {name}\" rel=\"author\">{name}</a>&nbsp;|\n      <i class=\"far fa-comment\"></i>&nbsp;<a href=\"{url}#respond\">0 Comments</a>\n    </p>\n  </header>\n  <div>\n    {thumb}\n    {content}  </div>\n</article>\n",
        id = p.id.0,
        class = post_class(site, p),
        title = texturize(&p.title, p.lang),
        q = p.lang.query(),
        name = esc(&name),
    ))
}

/// index.php's content column for a loop that found `posts`, with loops/index-post.php for each.
pub fn index_main(site: &Site, posts: &[&Page], images: &mut Images) -> Option<String> {
    let mut items = String::new();
    for p in posts {
        items.push_str(&index_post(site, p, images)?);
    }
    Some(format!(
        "\n\n<main class=\"container mt-5\">\n  <div class=\"row\">\n\n    <div class=\"col-sm\">\n      <div id=\"content\" role=\"main\"> 1\n\n        \n{items}\n      </div><!-- /#content -->\n    </div>\n\n    "
    ))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn local_times_follow_eastern_daylight_time() {
        assert_eq!(local("2022-02-16 16:45:00", Lang::En), Some(("2022-02-16T11:45:00-05:00".into(), "February 16, 2022".into())));
        assert_eq!(local("2026-10-22 19:38:09", Lang::Fr), Some(("2026-10-22T15:38:09-04:00".into(), "22 octobre 2026".into())));
        assert_eq!(local("2022-03-13 06:59:59", Lang::En).map(|t| t.0), Some("2022-03-13T01:59:59-05:00".into()));
        assert_eq!(local("2022-03-13 07:00:00", Lang::En).map(|t| t.0), Some("2022-03-13T03:00:00-04:00".into()));
        assert_eq!(local("2022-11-06 06:00:00", Lang::En).map(|t| t.0), Some("2022-11-06T01:00:00-05:00".into()));
        assert_eq!(local("2020-01-01 03:00:00", Lang::En).map(|t| t.1), Some("December 31, 2019".into()));
        assert_eq!(local("garbage", Lang::En), None);
    }

    #[test]
    fn blocks_print_their_inner_html() {
        let raw = "<!-- wp:paragraph -->\n<p>It's one.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph {\"x\":\"1\"} -->\n<p>Two</p>\n<!-- /wp:paragraph -->";
        assert_eq!(paragraphs(raw, Lang::En).as_deref(), Some("\n<p>It&#8217;s one.</p>\n\n\n\n<p>Two</p>\n"));
        assert_eq!(paragraphs("<!-- wp:paragraph -->\n<p>L'art</p>\n<!-- /wp:paragraph -->", Lang::Fr).as_deref(), Some("\n<p>L&rsquo;art</p>\n"));
        assert_eq!(paragraphs("<!-- wp:image -->\n<figure></figure>\n<!-- /wp:image -->", Lang::En), None);
        assert_eq!(blocks("<!-- wp:image -->\n<figure></figure>\n<!-- /wp:image -->", &["image"]).as_deref(), Some("\n<figure></figure>\n"));
        assert_eq!(paragraphs("<p>classic</p>", Lang::En), None);
        assert_eq!(paragraphs("<!-- wp:paragraph -->\n<p>[gallery]</p>\n<!-- /wp:paragraph -->", Lang::En), None);
        assert_eq!(blocks("<!-- wp:group --><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --><!-- /wp:group -->", &["group", "paragraph"]), None);
    }
}
