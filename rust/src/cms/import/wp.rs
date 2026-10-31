//! WordPress tables (wp_posts, wp_postmeta, wp_options, WPML's wp_icl_translations, the nav_menu terms) to the cms
//! model. Field values come out as WordPress stores them; `export` then replaces the text of every field a
//! ported template prints with what the page actually showed.

use std::collections::HashMap;

use super::{
    dump::{Table, Tables, Value},
    Import,
};
use crate::serialized::{self, Php};
use crate::cms::model::{Author, Field, Fields, Kind, Lang, Media, MediaId, MediaSize, Menu, MenuItem, Page, PageId, PostType, Status, Taxonomy, Template, Term};

/// (WPML element type, element id) -> (language, translation group).
type LangOf = HashMap<(String, i64), (Lang, i64)>;

struct Post {
    id: i64,
    kind: String,
    status: String,
    name: String,
    parent: i64,
    title: String,
    content: String,
    date_gmt: String,
    modified_gmt: String,
    menu_order: i64,
    mime: String,
    author: i64,
}

fn rows<'a>(tables: &'a Tables, name: &str, cols: &[&str]) -> Result<Vec<Vec<&'a Value>>, String> {
    let Some(t) = tables.get(name) else { return Ok(Vec::new()) };
    let idx = cols.iter().map(|c| t.col(c).ok_or_else(|| format!("{name} has no column {c}"))).collect::<Result<Vec<_>, _>>()?;
    Ok(t.rows.iter().map(|r| idx.iter().map(|&i| &r[i]).collect()).collect())
}

fn required<'a>(tables: &'a Tables, name: &str) -> Result<&'a Table, String> {
    tables.get(name).ok_or_else(|| format!("the dump has no {name} rows"))
}

