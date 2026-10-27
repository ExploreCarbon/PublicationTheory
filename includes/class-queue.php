<?php
namespace SPW;

defined( 'ABSPATH' ) || exit;

/**
 * Review queue: top-level admin screen listing pending items.
 */
final class Queue {

	public const SLUG = 'spw-review-queue';

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
		$query = new \WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'pending',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Kind of queue item (New by default; filterable so staged edits can say so).
	 */
	public static function kind( int $post_id ): string {
		$kind = apply_filters( 'spw_queue_item_kind', __( 'New', 'spw' ), $post_id );
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
		$label = esc_html__( 'Review Queue', 'spw' );
		if ( $count > 0 ) {
			$label .= sprintf(
				' <span class="update-plugins count-%1$d"><span class="plugin-count" aria-hidden="true">%1$d</span><span class="screen-reader-text">%2$s</span></span>',
				$count,
				esc_html(
					sprintf(
						/* translators: %d: number of items */
						__( '%d items pending review', 'spw' ),
						$count
					)
				)
			);
		}
		add_menu_page(
			__( 'Review Queue', 'spw' ),
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
			wp_die( esc_html__( 'You do not have permission to view this page.', 'spw' ), '', array( 'response' => 403 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		$table = new Queue_Table();
		$table->prepare_items();
		echo '<div class="wrap"><h1>' . esc_html__( 'Review Queue', 'spw' ) . '</h1>';
		$table->display();
		echo '</div>';
	}
}
