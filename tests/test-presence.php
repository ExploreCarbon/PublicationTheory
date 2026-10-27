<?php
class SPW_Presence_Test extends WP_UnitTestCase {

	private $author;
	private $author_b;
	private $editor;

	public function set_up() {
		parent::set_up();
		$this->author   = self::factory()->user->create( array( 'role' => 'author', 'display_name' => 'Ann' ) );
		$this->author_b = self::factory()->user->create( array( 'role' => 'author', 'display_name' => 'Bob' ) );
		$this->editor   = self::factory()->user->create( array( 'role' => 'editor', 'display_name' => 'Ed' ) );
	}

	private function post_locked_by( int $user ): int {
		$id = self::factory()->post->create( array( 'post_author' => $this->author, 'post_status' => 'draft' ) );
		update_post_meta( $id, '_edit_lock', time() . ':' . $user );
		return $id;
	}

	private function ask( int $post_id ): array {
		return SPW\Presence::filter_heartbeat( array(), array( 'spw_presence' => array( 'post_id' => $post_id ) ) );
	}

	public function test_presence_lists_other_editor() {
		$id = $this->post_locked_by( $this->editor );
		wp_set_current_user( $this->author );
		$res = $this->ask( $id );
		$this->assertCount( 1, $res['spw_presence']['users'] );
		$this->assertSame( $this->editor, $res['spw_presence']['users'][0]['id'] );
		$this->assertSame( 'Ed', $res['spw_presence']['users'][0]['name'] );
		$this->assertArrayHasKey( 'avatar_url', $res['spw_presence']['users'][0] );
	}

	public function test_presence_excludes_self() {
		$id = $this->post_locked_by( $this->author );
		wp_set_current_user( $this->author );
		$this->assertSame( array(), $this->ask( $id )['spw_presence']['users'] );
	}

	public function test_presence_ignores_stale_lock() {
		$id = self::factory()->post->create( array( 'post_author' => $this->author, 'post_status' => 'draft' ) );
		update_post_meta( $id, '_edit_lock', ( time() - DAY_IN_SECONDS ) . ':' . $this->editor );
		wp_set_current_user( $this->author );
		$this->assertSame( array(), $this->ask( $id )['spw_presence']['users'] );
	}

	public function test_no_presence_for_user_who_cannot_edit_post() {
		$id = $this->post_locked_by( $this->editor );
		wp_set_current_user( $this->author_b ); // Not the author, no edit_others_posts.
		$this->assertArrayNotHasKey( 'spw_presence', $this->ask( $id ) );
	}

	public function test_no_presence_without_request_key() {
		wp_set_current_user( $this->author );
		$this->assertSame( array(), SPW\Presence::filter_heartbeat( array(), array() ) );
	}
}
