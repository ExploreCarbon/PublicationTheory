<?php
class SPW_Mailer_Test extends WP_UnitTestCase {

	private $author;
	private $editor1;
	private $editor2;
	private $post_id;

	public function set_up() {
		parent::set_up();
		delete_option( 'spw_workflow_log' );
		reset_phpmailer_instance();
		$this->author  = self::factory()->user->create( array( 'role' => 'author', 'display_name' => 'Ann Author', 'user_email' => 'ann@example.org' ) );
		$this->editor1 = self::factory()->user->create( array( 'role' => 'editor', 'user_email' => 'ed1@example.org' ) );
		$this->editor2 = self::factory()->user->create( array( 'role' => 'editor', 'user_email' => 'ed2@example.org' ) );
		$this->post_id = self::factory()->post->create( array( 'post_author' => $this->author, 'post_title' => 'My Draft', 'post_status' => 'pending' ) );
	}

	public function tear_down() {
		reset_phpmailer_instance();
		delete_option( 'spw_workflow_log' );
		parent::tear_down();
	}

	private function sent_to(): array {
		$mailer = tests_retrieve_phpmailer_instance();
		$out    = array();
		foreach ( $mailer->mock_sent as $m ) {
			foreach ( $m['to'] as $to ) {
				$out[] = $to[0];
			}
		}
		sort( $out );
		return $out;
	}

	public function test_submit_emails_each_editor_separately() {
		SPW\Mailer::notify( 'submitted', $this->post_id, $this->author );
		$mailer = tests_retrieve_phpmailer_instance();
		// Two editors plus the default site admin (also has edit_others_posts).
		$this->assertCount( 3, $mailer->mock_sent );
		$this->assertSame( array( 'admin@example.org', 'ed1@example.org', 'ed2@example.org' ), $this->sent_to() );
		foreach ( $mailer->mock_sent as $m ) {
			$this->assertCount( 1, $m['to'] );
			$this->assertStringContainsString( 'My Draft', $m['subject'] );
			$this->assertStringContainsString( 'Ann Author', $m['body'] );
		}
		$statuses = wp_list_pluck( SPW\Log::entries(), 'status' );
		$this->assertSame( array( 'sent', 'sent', 'sent' ), $statuses );
	}

	public function test_actor_not_emailed() {
		SPW\Mailer::notify( 'submitted', $this->post_id, $this->editor1 );
		$this->assertSame( array( 'admin@example.org', 'ed2@example.org' ), $this->sent_to() );
	}

	public function test_no_editors_logs_no_recipients() {
		foreach ( array( $this->editor1, $this->editor2 ) as $id ) {
			wp_delete_user( $id );
		}
		foreach ( get_users( array( 'capability' => 'edit_others_posts' ) ) as $u ) {
			wp_delete_user( $u->ID );
		}
		SPW\Mailer::notify( 'submitted', $this->post_id, $this->author );
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );
		$entries = SPW\Log::entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'no_recipients', $entries[0]['status'] );
		$this->assertSame( 'submitted', $entries[0]['event'] );
		$this->assertSame( $this->post_id, $entries[0]['post_id'] );
	}

	public function test_sent_back_email_contains_note_and_edit_link() {
		SPW\Feedback::add( $this->post_id, $this->editor1, 'Please fix the intro.' );
		SPW\Mailer::notify( 'sent_back', $this->post_id, $this->editor1 );
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertSame( array( 'ann@example.org' ), $this->sent_to() );
		$body = $mailer->mock_sent[0]['body'];
		$this->assertStringContainsString( 'Please fix the intro.', $body );
		$this->assertStringContainsString( admin_url( 'post.php?post=' . $this->post_id . '&action=edit' ), $body );
		wp_set_current_user( $this->editor1 );
		$this->assertStringContainsString( get_edit_post_link( $this->post_id, 'raw' ), $body );
	}

	public function test_author_actor_not_emailed_on_published() {
		SPW\Mailer::notify( 'published', $this->post_id, $this->author );
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_filters_apply() {
		add_filter( 'spw_email_recipients', fn( $ids ) => array( $this->editor2 ) );
		add_filter( 'spw_email_message', function ( $m ) {
			$m['subject'] = 'Custom';
			return $m;
		} );
		SPW\Mailer::notify( 'submitted', $this->post_id, $this->author );
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertSame( array( 'ed2@example.org' ), $this->sent_to() );
		$this->assertSame( 'Custom', $mailer->mock_sent[0]['subject'] );
		remove_all_filters( 'spw_email_recipients' );
		remove_all_filters( 'spw_email_message' );
	}

	public function test_wp_mail_failure_is_logged() {
		// Real failure path: an unusable address makes PHPMailer fail inside wp_mail,
		// which fires wp_mail_failed from within notify().
		global $wpdb;
		$wpdb->update( $wpdb->users, array( 'user_email' => 'not-an-email' ), array( 'ID' => $this->author ) );
		clean_user_cache( $this->author );
		SPW\Feedback::add( $this->post_id, $this->editor1, 'Nope' );
		SPW\Mailer::notify( 'sent_back', $this->post_id, $this->editor1 );
		$entries = SPW\Log::entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'failed', $entries[0]['status'] );
		$this->assertSame( 'sent_back', $entries[0]['event'] );
		$this->assertSame( $this->post_id, $entries[0]['post_id'] );
		$this->assertNotSame( '', $entries[0]['detail'] );
	}

	public function test_log_ring_buffer_caps_at_500() {
		for ( $i = 0; $i < 505; $i++ ) {
			SPW\Log::record( $i, 'submitted', 'sent', (string) $i );
		}
		$entries = SPW\Log::entries();
		$this->assertCount( 500, $entries );
		$this->assertSame( 504, $entries[0]['post_id'] );
	}
}
