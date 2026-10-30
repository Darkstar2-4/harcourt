# Deliberate differences on the WordPress paths

- `rest_route=/wp/v2/users...` on a claimed page answers 410 from hxweb. Prod's nginx answers that query 404 with its own page before hxweb sees it; a bare hxweb must not pass the user listing to WordPress either.
- Requests prod's nginx answers itself differ from a bare hxweb and are not the wordpress area's to match: `?hx_form=` (nginx 404, the form-island endpoint is for hxweb only) and static files under `wp-content`, `wp-includes`, `hx-vendor`, `scs_scripts` and `hx` (nginx serves them with its own MIME types and its own 404 page).
- Cases that differ between two requests to the oracle itself and need a normalization rule rather than a fix: the Gravity Forms Zero Spam `fallbackToken` in `gfZeroSpamConfig` on pages WordPress renders live, and the random ids and `data-secret` of WordPress's embed pages (`/<page>/embed/`, `/wp-json/oembed/1.0/embed`).
