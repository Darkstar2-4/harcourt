"""Synthetic log lines only. Run: python3 -I -m unittest discover -s tools"""
import contextlib
import io
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(__file__))
import php_route_inventory  # noqa: E402

LINES = [
    'www.harcourt.co:443 192.0.2.1 - - [01/Jan/2026:00:00:01 +0000] "GET /catalog.php?x=1 HTTP/1.1" 200 10 "-" "Mozilla"',
    'www.harcourt.co:443 192.0.2.2 - - [01/Jan/2026:00:00:02 +0000] "GET //catalog.php HTTP/1.1" 200 10 "-" "Mozilla"',
    'www.harcourt.co:443 192.0.2.1 - - [01/Jan/2026:00:00:03 +0000] "POST /xmlrpc.php HTTP/1.1" 200 10 "-" "Mozilla"',
    'www.harcourt.co:443 192.0.2.3 - - [01/Jan/2026:00:00:04 +0000] "GET /shell.php HTTP/1.1" 404 10 "-" "Mozilla"',
    'www.harcourt.co:443 192.0.2.3 - - [01/Jan/2026:00:00:05 +0000] "GET /index.php?%ADd+allow_url_include%3D1 HTTP/1.1" 403 10 "-" "Mozilla"',
    'www.harcourt.co:443 192.0.2.9 - - [01/Jan/2026:00:00:06 +0000] "GET /catalog/ HTTP/1.1" 200 10 "-" "Mozilla"',
    'www.harcourt.co:443 192.0.2.8 - - [01/Jan/2026:00:00:07 +0000] "GET /catalog.php HTTP/1.1" 200 10 "-" "Amazon-Route53-Health-Check-Service"',
]


class Inventory(unittest.TestCase):
    def run_tool(self):
        with tempfile.TemporaryDirectory() as d:
            log, tracked = os.path.join(d, "access.log"), os.path.join(d, "tracked.txt")
            open(log, "w").write("\n".join(LINES) + "\n")
            open(tracked, "w").write("catalog.php\nindex.php\nREADME.md\n")
            out, err = io.StringIO(), io.StringIO()
            argv = sys.argv
            sys.argv = ["php_route_inventory", "--tracked", tracked, log]
            try:
                with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
                    php_route_inventory.main()
            finally:
                sys.argv = argv
            return out.getvalue(), err.getvalue()

    def test_counts_merge_slashes_skip_health_checks_and_never_print_clients(self):
        out, err = self.run_tool()
        rows = {r.split("\t")[0]: r.split("\t") for r in out.splitlines()[1:]}
        self.assertEqual(rows["/catalog.php"][1:3], ["2", "2"])
        self.assertEqual(rows["/catalog.php"][7], "yes")
        self.assertEqual(rows["/shell.php"][7], "no")
        self.assertEqual(rows["/xmlrpc.php"][3], "1")
        self.assertNotIn("192.0.2", out + err)
        self.assertIn("requests for a .php path\t5", err)
        self.assertIn("distinct .php paths that are not tracked files\t2", err)
        self.assertIn("php-cgi argument-injection options in the URL\t1", err)


if __name__ == "__main__":
    unittest.main()
