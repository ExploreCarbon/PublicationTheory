<?php
namespace PublicationTheory;

defined( 'ABSPATH' ) || exit;

/**
 * Hidden admin screen to review a staged copy, plus the approve handler.
 */
final class Staging_Review {

	public const SLUG = 'pt-staged-review';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_pt_approve_staged', array( __CLASS__, 'handle_approve' ) );
		add_action( 'admin_post_pt_discard_staged', array( __CLASS__, 'handle_discard' ) );
	}

	/**
	 * Hidden page (no parent menu).
	 */
	public static function add_page(): void {
		add_submenu_page( '', __( 'Review staged changes', 'publication-theory' ), '', 'edit_others_posts', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the review screen.
	 */
	public static function render(): void {
		$copy_id = isset( $_GET['copy'] ) ? absint( wp_unslash( $_GET['copy'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$copy    = $copy_id ? get_post( $copy_id ) : null;
		$object  = $copy ? get_post_type_object( $copy->post_type ) : null;
		if ( ! $copy || ! $object || ! current_user_can( $object->cap->edit_others_posts ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'publication-theory' ), '', array( 'response' => 403 ) );
		}
		$live_id = Staging::live_id( $copy_id );
		$live    = $live_id ? get_post( $live_id ) : null;
		if ( ! $live ) {
			wp_die( esc_html__( 'This is not a staged edit.', 'publication-theory' ), '', array( 'response' => 404 ) );
		}

		$title  = get_the_title( $live );
		$title  = '' !== $title ? $title : __( '(no title)', 'publication-theory' );
		$author = get_userdata( (int) $copy->post_author );

		echo '<div class="wrap">';
		/* translators: %s: live post title */
		echo '<h1>' . esc_html( sprintf( __( 'Review staged changes: %s', 'publication-theory' ), $title ) ) . '</h1>';
		Queue::render_notice();
		echo '<p>';
		/* translators: %s: author display name */
		echo esc_html( sprintf( __( 'Proposed by: %s', 'publication-theory' ), $author ? $author->display_name : __( 'Unknown', 'publication-theory' ) ) );
		echo ' &middot; <a href="' . esc_url( get_permalink( $live ) ) . '">' . esc_html__( 'View live item', 'publication-theory' ) . '</a>';
		$edit = get_edit_post_link( $copy, 'raw' );
		if ( $edit ) {
			echo ' &middot; <a href="' . esc_url( $edit ) . '">' . esc_html__( 'Open proposed version in editor', 'publication-theory' ) . '</a>';
		}
		echo '</p>';

		echo Staging_Diff::render( $copy_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render().

		if ( 'pending' === $copy->post_status ) {
			echo '<style>.pt-send-back summary{cursor:pointer;display:inline-block;min-height:24px;line-height:24px}</style>';
			echo '<h2>' . esc_html__( 'Decision', 'publication-theory' ) . '</h2>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="pt_approve_staged" />';
			echo '<input type="hidden" name="post_id" value="' . esc_attr( (string) $copy_id ) . '" />';
			wp_nonce_field( 'pt_approve_staged_' . $copy_id );
			echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Approve and update live item', 'publication-theory' ) . '</button></p>';
			echo '</form>';
			echo Queue_Table::send_back_form( $copy, $title ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in send_back_form().
		} else {
			echo '<h2>' . esc_html__( 'Decision', 'publication-theory' ) . '</h2>';
			echo '<p>' . esc_html__( 'This proposed version is a draft (returned to the author or not yet submitted). It can be approved once the author submits it.', 'publication-theory' ) . '</p>';
		}
		echo Staging::discard_form( $copy_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in discard_form().
		echo '</div>';
	}

	/**
	 * admin-post: discard a staged copy. Editors return to the queue; the copy's author to the live item's list.
	 */
	public static function handle_discard(): void {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- verified next line.
		check_admin_referer( 'pt_discard_staged_' . $post_id );

		$type   = (string) get_post_type( $post_id );
		$result = Staging::discard( $post_id );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 403 ) );
		}
		$object = get_post_type_object( $type );
		if ( $object && current_user_can( $object->cap->edit_others_posts ) ) {
			$url = add_query_arg(
				array(
					'page'       => Queue::SLUG,
					'pt_notice' => 'discarded',
				),
				admin_url( 'admin.php' )
			);
		} else {
			$url = add_query_arg( array( 'post_type' => $type ), admin_url( 'edit.php' ) );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * admin-post: approve a staged copy, then redirect to the queue.
	 */
	public static function handle_approve(): void {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- verified next line.
		check_admin_referer( 'pt_approve_staged_' . $post_id );

		$result = Staging::approve( $post_id );
		$args   = array( 'page' => Queue::SLUG );
		if ( is_wp_error( $result ) ) {
			$code               = (string) $result->get_error_code();
			$args['pt_notice'] = 'error';
			$args['pt_code']   = array_key_exists( $code, Admin_Post::error_messages() ) ? $code : 'pt_failed';
		} else {
			$args['pt_notice'] = 'approved';
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
