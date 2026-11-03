#!/bin/bash
# Builds the release zip from the committed tree: dist/filament-sheafgate-lite-<version>.zip
# Usage: ./build.sh            (version from the newest CHANGELOG heading)
set -euo pipefail
cd "$(dirname "$0")"
VERSION=$(grep -m1 -oE '^## [0-9]+\.[0-9]+\.[0-9]+' CHANGELOG.md | cut -d' ' -f2)
if [ -n "$(git status --porcelain -- src config database resources docs composer.json README.md LICENSE CHANGELOG.md)" ]; then
    echo "Commit your changes first: the zip is built from HEAD." >&2
    exit 1
fi
mkdir -p dist
OUT="dist/filament-sheafgate-lite-${VERSION}.zip"
rm -f "$OUT"
git archive --format=zip --prefix="filament-sheafgate-lite/" -o "$OUT" HEAD
unzip -l "$OUT" | tail -1
echo "$OUT"
