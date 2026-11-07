#!/usr/bin/env python3
"""Turn web server access logs into hx-diff cases: paths and query strings only, grouped by route.

    tools/hx_corpus.py --out DIR /var/log/apache2/other_vhosts_access.log* /var/log/nginx/access.log*

Reads combined-format logs, plain or .gz, with or without Apache's leading vhost:port field. Keeps GET and HEAD
requests that the site answered below 400, so scanner probes for files that do not exist stay out. A route is a path
with numeric segments folded; within a route, each distinct set of query keys keeps its most requested examples.

Nothing that identifies a visitor is written: client addresses, user agents and referrers are never read into the
output, and query values that could be credentials or personal data (emails, tokens, codes, long random strings,
addresses) become `x`. Writes DIR/pages/<route>.jsonl for pages (one case per line, persona anonymous, language from
the path or a `lang` parameter), DIR/assets.jsonl for static files, and DIR/routes.tsv (route, kind, requests, cases,
file). With --docroot, .php routes whose file no longer exists are dropped. Requests whose query names a changing
action (delete, clear, save, ...) or carries an api_key are never cases, and --exclude FILE drops every URL matching
any regular expression in FILE (one per line), for entry points that must not be replayed.
"""
import argparse
import gzip
import json
import os
import re
import sys
from collections import Counter, defaultdict
from urllib.parse import parse_qsl, unquote, urlencode

LINE = re.compile(r'^(?:\S+ )?\S+ \S+ \S+ \[[^\]]+\] "(GET|HEAD) (\S+) HTTP/[\d.]+" (\d{3}) ')
SECRET_KEYS = re.compile(
    r"(?i)^(.*(token|code|key|pass|pwd|secret|auth|sess|sid|nonce|hash|sig|state|email|mail|login|user|access|"
    r"remote|phone|name|address|ip)s?.*)$"
)
SECRET_VALUE = re.compile(r"(?i)@|^[A-Za-z0-9_\-+/=]{24,}$|^\d{1,3}(\.\d{1,3}){3}$|^[0-9a-f:]{6,}:[0-9a-f:]+$")
HOSTILE = re.compile(r"(?i)allow_url_include|auto_prepend_file|\.\./|%2e%2e|<script|union.+select|/etc/passwd|"
                     r"/\.(?!well-known/)|/@fs/")
PER_SIGNATURE = 3
MUTATING = re.compile(r"(?i)(?:^|&)(?:action|mode|op|do|task|clear)=(?:[^&]*(?:delete|remove|clear|purge|drop|update|"
                      r"save|insert|add|approve|deny|send|email|reset|import|grant|revoke|login|logout|access|1\b))|"
                      r"(?:^|&)api_key=")
ASSET = re.compile(r"(?i)\.(png|jpe?g|gif|svg|webp|avif|ico|css|js|mjs|woff2?|ttf|otf|eot|map|pdf|mp4|webm|zip|"
                   r"step|stp|igs|iges|dwg|dxf|stl|txt|xml)$")


def open_log(path):
    return gzip.open(path, "rt", errors="replace") if path.endswith(".gz") else open(path, errors="replace")


def route_of(path):
    path = re.sub(r"/{2,}", "/", path)
    return re.sub(r"/\d+(?=/|$)", "/{n}", path)


def slug(route):
    s = re.sub(r"[^A-Za-z0-9]+", "-", route).strip("-")
    return s or "root"


def clean_query(query):
    pairs = []
    for k, v in parse_qsl(query, keep_blank_values=True):
        if SECRET_KEYS.match(k) and k.lower() not in {"lang", "language_code", "scscpq_wp"} or SECRET_VALUE.search(v):
            v = "x" if v else ""
        pairs.append((k, v))
    return urlencode(pairs, safe="/:,")


def language(path, query):
    if path.startswith("/fr/") or path == "/fr" or "catalogue" in path:
        return "fr"
    return "fr" if re.search(r"(?:^|&)lang(?:uage_code)?=fr", query, re.I) else "en"


