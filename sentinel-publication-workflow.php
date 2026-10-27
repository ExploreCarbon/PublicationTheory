<?php
/**
 * Plugin Name: Sentinel Publication Workflow
 * Description: Publication workflow for Sentinel Group.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.5
 * Text Domain: spw
 *
 * @package SPW
 */

defined( 'ABSPATH' ) || exit;

define( 'SPW_VERSION', '0.1.0' );
define( 'SPW_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPW_URL', plugin_dir_url( __FILE__ ) );

/**
 * Classmap-style autoloader: SPW\Foo_Bar -> includes/class-foo-bar.php.
 */
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'SPW\\' ) ) {
			return;
		}
		$name = strtolower( str_replace( '_', '-', substr( $class, 4 ) ) );
		$file = SPW_DIR . 'includes/class-' . str_replace( '\\', '/', $name ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

add_action( 'plugins_loaded', array( 'SPW\\Plugin', 'boot' ) );
