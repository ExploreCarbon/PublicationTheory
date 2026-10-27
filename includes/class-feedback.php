<?php
namespace SPW;

defined( 'ABSPATH' ) || exit;

/**
 * Editor feedback history, stored as repeated post meta.
 */
final class Feedback {

	public const META_KEY = '_spw_feedback';

	/**
	 * Append a feedback entry.
	 *
	 * @return int|\WP_Error Meta ID of the stored entry, or error.
	 */
	public static function add( int $post_id, int $editor_id, string $note ) {
		$note = trim( sanitize_textarea_field( $note ) );
		if ( '' === $note ) {
			return new \WP_Error( 'spw_empty_note', __( 'Feedback note cannot be empty.', 'spw' ) );
		}

		$entry = array(
			'id'        => wp_generate_uuid4(),
			'editor_id' => $editor_id,
			'note'      => $note,
			'time'      => current_time( 'mysql', true ),
		);

		// wp_slash: add_post_meta unslashes its input.
		$meta_id = add_post_meta( $post_id, self::META_KEY, wp_slash( $entry ) );
		if ( ! $meta_id ) {
			return new \WP_Error( 'spw_feedback_failed', __( 'Could not save feedback.', 'spw' ) );
		}
		return (int) $meta_id;
	}

	/**
	 * All entries, newest first.
	 */
	public static function history( int $post_id ): array {
		$entries = get_post_meta( $post_id, self::META_KEY );
		if ( ! is_array( $entries ) ) {
			return array();
		}
		return array_reverse( array_values( array_filter( $entries, 'is_array' ) ) );
	}

	/**
	 * Most recent entry, or null.
	 */
	public static function latest( int $post_id ): ?array {
		$history = self::history( $post_id );
		return $history[0] ?? null;
	}
}
