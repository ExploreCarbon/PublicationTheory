<?php
namespace PublicationTheory;

defined( 'ABSPATH' ) || exit;

/**
 * Runtime capability filter: only Editor-level users may publish covered types.
 * Never edits stored roles; removing the filter restores core behavior.
 */
final class Caps {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_filter( 'user_has_cap', array( __CLASS__, 'filter_user_caps' ), 10, 4 );
	}

	/**
	 * Deny publish capabilities of covered post types to non-Editors.
	 *
	 * @param array    $allcaps All caps of the user.
	 * @param array    $caps    Primitive caps required (after meta mapping).
	 * @param array    $args    [0] requested cap, [1] user id, [2] optional object id.
	 * @param \WP_User $user    User.
	 */
	public static function filter_user_caps( $allcaps, $caps, $args, $user ): array {
		$allcaps = (array) $allcaps;
		$wanted  = (array) $caps;
		if ( isset( $args[0] ) ) {
			$wanted[] = (string) $args[0];
		}

		foreach ( Types::covered() as $type ) {
			$object = get_post_type_object( $type );
			if ( ! $object || empty( $object->cap->publish_posts ) ) {
				continue;
			}
			$publish = $object->cap->publish_posts;
			if ( ! in_array( $publish, $wanted, true ) ) {
				continue;
			}
			$others = $object->cap->edit_others_posts ?? 'edit_others_posts';
			if ( empty( $allcaps[ $others ] ) ) {
				$allcaps[ $publish ] = false;
			}
		}
		return $allcaps;
	}

	/**
	 * Whether the user has Editor-level publish ability for a post type.
	 * Reads the user's own role/user caps, before this filter applies.
	 */
	public static function can_publish( int $user_id, string $post_type ): bool {
		$user   = get_userdata( $user_id );
		$object = get_post_type_object( $post_type );
		if ( ! $user || ! $object ) {
			return false;
		}
		$others = $object->cap->edit_others_posts ?? 'edit_others_posts';
		$caps   = (array) $user->allcaps;
		return ! empty( $caps[ $others ] ) && ! empty( $caps[ $object->cap->publish_posts ] );
	}
}
