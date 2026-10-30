# Staff pages, round three (p3-staff): expected differences from the oracle

Run `p3-staff.jsonl` with `--rust-session`. Earlier rounds' differences (`staff.expected.md`, `staff2.expected.md`,
`p2-staff.expected.md`) still hold: Pending and Denied staff are turned away, AJAX calls need the page's role, a
link never changes data, persona sign-in times and languages drift on the oracle.

## How the rig shows through

- **A persona signed out mid-run stays signed out.** `cookie_reset.php` ends the `__Host-hx_session` it is sent
  with (accounts.expected.md). On the oracle the next request signs the persona in again by address; in Rust every
  later case of that persona arrives without a session and is sent home. A run that resets a persona's cookies
  before its staff cases fails those cases. Reseeding the persona sessions (`hx-diff seed --db`) before the staff
  cases removes the effect.

## Deliberate changes

### Every maintenance page

- **Only a post that writes needs the form token.** user.php, inventory.php, subcategory.php,
  campaign_manager.php, portal.php, portalcategory.php, portalitem.php, portalcontent.php and pricing_utility.php
  take a post that lists, opens a record, cancels, explains or downloads without `csrf`, as the PHP did. An update,
  a delete, or applying an upload still answers 403 without it.

### pricing_utility.php (`/pricing_utility.php`)

- **Smart modules.** The four smart-SKU modules' price tables (subcategories 549, 606, 607, 608) are data in
  `staff/smart.rs`; the subcategory editor finds a smart subcategory's module there too.
- **Uploads** go through the import/export pages' review and update. Applying one says "Update complete" and logs
  the import; the PHP said "Review complete" for both and logged nothing, because its map never saw function
  "update". The summary has no "Code:" line.
- **The AJAX record-type list needs an Administrator**, like the page; the PHP answered anyone.
- **Excel downloads** differ in their bytes (rust_xlsxwriter); text downloads match.

### inventory_pricebook.php (`/inventory_pricebook.php`)

- **Email output** says it is not available here: the outbox carries no attachments. "Download" builds the
  workbook (cover sheet, one sheet per category) as before; its bytes differ (rust_xlsxwriter) and number cells
  hold numbers ("84" where PHP_XLSXWriter wrote "84.00").

### portal_preview.php (`/portal_preview.php`)

- **Debug** prints no SQL; the Super User's "Queries" box stays empty, as on hero_preview.php.
- **A portal category the portal does not list** answers "No items found"; the PHP died with a fatal error that
  printed its file path.
- The preview's image and viewer links carry the viewer's last sign-in (`content_id`), which drifts on the oracle.

## Not ported

- `supplier.php`, `suppliersku.php`: tables `supplier` and `suppliersku` are missing in production.
- `xdashboard.php`: an older copy of dashboard.php; no traffic and no links.
