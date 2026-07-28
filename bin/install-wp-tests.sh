#!/usr/bin/env bash
# =============================================================================
# bin/install-wp-tests.sh
#
# Downloads and configures the WordPress test suite for integration tests.
# Based on the official WP plugin unit tests scaffold.
#
# Usage:
#   bash bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
#
# Example:
#   bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
#
# Defaults:
#   db-host    = localhost
#   wp-version = latest
#
# After running this, set WP_TESTS_DIR in your environment:
#   export WP_TESTS_DIR=/tmp/wordpress-tests-lib
#
# Then run integration tests:
#   composer test:integration
# =============================================================================

set -e

DB_NAME=${1:-wordpress_test}
DB_USER=${2:-root}
DB_PASS=${3:-''}
DB_HOST=${4:-localhost}
WP_VERSION=${5:-latest}

WP_TESTS_DIR=${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-/tmp/wordpress}

# ── Resolve WP version ────────────────────────────────────────────────────────
if [[ "$WP_VERSION" == "latest" ]]; then
    WP_TESTS_TAG="trunk"
else
    WP_TESTS_TAG="tags/$WP_VERSION"
fi

# ── Download WP core (if not already present) ─────────────────────────────────
if [ ! -d "$WP_CORE_DIR/src" ]; then
    echo "Downloading WordPress core to $WP_CORE_DIR..."
    mkdir -p "$WP_CORE_DIR"

    if command -v svn &>/dev/null; then
        svn co --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/" "$WP_CORE_DIR"
    else
        echo "svn not found. Install subversion and re-run."
        exit 1
    fi
fi

# ── Download WP test suite ────────────────────────────────────────────────────
if [ ! -d "$WP_TESTS_DIR" ]; then
    echo "Downloading WordPress test suite to $WP_TESTS_DIR..."
    mkdir -p "$WP_TESTS_DIR"
    svn co --quiet \
        "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" \
        "$WP_TESTS_DIR/includes"
    svn co --quiet \
        "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" \
        "$WP_TESTS_DIR/data"
fi

# ── Create wp-tests-config.php ────────────────────────────────────────────────
if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
    echo "Creating wp-tests-config.php..."
    cat > "$WP_TESTS_DIR/wp-tests-config.php" <<PHP
<?php
define( 'ABSPATH', '${WP_CORE_DIR}/src/' );
define( 'WP_DEFAULT_THEME', 'default' );

define( 'DB_NAME',     '${DB_NAME}' );
define( 'DB_USER',     '${DB_USER}' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST',     '${DB_HOST}' );
define( 'DB_CHARSET',  'utf8' );
define( 'DB_COLLATE',  '' );

\$table_prefix = 'wptest_';

define( 'WP_TESTS_DOMAIN',         'example.org' );
define( 'WP_TESTS_EMAIL',          'admin@example.org' );
define( 'WP_TESTS_TITLE',          'Test Blog' );
define( 'WP_PHP_BINARY',           'php' );
define( 'WPLANG',                  '' );
define( 'WP_DEBUG',                true );
PHP
fi

# ── Create test database ──────────────────────────────────────────────────────
EXTRA=""
if [ -n "$DB_PASS" ]; then
    EXTRA="--password=$DB_PASS"
fi

if mysqladmin create "$DB_NAME" --user="$DB_USER" --host="$DB_HOST" "$EXTRA" 2>/dev/null; then
    echo "Created database: $DB_NAME"
else
    echo "Database $DB_NAME already exists (OK)."
fi

echo ""
echo "✓ WordPress test suite installed."
echo ""
echo "Now export the path and run tests:"
echo "  export WP_TESTS_DIR=$WP_TESTS_DIR"
echo "  composer test:integration"
