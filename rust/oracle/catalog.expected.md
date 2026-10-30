# Catalog: deliberate differences from the PHP oracle

- `scscpq_wp.php` and `scscpq_api.php` answer as `application/json`. PHP sent `text/html`, with request values (`action`, `token`, posted `data`) inside json_encode output, which leaves `<` and `>` as they are. No `Access-Control-Allow-Origin: *`.
- `scscpq_wp.php`'s `meta.script` is `/scscpq_wp.php`, not the server's file path. The composed `/catalog/` page inlines the same loader, so it differs on this one value too.
- `scscpq_api.php?action=sku` returns `meta` with `source`, `sku_url`, `sku`, `quantity` only; smart (configured) SKUs are not priced.
- A request counts as inside a visit when it has a same-site Referer, `Sec-Fetch-Site: same-origin`, `X-Requested-With` or a PHP session cookie (`visitor::begins_visit`). PHP's first-visit redirect (`scs_header.php` `page()`) also caught the first three when the browser had no PHP session.
- Without a PHP session, whether a visit came through the WordPress frame is the query's `scscpq_wp`, else assumed for a request inside a visit. PHP remembered it per session.
- Subcategory images are omitted when `HXWEB_DOCROOT` lacks the file, as PHP's `forms->img()` did.
- Links write `&` raw and escape only quotes, `<` and `>`; element text from the tables escapes only `<`.
- Part-number lookups and quick CAD never call PARTsolutions while a page renders; `scscpq_api.php?action=download` fetches only https URLs on `*.partcommunity.com`.
- `document_viewer.php` links its `login_url` only when it is a path on this site, escaped.
- `new_catalog.php` keeps its own gate; past it, it shows the current catalog instead of the 2020 one.
- `scs_file.php` serves content only to viewers who may see what owns it, and a named file only when it is an image under the document root.
