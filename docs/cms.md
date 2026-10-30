# Site content without WordPress

The public pages were a static export of WordPress. `rust/src/cms` holds their content in SQLite and renders it in the momentum-custom theme's markup, so a page needs no WordPress to be served or edited.

## What is where

| Part | Role |
|---|---|
| `migrations/0500_cms.sql` | Pages and their WPML translations, ACF fields, page meta (Yoast lines, per-page scripts), site-wide options per language, menus, media, revisions. Rows keep their WordPress ids. `0503` drops the redirect table, which nothing filled or read. |
| `src/cms/model.rs` | Typed model and the in-memory `Site` snapshot. The newest revision id is the content version; `Content` reloads when it moves. |
| `src/cms/theme/` | The renderer. `chrome/{en,fr}/*.html` are WordPress's head, header and footer with the per-page parts cut out (cut once from the static export); `chrome.rs` fills them (menus, WPML switchers, body classes, Yoast lines); `templates.rs` transcribes the page templates. |
| `src/cms/import/` | Reads a mysqldump of the WordPress tables and the static export. Field text is recovered by matching each template, run in probe mode, against the page WordPress rendered. |
| `src/cms/edit.rs`, `src/cms/admin.rs` | Editing at `/staff/cms`, with revisions, behind `Staff<Administrator>` and the staff CSRF token. |
| `src/routes/wordpress.rs` | The page routes (`PAGES`), robots.txt, and the catch-all for every other path: a static file, the old stack's 410, or what WordPress made of the path. |

Ported templates: product-single, digest, page.php, home, about, history, why, career, FAQ, thank-you, contact (and its UK, France and India variants), locations, and the page builder's two-column, grid, list-column, custom HTML and call-to-action parts. scscpq is rendered as a shell through `theme::shell` for the area that owns the page, not routed here. Still on the static export: request (the accounts area serves those paths) and the builder pages that use a part not ported yet (hbox, boxjoint). WordPress's 404 page (index.php's empty loop) is rendered from the export's 404 entry, whose head values the import keeps as options `@404:<hole>`.

## Paths no route claims

`routes/wordpress.rs` ends in a catch-all (`front`), outside the visitor layer as WordPress ran without scs_header.php. A GET for a file of a static type in the asset directory gets the file (`src/assets.rs`). A path with a `.php`, `wp-*` or `xmlrpc` segment, or a query with `rest_route`, gets 410 (`src/retired.rs`). Everything else is WordPress's: `resolve` follows the rewrite rules with permalinks `/%postname%/`: a page, FAQ or FAQ type by path (any case), a French page asked for in English (WPML's redirect to the translation), a one-segment name (WPML's exact lookup across post types, then redirect_guess_404_permalink() in MySQL's index order), a two-segment path (the attachment rule, guessed by its last segment), or the 404 page. The front page's query vars (`?p=`, `?page_id=`, `?name=`, `?cat=`, `?m=`, `?feed=`, `?page=`) redirect as redirect_canonical() and WPML do, `?s=` shows the search page, and `/index.php[/path]` redirects to `/[path]`. What the site no longer has answers the 404 page: the blog, its feeds, the category, author and date archives, the post, case-study and testimonial singles, attachments, embeds, trackbacks and paging, and a WordPress query var on a content page shows the page.

How a claimed path answers follows what prod did, where hxweb served the export only for a GET whose query keys were all neutral (`lang`, `hxlang`, `utm_*`, `gclid`, `fbclid`) and WordPress answered the rest:

- A neutral GET for a page in its own language gets the page finished as the exported pages were (vendored assets, static icons).
- Any other request gets the page as WordPress printed it, or WordPress's own redirect: WPML's 302 away from `lang=en`, redirect_canonical()'s slash fix and front-page query rebuild, WPML's translation redirect, and redirect_guess_404_permalink() for a one-segment path.
- A path the cms lacks gets the 404 page or WordPress's guess, as above.

