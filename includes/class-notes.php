<?php
namespace SPW;

defined( 'ABSPATH' ) || exit;

/**
 * Enables core Notes (WP 7.1+ block comments, comment_type 'note') on covered post types.
 *
 * Core gate: a post type supports Notes when its `editor` support carries an arg with
 * `notes => true` (REST: WP_REST_Comments_Controller::check_post_type_supports_notes;
 * editor JS: post-type support check on `editor.notes`). Core sets it only for post and page.
 * Capability: creating or reading a note requires `edit_post` on the target post, so Authors and
 * Editors can note anything they can edit (their own drafts and staged copies), nothing more.
 */
final class Notes {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		// After all post types are registered (default priority 10).
		add_action( 'init', array( __CLASS__, 'enable' ), 99 );
	}

	/**
	 * Add `editor => [ notes => true ]` support to covered types that have the editor.
	 */
	public static function enable(): void {
		foreach ( Types::covered() as $type ) {
			$supports = get_all_post_type_supports( $type );
			if ( empty( $supports['editor'] ) ) {
				continue; // No editor: Notes are meaningless.
			}
			$args = is_array( $supports['editor'] ) ? $supports['editor'] : array();
			foreach ( $args as $item ) {
				if ( is_array( $item ) && ! empty( $item['notes'] ) ) {
					continue 2;
				}
			}
			// Notes item first: the editor reads `supports.editor[0].notes`.
			array_unshift( $args, array( 'notes' => true ) );
			add_post_type_support( $type, 'editor', ...$args );
		}
	}
}
