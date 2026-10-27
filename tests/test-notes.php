<?php
class SPW_Notes_Test extends WP_UnitTestCase {

	private $author;
	private $author_b;

	public function set_up() {
		parent::set_up();
		$this->author   = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->author_b = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		remove_all_filters( 'wp_update_comment_data' );
		foreach ( array( 'spw_plain', 'spw_thing' ) as $type ) {
			if ( post_type_exists( $type ) ) {
				unregister_post_type( $type );
			}
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function pending_copy_with_notes( array $statuses ): array {
		$live = self::factory()->post->create( array( 'post_author' => $this->author, 'post_status' => 'publish', 'post_content' => 'old' ) );
		$copy = SPW\Staging::open_for( $live, $this->author );
		wp_update_post( array( 'ID' => $copy, 'post_status' => 'pending', 'post_content' => 'new' ) );
		$ids    = array();
		$parent = 0;
		foreach ( $statuses as $i => $status ) {
			$ids[] = $id = wp_insert_comment(
				array(
					'comment_post_ID'  => $copy,
					'comment_type'     => 'note',
					'comment_content'  => "note $i",
					'comment_approved' => $status,
					'comment_parent'   => 'reply' === ( $statuses[ $i ] ?? '' ) ? $parent : 0,
					'user_id'          => $this->author,
				)
			);
			$parent = $parent ?: $id;
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		return array( $live, $copy, $ids );
	}

	public function test_notes_of_every_status_and_replies_move_on_approve() {
		list( $live, $copy, $ids ) = $this->pending_copy_with_notes( array( '1', '0', 'trash', 'spam' ) );
		$reply = wp_insert_comment(
			array(
				'comment_post_ID'  => $copy,
				'comment_type'     => 'note',
				'comment_content'  => 'reply',
				'comment_approved' => '1',
				'comment_parent'   => $ids[0],
				'user_id'          => $this->author,
			)
		);
		$this->assertSame( $live, SPW\Staging::approve( $copy ) );
		foreach ( array_merge( $ids, array( $reply ) ) as $id ) {
			$this->assertSame( $live, (int) get_comment( $id )->comment_post_ID, "comment $id not moved" );
		}
		$this->assertSame( $ids[0], (int) get_comment( $reply )->comment_parent );
	}

	public function test_failed_note_move_keeps_copy_sends_no_mail_and_retry_completes() {
		list( $live, $copy, $ids ) = $this->pending_copy_with_notes( array( '1', '1', '1' ) );
		reset_phpmailer_instance();
		$fail_id = $ids[1];
		$filter  = static function ( $data ) use ( $fail_id ) {
			return (int) ( $data['comment_ID'] ?? 0 ) === $fail_id ? new WP_Error( 'blocked', 'blocked' ) : $data;
		};
		add_filter( 'wp_update_comment_data', $filter );

		$res = SPW\Staging::approve( $copy );

		$this->assertWPError( $res );
		$this->assertSame( 'spw_notes_move_failed', $res->get_error_code() );
		$this->assertArrayHasKey( 'spw_notes_move_failed', SPW\Admin_Post::error_messages() );
		$this->assertNotNull( get_post( $copy ), 'copy must survive' );
		$this->assertSame( $copy, (int) get_comment( $ids[1] )->comment_post_ID );
		$this->assertSame( $copy, (int) get_comment( $ids[2] )->comment_post_ID, 'later notes intact' );
		$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent, 'no staged_approved mail' );
		$this->assertSame( 'new', get_post_field( 'post_content', $live ), 'merge already applied' );

		remove_filter( 'wp_update_comment_data', $filter );
		$this->assertSame( $live, SPW\Staging::approve( $copy ) );
		$this->assertNull( get_post( $copy ) );
		foreach ( $ids as $id ) {
			$this->assertSame( $live, (int) get_comment( $id )->comment_post_ID );
		}
		$this->assertCount( 3, get_comments( array( 'post_id' => $live, 'type' => 'note', 'status' => 'all' ) ) );
		$this->assertSame( 'new', get_post_field( 'post_content', $live ) );
		$this->assertNotEmpty( tests_retrieve_phpmailer_instance()->mock_sent, 'mail sent on success' );
	}

	private function add_note( int $post_id ) {
		$req = new WP_REST_Request( 'POST', '/wp/v2/comments' );
		$req->set_body_params(
			array(
				'post'    => $post_id,
				'type'    => 'note',
				'content' => 'Please tighten this paragraph.',
			)
		);
		return rest_do_request( $req );
	}

	public function test_notes_enabled_for_covered_types() {
		register_post_type( 'spw_plain', array( 'public' => true, 'show_in_rest' => true, 'supports' => array( 'title', 'editor' ) ) );
		register_post_type(
			'spw_thing',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor' => array( 'default-mode' => 'text' ) ),
			)
		);
		$this->assertContains( 'spw_plain', SPW\Types::covered() );
		$this->assertContains( 'spw_thing', SPW\Types::covered() );
		$this->assertFalse( $this->has_notes( 'spw_plain' ), 'precondition: no notes before enable' );

		SPW\Notes::enable();

		foreach ( SPW\Types::covered() as $type ) {
			if ( post_type_supports( $type, 'editor' ) ) {
				$this->assertTrue( $this->has_notes( $type ), "Notes not enabled for $type" );
			}
		}
		$editor = get_all_post_type_supports( 'spw_thing' )['editor'];
		$this->assertTrue( $editor[0]['notes'] );
		$this->assertSame( 'text', $editor[1]['default-mode'] );
	}

	private function has_notes( string $type ): bool {
		$editor = get_all_post_type_supports( $type )['editor'] ?? null;
		foreach ( is_array( $editor ) ? $editor : array() as $item ) {
			if ( is_array( $item ) && ! empty( $item['notes'] ) ) {
				return true;
			}
		}
		return false;
	}

	public function test_notes_move_to_live_post_on_approve() {
		$live = self::factory()->post->create( array( 'post_author' => $this->author, 'post_status' => 'publish' ) );
		$copy = SPW\Staging::open_for( $live, $this->author );
		wp_set_current_user( $this->author );
		$this->assertSame( 201, $this->add_note( $copy )->get_status() );
		$this->assertSame( 201, $this->add_note( $copy )->get_status() );
		wp_update_post( array( 'ID' => $copy, 'post_status' => 'pending' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( $live, SPW\Staging::approve( $copy ) );

		$this->assertNull( get_post( $copy ) );
		$notes = get_comments( array( 'post_id' => $live, 'type' => 'note', 'status' => 'all', 'fields' => 'ids' ) );
		$this->assertCount( 2, $notes );
		$this->assertSame( 0, (int) get_comments_number( $live ), 'notes do not count as public comments' );
	}

	public function test_notes_and_presence_are_registered_on_boot() {
		$this->assertSame( 99, has_action( 'init', array( 'SPW\Notes', 'enable' ) ) );
		$this->assertSame( 10, has_filter( 'heartbeat_received', array( 'SPW\Presence', 'filter_heartbeat' ) ) );
	}

	public function test_author_can_add_note_on_own_draft() {
		$id = self::factory()->post->create( array( 'post_author' => $this->author, 'post_status' => 'draft' ) );
		wp_set_current_user( $this->author );
		$res = $this->add_note( $id );
		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( 'note', $res->get_data()['type'] );
	}

	public function test_author_can_add_note_on_own_staged_copy() {
		$live = self::factory()->post->create( array( 'post_author' => $this->author, 'post_status' => 'publish' ) );
		$copy = SPW\Staging::open_for( $live, $this->author );
		$this->assertIsInt( $copy );
		wp_set_current_user( $this->author );
		$this->assertSame( 201, $this->add_note( $copy )->get_status() );
		// The live post stays closed to a non-publisher.
		$this->assertContains( $this->add_note( $live )->get_status(), array( 401, 403 ) );
	}

	public function test_author_cannot_add_note_on_other_authors_draft() {
		$id = self::factory()->post->create( array( 'post_author' => $this->author_b, 'post_status' => 'draft' ) );
		wp_set_current_user( $this->author );
		$res = $this->add_note( $id );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'rest_cannot_create_note', $res->as_error()->get_error_code() );
	}
}
