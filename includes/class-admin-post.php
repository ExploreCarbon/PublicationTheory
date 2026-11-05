<?php
namespace PublicationTheory;

defined( 'ABSPATH' ) || exit;

/**
 * admin-post.php handlers (no-JS send-back from the Review Queue).
 */
final class Admin_Post {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_post_pt_send_back', array( __CLASS__, 'handle_send_back' ) );
	}

	/**
	 * Allowlisted error codes and their messages.
	 *
	 * @return array<string,string>
	 */
	public static function error_messages(): array {
		return array(
			'pt_forbidden'   => __( 'You do not have permission to send this back.', 'publication-theory' ),
			'pt_not_pending' => __( 'Only pending items can be sent back.', 'publication-theory' ),
			'pt_empty_note'  => __( 'Feedback note cannot be empty.', 'publication-theory' ),
			'pt_not_found'   => __( 'Item not found.', 'publication-theory' ),
			'pt_not_covered' => __( 'This content type is not covered by the workflow.', 'publication-theory' ),
			'pt_not_staged'  => __( 'This is not a staged edit.', 'publication-theory' ),
			'pt_live_missing' => __( 'The live item no longer exists.', 'publication-theory' ),
			'pt_notes_move_failed' => __( 'Notes could not be moved to the live item, so nothing was deleted. Try approving again.', 'publication-theory' ),
			'pt_feedback_move_failed' => __( 'Feedback could not be moved to the live item, so nothing was deleted. Try approving again.', 'publication-theory' ),
			'pt_failed'      => __( 'Could not send this back.', 'publication-theory' ),
		);
	}

	/**
	 * Handle the send-back form post, then redirect to the queue.
	 */
	public static function handle_send_back(): void {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- verified next line.
		check_admin_referer( 'pt_send_back_' . $post_id );

		$note   = isset( $_POST['note'] ) ? (string) wp_unslash( $_POST['note'] ) : '';
		$result = Workflow::send_back( $post_id, $note );

		$args = array( 'page' => Queue::SLUG );
		if ( is_wp_error( $result ) ) {
			$code               = (string) $result->get_error_code();
			$args['pt_notice'] = 'error';
			$args['pt_code']   = array_key_exists( $code, self::error_messages() ) ? $code : 'pt_failed';
		} else {
			$args['pt_notice'] = 'sent';
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
