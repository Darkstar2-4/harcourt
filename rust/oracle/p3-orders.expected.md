# Orders, round three: expected differences from the oracle

Run `p3-orders.jsonl` with `--rust-session`, cold and with `--warm`. The cases cover the cart and checkout pages
WordPress served (with and without their slash, `?lang=fr`, `?format=json`, the French paths), posts to the form
pages (no `gform_submit`, an empty submission, the path without its slash, ajax), contact.php in both languages
and the PO Manager for the staff who may open it. Values are made up; no case leaves a valid submission.

## Deliberate

- **Pending and Denied staff are sent home** from orderhd.php, carrier.php, design_partner_agreement.php,
  mailer_output.php, scs_mail.php and scs_mailform.php (coordinator decision). The PHP's `access()` read the role
  only. Measured on a fresh server: of 220 orderhd.php cases over the 20 personas, the 66 that differ are all
  Executives, Administrators and Super Users with a Pending or Denied account; carrier.php and the Design Partner
  Agreement differ on the same four Administrator and Super User personas only. These cases are left out of
  `p3-orders.jsonl`.
- **A change needs the staff form's token.** orderhd.php and carrier.php print the session's `csrf` field and
  write only for a POST that returns it; a GET never writes. Listing, viewing, cancelling and a refused update (the
  same status, an empty name) answer as the PHP did. A change without the token gets the staff pages' 403
  "This form has expired". The PHP wrote on any request.
- **punchout.php answers 410** from `retired.rs`. It requires `classes/punchout.php`, which prod does not have,
  so the oracle prints a PHP fatal error for every request, anonymous included. No visitor is meant to see that
  page; the `punchout` table is empty and nothing links to it.
- **reCAPTCHA on the contact forms** (3, 4, 5, 6, 12, 13, 14). A post without the browser's token is refused
  with "We were unable to verify your submission", with or without `HXWEB_RECAPTCHA_SECRET`, as the PHP did. A
  post with a token: Rust scores it with Google when the secret is set and takes it unscored (with a warning in
  the log) when it is not. The oracle cannot reach Google, so it refuses every such post; posts with a token are
  left out.

## Not in this lane

- **`/shop` and `/shop/`.** WordPress's 404 guess sends any unknown one-segment path to the oldest page whose
  slug starts with it (`/shopping-cart/`). Rust claims only the pages it renders, so these go to the legacy stack.
- **An English-only form page asked for in French** (`/boxjoint-inquiry-form/?lang=fr`, the three Michigan
  contact forms). WordPress prints its themed 404 page, which is not ported; without the slash it first adds the
  slash with a 301, which `resolve()` does not model for an untranslated page.

## Caused by the measurement

- After a hidden-suite run every persona session in the Rust copy is gone: cookie_reset.php ends the browser's
  session, as the PHP destroyed its own, but a persona's seeded session is not signed in again by address the way
  PHP signs the company in again. Cases for a persona that run after its cookie_reset.php case see an anonymous
  visitor on the Rust side. Reseeding the sessions (`hx-diff seed`) before each area, or ordering cookie_reset.php
  last, would keep them.
