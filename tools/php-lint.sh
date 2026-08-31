#!/usr/bin/env bash
# Syntax-check every first-party PHP file.
#
#   tools/php-lint.sh            # use the php on PATH
#   PHP=php8.4 tools/php-lint.sh # pick an interpreter
#
# Vendored trees are excluded: store/vendor is Composer's, and includes/Slim is
# an unmaintained 2.6.1 bundle we patch but do not own. Exit 1 on any failure.

set -uo pipefail
cd "$(dirname "$0")/.."

PHP="${PHP:-php}"
command -v "$PHP" >/dev/null || { echo "error: '$PHP' not found on PATH" >&2; exit 127; }

echo "Linting with: $("$PHP" --version | head -1)"

fail=0
count=0
while IFS= read -r f; do
    count=$((count + 1))
    if ! out=$("$PHP" -l "$f" 2>&1); then
        echo "FAIL $f"
        echo "$out" | sed 's/^/     /'
        fail=$((fail + 1))
    fi
done < <(find . \
    -path ./.git -prune -o \
    -path ./store/vendor -prune -o \
    -path ./store/node_modules -prune -o \
    -type f -name '*.php' -print)

echo "checked $count files, $fail failed"
[ "$fail" -eq 0 ]