pub fn read(tables: &Tables) -> Result<Import, String> {
    required(tables, "wp_posts")?;
    required(tables, "wp_postmeta")?;
    required(tables, "wp_options")?;
    required(tables, "wp_icl_translations")?;
    let posts: HashMap<i64, Post> = rows(
        tables,
        "wp_posts",
        &["ID", "post_type", "post_status", "post_name", "post_parent", "post_title", "post_content", "post_date_gmt", "post_modified_gmt", "menu_order", "post_mime_type", "post_author"],
    )?
    .into_iter()
    .map(|r| {
        let p = Post {
            id: r[0].int(),
            kind: r[1].str().into(),
            status: r[2].str().into(),
            name: r[3].str().into(),
            parent: r[4].int(),
            title: r[5].str().into(),
            content: r[6].str().into(),
            date_gmt: r[7].str().into(),
            modified_gmt: r[8].str().into(),
            menu_order: r[9].int(),
            mime: r[10].str().into(),
            author: r[11].int(),
        };
        (p.id, p)
    })
    .collect();
    // The hand-written excerpt the_excerpt() prints instead of the content's first words (a dump may lack it).
    let excerpts: HashMap<i64, String> =
        rows(tables, "wp_posts", &["ID", "post_excerpt"]).unwrap_or_default().into_iter().filter(|r| !r[1].str().is_empty()).map(|r| (r[0].int(), r[1].str().into())).collect();
    // The guid the feeds print for a post.
    let guids: HashMap<i64, String> =
        rows(tables, "wp_posts", &["ID", "guid"]).unwrap_or_default().into_iter().filter(|r| !r[1].str().is_empty()).map(|r| (r[0].int(), r[1].str().into())).collect();
    let mut meta: HashMap<i64, Vec<(String, String)>> = HashMap::new();
    for r in rows(tables, "wp_postmeta", &["post_id", "meta_key", "meta_value"])? {
        meta.entry(r[0].int()).or_default().push((r[1].str().into(), r[2].str().into()));
    }
    let options: HashMap<String, String> =
        rows(tables, "wp_options", &["option_name", "option_value"])?.into_iter().map(|r| (r[0].str().into(), r[1].str().into())).collect();
    let mut lang_of: LangOf = HashMap::new();
    for r in rows(tables, "wp_icl_translations", &["element_type", "element_id", "trid", "language_code"])? {
        if let Some(lang) = Lang::parse(r[3].str()) {
            lang_of.insert((r[0].str().into(), r[1].int()), (lang, r[2].int()));
        }
    }
    let acf_types: HashMap<String, String> = posts
        .values()
        .filter(|p| p.kind == "acf-field")
        .filter_map(|p| {
            let settings = serialized::unserialize(&p.content).ok()?;
            if !p.name.starts_with("field_") {
                return None;
            }
            Some((p.name.clone(), settings.get("type")?.as_str()?.to_string()))
        })
        .collect();
    let front = options.get("page_on_front").and_then(|v| v.parse::<i64>().ok()).unwrap_or(0);
    let front_group = lang_of.get(&("post_page".into(), front)).map(|(_, trid)| *trid);

    let mut out = Import::default();
    let path_of = |id: i64| page_path(id, &posts, front_group.map(|g| (g, &lang_of)));
    for p in posts.values().filter(|p| p.status == "publish" || p.status == "draft") {
        let Some(kind) = PostType::parse(&p.kind) else { continue };
        let Some(&(lang, group)) = lang_of.get(&(format!("post_{}", p.kind), p.id)) else { continue };
        let template = match kind {
            PostType::Page => {
                let file = meta_value(&meta, p.id, "_wp_page_template").unwrap_or("");
                Template::parse(file).ok_or_else(|| format!("page {} uses unknown template {file:?}", p.id))?
            }
            PostType::Faq => Template::SingleFaq,
            _ => Template::Single,
        };
        let draft = p.status == "draft";
        // A draft's permalink is its ?page_id= (or ?p=) link; WPML redirects to it, so it is kept for that.
        let path = match (draft, kind) {
            (true, PostType::Page) => format!("/?page_id={}", p.id),
            (true, _) => format!("/?p={}", p.id),
            (false, PostType::Page) => path_of(p.id),
            (false, _) => format!("{}/{}/", kind.base(), p.name),
        };
        out.pages.push(Page {
            id: PageId(p.id),
            kind,
            name: p.name.clone(),
            group_id: group,
            lang,
            path,
            template,
            status: if draft { Status::Draft } else { Status::Publish },
            title: p.title.clone(),
            parent_id: (p.parent != 0).then_some(PageId(p.parent)),
            menu_order: p.menu_order,
            published_at: p.date_gmt.clone(),
            modified_at: p.modified_gmt.clone(),
            author_id: (p.author != 0).then_some(p.author),
        });
        let mut fields = acf_fields(meta.get(&p.id).map(Vec::as_slice).unwrap_or(&[]), &acf_types, "");
        fields.insert("content".into(), Field { kind: Kind::Html, value: p.content.clone() });
        // WordPress's search reads the stored post, which the export's rendered `content` replaces for pages.
        fields.insert("@post_content".into(), Field { kind: Kind::Choice, value: p.content.clone() });
        if let Some(excerpt) = excerpts.get(&p.id) {
            fields.insert("@excerpt".into(), Field { kind: Kind::Choice, value: excerpt.clone() });
        }
        if let Some(guid) = guids.get(&p.id).filter(|_| kind != PostType::Page) {
            fields.insert("@guid".into(), Field { kind: Kind::Choice, value: guid.clone() });
        }
        if let Some(thumb) = meta_value(&meta, p.id, "_thumbnail_id") {
            fields.insert("@thumbnail".into(), Field { kind: Kind::Media, value: thumb.to_string() });
        }
        out.fields.insert(PageId(p.id), fields);
        if meta_value(&meta, p.id, "_yoast_wpseo_meta-robots-noindex") == Some("1") {
            out.meta.entry(PageId(p.id)).or_default().insert("noindex".into(), "1".into());
        }
    }
    out.pages.sort_by_key(|p| p.id);

    let option_rows: Vec<(String, String)> = options.iter().map(|(k, v)| (k.clone(), v.clone())).collect();
    out.options.insert(Lang::En, acf_fields(&option_rows, &acf_types, "options_"));
    out.options.insert(Lang::Fr, acf_fields(&option_rows, &acf_types, "options_fr_"));
    for lang in Lang::ALL {
        if let Some(fields) = out.options.get_mut(&lang) {
            fields.retain(|name, _| !name.starts_with("fr_"));
        }
    }
    if let Some(privacy) = options.get("wp_page_for_privacy_policy") {
        out.options.entry(Lang::En).or_default().insert("@privacy_page".into(), Field { kind: Kind::Choice, value: privacy.clone() });
    }
    // Yoast's title templates and noindex settings, and the site name its titles end with: heads.rs builds the head
    // of a post the export left out from them.
    let en = out.options.entry(Lang::En).or_default();
    if let Some(name) = options.get("blogname") {
        en.insert("@blogname".into(), Field { kind: Kind::Choice, value: name.clone() });
    }
    if let Some(tagline) = options.get("blogdescription") {
        en.insert("@blogdescription".into(), Field { kind: Kind::Choice, value: tagline.clone() });
    }
    if let Some(titles) = options.get("wpseo_titles").and_then(|v| serialized::unserialize(v).ok()) {
        for (k, v) in titles.items() {
            let Php::Str(key) = k else { continue };
            if !(key.starts_with("title-") || key.starts_with("noindex-") || key == "separator") {
                continue;
            }
            let value = match v {
                Php::Str(s) => s.clone(),
                Php::Bool(b) => if *b { "1" } else { "" }.to_string(),
                Php::Int(i) => i.to_string(),
                _ => continue,
            };
            en.insert(format!("@wpseo:{key}"), Field { kind: Kind::Choice, value });
        }
    }

    out.media = posts.values().filter(|p| p.kind == "attachment").filter_map(|p| media(p, &meta, &lang_of)).collect();
    out.media.sort_by_key(|m| m.id.0);

    out.menus = menus(tables, &posts, &meta, &options, &lang_of, &out.pages)?;
    out.terms = terms(tables, &posts, &lang_of, &out.pages)?;
    out.authors = authors(tables, &out.pages)?;
    Ok(out)
}

