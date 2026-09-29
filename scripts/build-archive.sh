#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Builds the installable app archive: a folder "verein" with the built frontend and the production PHP
# dependencies (TCPDF), ready to be unpacked into custom_apps/ - no composer, npm or shell needed on the server.
# Used by the release workflow and by the CI job that installs exactly this archive into a fresh Nextcloud.
#
#   scripts/build-archive.sh [output.tar.gz]      (default: verein.tar.gz in the current directory)
#
# Needs git, composer, npm and GNU coreutils. Builds from the files tracked by git in a temporary copy, so the
# checkout's own vendor/ (with the dev dependencies) and node_modules/ are left alone.

set -euo pipefail

out="$(realpath -m "${1:-verein.tar.gz}")"
root="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

mkdir -p "$tmp/src" "$tmp/verein/js"
(cd "$root" && git ls-files -z | xargs -0 cp --parents -t "$tmp/src")

cd "$tmp/src"
composer update --no-dev --no-interaction --no-progress --optimize-autoloader
npm ci --no-audit --no-fund
npm run build

cp -r appinfo lib templates img l10n vendor LICENSE LICENSES REUSE.toml AUTHORS.md README.md CHANGELOG.md SECURITY.md "$tmp/verein/"
cp -r js/dist "$tmp/verein/js/"

# TCPDF ships examples, tests and 25 MB of fonts; the app only uses the built-in Helvetica.
tcpdf="$tmp/verein/vendor/tecnickcom/tcpdf"
rm -rf "$tcpdf/examples" "$tcpdf/tests" "$tcpdf/tools" "$tcpdf/.git"
find "$tcpdf/fonts" -mindepth 1 ! -name 'helvetica*' -exec rm -rf {} +

# nothing that only belongs to development may end up in the archive
for dev in vendor/phpunit vendor/nextcloud/ocp node_modules tests js/main.js; do
    if [ -e "$tmp/verein/$dev" ]; then
        echo "error: $dev must not be in the archive" >&2
        exit 1
    fi
done
for built in js/dist/nextcloud-verein.mjs js/dist/style.css; do
    test -f "$tmp/verein/$built" || { echo "error: frontend not built ($built missing)" >&2; exit 1; }
done

tar -czf "$out" -C "$tmp" verein
echo "built $out ($(du -h "$out" | cut -f1))"
