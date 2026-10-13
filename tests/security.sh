#!/usr/bin/env bash
# Permission and validation checks of the REST API, with real HTTP requests authenticated by
# application passwords (dev site only: WP_ENVIRONMENT_TYPE=local allows them over http).
#
#   tests/security.sh        (after dev/setup.sh)
set -euo pipefail

ENV_NAME="${RELECTURE_ENV:-dev}"
PORT="${RELECTURE_PORT:-8090}"
CLI="relecture_${ENV_NAME}_cli"
BASE="http://localhost:$PORT/?rest_route=/relecture/v1"
wp() { podman exec "$CLI" wp "$@"; }

fail=0
expect() { # label expected-status actual-status
    if [ "$2" = "$3" ]; then echo "  ✔ $1 ($3)"; else echo "  ✘ $1: expected $2, got $3"; fail=1; fi
}

declare -A PASS
for user in editor author contributor; do
    wp user application-password delete "$user" --all >/dev/null 2>&1 || true
    PASS[$user]="$(wp user application-password create "$user" relecture-tests --porcelain)"
done

# call <user|-> <method> <route> [json]  → prints the HTTP status, body in $BODY_FILE
BODY_FILE="$(mktemp)"
call() {
    local user="$1" method="$2" route="$3" data="${4:-}"
    local args=( -s -o "$BODY_FILE" -w '%{http_code}' -X "$method" -H 'Content-Type: application/json' )
    [ "$user" != "-" ] && args+=( -u "$user:${PASS[$user]}" )
    [ -n "$data" ] && args+=( --data "$data" )
    curl "${args[@]}" "$BASE$route"
}
json() { php -r '$d = json_decode( file_get_contents( $argv[1] ), true ); echo $argv[2] === "" ? count( $d ) : ( $d[ $argv[2] ] ?? "" );' "$BODY_FILE" "$1"; }

blocks="$(wp post list --post_type=post --name=relecture-demo-blocs --field=ID --format=ids)"
mine="$(wp post list --post_type=post --name=relecture-demo-auteur --field=ID --format=ids)"
wp eval 'foreach ( array( '"$blocks"', '"$mine"' ) as $id ) { \Relecture\Review_Service::delete_post_reviews( $id ); }' >/dev/null

echo "Editor"
expect "starts a review" 201 "$(call editor POST /reviews "{\"post_id\":$blocks}")"
review="$(php -r '$d = json_decode( file_get_contents( $argv[1] ), true ); echo $d["review"]["id"];' "$BODY_FILE")"
item="$(php -r '$d = json_decode( file_get_contents( $argv[1] ), true ); echo $d["items"][2]["id"];' "$BODY_FILE")"
expect "reads the review" 200 "$(call editor GET "/reviews/$review")"
expect "saves a correction" 200 "$(call editor PATCH "/items/$item" '{"correction":"Texte corrigé.","expected_version":0}')"
expect "stale version is refused" 409 "$(call editor PATCH "/items/$item" '{"correction":"Autre.","expected_version":0}')"
expect "unknown status is refused" 400 "$(call editor PATCH "/items/$item" '{"correction_status":"bogus"}')"
long="$(php -r 'echo json_encode( array( "note" => str_repeat( "a", 20001 ) ) );')"
expect "oversized note is refused" 400 "$(call editor PATCH "/items/$item" "$long")"
expect "unknown review status is refused" 400 "$(call editor PATCH "/reviews/$review" '{"status":"published"}')"

echo "Contributor"
expect "cannot read an editor review" 403 "$(call contributor GET "/reviews/$review")"
expect "cannot change an editor row" 403 "$(call contributor PATCH "/items/$item" '{"correction":"Piraté."}')"
expect "cannot start a review of an editor post" 403 "$(call contributor POST /reviews "{\"post_id\":$blocks}")"
expect "cannot resync an editor review" 403 "$(call contributor POST "/reviews/$review/resync")"
expect "list of reviews answers" 200 "$(call contributor GET /reviews)"
expect "…without the editor review" 0 "$(json '')"
expect "guidelines answer" 200 "$(call contributor GET '/guidelines&status=all')"
expect "…without editor guidelines" 0 "$(json '')"

echo "Author"
expect "cannot change an editor row" 403 "$(call author PATCH "/items/$item" '{"note":"Piraté."}')"
expect "starts a review of own draft" 201 "$(call author POST /reviews "{\"post_id\":$mine}")"

echo "Logged out"
expect "cannot list reviews" 401 "$(call - GET /reviews)"
expect "cannot change a row" 401 "$(call - PATCH "/items/$item" '{"note":"Piraté."}')"
expect "cannot read an item" 401 "$(call - GET "/items/$item")"
status="$(curl -s -o /dev/null -w '%{http_code}' -X POST --data "action=relecture_export&review=$review&format=csv" "http://localhost:$PORT/wp-admin/admin-post.php")"
[ "$status" != "200" ] && echo "  ✔ export refused without session ($status)" || { echo "  ✘ export answered 200 without session"; fail=1; }

echo "Stored data"
stored="$(wp eval 'echo \Relecture\Repository::get_item( '"$item"' )["correction"];')"
expect "the contributor's attempt left the row untouched" "Texte corrigé." "$stored"

for user in editor author contributor; do
    wp user application-password delete "$user" --all >/dev/null 2>&1 || true
done
wp eval 'foreach ( array( '"$blocks"', '"$mine"' ) as $id ) { \Relecture\Review_Service::delete_post_reviews( $id ); }' >/dev/null
rm -f "$BODY_FILE"

[ $fail -eq 0 ] && echo "✔ security checks passed" || { echo "✘ security checks failed"; exit 1; }
