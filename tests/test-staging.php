<?php
class SPW_Staging_Test extends WP_UnitTestCase {

	private $author;
	private $author_b;
	private $editor;
	private $cat_a;
	private $cat_b;
	private $thumb;

	public function set_up() {
		parent::set_up();
		delete_option( 'spw_workflow_log' );
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
		delete_option( 'spw_workflow_log' );
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
		update_post_meta( $id, '_spw_feedback', array( 'x' => 1 ) );
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
		$copy = SPW\Staging::open_for( $live, $author ?? $this->author );
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
		$this->assertSame( '', get_post_meta( $copy, '_spw_feedback', true ) );
		$this->assertSame( $this->term_ids( $live, 'category' ), $this->term_ids( $copy, 'category' ) );
		$this->assertSame( $this->term_ids( $live, 'post_tag' ), $this->term_ids( $copy, 'post_tag' ) );
		$this->assertSame( $live, SPW\Staging::live_id( $copy ) );
		$this->assertTrue( SPW\Staging::is_staged( $copy ) );
		$this->assertFalse( SPW\Staging::is_staged( $live ) );
		$this->assertNull( SPW\Staging::live_id( $live ) );
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
		$this->assertWPError( SPW\Staging::open_for( $live, $this->author_b ) );
		$draft = self::factory()->post->create( array( 'post_author' => $this->author, 'post_status' => 'draft' ) );
		$this->assertWPError( SPW\Staging::open_for( $draft, $this->author ) );
		$copy = $this->copy_for( $live );
		$this->assertWPError( SPW\Staging::open_for( $copy, $this->author ) );
	}

	// ---- edit links / redirect ----