/// Categories and FAQ types with their WPML language, the imported posts in each, and Yoast's lastmod for each: the
/// newest post_modified_gmt of anything published or inherited filed under it.
fn terms(tables: &Tables, posts: &HashMap<i64, Post>, lang_of: &LangOf, pages: &[Page]) -> Result<Vec<Term>, String> {
    let names: HashMap<i64, (String, String)> =
        rows(tables, "wp_terms", &["term_id", "name", "slug"])?.into_iter().map(|r| (r[0].int(), (r[1].str().to_string(), r[2].str().to_string()))).collect();
    let mut members: HashMap<i64, Vec<i64>> = HashMap::new();
    for r in rows(tables, "wp_term_relationships", &["object_id", "term_taxonomy_id"])? {
        members.entry(r[1].int()).or_default().push(r[0].int());
    }
    let mut out = Vec::new();
    for r in rows(tables, "wp_term_taxonomy", &["term_taxonomy_id", "term_id", "taxonomy"])? {
        let Some(taxonomy) = Taxonomy::parse(r[2].str()) else { continue };
        let (tt, id) = (r[0].int(), r[1].int());
        let Some((name, slug)) = names.get(&id) else { continue };
        let objects = members.get(&tt).map(Vec::as_slice).unwrap_or(&[]);
        let modified_at = objects
            .iter()
            .filter_map(|o| posts.get(o))
            .filter(|p| p.status == "publish" || p.status == "inherit")
            .map(|p| p.modified_gmt.clone())
            .max()
            .unwrap_or_default();
        let translation = lang_of.get(&(format!("tax_{}", taxonomy.as_str()), tt)).copied();
        let mut ids: Vec<PageId> = objects.iter().map(|&o| PageId(o)).filter(|o| pages.iter().any(|p| p.id == *o)).collect();
        ids.sort();
        out.push(Term {
            id,
            taxonomy,
            slug: slug.clone(),
            name: name.clone(),
            lang: translation.map(|t| t.0),
            group_id: translation.map(|t| t.1),
            modified_at,
            pages: ids,
        });
    }
    out.sort_by(|a, b| (a.taxonomy, &a.name, a.id).cmp(&(b.taxonomy, &b.name, b.id)));
    Ok(out)
}

