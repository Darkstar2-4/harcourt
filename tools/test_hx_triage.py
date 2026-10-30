import unittest

import hx_triage

OUTPUT = """PASS fz000001.persona anonymous (warm)
FAIL fz000002.id+drop customer-active (warm): body
--- oracle
+++ rust
@@ -4,3 +4,3 @@
 <div>
-<p>item 1234
+<p>item 99
FAIL fz000003.id customer-active (warm): body
--- oracle
+++ rust
@@ -4,3 +4,3 @@
-<p>item 77
+<p>item 1
FAIL fz000004.slash anonymous: status 200 vs 404; body
--- oracle
+++ rust
@@ -1,2 +1,2 @@
-status: 200
+status: 404
ERROR fz000005.page anonymous (warm): rust: connection refused
3 passed, 3 failed, 1 errors
"""
ROUTES = {"fz000002.id+drop": "/catalog/", "fz000003.id": "/catalog.php", "fz000004.slash": "/product/"}


class TriageTest(unittest.TestCase):
    def test_groups_by_first_differing_line(self):
        groups = hx_triage.triage([OUTPUT], ROUTES)
        self.assertEqual(len(groups), 3)
        item = groups[("*", "same", "-<p>item #")]
        self.assertEqual(item["n"], 2)
        self.assertEqual(dict(item["routes"]), {"/catalog/": 1, "/catalog.php": 1})
        self.assertEqual(dict(item["mutations"]), {"id": 2, "drop": 1})
        self.assertEqual(item["example"], "fz000002.id+drop customer-active")
        self.assertEqual(groups[("*", "200 vs 404", "-status: #")]["n"], 1)
        self.assertEqual(groups[("*", "error", "rust: connection refused")]["routes"], {"?": 1})

    def test_by_route_splits_a_cause(self):
        groups = hx_triage.triage([OUTPUT], ROUTES, by_route=True)
        self.assertIn(("/catalog/", "same", "-<p>item #"), groups)
        self.assertIn(("/catalog.php", "same", "-<p>item #"), groups)


if __name__ == "__main__":
    unittest.main()
