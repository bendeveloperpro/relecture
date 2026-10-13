#!/usr/bin/env bash
# Regenerates the translation template and compiles the bundled French translation.
#
#   scripts/i18n.sh        (dev containers running; needs msgmerge and msgfmt on the host)
#
# languages/relecture-fr_FR.po is the source of the translation: edit it, then re-run.
# Produced: relecture.pot, relecture-fr_FR.mo, relecture-fr_FR.l10n.php (fast loading,
# WordPress 6.5+) and relecture-fr_FR-<md5>.json (strings of assets/js/review.js).
set -euo pipefail

ENV_NAME="${RELECTURE_ENV:-dev}"
CLI="relecture_${ENV_NAME}_cli"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LANG_DIR="$ROOT/languages"
PLUGIN="wp-content/plugins/relecture"
TMP="/tmp/relecture-i18n"

podman exec "$CLI" rm -rf "$TMP"
podman exec "$CLI" mkdir -p "$TMP"

podman exec "$CLI" wp i18n make-pot "$PLUGIN" "$TMP/relecture.pot" \
    --slug=relecture --domain=relecture \
    --exclude=dev,tests,vendor,node_modules,docs,scripts,languages
podman cp "$CLI:$TMP/relecture.pot" "$LANG_DIR/relecture.pot"

po="$LANG_DIR/relecture-fr_FR.po"
if [ -f "$po" ]; then
    msgmerge --quiet --update --backup=none --no-fuzzy-matching "$po" "$LANG_DIR/relecture.pot"
fi
untranslated="$(msgattrib --untranslated --no-obsolete "$po" | grep -c '^msgid ' || true)"
[ "$untranslated" -gt 1 ] && echo "⚠ $((untranslated - 1)) string(s) not translated in $po" >&2
msgfmt --check --output-file="$LANG_DIR/relecture-fr_FR.mo" "$po"

podman cp "$po" "$CLI:$TMP/relecture-fr_FR.po"
podman exec "$CLI" wp i18n make-php "$TMP"
# The JS strings keep living in the .po (--no-purge): make-json only copies them out.
podman exec "$CLI" wp i18n make-json "$TMP/relecture-fr_FR.po" "$TMP" --no-purge
podman cp "$CLI:$TMP/relecture-fr_FR.l10n.php" "$LANG_DIR/relecture-fr_FR.l10n.php"
rm -f "$LANG_DIR"/relecture-fr_FR-*.json
for json in $(podman exec "$CLI" sh -c "ls $TMP/*.json"); do
    podman cp "$CLI:$json" "$LANG_DIR/"
done
podman exec "$CLI" rm -rf "$TMP"

ls -1 "$LANG_DIR"
