#!/bin/bash
# Runs the test suite against one PHP / Filament / Laravel combination in a clean copy.
# Usage: tools/matrix.sh <php-binary> <filament-constraint> <laravel-constraint> [label]
# Example: tools/matrix.sh php8.4 "^5.0" "^13.0" f5-l13
set -euo pipefail
PHP_BIN=$1; FILAMENT=$2; LARAVEL=$3; LABEL=${4:-$(echo "$FILAMENT-$LARAVEL" | tr -c 'a-z0-9.-' '_')}
ROOT=$(cd "$(dirname "$0")/.." && pwd)
DIR="$ROOT/build/matrix/$LABEL"
rm -rf "$DIR" && mkdir -p "$DIR"
# all tracked files (git archive would drop the export-ignored tests)
(cd "$ROOT" && git ls-files -z | xargs -0 tar -c) | tar -x -C "$DIR"
# include uncommitted working-tree changes of tracked files and new test files
(cd "$ROOT" && git ls-files -m -o --exclude-standard src tests resources composer.json | while read -r f; do mkdir -p "$DIR/$(dirname "$f")"; cp "$f" "$DIR/$f"; done)
cd "$DIR"
COMPOSER="$(command -v composer)"
"$PHP_BIN" "$COMPOSER" require --no-update --no-interaction "filament/filament:$FILAMENT" "illuminate/support:$LARAVEL" >/dev/null
"$PHP_BIN" "$COMPOSER" update --no-interaction --no-progress -q -W
echo "== $LABEL: $("$PHP_BIN" -r 'echo PHP_VERSION;') | filament $("$PHP_BIN" "$COMPOSER" show filament/filament | awk '/^versions/{print $4}') | laravel $("$PHP_BIN" "$COMPOSER" show laravel/framework | awk '/^versions/{print $4}') | livewire $("$PHP_BIN" "$COMPOSER" show livewire/livewire | awk '/^versions/{print $4}')"
"$PHP_BIN" vendor/bin/pest --colors=never 2>&1 | tail -n 3
