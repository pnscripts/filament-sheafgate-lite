#!/bin/bash
# Buyer check: install Sheafgate Lite from the release zip (dist/, built by build.sh) as a buyer
# would (unzip into packages/, path repository), then run the real UI flow in headless Chrome:
# upload a workbook and a Windows-1252 CSV, map, import, failed rows.
# Usage: tools/buyer_check.sh <php-binary> <laravel-constraint> <filament-constraint> <workdir> [port] [shots-dir]
set -euo pipefail
PHP_BIN=$1; LARAVEL=$2; FILAMENT=$3; WORK=$4; PORT=${5:-8781}; SHOTS=${6:-}
ROOT=$(cd "$(dirname "$0")/.." && pwd)
COMPOSER="$(command -v composer)"
step() { echo "STEP $1: $2"; }
ok() { echo "  ok: $1"; }
fail() { echo "  FAIL: $1"; exit 1; }
rm -rf "$WORK" && mkdir -p "$WORK"

step 1 "release zip"
ZIP=$(ls -v "$ROOT"/dist/filament-sheafgate-lite-*.zip | tail -1)
echo "  $ZIP"
mkdir -p "$WORK/unzipped" && (cd "$WORK/unzipped" && unzip -q "$ZIP")
D="$WORK/unzipped/filament-sheafgate-lite"
[ -f "$D/LICENSE" ] && [ -f "$D/README.md" ] && [ -d "$D/src" ] && [ ! -d "$D/tests" ] && [ ! -d "$D/tools" ] && ok "zip has src, README and LICENSE, no tests or tools" || fail "zip content"

step 2 "fresh Laravel $LARAVEL app with Filament $FILAMENT"
"$PHP_BIN" "$COMPOSER" create-project -q --no-interaction laravel/laravel "$WORK/app" "$LARAVEL"
cd "$WORK/app"
"$PHP_BIN" "$COMPOSER" require -q --no-interaction -W "filament/filament:$FILAMENT"
"$PHP_BIN" artisan filament:install --panels --no-interaction >/dev/null
echo "  $("$PHP_BIN" -r 'echo PHP_VERSION;') | laravel $("$PHP_BIN" artisan --version | awk '{print $3}') | filament $("$PHP_BIN" "$COMPOSER" show filament/filament | awk '/^versions/{print $4}')"

step 3 "install the release archive (path repository standing in for Packagist), composer require"
mkdir -p packages && cp -r "$D" packages/
# The archive has no git metadata, so the path repository is told its version (Packagist reads it from the tag).
VERSION=$(basename "$ZIP" .zip); VERSION=${VERSION##*-}
"$PHP_BIN" "$COMPOSER" config repositories.sheafgate-lite "{\"type\": \"path\", \"url\": \"packages/filament-sheafgate-lite\", \"options\": {\"versions\": {\"pnscripts/sheafgate-lite\": \"$VERSION\"}}}"
"$PHP_BIN" "$COMPOSER" require -q --no-interaction "pnscripts/sheafgate-lite:^1.0"
"$PHP_BIN" "$COMPOSER" show pnscripts/sheafgate-lite | grep -q "^versions : \* $VERSION$" && ok "installed as $VERSION" || fail "installed version"
"$PHP_BIN" artisan package:discover | grep -q "pnscripts/sheafgate-lite" && ok "provider discovered" || fail "provider discovered"
"$PHP_BIN" "$COMPOSER" audit --no-interaction >/dev/null 2>&1 && ok "composer audit: no advisories" || fail "composer audit"

step 4 "Filament's import tables and the demo resource"
"$PHP_BIN" artisan vendor:publish --tag=filament-actions-migrations --no-interaction >/dev/null
grep -rqs "job_batches" database/migrations || "$PHP_BIN" artisan make:queue-batches-table >/dev/null
grep -rqs "create(.notifications" database/migrations || "$PHP_BIN" artisan make:notifications-table >/dev/null
sed -i 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=sync/' .env
cp -r "$ROOT/tools/buyer-app/." "$WORK/app/"
sed -i "s#->path('admin')#->path('admin')->brandName('Demo Shop')#" app/Providers/Filament/AdminPanelProvider.php
"$PHP_BIN" artisan migrate:fresh --seed --force -q
"$PHP_BIN" artisan tinker --execute="echo Schema::hasTable('imports') && Schema::hasTable('failed_import_rows') ? 'yes' : 'no';" | grep -q yes && ok "tables migrated" || fail "tables migrated"
"$PHP_BIN" artisan tinker --execute="app()->setLocale('bg'); echo __('sheafgate-lite::import.sheet.label');" | grep -q "Лист" && ok "translations (bg)" || fail "translations"

step 5 "real UI in headless Chrome"
cd public
"$PHP_BIN" -S "127.0.0.1:$PORT" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php > "$WORK/serve.log" 2>&1 &
SERVER_PID=$!
cd ..
echo "$SERVER_PID" > "$WORK/serve.pid"
trap 'kill "$(cat "$WORK/serve.pid")" 2>/dev/null || true' EXIT
sleep 3
BASE="http://127.0.0.1:$PORT" DEMO_FILE="$ROOT/tools/demo/spring-price-update.xlsx" DEMO_CSV="$ROOT/tools/demo/cafe-range-windows-1252.csv" SHOTS="$SHOTS" CHROME_PROFILE="$ROOT/build/chrome" node "$ROOT/tools/buyer-ui.mjs" || fail "browser flow"
"$PHP_BIN" artisan tinker --execute="echo App\Models\Product::where('sku','PN-1040')->value('price').'|'.App\Models\Product::where('sku','PN-1131')->value('name').'|'.App\Models\Product::where('sku','PN-1131')->first()?->category?->name.'|'.(App\Models\Product::where('sku','PN-1110')->exists() ? 'x' : 'none');" | grep -q "26.00|Crème Linen Apron|Home|none" && ok "database after import: prices updated, Windows-1252 text as UTF-8, related record by code, invalid row not saved" || fail "database after import"
echo "BUYER CHECK PASSED"
