<?php
namespace PublicationTheory;

defined( 'ABSPATH' ) || exit;

/**
 * Tools > Workflow Log admin screen.
 */
final class Log_Screen {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
	}

	/**
	 * Add the Tools submenu.
	 */
	public static function add_menu(): void {
		add_management_page(
			__( 'Workflow Log', 'publication-theory' ),
			__( 'Workflow Log', 'publication-theory' ),
			'manage_options',
			'pt-workflow-log',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'publication-theory' ), '', array( 'response' => 403 ) );
		}
		$entries = Log::entries();
		echo '<div class="wrap"><h1>' . esc_html__( 'Workflow Log', 'publication-theory' ) . '</h1>';
		if ( ! $entries ) {
			echo '<p>' . esc_html__( 'No workflow events recorded yet.', 'publication-theory' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Recent workflow notifications, newest first', 'publication-theory' ) . '</caption><thead><tr>';
		$headings = array(
			__( 'Time (UTC)', 'publication-theory' ),
			__( 'Post', 'publication-theory' ),
			__( 'Event', 'publication-theory' ),
			__( 'Status', 'publication-theory' ),
			__( 'Detail', 'publication-theory' ),
		);
		foreach ( $headings as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $entries as $entry ) {
			$post_id = (int) ( $entry['post_id'] ?? 0 );
			$title   = $post_id ? get_the_title( $post_id ) : '';
			$link    = $post_id ? get_edit_post_link( $post_id, 'raw' ) : '';
			$label   = '' !== $title ? $title : '#' . $post_id;
			echo '<tr><td>' . esc_html( (string) ( $entry['time'] ?? '' ) ) . '</td><td>';
			if ( $link ) {
				echo '<a href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a>';
			} else {
				echo esc_html( $label );
			}
			echo '</td><td>' . esc_html( (string) ( $entry['event'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $entry['status'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $entry['detail'] ?? '' ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
