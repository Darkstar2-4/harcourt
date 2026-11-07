#!/usr/bin/env python3
"""Structure-aware mutations of ordinary hx-diff cases, for differential runs at scale.

    tools/hx_mutate.py --out DIR --personas personas.json [--cases N] [--seed S] [--shard-size N]
                       [--routes routes.tsv] [--db hx.db] [--known FILE]... SEEDS...

SEEDS are hx-diff corpus files or directories of them: the visible corpora (rust/oracle/*.jsonl) and the corpus built
from the access logs. Each new case is a seed request with one to three functional mutations: a query (or form)
parameter dropped, emptied, duplicated, repeated with another value seen for it, reordered or case-flipped; a numeric
id swapped for another id of its table or one just outside it; a page number at, below or above its bounds; a sort
order; a trailing slash, `//` or `/./` in the path; the other language; another persona; a same-site Referer added or
dropped. --cases is spread over routes by traffic (the `requests` column of --routes), with a floor per route.

Only ordinary requests go in or come out. A seed or a mutation is dropped unless every part of it looks like normal
traffic (plain path characters, short values, no markup, quotes, dot-dot or scheme), and it never touches a request
that could change data or identity: no page outside the allow rules below, no action value outside a read-only
list, no POST but the read-only list and edit-form posts of the staff corpus, ids of accounts only among the
personas. Cases that set the visit's campaign or language flag run cold, so they never alter a shared warm session.

Writes DIR/shard-NNN.jsonl (shuffled cases, `route` names the seed's route) and DIR/routes.tsv (route, weight,
seeds, cases).
"""
import argparse
import glob
import json
import os
import random
import re
import sqlite3
import sys
from dataclasses import dataclass, replace
from urllib.parse import parse_qsl, quote, unquote, urlencode

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from hx_corpus import kind, route_of  # noqa: E402

SITE = "https://www.harcourt.co"
ASSET_SHARE = 0.25
FLOOR = 10
UNLISTED_WEIGHT = 20

# Root-level PHP pages whose GET writes, mails, runs jobs, changes who the visitor is or exposes a console.
DENY_PHP = re.compile(
    r"^/(?:language_change|cookie_reset|cookie|user_impersonate|bot_impersonate|captcha_bypass|blacklist|"
    r"remote_access|remote_code|remote_token|stock|scs_api|scs_mysql_remote|scs_mysql|mysql_rev1|query_test|phpinfo|"
    r"test|x|scs_pdo|cron|migrate|partcommunity_import|image_filmstrip|cad_toolbox|search_utility|search_export|"
    r"inventory_increase|inventory_utility|user_utility|subcategory_utility|convert_\w+|scs_mail\w*|mailer_\w+|"
    r"scs_track_log|scs_log|user_email|scs_filemanager|scs_registry|scs_php\w*|\w*toolbox|scs_inc|scs_map|"
    r"legacy_header|scs_header|scs_version|punchout|wp-[\w-]+|xmlrpc)\.php(?:/|$)")
DENY_PATH = re.compile(r"^/(?:wp-admin|wp-includes/.*\.php|\.|[^/]+/.*\.php)|^/wp-json(?!/oembed/1\.0/embed/?$)|"
                       r"^/(?:fr/)?imperson|sitemap-users")
# Requests prod's nginx answers before hxweb (the form islands, the search context): the oracle's answer says
# nothing about Rust's, so they make no seeds.
NGINX_KEYS = {"hx_form"}
NGINX_PAGES = {"/hx_search_ctx.php"}
ACTION_KEYS = {"action", "mode", "op", "do", "task", "cmd", "function", "method", "step"}
READ_ACTIONS = {"", "subcategory", "sku", "search", "list", "maintain", "cancel", "prelist", "view", "show",
                "overview", "features", "applications", "price", "specials", "catalog", "products", "other",
                "nothing", "download", "x", "explain", "review"}
POST_ALLOW = {
    "/user.php": {"action": {"list", "cancel", "maintain"}},
    "/pricing_utility.php": {"action": {"prelist"}, "function": {"explain", "review", "download"}},
}
POST_SOURCE = "p3-staff.jsonl"
# Pages that act on other parameters than the ones listed (a file by name, a download log entry).
ROUTE_KEYS = {"/scs_file.php": {"content_id", "width", "height", "resize"}}
DENY_KEY = re.compile(r"(?i)^(?:author|rest_route|json_\w+|api_?key|e?mail|delete|remove|logout|overview_\w+|"
                      r"local_log|.*(?:token|pass|pwd|secret|nonce|hash|sig|sess).*)$")
