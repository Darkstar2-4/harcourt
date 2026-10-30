#!/usr/bin/env python3
"""Which PHP files does the internet ask Apache to run, and how many of them exist?

Reads Apache access logs (plain or .gz, combined format with a leading vhost:port field, as on the production server)
and the list of tracked files, and prints aggregate counts. Client addresses are counted, never printed.

    git ls-files > tracked.txt
    tools/php_route_inventory.py --tracked tracked.txt /var/log/apache2/other_vhosts_access.log*

Output is tab-separated, one row per requested .php path: path, requests, distinct clients, 2xx, 3xx, 4xx, 5xx,
tracked (yes/no). A summary goes to stderr.
"""
import argparse
import gzip
import re
import sys
from collections import Counter, defaultdict
from urllib.parse import unquote

LINE = re.compile(r'^\S+ (\S+) \S+ \S+ \[[^\]]+\] "(\S+) (\S+)[^"]*" (\d{3}) \S+ "[^"]*" "([^"]*)"')
HEALTH_CHECK = "Route53-Health-Check"
WEBSHELL_ARGS = ("allow_url_include", "auto_prepend_file")


def open_log(path):
    return gzip.open(path, "rt", errors="replace") if path.endswith(".gz") else open(path, errors="replace")


def php_path(url):
    path = re.sub(r"/{2,}", "/", unquote(url.partition("?")[0]))
    return path if path.endswith(".php") else None


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--tracked", required=True, help="file with one tracked path per line (git ls-files)")
    ap.add_argument("logs", nargs="+")
    args = ap.parse_args()

    tracked = {"/" + line.strip() for line in open(args.tracked, errors="replace") if line.strip().endswith(".php")}
    clients = defaultdict(set)
    status = defaultdict(Counter)
    requests = Counter()
    total = 0
    webshell_args = 0
    for log in args.logs:
        for line in open_log(log):
            m = LINE.match(line)
            if not m or HEALTH_CHECK in m.group(5):
                continue
            total += 1
            client, _method, url, code, _ua = m.groups()
            path = php_path(url)
            if path is None:
                continue
            requests[path] += 1
            clients[path].add(client)
            status[path][code[0] + "xx"] += 1
            if any(a in url for a in WEBSHELL_ARGS):
                webshell_args += 1

    print("path\trequests\tclients\t2xx\t3xx\t4xx\t5xx\ttracked")
    for path, n in requests.most_common():
        s = status[path]
        print(f"{path}\t{n}\t{len(clients[path])}\t{s['2xx']}\t{s['3xx']}\t{s['4xx']}\t{s['5xx']}\t{'yes' if path in tracked else 'no'}")

    php_total = sum(requests.values())
    absent = {p: n for p, n in requests.items() if p not in tracked}
    wp = sum(n for p, n in requests.items() if p.startswith(("/wp-", "/xmlrpc.php")))
    print(f"requests reaching Apache (health checks excluded)\t{total}", file=sys.stderr)
    print(f"requests for a .php path\t{php_total}", file=sys.stderr)
    print(f"distinct .php paths requested\t{len(requests)}", file=sys.stderr)
    print(f"distinct .php paths that are not tracked files\t{len(absent)}", file=sys.stderr)
    print(f"requests for .php paths that are not tracked files\t{sum(absent.values())}", file=sys.stderr)
    print(f"requests for WordPress entry points (wp-*, xmlrpc.php)\t{wp}", file=sys.stderr)
    print(f"requests carrying php-cgi argument-injection options in the URL\t{webshell_args}", file=sys.stderr)


if __name__ == "__main__":
    main()
