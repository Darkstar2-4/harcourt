# Catalog: deliberate differences from the PHP oracle

- `scscpq_wp.php` answers `text/html` as the PHP did, but the request values it echoes (`action` in an error, `token`, the referrer) have `<` and `>` written as `\u003c` and `\u003e`: the same JSON string, never markup. `scscpq_api.php`'s JSON answers only requests with `X-Requested-With`, which a page on another site cannot send. No `Access-Control-Allow-Origin: *` on either.
- `scscpq_wp.php`'s `meta.script` is `/scscpq_wp.php`, not the server's file path. The composed `/catalog/` page inlines the same loader, so it differs on this one value too.
- `scscpq_wp.php` answers every `action` that names one of its class's methods as the PHP did, except the constructor, `url` and `scs_table_version`: there the PHP crashed (printing its server paths) and Rust answers as for an unknown action, 104.
- `configure.php` is for Active Internal staff and above with a session, as every staff page; the PHP also showed it to Pending and Denied staff accounts. Posting the form (a part number to price) still goes to PHP. `catalog_metric_filter.php` likewise, for Administrators; its Excel download still goes to PHP.
- `cad_lookup.php` (the Configurator Toolbox) likewise, for Internal staff and above; its buttons post to the toolbox's JSON branch, which still goes to PHP. Its API Key field is empty: the toolbox uses the registry's key on the server side.
- `scs_api.php` always answers `{"accesslevel":"public","company":null}`, as the PHP did through a lookup bug; it answered any site, so the company stays unnamed.
- The scscpq pages (`/catalog/`, `/catalogue/`, `/search/`, `/recherche/`, `/customer_portal/`, `/portail-clients/`, `/beta_catalog/`) render from the cms tables with the footer form from the Gravity Forms port, whose zero-spam token differs from WordPress's. A query on them never goes to WordPress; one WordPress acts on (`page`, `s`, ...) still does.
- Pages that show the SCS ribbon outside the WordPress frame (catalog.php, search.php, scs_index.php, the staff pages) draw it inside a PHP session this server did not see begin, where the oracle's session was framed by a request only the oracle saw.
- `scscpq_api.php?action=sku` returns `meta` with `source`, `sku_url`, `sku`, `quantity` only; smart (configured) SKUs are not priced.
- A request counts as inside a visit when it has a same-site Referer, `Sec-Fetch-Site: same-origin`, `X-Requested-With` or a PHP session cookie (`visitor::begins_visit`). PHP's first-visit redirect (`scs_header.php` `page()`) also caught the first three when the browser had no PHP session.
- Without a PHP session, whether a visit came through the WordPress frame is the query's `scscpq_wp`, else assumed for a request inside a visit. PHP remembered it per session.
- Subcategory images are omitted when `HXWEB_DOCROOT` lacks the file, as PHP's `forms->img()` did.
- Links write `&` raw and escape only quotes, `<` and `>`; element text from the tables escapes only `<`.
- Part-number lookups and quick CAD never call PARTsolutions while a page renders; `scscpq_api.php?action=download` fetches only https URLs on `*.partcommunity.com`.
- `document_viewer.php` links its `login_url` only when it is a path on this site, escaped.
- `new_catalog.php` keeps its own gate; past it, it shows the current catalog instead of the 2020 one.
- `scs_file.php`, `scs_index.php`, `beta.php`, `customer_portal.php`, `scs_ajax.php` and the search box: see `fix-files.expected.md`.
