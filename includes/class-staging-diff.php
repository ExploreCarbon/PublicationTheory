<?php
namespace SPW;

defined( 'ABSPATH' ) || exit;

/**
 * Field-by-field diff of a staged copy against its live post.
 */
final class Staging_Diff {

	/**
	 * Render the diff markup ('' when the post is not a staged copy).
	 */
	public static function render( int $copy_id ): string {
		$copy    = get_post( $copy_id );
		$live_id = $copy ? Staging::live_id( $copy_id ) : null;
		$live    = $live_id ? get_post( $live_id ) : null;
		if ( ! $copy || ! $live ) {
			return '';
		}

		$fields = array(
			'post_title'   => __( 'Title', 'spw' ),
			'post_content' => __( 'Content', 'spw' ),
			'post_excerpt' => __( 'Excerpt', 'spw' ),
		);

		$out = '';
		foreach ( $fields as $field => $label ) {
			$heading_id = 'spw-diff-' . str_replace( '_', '-', $field );
			$out       .= '<section class="spw-diff" aria-labelledby="' . esc_attr( $heading_id ) . '">';
			$out       .= '<h2 id="' . esc_attr( $heading_id ) . '">' . esc_html( $label ) . '</h2>';
			// wp_text_diff() HTML-encodes the compared text; titles are escaped here.
			$diff = wp_text_diff(
				(string) $live->$field,
				(string) $copy->$field,
				array(
					'title'       => esc_html( $label ),
					'title_left'  => esc_html__( 'Live', 'spw' ),
					'title_right' => esc_html__( 'Proposed', 'spw' ),
				)
			);
			$out .= '' !== (string) $diff ? $diff : '<p>' . esc_html__( 'No changes.', 'spw' ) . '</p>';
			$out .= '</section>';
		}
		return $out;
	}
}
