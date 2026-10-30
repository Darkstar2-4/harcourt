import json
import os
import sqlite3
import tempfile
import unittest
from urllib.parse import parse_qsl

import hx_mutate

PERSONAS = [
    {"name": "customer-active", "account": {"id": 990001}},
    {"name": "administrator-active", "account": {"id": 990013}},
    {"name": "anonymous"},
]
STAFF = [
    {"name": "u-list", "method": "POST", "path": "/user.php?scscpq_wp=1", "persona": "administrator-active",
     "session": "warm", "body": "action=list&report_option_field=ip&report_option_value=198.51.100."},
    {"name": "u-maint", "method": "POST", "path": "/user.php?scscpq_wp=1", "persona": "administrator-active",
     "session": "warm", "body": "action=maintain&record_id=990001"},
    {"name": "u-update", "method": "POST", "path": "/user.php?scscpq_wp=1", "persona": "administrator-active",
     "body": "action=update&record_id=990001&ip=198.51.100.9"},
    {"name": "u-delete", "path": "/user.php?action=delete&record_id=990001", "persona": "administrator-active"},
    {"name": "u-view", "path": "/user.php?scscpq_wp=1&action=maintain&record_id=990001",
     "persona": "administrator-active"},
]
OTHER = [
    {"name": "cat", "path": "/catalog.php?scscpq_wp=1&action=subcategory&subcategory_id=5&scs_page=0"},
    {"name": "page", "path": "/product/clamping/", "language": "en"},
    {"name": "quote", "path": "/legal?lang='x"},
    {"name": "cart-add", "path": "/cart.php?json_code=eA&json_quantity=2"},
    {"name": "flag", "path": "/catalog/?hxlang=fr"},
    {"name": "campaign", "path": "/campaign.php?campaign=KIT", "persona": "customer-active"},
    {"name": "post-elsewhere", "method": "POST", "path": "/user.php", "body": "action=list"},
    {"name": "file-by-name", "path": "/scs_file.php?code=/images/a.png"},
    {"name": "cron", "path": "/cron.php"},
    {"name": "probe-key", "path": "/?wp_set_current_user=1", "route": "/", "hits": 9},
    {"name": "probe-route", "path": "/CLAUDE.md", "route": "/CLAUDE.md", "hits": 9},
    {"name": "logged", "path": "/catalog/?action=subcategory&subcategory_id=9", "route": "/catalog/", "hits": 50},
]
ALLOWED_FIELDS = {"name", "method", "path", "persona", "language", "route", "body", "headers", "session"}


class MutateTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.mkdtemp()
        for name, cases in (("p3-staff.jsonl", STAFF), ("other.jsonl", OTHER)):
            with open(os.path.join(self.dir, name), "w") as f:
                f.writelines(json.dumps(c) + "\n" for c in cases)
        with open(os.path.join(self.dir, "personas.json"), "w") as f:
            json.dump(PERSONAS, f)
        db = os.path.join(self.dir, "hx.db")
        conn = sqlite3.connect(db)
        conn.execute("CREATE TABLE subcategory (id INTEGER PRIMARY KEY)")
        conn.executemany("INSERT INTO subcategory VALUES (?)", [(3,), (5,), (9,)])
        conn.commit()
        conn.close()
        self.db = db

    def run_tool(self, out, seed=0, cases=600):
        hx_mutate.main(["--out", out, "--cases", str(cases), "--seed", str(seed), "--shard-size", "100",
                        "--db", self.db, "--personas", os.path.join(self.dir, "personas.json"),
                        os.path.join(self.dir, "p3-staff.jsonl"), os.path.join(self.dir, "other.jsonl")])
        lines = []
        for name in sorted(os.listdir(out)):
            if name.startswith("shard-"):
                with open(os.path.join(out, name)) as f:
                    lines += f.read().splitlines()
        return lines

    def test_cases_are_ordinary_reads(self):
        lines = self.run_tool(os.path.join(self.dir, "a"))
        cases = [json.loads(x) for x in lines]
        self.assertGreater(len(cases), 300)
        self.assertEqual(len({c["name"] for c in cases}), len(cases))
        routes = {c["route"] for c in cases}
        self.assertEqual(routes, {"/user.php", "/catalog.php", "/product/clamping/", "/catalog/", "/campaign.php"})
        for c in cases:
            self.assertLessEqual(set(c), ALLOWED_FIELDS)
            path, _, query = c["path"].partition("?")
            pairs = parse_qsl(query, keep_blank_values=True) + parse_qsl(c.get("body", ""), keep_blank_values=True)
            low = {k.lower(): v.lower() for k, v in pairs}
            self.assertNotIn("..", c["path"])
            self.assertNotIn("'", c["path"])
            for bad in ("delete", "update", "json_code", "cron", "code", "wp_set_current_user", "claude"):
                self.assertNotIn(bad, c["path"].lower() + c.get("body", "").lower(), c)
            self.assertIn(low.get("action", ""), {"", "list", "maintain", "cancel", "subcategory"}, c)
            if c["method"] == "POST":
                self.assertEqual(path.replace("/./", "/").strip("/"), "user.php", c)
            if "subcategory_id" in low and low["subcategory_id"]:
                self.assertIn(low["subcategory_id"], {"3", "5", "9", "0", "10"}, c)
            if c["route"] == "/user.php" and low.get("record_id"):
                self.assertIn(low["record_id"], {"990001", "990013", "0", "990014"}, c)
            if "hxlang" in low:
                self.assertEqual((c["persona"], c.get("session")), ("anonymous", "cold"), c)
            if "campaign" in low:
                self.assertEqual(c.get("session"), "cold", c)
            ref = (c.get("headers") or {}).get("Referer")
            self.assertTrue(ref is None or ref.startswith("https://www.harcourt.co/"), c)
        names = " ".join(c["name"] for c in cases)
        for m in ("persona", "language", "referer", "id", "page", "slash", "dslash", "drop", "repeat"):
            self.assertIn(f"{m}", names)

    def test_same_seed_same_cases(self):
        a = self.run_tool(os.path.join(self.dir, "a"))
        b = self.run_tool(os.path.join(self.dir, "b"))
        c = self.run_tool(os.path.join(self.dir, "c"), seed=1)
        self.assertEqual(a, b)
        self.assertNotEqual(a, c)

    def test_safe_rejects_unusual_shapes(self):
        names = {p["name"] for p in PERSONAS}

        def req(path, **kw):
            return hx_mutate.seed_of(dict({"name": "t", "path": path}, **kw), "x.jsonl")

        self.assertTrue(hx_mutate.safe(req("/catalog/?lang=fr&q=alignment pin"), names))
        self.assertTrue(hx_mutate.safe(req("/./catalog//"), names))
        for path in ("/newuser%2Ephp", "/%c0", "/.env", "/a/../b", "/oauth/callback.php", "/cron.php/x",
                     "/q?s=<b>", "/q?url=https://example.org/", "/q?return_url=//example.org",
                     "/wp-json/wp/v2/users", "/language_change.php?language_code=FR"):
            self.assertFalse(hx_mutate.safe(req(path), names), path)
        self.assertFalse(hx_mutate.safe(req("/", headers={"Referer": "https://example.org/"}), names))
        self.assertFalse(hx_mutate.safe(req("/catalog/?hxlang=fr", persona="customer-active"), names))


if __name__ == "__main__":
    unittest.main()
