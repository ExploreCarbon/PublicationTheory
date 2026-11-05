<?php
namespace PublicationTheory;

defined( 'ABSPATH' ) || exit;

/**
 * Editor feedback history, stored as repeated post meta.
 */
final class Feedback {

	public const META_KEY = '_pt_feedback';

	/**
	 * Append a feedback entry.
	 *
	 * @return int|\WP_Error Meta ID of the stored entry, or error.
	 */
	public static function add( int $post_id, int $editor_id, string $note ) {
		$note = trim( sanitize_textarea_field( $note ) );
		if ( '' === $note ) {
			return new \WP_Error( 'pt_empty_note', __( 'Feedback note cannot be empty.', 'publication-theory' ) );
		}
		return self::store( $post_id, $editor_id, $note );
	}

	/**
	 * Record an automatic (system-written) entry, e.g. an Editor's plain "Switch to draft".
	 *
	 * @return int|\WP_Error
	 */
	public static function add_automatic( int $post_id, int $editor_id, string $note ) {
		return self::store( $post_id, $editor_id, trim( sanitize_textarea_field( $note ) ) );
	}

	/**
	 * Persist one entry.
	 *
	 * @return int|\WP_Error
	 */
	private static function store( int $post_id, int $editor_id, string $note ) {
		$entry = array(
			'id'        => wp_generate_uuid4(),
			'editor_id' => $editor_id,
			'note'      => $note,
			'time'      => current_time( 'mysql', true ),
		);

		// wp_slash: add_post_meta unslashes its input.
		$meta_id = add_post_meta( $post_id, self::META_KEY, wp_slash( $entry ) );
		if ( ! $meta_id ) {
			return new \WP_Error( 'pt_feedback_failed', __( 'Could not save feedback.', 'publication-theory' ) );
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
