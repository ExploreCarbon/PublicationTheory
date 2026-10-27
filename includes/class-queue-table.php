<?php
namespace SPW;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List table of pending items.
 */
final class Queue_Table extends \WP_List_Table {

	private const PER_PAGE = 20;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'item',
				'plural'   => 'items',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'title'     => __( 'Title', 'spw' ),
			'type'      => __( 'Type', 'spw' ),
			'author'    => __( 'Author', 'spw' ),
			'submitted' => __( 'Submitted', 'spw' ),
			'kind'      => __( 'Kind', 'spw' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'title'     => array( 'title', false ),
			'submitted' => array( 'modified', true ),
		);
	}

	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$allowed = array( 'title', 'modified' );
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'modified'; // phpcs:ignore WordPress.Security.NonceVerification
		$orderby = in_array( $orderby, $allowed, true ) ? $orderby : 'modified';
		$order   = isset( $_GET['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification
		$order   = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';
		$paged   = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$paged   = max( 1, $paged );

		$types = Types::covered();
		$query = new \WP_Query(
			array(
				'post_type'      => $types ? $types : array( 'post' ),
				'post_status'    => 'pending',
				'posts_per_page' => self::PER_PAGE,
				'paged'          => $paged,
				'orderby'        => $orderby,
				'order'          => $order,
			)
		);
		$this->items = $query->posts;
		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->found_posts,
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	public function no_items() {
		esc_html_e( 'Nothing is waiting for review.', 'spw' );
	}

	protected function column_title( $item ) {
		$title = get_the_title( $item );
		$title = '' !== $title ? $title : __( '(no title)', 'spw' );
		$link  = get_edit_post_link( $item, 'raw' );
		if ( ! $link ) {
			return '<strong>' . esc_html( $title ) . '</strong>';
		}
		$actions = array(
			'review' => sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				esc_url( $link ),
				/* translators: %s: post title */
				esc_attr( sprintf( __( 'Review "%s"', 'spw' ), $title ) ),
				esc_html__( 'Review', 'spw' )
			),
		);
		return '<strong><a class="row-title" href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a></strong>' . $this->row_actions( $actions );
	}

	protected function column_type( $item ) {
		$object = get_post_type_object( $item->post_type );
		return esc_html( $object ? $object->labels->singular_name : $item->post_type );
	}

	protected function column_author( $item ) {
		$user = get_userdata( (int) $item->post_author );
		return esc_html( $user ? $user->display_name : '' );
	}

	protected function column_submitted( $item ) {
		$gmt = '0000-00-00 00:00:00' === $item->post_modified_gmt ? 0 : (int) strtotime( $item->post_modified_gmt . ' UTC' );
		if ( $gmt <= 0 ) {
			return '';
		}
		/* translators: %s: human-readable time difference */
		return esc_html( sprintf( __( '%s ago', 'spw' ), human_time_diff( $gmt ) ) );
	}

	protected function column_kind( $item ) {
		return esc_html( Queue::kind( (int) $item->ID ) );
	}

	protected function column_default( $item, $column_name ) {
		return '';
	}
}
