<?php
/**
 * PHPUnit bootstrap for the Kounselia mu-plugins.
 *
 * Boots the real WordPress core test framework (see
 * bin/install-wp-tests.sh, which fetches it into a throwaway location
 * — WP_TESTS_DIR/WP_CORE_DIR below — this repo never commits WordPress
 * core itself, same as the live site does not) against a throwaway
 * test database, then loads our own mu-plugin loader the same way
 * WordPress would on a real request: on the muplugins_loaded hook.
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
    $_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
    fwrite( STDERR, "Could not find {$_tests_dir}/includes/functions.php — run bin/install-wp-tests.sh first (see .github/workflows/tests.yml for the exact invocation).\n" );
    exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

/**
 * Load Kounselia's mu-plugin loader the way a real request would —
 * every include/*.php file it requires registers its own hooks, so
 * this is the one place a test suite needs to hook in, not each file
 * individually.
 */
function _kounselia_load_mu_plugins() {
    require dirname( __DIR__ ) . '/portal/wp-content/mu-plugins/kounselia-core.php';
}
tests_add_filter( 'muplugins_loaded', '_kounselia_load_mu_plugins' );

require "{$_tests_dir}/includes/bootstrap.php";
