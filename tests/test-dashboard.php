<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) { exit; }
class PT_Dashboard_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
	}

	public function tear_down() {
		unset( $GLOBALS['wp_meta_boxes'] );
		delete_option( PublicationTheory\Log::OPTION );
		parent::tear_down();
	}

	private function widgets_for( string $role ): array {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		set_current_screen( 'dashboard' );
		$GLOBALS['wp_meta_boxes'] = array();
		PublicationTheory\Dashboard::add_widgets();
		return array_keys( $GLOBALS['wp_meta_boxes']['dashboard']['normal']['core'] ?? array() );
	}

	public function test_widgets_by_role() {
		$author = $this->widgets_for( 'author' );
		$this->assertContains( 'pt_my_submissions', $author );
		$this->assertNotContains( 'pt_review_queue', $author );
		$this->assertNotContains( 'pt_recent_activity', $author );

		$editor = $this->widgets_for( 'editor' );
		$this->assertContains( 'pt_review_queue', $editor );
		$this->assertNotContains( 'pt_recent_activity', $editor );

		$admin = $this->widgets_for( 'administrator' );
		$this->assertContains( 'pt_recent_activity', $admin );
	}

	public function test_queue_widget_lists_pending_escaped() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		self::factory()->post->create( array( 'post_status' => 'pending', 'post_title' => 'Alpha <script>x</script>' ) );
		ob_start();
		PublicationTheory\Dashboard::render_queue();
		$html = ob_get_clean();
		$this->assertStringContainsString( '1 item waiting', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_queue_widget_empty() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		ob_start();
		PublicationTheory\Dashboard::render_queue();
		$this->assertStringContainsString( 'Nothing is waiting', ob_get_clean() );
	}

	public function test_queue_widget_denied_for_author() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		self::factory()->post->create( array( 'post_status' => 'pending' ) );
		ob_start();
		PublicationTheory\Dashboard::render_queue();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_submissions_scoped_to_viewer_with_feedback() {
		$me    = self::factory()->user->create( array( 'role' => 'author' ) );
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create( array( 'post_author' => $me, 'post_status' => 'pending', 'post_title' => 'Mine pending' ) );
		$back = self::factory()->post->create( array( 'post_author' => $me, 'post_status' => 'draft', 'post_title' => 'Mine back' ) );
		self::factory()->post->create( array( 'post_author' => $me, 'post_status' => 'draft', 'post_title' => 'Plain draft' ) );
		self::factory()->post->create( array( 'post_author' => $other, 'post_status' => 'pending', 'post_title' => 'Theirs' ) );
		PublicationTheory\Feedback::add( $back, self::factory()->user->create( array( 'role' => 'editor' ) ), 'Tighten the intro' );
		wp_set_current_user( $me );
		ob_start();
		PublicationTheory\Dashboard::render_submissions();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Mine pending', $html );
		$this->assertStringContainsString( 'In review', $html );
		$this->assertStringContainsString( 'Sent back', $html );
		$this->assertStringContainsString( 'Tighten the intro', $html );
		$this->assertStringNotContainsString( 'Plain draft', $html );
		$this->assertStringNotContainsString( 'Theirs', $html );
	}

	public function test_activity_widget_shows_log_for_admin() {
		PublicationTheory\Log::record( 0, 'submitted', 'failed', 'x' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		PublicationTheory\Dashboard::render_activity();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'submitted', $html );
		$this->assertStringContainsString( 'failed', $html );
	}
}
