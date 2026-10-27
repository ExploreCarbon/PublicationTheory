<?php
class SPW_Queue_Test extends WP_UnitTestCase {

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
		remove_all_filters( 'spw_queue_item_kind' );
		unset( $GLOBALS['menu'] );
		parent::tear_down();
	}

	public function test_pending_count_covers_all_types() {
		self::factory()->post->create( array( 'post_status' => 'pending' ) );
		self::factory()->post->create( array( 'post_status' => 'pending', 'post_type' => 'page' ) );
		self::factory()->post->create( array( 'post_status' => 'pending', 'post_type' => 'spw_book' ) );
		$this->assertSame( 3, SPW\Queue::pending_count() );
	}

	public function test_draft_and_publish_not_counted() {
		self::factory()->post->create( array( 'post_status' => 'draft' ) );
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		self::factory()->post->create( array( 'post_status' => 'pending' ) );
		$this->assertSame( 1, SPW\Queue::pending_count() );
	}

	public function test_menu_hidden_from_authors() {
		$GLOBALS['menu'] = array();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		SPW\Queue::add_menu();
		$this->assertSame( array(), $GLOBALS['menu'] );
	}

	public function test_menu_shown_to_editors_with_badge() {
		$GLOBALS['menu'] = array();
		self::factory()->post->create( array( 'post_status' => 'pending' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		SPW\Queue::add_menu();
		$titles = wp_list_pluck( $GLOBALS['menu'], 0 );
		$this->assertCount( 1, $titles );
		$title = reset( $titles );
		$this->assertStringContainsString( 'Review Queue', $title );
		$this->assertStringContainsString( 'count-1', $title );
		$this->assertStringContainsString( '1 items pending review', $title );
	}

	public function test_menu_badge_omitted_when_zero() {
		$GLOBALS['menu'] = array();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		SPW\Queue::add_menu();
		$this->assertStringNotContainsString( 'update-plugins', reset( $GLOBALS['menu'] )[0] );
	}

	public function test_kind_default_and_filter() {
		$id = self::factory()->post->create( array( 'post_status' => 'pending' ) );
		$this->assertSame( 'New', SPW\Queue::kind( $id ) );
		add_filter(
			'spw_queue_item_kind',
			static function () {
				return 'Staged edit';
			}
		);
		$this->assertSame( 'Staged edit', SPW\Queue::kind( $id ) );
	}

	public function test_render_lists_pending_item_escaped() {
		self::factory()->post->create( array( 'post_status' => 'pending', 'post_title' => 'A <b>x</b>' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		SPW\Queue::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'A &lt;b&gt;x&lt;/b&gt;', $html );
		$this->assertStringContainsString( '>Review<', $html );
		$this->assertStringContainsString( 'New', $html );
	}
}
