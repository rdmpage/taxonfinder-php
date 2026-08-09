#!/bin/sh
# Check this port against the JavaScript original by running both over a
# generated corpus and diffing the results.
#
#   tools/compare.sh [path-to-node-taxonfinder]
#
# Needs node and a checkout of https://github.com/pleary/node-taxonfinder
# (default: ~/Development/node-taxonfinder).

set -e
cd "$(dirname "$0")/.."

TAXONFINDER_JS="${1:-$HOME/Development/node-taxonfinder}"
WORK="${TMPDIR:-/tmp}/taxonfinder-compare"
mkdir -p "$WORK"

if [ ! -d "$TAXONFINDER_JS/lib" ]; then
    echo "node-taxonfinder not found at $TAXONFINDER_JS" >&2
    echo "usage: tools/compare.sh [path-to-node-taxonfinder]" >&2
    exit 1
fi

echo "building corpus..."
php tools/make-corpus.php > "$WORK/corpus.txt"

status=0
for mode in plain html; do
    if [ "$mode" = html ]; then flag=--html; else flag=""; fi
    php  tools/dump.php "$WORK/corpus.txt" $flag > "$WORK/php.$mode.out"
    node tools/dump.js  "$WORK/corpus.txt" $flag --taxonfinder "$TAXONFINDER_JS" > "$WORK/node.$mode.out"
    echo
    echo "=== $mode ==="
    php tools/compare.php "$WORK/corpus.txt" "$WORK/php.$mode.out" "$WORK/node.$mode.out" || status=1
done

exit $status
