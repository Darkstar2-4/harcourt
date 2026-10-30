# Catalog: deliberate differences from the PHP oracle

- `scscpq_wp.php`: answered as `application/json` (PHP sent `text/html`); no `Access-Control-Allow-Origin: *`; `meta.script` is `/scscpq_wp.php`, not a server path; the search box script calls `/catalog-api/search` and drops a commented-out block.
- No `harcourt_profile` Set-Cookie on every response; only `scscpq_api.php?action=profile` sets it.
- No first-visit redirect: PHP answers a request without a session cookie with 302 to the catalog page (`scs_header.php` `page()`), even with a same-site Referer and `X-Requested-With`. Rust answers the view.
- Subcategory images are omitted when `HXWEB_DOCROOT` lacks the file, as PHP's `forms->img()` did; the hxport docroot has no images, prod's does.
- Links write `&` raw and escape only quotes, `<` and `>`; element text from the tables escapes only `<` and `>`.
- Part-number lookups and quick CAD never call PARTsolutions while a page renders; `scscpq_api.php?action=download` fetches only https URLs on `*.partcommunity.com`.
- `scs_file.php`, `scs_ajax.php` and the search box: see `fix-files.expected.md`.
- `scscpq_api.php?action=sku` returns `meta` with `source`, `sku_url`, `sku`, `quantity` only; smart (configured) SKUs are not priced.
