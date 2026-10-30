# Moving www.harcourt.co off PHP and WordPress

The goal is one compiled program and no PHP runtime on the server. Rust serves every request. sqlx talks to the database. The browser runs strict TypeScript. SQLite replaces MySQL once the last PHP page is gone.

This repository is public and the PHP still serves most of the site, so this document describes security problems by class and does not name the entry points involved. The details are in a private note to the site owner.

## Why leave PHP

A PHP site has two kinds of exposure. One is the runtime and the code you did not write. The other is the code you did. Both show up in this site's logs and source.

### The runtime and WordPress cost a patch every few weeks, forever

The server runs Apache, PHP 8.4, WordPress core and 30 plugins. Eighteen plugins come from wordpress.org. Twelve are premium and have no public checksums to verify against, so the only check on them is that they match the copy someone downloaded. WordPress and its plugins are 19,028 of the 32,004 files in this repository. Each file is a place a vulnerability can appear, and each needs a patch the day the vendor ships it.

Any `.php` file under the document root is a URL, and Apache runs it. An upload directory, a stray backup or a plugin test file becomes code the moment it lands there. The server carries 728 `.htaccess` files that exist only to stop PHP from running in directories where it should never run.

### The logs show who is knocking

In the six days after the move to AWS (2026-10-23 to 2026-10-29), 145,027 requests for `.php` paths reached Apache. `tools/php_route_inventory.py` regenerates these figures from the server's logs.

- 5,345 distinct `.php` paths that do not exist in this repository were requested 10,281 times. These are probes for web shells and known-vulnerable scripts.
- 68,253 requests went to WordPress entry points. 65,063 of them were `xmlrpc.php`, and 62,708 of those got a 2xx answer. That is the pattern of password guessing batched through XML-RPC. The 1,238 requests to `wp-login.php` are the same traffic.
- The logs include requests carrying PHP-CGI option-injection arguments in the query string (the CVE-2024-4577 pattern). They do not work against PHP-FPM on Linux. They do show that scanners treat a PHP runtime as a standing target.

Only 90 of this repository's PHP files received any web request in those six days.

### The application's own PHP has design problems a patch does not fix

The SCS application is about 79,000 lines in the root, `includes/`, `classes/`, `scsps/` and `oauth/`, with another 74,000 in `includes_legacy/`, `xincludes/`, `xincludes_legacy/` and `xscsps/`. Reading its core turned up these classes of problem.

- **Identity.** A visitor is signed in by IP address, and the address comes from `X-Real-IP` or `X-Forwarded-For` before the socket (`harcourt_remote_addr()` in `scs_header.php`). The `user` table has `ip varchar(15)`, so an IPv6 address does not fit.
- **Entry points that decide who you are without asking.** Some scripts set or change the session user, or a user's domain, with no sign-in check, or check after they have answered. One of them is replaced and removed in this PR.
- **Sign-in codes.** The emailed code is `dechex(strtotime("+15 minutes"))` (`classes/remote.php`). It is the expiry time in hex. The three-try counter lives in the PHP session, so a new session resets it.
- **SQL.** Queries are built by joining string fragments. `fn_escape()` quotes strings and returns nothing for an array, so `?x[]=1` becomes broken SQL. Outside `scs_pdo.php` and WordPress, nothing in the SCS code uses a prepared statement.
- **No CSRF protection.** The SCS code has no tokens. The `SameSite=Lax` session cookie is the only defense.
- **Errors on screen.** `scs_header.php` turns `display_errors` on whenever `SERVER_ADMIN` is not the developer's address, which is the opposite of the intent.
- **Secrets in source.** Credentials are written into tracked files instead of the environment.
- **Session trust in the browser.** `scscpq_wp.js` accepted `postMessage` from any origin and wrote the payload into the page as HTML.

### What a Rust program changes, and what it does not

Rust, sqlx and strict TypeScript remove whole classes of problem at the language level.

- No interpreter and no plugin ecosystem. The deployable is one binary built from `Cargo.lock`. `cargo audit` lists the dependencies that need updates.
- Routes are a table in the program. A file in a directory is never a route, so there is nothing for a web shell to be.
- sqlx binds values as parameters. `sqlx::query!` checks each statement against the schema when the program compiles, so a misspelled column or a changed type fails the build.
- Role and status arrive from the database as text and are parsed once into enums (`rust/src/identity.rs`). A value the program does not know grants nothing.
- A handler that needs a signed-in Administrator takes `Authed<Administrator>` as an argument (`rust/src/api.rs`). There is no way to write the handler and skip the check.
- Memory-unsafe bugs do not occur in safe Rust, and this crate contains no `unsafe`.

It does not remove logic bugs. A port that copies the PHP flow also copies its flaws. The PHP above is a list of design decisions, and the fix for each is to redesign the flow, as `rust/src/remote_grant.rs` does for the one flow ported here. Dependencies still need updates, but there are 16 direct ones to watch instead of 30 plugins.

## What this PR changes

**`rust/`** brings `hxweb`, the Rust front that already serves the catalog, the static pages and search, into this repository. It adds the pieces that replace PHP.

