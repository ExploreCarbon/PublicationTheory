<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) { exit; }
class PT_Editor_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		register_post_type(
			'pt_book',
			array(
				'public'       => true,
				'show_in_rest' => true,
			)
		);
	}

	public function tear_down() {
		unregister_post_type( 'pt_book' );
		wp_dequeue_script( 'pt-workflow' );
		wp_deregister_script( 'pt-workflow' );
		remove_all_filters( 'pt_covered_post_types' );
		remove_all_filters( 'pt_build_dir' );
		unset( $GLOBALS['current_screen'] );
		parent::tear_down();
	}

	private function screen_for( string $post_type ): void {
		set_current_screen( 'edit-' . $post_type );
		$screen            = get_current_screen();
		$screen->post_type = $post_type;
		$screen->base      = 'post';
	}

	public function test_panel_script_enqueued_only_for_covered_types() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->screen_for( 'pt_book' );
		PublicationTheory\Editor_Assets::enqueue();
		$this->assertTrue( wp_script_is( 'pt-workflow', 'enqueued' ) );
		$data = implode( '', (array) wp_scripts()->get_data( 'pt-workflow', 'before' ) );
		$this->assertStringContainsString( 'window.ptWorkflow', $data );
		$this->assertStringContainsString( '"canPublish":false', $data );

		wp_dequeue_script( 'pt-workflow' );
		wp_deregister_script( 'pt-workflow' );

		add_filter(
			'pt_covered_post_types',
			static function ( $types ) {
				return array_values( array_diff( $types, array( 'pt_book' ) ) );
			}
		);
		PublicationTheory\Editor_Assets::enqueue();
		$this->assertFalse( wp_script_is( 'pt-workflow', 'enqueued' ) );
	}

	public function test_panel_script_not_enqueued_without_screen() {
		unset( $GLOBALS['current_screen'] );
		PublicationTheory\Editor_Assets::enqueue();
		$this->assertFalse( wp_script_is( 'pt-workflow', 'enqueued' ) );
	}

	public function test_feedback_route_author_sees_history_other_author_forbidden() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor', 'display_name' => 'Ed Editor' ) );
		$other  = self::factory()->user->create( array( 'role' => 'author' ) );
		$id     = self::factory()->post->create( array( 'post_status' => 'pending', 'post_author' => $author ) );

		wp_set_current_user( $editor );
		$this->assertTrue( PublicationTheory\Workflow::send_back( $id, 'Fix the intro' ) );

		wp_set_current_user( $author );
		$res = rest_do_request( new WP_REST_Request( 'GET', '/publication-theory/v1/posts/' . $id . '/feedback' ) );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertCount( 1, $data );
		$this->assertSame( 'Ed Editor', $data[0]['editor_name'] );
		$this->assertSame( 'Fix the intro', $data[0]['note'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $data[0]['time'] );

		wp_set_current_user( $other );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/publication-theory/v1/posts/' . $id . '/feedback' ) )->get_status() );
	}

	public function test_feedback_route_403_before_404_and_anonymous_401() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/publication-theory/v1/posts/999999/feedback' ) )->get_status() );
		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_do_request( new WP_REST_Request( 'GET', '/publication-theory/v1/posts/999999/feedback' ) )->get_status() );
	}

	public function test_nothing_enqueued_when_build_missing() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->screen_for( 'pt_book' );
		add_filter(
			'pt_build_dir',
			static function () {
				return '/nonexistent/build/';
			}
		);
		PublicationTheory\Editor_Assets::enqueue();
		$this->assertFalse( wp_script_is( 'pt-workflow', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'pt-workflow', 'registered' ) );
	}

	public function test_can_publish_flag_for_editor_true_author_false() {
		$this->screen_for( 'pt_book' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		PublicationTheory\Editor_Assets::enqueue();
		$this->assertStringContainsString( '"canPublish":true', implode( '', (array) wp_scripts()->get_data( 'pt-workflow', 'before' ) ) );
	}
}
