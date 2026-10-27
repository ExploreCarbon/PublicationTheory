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

		unregister_post_type( 'spw_plain' );
		unregister_post_type( 'spw_thing' );
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
