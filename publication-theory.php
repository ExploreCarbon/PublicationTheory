<?php
/**
 * Plugin Name: PublicationTheory
 * Description: Editorial review, staging and publication workflow for WordPress.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.5
 * Text Domain: publication-theory
 *
 * @package PublicationTheory
 */

defined( 'ABSPATH' ) || exit;

define( 'PT_VERSION', '0.1.0' );
define( 'PT_DIR', plugin_dir_path( __FILE__ ) );
define( 'PT_URL', plugin_dir_url( __FILE__ ) );

/**
 * Classmap-style autoloader: PublicationTheory\Foo_Bar -> includes/class-foo-bar.php.
 */
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'PublicationTheory\\' ) ) {
			return;
		}
		$name = strtolower( str_replace( '_', '-', substr( $class, strlen( 'PublicationTheory\\' ) ) ) );
		$file = PT_DIR . 'includes/class-' . str_replace( '\\', '/', $name ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

add_action( 'plugins_loaded', array( 'PublicationTheory\\Migrate', 'maybe_run' ), 1 );
add_action( 'plugins_loaded', array( 'PublicationTheory\\Plugin', 'boot' ) );
