<?php
class SPW_Types_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		register_post_type( 'spw_rest_cpt', array( 'public' => true, 'show_in_rest' => true ) );
		register_post_type( 'spw_norest_cpt', array( 'public' => true, 'show_in_rest' => false ) );
	}

	public function tear_down() {
		unregister_post_type( 'spw_rest_cpt' );
		unregister_post_type( 'spw_norest_cpt' );
		remove_all_filters( 'spw_covered_post_types' );
		parent::tear_down();
	}

	public function test_page_and_post_covered() {
		$this->assertTrue( SPW\Types::is_covered( 'page' ) );
		$this->assertTrue( SPW\Types::is_covered( 'post' ) );
		$this->assertTrue( SPW\Types::is_covered( 'spw_rest_cpt' ) );
		$this->assertSame( array_values( SPW\Types::covered() ), SPW\Types::covered() );
	}

	public function test_attachment_and_wp_block_excluded() {
		foreach ( array( 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' ) as $type ) {
			$this->assertFalse( SPW\Types::is_covered( $type ), $type );
		}
	}

	public function test_non_rest_cpt_excluded() {
		$this->assertFalse( SPW\Types::is_covered( 'spw_norest_cpt' ) );
	}

	public function test_filter_can_add_and_remove() {
		add_filter(
			'spw_covered_post_types',
			function ( $types ) {
				$types   = array_diff( $types, array( 'post' ) );
				$types[] = 'spw_norest_cpt';
				return $types;
			}
		);
		$this->assertFalse( SPW\Types::is_covered( 'post' ) );
		$this->assertTrue( SPW\Types::is_covered( 'spw_norest_cpt' ) );
		$this->assertSame( array_values( SPW\Types::covered() ), SPW\Types::covered() );
	}
}
