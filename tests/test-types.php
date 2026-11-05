<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) { exit; }
class PT_Types_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		register_post_type( 'pt_rest_cpt', array( 'public' => true, 'show_in_rest' => true ) );
		register_post_type( 'pt_norest_cpt', array( 'public' => true, 'show_in_rest' => false ) );
	}

	public function tear_down() {
		unregister_post_type( 'pt_rest_cpt' );
		unregister_post_type( 'pt_norest_cpt' );
		remove_all_filters( 'pt_covered_post_types' );
		parent::tear_down();
	}

	public function test_page_and_post_covered() {
		$this->assertTrue( PublicationTheory\Types::is_covered( 'page' ) );
		$this->assertTrue( PublicationTheory\Types::is_covered( 'post' ) );
		$this->assertTrue( PublicationTheory\Types::is_covered( 'pt_rest_cpt' ) );
		$this->assertSame( array_values( PublicationTheory\Types::covered() ), PublicationTheory\Types::covered() );
	}

	public function test_attachment_and_wp_block_excluded() {
		foreach ( array( 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' ) as $type ) {
			$this->assertFalse( PublicationTheory\Types::is_covered( $type ), $type );
		}
	}

	public function test_non_rest_cpt_excluded() {
		$this->assertFalse( PublicationTheory\Types::is_covered( 'pt_norest_cpt' ) );
	}

	public function test_filter_can_add_and_remove() {
		add_filter(
			'pt_covered_post_types',
			function ( $types ) {
				$types   = array_diff( $types, array( 'post' ) );
				$types[] = 'pt_norest_cpt';
				return $types;
			}
		);
		$this->assertFalse( PublicationTheory\Types::is_covered( 'post' ) );
		$this->assertTrue( PublicationTheory\Types::is_covered( 'pt_norest_cpt' ) );
		$this->assertSame( array_values( PublicationTheory\Types::covered() ), PublicationTheory\Types::covered() );
	}
}
