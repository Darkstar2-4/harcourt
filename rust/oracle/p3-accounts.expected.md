# Accounts, round three: expected differences from the oracle

Run `p3-accounts.jsonl` with `--rust-session`; every case names its own session. The cases cover the sign-up
pages WordPress served (request-access, demande-dacces, newuser, impersonate, with and without their slash, in
both languages), the spellings of a PHP path Apache accepted (`//login.php`, `/login.php/x`, `/newuser%2Ephp`),
the old docroot directories, and language_change.php with and without a Referer.

## Deliberate

- **login.php** prints a plain message, not the MySQL error every visitor gets from the PHP (coordinator decision,
  `p2-accounts.expected.md`). The `php-dslash`, `php-info` and `php-slash` cases fail on that body alone.
- **language_change.php without a Referer** goes home. The PHP answered 302 with an empty Location: Chrome treats
  an empty Location as no redirect and shows a blank page, and a browser that follows it requests the same URL
  again. With an empty Location the hidden suite's language_change cases all pass (measured), so they differ on
  that header alone. The `lc-noref-*` cases show it.
- **Pending and Denied Administrators and Super Users** are sent home from the staff pages (currency.php, bot.php,
  language.php, blacklist.php, captcha_bypass.php, remote_access.php, remote_token.php, bot_impersonate.php,
  user_impersonate.php). The PHP's `access()` checked the role only. Coordinator decision.
- **remote_code.php and beta_authorize.php** answer 410 from `retired.rs`. beta_authorize.php wrote the server's
  request variables into a file in the docroot and printed nothing.
- **Forms post back to PHP_SELF**, as the PHP's `form()` did (`/campaign.php/more` posts to itself), but escaped.

## Not comparable here

- **Files under welcome-pages/ and campaign/.** The directories, a missing slash and the PHP inside them get
  Apache's answers from hxweb. Their files are static: Apache served the `.html` ones and prod's nginx the images
  and CSS, from the docroot. The rig's docroot copy does not hold them, so those cases fail on the closed backend.
- **Embeds, feeds and a post without a form** on the sign-up pages (`/request-access/embed/`,
  `/request-access/feed/`, a POST with no `gform_submit`) are WordPress's answers for every page, not ported yet.