# Parameter names the site's pages, WordPress and campaign links use. The logs also carry scanners' plugin and
# account probes on ordinary-looking URLs; a name outside this list makes a seed unusable.
KEY_OK = re.compile(
    r"(?i)^(?:scscpq_wp|action|subcategory_id|category_id|search_(?:code|id|source|complete)|scs_page|sku|term|table|"
    r"limit|unit_type|lang|hxlang|language_code|content_id|width|height|resize|record_(?:id|item|type)|return_url|"
    r"login_url|document|prj|tab|campaign|page|q|s|report_(?:type|status|option_field|option_value)|ajax_user_id|"
    r"function|file_type|portal_id|industry_id|document_id|hx_(?:form|page|lang)|detail|source|cad|parameter\d{0,2}|"
    r"x|y|a|m|p|page_id|cat|feed|paged|post_type|format|replytocom|url|maxheight|maxwidth|_fields|per_page|order|"
    r"orderby|ver|v|theme|utm_(?:source|medium|campaign|term|content)|gclid|fbclid|ref|_)$")
VALUE_OK = re.compile(r"^[\w .,:/+=\-]{0,120}$")
TAB_OK = re.compile(r"^[\w .,:/+=\-()]{0,120}$")
PATH_OK = re.compile(r"^/(?:[\w.\-~+/]|%[89a-fA-F][0-9a-fA-F])*$")
# The access logs also hold scanners' guesses that a catch-all answered (/credentials, /api-keys, plugin readmes). A
# logged route is a seed only when it is one of the site's own: a root PHP page, a sitemap, a static file under the
# directories the site serves, or a page path the visible corpora or --known (sitemaps, the export manifest) name.
STATIC_ROUTE = re.compile(r"^/(?:[a-z0-9_\-]+\.php|[a-z0-9_\-]*sitemap[a-z0-9_\-]*\.xml|robots\.txt|favicon\.ico|"
                          r"apple-touch-icon[\w\-]*\.png|wp-json/oembed/1\.0/embed|(?:wp-content|wp-includes|hx-vendor|"
                          r"hx|scs_scripts|images_subcategory|scs_images|overview_documents)/[\w\-./{}]+\.[A-Za-z0-9]{1,5})$")
ARCHIVE_TAIL = re.compile(r"(?:/(?:feed(?:/(?:atom|rss2?|rdf))?|embed|amp|page/(?:\d+|\{n\})))*/?$")
BAD = re.compile(r"(?i)\.\.|<|>|script|select\s|union\s|sleep\s*\(|benchmark|phpinfo|passwd|/etc/|php:|file:|"
                 r"data:|allow_url|prepend|wget\s|curl\s|@")
FROZEN = {"table", "source", "detail", "url", "return_url", "login_url", "document", "content_id", "code", "sku",
          "function"}
ID_TABLE = {"subcategory_id": "subcategory", "category_id": "category", "search_id": "search", "portal_id": "portal",
            "industry_id": "industry", "document_id": "document"}
RECORD_TABLE = {"/orderhd.php": "orderhd", "/bot.php": "bot"}
USER_KEYS = {"user_id", "ajax_user_id"}
PAGE_KEYS = {"page", "scs_page", "paged", "pg"}
PAGE_VALUES = ["0", "1", "-1", "2", "999", "99999"]
SORT_VALUES = {"order": ["asc", "desc", "ASC", "DESC"], "dir": ["asc", "desc"], "sort_order": ["asc", "desc"],
               "orderby": ["date", "title", "modified", "menu_order", "relevance"], "sort": ["date", "title", "name"]}
COLD_KEYS = {"campaign", "hxlang"}


@dataclass(frozen=True)
class Req:
    method: str
    path: str
    query: tuple
    body: tuple
    persona: str
    language: str
    headers: tuple
    session: object
    route: str
    post_ok: bool = False

    def pairs(self):
        return self.query + self.body

    def keys(self):
        return {k.lower() for k, _ in self.pairs()}

    def referer(self):
        return next((v for n, v in self.headers if n.lower() == "referer"), None)

    def case(self, name):
        c = {"name": name, "method": self.method, "path": self.url(), "persona": self.persona,
             "language": self.language, "route": self.route}
        if self.method == "POST":
            c["body"] = encode(self.body)
        if self.headers:
            c["headers"] = dict(self.headers)
        if self.session:
            c["session"] = self.session
        return c

    def url(self):
        return self.path + ("?" + encode(self.query) if self.query else "")


