#!/usr/bin/env bash
# Builds the installable zip (dist/relecture-<version>.zip): the plugin folder without the
# files listed in .distignore. Install it from Plugins → Add New → Upload Plugin.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(sed -n 's/^ \* Version: *//p' "$ROOT/relecture.php" | tr -d '[:space:]')"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

rsync -a --exclude-from="$ROOT/.distignore" "$ROOT/" "$STAGE/relecture/"
mkdir -p "$ROOT/dist"
rm -f "$ROOT/dist/relecture-$VERSION.zip"
( cd "$STAGE" && zip -qr -X "$ROOT/dist/relecture-$VERSION.zip" relecture )
echo "dist/relecture-$VERSION.zip"
unzip -l "$ROOT/dist/relecture-$VERSION.zip" | tail -1
