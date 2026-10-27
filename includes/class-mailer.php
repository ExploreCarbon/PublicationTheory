<?php
namespace SPW;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-text workflow notification emails.
 */
final class Mailer {

	/**
	 * Context of the wp_mail call in progress, for the failure handler.
	 *
	 * @var array|null
	 */
	private static $context = null;

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'wp_mail_failed', array( __CLASS__, 'on_mail_failed' ) );
	}

	/**
	 * Send notifications for a workflow event.
	 *
	 * @param string $event    submitted|sent_back|published|staged_submitted|staged_approved.
	 * @param int    $post_id  Post (or staged copy) ID.
	 * @param int    $actor_id User who triggered the event.
	 */
	public static function notify( string $event, int $post_id, int $actor_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$recipients = self::recipients( $event, $post, $actor_id );
		/**
		 * Filter notification recipient user IDs.
		 *
		 * @param int[]  $user_ids Recipient IDs.
		 * @param string $event    Event.
		 * @param int    $post_id  Post ID.
		 */
		$recipients = array_values( array_unique( array_map( 'intval', (array) apply_filters( 'spw_email_recipients', $recipients, $event, $post_id ) ) ) );

		$users = array();
		foreach ( $recipients as $user_id ) {
			if ( $user_id <= 0 ) {
				continue;
			}
			$user = get_userdata( $user_id );
			if ( $user && '' !== (string) $user->user_email ) {
				$users[] = $user;
			}
		}

		if ( ! $users ) {
			Log::record( $post_id, $event, 'no_recipients', $recipients ? 'no resolvable recipients' : '' );
			return;
		}

		$message = self::message( $event, $post, $actor_id );
		/**
		 * Filter the email message.
		 *
		 * @param array  $message  {subject, body}.
		 * @param string $event    Event.
		 * @param int    $post_id  Post ID.
		 */
		$message = (array) apply_filters( 'spw_email_message', $message, $event, $post_id );
		$subject = (string) ( $message['subject'] ?? '' );
		$body    = (string) ( $message['body'] ?? '' );

		foreach ( $users as $user ) {
			$previous      = self::$context;
			self::$context = array(
				'post_id' => $post_id,
				'event'   => $event,
				'failed'  => false,
			);
			try {
				$ok     = wp_mail( $user->user_email, $subject, $body );
				$failed = self::$context['failed'];
			} finally {
				self::$context = $previous;
			}
			if ( $ok ) {
				Log::record( $post_id, $event, 'sent', $user->user_email );
			} elseif ( ! $failed ) {
				Log::record( $post_id, $event, 'failed', $user->user_email );
			}
		}
	}

	/**
	 * wp_mail_failed handler.
	 *
	 * @param \WP_Error $error Error.
	 */
	public static function on_mail_failed( $error ): void {
		if ( ! self::$context ) {
			return;
		}
		self::$context['failed'] = true;
		$detail                  = $error instanceof \WP_Error ? $error->get_error_message() : '';
		Log::record( self::$context['post_id'], self::$context['event'], 'failed', $detail );
	}

	/**
	 * Default recipient IDs for an event.
	 *
	 * @return int[]
	 */
	private static function recipients( string $event, \WP_Post $post, int $actor_id ): array {
		if ( in_array( $event, array( 'submitted', 'staged_submitted' ), true ) ) {
			$ids = get_users(
				array(
					'capability' => 'edit_others_posts',
					'fields'     => 'ID',
				)
			);
		} else {
			$ids = array( (int) $post->post_author );
		}
		$ids = array_map( 'intval', $ids );
		return array_values( array_diff( $ids, array( $actor_id ) ) );
	}

	/**
	 * Build subject and body.
	 */
	private static function message( string $event, \WP_Post $post, int $actor_id ): array {
		$labels = array(
			'submitted'        => __( 'Review requested', 'spw' ),
			'sent_back'        => __( 'Changes requested', 'spw' ),
			'published'        => __( 'Published', 'spw' ),
			'staged_submitted' => __( 'Update review requested', 'spw' ),
			'staged_approved'  => __( 'Update approved', 'spw' ),
		);
		$label = $labels[ $event ] ?? $event;
		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );
		$actor = get_userdata( $actor_id );
		$name  = $actor ? $actor->display_name : __( 'Someone', 'spw' );
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$lines = array(
			sprintf( '%1$s: %2$s', $label, $title ),
			/* translators: %s: user display name */
			sprintf( __( 'By: %s', 'spw' ), $name ),
		);

		if ( 'sent_back' === $event ) {
			$latest = Feedback::latest( $post->ID );
			if ( $latest ) {
				$lines[] = '';
				$lines[] = __( 'Editor feedback:', 'spw' );
				$lines[] = (string) $latest['note'];
			}
		}

		// get_edit_post_link() is null when the current user cannot edit the post.
		$link = get_edit_post_link( $post->ID, 'raw' );
		if ( ! $link ) {
			$link = admin_url( 'post.php?post=' . $post->ID . '&action=edit' );
		}
		if ( $link ) {
			$lines[] = '';
			/* translators: %s: edit URL */
			$lines[] = sprintf( __( 'Edit: %s', 'spw' ), $link );
		}

		return array(
			'subject' => sprintf( '[%1$s] %2$s: %3$s', $site, $label, $title ),
			'body'    => implode( "\n", $lines ) . "\n",
		);
	}
}
