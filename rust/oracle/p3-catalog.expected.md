# Catalog, round three: deliberate differences from the PHP oracle

- `inventory_info.php` with neither a part number nor a `prj` that names a family shows its page with nothing in it. The PHP built an empty SQL condition and printed MariaDB's syntax error instead of the page.
- `catalog.php?debug=1` prints the request's keys and values with `<` escaped; the PHP printed them raw. Its trace stops at the gate's choice (`Document: 157`, `Profile`, `Landing page: N` or `route`); past `route` the PHP also listed each view function it called, which only a signed-in account with a complete profile reaches.
- `image_viewer.php` stays retired (410, `retired.rs`): it served a file whose path the PHP session held, and nothing puts one there any more. The PHP answered an empty page.
- `scs_cad.php` is not ported: it requires `cad_toolbox.php`, which is gone, and fails on every request with a PHP fatal error that prints the server's path.
