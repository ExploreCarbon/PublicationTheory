<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) { exit; }
/**
 * PHPUnit bootstrap. Uses dedicated DB `pt_test` (never the site's `local` DB).
 */
$db_name = trim( (string) ( getenv( 'PT_TEST_DB_NAME' ) ?: 'pt_test' ) );
if ( ! preg_match( '/^pt_test/i', $db_name ) ) {
	fwrite( STDERR, "Refusing to run: test DB name must start with 'pt_test' (got '$db_name').\n" );
	exit( 1 );
}

$plugin_dir = dirname( __DIR__ );
$wp_dir     = $plugin_dir . '/vendor/wp-phpunit/wp-phpunit';

define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
$_ENV['PT_TEST_DB_NAME'] = $db_name;
putenv( 'PT_TEST_DB_NAME=' . $db_name );

require_once $plugin_dir . '/vendor/autoload.php';

$tests_dir = getenv( 'WP_PHPUNIT__DIR' ) ?: $wp_dir;
require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $plugin_dir ) {
		require $plugin_dir . '/publication-theory.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';
