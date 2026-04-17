<?php
/**
 * PHPUnit bootstrap for User Groups tests.
 *
 * Works with wp-env:  npm run test / npm run test:multisite
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php – is WP_TESTS_DIR set?\n";
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', function () {
	require dirname( __DIR__ ) . '/wp-user-groups.php';
} );

require $_tests_dir . '/includes/bootstrap.php';