def encode(pairs):
    return urlencode(pairs, quote_via=quote, safe="/:,")


def seed_of(c, source):
    path, _, query = c["path"].partition("?")
    method = c.get("method", "GET").upper()
    body = ()
    if method == "POST":
        if c.get("content_type") not in (None, "application/x-www-form-urlencoded"):
            return None
        body = tuple(parse_qsl(c.get("body") or "", keep_blank_values=True))
    return Req(method, path, tuple(parse_qsl(query, keep_blank_values=True)), body, c.get("persona", "anonymous"),
               c.get("language", "en"), tuple(sorted((c.get("headers") or {}).items())), c.get("session"),
               c.get("route") or route_of(path), post_ok=method == "POST" and os.path.basename(source) == POST_SOURCE)


def page_of(path):
    """The path a server resolves: empty and dot segments gone, lowercased for matching."""
    return re.sub(r"/{2,}", "/", re.sub(r"(?:/\.)+(?=/|$)", "", path)).lower() or "/"


def value_ok(k, v):
    if not (TAB_OK if k.lower() == "tab" else VALUE_OK).match(v) or BAD.search(v) or v.startswith("//"):
        return False
    return "://" not in v or v.startswith(SITE + "/")


def safe(r, personas):
    """True when the request is ordinary traffic that reads and never writes."""
    if r.method not in ("GET", "HEAD", "POST") or r.persona not in personas or r.language not in ("en", "fr"):
        return False
    try:
        decoded = unquote(r.path, errors="strict")
    except UnicodeDecodeError:
        return False
    if not PATH_OK.match(r.path) or BAD.search(decoded) or any(s.startswith(".") and s != "." for s in r.path.split("/")):
        return False
    page = page_of(r.path)
    if DENY_PHP.search(page) or DENY_PATH.search(page):
        return False
    for k, v in r.pairs():
        if not KEY_OK.match(k) or DENY_KEY.match(k) or not value_ok(k, v):
            return False
        if k.lower() in ACTION_KEYS and v.lower() not in READ_ACTIONS:
            return False
    if page.rstrip("/") == "/scs_ajax.php" and dict((k.lower(), v) for k, v in r.query).get("table") != "search":
        return False
    if page.rstrip("/") in ROUTE_KEYS and not r.keys() <= ROUTE_KEYS[page.rstrip("/")]:
        return False
    if r.method == "POST":
        rules = POST_ALLOW.get(page.rstrip("/"))
        if not r.post_ok or rules is None or r.query and {k.lower() for k, _ in r.query} != {"scscpq_wp"}:
            return False
        for k, v in r.body:
            if k.lower() in rules and v.lower() not in rules[k.lower()]:
                return False
    if "hxlang" in r.keys() and r.persona != "anonymous":
        return False
    ref = r.referer()
    return ref is None or (ref == SITE or ref.startswith(SITE + "/")) and not BAD.search(ref)


def settle(r):
    """Cases that set the visit's campaign or flag a language choice run cold."""
    return replace(r, session="cold") if r.keys() & COLD_KEYS else r


class Ctx:
    def __init__(self, personas, ids, seeds):
        self.personas = [p["name"] for p in personas]
        self.persona_ids = sorted(p["account"]["id"] for p in personas if p.get("account"))
        self.ids = ids
        self.pool = {}
        for s in seeds:
            for k, v in s.pairs():
                self.pool.setdefault((s.route, k.lower()), set()).add(v)
        self.pool = {k: sorted(v) for k, v in self.pool.items()}

    def id_pool(self, r, key):
        k = key.lower()
        if k in USER_KEYS or (k == "record_id" and page_of(r.path).rstrip("/") == "/user.php"):
            return self.persona_ids, [0, max(self.persona_ids) + 1]
        table = ID_TABLE.get(k) or (RECORD_TABLE.get(page_of(r.path).rstrip("/")) if k == "record_id" else None)
        if table and self.ids.get(table):
            ids = self.ids[table]
            return ids, [0, max(ids) + 1]
        return None


