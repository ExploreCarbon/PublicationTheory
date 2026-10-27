<?php
namespace SPW;

defined( 'ABSPATH' ) || exit;

/**
 * Hidden admin screen to review a staged copy, plus the approve handler.
 */
final class Staging_Review {

	public const SLUG = 'spw-staged-review';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_spw_approve_staged', array( __CLASS__, 'handle_approve' ) );
	}

	/**
	 * Hidden page (no parent menu).
	 */
	public static function add_page(): void {
		add_submenu_page( '', __( 'Review staged changes', 'spw' ), '', 'edit_others_posts', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the review screen.
	 */
	public static function render(): void {
		$copy_id = isset( $_GET['copy'] ) ? absint( wp_unslash( $_GET['copy'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$copy    = $copy_id ? get_post( $copy_id ) : null;
		$object  = $copy ? get_post_type_object( $copy->post_type ) : null;
		if ( ! $copy || ! $object || ! current_user_can( $object->cap->edit_others_posts ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'spw' ), '', array( 'response' => 403 ) );
		}
		$live_id = Staging::live_id( $copy_id );
		$live    = $live_id ? get_post( $live_id ) : null;
		if ( ! $live ) {
			wp_die( esc_html__( 'This is not a staged edit.', 'spw' ), '', array( 'response' => 404 ) );
		}

		$title  = get_the_title( $live );
		$title  = '' !== $title ? $title : __( '(no title)', 'spw' );
		$author = get_userdata( (int) $copy->post_author );

		echo '<div class="wrap">';
		/* translators: %s: live post title */
		echo '<h1>' . esc_html( sprintf( __( 'Review staged changes: %s', 'spw' ), $title ) ) . '</h1>';
		Queue::render_notice();
		echo '<p>';
		/* translators: %s: author display name */
		echo esc_html( sprintf( __( 'Proposed by: %s', 'spw' ), $author ? $author->display_name : __( 'Unknown', 'spw' ) ) );
		echo ' &middot; <a href="' . esc_url( get_permalink( $live ) ) . '">' . esc_html__( 'View live item', 'spw' ) . '</a>';
		$edit = get_edit_post_link( $copy, 'raw' );
		if ( $edit ) {
			echo ' &middot; <a href="' . esc_url( $edit ) . '">' . esc_html__( 'Open proposed version in editor', 'spw' ) . '</a>';
		}
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Featured image, custom fields and categories/tags from the proposed version are also applied on approval.', 'spw' ) . '</p>';

		echo Staging_Diff::render( $copy_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render().

		if ( 'pending' === $copy->post_status ) {
			echo '<style>.spw-send-back summary{cursor:pointer;display:inline-block;min-height:24px;line-height:24px}</style>';
			echo '<h2>' . esc_html__( 'Decision', 'spw' ) . '</h2>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="spw_approve_staged" />';
			echo '<input type="hidden" name="post_id" value="' . esc_attr( (string) $copy_id ) . '" />';
			wp_nonce_field( 'spw_approve_staged_' . $copy_id );
			echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Approve and update live item', 'spw' ) . '</button></p>';
			echo '</form>';
			echo Queue_Table::send_back_form( $copy, $title ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in send_back_form().
		}
		echo '</div>';
	}

	/**
	 * admin-post: approve a staged copy, then redirect to the queue.
	 */
	public static function handle_approve(): void {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- verified next line.
		check_admin_referer( 'spw_approve_staged_' . $post_id );

		$result = Staging::approve( $post_id );
		$args   = array( 'page' => Queue::SLUG );
		if ( is_wp_error( $result ) ) {
			$code               = (string) $result->get_error_code();
			$args['spw_notice'] = 'error';
			$args['spw_code']   = array_key_exists( $code, Admin_Post::error_messages() ) ? $code : 'spw_failed';
		} else {
			$args['spw_notice'] = 'approved';
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
