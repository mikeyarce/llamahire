#!/usr/bin/env bash
# Verify the atomic submission contract on an isolated SQLite WordPress site.
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_DIR="$(mktemp -d)"
trap 'rm -rf "$TEST_DIR"' EXIT
mkdir -p "$TEST_DIR/site"
# Pin the minimum WordPress version by default; never copy existing site data.
curl --fail --silent --show-error --location "https://wordpress.org/wordpress-${WP_SQLITE_CORE_VERSION:-6.5}.zip" -o "$TEST_DIR/core.zip"
unzip -q "$TEST_DIR/core.zip" -d "$TEST_DIR"
cp -a "$TEST_DIR/wordpress/." "$TEST_DIR/site/"
curl --fail --silent --show-error --location https://downloads.wordpress.org/plugin/sqlite-database-integration.3.0.2.zip -o "$TEST_DIR/sqlite.zip"
unzip -q "$TEST_DIR/sqlite.zip" -d "$TEST_DIR/site/wp-content/plugins"
cp "$TEST_DIR/site/wp-content/plugins/sqlite-database-integration/db.copy" "$TEST_DIR/site/wp-content/db.php"
CLI=(docker run --rm -i --user "$(id -u):$(id -g)" --env WP_CLI_CACHE_DIR=/tmp/wp-cli --workdir /var/www/html --volume "$TEST_DIR/site:/var/www/html" --volume "$ROOT_DIR:/var/www/html/wp-content/plugins/llamahire:ro" --entrypoint wp wordpress:cli-php8.3)
"${CLI[@]}" config create --dbname=sqlite --dbuser=unused --dbpass=unused --skip-check --extra-php <<'PHP'
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
PHP
"${CLI[@]}" core install --url=http://localhost --title=AtomicSQLiteFixture --admin_user=fixture --admin_password=Fictional-local-fixture-42 --admin_email=fixture@example.test --skip-email
"${CLI[@]}" plugin activate llamahire
"${CLI[@]}" eval-file wp-content/plugins/llamahire/tests/atomic-submissions.php
