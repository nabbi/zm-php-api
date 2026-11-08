#!/bin/sh
# Builds the release tarball: this repository at HEAD (api/, tools/, docs/,
# tests/, contrib/) under zm-php-api-<VERSION>/, plus its SHA-256. The same
# tarball comes out of CI (as an artifact) and of a release (as its asset), so
# a distribution package can pin either. Unpack it and run
# tools/zm-overlay.sh on a ZoneMinder source tree (docs/install.md).
#
#   tools/package.sh dist             writes dist/zm-php-api-<VERSION>.tar.gz and .sha256
#   tools/package.sh notes <version>  prints that version's CHANGELOG.md section (release notes)
set -eu
here="$(cd "$(dirname "$0")/.." && pwd)"
version="$(tr -d '[:space:]' < "$here/VERSION")"
case "${1:-}" in
    dist)
        out="${2:-dist}"
        mkdir -p "$out"
        name="zm-php-api-$version"
        git -C "$here" archive --format=tar.gz --prefix="$name/" -o "$out/$name.tar.gz" HEAD
        (cd "$out" && sha256sum "$name.tar.gz" > "$name.tar.gz.sha256")
        ls -l "$out/$name.tar.gz" "$out/$name.tar.gz.sha256"
        ;;
    notes)
        want="${2:?version}"
        # The "## <version>" section of CHANGELOG.md, up to the next "## ".
        awk -v want="$want" '
            /^## / { on = (index($0, "## " want) == 1); if (on) { next } }
            on { print }
        ' "$here/CHANGELOG.md" | sed -e :a -e '/^\n*$/{$d;N;ba' -e '}'
        ;;
    *)
        sed -n '2,10p' "$0" | sed 's/^# \{0,1\}//'
        exit 2
        ;;
esac
