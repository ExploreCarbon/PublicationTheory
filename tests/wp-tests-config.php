<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) { exit; }
$db_name = trim( (string) ( getenv( 'SPW_TEST_DB_NAME' ) ?: 'spw_test' ) );
if ( ! preg_match( '/^spw_test/i', $db_name ) ) {
	fwrite( STDERR, "Refusing to run: test DB name must start with 'spw_test' (got '$db_name').\n" );
	exit( 1 );
}
$spw_abs = getenv( 'SPW_WP_ABSPATH' );
if ( ! $spw_abs ) {
	// Walk up until a WP core (wp-settings.php) is found; worktrees live inside the site.
	$spw_dir = __DIR__;
	while ( dirname( $spw_dir ) !== $spw_dir && ! file_exists( $spw_dir . '/wp-settings.php' ) ) {
		$spw_dir = dirname( $spw_dir );
	}
	$spw_abs = $spw_dir;
}
define( 'ABSPATH', rtrim( str_replace( '\\', '/', $spw_abs ), '/' ) . '/' );
define( 'WP_DEBUG', true );
define( 'DB_NAME', $db_name );
define( 'DB_USER', getenv( 'SPW_TEST_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'SPW_TEST_DB_PASSWORD' ) ?: 'root' );
define( 'DB_HOST', getenv( 'SPW_TEST_DB_HOST' ) ?: '127.0.0.1:10096' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
$table_prefix  = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