def numeric(v):
    return re.fullmatch(r"-?\d{1,9}", v) is not None


def target(r, rng):
    """Mutate the form body of a POST most of the time, else the query."""
    if r.body and (not r.query or rng.random() < 0.7):
        return "body", list(r.body)
    return "query", list(r.query)


def with_pairs(r, where, pairs):
    return replace(r, **{where: tuple(pairs)})


def m_drop(r, ctx, rng):
    where, p = target(r, rng)
    if not p:
        return None
    del p[rng.randrange(len(p))]
    return with_pairs(r, where, p)


def m_empty(r, ctx, rng):
    where, p = target(r, rng)
    idx = [i for i, (_, v) in enumerate(p) if v]
    if not idx:
        return None
    i = rng.choice(idx)
    p[i] = (p[i][0], "")
    return with_pairs(r, where, p)


def m_duplicate(r, ctx, rng):
    where, p = target(r, rng)
    if not p:
        return None
    i = rng.randrange(len(p))
    p.insert(rng.choice([i + 1, len(p)]), p[i])
    return with_pairs(r, where, p)


def m_repeat(r, ctx, rng):
    where, p = target(r, rng)
    if not p:
        return None
    k, v = p[rng.randrange(len(p))]
    others = [x for x in ctx.pool.get((r.route, k.lower()), []) if x != v]
    ids = ctx.id_pool(r, k)
    if ids and numeric(v):
        others += [str(x) for x in rng.sample(ids[0], min(3, len(ids[0])))]
    if not others:
        return None
    p.append((k, rng.choice(others)))
    return with_pairs(r, where, p)


def m_reorder(r, ctx, rng):
    where, p = target(r, rng)
    if len(set(p)) < 2:
        return None
    q = p[:]
    while q == p:
        rng.shuffle(q)
    return with_pairs(r, where, q)


def flip(s, rng):
    out = rng.choice([s.upper(), s.capitalize(), s.swapcase(), s.lower()])
    return out if out != s else s.swapcase()


def m_case_key(r, ctx, rng):
    where, p = target(r, rng)
    idx = [i for i, (k, _) in enumerate(p) if re.search("[A-Za-z]", k)]
    if not idx:
        return None
    i = rng.choice(idx)
    p[i] = (flip(p[i][0], rng), p[i][1])
    return with_pairs(r, where, p)


def m_case_value(r, ctx, rng):
    where, p = target(r, rng)
    idx = [i for i, (k, v) in enumerate(p) if re.search("[A-Za-z]", v) and k.lower() not in FROZEN]
    if not idx:
        return None
    i = rng.choice(idx)
    p[i] = (p[i][0], flip(p[i][1], rng))
    return with_pairs(r, where, p)


def m_swap_id(r, ctx, rng):
    where, p = target(r, rng)
    idx = [(i, pool) for i, (k, v) in enumerate(p) if numeric(v) and (pool := ctx.id_pool(r, k))]
    if not idx:
        return None
    i, (valid, outside) = rng.choice(idx)
    v = rng.choice(outside) if rng.random() < 0.25 else rng.choice(valid)
    if str(v) == p[i][1]:
        return None
    p[i] = (p[i][0], str(v))
    return with_pairs(r, where, p)


def m_page(r, ctx, rng):
    where, p = target(r, rng)
    idx = [i for i, (k, _) in enumerate(p) if k.lower() in PAGE_KEYS]
    if idx:
        i = rng.choice(idx)
        p[i] = (p[i][0], rng.choice([v for v in PAGE_VALUES if v != p[i][1]]))
        return with_pairs(r, where, p)
    m = re.search(r"/page/(\d+)(/?)$", r.path)
    if m:
        n = rng.choice([v for v in PAGE_VALUES if v != m.group(1) and not v.startswith("-")])
        return replace(r, path=r.path[:m.start(1)] + n + m.group(2))
    return None


def m_sort(r, ctx, rng):
    p = list(r.query)
    idx = [i for i, (k, _) in enumerate(p) if k.lower() in SORT_VALUES]
    if idx:
        i = rng.choice(idx)
        k, v = p[i]
        p[i] = (k, rng.choice([x for x in SORT_VALUES[k.lower()] if x != v]))
    else:
        k = rng.choice(["order", "orderby"])
        p.append((k, rng.choice(SORT_VALUES[k])))
    return replace(r, query=tuple(p))


