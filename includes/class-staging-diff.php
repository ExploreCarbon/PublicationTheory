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
		return $out . self::other_changes( $copy_id );
	}

	/**
	 * Meta, featured image and term changes that approval would apply to the CURRENT live post.
	 */
	private static function other_changes( int $copy_id ): string {
		$plan = Staging::plan( $copy_id );
		$out  = '<section class="spw-diff" aria-labelledby="spw-diff-other"><h2 id="spw-diff-other">' . esc_html__( 'Other changes', 'spw' ) . '</h2>';
		if ( null === $plan ) {
			return $out . '<p>' . esc_html__( 'Featured image, custom fields and terms will be replaced with the proposed version.', 'spw' ) . '</p></section>';
		}

		$changed = $plan['live_changed'];
		$changed['fields'] = $changed['fields'] ?? array();
		if ( $changed['fields'] || $changed['meta'] || $changed['terms'] ) {
			$names = array();
			foreach ( $changed['terms'] as $taxonomy ) {
				$tax     = get_taxonomy( $taxonomy );
				$names[] = $tax ? $tax->labels->name : $taxonomy;
			}
			$out .= '<div class="notice notice-warning inline"><p>' . esc_html__( 'The live item changed after this copy was opened. Changes made only on the live item are kept; where both changed, the proposed version wins.', 'spw' ) . '</p>';
			if ( $changed['fields'] ) {
				$labels = array(
					'post_title'   => __( 'Title', 'spw' ),
					'post_content' => __( 'Content', 'spw' ),
					'post_excerpt' => __( 'Excerpt', 'spw' ),
				);
				$fields = array();
				foreach ( $changed['fields'] as $field ) {
					$fields[] = $labels[ $field ] ?? $field;
				}
				/* translators: %s: comma-separated field names */
				$out .= '<p>' . esc_html( sprintf( __( 'Live changed since this copy was opened: %s (the proposed version will replace them).', 'spw' ), implode( ', ', $fields ) ) ) . '</p>';
			}
			if ( $changed['meta'] ) {
				/* translators: %s: comma-separated meta keys */
				$out .= '<p>' . esc_html( sprintf( __( 'Fields changed on live: %s', 'spw' ), implode( ', ', $changed['meta'] ) ) ) . '</p>';
			}
			if ( $names ) {
				/* translators: %s: comma-separated taxonomy names */
				$out .= '<p>' . esc_html( sprintf( __( 'Terms changed on live: %s', 'spw' ), implode( ', ', $names ) ) ) . '</p>';
			}
			$out .= '</div>';
		}

		$rows = '';
		$meta = $plan['meta'];
		if ( isset( $meta['_thumbnail_id'] ) ) {
			$rows .= self::row( __( 'Featured image', 'spw' ), self::image( $meta['_thumbnail_id']['before'] ), self::image( $meta['_thumbnail_id']['after'] ) );
			unset( $meta['_thumbnail_id'] );
		}
		foreach ( $plan['terms'] as $taxonomy => $change ) {
			$tax   = get_taxonomy( (string) $taxonomy );
			$label = $tax ? $tax->labels->name : (string) $taxonomy;
			/* translators: %s: term names */
			$before = $change['removed'] ? sprintf( __( 'Removed: %s', 'spw' ), self::term_names( $change['removed'], (string) $taxonomy ) ) : '';
			/* translators: %s: term names */
			$after = $change['added'] ? sprintf( __( 'Added: %s', 'spw' ), self::term_names( $change['added'], (string) $taxonomy ) ) : '';
			$rows .= self::row( $label, $before, $after );
		}
		foreach ( $meta as $key => $change ) {
			$rows .= self::row( (string) $key, self::value( $change['before'] ), self::value( $change['after'] ) );
		}

		if ( '' === $rows ) {
			return $out . '<p>' . esc_html__( 'No changes.', 'spw' ) . '</p></section>';
		}
		$out .= '<table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Other changes', 'spw' ) . '</caption><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Field', 'spw' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Live', 'spw' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Proposed', 'spw' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></section>';
		return $out;
	}

	/**
	 * One escaped table row.
	 */
	private static function row( string $label, string $before, string $after ): string {
		$none = __( '(none)', 'spw' );
		return '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( '' !== $before ? $before : $none ) . '</td><td>' . esc_html( '' !== $after ? $after : $none ) . '</td></tr>';
	}

	/**
	 * Display text for a meta value list (null = absent), truncated to 200 chars.
	 *
	 * @param array|null $values Values.
	 */
	private static function value( $values ): string {
		if ( null === $values ) {
			return '';
		}
		$parts = array();
		foreach ( (array) $values as $value ) {
			$parts[] = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
		}
		$text = implode( ' | ', $parts );
		return mb_strlen( $text ) > 200 ? mb_substr( $text, 0, 200 ) . '…' : $text;
	}

	/**
	 * Attachment title and filename for a _thumbnail_id value list.
	 *
	 * @param array|null $values Values.
	 */
	private static function image( $values ): string {
		$id = $values ? (int) reset( $values ) : 0;
		if ( $id <= 0 ) {
			return '';
		}
		$file = get_attached_file( $id );
		$name = get_the_title( $id );
		return trim( $name . ( $file ? ' (' . wp_basename( $file ) . ')' : '' ) . ' #' . $id );
	}

	/**
	 * Comma-separated term names.
	 *
	 * @param int[] $ids Term IDs.
	 */
	private static function term_names( array $ids, string $taxonomy ): string {
		$names = array();
		foreach ( $ids as $id ) {
			$term    = get_term( (int) $id, $taxonomy );
			$names[] = $term && ! is_wp_error( $term ) ? $term->name : '#' . (int) $id;
		}
		return implode( ', ', $names );
	}
}
