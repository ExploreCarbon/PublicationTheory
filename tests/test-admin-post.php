<?php
class SPW_Admin_Post_Test extends WP_UnitTestCase {

	public function tear_down() {
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'wp_die_handler' );
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();
		parent::tear_down();
	}

	private function post_as( int $user, int $post_id, string $note, bool $good_nonce = true ): void {
		wp_set_current_user( $user );
		$_POST    = array(
			'post_id'          => (string) $post_id,
			'note'             => wp_slash( $note ),
			'_wpnonce'         => $good_nonce ? wp_create_nonce( 'spw_send_back_' . $post_id ) : 'bad',
			'_wp_http_referer' => '/wp-admin/admin.php?page=spw-review-queue',
		);
		$_REQUEST = $_POST;
	}

	private function run_handler(): string {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( $location );
			}
		);
		add_filter(
			'wp_die_handler',
			static function () {
				return static function () {
					throw new WPDieException( 'died' );
				};
			}
		);
		try {
			SPW\Admin_Post::handle_send_back();
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}
		return '';
	}

	public function test_rejects_bad_nonce() {
		$id = self::factory()->post->create( array( 'post_status' => 'pending' ) );
		$this->post_as( self::factory()->user->create( array( 'role' => 'editor' ) ), $id, 'Note', false );
		$this->expectException( WPDieException::class );
		$this->run_handler();
	}

	public function test_author_without_cap_gets_error_and_post_unchanged() {
		$id = self::factory()->post->create( array( 'post_status' => 'pending' ) );
		$this->post_as( self::factory()->user->create( array( 'role' => 'author' ) ), $id, 'Note' );
		$location = $this->run_handler();
		$this->assertStringContainsString( 'spw_notice=error', $location );
		$this->assertStringContainsString( 'spw_code=spw_forbidden', $location );
		$this->assertSame( 'pending', get_post_status( $id ) );
	}

	public function test_editor_succeeds() {
		$id = self::factory()->post->create( array( 'post_status' => 'pending' ) );
		$this->post_as( self::factory()->user->create( array( 'role' => 'editor' ) ), $id, 'Fix it' );
		$location = $this->run_handler();
		$this->assertStringContainsString( 'page=spw-review-queue', $location );
		$this->assertStringContainsString( 'spw_notice=sent', $location );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( 'Fix it', SPW\Feedback::latest( $id )['note'] );
	}

	public function test_empty_note_reports_error() {
		$id = self::factory()->post->create( array( 'post_status' => 'pending' ) );
		$this->post_as( self::factory()->user->create( array( 'role' => 'editor' ) ), $id, '   ' );
		$this->assertStringContainsString( 'spw_code=spw_empty_note', $this->run_handler() );
		$this->assertSame( 'pending', get_post_status( $id ) );
	}

	public function test_queue_notice_allowlist_and_escaping() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET = array(
			'spw_notice' => 'error',
			'spw_code'   => '<script>alert(1)</script>',
		);
		ob_start();
		SPW\Queue::render();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( 'notice-error', $html );
		$_GET = array( 'spw_notice' => 'sent' );
		ob_start();
		SPW\Queue::render();
		$this->assertStringContainsString( 'notice-success', ob_get_clean() );
	}

	public function test_row_action_form_rendered() {
		$id = self::factory()->post->create( array( 'post_status' => 'pending' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		SPW\Queue::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( '<details', $html );
		$this->assertStringContainsString( 'name="action" value="spw_send_back"', $html );
		$this->assertStringContainsString( 'name="post_id" value="' . $id . '"', $html );
		$this->assertStringContainsString( 'required', $html );
	}
}
