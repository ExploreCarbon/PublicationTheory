<?php
namespace SPW;

defined( 'ABSPATH' ) || exit;

/**
 * Block editor sidebar panel assets.
 */
final class Editor_Assets {

	public const HANDLE = 'spw-workflow';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue the panel script, only on edit screens of covered post types.
	 */
	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$type   = $screen && ! empty( $screen->post_type ) ? (string) $screen->post_type : '';
		if ( '' === $type || ! Types::is_covered( $type ) ) {
			return;
		}

		$asset_file = SPW_DIR . 'build/index.asset.php';
		$asset      = is_readable( $asset_file ) ? require $asset_file : array();
		$deps       = $asset['dependencies'] ?? array( 'wp-api-fetch', 'wp-components', 'wp-data', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins', 'wp-a11y', 'wp-notices' );
		$version    = $asset['version'] ?? SPW_VERSION;

		wp_enqueue_script( self::HANDLE, SPW_URL . 'build/index.js', $deps, $version, true );
		wp_set_script_translations( self::HANDLE, 'spw' );

		$config = array(
			'canPublish' => Caps::can_publish( get_current_user_id(), $type ),
			'postType'   => $type,
		);
		wp_add_inline_script( self::HANDLE, 'window.spwWorkflow = ' . wp_json_encode( $config ) . ';', 'before' );
	}
}