/// The authors of the imported posts, with the time Yoast last recorded a change to their profile.
fn authors(tables: &Tables, pages: &[Page]) -> Result<Vec<Author>, String> {
    let updated: HashMap<i64, i64> = rows(tables, "wp_usermeta", &["user_id", "meta_key", "meta_value"])?
        .into_iter()
        .filter(|r| r[1].str() == "_yoast_wpseo_profile_updated")
        .map(|r| (r[0].int(), r[2].int()))
        .collect();
    let mut out: Vec<Author> = rows(tables, "wp_users", &["ID", "user_nicename", "display_name"])?
        .into_iter()
        .filter(|r| pages.iter().any(|p| p.author_id == Some(r[0].int())))
        .map(|r| Author { id: r[0].int(), nicename: r[1].str().into(), display_name: r[2].str().into(), profile_updated: updated.get(&r[0].int()).copied().unwrap_or(0) })
        .collect();
    out.sort_by_key(|a| a.id);
    Ok(out)
}

fn meta_value<'a>(meta: &'a HashMap<i64, Vec<(String, String)>>, id: i64, key: &str) -> Option<&'a str> {
    meta.get(&id)?.iter().find(|(k, _)| k == key).map(|(_, v)| v.as_str())
}

/// The value rows of one ACF owner (a post, or the options page with `prefix`), keyed by field name. Every
/// `name` that has an `_name` row pointing at a field key is an ACF field; other keys without a leading
/// underscore are kept as raw choices.
fn acf_fields(rows: &[(String, String)], types: &HashMap<String, String>, prefix: &str) -> Fields {
    let keys: HashMap<&str, &str> = rows.iter().filter_map(|(k, v)| Some((k.strip_prefix('_')?, v.as_str()))).collect();
    let mut out = Fields::new();
    for (k, v) in rows {
        if k.starts_with('_') {
            continue;
        }
        let Some(name) = k.strip_prefix(prefix) else { continue };
        let acf_type = keys.get(k.as_str()).and_then(|key| types.get(*key)).map(String::as_str);
        if prefix.is_empty() || acf_type.is_some() {
            if let Some(field) = field(acf_type, v) {
                out.insert(name.to_string(), field);
            }
        }
    }
    out
}

fn field(acf_type: Option<&str>, raw: &str) -> Option<Field> {
    let (kind, value) = match acf_type {
        Some("group" | "clone" | "tab" | "message" | "accordion") => return None,
        Some("text" | "textarea" | "wysiwyg" | "url" | "email" | "number" | "password" | "oembed") => (Kind::Html, raw.to_string()),
        Some("true_false") => (Kind::Flag, if raw == "1" { "1".into() } else { String::new() }),
        Some("repeater") => (Kind::Count, raw.trim().parse::<u32>().unwrap_or(0).to_string()),
        Some("image" | "file") => (Kind::Media, raw.trim().to_string()),
        Some("link") => (Kind::Link, link_json(raw)),
        _ => (Kind::Choice, choice_json(raw)),
    };
    Some(Field { kind, value })
}

/// Serialized arrays (checkboxes, relationships, flexible content layouts) as JSON arrays of strings; any other
/// value as is.
fn choice_json(raw: &str) -> String {
    if !serialized::is_serialized(raw) {
        return raw.to_string();
    }
    match serialized::unserialize(raw) {
        Ok(Php::Array(items)) => serde_json::to_string(
            &items.iter().map(|(_, v)| v.as_str().map(str::to_string).or_else(|| v.as_int().map(|i| i.to_string())).unwrap_or_default()).collect::<Vec<_>>(),
        )
        .unwrap_or_default(),
        _ => raw.to_string(),
    }
}

fn link_json(raw: &str) -> String {
    let Ok(v) = serialized::unserialize(raw) else { return serde_json::json!({ "url": "", "title": "", "target": "" }).to_string() };
    let s = |k: &str| v.get(k).and_then(Php::as_str).unwrap_or("").to_string();
    serde_json::json!({ "url": s("url"), "title": s("title"), "target": s("target") }).to_string()
}

/// get_page_uri() with a trailing slash; the front page (and its translations) is "/".
fn page_path(id: i64, posts: &HashMap<i64, Post>, front: Option<(i64, &LangOf)>) -> String {
    if let Some((group, langs)) = front {
        if langs.get(&("post_page".into(), id)).is_some_and(|(_, trid)| *trid == group) {
            return "/".into();
        }
    }
    let mut parts = Vec::new();
    let mut next = Some(id);
    while let Some(p) = next.and_then(|i| posts.get(&i)) {
        if parts.len() > 16 {
            break;
        }
        parts.push(p.name.as_str());
        next = (p.parent != 0).then_some(p.parent);
    }
    parts.reverse();
    format!("/{}/", parts.join("/"))
}

