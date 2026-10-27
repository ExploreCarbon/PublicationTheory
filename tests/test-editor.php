<?php
class SPW_Editor_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		register_post_type(
			'spw_book',
			array(
				'public'       => true,
				'show_in_rest' => true,
			)
		);
	}

	public function tear_down() {
		unregister_post_type( 'spw_book' );
		wp_dequeue_script( 'spw-workflow' );
		wp_deregister_script( 'spw-workflow' );
		remove_all_filters( 'spw_covered_post_types' );
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

		$this->screen_for( 'spw_book' );
		SPW\Editor_Assets::enqueue();
		$this->assertTrue( wp_script_is( 'spw-workflow', 'enqueued' ) );
		$data = implode( '', (array) wp_scripts()->get_data( 'spw-workflow', 'before' ) );
		$this->assertStringContainsString( 'window.spwWorkflow', $data );
		$this->assertStringContainsString( '"canPublish":false', $data );

		wp_dequeue_script( 'spw-workflow' );
		wp_deregister_script( 'spw-workflow' );

		add_filter(
			'spw_covered_post_types',
			static function ( $types ) {
				return array_values( array_diff( $types, array( 'spw_book' ) ) );
			}
		);
		SPW\Editor_Assets::enqueue();
		$this->assertFalse( wp_script_is( 'spw-workflow', 'enqueued' ) );
	}

	public function test_panel_script_not_enqueued_without_screen() {
		unset( $GLOBALS['current_screen'] );
		SPW\Editor_Assets::enqueue();
		$this->assertFalse( wp_script_is( 'spw-workflow', 'enqueued' ) );
	}

	public function test_feedback_route_author_sees_history_other_author_forbidden() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor', 'display_name' => 'Ed Editor' ) );
		$other  = self::factory()->user->create( array( 'role' => 'author' ) );
		$id     = self::factory()->post->create( array( 'post_status' => 'pending', 'post_author' => $author ) );

		wp_set_current_user( $editor );
		$this->assertTrue( SPW\Workflow::send_back( $id, 'Fix the intro' ) );

		wp_set_current_user( $author );
		$res = rest_do_request( new WP_REST_Request( 'GET', '/spw/v1/posts/' . $id . '/feedback' ) );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertCount( 1, $data );
		$this->assertSame( 'Ed Editor', $data[0]['editor_name'] );
		$this->assertSame( 'Fix the intro', $data[0]['note'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $data[0]['time'] );

		wp_set_current_user( $other );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/spw/v1/posts/' . $id . '/feedback' ) )->get_status() );
	}

	public function test_feedback_route_403_before_404_and_anonymous_401() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/spw/v1/posts/999999/feedback' ) )->get_status() );
		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_do_request( new WP_REST_Request( 'GET', '/spw/v1/posts/999999/feedback' ) )->get_status() );
	}
}
