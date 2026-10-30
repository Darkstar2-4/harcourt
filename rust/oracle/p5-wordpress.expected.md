# WordPress and search, round five (p5-wordpress, p5-catalog)

Run `p5-wordpress.jsonl` and `p5-catalog.jsonl` against the oracle as the other corpora are run. Rust renders the FAQ pages, the `faq_type` archives (with WPML's redirects between translations), `?s=` search on the front page and `cad_lookup.php`. What the blog showed is gone; see `m-site.expected.md`.

Deliberate differences, as in earlier rounds:

- `cad_lookup.php` no longer prints the PARTsolutions API key, and turns Pending and Denied staff away. Its JSON branch is not answered.
