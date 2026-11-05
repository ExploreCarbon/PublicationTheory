<?php
namespace PublicationTheory;

defined( 'ABSPATH' ) || exit;

/**
 * Review queue: top-level admin screen listing pending items.
 */
final class Queue {

	public const SLUG = 'pt-review-queue';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
	}

	/**
	 * Count pending posts of covered types (staged copies included, as they are pending posts).
	 */
	public static function pending_count(): int {
		$types = Types::covered();
		if ( ! $types ) {
			return 0;
		}
		$total = 0;
		foreach ( $types as $type ) {
			$total += (int) ( wp_count_posts( $type )->pending ?? 0 );
		}
		return $total;
	}

	/**
	 * Kind of queue item (New by default; filterable so staged edits can say so).
	 */
	public static function kind( int $post_id ): string {
		$kind = apply_filters( 'pt_queue_item_kind', __( 'New', 'publication-theory' ), $post_id );
		return (string) $kind;
	}

	/**
	 * Add the top-level menu. No capability, no menu.
	 */
	public static function add_menu(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		$count = self::pending_count();
		$label = esc_html__( 'Review Queue', 'publication-theory' );
		if ( $count > 0 ) {
			$label .= sprintf(
				' <span class="update-plugins count-%1$d"><span class="plugin-count" aria-hidden="true">%1$d</span><span class="screen-reader-text">%2$s</span></span>',
				$count,
				esc_html(
					sprintf(
						/* translators: %d: number of items */
						_n( '%d item pending review', '%d items pending review', $count, 'publication-theory' ),
						$count
					)
				)
			);
		}
		add_menu_page(
			__( 'Review Queue', 'publication-theory' ),
			$label,
			'edit_others_posts',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-yes-alt',
			26
		);
	}

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'publication-theory' ), '', array( 'response' => 403 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		$table = new Queue_Table();
		$table->prepare_items();
		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Review Queue', 'publication-theory' ) . '</h1><hr class="wp-header-end" />';
		echo '<style>.pt-send-back summary{cursor:pointer;display:inline-block;min-height:24px;line-height:24px}.row-actions:has(.pt-send-back[open]){position:static}</style>';
		self::render_notice();
		$table->display();
		echo '</div>';
	}

	/**
	 * Result notice from admin-post redirect. Message text comes only from an allowlist.
	 */
	public static function render_notice(): void {
		$notice = isset( $_GET['pt_notice'] ) ? sanitize_key( wp_unslash( $_GET['pt_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'approved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible" role="status"><p>' . esc_html__( 'Staged changes approved and merged into the live item.', 'publication-theory' ) . '</p></div>';
			return;
		}
		if ( 'discarded' === $notice ) {
			echo '<div class="notice notice-success is-dismissible" role="status"><p>' . esc_html__( 'Staged copy discarded. The live item is unchanged.', 'publication-theory' ) . '</p></div>';
			return;
		}
		if ( 'sent' === $notice ) {
			echo '<div class="notice notice-success is-dismissible" role="status"><p>' . esc_html__( 'Sent back to the author.', 'publication-theory' ) . '</p></div>';
			return;
		}
		if ( 'error' !== $notice ) {
			return;
		}
		$code     = isset( $_GET['pt_code'] ) ? sanitize_key( wp_unslash( $_GET['pt_code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$messages = Admin_Post::error_messages();
		$message  = $messages[ $code ] ?? $messages['pt_failed'];
		echo '<div class="notice notice-error" role="alert"><p>' . esc_html( $message ) . '</p></div>';
	}
}
