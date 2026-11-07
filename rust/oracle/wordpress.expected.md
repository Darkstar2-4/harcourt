# Deliberate differences on the WordPress paths

- Static files prod's nginx serves itself come from hxweb's asset directory (`src/assets.rs`) with the content types prod sent; a missing one is the 404 page (410 under `wp-content` and `wp-includes`), not nginx's.
- Cases that differ between two requests to the oracle itself and need a normalization rule rather than a fix: the Gravity Forms Zero Spam `fallbackToken` in `gfZeroSpamConfig` on pages WordPress renders live, and the random ids and `data-secret` of WordPress's embed pages (`/<page>/embed/`, `/wp-json/oembed/1.0/embed`).
