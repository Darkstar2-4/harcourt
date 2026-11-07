# Accounts pages ported in round two: expected differences from the oracle

Run `p2-accounts.jsonl` with `--warm --rust-session`. hx-diff primes the warm PHP session on the oracle alone, in
WordPress mode, so every case carries `?scscpq_wp=1` to put the Rust page in the same mode.

## Deliberate

- **Staff pages need an Active session** (`Staff<R>`), as in `staff.expected.md`: currency.php, language.php,
  bot.php, bot_impersonate.php, blacklist.php, captcha_bypass.php, remote_access.php and remote_token.php send a
  Pending or Denied staff persona home. Every call to them, AJAX included, needs an Administrator.
- **Changes come from the pages' own forms.** A maintain-page post carries the session's `csrf` field. The AJAX
  calls of blacklist.php, captcha_bypass.php, remote_access.php and remote_token.php send `X-Requested-With`
  and, when the browser sends one, this site's Origin. A link to bot.php may open a record or cancel; a change
  needs the posted form. campaign.php takes its forms from this site only.
- **remote_access.php** gives the domain to the chosen address within that company.
- **remote_token.php.** Sign-in is the emailed code and the session table, not the old tokens and unlock codes;
  the columns show what an old row still holds. Remove forgets the address and ends every session it holds;
  Reset ends them and renews its remote access for `remote_days`.
- **Retired with 410** (`retired.rs`): remote_code.php (unlock codes; staff give access from
  `/staff/remote-grants`), cookie.php and bot_apache.php (diagnostics).
- **login.php** answers the one line every visitor gets from the PHP, MySQL's error for the missing `user.email`
  column, and sends a bot home. An impersonating Administrator saw the old forms there; that view is not kept.
- **language_change.php** goes back to the page on this site the browser came from, else to `/`. Without a
  Referer the PHP sent an empty Location, which a browser follows back to the same URL until it gives up.

## Caused by the oracle

- The oracle's administrator-active persona (990013) is gone from its database, so that persona is anonymous
  there; personas 990003 and 990012 read French and 990031 English. Reseeding the personas fixes both.
- Last-login dates in remote_access.php follow the oracle's sign-ins after the data copy was taken.

## login.php (coordinator decision)

The PHP page queries a column that does not exist and prints the database's error line to every visitor. Rust answers a plain message instead of reproducing a database error, so the hidden-suite cases for `/login.php` that expect that line fail by design.