| File | Role |
|---|---|
| `migrations/0001_core.sql` | SQLite schema. `user` and `log` match the MySQL tables column for column, so cut-over is a copy. Adds `session`, `access_code`, `remote_grant`, `outbox`. |
| `src/identity.rs` | `Role`, `Status`, `User`, `Email`. Parses database text once. |
| `src/session.rs`, `src/token.rs` | Random 256-bit session cookies. The database stores only the SHA-256. Sessions expire after 12 hours and sign-out deletes them. |
| `src/access_code.rs` | Emailed sign-in code. 8 characters from the system CSPRNG, 15 minutes, five tries counted in the database, stored as an HMAC, consumed on success. |
| `src/remote_grant.rs` | Replaces `scs_remote.php`. See below. |
| `src/api.rs` | `/api/auth/code`, `/api/auth/verify`, `/api/auth/logout`, `/api/me`, `/api/remote/grants`, `/api/remote/redeem`. Refuses cross-origin POSTs. |
| `src/retired.rs` | Answers 410 for retired PHP paths before anything is proxied to Apache. |
| `src/config.rs`, `src/db.rs`, `src/audit.rs` | Settings parsed once, SQLite pool and migrations, security events in `log`. |

**`scs_remote.php` is gone.** It built a code from a user id, an IP address and a timestamp with no secret in it, and its `access` action put the named user into the session without any sign-in. Nothing in the repository references it, and six days of logs show no request for it. The replacement works like this.

- Minting needs a signed-in Administrator and cannot reach above that Administrator's own role.
- The credential is 256 random bits, valid for ten minutes, and redeemable once. Redeeming is one atomic `UPDATE ... RETURNING`.
- Missing and out-of-reach targets get the same answer, so ids cannot be probed.
- `retired.rs` returns 410 for `scs_remote.php` in every form Apache would run it, including `//`, `/./`, percent-encoded names and `/scs_remote.php/extra`. This closes the hole on any server that still has the file.

**`web/`** holds the TypeScript for `hx/hx-nav.js` and `scs_scripts/scscpq_wp.js` under `strict`, `noUncheckedIndexedAccess` and `exactOptionalPropertyTypes`. `npm run build` writes the two files the pages already load, and `npm run verify` fails if the committed copy is not what the TypeScript compiles to. The jsdom tests ran against the original JavaScript first. Twelve of fifteen passed, and the three that failed are the intended changes below. All fifteen pass on the generated files.

Intended changes to `scscpq_wp.js`:

- A `message` is acted on only when it comes from this page's own origin.
- `scscpq_ipc` posts to this origin instead of `*`.
- A failed request shows the server's text as text.
- `ajax_fill` no longer logs element ids to the console.

**`tools/php_route_inventory.py`** produces the numbers above from Apache logs without printing any client address.

## How the rest moves

This PR is the foundation and one slice. PHP still serves everything else, and `hxweb` still proxies to it. A PHP file is deleted when its replacement has tests and the file has had no requests for 14 days.

| Phase | Replaces | Traffic in six days |
|---|---|---|
| A. This PR | `scs_remote.php`, sign-in and session foundation, browser scripts | none |
| B. Accounts | `newuser.php`, `oauth/*`, `user_impersonate.php`, `login.php`, `cookie_reset.php`, MySQL to SQLite import of `user` and `remote` | about 500 |
| C. Catalog reads | `scscpq_wp.php`, `catalog.php`, `scscpq_api.php`, `scs_file.php`, `hx_search_ctx.php`, `scs_index.php` | about 66,000 |
| D. Orders and forms | `cart.php`, `checkout.php`, `orderhd.php`, Gravity Forms handlers, the `outbox` sender | about 150 |
| E. Staff tools | `dashboard.php`, `inventory*.php`, `user*.php`, `remote_*.php`, `portal*.php`, `mailer_*.php`, `scs_log.php` | about 1,100 |
| F. WordPress | `wp-login.php`, `xmlrpc.php`, `wp-admin`, `wp-cron.php`, the theme | about 68,000, nearly all hostile |
| G. Cut-over | Import the rest of MySQL into SQLite, stop Apache, PHP-FPM and MariaDB, uninstall PHP | |

Phase F can start early. The public pages are already static, so `xmlrpc.php` and `wp-login.php` can answer 410 from `retired.rs` as soon as no one needs WordPress admin.

Phase C is the largest and the most checkable. Each view gets a differential test that compares Rust output with `catalog.php` output for every subcategory in both languages.

## Deploy notes for this PR

1. Build with `cd rust && SQLX_OFFLINE=true cargo build --release`. The `.sqlx/` directory lets the build run without a database.
2. In `hxweb.service`, add `StateDirectory=hxweb` and `Environment=HXWEB_DB=/var/lib/hxweb/hx.db`. Put `HXWEB_SECRET` (at least 32 bytes as hex, for example `openssl rand -hex 32`) in a root-only `EnvironmentFile`. `HXWEB_PUBLIC_ORIGIN` defaults to `https://www.harcourt.co`. `HXWEB_TRUSTED_PROXIES` defaults to loopback, where nginx runs.
3. If the database cannot open or a setting is malformed, `/api` answers 503 and logs the reason. The pages keep serving.
4. Until the `user` table is imported into SQLite, no email domain matches a company, so sign-in sends nothing. The routes are dormant, and the only live effect of this PR is the 410 on `scs_remote.php`.
5. The static pages bake in `scscpq_wp.js?ver=`. Re-run the static export after deploy so they load the new script.
6. Roll back by restoring the previous `hxweb` binary. Do not restore `scs_remote.php`.

## Decisions for the site owner

- Who, if anyone, used `scs_remote.php`? The logs show no one in six days.
- The PHP signs a company in automatically from its IP address. The Rust model asks for an emailed code or a grant. Keeping IP sign-in would need an explicit allow-list of company addresses.
- The `outbox` sender (SMTP through Microsoft 365) is not written yet.