The transcriptions follow the theme in prod's document root (`/home/ubuntu/oracle-data/prod-code/`), which has French strings and link changes that are not in git.

## Sitemaps

`cms::sitemap` prints Yoast's sitemaps from the cms tables in the request's language: `sitemap_index.xml`, `page-sitemap.xml` and `faq-sitemap.xml` (by last change, the front page first, each post at the URL WPML gives it in that language, which lists a French page under its English URL in English; images are the featured image and the content's), and `faq_type-sitemap.xml` (`cms_term`, whose date counts everything filed under the term, ACF field groups included, as Yoast's query does). `/sitemap.xml` redirects to the index and `/main-sitemap.xsl` is Yoast's stylesheet. Any other sitemap is the 404 page.

## Import

Dump the WordPress content tables from the oracle or prod with `mysqldump --single-transaction --complete-insert`: `wp_posts` and `wp_postmeta` limited to the content post types (page, nav_menu_item, attachment, acf-field, acf-field-group), `wp_options` limited to the ACF options (`options_%`, `_options_%`), `widget_%`, `sidebars_widgets`, `theme_mods_momentum-custom`, `page_on_front` and `wp_page_for_privacy_policy`, and `wp_terms`, `wp_term_taxonomy`, `wp_term_relationships`, `wp_icl_translations`, and the Gravity Forms definitions `wp_gf_form` and `wp_gf_form_meta`. The post types are `page`, `nav_menu_item`, `attachment`, `acf-field`, `acf-field-group`, `post`, `faq`, `case_study` and `testimonials`. Of the users, only `ID`, `user_nicename` and `display_name` of the posts' authors, and their `_yoast_wpseo_profile_updated` meta (the author sitemap's date), written as INSERTs with those columns. No form entries. Then:

```
hxweb-cms check  --dump wp.sql --export /home/harcou6/static_site          # writes nothing; one PASS/FAIL line per page
hxweb-cms import --dump wp.sql --export /home/harcou6/static_site --db /var/lib/hxweb/hx.db
```

`import` replaces the cms content and records an `import` revision per page, then checks the pages it loads back. It also replaces the form definitions in the orders area's `wp_gf_form` tables (`orders::forms::import`; entries untouched), since every page embeds a form and renders it from them. The other post types' singles are not in the export: `hxweb-cms capture --dump wp.sql --export DIR --origin URL --out CAPTURE` fetches them once from a running WordPress into the export's form, and `--capture CAPTURE` adds them to `check` and `import`. A re-import overwrites edits made in the dashboard; their revisions stay. Import before deploying: until then every content page answers 404.

The same holds for any copy of the database: the oracle rig's `hx.db` is built by `hx-import` from the SCS tables only, so a server started by `hx-serve.sh` has an empty cms until `hxweb-cms import` runs against its copy (`/tmp/hx_<area>_<port>.db`).

## Deploy switches

- `PAGES` in `routes/wordpress.rs`: the paths rendered from the cms; a path the cms holds but `PAGES` lacks still answers through the catch-all.

## Forms

Pages mark each Gravity Form `{{gform:N}}`; `cms::forms::OrdersForms` fills it from `orders::forms::island`; a form the orders tables lack fails the page rather than leaving a hole. A post carrying `gform_submit` goes to `orders::forms::handle`; its answer takes the form's place on the page, or the iframe postback document for an ajax form.

## WordPress cron

Prod runs `wp cron event run --due-now` every five minutes. None of the scheduled events feeds a page: core housekeeping and update checks, Yoast reindexing, WPML and Complianz telemetry and cookie scans, Smush, malware scans and ManageWP keys all end with WordPress. The four wp-crontrol jobs include files under `/nas/content/live/`, a path from the old host that no longer exists; their real counterparts run from the system crontab through `cron.php` (staff area). `gravityforms_cron` deletes entries past a form's retention setting, which the orders port should carry over if any form sets one.
