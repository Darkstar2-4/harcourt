//! image_filmstrip.php's job: every subcategory's thumbnail in one strip of 80-pixel squares
//! (`scs_scripts/catalog_images.png`) and the stylesheet that shows each subcategory its square
//! (`scs_scripts/catalog.css`), so the catalog's subcategory list loads one image instead of hundreds.

use std::path::Path;

use image::{imageops, DynamicImage, ImageFormat, RgbaImage};
use sqlx::SqlitePool;

use super::time;

const SIZE: u32 = 80;
const STRIP: &str = "scs_scripts/catalog_images.png";
const CSS: &str = "scs_scripts/catalog.css";

/// date("r"): `Thu, 30 Oct 2026 06:49:00 -0400`.
fn rfc2822(unix: i64) -> String {
    let offset = time::offset_at(unix) / 60;
    let sign = if offset < 0 { '-' } else { '+' };
    format!("{}{sign}{:02}{:02}", time::date("D, d M Y H:i:s ", unix), offset.abs() / 60, offset.abs() % 60)
}

/// A thumbnail the strip can take: a GIF, JPEG or PNG file under the document root.
fn thumbnail(docroot: &Path, url: &str) -> Option<DynamicImage> {
    let path = docroot.join(url.trim_start_matches('/'));
    if url.is_empty() || url.split('/').any(|seg| seg == "..") || !path.is_file() {
        return None;
    }
    let bytes = std::fs::read(path).ok()?;
    match image::guess_format(&bytes).ok()? {
        ImageFormat::Gif | ImageFormat::Jpeg | ImageFormat::Png => image::load_from_memory(&bytes).ok(),
        _ => None,
    }
}

/// process(): the strip, the stylesheet, a log row and the registry's timestamp. The first square stays empty and
/// a subcategory without a usable thumbnail points at it, as the PHP counted from one.
pub async fn run(pool: &SqlitePool, docroot: &Path, now: i64) -> Result<String, String> {
    let fail = |e: sqlx::Error| e.to_string();
    let subcategories = sqlx::query!(r#"SELECT id AS "id!: i64", COALESCE(thumbnail_url, '') AS "thumbnail!: String" FROM subcategory ORDER BY id"#)
        .fetch_all(pool)
        .await
        .map_err(fail)?;
    let mut strip = RgbaImage::new(subcategories.len() as u32 * SIZE, SIZE);
    let mut css = vec![
        format!("/* Generated: {} */", rfc2822(now)),
        String::new(),
        ".catalog_subcategory_list span {".to_string(),
        format!("\twidth: {SIZE}px;"),
        format!("\theight: {SIZE}px;"),
        "\tdisplay: block;".into(),
        format!("\tbackground-image: url(\"/{STRIP}?ver={now}\");"),
        "\tbackground-position-y: center;".into(),
        "}".into(),
        String::new(),
    ];
    let mut offset: u32 = 0;
    for s in &subcategories {
        let placed = match thumbnail(docroot, &s.thumbnail) {
            Some(img) => {
                offset += 1;
                let square = img.crop_imm(0, 0, SIZE, SIZE).to_rgba8();
                imageops::overlay(&mut strip, &square, i64::from(offset * SIZE), 0);
                offset
            }
            None => 0,
        };
        let position = if placed == 0 { "0px 0".to_string() } else { format!("-{}px", placed * SIZE) };
        css.push(format!(".subcat{} {{ background-position: {position}; }}", s.id));
    }
    strip.save_with_format(docroot.join(STRIP), ImageFormat::Png).map_err(|e| format!("{STRIP}: {e}"))?;
    std::fs::write(docroot.join(CSS), css.join("\n")).map_err(|e| format!("{CSS}: {e}"))?;
    let comment = format!("Items processed: {}", super::html::number(i64::from(offset) + 1));
    let stamp = now.to_string();
    let mut tx = pool.begin().await.map_err(fail)?;
    sqlx::query!("INSERT INTO log (date_time, type, subtype, comment) VALUES (?1, 'CRON', 'Filmstrip', ?2)", now, comment).execute(&mut *tx).await.map_err(fail)?;
    let updated = sqlx::query!("UPDATE registry SET data = ?1 WHERE domain = '' AND module = 'local' AND field = 'filmstrip_timestamp' AND item = ''", stamp)
        .execute(&mut *tx)
        .await
        .map_err(fail)?
        .rows_affected();
    if updated == 0 {
        sqlx::query!("INSERT INTO registry (domain, module, field, item, data) VALUES ('', 'local', 'filmstrip_timestamp', '', ?1)", stamp).execute(&mut *tx).await.map_err(fail)?;
    }
    tx.commit().await.map_err(fail)?;
    Ok(comment + "\n")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn dates_print_as_rfc_2822_in_detroit() {
        assert_eq!(rfc2822(1793371740), "Fri, 30 Oct 2026 10:49:00 -0400");
        assert_eq!(rfc2822(1798779600), "Fri, 01 Jan 2027 00:00:00 -0500");
    }

    #[tokio::test]
    async fn the_strip_holds_each_thumbnail_and_the_stylesheet_points_at_it() {
        let root = std::env::temp_dir().join(format!("hx-filmstrip-{}", std::process::id()));
        std::fs::create_dir_all(root.join("scs_scripts")).unwrap();
        std::fs::create_dir_all(root.join("images_subcategory")).unwrap();
        let red = RgbaImage::from_pixel(100, 90, image::Rgba([200, 0, 0, 255]));
        red.save_with_format(root.join("images_subcategory/a.png"), ImageFormat::Png).unwrap();
        let pool = crate::db::open_memory().await.unwrap();
        sqlx::query("INSERT INTO subcategory (id, name, thumbnail_url) VALUES (3, 'A', '/images_subcategory/a.png'), (4, 'B', '/images_subcategory/missing.png'), (5, 'C', '')").execute(&pool).await.unwrap();
        assert_eq!(run(&pool, &root, 1793371740).await.unwrap(), "Items processed: 2\n");
        let css = std::fs::read_to_string(root.join(CSS)).unwrap();
        assert!(css.starts_with("/* Generated: Fri, 30 Oct 2026 10:49:00 -0400 */\n\n.catalog_subcategory_list span {"));
        assert!(css.ends_with(".subcat3 { background-position: -80px; }\n.subcat4 { background-position: 0px 0; }\n.subcat5 { background-position: 0px 0; }"));
        let strip = image::open(root.join(STRIP)).unwrap().to_rgba8();
        assert_eq!(strip.dimensions(), (240, 80));
        assert_eq!(strip.get_pixel(100, 10).0, [200, 0, 0, 255]);
        assert_eq!(strip.get_pixel(10, 10).0[3], 0);
        let stamp: String = sqlx::query_scalar("SELECT data FROM registry WHERE module = 'local' AND field = 'filmstrip_timestamp'").fetch_one(&pool).await.unwrap();
        assert_eq!(stamp, "1793371740");
        std::fs::remove_dir_all(root).unwrap();
    }
}
