# The site without PHP (m-site): deliberate differences from the oracle

- **The old stack is gone.** A path no route claims with a `.php`, `wp-*` or `xmlrpc` segment, and any `rest_route` query, answers 410 (`src/retired.rs`): WordPress sign-in, admin, `admin-ajax.php`, the REST API (`/wp-json/`, `?rest_route=`), xmlrpc, every PHP script the program did not keep, and missing files under `wp-content` and `wp-includes`.
- **The blog is gone.** Its home (`/?order=`, `/?author=`), the feeds (`/feed/`, `/comments/feed/`, a page's or term's `feed/`), category, author and date archives, post, case-study and testimonial singles, attachments, embeds and paths of three or more segments answer the 404 page; WordPress listed the blog's posts under that 404.
- **Sitemaps.** The index lists the `page`, `faq` and `faq_type` sitemaps. The `post`, `case_study`, `category` and `author` sitemaps answer the 404 page.
- **WordPress query vars.** On the front page they still redirect or search as WordPress did; on a content page the page shows, where WordPress showed the post or archive the var named.
- **Static files** come from the asset directory with the content types prod sent. A missing one, or one asked for with a trailing slash, is the 404 page, not Apache's or nginx's.
- **`?hx_form=`** was refused by nginx for WordPress's form island, which is gone; the key is ignored.
- **`/index.php?s=`** redirects to `/?s=` like every other `/index.php` request.
