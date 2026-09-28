#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PLUGIN_FILE="ufsc-licence-competition.php"
VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "$PLUGIN_FILE" | head -n1 | tr -d '\r')"

if [[ -z "$VERSION" ]]; then
  echo "Unable to detect plugin version." >&2
  exit 1
fi

composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

test -f vendor/autoload.php
php -r "require 'vendor/autoload.php'; exit(class_exists('Dompdf\\Dompdf') ? 0 : 1);"

rm -rf build
mkdir -p build/ufsc-licence-competition

rsync -a ./ build/ufsc-licence-competition/ \
  --exclude=".git" \
  --exclude=".github" \
  --exclude="build" \
  --exclude="node_modules" \
  --exclude="tests" \
  --exclude="tools" \
  --exclude=".env" \
  --exclude="*.log"

test -f build/ufsc-licence-competition/vendor/autoload.php
test -f build/ufsc-licence-competition/vendor/dompdf/dompdf/src/Dompdf.php
test -f build/ufsc-licence-competition/ufsc-licence-competition.php

printf '%s\n' "$VERSION" > build/ufsc-licence-competition/DISTRIBUTION-VERSION

cd build
ZIP="ufsc-licence-competition-${VERSION}.zip"
zip -qr "$ZIP" ufsc-licence-competition

echo "PACKAGE_PATH=build/$ZIP"
echo "PACKAGE_NAME=$ZIP"
