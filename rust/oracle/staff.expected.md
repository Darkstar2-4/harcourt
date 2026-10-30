# Staff routes: expected differences from the oracle

Run `staff.jsonl` with `--rust-session`. The staff pages read the persona's session cookie, not the client address.
Every case below differs from the oracle only in the ways listed here. hx-diff reports them as failures, because
none of them is an allowed normalization.

## Deliberate changes

- **Personal sign-in for staff.** Staff pages take `Authed<R>`, a session from an emailed code or OAuth that stays
  valid for the registry's `remote_days` and renews on use. A visitor from a company's office address is not signed
  in as staff by that address alone, as the PHP did. The oracle signs personas in by address; Rust holds the
  persona's session (`--rust-session`).
- **CSRF field.** Every staff form carries `<input type=hidden name='csrf'>`, a token bound to the session, and
  every POST must return it (403 otherwise). The PHP forms had none. Each signed-in dashboard case shows this one
  added line until hx-diff's normalization rule for it lands.
- **Event log role.** `/scs_log.php` needs Executive or above, the role the dashboard shows its link to. The PHP
  page checked no role. No case exercises it.
- **Retired paths.** The web SQL consoles, PHP diagnostics and developer toolboxes (`retired.rs`) answer 410 in
  Rust. There are no cases for them.
- **Dashboard menu.** The links to `/scs_mysql.php`, `/scs_toolbox.php`, `/jquery_toolbox.php`,
  `/php_toolbox.php` and `/scs_filemanager.php` are gone, because those paths answer 410. "Remote Grants"
  (`/staff/remote-grants`) is added for Administrators. The other links stay as the PHP printed them, though the
  pages most of them name are gone (below), so the dashboard renders exactly as before.
- **The cut.** The maintenance pages answer 410 (`retired.rs`); their data work is `hxweb export`, `hxweb import`,
  `hxweb job` and `hxweb remote-grants` (`staff/cli.rs`). The Pending Access tab still links each request to
  `/user.php?action=maintain&record_id=<id>`, which redirects to `/staff/access/<id>`, where an Administrator
  approves or denies it; every other `/user.php` request answers 410. The menu's "Pages" link
  (`/pages.php`) redirects to the cms page-text editor, `/staff/cms`.
- **Stopwatch tab.** The Super User tab that timed the dashboard's queries and printed their SQL is not ported. The
  superuser case shows its absence.
- **Active accounts only.** Staff pages take `Authed<R>`, which refuses Pending and Denied sessions. The oracle signs
  those companies in and checks only the role. The Pending and Denied personas are left out of the cases, as
  ORACLE.md describes for `Visitor`.
- **Escaping.** Element text escapes `<`, and attributes are fully escaped. The output is identical for data
  without those characters, which is all the data the cases see.
- **WordPress mode.** `?scscpq_wp=1` is read on each request. The PHP kept it in its session after the first
  request.

## Caused by the oracle or another area

- **First-request landing redirect.** hx-diff sends no cookies, so every oracle request starts a PHP session. On
  that first request the PHP sends a signed-in visitor to their landing page: `/dashboard.php` for staff,
  `/catalog.php` for customers. Signed-in personas can therefore be compared only on `/dashboard.php`.
  `/scs_log.php` and `/company.php` have anonymous cases only. A Rust session has no first
  request, so a below-role session goes to `/scs_index.php`, where the PHP's `user->access()` sends it on later
  requests. The landing redirect itself belongs to the accounts sign-in flow.
- **`harcourt_profile` cookie.** The PHP sets it on every new session, so every oracle response names it. The Rust
  `Visitor` layer (accounts) does not set it.
- **Persona sign-in times.** Each oracle request rewrites the persona's `last_login` and logs a sign-in. Persona
  rows, and the count in the "7 Day Access (N)" tab title, differ from the SQLite copy.
- **Lead Score row order.** Rows with equal scores come back in a different order on each oracle request. Rust
  orders by score, then by address. Compare the rows as a set.
- **Lead Score portal thumbnails.** The `scs_file.php` key (`content_id`) encodes the viewer's last sign-in time,
  which the oracle rewrites on every request, so the key differs on every run.

With those set aside, every tab of every dashboard case matches row for row. That covers executive, administrator
and superuser, the `?tab=` landing tabs and WordPress mode. The anonymous redirects match on status and
`Location`.
