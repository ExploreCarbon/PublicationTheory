<?php
namespace PublicationTheory;

defined( 'ABSPATH' ) || exit;

/**
 * Block editor sidebar panel assets.
 */
final class Editor_Assets {

	public const HANDLE = 'pt-workflow';

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

		$dir        = (string) apply_filters( 'pt_build_dir', PT_DIR . 'build/' );
		$asset_file = $dir . 'index.asset.php';
		if ( ! is_readable( $asset_file ) || ! is_readable( $dir . 'index.js' ) ) {
			return;
		}
		$asset   = require $asset_file;
		$deps    = $asset['dependencies'] ?? array();
		$version = $asset['version'] ?? PT_VERSION;

		wp_enqueue_script( self::HANDLE, PT_URL . 'build/index.js', $deps, $version, true );
		wp_set_script_translations( self::HANDLE, 'publication-theory' );

		$config = array(
			'canPublish' => Caps::can_publish( get_current_user_id(), $type ),
			'postType'   => $type,
		);
		wp_add_inline_script( self::HANDLE, 'window.ptWorkflow = ' . wp_json_encode( $config ) . ';', 'before' );
	}
}
