<?php
namespace SPW;

defined( 'ABSPATH' ) || exit;

/**
 * admin-post.php handlers (no-JS send-back from the Review Queue).
 */
final class Admin_Post {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_post_spw_send_back', array( __CLASS__, 'handle_send_back' ) );
	}

	/**
	 * Allowlisted error codes and their messages.
	 *
	 * @return array<string,string>
	 */
	public static function error_messages(): array {
		return array(
			'spw_forbidden'   => __( 'You do not have permission to send this back.', 'spw' ),
			'spw_not_pending' => __( 'Only pending items can be sent back.', 'spw' ),
			'spw_empty_note'  => __( 'Feedback note cannot be empty.', 'spw' ),
			'spw_not_found'   => __( 'Item not found.', 'spw' ),
			'spw_not_covered' => __( 'This content type is not covered by the workflow.', 'spw' ),
			'spw_not_staged'  => __( 'This is not a staged edit.', 'spw' ),
			'spw_live_missing' => __( 'The live item no longer exists.', 'spw' ),
			'spw_notes_move_failed' => __( 'Notes could not be moved to the live item, so nothing was deleted. Try approving again.', 'spw' ),
			'spw_failed'      => __( 'Could not send this back.', 'spw' ),
		);
	}

	/**
	 * Handle the send-back form post, then redirect to the queue.
	 */
	public static function handle_send_back(): void {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- verified next line.
		check_admin_referer( 'spw_send_back_' . $post_id );

		$note   = isset( $_POST['note'] ) ? (string) wp_unslash( $_POST['note'] ) : '';
		$result = Workflow::send_back( $post_id, $note );

		$args = array( 'page' => Queue::SLUG );
		if ( is_wp_error( $result ) ) {
			$code               = (string) $result->get_error_code();
			$args['spw_notice'] = 'error';
			$args['spw_code']   = array_key_exists( $code, self::error_messages() ) ? $code : 'spw_failed';
		} else {
			$args['spw_notice'] = 'sent';
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
