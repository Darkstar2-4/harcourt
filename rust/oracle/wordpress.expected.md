# Deliberate differences on the WordPress paths

- Static files prod's nginx serves itself differ from a bare hxweb and are not the wordpress area's to match: files under `wp-content`, `wp-includes`, `hx-vendor`, `scs_scripts` and `hx` (nginx serves them with its own MIME types and its own 404 page). The queries its deny rules refuse (`?hx_form=`, the REST user listing) get nginx's 404 page from hxweb too (`cms/refused.rs`).
- Cases that differ between two requests to the oracle itself and need a normalization rule rather than a fix: the Gravity Forms Zero Spam `fallbackToken` in `gfZeroSpamConfig` on pages WordPress renders live, and the random ids and `data-secret` of WordPress's embed pages (`/<page>/embed/`, `/wp-json/oembed/1.0/embed`).
