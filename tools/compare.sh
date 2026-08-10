#!/bin/sh
# Check this port against the JavaScript original by running both over a
# generated corpus and diffing the results.
#
#   tools/compare.sh [path-to-node-taxonfinder] [real-text-file ...]
#
# Any files listed after the node-taxonfinder path are compared as well, so you
# can point this at real documents rather than only the generated corpus.
#
# Needs node and a checkout of https://github.com/pleary/node-taxonfinder
# (default: ~/Development/node-taxonfinder).

set -e
cd "$(dirname "$0")/.."

TAXONFINDER_JS="${1:-$HOME/Development/node-taxonfinder}"
shift || true
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

# Any real documents named on the command line. Each becomes a one line corpus
# with its newlines escaped, which is the format dump.php and dump.js expect.
for real in "$@"; do
    name=$(basename "$real")
    php -r '$t=file_get_contents($argv[1]); file_put_contents($argv[2], str_replace("\n","\\n",$t)."\n");' \
        "$real" "$WORK/$name.corpus"
    php  tools/dump.php "$WORK/$name.corpus" > "$WORK/$name.php.out"
    node tools/dump.js  "$WORK/$name.corpus" --taxonfinder "$TAXONFINDER_JS" > "$WORK/$name.node.out"
    echo
    echo "=== $name ==="
    php tools/compare.php "$WORK/$name.corpus" "$WORK/$name.php.out" "$WORK/$name.node.out" || status=1
done

exit $status