def m_language(r, ctx, rng):
    other = "fr" if r.language == "en" else "en"
    langs = [i for i, (k, _) in enumerate(r.query) if k.lower() == "lang"]
    how = rng.choice(["field", "both", "param"] if langs else ["field", "field", "add"])
    p = list(r.query)
    if how in ("both", "param"):
        for i in langs:
            p[i] = (p[i][0], "fr" if p[i][1].lower() == "en" else "en")
    if how == "add":
        p.append(("lang", other))
    return replace(r, query=tuple(p), language=other if how != "param" else r.language)


def m_persona(r, ctx, rng):
    return replace(r, persona=rng.choice([p for p in ctx.personas if p != r.persona]))


def m_referer(r, ctx, rng):
    if r.referer() is not None:
        return replace(r, headers=tuple((n, v) for n, v in r.headers if n.lower() != "referer"))
    where = rng.choice([r.path, "/", "/catalog/", "/request-access/", "/catalogue/?lang=fr"])
    return replace(r, headers=tuple(sorted(r.headers + (("Referer", SITE + where),))))


def m_trailing_slash(r, ctx, rng):
    if r.path == "/":
        return None
    return replace(r, path=r.path[:-1] if r.path.endswith("/") else r.path + "/")


def slash_at(path, rng):
    return rng.choice([i for i, c in enumerate(path) if c == "/"])


def m_double_slash(r, ctx, rng):
    i = slash_at(r.path, rng)
    return replace(r, path=r.path[:i] + "/" + r.path[i:])


def m_dot_segment(r, ctx, rng):
    i = slash_at(r.path, rng)
    return replace(r, path=r.path[:i] + "/." + r.path[i:])


MUTATORS = {
    "persona": (m_persona, 6), "language": (m_language, 3), "referer": (m_referer, 2), "drop": (m_drop, 3),
    "empty": (m_empty, 2), "dup": (m_duplicate, 1), "repeat": (m_repeat, 2), "reorder": (m_reorder, 1),
    "casekey": (m_case_key, 1), "caseval": (m_case_value, 1), "id": (m_swap_id, 4), "page": (m_page, 3),
    "sort": (m_sort, 1), "slash": (m_trailing_slash, 1), "dslash": (m_double_slash, 1), "dot": (m_dot_segment, 0.5),
}


def mutate(seed, ctx, rng, personas):
    """One case from `seed`: one to three mutations, each kept only if the result is still safe."""
    names = list(MUTATORS)
    weights = [MUTATORS[n][1] for n in names]
    r, done = seed, []
    for _ in range(rng.choices([1, 2, 3], [0.5, 0.35, 0.15])[0]):
        for _ in range(4):
            name = rng.choices(names, weights)[0]
            out = MUTATORS[name][0](r, ctx, rng)
            if out is not None and out != r and safe(out, personas):
                r = out
                done.append(name)
                break
    return (settle(r), done) if done else (None, done)


def page_key(route):
    return ARCHIVE_TAIL.sub("", route.partition("?")[0].lower())


def load_seeds(paths, known=()):
    """Seeds from corpus files or directories: every case of a written corpus, and the logged cases (those with
    `hits`) whose route is the site's own."""
    files = []
    for p in paths:
        files += sorted(glob.glob(os.path.join(p, "**", "*.jsonl"), recursive=True)) if os.path.isdir(p) else [p]
    written, logged = [], []
    for f in files:
        with open(f) as fh:
            for line in fh:
                line = line.strip()
                if line and not line.startswith("#"):
                    c = json.loads(line)
                    s = seed_of(c, f)
                    if s is not None:
                        (logged if "hits" in c else written).append(s)
    pages = {page_key(k) for k in known} | {page_key(s.route) for s in written}
    return written + [s for s in logged if STATIC_ROUTE.match(s.route) or page_key(s.route) in pages]


def load_known(paths):
    out = []
    for p in paths:
        with open(p) as f:
            out += [line.strip().replace(SITE, "") or "/" for line in f if line.strip()]
    return out


def load_weights(path):
    w = {}
    if path:
        with open(path) as f:
            next(f)
            for line in f:
                route, k, requests = line.rstrip("\n").split("\t")[:3]
                w[route] = int(requests) * (ASSET_SHARE if k == "asset" else 1)
    return w


