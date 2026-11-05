<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) { exit; }
$db_name = trim( (string) ( getenv( 'PT_TEST_DB_NAME' ) ?: 'pt_test' ) );
if ( ! preg_match( '/^pt_test/i', $db_name ) ) {
	fwrite( STDERR, "Refusing to run: test DB name must start with 'pt_test' (got '$db_name').\n" );
	exit( 1 );
}
$pt_abs = getenv( 'PT_WP_ABSPATH' );
if ( ! $pt_abs ) {
	// Walk up until a WP core (wp-settings.php) is found; worktrees live inside the site.
	$pt_dir = __DIR__;
	while ( dirname( $pt_dir ) !== $pt_dir && ! file_exists( $pt_dir . '/wp-settings.php' ) ) {
		$pt_dir = dirname( $pt_dir );
	}
	$pt_abs = $pt_dir;
}
define( 'ABSPATH', rtrim( str_replace( '\\', '/', $pt_abs ), '/' ) . '/' );
define( 'WP_DEBUG', true );
define( 'DB_NAME', $db_name );
define( 'DB_USER', getenv( 'PT_TEST_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'PT_TEST_DB_PASSWORD' ) ?: 'root' );
define( 'DB_HOST', getenv( 'PT_TEST_DB_HOST' ) ?: '127.0.0.1:10096' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
$table_prefix  = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
