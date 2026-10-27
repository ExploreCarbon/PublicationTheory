<?php
namespace SPW;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side status transitions and guards for covered post types.
 */
final class Workflow {

	/**
	 * True while send_back() is changing status.
	 *
	 * @var bool
	 */
	public static $sending_back = false;

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'guard' ), 10, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
	}

	/**
	 * Revert disallowed status changes. Never dies; no current user means no guard.
	 *
	 * @param array $data    Slashed, sanitized post data.
	 * @param array $postarr Raw post array.
	 */
	public static function guard( $data, $postarr ): array {
		$data  = (array) $data;
		$user  = get_current_user_id();
		$type  = (string) ( $data['post_type'] ?? '' );
		$new   = (string) ( $data['post_status'] ?? '' );
		$id    = (int) ( $postarr['ID'] ?? 0 );
		$old   = $id ? get_post( $id ) : null;
		$prior = $old ? $old->post_status : '';

		if ( ! Types::is_covered( $type ) ) {
			return $data;
		}

		// A staged copy never becomes public, whoever asks (approve merges it into the live post instead).
		if ( $id && Staging::is_staged( $id ) && in_array( $new, array( 'publish', 'future', 'private' ), true ) ) {
			$data['post_status'] = 'pending';
			return $data;
		}

		if ( $user <= 0 ) {
			return $data;
		}

		if ( in_array( $new, array( 'publish', 'future', 'private' ), true ) && ! Caps::can_publish( $user, $type ) ) {
			$data['post_status'] = ( $old && ! in_array( $prior, array( 'auto-draft', 'trash' ), true ) ) ? $prior : 'pending';
			return $data;
		}

		if ( $old && 'pending' === $prior && 'draft' === $new && ! self::$sending_back && (int) $old->post_author !== $user ) {
			$data['post_status'] = 'pending';
		}
		return $data;
	}

	/**
	 * Send notifications on entry to pending / publish.
	 *
	 * @param string   $new  New status.
	 * @param string   $old  Old status.
	 * @param \WP_Post $post Post.
	 */
	public static function on_transition( $new, $old, $post ): void {
		if ( $new === $old || ! $post instanceof \WP_Post || ! Types::is_covered( $post->post_type ) ) {
			return;
		}
		$user   = get_current_user_id();
		$staged = Staging::is_staged( (int) $post->ID );
		if ( 'pending' === $new && $user > 0 && ! Caps::can_publish( $user, $post->post_type ) ) {
			Mailer::notify( $staged ? 'staged_submitted' : 'submitted', $post->ID, $user );
		} elseif ( 'publish' === $new && ! $staged ) {
			Mailer::notify( 'published', $post->ID, $user );
		}
	}

	/**
	 * Editor sends a pending post back to draft with feedback.
	 *
	 * @return true|\WP_Error
	 */
	public static function send_back( int $post_id, string $note ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'spw_not_found', __( 'Post not found.', 'spw' ), array( 'status' => 404 ) );
		}
		$object = get_post_type_object( $post->post_type );
		$cap    = $object ? $object->cap->edit_others_posts : 'edit_others_posts';
		if ( ! current_user_can( $cap ) ) {
			return new \WP_Error( 'spw_forbidden', __( 'You cannot send this back.', 'spw' ), array( 'status' => 403 ) );
		}
		if ( ! Types::is_covered( $post->post_type ) ) {
			return new \WP_Error( 'spw_not_covered', __( 'This content type is not covered by the workflow.', 'spw' ), array( 'status' => 400 ) );
		}
		if ( 'pending' !== $post->post_status ) {
			return new \WP_Error( 'spw_not_pending', __( 'Only pending posts can be sent back.', 'spw' ), array( 'status' => 400 ) );
		}

		$user     = get_current_user_id();
		$feedback = Feedback::add( $post_id, $user, $note );
		if ( is_wp_error( $feedback ) ) {
			$feedback->add_data( array( 'status' => 400 ) );
			return $feedback;
		}

		self::$sending_back = true;
		try {
			$result = wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'draft',
				),
				true
			);
		} finally {
			self::$sending_back = false;
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Mailer::notify( 'sent_back', $post_id, $user );
		return true;
	}

	/**
	 * Author submits a draft for review.
	 *
	 * @return true|\WP_Error
	 */
	public static function submit( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'spw_not_found', __( 'Post not found.', 'spw' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'spw_forbidden', __( 'You cannot submit this post.', 'spw' ), array( 'status' => 403 ) );
		}
		if ( ! Types::is_covered( $post->post_type ) ) {
			return new \WP_Error( 'spw_not_covered', __( 'This content type is not covered by the workflow.', 'spw' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $post->post_status, array( 'draft', 'auto-draft' ), true ) ) {
			return new \WP_Error( 'spw_not_draft', __( 'Only drafts can be submitted.', 'spw' ), array( 'status' => 400 ) );
		}

		$result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'pending',
			),
			true
		);
		return is_wp_error( $result ) ? $result : true;
	}
}