fn media(p: &Post, meta: &HashMap<i64, Vec<(String, String)>>, lang_of: &LangOf) -> Option<Media> {
    let translation = lang_of.get(&("post_attachment".to_string(), p.id)).copied();
    let file = meta_value(meta, p.id, "_wp_attached_file")?.to_string();
    let info = meta_value(meta, p.id, "_wp_attachment_metadata").and_then(|m| serialized::unserialize(m).ok());
    let int = |k: &str| info.as_ref().and_then(|i| i.get(k)).and_then(Php::as_int).unwrap_or(0);
    let sizes = info
        .as_ref()
        .and_then(|i| i.get("sizes"))
        .map(|s| {
            s.items()
                .iter()
                .filter_map(|(name, size)| {
                    Some(MediaSize {
                        name: name.as_str().map(str::to_string).or_else(|| name.as_int().map(|i| i.to_string()))?,
                        file: size.get("file")?.as_str()?.to_string(),
                        width: size.get("width").and_then(Php::as_int).unwrap_or(0),
                        height: size.get("height").and_then(Php::as_int).unwrap_or(0),
                    })
                })
                .collect()
        })
        .unwrap_or_default();
    Some(Media {
        id: MediaId(p.id),
        file,
        mime: p.mime.clone(),
        width: int("width"),
        height: int("height"),
        alt: meta_value(meta, p.id, "_wp_attachment_image_alt").unwrap_or("").to_string(),
        sizes,
        name: p.name.clone(),
        parent_id: (p.parent != 0).then_some(PageId(p.parent)),
        lang: translation.map(|t| t.0),
        group_id: translation.map(|t| t.1),
    })
}

