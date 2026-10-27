<?php
class SPW_Caps_Test extends WP_UnitTestCase {

	private $author;
	private $editor;
	private $admin;

	public function set_up() {
		parent::set_up();
		register_post_type(
			'spw_book',
			array(
				'public'          => true,
				'show_in_rest'    => true,
				'capability_type' => array( 'spw_book', 'spw_books' ),
				'map_meta_cap'    => true,
			)
		);
		$this->author = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		// Give roles the custom-type caps they would have via a role plugin.
		get_role( 'author' )->add_cap( 'publish_spw_books' );
		get_role( 'author' )->add_cap( 'edit_spw_books' );
		get_role( 'editor' )->add_cap( 'publish_spw_books' );
		get_role( 'editor' )->add_cap( 'edit_others_spw_books' );
		clean_user_cache( $this->author );
		clean_user_cache( $this->editor );
	}

	public function tear_down() {
		get_role( 'author' )->remove_cap( 'publish_spw_books' );
		get_role( 'author' )->remove_cap( 'edit_spw_books' );
		get_role( 'editor' )->remove_cap( 'publish_spw_books' );
		get_role( 'editor' )->remove_cap( 'edit_others_spw_books' );
		unregister_post_type( 'spw_book' );
		remove_all_filters( 'spw_covered_post_types' );
		parent::tear_down();
	}

	public function test_author_lacks_publish_posts_for_covered_type() {
		wp_set_current_user( $this->author );
		$this->assertFalse( current_user_can( 'publish_posts' ) );
		$this->assertFalse( current_user_can( 'publish_pages' ) );
		$post = self::factory()->post->create( array( 'post_author' => $this->author, 'post_status' => 'draft' ) );
		$this->assertFalse( current_user_can( 'publish_post', $post ) );
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_author' => $this->author, 'post_status' => 'draft' ) );
		$this->assertFalse( current_user_can( 'publish_post', $page ) );
		$book = self::factory()->post->create( array( 'post_type' => 'spw_book', 'post_author' => $this->author, 'post_status' => 'draft' ) );
		$this->assertFalse( current_user_can( get_post_type_object( 'spw_book' )->cap->publish_posts ) );
		$this->assertFalse( current_user_can( 'publish_post', $book ) );
		$this->assertTrue( current_user_can( 'edit_post', $post ) );
		$this->assertFalse( SPW\Caps::can_publish( $this->author, 'post' ) );
		$this->assertFalse( SPW\Caps::can_publish( $this->author, 'spw_book' ) );
	}

	public function test_author_keeps_edit_published_posts() {
		$post = self::factory()->post->create( array( 'post_author' => $this->author, 'post_status' => 'publish' ) );
		wp_set_current_user( $this->author );
		$this->assertTrue( current_user_can( 'edit_published_posts' ) );
		$this->assertTrue( current_user_can( 'delete_published_posts' ) );
		// Task 9 supersedes edit_post on live content: Authors stage edits through a copy instead.
		$this->assertSame( 'publish', get_post_status( $post ) );
		$this->assertFalse( current_user_can( 'edit_post', $post ) );
		$this->assertFalse( current_user_can( 'delete_post', $post ) );
		$this->assertTrue( SPW\Staging::can_stage( $this->author, $post ) );
		$draft = self::factory()->post->create( array( 'post_author' => $this->author, 'post_status' => 'draft' ) );
		$this->assertTrue( current_user_can( 'edit_post', $draft ) );
		$copy = SPW\Staging::open_for( $post, $this->author );
		$this->assertTrue( current_user_can( 'edit_post', $copy ) );
	}

	public function test_editor_and_admin_unchanged() {
		foreach ( array( $this->editor, $this->admin ) as $uid ) {
			wp_set_current_user( $uid );
			$this->assertTrue( current_user_can( 'publish_posts' ) );
			$this->assertTrue( current_user_can( 'publish_pages' ) );
			$post = self::factory()->post->create( array( 'post_status' => 'draft' ) );
			$this->assertTrue( current_user_can( 'publish_post', $post ) );
			$this->assertTrue( SPW\Caps::can_publish( $uid, 'post' ) );
		}
		wp_set_current_user( $this->editor );
		$this->assertTrue( current_user_can( 'publish_spw_books' ) );
		$this->assertTrue( SPW\Caps::can_publish( $this->editor, 'spw_book' ) );
	}

	public function test_uncovered_type_unchanged() {
		add_filter(
			'spw_covered_post_types',
			function ( $types ) {
				return array_diff( $types, array( 'post' ) );
			}
		);
		wp_set_current_user( $this->author );
		$post = self::factory()->post->create( array( 'post_author' => $this->author, 'post_status' => 'draft' ) );
		$this->assertTrue( current_user_can( 'publish_post', $post ) );
		$this->assertTrue( current_user_can( 'publish_posts' ) );
		$this->assertFalse( current_user_can( 'publish_pages' ) );
	}

	public function test_deactivation_restores() {
		remove_filter( 'user_has_cap', array( 'SPW\Caps', 'filter_user_caps' ), 10 );
		wp_set_current_user( $this->author );
		$this->assertTrue( current_user_can( 'publish_posts' ) );
		$post = self::factory()->post->create( array( 'post_author' => $this->author, 'post_status' => 'draft' ) );
		$this->assertTrue( current_user_can( 'publish_post', $post ) );
		add_filter( 'user_has_cap', array( 'SPW\Caps', 'filter_user_caps' ), 10, 4 );
	}
}
