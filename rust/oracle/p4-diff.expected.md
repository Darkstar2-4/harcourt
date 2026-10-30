# Mutation runs: expected differences from the oracle

The cases come from `tools/hx_mutate.py`, which mutates the visible corpora and the access-log corpus (parameters
dropped, emptied, repeated, reordered or case-flipped, ids swapped, page bounds, sort orders, slashes and dot
segments, the other language, every persona, a same-site Referer) and writes them outside the repository. They run
with `--warm --rust-session`, once with PHP behind Rust and once with the legacy backend closed;
`tools/hx_triage.py` groups the failures by route, status pair and first differing line. These are the signatures
that are not bugs, with the reason. Differences already recorded in another area's file (`scs_file.php`'s
`content_id`, the search box's language, `image_viewer.php`, `login.php`'s message, Pending and Denied staff sent
home, the database errors `inventory_info.php` printed) are not repeated.

## Deliberate

- **`scs_ajax.php` under another spelling** (`/scs_ajax.php/`, `//scs_ajax.php`) or with a query prod's hxweb did
  not take (an empty `limit`, a repeated key) answers like `/scs_ajax.php`: the search box's JSON, or the refusal
  (403 with `External access`) without a same-site Referer. Prod's hxweb claimed the exact path only and handed the
  rest to the PHP, which answered its older format, or 401 with an empty body. One endpoint answers one way.
- **`hx_search_ctx.php` under every spelling** answers nginx's 404, as prod's nginx did for the exact path. Its
  PATH_INFO spelling (`/hx_search_ctx.php/`) reached the PHP on the oracle and answered the session's context.
- **WPML's redirect from a path that begins with two slashes** (`//catalog/?lang=en`): WordPress's
  wp_safe_redirect() turned the protocol-relative Location into the admin URL, and Rust does too. The front page
  under more than one slash with `lang=en` goes to `/`, as on the oracle.

## Not comparable here

- **Requests prod's nginx answers itself** (see `wordpress.expected.md`; `?hx_form=` in any letter case now gets
  nginx's page from hxweb too): `/hx_search_ctx.php` under a doubled slash (Rust's
  404 also sets `harcourt_profile`), and static files, whose MIME types are nginx's or Apache's (`favicon.ico`,
  `.js` under `mu-plugins`).
- **The author sitemap.** The rig's `wp.sql` holds no users, so the cms has no authors and the sitemap index lists
  no `author-sitemap.xml`; the oracle lists it.
- **Dashboard counts.** The oracle writes a `log` row and `last_login` for every persona request, so the dashboard's
  access counts and tabs on the oracle run ahead of the SQLite copy.
- **`/robots.txt/`.** Apache refused the PATH_INFO on prod's docroot file with its 404. The rig's docroot copy has no
  `robots.txt` (Rust serves it from `rust/assets`), so the front controller sees no file and answers WordPress's 404.
- **WordPress shapes not ported**, which the closed backend shows as 502: a blank search or a page of results with
  a form under `?s=`, the 404 over the blog query as Yoast's sitemap router leaves it (`/sitemap.xml/`,
  `/sitemap.xml` with a query, a sitemap Yoast does not have), a doubled slash WordPress cannot resolve, a post id on
  the front page under a doubled slash (`//?p=`), WordPress's `page` argument on a page (`/catalog?page=0`), the
  blog's own listing (`?order=`, `?author=`), author and date archives, Atom feeds of archives, the singles
  of posts, case studies and testimonials, oEmbed, and the REST API beyond its no-route and invalid-id answers and the
  posts' ids.
