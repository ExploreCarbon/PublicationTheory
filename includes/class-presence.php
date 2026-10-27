<?php
namespace SPW;

defined( 'ABSPATH' ) || exit;

/**
 * "Also editing" presence, riding on Heartbeat and core's post lock (`_edit_lock`).
 */
final class Presence {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_filter( 'heartbeat_received', array( __CLASS__, 'filter_heartbeat' ), 10, 2 );
	}

	/**
	 * Report other users holding the edit lock, only to users who can edit the post.
	 *
	 * @param array $response Heartbeat response.
	 * @param array $data     Heartbeat data from the client.
	 */
	public static function filter_heartbeat( $response, $data ): array {
		$response = (array) $response;
		$data     = (array) $data;
		if ( empty( $data['spw_presence'] ) || ! is_array( $data['spw_presence'] ) ) {
			return $response;
		}
		$post_id = absint( $data['spw_presence']['post_id'] ?? 0 );
		if ( $post_id <= 0 || ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return $response;
		}

		$users = array();
		// Core semantics: returns the holder's ID when it is someone else and the lock is fresh.
		$holder = (int) wp_check_post_lock( $post_id );
		if ( $holder > 0 && get_current_user_id() !== $holder ) {
			$user = get_userdata( $holder );
			if ( $user ) {
				$users[] = array(
					'id'   => (int) $user->ID,
					'name' => (string) $user->display_name,
				);
			}
		}

		$response['spw_presence'] = array(
			'post_id' => $post_id,
			'users'   => $users,
		);
		return $response;
	}
}
