#!/bin/bash
# Start your own build of hxweb on hxport for differential testing: a fresh copy of the imported database with the
# persona sessions seeded, unported paths passed to the oracle's PHP, listening on 127.0.0.1:<port>.
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
[ -x $bin/hxweb ] || { echo "build hxweb first (run from your rust/ directory)" >&2; exit 2; }
hxdiff=${HX_DIFF:-$ORACLE_DATA/bin/hx-diff}
db=/tmp/hx_${area}_${port}.db
rm -f "$db" "$db-wal" "$db-shm"
cp "$ORACLE_DATA/hx.db" "$db"
"$hxdiff" seed --db "$db"
export HXWEB_LISTEN=127.0.0.1:$port HXWEB_DB=$db HXWEB_LEGACY=$ORACLE_LEGACY HXWEB_SITE=www.harcourt.co \
    HXWEB_EXPORT=$ORACLE_DATA/static_site HXWEB_SEARCH=$ORACLE_DATA/search_index HXWEB_DOCROOT=$ORACLE_DATA/docroot \
    HXWEB_SECRET=$(openssl rand -hex 32) HXWEB_TRUSTED_PROXIES=127.0.0.1,::1 RUST_LOG=${RUST_LOG:-info}
echo "hxweb ($bin) on http://127.0.0.1:$port, database $db, legacy $ORACLE_LEGACY"
exec $bin/hxweb
