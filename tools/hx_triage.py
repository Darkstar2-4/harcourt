#!/usr/bin/env python3
"""Group hx-diff failures by signature: the route, the status pair and the first differing line.

    tools/hx_triage.py [--cases FILE|DIR]... [--top N] [--by-route] OUTPUT...

OUTPUT files are what `hx-diff run` printed. --cases (repeatable) names the corpus files that were run, or a directory
of them, for each case's `route` (without them the route is `?`). Digits in the first differing line become `#`, so one cause seen on
many ids, pages or dates counts once. Prints one line per signature, most frequent first: count, status pair, the
first differing line, then the routes, personas and mutations (the part of a fuzz case's name after the first dot)
it was seen with, and one example case.
"""
import argparse
import glob
import json
import os
import re
import sys
from collections import Counter, defaultdict

HEAD = re.compile(r"^(PASS|FAIL|ERROR) (\S+) (\S+)( \(warm\))?(?:: (.*))?$")
TOTAL = re.compile(r"^\d+ passed, \d+ failed")


def parse(lines):
    """Yield (kind, case, persona, summary, diff lines) for every FAIL and ERROR."""
    cur = None
    for line in lines:
        m = HEAD.match(line)
        if m or TOTAL.match(line):
            if cur:
                yield cur
            cur = None
            if m and m.group(1) != "PASS":
                cur = (m.group(1), m.group(2), m.group(3), m.group(5) or "", [])
        elif cur:
            cur[4].append(line)
    if cur:
        yield cur


def first_change(diff):
    return next((ln for ln in diff if ln[:1] in "-+" and not ln.startswith(("---", "+++"))), "")


def signature(kind, summary, diff):
    if kind == "ERROR":
        return "error", re.sub(r"\d+", "#", summary)[:160]
    m = re.match(r"status (\d+) vs (\d+)", summary)
    status = f"{m.group(1)} vs {m.group(2)}" if m else "same"
    return status, re.sub(r"\d+", "#", first_change(diff).strip())[:160]


def routes_of(paths):
    out = {}
    files = [f for p in paths for f in (sorted(glob.glob(os.path.join(p, "*.jsonl"))) if os.path.isdir(p) else [p])]
    for p in files:
        with open(p) as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith("#"):
                    c = json.loads(line)
                    out[c["name"]] = c.get("route") or c["path"].partition("?")[0]
    return out


def triage(outputs, routes, by_route=False):
    """{(route or '*', status, line): {"n", "routes", "personas", "mutations", "example"}}."""
    groups = defaultdict(lambda: {"n": 0, "routes": Counter(), "personas": Counter(), "mutations": Counter(),
                                  "example": None})
    for text in outputs:
        for kind, case, persona, summary, diff in parse(text.splitlines()):
            route = routes.get(case, "?")
            status, line = signature(kind, summary, diff)
            g = groups[(route if by_route else "*", status, line)]
            g["n"] += 1
            g["routes"][route] += 1
            g["personas"][persona] += 1
            g["mutations"].update(case.partition(".")[2].split("+") if "." in case else [])
            g["example"] = g["example"] or f"{case} {persona}"
    return groups


def top(counter, n=3):
    return ",".join(f"{k}:{v}" for k, v in counter.most_common(n))


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--cases", action="append", default=[])
    ap.add_argument("--top", type=int, default=0, help="print only the N most frequent signatures")
    ap.add_argument("--by-route", action="store_true", help="one signature per route as well")
    ap.add_argument("outputs", nargs="+")
    args = ap.parse_args(argv)
    texts = []
    for p in args.outputs:
        with open(p, errors="replace") as f:
            texts.append(f.read())
    groups = triage(texts, routes_of(args.cases), args.by_route)
    ranked = sorted(groups.items(), key=lambda kv: -kv[1]["n"])
    print(f"{sum(g['n'] for g in groups.values())} failures, {len(groups)} signatures", file=sys.stderr)
    for (route, status, line), g in ranked[:args.top or None]:
        print(f"{g['n']}\t{status}\t{line}\t{route if args.by_route else len(g['routes'])} routes: {top(g['routes'])}"
              f"\t{top(g['personas'])}\t{top(g['mutations'])}\t{g['example']}")


if __name__ == "__main__":
    main()
