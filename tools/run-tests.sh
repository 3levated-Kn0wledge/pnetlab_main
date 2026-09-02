#!/usr/bin/env bash
# Run the test suite.
#
#   tools/run-tests.sh             # use the php on PATH
#   PHP=php8.4 tools/run-tests.sh  # pick an interpreter
#
# Tests are plain PHP scripts that exit non-zero on failure — no composer
# install required, so they run against a bare interpreter. Exit 1 if any fails.

set -uo pipefail
cd "$(dirname "$0")/.."

PHP="${PHP:-php}"
command -v "$PHP" >/dev/null || { echo "error: '$PHP' not found on PATH" >&2; exit 127; }

echo "Testing with: $("$PHP" --version | head -1)"

fail=0
shopt -s nullglob
for t in tests/*/*Test.php; do
    echo "$t"
    if ! "$PHP" "$t"; then
        fail=$((fail + 1))
    fi
done

if [ "$fail" -ne 0 ]; then
    echo "$fail test file(s) failed"
    exit 1
fi
echo "all test files passed"
