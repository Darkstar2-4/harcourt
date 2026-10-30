#!/bin/bash
# Start your own build of hxweb on hxport for differential testing: a fresh copy of the imported database, migrated
# with your migrations/, the persona sessions seeded, the Gravity Forms definitions loaded and, when hxweb-cms is built
# next to hxweb, the cms content imported from the oracle's WordPress tables. Listens on 127.0.0.1:<port>.
#   cd ~/w/<area>/rust && cargo build --release --bin hxweb && /home/ubuntu/oracle-data/bin/hx-serve.sh <area> <port>
# Then, from another shell: $ORACLE_DATA/bin/hx-diff run --oracle "$ORACLE_FRONT" --rust http://127.0.0.1:<port> ...
# HX_DIFF overrides which hx-diff seeds the copy (default: the published one next to this script).
set -euo pipefail
[ $# -eq 2 ] || { echo "usage: oracle/hx-serve.sh <area> <port>" >&2; exit 2; }
area=$1 port=$2
[[ $area =~ ^[a-z0-9-]+$ ]] || { echo "area: lowercase letters, digits, dashes" >&2; exit 2; }
[[ $port =~ ^[0-9]+$ ]] && [ "$port" -ge 1024 ] && [ "$port" -le 65535 ] || { echo "port: 1024-65535" >&2; exit 2; }
if ss -ltnH "sport = :$port" | grep -q .; then
    echo "port $port is already listening" >&2
    exit 2
fi
. /home/ubuntu/oracle-data/oracle.env
bin=target/release
[ -x $bin/hxweb ] || bin=target/debug
[ -x $bin/hxweb ] && [ -d migrations ] || { echo "build hxweb first (run from your rust/ directory)" >&2; exit 2; }
hxdiff=${HX_DIFF:-$ORACLE_DATA/bin/hx-diff}
sqlx=${SQLX:-$HOME/.cargo/bin/sqlx}
db=/tmp/hx_${area}_${port}.db
rm -f "$db" "$db-wal" "$db-shm"
cp "$ORACLE_DATA/hx.db" "$db"
"$sqlx" migrate run --source migrations --database-url "sqlite://$db" >/dev/null
"$hxdiff" seed --db "$db"
python3 -I "$ORACLE_DATA/bin/gf_dump_to_sqlite.py" "$ORACLE_DATA/wp.sql" --tables wp_gf_form wp_gf_form_meta |
    python3 -I -c 'import sqlite3, sys; c = sqlite3.connect(sys.argv[1]); c.executescript(sys.stdin.read()); c.close()' "$db"
echo "gravity forms: $(python3 -I -c 'import sqlite3, sys; print(sqlite3.connect(sys.argv[1]).execute("select count(*) from wp_gf_form").fetchone()[0])' "$db") definitions"
if [ -x $bin/hxweb-cms ]; then
    log=/tmp/hx_${area}_${port}.cms.log
    if ! $bin/hxweb-cms import --dump "$ORACLE_DATA/wp.sql" --export "$ORACLE_DATA/static_site" --db "$db" > "$log" 2>&1; then
        echo "hxweb-cms import failed; see $log" >&2
        tail -5 "$log" >&2
        exit 1
    fi
    echo "cms imported from $ORACLE_DATA/wp.sql: $(tail -1 "$log")"
fi
export HXWEB_LISTEN=127.0.0.1:$port HXWEB_DB=$db HXWEB_SITE=www.harcourt.co \
    HXWEB_EXPORT=$ORACLE_DATA/static_site HXWEB_SEARCH=$ORACLE_DATA/search_index HXWEB_DOCROOT=$ORACLE_DATA/docroot \
    HXWEB_SECRET=$(openssl rand -hex 32) HXWEB_TRUSTED_PROXIES=127.0.0.1,::1 RUST_LOG=${RUST_LOG:-info}
echo "hxweb ($bin) on http://127.0.0.1:$port, database $db"
exec $bin/hxweb
