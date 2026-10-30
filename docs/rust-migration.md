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

It does not remove logic bugs. A port that copies the PHP flow also copies its flaws. The PHP above is a list of design decisions, and the fix for each is to redesign the flow, as `rust/src/remote_grant.rs` and the sign-in code in `rust/src/accounts/` do. Dependencies still need updates, but there are 28 direct ones to watch (cargo audit lists them) instead of 30 plugins and a PHP runtime.

## What this PR changes

The PR moves the site's request handling into one Rust program, `hxweb`. It brings in the front that already served the catalog, static pages and search, and adds Rust versions of the account, catalog, order, staff and WordPress behavior. Anything a Rust module does not claim still goes to Apache and PHP, so the move can be checked and deployed in steps.

| Area | Where | What it replaces |
|---|---|---|
| Visitor identity | `src/visitor.rs`, `src/accounts/` | `scs_header.php`'s sign-in by address, bots, campaigns, language and currency, the PHP session, and the first-visit redirect. Sign-in by emailed code and by Microsoft or Google, sign-up, impersonation, sign-out. |
| Catalog | `src/catalog/`, `src/compose.rs`, `src/pricing.rs` | `catalog.php`, `scscpq_wp.php`, `scscpq_api.php`, `scs_file.php`, `scs_ajax.php`, search, the catalog page's composition. |
| Orders | `src/orders/` | `cart.php`, `checkout.php`, `orderhd.php`, carriers, contact, every live Gravity Forms form, and the sender that drains the `outbox` table. |
| Staff | `src/staff/` | The dashboard with its access requests, the log viewer, and the commands `hxweb export`, `hxweb import`, `hxweb job` and `hxweb remote-grants` for the data work, the cron jobs and remote grants (`hxweb export` alone prints their usage). |
| WordPress | `src/cms/`, `src/routes/wordpress.rs`, `docs/cms.md` | The theme, the page content, menus, redirects, sitemaps, the 404 page, and editing page text from the staff dashboard. |
| Database | `migrations/` | SQLite schema for all 37 application tables, generated from the MySQL schema, plus the tables Rust adds (`session`, `visit`, `access_code`, `remote_grant`, `outbox`, `cart`, the `cms_*` tables). |
| Browser | `web/` | `hx/hx-nav.js` and `scs_scripts/scscpq_wp.js`, now generated from strict TypeScript. |

Pages are rendered by Rust functions that write the same HTML, classes and ids the PHP wrote, so the existing CSS and scripts keep working. Every value a page prints is escaped. Queries are `sqlx::query!` with bound values, checked against `migrations/` when the program compiles, and `rust/.sqlx/` lets CI build without a database.

### Behavior that changes on purpose

These are not bugs. Each is listed with its reason in the `rust/oracle/*.expected.md` files.

- **Staff pages need a real sign-in.** An emailed code or OAuth, not a company's address. Pending and Denied staff accounts are turned away, and the role is checked before any AJAX call.
- **State-changing forms need a token.** Every staff form carries a per-session token. A GET never changes data.
- **The emailed code is random.** 8 characters from the system random source, 15 minutes, five tries counted in the database. The PHP code was derived from the clock.
- **Retired paths answer 410.** `scs_remote.php` is deleted and replaced by `remote_grant.rs`. The web SQL consoles, PHP diagnostics, developer toolboxes and `scs_filemanager.php` have no replacement. The list is `src/retired.rs`.
- **Errors are not printed to visitors.** `login.php` failed on every request and printed the database's error; Rust prints a plain message.
- **Files are served by id to whoever may see them.** `scs_file.php` no longer serves a file by name.
- **Outside services are opt-in.** ipstack lookups at sign-up run only with `HXWEB_IPSTACK=on`. Spam checks and mail read their keys from the environment and skip with a warning when absent.
- **Browser scripts trust less.** `scscpq_wp.js` acts on a `message` only from its own origin, posts only to its own origin, shows a failed request's text as text, and no longer logs element ids.

### How it was checked

The PHP site runs unchanged on a private box (the oracle), with a copy of the production database and files and no way to send mail or reach outside services. `rust/src/bin/hx-diff.rs` sends the same request to the oracle and to a Rust server, as synthetic accounts of every role and status, in both languages, and compares status, the headers that matter and the normalized body. Each normalization rule removes one kind of value (nonces, session ids, times near now, asset versions) and has a test. `rust/oracle/` holds the cases and the list of deliberate differences. A separate suite of about 9,400 cases was written from the PHP alone and run against the Rust server; only its counts were read. `rust/src/bin/hx-import.rs` copies the MySQL tables into SQLite and checks row counts and checksums per table.

