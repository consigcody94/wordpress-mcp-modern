<?php
declare(strict_types=1);

// Prefer the WordPress test library provided by wp-env (WP_TESTS_DIR=/wordpress-phpunit,
// which ships a ready wp-tests-config.php wired to the tests DB). Fall back to the
// vendored wp-phpunit for non-wp-env runners.
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = getenv( 'WP_PHPUNIT__DIR' ) ?: dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/wordpress-mcp-modern.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';
