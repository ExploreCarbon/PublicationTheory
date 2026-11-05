<?php
namespace PublicationTheory;

defined( 'ABSPATH' ) || exit;

/**
 * Post types covered by the publication workflow.
 */
final class Types {

	private const EXCLUDED = array( 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' );

	/**
	 * Covered post type slugs.
	 *
	 * @return string[]
	 */
	public static function covered(): array {
		$types = get_post_types(
			array(
				'public'       => true,
				'show_in_rest' => true,
			)
		);
		$types = array_values( array_diff( array_keys( $types ), self::EXCLUDED ) );
		$types = apply_filters( 'pt_covered_post_types', $types );
		return array_values( array_map( 'strval', (array) $types ) );
	}

	/**
	 * Whether a post type is covered.
	 */
	public static function is_covered( string $post_type ): bool {
		return in_array( $post_type, self::covered(), true );
	}
}
