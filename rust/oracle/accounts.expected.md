# Accounts: differences from the PHP that are intended

`accounts.jsonl` holds cases that should match the oracle exactly. What is listed here differs on purpose, or
cannot be compared by `hx-diff`, and has no case.

## Not comparable with hx-diff

- **Pages behind the landing redirect.** A cold case begins a visit, so both sides answer an SCS page with
  scs_header.php's landing redirect. The pages themselves are reached warm (`fix-accounts.jsonl`); cases that
  change the PHP session are in `fix-accounts-session.jsonl`, to be run on their own.
- **The sign-up page's AJAX actions** (`register`, `remote`, `token`, `microsoft_oauth_init`) answer JSON only to a
  request with `X-Requested-With`, which hx-diff cannot send.
- **`/oauth/init.php` and `/oauth/google_init.php`.** Both sides redirect to the provider with a fresh random state,
  PKCE challenge and nonce, so no two answers match, and the Rust server needs the `HXWEB_MICROSOFT_*` and
  `HXWEB_GOOGLE_*` settings.

## Intended differences

- **Sign-in codes.** The code is random and lives in `access_code`. The sign-up page allows two wrong tries, counted
  on the server for the address, and then needs a new code. A try reCAPTCHA calls spam is counted for the visit as
  the PHP counted it, and for the address. A code is mailed only to an address at an Active company, at most once
  a minute. The code mail is `access_code`'s text, not the `remote` mail form's.
- **reCAPTCHA.** When it is on, a token Google does not confirm, with the expected action, scores 0.
- **POSTs** whose `Origin` is not the site are refused on the sign-up, impersonation and other accounts pages.
- **Remembered sign-in.** A code or provider sign-in is a session cookie (`__Host-hx_session`) that lasts the
  registry's `remote_days` and is renewed on use, instead of the `harcourt` and `remote` cookie pair.
- **OAuth state** is kept on the server under the `__Host-hx_oauth` cookie instead of the PHP session. The ID
  token's nonce and audience are checked for both providers. A callback from a browser with no OAuth cookie goes
  to /request-access without a message, as the PHP did for a browser with no session.
- **Impersonation** is stored against the Administrator's session and never shows a role above the
  Administrator's own. Only an Active Administrator may impersonate; the PHP checked the role alone.
- **language_change.php** goes back only to a page on this site, else to `/`, and saves the choice to the account
  only when the request comes from one of the site's pages. Without a Referer the PHP answered 302 with an empty
  Location, which browsers show as a blank page; Rust sends the visitor home.
- **Staff notice mails** escape the values visitors and providers supplied.
- **Sign-in by company address** believes X-Real-IP only from a trusted proxy, and never signs anyone in from a
  proxy or loopback address. Bots are matched only on a reverse DNS name that resolves forward to the same address.
- **cookie_reset.php** always clears `harcourt` and forgets the visit, as the PHP did, and also ends a sign-in by
  emailed code or provider (`__Host-hx_session`).
