#!/usr/bin/env bash
# Build and sign a release from the committed tree (HEAD).
#
# Usage: tools/build-release.sh <key_id> [tested_wp] [changelog.md] < secret-key.b64
#
# Produces dist/pardesign-entretien.zip and dist/pardesign-entretien.json, then prints the
# command to publish them as a GitHub release tagged v{version}. Requires git, zip support in
# PHP and ext/sodium (set PHP_BIN if the default php lacks them).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# PHP binary: $PHP_BIN if set, else the first "php" on PATH that actually runs, else the newest
# MAMP PHP (shell aliases such as "php -> MAMP" do not apply inside scripts).
if [ -z "${PHP_BIN:-}" ]; then
	if sh -c "php -r 'exit(0);'; exit \$?" >/dev/null 2>&1; then
		PHP_BIN=php
	else
		PHP_BIN="$(ls -d /Applications/MAMP/bin/php/php*/bin/php 2>/dev/null | sort -V | tail -1)"
	fi
fi
[ -n "${PHP_BIN:-}" ] && "$PHP_BIN" -r 'exit(0);' >/dev/null 2>&1 || { echo "No working PHP found; set PHP_BIN=/path/to/php" >&2; exit 1; }
KEY_ID="${1:?key_id required}"
TESTED="${2:-}"
CHANGELOG="${3:-}"

VERSION="$(sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]+([0-9.]+).*/\1/p' "$ROOT/pardesign-entretien.php")"
[ -n "$VERSION" ] || { echo "Cannot read plugin version." >&2; exit 1; }

if ! git -C "$ROOT" diff --quiet HEAD -- . ':!dist'; then
	echo "Working tree has uncommitted changes; releases are built from HEAD. Commit first." >&2
	exit 1
fi
if [ "$(git -C "$ROOT" describe --tags --exact-match 2>/dev/null || true)" != "v$VERSION" ]; then
	echo "Warning: HEAD is not tagged v$VERSION (tag it before publishing)." >&2
fi

mkdir -p "$ROOT/dist"
ZIP="$ROOT/dist/pardesign-entretien.zip"
rm -f "$ZIP" "$ROOT/dist/pardesign-entretien.json"
git -C "$ROOT" archive --format=zip --prefix=pardesign-entretien/ -o "$ZIP" HEAD
echo "Built $ZIP for version $VERSION"

"$PHP_BIN" "$ROOT/tools/sign-release.php" "$ZIP" "$KEY_ID" "$TESTED" "$CHANGELOG"

cat <<EOF

Publish (assets must keep these exact names):
  gh release create "v$VERSION" --latest --title "v$VERSION" --notes-file "${CHANGELOG:-/dev/null}" dist/pardesign-entretien.zip dist/pardesign-entretien.json
EOF
