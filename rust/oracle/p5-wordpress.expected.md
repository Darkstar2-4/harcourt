# WordPress and search, round five (p5-wordpress, p5-catalog): what still goes to PHP

Run `p5-wordpress.jsonl` and `p5-catalog.jsonl` against the oracle as the other corpora are run. Rust now renders the FAQ pages, the `faq_type` and category archives (with WPML's redirects between translations), the blog 404 page, RSS feeds for posts, categories and FAQ types, the Atom main feed, `?s=` search on the front page, the simple REST answers, and `cad_lookup.php`. With the legacy backend closed these shapes are still answered by PHP, each for one reason:

- **Blog home** (`?order=`, `?author=`). It has its own layout and carousel assets, which the theme templates in `src/cms/` do not carry yet.
- **Author archives and the author sitemap.** The WordPress dump the cms imports has no users.
- **Post singles.** Yoast's author schema id is a hash of WordPress's secret keys, so the page cannot be reproduced from the dump.
- **Case-study and testimonial singles, and embeds.** Not ported.
- **Sitemap 404s.** Yoast drops the footer menu widget on those pages.
- **WordPress search feeds under `/search/` and `/recherche/`.** A feed item needs each post's stored guid and its full content, and `hxweb-cms import` records neither.

Deliberate differences, as in earlier rounds:

- `cad_lookup.php` no longer prints the PARTsolutions API key, and turns Pending and Denied staff away. Its JSON branch still goes to PHP.
- `?hx_form=` and the REST user listing answer with nginx's own 404 page, the way prod's nginx answers them before WordPress sees the request.
