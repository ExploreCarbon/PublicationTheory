<?php
namespace SPW;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap. Later tasks register each component in boot().
 */
final class Plugin {

	/**
	 * Instantiate and register all components.
	 */
	public static function boot(): void {
		Caps::register();
		Mailer::register();
		Log_Screen::register();
	}
}