/// The navbar menu (theme location "navbar") and the footer menu (the nav_menu widget in footer-widget-area), in
/// each language: WPML keeps one menu term per language, linked by a translation group.
fn menus(
    tables: &Tables,
    posts: &HashMap<i64, Post>,
    meta: &HashMap<i64, Vec<(String, String)>>,
    options: &HashMap<String, String>,
    lang_of: &LangOf,
    pages: &[Page],
) -> Result<Vec<MenuItem>, String> {
    let taxonomy: HashMap<i64, (i64, String)> = rows(tables, "wp_term_taxonomy", &["term_taxonomy_id", "term_id", "taxonomy"])?
        .into_iter()
        .map(|r| (r[0].int(), (r[1].int(), r[2].str().to_string())))
        .collect();
    let tt_of_term: HashMap<i64, i64> = taxonomy.iter().filter(|(_, (_, t))| t == "nav_menu").map(|(tt, (term, _))| (*term, *tt)).collect();
    let mut items_of_term: HashMap<i64, Vec<i64>> = HashMap::new();
    for r in rows(tables, "wp_term_relationships", &["object_id", "term_taxonomy_id"])? {
        if let Some((term, t)) = taxonomy.get(&r[1].int()) {
            if t == "nav_menu" {
                items_of_term.entry(*term).or_default().push(r[0].int());
            }
        }
    }
    let unser = |name: &str| options.get(name).and_then(|v| serialized::unserialize(v).ok());
    let navbar = unser("theme_mods_momentum-custom").and_then(|m| m.get("nav_menu_locations")?.get("navbar")?.as_int());
    let footer_widget = unser("sidebars_widgets")
        .and_then(|s| s.get("footer-widget-area").map(|a| a.items().iter().filter_map(|(_, w)| w.as_str().map(str::to_string)).collect::<Vec<_>>()))
        .unwrap_or_default()
        .into_iter()
        .find_map(|w| w.strip_prefix("nav_menu-").and_then(|n| n.parse::<i64>().ok()));
    let footer = footer_widget.and_then(|n| unser("widget_nav_menu")?.get(&n.to_string())?.get("nav_menu")?.as_int());
    let page_url = |id: i64| pages.iter().find(|p| p.id.0 == id).map(|p| p.url("https://www.harcourt.co"));
    let page_title = |id: i64| posts.get(&id).map(|p| p.title.clone());
    let mut out = Vec::new();
    for (menu, term) in [(Menu::Main, navbar), (Menu::Footer, footer)] {
        let Some(term) = term else { continue };
        let Some(&tt) = tt_of_term.get(&term) else { continue };
        let Some(&(_, group)) = lang_of.get(&("tax_nav_menu".into(), tt)) else { continue };
        for (&(ref kind, tt_id), &(lang, trid)) in lang_of {
            if kind != "tax_nav_menu" || trid != group {
                continue;
            }
            let Some((term_id, _)) = taxonomy.get(&tt_id) else { continue };
            for &item in items_of_term.get(term_id).map(Vec::as_slice).unwrap_or(&[]) {
                let Some(p) = posts.get(&item).filter(|p| p.kind == "nav_menu_item" && p.status == "publish") else { continue };
                // functions.php drops the navbar's "Test" item unless Cloudflare says the visitor is in NL; it is
                // a hidden test entry, so it is not imported rather than shown on a client-supplied header.
                if menu == Menu::Main && p.title == "Test" {
                    continue;
                }
                let m = |k: &str| meta_value(meta, item, k).unwrap_or("");
                let object_id = m("_menu_item_object_id").parse::<i64>().unwrap_or(0);
                let is_page = m("_menu_item_type") == "post_type" && m("_menu_item_object") == "page";
                let url = if is_page { page_url(object_id).unwrap_or_default() } else { m("_menu_item_url").to_string() };
                let label = if p.title.is_empty() && is_page { page_title(object_id).unwrap_or_default() } else { p.title.clone() };
                let classes = serialized::unserialize(m("_menu_item_classes"))
                    .map(|c| c.items().iter().filter_map(|(_, v)| v.as_str().filter(|s| !s.is_empty()).map(str::to_string)).collect::<Vec<_>>().join(" "))
                    .unwrap_or_default();
                let parent = m("_menu_item_menu_item_parent").parse::<i64>().unwrap_or(0);
                out.push(MenuItem {
                    id: item,
                    menu,
                    lang,
                    parent_id: (parent != 0).then_some(parent),
                    position: p.menu_order,
                    label,
                    url,
                    page_id: is_page.then_some(PageId(object_id)),
                    classes,
                    target: m("_menu_item_target").to_string(),
                });
            }
        }
    }
    out.sort_by_key(|m| (m.menu.as_str(), m.lang, m.position, m.id));
    Ok(out)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn field_kinds_follow_the_acf_type() {
        assert_eq!(field(Some("true_false"), "1").unwrap().kind, Kind::Flag);
        assert_eq!(field(Some("true_false"), "0").unwrap().value, "");
        assert_eq!(field(Some("repeater"), "3").unwrap(), Field { kind: Kind::Count, value: "3".into() });
        assert_eq!(field(Some("checkbox"), r#"a:2:{i:0;s:1:"a";i:1;s:1:"b";}"#).unwrap().value, r#"["a","b"]"#);
        assert!(field(Some("group"), "").is_none());
        let link = field(Some("link"), r#"a:3:{s:5:"title";s:2:"Go";s:3:"url";s:2:"/x";s:6:"target";s:0:"";}"#).unwrap();
        assert_eq!(link.kind, Kind::Link);
        assert_eq!(serde_json::from_str::<serde_json::Value>(&link.value).unwrap()["url"], "/x");
    }

    #[test]
    fn options_split_by_language_prefix() {
        let rows: Vec<(String, String)> = [("options_cta_title", "Join"), ("_options_cta_title", "field_1"), ("options_fr_cta_title", "Rejoindre"), ("_options_fr_cta_title", "field_1"), ("blogname", "x")]
            .iter()
            .map(|(k, v)| (k.to_string(), v.to_string()))
            .collect();
        let types = HashMap::from([("field_1".to_string(), "text".to_string())]);
        let en = acf_fields(&rows, &types, "options_");
        assert_eq!(en["cta_title"].value, "Join");
        assert_eq!(en["fr_cta_title"].value, "Rejoindre");
        assert_eq!(acf_fields(&rows, &types, "options_fr_")["cta_title"].value, "Rejoindre");
    }
}
