# Site content without WordPress

The public pages were a static export of WordPress. `rust/src/cms` holds their content in SQLite and renders it in the momentum-custom theme's markup, so a page needs no WordPress to be served or edited.

## What is where

| Part | Role |
|---|---|
| `migrations/0500_cms.sql` | Pages and their WPML translations, ACF fields, page meta (Yoast lines, per-page scripts), site-wide options per language, menus, media, redirects, revisions. Rows keep their WordPress ids. |
| `src/cms/model.rs` | Typed model and the in-memory `Site` snapshot. The newest revision id is the content version; `Content` reloads when it moves. |
| `src/cms/theme/` | The renderer. `chrome/{en,fr}/*.html` are WordPress's head, header and footer with the per-page parts cut out (made by `tools/cms_chrome.py`); `chrome.rs` fills them (menus, WPML switchers, body classes, Yoast lines); `templates.rs` transcribes the page templates. |
| `src/cms/import/` | Reads a mysqldump of the WordPress tables and the static export. Field text is recovered by matching each template, run in probe mode, against the page WordPress rendered. |
| `src/cms/edit.rs`, `src/cms/admin.rs` | Editing at `/staff/cms`, with revisions, behind `Staff<Administrator>` and the staff CSRF token. |
| `src/routes/wordpress.rs` | The page routes (`PAGES`), robots.txt, and which WordPress entry points answer 410. |

Ported templates: product-single, digest, page.php, home, about, history, why, career, FAQ, thank-you, contact (and its UK, France and India variants), locations, and the page builder's two-column, grid, list-column, custom HTML and call-to-action parts. scscpq is rendered as a shell through `theme::shell` for the area that owns the page, not routed here. Still on the static export: request (the accounts area serves those paths), the builder pages that use a part not ported yet (hbox, boxjoint), and the themed 404 page, which WordPress prints for a claimed path the cms lacks.

How a claimed path answers follows what prod did, where hxweb served the export only for a GET whose query keys were all neutral (`lang`, `hxlang`, `utm_*`, `gclid`, `fbclid`) and WordPress answered the rest:

- A neutral GET for a page in its own language gets the page finished as the exported pages were (vendored assets, static icons).
- Any other request gets the page as WordPress printed it, or WordPress's own redirect: WPML's 302 away from `lang=en`, redirect_canonical()'s slash fix and front-page query rebuild, WPML's translation redirect, and redirect_guess_404_permalink() for a one-segment path.
- A query key WordPress acts on (`p`, `page_id`, `s`, `replytocom`, `preview`, `rest_route`, `hx_form`, ...), a path the cms lacks, and, while WordPress runs, a non-neutral request for a page with forms are handed back (`x-hxweb: legacy`) and main.rs sends them where an unclaimed path goes. The user listing (`rest_route=/wp/v2/users`) never leaves hxweb.

The transcriptions follow the theme in prod's document root (`/home/ubuntu/oracle-data/prod-code/`), which has French strings and link changes that are not in git.

## Import

Dump the WordPress content tables from the oracle or prod with `mysqldump --single-transaction --complete-insert`: `wp_posts` and `wp_postmeta` limited to the content post types (page, nav_menu_item, attachment, acf-field, acf-field-group), `wp_options` limited to the ACF options (`options_%`, `_options_%`), `widget_%`, `sidebars_widgets`, `theme_mods_momentum-custom`, `page_on_front` and `wp_page_for_privacy_policy`, and `wp_terms`, `wp_term_taxonomy`, `wp_term_relationships`, `wp_icl_translations`. No users and no form entries. Then:

```
hxweb-cms check  --dump wp.sql --export /home/harcou6/static_site          # writes nothing; one PASS/FAIL line per page
hxweb-cms import --dump wp.sql --export /home/harcou6/static_site --db /var/lib/hxweb/hx.db
```

`import` replaces the cms content and records an `import` revision per page, then checks the pages it loads back. A re-import overwrites edits made in the dashboard; their revisions stay. Import before deploying a build that claims pages: until then a claimed path is handed back and answers as an unclaimed one does (the static export, or WordPress).

The same holds for any copy of the database: the oracle rig's `hx.db` is built by `hx-import` from the SCS tables only, so a server started by `hx-serve.sh` has an empty cms until `hxweb-cms import` runs against its copy (`/tmp/hx_<area>_<port>.db`).

## Deploy switches

- `PAGES` in `routes/wordpress.rs`: delete a path to hand it back to the static export.
- `HXWEB_RETIRE_WORDPRESS=1`: `wp-login.php`, `wp-admin/*` (except `admin-ajax.php`) and `wp-json/*` answer 410. Set it once staff edit pages from the dashboard and Gravity Forms entries are read outside wp-admin. `xmlrpc.php`, `wp-comments-post.php` and `wp-trackback.php` answer 410 always.

## Forms

Pages mark each Gravity Form `{{gform:N}}`; `cms::forms::OrdersForms` fills it from `orders::forms::island` and falls back to the WordPress island endpoint for a form the orders tables lack. A post carrying `gform_submit` goes to `orders::forms::handle`; its answer takes the form's place on the page, or the iframe postback document for an ajax form.

## WordPress cron

Prod runs `wp cron event run --due-now` every five minutes. None of the scheduled events feeds a page: core housekeeping and update checks, Yoast reindexing, WPML and Complianz telemetry and cookie scans, Smush, malware scans and ManageWP keys all end with WordPress. The four wp-crontrol jobs include files under `/nas/content/live/`, a path from the old host that no longer exists; their real counterparts run from the system crontab through `cron.php` (staff area). `gravityforms_cron` deletes entries past a form's retention setting, which the orders port should carry over if any form sets one.
