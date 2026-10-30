#!/usr/bin/env python3
"""Turn a mysqldump of the Gravity Forms tables into SQLite statements for hxweb's copy of them.

    mysqldump --single-transaction --skip-extended-insert --complete-insert DB \\
        wp_gf_form wp_gf_form_meta wp_gf_entry wp_gf_entry_meta > gf.sql
    tools/gf_dump_to_sqlite.py gf.sql | sqlite3 /var/lib/hxweb/hx.db

The SQLite tables (migration 0330) have the MySQL names and columns, so a row is copied as it is. Every row is
written with INSERT OR REPLACE inside one transaction: running the script again over a newer dump converges on that
dump, and a failure part way leaves the database as it was. Tables in the dump that are not listed in --tables are
skipped. Both mysqldump insert styles (one row per statement, or extended inserts) and dumps with or without column
lists are read.
"""
import argparse
import re
import sys

TABLES = ("wp_gf_form", "wp_gf_form_meta", "wp_gf_entry", "wp_gf_entry_meta")
ESCAPES = {"0": "\0", "b": "\b", "n": "\n", "r": "\r", "t": "\t", "Z": "\x1a"}

INSERT = re.compile(r"INSERT(?: IGNORE)? INTO `([^`]+)`\s*(\(([^)]*)\))?\s*VALUES\s*", re.S)
CREATE = re.compile(r"CREATE TABLE `([^`]+)` \((.*?)\n\)", re.S)


class DumpError(Exception):
    pass


def columns_from_create(text):
    """Column order per table, for dumps written without --complete-insert."""
    out = {}
    for m in CREATE.finditer(text):
        out[m.group(1)] = re.findall(r"^\s*`([^`]+)`", m.group(2), re.M)
    return out


def read_value(s, i):
    """One SQL value starting at s[i]. Returns (python value, index after it)."""
    if s.startswith("NULL", i):
        return None, i + 4
    if s[i] == "'":
        out, i = [], i + 1
        while True:
            j = i
            while j < len(s) and s[j] not in "\\'":
                j += 1
            out.append(s[i:j])
            if j >= len(s):
                raise DumpError("unterminated string")
            if s[j] == "\\":
                c = s[j + 1]
                out.append(ESCAPES.get(c, c))
                i = j + 2
            elif s.startswith("''", j):
                out.append("'")
                i = j + 2
            else:
                return "".join(out), j + 1
    m = re.compile(r"-?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?").match(s, i)
    if m:
        text = m.group(0)
        return (float(text) if any(c in text for c in ".eE") else int(text)), m.end()
    if s.startswith("0x", i):
        m = re.compile(r"0x([0-9A-Fa-f]*)").match(s, i)
        return bytes.fromhex(m.group(1)), m.end()
    raise DumpError(f"cannot read a value at {s[i:i + 30]!r}")


def read_rows(s, i):
    """The `(...),(...);` list after VALUES. Returns (rows, index after the semicolon)."""
    rows = []
    while True:
        while s[i].isspace():
            i += 1
        if s[i] != "(":
            raise DumpError(f"expected '(' at {s[i:i + 30]!r}")
        i += 1
        row = []
        while True:
            while s[i].isspace():
                i += 1
            value, i = read_value(s, i)
            row.append(value)
            while s[i].isspace():
                i += 1
            if s[i] == ",":
                i += 1
                continue
            if s[i] == ")":
                i += 1
                break
            raise DumpError(f"expected ',' or ')' at {s[i:i + 30]!r}")
        rows.append(row)
        while s[i].isspace():
            i += 1
        if s[i] == ",":
            i += 1
            continue
        if s[i] == ";":
            return rows, i + 1
        raise DumpError(f"expected ',' or ';' at {s[i:i + 30]!r}")


def parse_dump(text, wanted):
    """Yields (table, columns, row) for every row of a wanted table, in dump order."""
    declared = columns_from_create(text)
    pos = 0
    while True:
        m = INSERT.search(text, pos)
        if not m:
            return
        table = m.group(1)
        columns = [c.strip().strip("`") for c in m.group(3).split(",")] if m.group(2) else declared.get(table)
        rows, pos = read_rows(text, m.end())
        if table not in wanted:
            continue
        if not columns:
            raise DumpError(f"{table}: no column list and no CREATE TABLE in the dump")
        for row in rows:
            if len(row) != len(columns):
                raise DumpError(f"{table}: a row has {len(row)} values for {len(columns)} columns")
            yield table, columns, row


def literal(value):
    if value is None:
        return "NULL"
    if isinstance(value, bytes):
        return "X'" + value.hex() + "'"
    if isinstance(value, (int, float)):
        return repr(value)
    if "\0" in value:
        raise DumpError("a text value holds a NUL byte, which SQLite text literals cannot carry")
    return "'" + value.replace("'", "''") + "'"


def to_sqlite(text, wanted=TABLES):
    out = ["BEGIN;"]
    counts = {t: 0 for t in wanted}
    for table, columns, row in parse_dump(text, set(wanted)):
        cols = ", ".join(f'"{c}"' for c in columns)
        vals = ", ".join(literal(v) for v in row)
        out.append(f'INSERT OR REPLACE INTO "{table}" ({cols}) VALUES ({vals});')
        counts[table] += 1
    out.append("COMMIT;")
    return "\n".join(out) + "\n", counts


def main(argv=None):
    p = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    p.add_argument("dump", nargs="?", help="mysqldump output (default: stdin)")
    p.add_argument("--tables", nargs="+", default=list(TABLES), choices=TABLES)
    args = p.parse_args(argv)
    text = open(args.dump, encoding="utf-8").read() if args.dump else sys.stdin.read()
    try:
        sql, counts = to_sqlite(text, args.tables)
    except DumpError as e:
        sys.exit(f"gf_dump_to_sqlite: {e}")
    sys.stdout.write(sql)
    print(" ".join(f"{t}={n}" for t, n in counts.items()), file=sys.stderr)


if __name__ == "__main__":
    main()