def load_ids(db, per_table=400, seed=0):
    """Ids per table from a read-only copy of the site database: a random sample, plus the smallest and largest."""
    if not db:
        return {}
    conn = sqlite3.connect(f"file:{db}?mode=ro", uri=True)
    rng, out = random.Random(seed), {}
    present = {t for (t,) in conn.execute("SELECT name FROM sqlite_master WHERE type = 'table'")}
    for table in sorted((set(ID_TABLE.values()) | set(RECORD_TABLE.values())) & present):
        ids = [i for (i,) in conn.execute(f'SELECT id FROM "{table}" ORDER BY id')]
        if ids:
            out[table] = sorted(set(rng.sample(ids, min(per_table, len(ids))) + [ids[0], ids[-1]]))
    conn.close()
    return out


def allocate(by_route, weights, total):
    w = {r: weights.get(r, UNLISTED_WEIGHT * (ASSET_SHARE if kind(r) == "asset" else 1)) for r in by_route}
    rest = max(0, total - FLOOR * len(w))
    whole = sum(w.values()) or 1
    return {r: FLOOR + round(rest * x / whole) for r, x in w.items()}, w


def generate(seeds, ctx, weights, total, seed=0):
    """(case dicts, {route: (weight, seeds, cases)}) from the safe seeds; deterministic for a given seed."""
    personas = set(ctx.personas)
    by_route = {}
    for s in seeds:
        by_route.setdefault(s.route, []).append(s)
    budget, weights = allocate(by_route, weights, total)
    rng = random.Random(seed)
    out, seen, stats = [], set(), {}
    for route in sorted(by_route):
        pool, made = by_route[route], 0
        for _ in range(budget[route] * 20):
            if made >= budget[route]:
                break
            r, done = mutate(rng.choice(pool), ctx, rng, personas)
            if r is None or r in seen or r in pool:
                continue
            seen.add(r)
            out.append(r.case(f"fz{len(out):06d}.{'+'.join(done)}"))
            made += 1
        stats[route] = (weights[route], len(pool), made)
    rng.shuffle(out)
    return out, stats


def write(out_dir, cases, stats, shard_size):
    os.makedirs(out_dir, exist_ok=True)
    for old in glob.glob(os.path.join(out_dir, "shard-*.jsonl")):
        os.remove(old)
    for n, i in enumerate(range(0, len(cases), shard_size)):
        with open(os.path.join(out_dir, f"shard-{n:03d}.jsonl"), "w") as f:
            f.writelines(json.dumps(c, ensure_ascii=False) + "\n" for c in cases[i:i + shard_size])
    with open(os.path.join(out_dir, "routes.tsv"), "w") as f:
        f.write("route\tweight\tseeds\tcases\n")
        for route, (w, s, c) in sorted(stats.items(), key=lambda kv: -kv[1][2]):
            f.write(f"{route}\t{w:g}\t{s}\t{c}\n")


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--out", required=True)
    ap.add_argument("--cases", type=int, default=110_000)
    ap.add_argument("--seed", type=int, default=0)
    ap.add_argument("--shard-size", type=int, default=2500)
    ap.add_argument("--routes", help="routes.tsv of the access-log corpus, for traffic weights")
    ap.add_argument("--db", help="the imported SQLite copy, read only, for valid ids")
    ap.add_argument("--personas", required=True)
    ap.add_argument("--known", action="append", default=[],
                    help="file of the site's page paths or URLs, one per line (repeatable)")
    ap.add_argument("seeds", nargs="+")
    args = ap.parse_args(argv)
    with open(args.personas) as f:
        personas = json.load(f)
    seeds = load_seeds(args.seeds, load_known(args.known))
    names = {p["name"] for p in personas}
    ok = [s for s in seeds if safe(s, names) and not (s.keys() & NGINX_KEYS or page_of(s.path).rstrip("/") in NGINX_PAGES)]
    ctx = Ctx(personas, load_ids(args.db, seed=args.seed), ok)
    cases, stats = generate(ok, ctx, load_weights(args.routes), args.cases, args.seed)
    write(args.out, cases, stats, args.shard_size)
    print(f"{len(seeds)} seeds, {len(ok)} safe, {sum(1 for s in stats.values() if s[2])} routes, {len(cases)} cases "
          f"in {args.out}", file=sys.stderr)


if __name__ == "__main__":
    main()
