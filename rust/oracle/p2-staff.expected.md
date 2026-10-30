# Staff pages, round two (p2-staff): expected differences from the oracle

Run `p2-staff.jsonl` with `--rust-session --jobs 1`. Signed-in cases are warm and carry `?scscpq_wp=1` (see
`staff2.expected.md`). POST bodies carry the persona session's CSRF token; the AJAX posts carry none and send
`X-Requested-With`, as the pages' scripts do. The upload cases only review; no case writes to the oracle's tables.

## How the rig and the data show through

- **Warm visits Rust never saw.** hx-diff primes the oracle's PHP session with `/catalog.php?scscpq_wp=1`; Rust
  sees only the session cookie. A warm request without `?scscpq_wp=1` is inside WordPress on the oracle and not in
  Rust, so redirects go to `/` there and `/scs_index.php` here, and the ribbon shows here. Staff pages take the
  frame from the visit (`Visitor::framed`) as the PHP took it from its session.
- **Persona sign-in times.** The oracle rewrites a persona's `last_login` on every sign-in; the SQLite copy keeps
  the import's. Pages that print it (user.php's Last Login and reCAPTCHA rows) or derive a key from it
  (portalitem.php's and portalcontent.php's viewer links) differ for persona accounts.
- **Pending and Denied staff.** Staff pages take `Authed<R>`, which refuses Pending and Denied sessions
  (`staff.expected.md`); the oracle shows them the page.
- **Persona drift.** Language flags clicked by other runs change persona languages on the oracle; the copy keeps
  the import's.

## Deliberate changes

### Every page

- **Sort ties.** Lists with tied sort keys come out in the order MariaDB's filesort gave them (staff/filesort.rs runs
  its quicksort over the rows in table order and compares text as latin1_swedish_ci). Checked against the oracle's
  whole document list.
- **AJAX calls need the page's role.** subcategory.php, campaign_manager.php and hero_preview.php answered their
  AJAX calls (and subcategory.php its downloads) before checking the role. Here every call needs the role of the
  page. The calls carry no form token, as the PHP scripts sent none; `X-Requested-With`, which another site's page
  cannot send without a preflight, marks them, and they change only the session's draft.
- **Session state.** What the PHP kept in `$_SESSION` between requests (an import's parsed rows, subcategory.php's
  and campaign_manager.php's content being edited) lives in `staff_draft`, keyed by the staff session and removed
  with it.

### Import/export (map_rev3.php and its pages)

- **Uploads** are read in memory, capped at 20 MB, and never written to disk. The PHP moved them to temp files named
  in the session.
- **Excel files** are written with rust_xlsxwriter; the workbook bytes differ from PHP_XLSXWriter's. Text exports
  match byte for byte.
- **inventory_xref.php "XR File?"** converts the competitor-per-column file into Item #/Xref lines. The PHP path
  read a temp file it never filled and then called an undefined method.
- **user_utility.php** imports no role above the importer's.
- **Kept PHP quirks:** "review" on inventory_price.php and inventory_xref.php does nothing (the pages looked for
  "preview"); subcategory_utility.php downloads on "Go!" without the field list; user_utility.php's date filter
  compares the last sign-in day in Detroit time.

### Single pages

- **company.php** is shown to every visit, as the PHP's session user was always at least a Customer; HTML documents
  print from `html_content`.
- **scs_track_log.php** needs an Administrator; the PHP checked no role.
- **hero_preview.php**'s debug box printed the SQL; the debug answer stays empty.
- **subcategory.php** image uploads must decode as jpg, png or gif and go to `images_subcategory/` under names the
  page makes; the PHP accepted any upload with those endings. A smart-SKU subcategory needs a configure module,
  which the port does not have, so "Only CAD (SMART SKU)" reports it missing.
- **portal.php** prints its message line (title, version, "Request cancelled", "Portal … updated"), as
  `forms->message()` always printed.
- **user.php**'s edit tabs keep `heightStyle: 'content'` inside WordPress (the page never set it); a new account
  starts at the address of whoever opens the form, signed in now, as user_data_class did.
- **Textareas** escape `&`, `<` and `>` only, as `htmlspecialchars($text, ENT_HTML401)` did.

## Not ported

- `supplier.php`, `suppliersku.php`: tables `supplier` and `suppliersku` are missing in production; no traffic.
- `xdashboard.php`: superseded by dashboard.php; no traffic and no links.
- `pricing_utility.php`: its record types come from per-subcategory PHP configure modules (smart SKUs), which belong
  with the configurator.
- `inventory_pricebook.php`, `portal_preview.php`, and the cron jobs `image_filmstrip` and `cad_toolbox`: not reached in
  this round.
