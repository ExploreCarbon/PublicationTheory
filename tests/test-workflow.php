<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) { exit; }
class SPW_Workflow_Test extends WP_UnitTestCase {

	private $author;
	private $editor;

	public function set_up() {
		parent::set_up();
		delete_option( 'spw_workflow_log' );
		reset_phpmailer_instance();
		$this->author = self::factory()->user->create( array( 'role' => 'author', 'user_email' => 'ann@example.org' ) );
		$this->editor = self::factory()->user->create( array( 'role' => 'editor', 'user_email' => 'ed1@example.org' ) );
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		wp_set_current_user( 0 );
		reset_phpmailer_instance();
		delete_option( 'spw_workflow_log' );
		parent::tear_down();
	}

	private function make( string $status, ?int $author = null ): int {
		return self::factory()->post->create( array( 'post_author' => $author ?? $this->author, 'post_status' => $status ) );
	}

	private function recipients(): array {
		$out = array();
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $m ) {
			foreach ( $m['to'] as $to ) {
				$out[] = $to[0];
			}
		}
		sort( $out );
		return $out;
	}

	public function test_author_cannot_publish_via_wp_update_post() {
		$id = $this->make( 'draft' );
		wp_set_current_user( $this->author );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$new = wp_insert_post( array( 'post_title' => 'x', 'post_status' => 'publish', 'post_author' => $this->author ) );
		$this->assertSame( 'pending', get_post_status( $new ) );
	}

	public function test_author_cannot_publish_via_rest() {
		$id = $this->make( 'draft' );
		wp_set_current_user( $this->author );
		$req = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $id );
		$req->set_param( 'status', 'publish' );
		$res = rest_do_request( $req );
		$this->assertSame( 403, $res->get_status() );
		$this->assertNotSame( 'publish', get_post_status( $id ) );
	}

	public function test_editor_can_publish() {
		$id = $this->make( 'pending' );
		wp_set_current_user( $this->editor );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	public function test_scheduled_publish_without_user_not_blocked() {
		$id = self::factory()->post->create(
			array( 'post_author' => $this->author, 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) )
		);
		$this->assertSame( 'future', get_post_status( $id ) );
		wp_publish_post( $id );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	public function test_submit_sets_pending_and_emails_editors() {
		$id = $this->make( 'draft' );
		wp_set_current_user( $this->author );
		$this->assertTrue( SPW\Workflow::submit( $id ) );
		$this->assertSame( 'pending', get_post_status( $id ) );
		$this->assertSame( array( 'admin@example.org', 'ed1@example.org' ), $this->recipients() );
	}

	public function test_submit_requires_edit_cap() {
		$id    = $this->make( 'draft' );
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $other );
		$res = SPW\Workflow::submit( $id );
		$this->assertWPError( $res );
		$this->assertSame( 'draft', get_post_status( $id ) );
	}

	public function test_send_back_requires_note() {
		$id = $this->make( 'pending' );
		wp_set_current_user( $this->editor );
		$res = SPW\Workflow::send_back( $id, '   ' );
		$this->assertWPError( $res );
		$this->assertSame( 'spw_empty_note', $res->get_error_code() );
		$this->assertSame( 'pending', get_post_status( $id ) );
	}

	public function test_send_back_forbidden_for_author() {
		$id = $this->make( 'pending' );
		wp_set_current_user( $this->author );
		$res = SPW\Workflow::send_back( $id, 'note' );
		$this->assertSame( 'spw_forbidden', $res->get_error_code() );
		$this->assertSame( 'pending', get_post_status( $id ) );
	}

	public function test_send_back_requires_pending() {
		$id = $this->make( 'draft' );
		wp_set_current_user( $this->editor );
		$this->assertSame( 'spw_not_pending', SPW\Workflow::send_back( $id, 'note' )->get_error_code() );
	}

	public function test_send_back_sets_draft_stores_feedback_emails_author() {
		$id = $this->make( 'pending' );
		wp_set_current_user( $this->editor );
		$this->assertTrue( SPW\Workflow::send_back( $id, 'Fix the intro' ) );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( 'Fix the intro', SPW\Feedback::latest( $id )['note'] );
		$this->assertSame( array( 'ann@example.org' ), $this->recipients() );
		$this->assertFalse( SPW\Workflow::$sending_back );
	}

	public function test_send_back_via_rest() {
		$id = $this->make( 'pending' );
		wp_set_current_user( $this->editor );
		$req = new WP_REST_Request( 'POST', '/spw/v1/posts/' . $id . '/send-back' );
		$req->set_param( 'note', 'Please revise' );
		$res = rest_do_request( $req );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'draft', $res->get_data()['status'] );
		wp_set_current_user( $this->author );
		$req = new WP_REST_Request( 'POST', '/spw/v1/posts/' . $id . '/send-back' );
		$req->set_param( 'note', 'x' );
		$this->assertSame( 403, rest_do_request( $req )->get_status() );
	}

	public function test_submit_via_rest() {
		$id = $this->make( 'draft' );
		wp_set_current_user( $this->author );
		$res = rest_do_request( new WP_REST_Request( 'POST', '/spw/v1/posts/' . $id . '/submit' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'pending', $res->get_data()['status'] );
		wp_set_current_user( 0 );
		$id2 = $this->make( 'draft' );
		$this->assertSame( 401, rest_do_request( new WP_REST_Request( 'POST', '/spw/v1/posts/' . $id2 . '/submit' ) )->get_status() );
	}

	public function test_editor_switch_to_draft_records_auto_feedback_and_mails() {
		$id = $this->make( 'pending' );
		reset_phpmailer_instance();
		wp_set_current_user( $this->editor );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$latest = SPW\Feedback::latest( $id );
		$this->assertSame( $this->editor, (int) $latest['editor_id'] );
		$this->assertStringContainsString( 'without a comment', $latest['note'] );
		$this->assertSame( array( 'ann@example.org' ), $this->recipients() );
	}

	public function test_non_owner_author_pending_to_draft_reverted() {
		$id    = $this->make( 'pending' );
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $other );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
		$this->assertSame( 'pending', get_post_status( $id ) );
		$this->assertNull( SPW\Feedback::latest( $id ) );
	}

	public function test_published_mail_rules_and_veto() {
		$id = $this->make( 'pending' );
		reset_phpmailer_instance();
		wp_publish_post( $id ); // No user: import / CLI.
		$this->assertSame( array(), $this->recipients() );

		$future = self::factory()->post->create(
			array( 'post_author' => $this->author, 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) )
		);
		reset_phpmailer_instance();
		wp_publish_post( $future ); // Scheduled publish via cron.
		$this->assertSame( array( 'ann@example.org' ), $this->recipients() );

		$id2 = $this->make( 'pending' );
		reset_phpmailer_instance();
		wp_set_current_user( $this->editor );
		wp_update_post( array( 'ID' => $id2, 'post_status' => 'publish' ) );
		$this->assertSame( array( 'ann@example.org' ), $this->recipients() );

		add_filter( 'spw_notify_enabled', '__return_false' );
		$id3 = $this->make( 'pending' );
		reset_phpmailer_instance();
		wp_update_post( array( 'ID' => $id3, 'post_status' => 'publish' ) );
		remove_filter( 'spw_notify_enabled', '__return_false' );
		$this->assertSame( array(), $this->recipients() );
	}

	public function test_author_can_withdraw_own_pending() {
		$id = $this->make( 'pending' );
		wp_set_current_user( $this->author );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
		$this->assertSame( 'draft', get_post_status( $id ) );
	}

	public function test_publish_emails_author() {
		$id = $this->make( 'pending' );
		reset_phpmailer_instance();
		wp_set_current_user( $this->editor );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		$this->assertSame( array( 'ann@example.org' ), $this->recipients() );
	}

	public function test_editor_is_author_no_self_email() {
		$id = $this->make( 'pending', $this->editor );
		reset_phpmailer_instance();
		wp_set_current_user( $this->editor );
		wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		$this->assertSame( array(), $this->recipients() );
	}
}