`rust/fuzz` holds property tests over everything that reads untrusted input: the query, form, cookie and upload readers, the path clean-up, WordPress routing, PHP's serialize format, the csv and Excel import, the escapers and markup builders, the mail the outbox sends, the PHP number and date formats, and the whole router answering generated requests over synthetic rows. Each states a documented invariant and a time or memory bound. Runs are deterministic; `HX_FUZZ_CASES=3000000 cargo test --release` in that directory is a long run, and `HX_FUZZ_SEED` picks another seed.

`.github/workflows/rust-web.yml` runs the Rust tests and clippy, the tool builds, the property tests in `rust/fuzz`, the TypeScript checks and the Python tool tests.

## How the rest moves

PHP files stay in the tree until their routes have held up under the checks above and a week of traffic shows no request for them. When the last one is gone, `proxy.rs` and the Apache, PHP-FPM and MariaDB services go with it.

What is still handed to PHP is listed in each area's expected-differences file. In short, it is the blog home, author archives, post singles and search feeds of the WordPress front controller (the FAQ pages, category archives, feeds and `?s=` search on the front page are in Rust), a few catalog leftovers, the cron jobs that call outside services (`cad_toolbox`), and attachments on mail from the staff pages.

The order of a deploy is the order of risk.

1. Stand the program up beside PHP with the `rust/` build and the SQLite file, and confirm the log shows no `/api disabled`.
2. Import `user`, `remote` and the other tables with `hx-import`, and the page content with `hxweb-cms import`. Without the content import every page the cms claims answers 404.
3. Send read-only traffic first (the catalog and the static pages), then accounts and orders, then the staff pages.
4. Turn `HXWEB_RETIRE_WORDPRESS=1` on when no one needs WordPress admin.
5. Stop Apache, PHP-FPM and MariaDB when the legacy handoff count in the log has stayed at zero.

## Deploy notes

1. Build the `hxweb` and `hxweb-cms` binaries in release mode from `rust/` with `SQLX_OFFLINE=true`. The `.sqlx/` directory lets the build run without a database.
2. In `hxweb.service`, add `StateDirectory=hxweb` and `Environment=HXWEB_DB=/var/lib/hxweb/hx.db`. Put `HXWEB_SECRET` (at least 32 bytes as hex, for example `openssl rand -hex 32`) in a root-only `EnvironmentFile`. The other settings are in the next section.
3. If the database cannot open or a core setting is malformed, hxweb mounts none of its own routes: `/api` answers 503, every other request goes to the legacy stack, and the log says `/api disabled` with the reason.
4. Until the `user` table is imported into SQLite, no email domain matches a company, so sign-in sends nothing.
5. The static pages bake in `scscpq_wp.js?ver=`. Re-run the static export after deploy so they load the new script.
6. Roll back by restoring the previous `hxweb` binary. Do not restore `scs_remote.php`.

## Deploy settings

hxweb reads these from its environment once, at start. Put the secrets (`HXWEB_SECRET`, the SMTP password, the OAuth client secrets, the Akismet key, the reCAPTCHA secret) in a root-only `EnvironmentFile`; the rest can go in `hxweb.service`. After a deploy, check the log: a missing optional setting logs a warning naming it, and a malformed core setting logs `/api disabled`.

A malformed value in the core group (marked core below) is not a warning. hxweb then mounts none of its own routes, so every page goes to the legacy stack, and once PHP is gone that is an outage.

