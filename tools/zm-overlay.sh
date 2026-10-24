#!/bin/sh
# Layers this API onto a ZoneMinder source tree in place of CakePHP, before
# ZoneMinder is configured with CMake. Used by packaging (a distribution
# package's prepare step) and by test builds.
#
#   tools/zm-overlay.sh <zoneminder-source-tree>
#
# It:
#   - replaces web/api (CakePHP, its Crud and Enum-Behavior plugins) with api/
#   - drops web/CMakeLists.txt's add_subdirectory(api) and the install() rules
#     for CakePHP's generated config files
#   - points the top-level submodule check at dep/RtspServer, since the Crud
#     submodule it looked for is gone
#   - removes ZoneMinder's PHP tests that read CakePHP's source
#   - records this API's version in web/api/VERSION
# Nothing else changes: ZoneMinder's web server configurations still route
# /zm/api to api/app/webroot/index.php, which forwards to api/dispatch.php.
# Safe to run again on a tree it has already changed. Fails, changing nothing
# further, on a tree whose CMake files it does not recognise.
set -eu
here="$(cd "$(dirname "$0")/.." && pwd)"
zm="${1:?usage: zm-overlay.sh <zoneminder-source-tree>}"
zm="$(cd "$zm" && pwd)"

die() { echo "zm-overlay: $*" >&2; exit 1; }
[ -f "$zm/web/includes/auth.php" ] && [ -f "$zm/web/CMakeLists.txt" ] && [ -f "$zm/CMakeLists.txt" ] ||
    die "$zm is not a ZoneMinder source tree"

crud='web/api/app/Plugin/Crud/Lib/CrudControllerTrait.php'
rtsp='dep/RtspServer/CMakeLists.txt'
top="$zm/CMakeLists.txt"
web="$zm/web/CMakeLists.txt"

# Check every edit applies before making any.
grep -qF "$crud" "$top" || grep -qF "$rtsp" "$top" || die "no submodule check in CMakeLists.txt"
if grep -q 'api/app/Config\|api/lib/Cake' "$web"; then
    grep -q '^# Install the api config files' "$web" || die "unrecognised api install rules in web/CMakeLists.txt"
fi

sed -i "s|$crud|$rtsp|" "$top"
sed -i -e '/^# Process the api subdirectory$/d' -e '/^add_subdirectory(api)$/d' \
    -e '/^# Install the api config files/,/^endif()$/d' "$web"
! grep -q 'add_subdirectory(api)\|api/app/Config\|api/lib/Cake' "$web" || die "web/CMakeLists.txt still names CakePHP"

rm -rf "$zm/web/api"
cp -R "$here/api" "$zm/web/api"

# CakePHP's plugins were submodules: drop them from .gitmodules, so a checkout
# (or a build that fetches missing submodules) doesn't bring them back. awk,
# not git config: a release tarball's build has no git.
if [ -f "$zm/.gitmodules" ]; then
    awk '
        /^\[submodule /    { flush(); held = $0 "\n"; drop = 0; next }
        held != ""          { held = held $0 "\n"; if ($1 == "path" && $3 ~ /^web\/api\//) drop = 1; next }
                            { printf "%s\n", $0 }
        function flush() { if (held != "" && !drop) printf "%s", held; held = "" }
        END                 { flush() }
    ' "$zm/.gitmodules" > "$zm/.gitmodules.zm-overlay" && mv "$zm/.gitmodules.zm-overlay" "$zm/.gitmodules"
fi
# ZoneMinder's PHP tests that read CakePHP's source (test_api_request_field.php
# on master) have nothing left to read; this repository's tests/ cover the API.
for t in "$zm"/tests/php/*.php; do
    if [ -f "$t" ] && grep -q 'web/api/app/\|web/api/lib/Cake' "$t"; then rm -f "$t"; fi
done
cp "$here/VERSION" "$zm/web/api/VERSION"
echo "zm-overlay: $zm/web/api is zm-php-api $(cat "$here/VERSION")"