def read(paths, exclude=()):
    """Counter of (method, path, cleaned query) for answered GET/HEAD requests."""
    seen = Counter()
    for p in paths:
        with open_log(p) as f:
            for line in f:
                m = LINE.match(line)
                if not m or int(m.group(3)) >= 400 or "Route53-Health-Check" in line:
                    continue
                method, url = m.group(1), m.group(2)
                if HOSTILE.search(unquote(url)) or not url.startswith("/"):
                    continue
                path, _, query = url.partition("?")
                if MUTATING.search(unquote(query)) or any(x.search(unquote(url)) for x in exclude):
                    continue
                seen[(method, path, clean_query(query))] += 1
    return seen


def build(seen):
    """{route: (requests, [case dicts])}, cases most requested first, PER_SIGNATURE per set of query keys."""
    by_route = defaultdict(lambda: defaultdict(Counter))
    totals = Counter()
    for (method, path, query), n in seen.items():
        r = route_of(path)
        keys = tuple(sorted({k for k, _ in parse_qsl(query, keep_blank_values=True)}))
        by_route[r][keys][(method, path, query)] += n
        totals[r] += n
    out = {}
    for r, sigs in by_route.items():
        cases = []
        for keys, examples in sorted(sigs.items(), key=lambda kv: -sum(kv[1].values())):
            for (method, path, query), n in examples.most_common(PER_SIGNATURE):
                full = path + ("?" + query if query else "")
                cases.append({
                    "name": f"{slug(r)}#{len(cases) + 1}",
                    "method": "GET",
                    "path": full,
                    "persona": "anonymous",
                    "language": language(path, query),
                    "route": r,
                    "hits": n,
                })
        out[r] = (totals[r], cases)
    return out


def kind(route):
    return "asset" if ASSET.search(route) and not route.endswith(("robots.txt", "sitemap.xml")) and \
        "sitemap" not in route else "page"


def write(out_dir, routes, min_hits, exists=None):
    """Files for every route requested at least min_hits times; returns (routes kept, cases written)."""
    os.makedirs(os.path.join(out_dir, "pages"), exist_ok=True)
    kept = sorted(((r, t, c) for r, (t, c) in routes.items()
                   if t >= min_hits and (exists is None or not r.endswith(".php") or exists(r))), key=lambda x: -x[1])
    names, assets, n = set(), [], 0
    with open(os.path.join(out_dir, "routes.tsv"), "w") as idx:
        idx.write("route\tkind\trequests\tcases\tfile\n")
        for r, total, cases in kept:
            k = kind(r)
            if k == "asset":
                assets.extend(cases)
                name = "assets.jsonl"
            else:
                base, i = slug(r), 1
                while (name := f"pages/{base}{'' if i == 1 else f'-{i}'}.jsonl") in names:
                    i += 1
                names.add(name)
                with open(os.path.join(out_dir, name), "w") as f:
                    f.writelines(json.dumps(c, ensure_ascii=False) + "\n" for c in cases)
            n += len(cases)
            idx.write(f"{r}\t{k}\t{total}\t{len(cases)}\t{name}\n")
    with open(os.path.join(out_dir, "assets.jsonl"), "w") as f:
        f.writelines(json.dumps(c, ensure_ascii=False) + "\n" for c in sorted(assets, key=lambda c: -c["hits"]))
    return len(kept), n


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--out", required=True)
    ap.add_argument("--min-hits", type=int, default=2, help="drop routes requested fewer times (default 2)")
    ap.add_argument("--docroot", help="drop .php routes whose file is not under this directory")
    ap.add_argument("--exclude", help="file of regular expressions; matching URLs are dropped")
    ap.add_argument("logs", nargs="+")
    args = ap.parse_args(argv)
    exists = None
    if args.docroot:
        root = os.path.realpath(args.docroot)
        exists = lambda r: os.path.isfile(os.path.join(root, unquote(r).lstrip("/")))  # noqa: E731
    exclude = []
    if args.exclude:
        with open(args.exclude) as f:
            exclude = [re.compile(x.strip()) for x in f if x.strip() and not x.startswith("#")]
    kept, n = write(args.out, build(read(args.logs, exclude)), args.min_hits, exists)
    print(f"{kept} routes, {n} cases in {args.out}", file=sys.stderr)


if __name__ == "__main__":
    main()