| Variable | Default | What it is for | Without it |
|---|---|---|---|
| `HXWEB_LISTEN` | `127.0.0.1:8090` | Address hxweb listens on, behind nginx. | Listens on the default; nginx must point there. |
| `HXWEB_DB` (core) | `/var/lib/hxweb/hx.db` | The SQLite database. | If it cannot be opened, no Rust route is mounted. |
| `HXWEB_SECRET` (core) | none | At least 32 bytes as hex. Keys the emailed sign-in codes and the Gravity Forms anti-spam token. | Emailed-code sign-in answers 503, sign-up sends no code, and form posts are not checked for the anti-spam token. Not hex or under 32 bytes: no Rust route is mounted. |
| `HXWEB_PUBLIC_ORIGIN` (core) | `https://www.harcourt.co` | The one origin browsers may post from; absolute redirects; the blog URL sent to Akismet. | Must be `https://host` with no path, or no Rust route is mounted. A wrong host refuses every form post from the real site (403). |
| `HXWEB_TRUSTED_PROXIES` (core) | `127.0.0.1,::1` | Proxies whose `X-Real-IP` is believed, comma-separated. | Not a list of addresses: no Rust route is mounted. Without nginx's address, every visitor is seen as nginx: no sign-in by company address, and logs record the proxy. |
| `HXWEB_LEGACY` | `https://127.0.0.1:8443` | The Apache and PHP stack, for paths Rust does not claim yet and for a form the orders tables lack. | Those requests answer 502. |
| `HXWEB_SITE` | `www.harcourt.co` | Host header sent to the legacy stack; the host the exported pages link to. | Default host. |
| `HXWEB_PUBLIC_HOST` | `HXWEB_SITE` | Host written into page links instead of `HXWEB_SITE`, for a staging copy. | Links name `HXWEB_SITE`. |
| `HXWEB_EXPORT` | `/home/harcou6/static_site` | The static WordPress export and its `manifest.json`, which says how each page embeds its forms. | Pages not yet rendered from the cms fall to the legacy stack, and every form is embedded with Gravity Forms' defaults. |
| `HXWEB_DOCROOT` | `/home/harcou6/public_html` | The PHP document root: vendored scripts and styles, mail stylesheets, flag images, and the image files the catalog, campaign and subcategory pages check for. | The vendored assets and mail styles are read from the default path; the image checks count every file as present. |
| `HXWEB_SEARCH` | none | Directory of search click data (`build_learned.py`). | Search ranks without what earlier searchers opened. |
| `HXWEB_OFF` | empty | Page rewrites to switch off without a build: `tabs`, `repeats`, `dead-assets`, `preload`, `aos`, `fa-static`, `bg-preload`, `fonts-css`. | Every rewrite is on. |
| `HXWEB_RETIRE_WORDPRESS` | unset | `1` makes `wp-login.php`, `wp-admin/*` (except `admin-ajax.php`) and `wp-json/*` answer 410. | WordPress admin stays reachable through the legacy stack. |
| `HXWEB_ADMIN_EMAIL` | none | WordPress's `admin_email`, which form notifications address as `{admin_email}`. | That address is left out; a notification with no other recipient is not sent. |
| `HXWEB_AKISMET_KEY` | none | Akismet key for the form spam check. | Akismet is skipped, with a warning. The honeypot and the anti-spam token still apply. |
| `HXWEB_RECAPTCHA_SECRET` | none | reCAPTCHA v3 secret that scores the token the contact forms (3, 4, 5, 6, 12, 13 and 14) send. The site key comes from the `registry` table. | The score is not checked, with a warning. A post without the browser's token is still refused, as the PHP did. |
| `HXWEB_SMTP_USER` | none | Microsoft 365 mailbox that sends the `outbox`. | Mail is queued in `outbox` and not sent, with a warning. |
| `HXWEB_SMTP_PASSWORD` | none | Its password. Required with `HXWEB_SMTP_USER`. | The sender does not start (logged as an error); mail stays queued. |
| `HXWEB_SMTP_HOST` | `smtp.office365.com` | SMTP server, STARTTLS. | Default server. |
| `HXWEB_SMTP_PORT` | `587` | SMTP port. | Default port. Not a number: the sender does not start. |
| `HXWEB_SMTP_FROM` | `HXWEB_SMTP_USER` | From address of the site's mail. | The mailbox's own address. Not an address: the sender does not start. |
| `HXWEB_SMTP_FROM_NAME` | `Harcourt` | From name, where a form notification does not set one. | `Harcourt`. |
| `HXWEB_MICROSOFT_CLIENT_ID`, `_CLIENT_SECRET`, `_TENANT` (core) | none | Microsoft sign-in, the PHP `.env` `MICROSOFT_*` values. All three or none. | No Microsoft sign-in. Only some of the three, or a tenant that is not an id or domain: no Rust route is mounted. |
| `HXWEB_GOOGLE_CLIENT_ID`, `_CLIENT_SECRET` (core) | none | Google sign-in. Both or neither. | No Google sign-in. Only one of the two: no Rust route is mounted. |
| `HXWEB_IPSTACK` (core) | `off` | `on` looks each new account's address up at IPStack (URL and key from the `registry` table), as the PHP did. Off keeps a test or staging server from calling the paid service. | New accounts get no IPStack data or currency from it. Anything but `on` or `off`: no Rust route is mounted. |

The staff commands (`hxweb job`, `hxweb export`, `hxweb import`, `hxweb remote-grants`) read `HXWEB_DB`, `HXWEB_SECRET`, `HXWEB_PUBLIC_ORIGIN` and `HXWEB_TRUSTED_PROXIES` the same way and stop on a malformed one.

## Decisions for the site owner

- The PHP signs a company in automatically from its IP address. The Rust model keeps that for customers browsing and using the cart, and asks staff for an emailed code or OAuth. Keeping address sign-in for staff pages would need an explicit allow-list of company addresses.
- Which Microsoft 365 mailbox sends the site's mail (`HXWEB_SMTP_USER`)? Until one is set, mail waits in `outbox`.
- Whether `HXWEB_RETIRE_WORDPRESS=1` goes on at cut-over or later depends on whether anyone still edits pages in WordPress admin.
- Production's PHP is not the `aws-production` branch. It is an upstream version with local edits (32 code files differ). Commit that drift upstream before comparing this PR to the repository's PHP.
