#!/usr/bin/env bash
# Installs WordPress in the dev containers, activates Relecture and seeds test content.
# Idempotent: re-running it only refreshes the test content.
#
#   RELECTURE_ENV    instance name (default: dev)        → containers relecture_<env>_*
#   RELECTURE_PORT   published port (default: 8090)
#   WP_CORE_VERSION  core version to install (default: 7.1.3, same as the production sites)
#
# Credentials of the test accounts are written to dev/.credentials-<env> (git-ignored).
set -euo pipefail

ENV_NAME="${RELECTURE_ENV:-dev}"
PORT="${RELECTURE_PORT:-8090}"
CORE_VERSION="${WP_CORE_VERSION:-7.1.3}"
CLI="relecture_${ENV_NAME}_cli"
HERE="$(cd "$(dirname "$0")" && pwd)"
CREDENTIALS="$HERE/.credentials-$ENV_NAME"

wp() { podman exec "$CLI" wp "$@"; }
random_password() { head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c1-20; }

echo "· waiting for the database and wp-config.php…"
for _ in $(seq 1 90); do
    if podman exec "$CLI" test -f /var/www/html/wp-config.php \
        && podman exec "$CLI" php -r 'exit( @mysqli_connect( "db", "relecture", "relecture", "relecture" ) ? 0 : 1 );' 2>/dev/null; then
        break
    fi
    sleep 2
done

touch "$CREDENTIALS" && chmod 600 "$CREDENTIALS"

current="$(wp core version)"
if [ "$current" != "$CORE_VERSION" ]; then
    echo "· WordPress $current → $CORE_VERSION"
    if wp core is-installed 2>/dev/null; then
        wp core update --version="$CORE_VERSION" --force
        wp core update-db
    else
        # Not installed yet: put the wanted files in place first, the database is then
        # created by that very version.
        wp core download --version="$CORE_VERSION" --force --skip-content
    fi
fi

if ! wp core is-installed 2>/dev/null; then
    admin_pass="$(random_password)"
    wp core install --url="http://localhost:$PORT" --title="Relecture dev" \
        --admin_user=admin --admin_password="$admin_pass" \
        --admin_email=admin@example.test --skip-email
    echo "admin $admin_pass" >> "$CREDENTIALS"
fi

wp language core install fr_FR --activate >/dev/null 2>&1 || echo "  (fr_FR language pack unavailable: is the network down?)" >&2
wp rewrite structure '/%postname%/' --hard >/dev/null

for role in editor author contributor; do
    if ! wp user get "$role" --field=ID >/dev/null 2>&1; then
        pass="$(random_password)"
        wp user create "$role" "$role@example.test" --role="$role" --user_pass="$pass" --display_name="Test $role" >/dev/null
        echo "$role $pass" >> "$CREDENTIALS"
    fi
done

wp plugin activate relecture
wp eval-file wp-content/plugins/relecture/dev/seed.php

echo
echo "✔ http://localhost:$PORT/wp-admin/admin.php?page=relecture"
echo "  accounts (user password): $CREDENTIALS"
