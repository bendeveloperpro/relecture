#!/usr/bin/env bash
# Generates every export format through WP-CLI on the development site, then checks them
# with independent tools: zip integrity, XML well-formedness, and LibreOffice opening the
# Excel and Word files (the expected texts must come out of the conversion).
#
#   tests/check-exports.sh        (after dev/setup.sh; needs unzip, xmllint, soffice)
set -euo pipefail

ENV_NAME="${RELECTURE_ENV:-dev}"
CLI="relecture_${ENV_NAME}_cli"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/dev/out"
IN_CONTAINER="wp-content/plugins/relecture/dev/out"
wp() { podman exec "$CLI" wp "$@"; }

rm -rf "$OUT" && mkdir -p "$OUT" && chmod 0777 "$OUT"   # written by the container (UID 33)
podman exec "$CLI" mkdir -p "$IN_CONTAINER"

post_id="$(wp post list --post_type=post --name=relecture-demo-pieges --field=ID --format=ids)"
wp relecture start "$post_id" >/dev/null
wp eval '
	$review = \Relecture\Repository::get_latest_review( '"$post_id"' );
	foreach ( \Relecture\Repository::get_items( $review["id"] ) as $item ) {
		if ( false !== strpos( $item["original"], "HYPERLINK" ) ) {
			\Relecture\Review_Service::update_item( $item, array( "correction" => "=HYPERLINK(\"https://evil.test\",\"clic ici\")", "note" => "Formule piégée" ), 1 );
		}
		if ( false !== strpos( $item["original"], "xss-original" ) ) {
			\Relecture\Review_Service::update_item( $item, array( "note" => "<script>alert(\"xss-note\")</script>", "note_type" => "guideline" ), 1 );
		}
	}
' >/dev/null

for format in json md csv xlsx docx; do
    wp relecture export "$post_id" --format="$format" --output="$IN_CONTAINER/review.$format" >/dev/null
done
wp relecture guidelines --status=all --format=md --output="$IN_CONTAINER/guidelines.md" >/dev/null

fail=0
check() { if eval "$2"; then echo "  ✔ $1"; else echo "  ✘ $1"; fail=1; fi; }

for format in xlsx docx; do
    file="$OUT/review.$format"
    check "$format: zip integrity" "unzip -tq '$file' >/dev/null"
    parts="$(mktemp -d)"
    unzip -q "$file" -d "$parts"
    bad=0
    while IFS= read -r -d '' xml; do
        xmllint --noout "$xml" 2>/dev/null || { echo "    malformed: ${xml#"$parts"/}"; bad=1; }
    done < <(find "$parts" -type f \( -name '*.xml' -o -name '*.rels' \) -print0)
    check "$format: every XML part is well-formed" "[ $bad -eq 0 ]"
    rm -rf "$parts"
done

convert="$(mktemp -d)"
soffice --headless --convert-to csv:"Text - txt - csv (StarCalc)":44,34,76 --outdir "$convert" "$OUT/review.xlsx" >/dev/null 2>&1
check "xlsx: LibreOffice reads it" "[ -s '$convert/review.csv' ]"
check "xlsx: formula stays text" "grep -q '=HYPERLINK(\"\"https://evil.test' '$convert/review.csv'"
check "xlsx: payload stays text" "grep -q '<script>alert(\"\"xss-note\"\")</script>' '$convert/review.csv'"
soffice --headless --convert-to txt:Text --outdir "$convert" "$OUT/review.docx" >/dev/null 2>&1
check "docx: LibreOffice reads it" "[ -s '$convert/review.txt' ]"
check "docx: payload stays text" "grep -q '<script>alert(\"xss-note\")</script>' '$convert/review.txt'"
check "docx: guidelines section present" "grep -qi 'consigne\|guideline' '$convert/review.txt'"
rm -rf "$convert"

check "csv: BOM + semicolon separator" "head -c 3 '$OUT/review.csv' | od -An -tx1 | grep -q 'ef bb bf' && head -1 '$OUT/review.csv' | grep -q ';'"
check "csv: formulas neutralized" "! grep -Eq '(^|;)\"?=HYPERLINK' '$OUT/review.csv'"
check "json: valid" "php -r 'exit( json_decode( file_get_contents( \"$OUT/review.json\" ) ) ? 0 : 1 );'"
check "md: guidelines exported" "grep -q 'xss-note' '$OUT/guidelines.md'"

wp eval 'foreach ( \Relecture\Repository::get_post_reviews( '"$post_id"' ) as $r ) { \Relecture\Repository::delete_reviews( array( $r["id"] ) ); }' >/dev/null

[ $fail -eq 0 ] && echo "✔ exports OK ($OUT)" || { echo "✘ export checks failed"; exit 1; }
