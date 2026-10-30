import gzip
import json
import os
import tempfile
import unittest

import hx_corpus

LOG = [
    'www.harcourt.co:443 198.51.100.7 - - [29/Oct/2026:10:00:00 +0000] "GET /catalog.php?scscpq_wp=1&subcategory_id=4 HTTP/1.1" 200 10 "-" "Mozilla"',
    'www.harcourt.co:443 198.51.100.8 - - [29/Oct/2026:10:00:01 +0000] "GET /catalog.php?scscpq_wp=1&subcategory_id=4 HTTP/1.1" 200 10 "-" "Mozilla"',
    'www.harcourt.co:443 198.51.100.9 - - [29/Oct/2026:10:00:02 +0000] "GET /catalog.php?subcategory_id=5&scscpq_wp=1&lang=fr HTTP/1.1" 200 10 "-" "Mozilla"',
    '198.51.100.10 - - [29/Oct/2026:10:00:03 +0000] "GET /remote_code.php?email=someone%40example.com&code=ABCD1234 HTTP/1.1" 200 5 "-" "Mozilla"',
    '198.51.100.11 - - [29/Oct/2026:10:00:04 +0000] "GET /x.php?token=Zm9vYmFyYmF6cXV4cXV1eHF1dXhxdXV4 HTTP/1.1" 302 5 "-" "Mozilla"',
    '198.51.100.12 - - [29/Oct/2026:10:00:05 +0000] "GET /shell.php HTTP/1.1" 404 5 "-" "Mozilla"',
    '198.51.100.13 - - [29/Oct/2026:10:00:06 +0000] "POST /scscpq_wp.php HTTP/1.1" 200 5 "-" "Mozilla"',
    '198.51.100.14 - - [29/Oct/2026:10:00:07 +0000] "GET /index.php?-d+allow_url_include%3D1 HTTP/1.1" 200 5 "-" "Mozilla"',
    '198.51.100.15 - - [29/Oct/2026:10:00:08 +0000] "GET /wp-content/uploads/2024/05/logo.png HTTP/1.1" 200 5 "-" "Mozilla"',
    '198.51.100.16 - - [29/Oct/2026:10:00:09 +0000] "GET /wp-content/uploads/2025/01/logo.png HTTP/1.1" 304 0 "-" "Mozilla"',
    '198.51.100.17 - - [29/Oct/2026:10:00:10 +0000] "GET /catalog/ HTTP/1.1" 200 5 "-" "Route53-Health-Check"',
    '198.51.100.18 - - [29/Oct/2026:10:00:11 +0000] "GET /catalog HTTP/1.1" 301 5 "-" "Mozilla"',
    '198.51.100.19 - - [29/Oct/2026:10:00:12 +0000] "GET /catalog/ HTTP/1.1" 200 5 "-" "Mozilla"',
    '198.51.100.20 - - [29/Oct/2026:10:00:13 +0000] "GET /.env HTTP/1.1" 301 5 "-" "Mozilla"',
    '198.51.100.21 - - [29/Oct/2026:10:00:14 +0000] "GET /a/.git/config HTTP/1.1" 403 5 "-" "Mozilla"',
]


class CorpusTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.mkdtemp()
        self.log = os.path.join(self.dir, "access.log.2.gz")
        with gzip.open(self.log, "wt") as f:
            f.write("\n".join(LOG) + "\n")
        self.seen = hx_corpus.read([self.log])

    def test_only_answered_reads_without_attacks(self):
        paths = {p for (_, p, _) in self.seen}
        self.assertNotIn("/shell.php", paths)
        self.assertNotIn("/scscpq_wp.php", paths)
        self.assertNotIn("/index.php", paths)
        self.assertNotIn("/.env", paths)
        self.assertIn("/catalog.php", paths)

    def test_secrets_and_addresses_never_reach_the_output(self):
        routes = hx_corpus.build(self.seen)
        text = json.dumps(routes)
        for secret in ("someone", "example.com", "ABCD1234", "Zm9vYmFy", "198.51.100", "Mozilla", "Route53"):
            self.assertNotIn(secret, text)
        (case,) = routes["/remote_code.php"][1]
        self.assertEqual(case["path"], "/remote_code.php?email=x&code=x")

    def test_routes_group_query_shapes_and_fold_numbers(self):
        routes = hx_corpus.build(self.seen)
        total, cases = routes["/catalog.php"]
        self.assertEqual(total, 3)
        self.assertEqual([c["hits"] for c in cases], [2, 1])
        self.assertEqual(cases[1]["language"], "fr")
        self.assertEqual(routes["/wp-content/uploads/{n}/{n}/logo.png"][0], 2)

    def test_files_are_written_per_route_with_unique_names(self):
        out = os.path.join(self.dir, "out")
        kept, n = hx_corpus.write(out, hx_corpus.build(self.seen), 1)
        self.assertEqual(kept, 6)
        pages = sorted(os.listdir(os.path.join(out, "pages")))
        self.assertIn("catalog.jsonl", pages)
        self.assertIn("catalog-2.jsonl", pages)
        with open(os.path.join(out, "assets.jsonl")) as f:
            self.assertEqual(len(f.readlines()), 2)
        with open(os.path.join(out, "routes.tsv")) as f:
            self.assertEqual(f.readline().split("\t")[:3], ["route", "kind", "requests"])
        kept2, _ = hx_corpus.write(os.path.join(self.dir, "out2"), hx_corpus.build(self.seen), 2)
        self.assertEqual(kept2, 2)

    def test_changing_actions_and_excluded_urls_are_never_cases(self):
        log = os.path.join(self.dir, "mut.log")
        lines = [
            '198.51.100.20 - - [29/Oct/2026:10:00:00 +0000] "GET /user.php?action=delete&record_id=9 HTTP/1.1" 200 1 "-" "M"',
            '198.51.100.21 - - [29/Oct/2026:10:00:00 +0000] "GET /log.php?clear=1 HTTP/1.1" 200 1 "-" "M"',
            '198.51.100.22 - - [29/Oct/2026:10:00:00 +0000] "GET /job.php?api_key=k HTTP/1.1" 200 1 "-" "M"',
            '198.51.100.23 - - [29/Oct/2026:10:00:00 +0000] "GET /private.php?a=1 HTTP/1.1" 200 1 "-" "M"',
            '198.51.100.24 - - [29/Oct/2026:10:00:00 +0000] "GET /user.php?action=maintain&record_id=9 HTTP/1.1" 200 1 "-" "M"',
        ]
        with open(log, "w") as f:
            f.write("\n".join(lines) + "\n")
        seen = hx_corpus.read([log], [hx_corpus.re.compile(r"^/private\.php")])
        self.assertEqual({(p, q) for (_, p, q) in seen}, {("/user.php", "action=maintain&record_id=9")})

    def test_missing_php_files_can_be_dropped(self):
        out = os.path.join(self.dir, "out3")
        kept, _ = hx_corpus.write(out, hx_corpus.build(self.seen), 1, exists=lambda r: r == "/catalog.php")
        self.assertEqual(kept, 4)


if __name__ == "__main__":
    unittest.main()
