<?php
namespace SPW;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes for workflow transitions.
 */
final class Rest {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Register routes.
	 */
	public static function routes(): void {
		$id_arg = array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
		);
		register_rest_route(
			'spw/v1',
			'/posts/(?P<id>\d+)/send-back',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'send_back' ),
				'permission_callback' => array( __CLASS__, 'can_send_back' ),
				'args'                => array(
					'id'   => $id_arg,
					'note' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
		register_rest_route(
			'spw/v1',
			'/posts/(?P<id>\d+)/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'submit' ),
				'permission_callback' => array( __CLASS__, 'can_submit' ),
				'args'                => array( 'id' => $id_arg ),
			)
		);
	}

	/**
	 * Permission: may edit other users' posts of the post's type.
	 */
	public static function can_send_back( \WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post ) {
			return new \WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'spw' ), array( 'status' => 404 ) );
		}
		$object = get_post_type_object( $post->post_type );
		if ( $object && current_user_can( $object->cap->edit_others_posts ) ) {
			return true;
		}
		return self::forbidden();
	}

	/**
	 * Permission: may edit this post.
	 */
	public static function can_submit( \WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post ) {
			return new \WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'spw' ), array( 'status' => 404 ) );
		}
		return current_user_can( 'edit_post', $post->ID ) ? true : self::forbidden();
	}

	/**
	 * 401 for anonymous, 403 for logged-in users.
	 */
	private static function forbidden(): \WP_Error {
		return new \WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to do that.', 'spw' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}

	/**
	 * POST send-back.
	 */
	public static function send_back( \WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = Workflow::send_back( $id, (string) $request['note'] );
		return is_wp_error( $result ) ? $result : array(
			'status' => get_post_status( $id ),
			'id'     => $id,
		);
	}

	/**
	 * POST submit.
	 */
	public static function submit( \WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = Workflow::submit( $id );
		return is_wp_error( $result ) ? $result : array(
			'status' => get_post_status( $id ),
			'id'     => $id,
		);
	}
}
