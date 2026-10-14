#!/usr/bin/env bash
#
# Sets up a throwaway WordPress core checkout + the WP core test
# framework (WP_UnitTestCase and friends) and a throwaway test
# database, so `vendor/bin/phpunit` has something real to run against.
#
# Adapted from the script WordPress core itself has published for
# plugin/mu-plugin test suites for over a decade — nothing
# Kounselia-specific in here, simplified for Linux CI only (no macOS
# `sed -i` compatibility shim needed there). Safe to re-run; it reuses
# the cached checkout unless WP_TESTS_FORCE_DOWNLOAD=1 is set.
#
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version]"
	exit 1
fi

set -e

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}

WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress}
WP_CORE_DIR=$(echo "$WP_CORE_DIR" | sed 's:/\+$::') # strip any trailing slash

install_wp() {
	if [ -d "$WP_CORE_DIR" ] && [ "$WP_TESTS_FORCE_DOWNLOAD" != "1" ]; then
		return
	fi

	mkdir -p "$WP_CORE_DIR"

	if [ "$WP_VERSION" == "latest" ]; then
		ARCHIVE_NAME='latest'
	else
		ARCHIVE_NAME="wordpress-$WP_VERSION"
	fi

	ARCHIVE="/tmp/wordpress-$WP_VERSION.tar.gz"
	curl -s "https://wordpress.org/$ARCHIVE_NAME.tar.gz" > "$ARCHIVE"
	tar --strip-components=1 -zxmf "$ARCHIVE" -C "$WP_CORE_DIR"
	rm "$ARCHIVE"
}

# The WordPress test tools must come from the same version as WordPress itself.
# They used to come from "trunk" (the unreleased development version), which
# broke the tests as soon as trunk added something the released WordPress
# doesn't have yet. This works out the real version number (asking
# wordpress.org when WP_VERSION is "latest") so the matching tools are used.
resolve_wp_version() {
	if [ "$WP_VERSION" != "latest" ]; then
		RESOLVED_WP_VERSION="$WP_VERSION"
		return
	fi

	RESOLVED_WP_VERSION=$(curl -s https://api.wordpress.org/core/version-check/1.7/ \
		| grep -o '"version":"[0-9][0-9.]*"' | head -1 | sed 's/"version":"//; s/"//')

	if [ -z "$RESOLVED_WP_VERSION" ]; then
		echo "Could not work out the latest WordPress version; using trunk for the test tools." >&2
		RESOLVED_WP_VERSION="trunk"
	fi
}

install_test_suite() {
	if [ -d "$WP_TESTS_DIR" ] && [ "$WP_TESTS_FORCE_DOWNLOAD" != "1" ]; then
		return
	fi

	resolve_wp_version

	if [ "$RESOLVED_WP_VERSION" == "trunk" ]; then
		SVN_BASE="https://develop.svn.wordpress.org/trunk"
		CONFIG_REF="trunk"
	else
		SVN_BASE="https://develop.svn.wordpress.org/tags/$RESOLVED_WP_VERSION"
		CONFIG_REF="$RESOLVED_WP_VERSION"
	fi
	echo "Using the WordPress $RESOLVED_WP_VERSION test tools."

	mkdir -p "$WP_TESTS_DIR"
	svn export --quiet "$SVN_BASE/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
	svn export --quiet "$SVN_BASE/tests/phpunit/data/" "$WP_TESTS_DIR/data"

	if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
		curl -s "https://raw.githubusercontent.com/WordPress/wordpress-develop/$CONFIG_REF/wp-tests-config-sample.php" \
			> "$WP_TESTS_DIR/wp-tests-config.php"

		sed -i "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s|localhost|$DB_HOST|" "$WP_TESTS_DIR/wp-tests-config.php"
	fi
}

install_db() {
	# DB_HOST as "host:port" or "host:socket" — mysqladmin wants those split out.
	local DB_HOSTNAME="${DB_HOST%%:*}"
	local DB_SOCK_OR_PORT="${DB_HOST#*:}"
	local EXTRA=""

	if [ "$DB_HOSTNAME" == "$DB_HOST" ] || [ "$DB_HOSTNAME" == "localhost" ]; then
		EXTRA=""
	elif [[ "$DB_SOCK_OR_PORT" =~ ^[0-9]+$ ]]; then
		EXTRA=" --host=$DB_HOSTNAME --port=$DB_SOCK_OR_PORT --protocol=tcp"
	else
		EXTRA=" --host=$DB_HOSTNAME --socket=$DB_SOCK_OR_PORT"
	fi

	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" $EXTRA 2>/dev/null || true
}

install_wp
install_test_suite
install_db
