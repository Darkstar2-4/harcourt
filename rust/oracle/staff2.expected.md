# Staff maintenance pages (staff2): expected differences from the oracle

Run `staff2.jsonl` with `--rust-session`. Staff pages read the persona's session cookie, not the client address
(see `staff.expected.md` for the design). Every case differs from the oracle only in the ways listed here.

## How the cases reach the pages

- **Warm sessions.** A cold oracle request sends a signed-in persona to its landing page (`/dashboard.php` for
  staff), so signed-in cases are `"session":"warm"`. hx-diff's warm-up request leaves the PHP session in WordPress
  mode, so those cases also carry `?scscpq_wp=1`, which puts the Rust page in the same mode.
- **POST bodies** carry `csrf=<token>` for the persona's seeded session (SHA-256 of the session cookie under the
  staff form label, base64url). The PHP ignores the field.
- Only persona accounts are listed, viewed or edited. Update cases post errors, so the oracle's database is not
  changed.

## Caused by the oracle or another area

- **Persona sign-in times.** Each oracle request as a persona rewrites its `last_login`. If a persona was first used
  on the oracle after the SQLite copy was made, its edit form shows "reCAPTCHA Score" and "Last Login" rows there and
  none in Rust, where its `last_login` is still 0.

## Deliberate changes

### user.php (`/user.php`)

- **Roles.** The list, the edit form, delete and "Copy from" cover only accounts whose role is at or below the
  Administrator's, and the role given must be one of those. The PHP limited only the pick lists.
- **Changes need a POST.** `?action=delete` or `?action=update` in a link only lists. `?action=maintain` (the
  dashboard's links) still opens the form.
- **Return after a save** goes to `return_url` only when it is a path on this site; otherwise the list shows with
  "Record maintained".
- **Salesforce push not ported.** With `return_url` set, an approval posted a web-to-lead form whose org id was
  sent as `old`, so Salesforce discarded it. The Salesforce rows still show in the form; nothing reads them.
- **Annual revenue is saved.** The PHP showed and read the field but left it out of the UPDATE.
- **IPStack.** Lookups run as before when the box is ticked or an account is created; a failure goes to the server
  log instead of an `IPStack:Error` event. The IPStack tab lists the answer's keys sorted.
- **Escaping.** Element text escapes `<`; attributes are fully escaped. Identical for the cases' data.
- **Document pick list ties.** Documents with the same group, parent and name sort by id, highest first, which is the
  order the oracle returns today (MySQL leaves it undefined).

### inventory.php (`/inventory.php`)

- **No session state.** The PHP kept the descriptions and downloads of the part being edited in the session between
  the form and the save, and refused a save without them ("Refresh not allowed"). Rust reads them from `content` at
  save time and applies what was posted, so a resent form saves. `inventory-switch-subcategory` passes on the oracle
  only when `inventory-maintain` ran earlier in the same warm session.
- **Duplicate SKU.** The PHP showed MySQL's duplicate-key error; Rust marks the SKU and says
  "Duplicate entry '<sku>' for key 'sku'".
- **New date.** A date that is not a calendar date is marked in error instead of stored as typed.
- **Upload type.** The MIME type and file type of a download come from its suffix with the PHP's tables; image sizes
  are not recorded.

### inventory_prj.php and inventory_missing_prj.php

- **No shared temp file.** The PHP import parked the parsed upload in `scs.tmp` in the document root, one file for
  all users and readable from the web, between the review and the update. The review form now carries the parsed
  links in a hidden `prj_rows` field (JSON), and the update reads them from there.
- **"Not Changed" lists each SKU.** The PHP printed the last SKU of the file once per unchanged part.
- The PHP's `Import: Inventory PRJ` log call wrote nothing (no such log type), so there is none.
