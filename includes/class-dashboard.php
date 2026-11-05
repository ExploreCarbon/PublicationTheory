<?php
namespace PublicationTheory;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard widgets: review queue summary, my submissions, recent notifications.
 */
final class Dashboard {

	private const LIMIT     = 5;
	private const LOG_LIMIT = 10;

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_widgets' ) );
	}

	/**
	 * Add widgets the current user may use.
	 */
	public static function add_widgets(): void {
		if ( ! Types::covered() ) {
			return;
		}
		if ( current_user_can( 'edit_others_posts' ) ) {
			wp_add_dashboard_widget( 'pt_review_queue', __( 'Review Queue', 'publication-theory' ), array( __CLASS__, 'render_queue' ) );
		}
		if ( current_user_can( 'edit_posts' ) ) {
			wp_add_dashboard_widget( 'pt_my_submissions', __( 'My Submissions', 'publication-theory' ), array( __CLASS__, 'render_submissions' ) );
		}
		if ( current_user_can( 'manage_options' ) ) {
			wp_add_dashboard_widget( 'pt_recent_activity', __( 'Recent Workflow Notifications', 'publication-theory' ), array( __CLASS__, 'render_activity' ) );
		}
	}

	/**
	 * Covered-type query that includes staged copies.
	 */
	private static function query( array $args ): \WP_Query {
		return new \WP_Query(
			array_merge(
				array(
					'post_type'          => Types::covered(),
					'pt_include_staged' => true,
				),
				$args
			)
		);
	}

	/**
	 * Number of pending staged copies.
	 */
	public static function staged_pending_count(): int {
		$query = self::query(
			array(
				'post_status'    => 'pending',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => Staging::META,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Review Queue widget.
	 */
	public static function render_queue(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		$pending = Queue::pending_count();
		if ( 0 === $pending ) {
			echo '<p>' . esc_html__( 'Nothing is waiting for review.', 'publication-theory' ) . '</p>';
			return;
		}
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: pending items, 2: staged edits among them */
				_n( '%1$d item waiting (%2$d staged).', '%1$d items waiting (%2$d staged).', $pending, 'publication-theory' ),
				$pending,
				self::staged_pending_count()
			)
		) . '</p>';

		$query = self::query(
			array(
				'post_status'    => 'pending',
				'posts_per_page' => self::LIMIT,
				'orderby'        => 'modified',
				'order'          => 'ASC',
			)
		);
		echo '<h3>' . esc_html__( 'Oldest first', 'publication-theory' ) . '</h3><ul>';
		foreach ( $query->posts as $post ) {
			$id     = (int) $post->ID;
			$title  = get_the_title( $post );
			$title  = '' !== $title ? $title : __( '(no title)', 'publication-theory' );
			$link   = Staging::is_staged( $id ) ? Staging::review_url( $id ) : get_edit_post_link( $id, 'raw' );
			$author = get_userdata( (int) $post->post_author );
			echo '<li>';
			if ( $link ) {
				echo '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>';
			} else {
				echo esc_html( $title );
			}
			echo ' <span>' . esc_html(
				sprintf(
					/* translators: 1: item kind, 2: author name, 3: time difference */
					__( '%1$s by %2$s, %3$s ago', 'publication-theory' ),
					Queue::kind( $id ),
					$author ? $author->display_name : __( 'unknown', 'publication-theory' ),
					self::age( $post )
				)
			) . '</span></li>';
		}
		echo '</ul>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . Queue::SLUG ) ) . '">' . esc_html__( 'Open the Review Queue', 'publication-theory' ) . '</a></p>';
	}

	/**
	 * My Submissions widget: the viewer's items in review, sent back, or staged.
	 */
	public static function render_submissions(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$query = self::query(
			array(
				'author'         => $user_id,
				'post_status'    => array( 'pending', 'draft' ),
				'posts_per_page' => 50,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$rows = array();
		foreach ( $query->posts as $post ) {
			$id       = (int) $post->ID;
			$staged   = Staging::is_staged( $id );
			$feedback = 'draft' === $post->post_status ? Feedback::latest( $id ) : null;
			if ( 'pending' === $post->post_status ) {
				$state = $staged ? __( 'Staged edit in review', 'publication-theory' ) : __( 'In review', 'publication-theory' );
			} elseif ( $feedback ) {
				$state = __( 'Sent back', 'publication-theory' );
			} elseif ( $staged ) {
				$state = __( 'Staged edit in progress', 'publication-theory' );
			} else {
				continue; // Plain drafts are not submissions.
			}
			$rows[] = array( $post, $state, $feedback );
		}
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Nothing submitted yet.', 'publication-theory' ) . '</p>';
			return;
		}
		echo '<ul>';
		foreach ( array_slice( $rows, 0, self::LIMIT ) as $row ) {
			list( $post, $state, $feedback ) = $row;
			$title = get_the_title( $post );
			$title = '' !== $title ? $title : __( '(no title)', 'publication-theory' );
			$link  = get_edit_post_link( $post, 'raw' );
			echo '<li>';
			if ( $link ) {
				echo '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>';
			} else {
				echo esc_html( $title );
			}
			echo ' <strong>' . esc_html( $state ) . '</strong>';
			if ( $feedback && ! empty( $feedback['note'] ) ) {
				echo '<br /><em>' . esc_html( wp_trim_words( (string) $feedback['note'], 30 ) ) . '</em>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Recent Workflow Notifications widget (admins).
	 */
	public static function render_activity(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$entries = array_slice( Log::entries(), 0, self::LOG_LIMIT );
		if ( ! $entries ) {
			echo '<p>' . esc_html__( 'No workflow events recorded yet.', 'publication-theory' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Recent workflow notifications, newest first', 'publication-theory' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Time (UTC)', 'publication-theory' ), __( 'Post', 'publication-theory' ), __( 'Event', 'publication-theory' ), __( 'Status', 'publication-theory' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $entries as $entry ) {
			$post_id = (int) ( $entry['post_id'] ?? 0 );
			$title   = $post_id ? get_the_title( $post_id ) : '';
			$label   = '' !== $title ? $title : '#' . $post_id;
			$link    = $post_id ? get_edit_post_link( $post_id, 'raw' ) : '';
			echo '<tr><td>' . esc_html( (string) ( $entry['time'] ?? '' ) ) . '</td><td>';
			echo $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label );
			echo '</td><td>' . esc_html( (string) ( $entry['event'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $entry['status'] ?? '' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><a href="' . esc_url( admin_url( 'tools.php?page=pt-workflow-log' ) ) . '">' . esc_html__( 'Open the Workflow Log', 'publication-theory' ) . '</a></p>';
	}

	/**
	 * Human-readable age of a post since last modification.
	 */
	private static function age( \WP_Post $post ): string {
		$gmt = '0000-00-00 00:00:00' === $post->post_modified_gmt ? 0 : (int) strtotime( $post->post_modified_gmt . ' UTC' );
		return $gmt > 0 ? human_time_diff( $gmt ) : '';
	}
}
