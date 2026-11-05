<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) { exit; }
class PT_Staging_Test extends WP_UnitTestCase {

	private $author;
	private $author_b;
	private $editor;
	private $cat_a;
	private $cat_b;
	private $thumb;

	public function set_up() {
		parent::set_up();
		delete_option( 'pt_workflow_log' );
		reset_phpmailer_instance();
		$this->author   = self::factory()->user->create( array( 'role' => 'author', 'user_email' => 'ann@example.org' ) );
		$this->author_b = self::factory()->user->create( array( 'role' => 'author', 'user_email' => 'bob@example.org' ) );
		$this->editor   = self::factory()->user->create( array( 'role' => 'editor', 'user_email' => 'ed1@example.org' ) );
		$this->cat_a    = self::factory()->category->create( array( 'name' => 'Alpha' ) );
		$this->cat_b    = self::factory()->category->create( array( 'name' => 'Beta' ) );
		$this->thumb    = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		wp_set_current_user( 0 );
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'wp_die_handler' );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		reset_phpmailer_instance();
		delete_option( 'pt_workflow_log' );
		parent::tear_down();
	}

	private function live( ?int $author = null ): int {
		$id = self::factory()->post->create(
			array(
				'post_author'  => $author ?? $this->author,
				'post_status'  => 'publish',
				'post_title'   => 'Live title',
				'post_content' => 'Live content',
				'post_excerpt' => 'Live excerpt',
			)
		);
		update_post_meta( $id, 'color', 'blue' );
		add_post_meta( $id, 'multi', 'one' );
		add_post_meta( $id, 'multi', 'two' );
		update_post_meta( $id, 'arr', array( 'a' => 1 ) );
		update_post_meta( $id, '_thumbnail_id', $this->thumb );
		update_post_meta( $id, '_edit_lock', '123:1' );
		update_post_meta( $id, '_pt_feedback', array( 'x' => 1 ) );
		wp_set_object_terms( $id, array( $this->cat_a ), 'category' );
		wp_set_object_terms( $id, array( 'tag1' ), 'post_tag' );
		return $id;
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

	private function trap(): void {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( $location );
			}
		);
		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $message ) {
					throw new WPDieException( is_string( $message ) ? $message : 'died' );
				};
			}
		);
	}

	private function redirect_of( callable $fn ): string {
		$this->trap();
		try {
			$fn();
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}
		return '';
	}

	private function copy_for( int $live, ?int $author = null ): int {
		$copy = PublicationTheory\Staging::open_for( $live, $author ?? $this->author );
		$this->assertIsInt( $copy );
		return $copy;
	}

	private function term_ids( int $id, string $tax ): array {
		$ids = wp_get_object_terms( $id, $tax, array( 'fields' => 'ids' ) );
		$ids = array_map( 'intval', $ids );
		sort( $ids );
		return $ids;
	}

	// ---- open_for ----

	public function test_open_for_creates_clone_with_meta_and_terms() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		$this->assertNotSame( $live, $copy );
		$post = get_post( $copy );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( 'post', $post->post_type );
		$this->assertSame( $this->author, (int) $post->post_author );
		$this->assertSame( 0, (int) $post->post_parent );
		$this->assertSame( 'Live title', $post->post_title );
		$this->assertSame( 'Live content', $post->post_content );
		$this->assertSame( 'Live excerpt', $post->post_excerpt );
		$this->assertSame( 'blue', get_post_meta( $copy, 'color', true ) );
		$this->assertSame( array( 'one', 'two' ), get_post_meta( $copy, 'multi' ) );
		$this->assertSame( array( 'a' => 1 ), get_post_meta( $copy, 'arr', true ) );
		$this->assertSame( (string) $this->thumb, (string) get_post_meta( $copy, '_thumbnail_id', true ) );
		$this->assertSame( '', get_post_meta( $copy, '_edit_lock', true ) );
		$this->assertSame( '', get_post_meta( $copy, '_pt_feedback', true ) );
		$this->assertSame( $this->term_ids( $live, 'category' ), $this->term_ids( $copy, 'category' ) );
		$this->assertSame( $this->term_ids( $live, 'post_tag' ), $this->term_ids( $copy, 'post_tag' ) );
		$this->assertSame( $live, PublicationTheory\Staging::live_id( $copy ) );
		$this->assertTrue( PublicationTheory\Staging::is_staged( $copy ) );
		$this->assertFalse( PublicationTheory\Staging::is_staged( $live ) );
		$this->assertNull( PublicationTheory\Staging::live_id( $live ) );
	}

	public function test_open_for_reuses_existing_copy() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		$this->assertSame( $copy, $this->copy_for( $live ) );
		wp_update_post( array( 'ID' => $copy, 'post_status' => 'pending' ) );
		$this->assertSame( $copy, $this->copy_for( $live ) );
	}

	public function test_open_for_rejects_other_author_and_non_live() {
		$live = $this->live();
		$this->assertWPError( PublicationTheory\Staging::open_for( $live, $this->author_b ) );
		$draft = self::factory()->post->create( array( 'post_author' => $this->author, 'post_status' => 'draft' ) );
		$this->assertWPError( PublicationTheory\Staging::open_for( $draft, $this->author ) );
		$copy = $this->copy_for( $live );
		$this->assertWPError( PublicationTheory\Staging::open_for( $copy, $this->author ) );
	}

	// ---- edit links / redirect ----

	public function test_author_edit_link_redirects_to_copy() {
		$live = $this->live();
		wp_set_current_user( $this->author );
		$this->assertNull( get_edit_post_link( $live ) );
		$actions = apply_filters( 'post_row_actions', array(), get_post( $live ) );
		$this->assertArrayHasKey( 'edit', $actions );
		$this->assertStringContainsString( 'action=pt_open_staged', $actions['edit'] );
		$this->assertStringContainsString( 'post_id=' . $live, $actions['edit'] );

		$_GET     = array(
			'post_id'  => (string) $live,
			'_wpnonce' => wp_create_nonce( 'pt_open_staged_' . $live ),
		);
		$_REQUEST = $_GET;
		$location = $this->redirect_of( array( 'PublicationTheory\Staging', 'handle_open' ) );
		$copy     = PublicationTheory\Staging::find_copy( $live );
		$this->assertGreaterThan( 0, $copy );
		$this->assertStringContainsString( 'post.php?post=' . $copy . '&action=edit', $location );

		// Direct visit to the live edit screen: interstitial with continue link and discard form.
		$_GET     = array(
			'post'   => (string) $live,
			'action' => 'edit',
		);
		$_REQUEST = $_GET;
		try {
			PublicationTheory\Staging::on_load_post();
			$this->fail( 'expected interstitial' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'post.php?post=' . $copy . '&#038;action=edit', $e->getMessage() );
			$this->assertStringContainsString( 'value="pt_discard_staged"', $e->getMessage() );
		}
	}

	public function test_direct_visit_without_copy_shows_nonce_link_not_side_effect() {
		$live = $this->live();
		wp_set_current_user( $this->author );
		$_GET     = array(
			'post'   => (string) $live,
			'action' => 'edit',
		);
		$_REQUEST = $_GET;
		$this->trap();
		try {
			PublicationTheory\Staging::on_load_post();
			$this->fail( 'expected interstitial' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'pt_open_staged', $e->getMessage() );
		}
		$this->assertSame( 0, PublicationTheory\Staging::find_copy( $live ) );
	}

	public function test_open_endpoint_rejects_bad_nonce_and_other_author() {
		$live = $this->live();
		wp_set_current_user( $this->author );
		$_GET     = array(
			'post_id'  => (string) $live,
			'_wpnonce' => 'bad',
		);
		$_REQUEST = $_GET;
		$this->trap();
		try {
			PublicationTheory\Staging::handle_open();
			$this->fail( 'expected die' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 0, PublicationTheory\Staging::find_copy( $live ) );
		}
		wp_set_current_user( $this->author_b );
		$_GET     = array(
			'post_id'  => (string) $live,
			'_wpnonce' => wp_create_nonce( 'pt_open_staged_' . $live ),
		);
		$_REQUEST = $_GET;
		try {
			PublicationTheory\Staging::handle_open();
			$this->fail( 'expected die' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 0, PublicationTheory\Staging::find_copy( $live ) );
		}
	}

	public function test_editor_edit_link_unchanged() {
		$live = $this->live();
		wp_set_current_user( $this->editor );
		$this->assertStringContainsString( 'post.php?post=' . $live . '&amp;action=edit', get_edit_post_link( $live ) );
		$this->assertTrue( current_user_can( 'edit_post', $live ) );
		$actions = apply_filters( 'post_row_actions', array( 'edit' => 'core' ), get_post( $live ) );
		$this->assertSame( 'core', $actions['edit'] );
		$_GET     = array(
			'post'   => (string) $live,
			'action' => 'edit',
		);
		$_REQUEST = $_GET;
		$this->assertSame( '', $this->redirect_of( array( 'PublicationTheory\Staging', 'on_load_post' ) ) );
		$this->assertSame( 0, PublicationTheory\Staging::find_copy( $live ) );
	}

	// ---- live write blocking ----

	public function test_author_cannot_change_live_via_functions() {
		$live = $this->live();
		wp_set_current_user( $this->author );
		$this->assertFalse( current_user_can( 'edit_post', $live ) );
		$this->assertFalse( current_user_can( 'delete_post', $live ) );
		$this->assertTrue( current_user_can( 'edit_published_posts' ) );

		wp_update_post(
			array(
				'ID'           => $live,
				'post_title'   => 'Hacked',
				'post_content' => 'Hacked',
				'post_excerpt' => 'Hacked',
				'post_status'  => 'draft',
				'meta_input'   => array( 'color' => 'red' ),
			)
		);
		update_post_meta( $live, 'color', 'red' );
		add_post_meta( $live, 'newkey', 'x' );
		delete_post_meta( $live, 'multi' );
		update_post_meta( $live, '_thumbnail_id', 999 );
		delete_post_thumbnail( $live );
		wp_set_object_terms( $live, array( $this->cat_b ), 'category' );
		wp_set_object_terms( $live, array( 'evil' ), 'post_tag', true );
		wp_remove_object_terms( $live, array( 'tag1' ), 'post_tag' );
		wp_trash_post( $live );
		wp_delete_post( $live, true );

		clean_post_cache( $live );
		$post = get_post( $live );
		$this->assertNotNull( $post );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 'Live title', $post->post_title );
		$this->assertSame( 'Live content', $post->post_content );
		$this->assertSame( 'Live excerpt', $post->post_excerpt );
		$this->assertSame( 'blue', get_post_meta( $live, 'color', true ) );
		$this->assertSame( '', get_post_meta( $live, 'newkey', true ) );
		$this->assertSame( array( 'one', 'two' ), get_post_meta( $live, 'multi' ) );
		$this->assertSame( (string) $this->thumb, (string) get_post_meta( $live, '_thumbnail_id', true ) );
		$this->assertSame( array( $this->cat_a ), $this->term_ids( $live, 'category' ) );
		$this->assertSame( array( 'tag1' ), wp_get_object_terms( $live, 'post_tag', array( 'fields' => 'names' ) ) );
	}

	public function test_author_cannot_change_live_via_rest() {
		$live = $this->live();
		wp_set_current_user( $this->author );
		$req = new WP_REST_Request( 'PUT', '/wp/v2/posts/' . $live );
		$req->set_param( 'title', 'Hacked' );
		$req->set_param( 'categories', array( $this->cat_b ) );
		$req->set_param( 'featured_media', 0 );
		$this->assertSame( 403, rest_do_request( $req )->get_status() );
		$req = new WP_REST_Request( 'DELETE', '/wp/v2/posts/' . $live );
		$this->assertSame( 403, rest_do_request( $req )->get_status() );
		$req = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $live . '/autosaves' );
		$req->set_param( 'content', 'Hacked' );
		$this->assertContains( rest_do_request( $req )->get_status(), array( 401, 403 ) );
		$this->assertSame( 'Live title', get_post( $live )->post_title );
		$this->assertSame( array( $this->cat_a ), $this->term_ids( $live, 'category' ) );
	}

	public function test_author_cannot_forge_staged_marker() {
		$own  = self::factory()->post->create( array( 'post_author' => $this->author, 'post_status' => 'draft' ) );
		$live = $this->live( $this->author_b );
		wp_set_current_user( $this->author );
		update_post_meta( $own, '_pt_staged_for', $live );
		$this->assertFalse( PublicationTheory\Staging::is_staged( $own ) );
	}

	public function test_editor_can_change_live() {
		$live = $this->live();
		wp_set_current_user( $this->editor );
		wp_update_post( array( 'ID' => $live, 'post_title' => 'Edited' ) );
		update_post_meta( $live, 'color', 'red' );
		wp_set_object_terms( $live, array( $this->cat_b ), 'category' );
		$this->assertSame( 'Edited', get_post( $live )->post_title );
		$this->assertSame( 'red', get_post_meta( $live, 'color', true ) );
		$this->assertSame( array( $this->cat_b ), $this->term_ids( $live, 'category' ) );
		$req = new WP_REST_Request( 'PUT', '/wp/v2/posts/' . $live );
		$req->set_param( 'title', 'Via REST' );
		$this->assertSame( 200, rest_do_request( $req )->get_status() );
		$this->assertSame( 'Via REST', get_post( $live )->post_title );
	}

	public function test_no_user_context_unaffected() {
		$live = $this->live();
		wp_update_post( array( 'ID' => $live, 'post_title' => 'Cron' ) );
		update_post_meta( $live, 'color', 'green' );
		$this->assertSame( 'Cron', get_post( $live )->post_title );
		$this->assertSame( 'green', get_post_meta( $live, 'color', true ) );
	}

	public function test_author_can_edit_own_copy() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_set_current_user( $this->author );
		$this->assertTrue( current_user_can( 'edit_post', $copy ) );
		$req = new WP_REST_Request( 'PUT', '/wp/v2/posts/' . $copy );
		$req->set_param( 'title', 'Proposed' );
		$this->assertSame( 200, rest_do_request( $req )->get_status() );
		update_post_meta( $copy, 'color', 'red' );
		$this->assertSame( 'Proposed', get_post( $copy )->post_title );
		$this->assertSame( 'red', get_post_meta( $copy, 'color', true ) );
		$req = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $copy );
		$req->set_param( 'context', 'edit' );
		$this->assertSame( 200, rest_do_request( $req )->get_status() );
		$this->assertSame( 'Live title', get_post( $live )->post_title );
	}

	public function test_other_author_cannot_reach_copy() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_set_current_user( $this->author_b );
		$this->assertFalse( current_user_can( 'edit_post', $copy ) );
		$req = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $copy );
		$req->set_param( 'context', 'edit' );
		$this->assertContains( rest_do_request( $req )->get_status(), array( 401, 403 ) );
		$this->assertWPError( PublicationTheory\Staging::approve( $copy ) );
	}

	public function test_anonymous_rest_cannot_read_copy() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_update_post( array( 'ID' => $copy, 'post_status' => 'pending' ) );
		$res = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $copy ) );
		$this->assertContains( $res->get_status(), array( 401, 403, 404 ) );
	}

	// ---- copy never public ----

	public function test_copy_never_published_even_by_editor() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		foreach ( array( $this->editor, 0 ) as $user ) {
			wp_set_current_user( $user );
			foreach ( array( 'publish', 'future', 'private' ) as $status ) {
				wp_update_post( array( 'ID' => $copy, 'post_status' => $status, 'post_date' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
				$this->assertSame( 'pending', get_post_status( $copy ), "$status as $user" );
			}
		}
		wp_set_current_user( $this->editor );
		$req = new WP_REST_Request( 'PUT', '/wp/v2/posts/' . $copy );
		$req->set_param( 'status', 'publish' );
		rest_do_request( $req );
		$this->assertSame( 'pending', get_post_status( $copy ) );
	}

	// ---- hiding ----

	public function test_copy_hidden_from_main_query_rest_search_sitemap() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_update_post( array( 'ID' => $copy, 'post_status' => 'pending', 'post_title' => 'Zebracopy' ) );
		wp_set_current_user( $this->editor );

		$ids = ( new WP_Query( array( 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1 ) ) )->posts;
		$this->assertContains( $live, $ids );
		$this->assertNotContains( $copy, $ids );

		$ids = get_posts( array( 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1, 'post_type' => 'any' ) );
		$this->assertNotContains( $copy, $ids );

		$ids = ( new WP_Query( array( 's' => 'Zebracopy', 'post_status' => 'any', 'fields' => 'ids' ) ) )->posts;
		$this->assertSame( array(), $ids );

		$req = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$req->set_param( 'status', 'pending' );
		$req->set_param( 'context', 'edit' );
		$res = rest_do_request( $req );
		$this->assertSame( 200, $res->get_status() );
		$this->assertNotContains( $copy, wp_list_pluck( $res->get_data(), 'id' ) );

		$args = apply_filters( 'wp_sitemaps_posts_query_args', array( 'post_type' => 'post', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1 ), 'post' );
		$this->assertNotContains( $copy, ( new WP_Query( $args ) )->posts );

		$this->go_to( '/?s=Zebracopy' );
		$this->assertSame( array(), wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );

		$ids = ( new WP_Query( array( 'post_status' => 'pending', 'fields' => 'ids', 'pt_include_staged' => true ) ) )->posts;
		$this->assertContains( $copy, $ids );

		// Queue still lists it.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		PublicationTheory\Queue::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Zebracopy', $html );
		$this->assertStringContainsString( 'Staged edit', $html );
		$this->assertMatchesRegularExpression( '/page=pt-staged-review(&amp;|&#038;|&)copy=' . $copy . '"/', $html );
	}

	public function test_query_var_not_public() {
		$this->assertNotContains( 'pt_include_staged', $GLOBALS['wp']->public_query_vars );
	}

	// ---- approve ----

	public function test_approve_updates_live_keeps_revision_deletes_copy() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_set_current_user( $this->author );
		wp_update_post( array( 'ID' => $copy, 'post_title' => 'New title', 'post_content' => 'New content', 'post_excerpt' => 'New excerpt' ) );
		update_post_meta( $copy, 'color', 'red' );
		delete_post_meta( $copy, 'arr' );
		add_post_meta( $copy, 'added', 'yes' );
		update_post_meta( $copy, '_wp_page_template', 'wide.php' );
		$thumb2 = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $copy, '_thumbnail_id', $thumb2 );
		wp_set_object_terms( $copy, array( $this->cat_b ), 'category' );
		wp_set_object_terms( $copy, array(), 'post_tag' );
		PublicationTheory\Workflow::submit( $copy );
		reset_phpmailer_instance();

		wp_set_current_user( $this->editor );
		$this->assertSame( $live, PublicationTheory\Staging::approve( $copy ) );

		$post = get_post( $live );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 'New title', $post->post_title );
		$this->assertSame( 'New content', $post->post_content );
		$this->assertSame( 'New excerpt', $post->post_excerpt );
		$this->assertSame( $this->author, (int) $post->post_author );
		$this->assertSame( 'red', get_post_meta( $live, 'color', true ) );
		$this->assertSame( '', get_post_meta( $live, 'arr', true ) );
		$this->assertSame( 'yes', get_post_meta( $live, 'added', true ) );
		$this->assertSame( array( 'one', 'two' ), get_post_meta( $live, 'multi' ) );
		$this->assertSame( 'wide.php', get_post_meta( $live, '_wp_page_template', true ) );
		$this->assertSame( (string) $thumb2, (string) get_post_meta( $live, '_thumbnail_id', true ) );
		$this->assertSame( '', get_post_meta( $live, '_pt_staged_for', true ) );
		$this->assertSame( array( $this->cat_b ), $this->term_ids( $live, 'category' ) );
		$this->assertSame( array(), $this->term_ids( $live, 'post_tag' ) );

		$contents = wp_list_pluck( wp_get_post_revisions( $live ), 'post_content' );
		$this->assertContains( 'Live content', $contents );
		$this->assertNull( get_post( $copy ) );
		$this->assertSame( array( 'ann@example.org' ), $this->recipients() );
		$this->assertSame( 'staged_approved', PublicationTheory\Log::entries()[0]['event'] );
		$this->assertSame( $live, (int) PublicationTheory\Log::entries()[0]['post_id'] );
	}

	public function test_approve_after_editor_edit_overwrites_and_keeps_prior_revision() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_set_current_user( $this->author );
		wp_update_post( array( 'ID' => $copy, 'post_content' => 'Author version' ) );
		PublicationTheory\Workflow::submit( $copy );
		wp_set_current_user( $this->editor );
		wp_update_post( array( 'ID' => $live, 'post_content' => 'Editor hotfix' ) );
		$this->assertSame( $live, PublicationTheory\Staging::approve( $copy ) );
		$this->assertSame( 'Author version', get_post( $live )->post_content );
		$contents = wp_list_pluck( wp_get_post_revisions( $live ), 'post_content' );
		$this->assertContains( 'Editor hotfix', $contents );
	}

	public function test_approve_requires_editor_cap() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_set_current_user( $this->author );
		wp_update_post( array( 'ID' => $copy, 'post_title' => 'Mine' ) );
		PublicationTheory\Workflow::submit( $copy );
		$res = PublicationTheory\Staging::approve( $copy );
		$this->assertWPError( $res );
		$this->assertSame( 'pt_forbidden', $res->get_error_code() );
		$this->assertSame( 'Live title', get_post( $live )->post_title );
		$this->assertNotNull( get_post( $copy ) );
		wp_set_current_user( $this->editor );
		$this->assertWPError( PublicationTheory\Staging::approve( $live ) );
	}

	public function test_approve_endpoint_nonce_and_redirect() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_update_post( array( 'ID' => $copy, 'post_status' => 'pending', 'post_title' => 'Approved title' ) );
		wp_set_current_user( $this->editor );
		$_POST    = array(
			'post_id'  => (string) $copy,
			'_wpnonce' => 'bad',
		);
		$_REQUEST = $_POST;
		$this->trap();
		try {
			PublicationTheory\Staging_Review::handle_approve();
			$this->fail( 'expected die' );
		} catch ( WPDieException $e ) {
			$this->assertNotNull( get_post( $copy ) );
		}
		$_POST['_wpnonce'] = wp_create_nonce( 'pt_approve_staged_' . $copy );
		$_REQUEST          = $_POST;
		$location          = $this->redirect_of( array( 'PublicationTheory\Staging_Review', 'handle_approve' ) );
		$this->assertStringContainsString( 'page=pt-review-queue', $location );
		$this->assertStringContainsString( 'pt_notice=approved', $location );
		$this->assertSame( 'Approved title', get_post( $live )->post_title );
	}

	public function test_approve_endpoint_author_gets_error() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_update_post( array( 'ID' => $copy, 'post_status' => 'pending', 'post_title' => 'X' ) );
		wp_set_current_user( $this->author );
		$_POST    = array(
			'post_id'  => (string) $copy,
			'_wpnonce' => wp_create_nonce( 'pt_approve_staged_' . $copy ),
		);
		$_REQUEST = $_POST;
		$location = $this->redirect_of( array( 'PublicationTheory\Staging_Review', 'handle_approve' ) );
		$this->assertStringContainsString( 'pt_code=pt_forbidden', $location );
		$this->assertSame( 'Live title', get_post( $live )->post_title );
	}

	// ---- send back / submit ----

	public function test_send_back_staged_copy_returns_draft_and_mails() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_set_current_user( $this->author );
		PublicationTheory\Workflow::submit( $copy );
		reset_phpmailer_instance();
		wp_set_current_user( $this->editor );
		$this->assertTrue( PublicationTheory\Workflow::send_back( $copy, 'Tone it down' ) );
		$this->assertSame( 'draft', get_post_status( $copy ) );
		$this->assertTrue( PublicationTheory\Staging::is_staged( $copy ) );
		$this->assertSame( array( 'ann@example.org' ), $this->recipients() );
		$this->assertSame( 'Live title', get_post( $live )->post_title );
	}

	public function test_submit_staged_mails_editors_with_staged_event() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_set_current_user( $this->author );
		reset_phpmailer_instance();
		$this->assertTrue( PublicationTheory\Workflow::submit( $copy ) );
		$this->assertSame( array( 'admin@example.org', 'ed1@example.org' ), $this->recipients() );
		$events = wp_list_pluck( PublicationTheory\Log::entries(), 'event' );
		$this->assertContains( 'staged_submitted', $events );
		$this->assertNotContains( 'submitted', $events );
		$this->assertStringContainsString( 'Update review requested', tests_retrieve_phpmailer_instance()->mock_sent[0]['subject'] );
	}

	// ---- lifecycle ----

	public function test_trashing_live_trashes_copy() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_set_current_user( $this->editor );
		wp_trash_post( $live );
		$this->assertSame( 'trash', get_post_status( $copy ) );
		wp_delete_post( $live, true );
		$this->assertNull( get_post( $copy ) );
	}

	public function test_deleting_live_deletes_copy() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_delete_post( $live, true );
		$this->assertNull( get_post( $copy ) );
	}

	public function test_kind_filter() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		$this->assertSame( 'Staged edit', PublicationTheory\Queue::kind( $copy ) );
		$this->assertSame( 'New', PublicationTheory\Queue::kind( $live ) );
	}

	// ---- review screen ----

	public function test_review_screen_renders_escaped_diff_and_forms() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_update_post( array( 'ID' => $copy, 'post_status' => 'pending', 'post_content' => '<script>alert(1)</script>Changed' ) );
		wp_set_current_user( $this->editor );
		$_GET = array( 'copy' => (string) $copy );
		ob_start();
		PublicationTheory\Staging_Review::render();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( 'Live title', $html );
		$this->assertStringContainsString( 'name="action" value="pt_approve_staged"', $html );
		$this->assertStringContainsString( 'name="action" value="pt_send_back"', $html );
		$this->assertStringContainsString( '<h1', $html );
		$this->assertStringContainsString( '<table', $html );
	}

	public function test_review_screen_forbidden_for_author() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_set_current_user( $this->author );
		$_GET = array( 'copy' => (string) $copy );
		$this->trap();
		$this->expectException( WPDieException::class );
		PublicationTheory\Staging_Review::render();
	}

	public function test_live_notice_for_editor() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_update_post( array( 'ID' => $copy, 'post_status' => 'pending' ) );
		wp_set_current_user( $this->editor );
		$this->assertStringContainsString( 'Changes pending review', PublicationTheory\Staging::notice_html( $live ) );
		$this->assertStringContainsString( 'copy=' . $copy, PublicationTheory\Staging::notice_html( $live ) );
		wp_set_current_user( $this->author );
		$this->assertSame( '', PublicationTheory\Staging::notice_html( $live ) );
	}

	// ---- fix round 1: 3-way merge, full diff, meta allowlist, post type pin ----

	public function test_approve_three_way_merge_keeps_live_only_changes() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		$tag2 = self::factory()->tag->create( array( 'name' => 'tag2' ) );
		// Author: remove 'arr', add category Beta, change color.
		wp_set_current_user( $this->author );
		delete_post_meta( $copy, 'arr' );
		update_post_meta( $copy, 'color', 'red' );
		wp_set_object_terms( $copy, array( $this->cat_a, $this->cat_b ), 'category' );
		PublicationTheory\Workflow::submit( $copy );
		// Live changes after copy was opened (editor / plugin).
		wp_set_current_user( $this->editor );
		update_post_meta( $live, 'rank_math_title', 'SEO' );
		update_post_meta( $live, 'color', 'green' );
		wp_set_object_terms( $live, array( $tag2 ), 'post_tag', true );

		ob_start();
		$_GET = array( 'copy' => (string) $copy );
		PublicationTheory\Staging_Review::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'rank_math_title', $html );
		$this->assertStringContainsString( 'Tags', $html );

		$this->assertSame( $live, PublicationTheory\Staging::approve( $copy ) );
		$this->assertSame( 'SEO', get_post_meta( $live, 'rank_math_title', true ) );
		$this->assertSame( 'red', get_post_meta( $live, 'color', true ) );
		$this->assertSame( '', get_post_meta( $live, 'arr', true ) );
		$this->assertSame( array( 'one', 'two' ), get_post_meta( $live, 'multi' ) );
		$expected = array( $this->cat_a, $this->cat_b );
		sort( $expected );
		$this->assertSame( $expected, $this->term_ids( $live, 'category' ) );
		$tags = wp_get_object_terms( $live, 'post_tag', array( 'fields' => 'names' ) );
		sort( $tags );
		$this->assertSame( array( 'tag1', 'tag2' ), $tags );
	}

	private function other_section( int $copy ): string {
		$html = PublicationTheory\Staging_Diff::render( $copy );
		$pos  = strpos( $html, 'id="pt-diff-other"' );
		$this->assertNotFalse( $pos );
		return substr( $html, $pos );
	}

	public function test_diff_shows_image_change() {
		$live   = $this->live();
		$copy   = $this->copy_for( $live );
		$this->assertStringContainsString( 'No changes', $this->other_section( $copy ) );
		$thumb2 = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'New hero' ) );
		update_post_meta( $copy, '_thumbnail_id', $thumb2 );
		$html = $this->other_section( $copy );
		$this->assertStringNotContainsString( 'No changes', $html );
		$this->assertStringContainsString( 'Featured image', $html );
		$this->assertStringContainsString( 'New hero', $html );
	}

	public function test_diff_shows_category_change() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_set_object_terms( $copy, array( $this->cat_b ), 'category' );
		$html = $this->other_section( $copy );
		$this->assertStringNotContainsString( 'No changes', $html );
		$this->assertStringContainsString( 'Added: Beta', $html );
		$this->assertStringContainsString( 'Removed: Alpha', $html );
	}

	public function test_diff_shows_meta_change_escaped_truncated() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		update_post_meta( $copy, '_wp_page_template', 'wide.php' );
		update_post_meta( $copy, 'arr', array( 'x' => '<script>alert(1)</script>' ) );
		update_post_meta( $copy, 'long', str_repeat( 'z', 500 ) );
		$html = $this->other_section( $copy );
		$this->assertStringNotContainsString( 'No changes', $html );
		$this->assertStringContainsString( '_wp_page_template', $html );
		$this->assertStringContainsString( 'wide.php', $html );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringNotContainsString( str_repeat( 'z', 201 ), $html );
		$this->assertStringNotContainsString( '_pt_', $html );
	}

	public function test_live_meta_allowlist_and_filter() {
		$live = $this->live();
		wp_set_current_user( $this->author );
		update_post_meta( $live, '_oembed_abc', '<iframe></iframe>' );
		update_post_meta( $live, '_wp_desired_post_slug', 'x' );
		update_post_meta( $live, 'view_count', '5' );
		update_post_meta( $live, '_thumbnail_id', 999 );
		update_post_meta( $live, 'custom_field', 'x' );
		update_post_meta( $live, '_pt_feedback', 'forged' );
		$this->assertSame( '<iframe></iframe>', get_post_meta( $live, '_oembed_abc', true ) );
		$this->assertSame( 'x', get_post_meta( $live, '_wp_desired_post_slug', true ) );
		$this->assertSame( '', get_post_meta( $live, 'view_count', true ) );
		$this->assertSame( (string) $this->thumb, (string) get_post_meta( $live, '_thumbnail_id', true ) );
		$this->assertSame( '', get_post_meta( $live, 'custom_field', true ) );
		$this->assertSame( array( 'x' => 1 ), get_post_meta( $live, '_pt_feedback', true ) );

		add_filter(
			'pt_allowed_live_meta_keys',
			static function ( $keys ) {
				$keys[] = 'view_count';
				$keys[] = '_pt_';
				return $keys;
			}
		);
		update_post_meta( $live, 'view_count', '6' );
		update_post_meta( $live, '_pt_feedback', 'forged' );
		remove_all_filters( 'pt_allowed_live_meta_keys' );
		$this->assertSame( '6', get_post_meta( $live, 'view_count', true ) );
		$this->assertSame( array( 'x' => 1 ), get_post_meta( $live, '_pt_feedback', true ) );
	}

	// ---- final review wave ----

	public function test_draft_copy_visible_to_editor_and_discardable() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_set_current_user( $this->editor );
		$html = PublicationTheory\Staging::notice_html( $live );
		$this->assertStringContainsString( 'in draft', $html );
		$this->assertStringContainsString( 'copy=' . $copy, $html );
		$this->assertStringContainsString( 'pt_discard_staged', $html );

		$_GET = array( 'copy' => (string) $copy );
		ob_start();
		PublicationTheory\Staging_Review::render();
		$review = ob_get_clean();
		$this->assertStringNotContainsString( 'value="pt_approve_staged"', $review );
		$this->assertStringContainsString( 'value="pt_discard_staged"', $review );

		$_POST    = array(
			'post_id'  => (string) $copy,
			'_wpnonce' => wp_create_nonce( 'pt_discard_staged_' . $copy ),
		);
		$_REQUEST = $_POST;
		$location = $this->redirect_of( array( 'PublicationTheory\Staging_Review', 'handle_discard' ) );
		$this->assertStringContainsString( 'pt_notice=discarded', $location );
		$this->assertNull( get_post( $copy ) );
		$this->assertSame( 'Live title', get_post( $live )->post_title );
		// Another author can now open a copy.
		$this->assertSame( 0, PublicationTheory\Staging::find_copy( $live ) );
	}

	public function test_discard_permissions_and_nonce() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		$this->trap();
		// Bad nonce.
		wp_set_current_user( $this->author );
		$_POST    = array(
			'post_id'  => (string) $copy,
			'_wpnonce' => 'bad',
		);
		$_REQUEST = $_POST;
		try {
			PublicationTheory\Staging_Review::handle_discard();
			$this->fail( 'expected die' );
		} catch ( WPDieException $e ) {
			$this->assertNotNull( get_post( $copy ) );
		}
		// Other author.
		wp_set_current_user( $this->author_b );
		$this->assertSame( 'pt_forbidden', PublicationTheory\Staging::discard( $copy )->get_error_code() );
		$this->assertNotNull( get_post( $copy ) );
		$this->assertWPError( PublicationTheory\Staging::discard( $live ) );
		// Own author.
		wp_set_current_user( $this->author );
		$this->assertSame( $live, PublicationTheory\Staging::discard( $copy ) );
		$this->assertNull( get_post( $copy ) );
	}

	public function test_locked_copy_names_holder() {
		$live = self::factory()->post->create( array( 'post_author' => $this->author, 'post_status' => 'publish' ) );
		wp_update_user( array( 'ID' => $this->author_b, 'display_name' => 'Bob <b>B</b>' ) );
		$copy = self::factory()->post->create( array( 'post_author' => $this->author_b, 'post_status' => 'draft' ) );
		add_post_meta( $copy, '_pt_staged_for', (string) $live );
		$res = PublicationTheory\Staging::open_for( $live, $this->author );
		$this->assertSame( 'pt_copy_locked', $res->get_error_code() );
		$this->assertStringContainsString( 'already being edited by Bob', $res->get_error_message() );
		wp_set_current_user( $this->author );
		$_GET     = array(
			'post'   => (string) $live,
			'action' => 'edit',
		);
		$_REQUEST = $_GET;
		$this->trap();
		try {
			PublicationTheory\Staging::on_load_post();
			$this->fail( 'expected lock page' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'ask an Editor to discard that copy', $e->getMessage() );
			$this->assertStringNotContainsString( '<b>B</b>', $e->getMessage() );
		}
	}

	public function test_live_field_change_warned_and_revision_kept() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_set_current_user( $this->author );
		wp_update_post( array( 'ID' => $copy, 'post_content' => 'Author version' ) );
		PublicationTheory\Workflow::submit( $copy );
		wp_set_current_user( $this->editor );
		wp_update_post( array( 'ID' => $live, 'post_content' => 'Editor hotfix' ) );
		$html = PublicationTheory\Staging_Diff::render( $copy );
		$this->assertStringContainsString( 'Live changed since this copy was opened: Content', $html );
		$this->assertSame( $live, PublicationTheory\Staging::approve( $copy ) );
		$this->assertSame( 'Author version', get_post( $live )->post_content );
		$this->assertContains( 'Editor hotfix', wp_list_pluck( wp_get_post_revisions( $live ), 'post_content' ) );
	}

	public function test_base_without_field_hashes_does_not_error() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		$base = get_post_meta( $copy, '_pt_base', true );
		unset( $base['fields'] );
		update_post_meta( $copy, '_pt_base', $base );
		wp_update_post( array( 'ID' => $live, 'post_content' => 'Changed' ) );
		$plan = PublicationTheory\Staging::plan( $copy );
		$this->assertSame( array(), $plan['live_changed']['fields'] );
		$this->assertIsString( PublicationTheory\Staging_Diff::render( $copy ) );
	}

	public function test_feedback_moves_to_live_on_approve_idempotent() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_set_current_user( $this->author );
		PublicationTheory\Workflow::submit( $copy );
		wp_set_current_user( $this->editor );
		PublicationTheory\Workflow::send_back( $copy, 'Tone it down' );
		wp_set_current_user( $this->author );
		PublicationTheory\Workflow::submit( $copy );
		$entry = PublicationTheory\Feedback::latest( $copy );
		// Simulate a retry where the entry already reached live.
		add_post_meta( $live, PublicationTheory\Feedback::META_KEY, wp_slash( $entry ) );
		wp_set_current_user( $this->editor );
		$this->assertSame( $live, PublicationTheory\Staging::approve( $copy ) );
		$count = 0;
		foreach ( PublicationTheory\Feedback::history( $live ) as $row ) {
			$count += ( $row['note'] ?? '' ) === 'Tone it down' ? 1 : 0;
		}
		$this->assertSame( 1, $count );
	}

	public function test_feedback_moved_on_approve() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_set_current_user( $this->author );
		PublicationTheory\Workflow::submit( $copy );
		wp_set_current_user( $this->editor );
		PublicationTheory\Workflow::send_back( $copy, 'Fix intro' );
		wp_set_current_user( $this->author );
		PublicationTheory\Workflow::submit( $copy );
		wp_set_current_user( $this->editor );
		PublicationTheory\Staging::approve( $copy );
		$this->assertSame( 'Fix intro', PublicationTheory\Feedback::latest( $live )['note'] );
	}

	public function test_rest_publish_copy_returns_409_and_non_rest_reverts_with_notice() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_update_post( array( 'ID' => $copy, 'post_status' => 'pending' ) );
		wp_set_current_user( $this->editor );
		$req = new WP_REST_Request( 'PUT', '/wp/v2/posts/' . $copy );
		$req->set_param( 'status', 'publish' );
		$res = rest_do_request( $req );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'pt_use_review_screen', $res->get_data()['code'] );
		$this->assertSame( 'pending', get_post_status( $copy ) );

		wp_update_post( array( 'ID' => $copy, 'post_status' => 'publish' ) );
		$this->assertSame( 'pending', get_post_status( $copy ) );
		ob_start();
		PublicationTheory\Staging::blocked_publish_notice();
		$this->assertStringContainsString( 'Use Approve on the review screen', ob_get_clean() );

		$html = PublicationTheory\Staging::notice_html( $copy );
		$this->assertStringContainsString( 'Proposed version', $html );
		$this->assertStringContainsString( 'copy=' . $copy, $html );
	}

	public function test_staged_submit_mail_links_review_screen() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_set_current_user( $this->author );
		reset_phpmailer_instance();
		PublicationTheory\Workflow::submit( $copy );
		$body = tests_retrieve_phpmailer_instance()->mock_sent[0]['body'];
		$this->assertStringContainsString( 'page=pt-staged-review&copy=' . $copy, $body );
	}

	public function test_copy_post_type_pinned() {
		register_post_type( 'pt_private_t', array( 'public' => false ) );
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_set_current_user( $this->author );
		wp_update_post( array( 'ID' => $copy, 'post_type' => 'pt_private_t' ) );
		$this->assertSame( 'post', get_post_type( $copy ) );
		wp_set_current_user( $this->editor );
		wp_update_post( array( 'ID' => $copy, 'post_type' => 'page' ) );
		$this->assertSame( 'post', get_post_type( $copy ) );
		unregister_post_type( 'pt_private_t' );
	}
}
