#!/bin/sh
# Runs the API's unit tests against a ZoneMinder source tree's web includes.
#
#   ZM_SRC=/path/to/zoneminder tests/run.sh [test_api_x.php ...]
#
# The API reads ZoneMinder's web/includes and web/vendor by relative path, so
# the tests run in a scratch copy laid out as ZoneMinder's tree is: web/ from
# ZM_SRC with this repo's api/ in place of web/api, and tests/ as tests/php/.
# No database or web server is needed. Exit status: the number of failed files.
set -u
here="$(cd "$(dirname "$0")/.." && pwd)"
zm="${ZM_SRC:?set ZM_SRC to a ZoneMinder source tree}"
[ -f "$zm/web/includes/auth.php" ] || { echo "run.sh: $zm is not a ZoneMinder source tree" >&2; exit 2; }
php="${PHP:-php}"

tmp="$(mktemp -d "${TMPDIR:-/tmp}/zm-php-api-tests.XXXXXX")" || exit 2
trap 'rm -rf "$tmp"' EXIT INT TERM
mkdir -p "$tmp/web" "$tmp/tests"
# ZoneMinder's web tree without its API (CakePHP, or an older copy of this one).
(cd "$zm/web" && find . -path ./api -prune -o -type f -print | cpio -pdm --quiet "$tmp/web") || exit 2
cp -R "$here/api" "$tmp/web/api"
cp -R "$here/tests" "$tmp/tests/php"
# The API reference and the release, which test_api_openapi.php checks against the code.
mkdir -p "$tmp/docs" && cp -R "$here/docs/openapi" "$tmp/docs/openapi" && cp "$here/VERSION" "$tmp/VERSION"

cd "$tmp/tests/php" || exit 2
[ $# -gt 0 ] || set -- test_*.php
failed=0
for t in "$@"; do
    t="$(basename "$t")"
    printf '== %s\n' "$t"
    if "$php" -d error_reporting=-1 -d display_errors=stderr "$t"; then :; else
        echo "FAIL $t"
        failed=$((failed + 1))
    fi
done
echo
echo "$# files, $failed failed"
exit "$failed"