	public function test_author_edit_link_redirects_to_copy() {
		$live = $this->live();
		wp_set_current_user( $this->author );
		$this->assertNull( get_edit_post_link( $live ) );
		$actions = apply_filters( 'post_row_actions', array(), get_post( $live ) );
		$this->assertArrayHasKey( 'edit', $actions );
		$this->assertStringContainsString( 'action=spw_open_staged', $actions['edit'] );
		$this->assertStringContainsString( 'post_id=' . $live, $actions['edit'] );

		$_GET     = array(
			'post_id'  => (string) $live,
			'_wpnonce' => wp_create_nonce( 'spw_open_staged_' . $live ),
		);
		$_REQUEST = $_GET;
		$location = $this->redirect_of( array( 'SPW\Staging', 'handle_open' ) );
		$copy     = SPW\Staging::find_copy( $live );
		$this->assertGreaterThan( 0, $copy );
		$this->assertStringContainsString( 'post.php?post=' . $copy . '&action=edit', $location );

		// Direct visit to the live edit screen goes to the existing copy.
		$_GET     = array(
			'post'   => (string) $live,
			'action' => 'edit',
		);
		$_REQUEST = $_GET;
		$location = $this->redirect_of( array( 'SPW\Staging', 'on_load_post' ) );
		$this->assertStringContainsString( 'post.php?post=' . $copy . '&action=edit', $location );
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
			SPW\Staging::on_load_post();
			$this->fail( 'expected interstitial' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'spw_open_staged', $e->getMessage() );
		}
		$this->assertSame( 0, SPW\Staging::find_copy( $live ) );
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
			SPW\Staging::handle_open();
			$this->fail( 'expected die' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 0, SPW\Staging::find_copy( $live ) );
		}
		wp_set_current_user( $this->author_b );
		$_GET     = array(
			'post_id'  => (string) $live,
			'_wpnonce' => wp_create_nonce( 'spw_open_staged_' . $live ),
		);
		$_REQUEST = $_GET;
		try {
			SPW\Staging::handle_open();
			$this->fail( 'expected die' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 0, SPW\Staging::find_copy( $live ) );
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
		$this->assertSame( '', $this->redirect_of( array( 'SPW\Staging', 'on_load_post' ) ) );
		$this->assertSame( 0, SPW\Staging::find_copy( $live ) );
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
		update_post_meta( $own, '_spw_staged_for', $live );
		$this->assertFalse( SPW\Staging::is_staged( $own ) );
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
		$this->assertWPError( SPW\Staging::approve( $copy ) );
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

		$ids = ( new WP_Query( array( 'post_status' => 'pending', 'fields' => 'ids', 'spw_include_staged' => true ) ) )->posts;
		$this->assertContains( $copy, $ids );

		// Queue still lists it.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		SPW\Queue::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Zebracopy', $html );
		$this->assertStringContainsString( 'Staged edit', $html );
		$this->assertMatchesRegularExpression( '/page=spw-staged-review(&amp;|&#038;|&)copy=' . $copy . '"/', $html );
	}

	public function test_query_var_not_public() {
		$this->assertNotContains( 'spw_include_staged', $GLOBALS['wp']->public_query_vars );
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
		SPW\Workflow::submit( $copy );
		reset_phpmailer_instance();

		wp_set_current_user( $this->editor );
		$this->assertSame( $live, SPW\Staging::approve( $copy ) );

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
		$this->assertSame( '', get_post_meta( $live, '_spw_staged_for', true ) );
		$this->assertSame( array( $this->cat_b ), $this->term_ids( $live, 'category' ) );
		$this->assertSame( array(), $this->term_ids( $live, 'post_tag' ) );

		$contents = wp_list_pluck( wp_get_post_revisions( $live ), 'post_content' );
		$this->assertContains( 'Live content', $contents );
		$this->assertNull( get_post( $copy ) );
		$this->assertSame( array( 'ann@example.org' ), $this->recipients() );
		$this->assertSame( 'staged_approved', SPW\Log::entries()[0]['event'] );
		$this->assertSame( $live, (int) SPW\Log::entries()[0]['post_id'] );
	}

	public function test_approve_after_editor_edit_overwrites_and_keeps_prior_revision() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_set_current_user( $this->author );
		wp_update_post( array( 'ID' => $copy, 'post_content' => 'Author version' ) );
		SPW\Workflow::submit( $copy );
		wp_set_current_user( $this->editor );
		wp_update_post( array( 'ID' => $live, 'post_content' => 'Editor hotfix' ) );
		$this->assertSame( $live, SPW\Staging::approve( $copy ) );
		$this->assertSame( 'Author version', get_post( $live )->post_content );
		$contents = wp_list_pluck( wp_get_post_revisions( $live ), 'post_content' );
		$this->assertContains( 'Editor hotfix', $contents );
	}

	public function test_approve_requires_editor_cap() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_set_current_user( $this->author );
		wp_update_post( array( 'ID' => $copy, 'post_title' => 'Mine' ) );
		SPW\Workflow::submit( $copy );
		$res = SPW\Staging::approve( $copy );
		$this->assertWPError( $res );
		$this->assertSame( 'spw_forbidden', $res->get_error_code() );
		$this->assertSame( 'Live title', get_post( $live )->post_title );
		$this->assertNotNull( get_post( $copy ) );
		wp_set_current_user( $this->editor );
		$this->assertWPError( SPW\Staging::approve( $live ) );
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
			SPW\Staging_Review::handle_approve();
			$this->fail( 'expected die' );
		} catch ( WPDieException $e ) {
			$this->assertNotNull( get_post( $copy ) );
		}
		$_POST['_wpnonce'] = wp_create_nonce( 'spw_approve_staged_' . $copy );
		$_REQUEST          = $_POST;
		$location          = $this->redirect_of( array( 'SPW\Staging_Review', 'handle_approve' ) );
		$this->assertStringContainsString( 'page=spw-review-queue', $location );
		$this->assertStringContainsString( 'spw_notice=approved', $location );
		$this->assertSame( 'Approved title', get_post( $live )->post_title );
	}

	public function test_approve_endpoint_author_gets_error() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_update_post( array( 'ID' => $copy, 'post_status' => 'pending', 'post_title' => 'X' ) );
		wp_set_current_user( $this->author );
		$_POST    = array(
			'post_id'  => (string) $copy,
			'_wpnonce' => wp_create_nonce( 'spw_approve_staged_' . $copy ),
		);
		$_REQUEST = $_POST;
		$location = $this->redirect_of( array( 'SPW\Staging_Review', 'handle_approve' ) );
		$this->assertStringContainsString( 'spw_code=spw_forbidden', $location );
		$this->assertSame( 'Live title', get_post( $live )->post_title );
	}

	// ---- send back / submit ----

	public function test_send_back_staged_copy_returns_draft_and_mails() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_set_current_user( $this->author );
		SPW\Workflow::submit( $copy );
		reset_phpmailer_instance();
		wp_set_current_user( $this->editor );
		$this->assertTrue( SPW\Workflow::send_back( $copy, 'Tone it down' ) );
		$this->assertSame( 'draft', get_post_status( $copy ) );
		$this->assertTrue( SPW\Staging::is_staged( $copy ) );
		$this->assertSame( array( 'ann@example.org' ), $this->recipients() );
		$this->assertSame( 'Live title', get_post( $live )->post_title );
	}

	public function test_submit_staged_mails_editors_with_staged_event() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_set_current_user( $this->author );
		reset_phpmailer_instance();
		$this->assertTrue( SPW\Workflow::submit( $copy ) );
		$this->assertSame( array( 'admin@example.org', 'ed1@example.org' ), $this->recipients() );
		$events = wp_list_pluck( SPW\Log::entries(), 'event' );
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
		$this->assertSame( 'Staged edit', SPW\Queue::kind( $copy ) );
		$this->assertSame( 'New', SPW\Queue::kind( $live ) );
	}

	// ---- review screen ----

	public function test_review_screen_renders_escaped_diff_and_forms() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_update_post( array( 'ID' => $copy, 'post_status' => 'pending', 'post_content' => '<script>alert(1)</script>Changed' ) );
		wp_set_current_user( $this->editor );
		$_GET = array( 'copy' => (string) $copy );
		ob_start();
		SPW\Staging_Review::render();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( 'Live title', $html );
		$this->assertStringContainsString( 'name="action" value="spw_approve_staged"', $html );
		$this->assertStringContainsString( 'name="action" value="spw_send_back"', $html );
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
		SPW\Staging_Review::render();
	}

	public function test_live_notice_for_editor() {
		$live = $this->live();
		$copy = $this->copy_for( $live );
		wp_update_post( array( 'ID' => $copy, 'post_status' => 'pending' ) );
		wp_set_current_user( $this->editor );
		$this->assertStringContainsString( 'Changes pending review', SPW\Staging::notice_html( $live ) );
		$this->assertStringContainsString( 'copy=' . $copy, SPW\Staging::notice_html( $live ) );
		wp_set_current_user( $this->author );
		$this->assertSame( '', SPW\Staging::notice_html( $live ) );
	}
}
