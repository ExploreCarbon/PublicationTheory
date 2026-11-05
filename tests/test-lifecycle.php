<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) { exit; }
/**
 * Deactivation behavior: every restriction is a runtime hook, so removing the
 * plugin's hooks restores core behavior and leaves staged copies as plain drafts.
 */
class PT_Lifecycle_Test extends WP_UnitTestCase {

	/**
	 * Simulate deactivation: drop every callback whose class lives in the PublicationTheory namespace.
	 */
	private function deactivate(): void {
		global $wp_filter;
		foreach ( $wp_filter as $hook => $wp_hook ) {
			foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$fn = $callback['function'];
					if ( is_array( $fn ) && is_string( $fn[0] ) && 0 === strpos( $fn[0], 'PublicationTheory' . chr( 92 ) ) ) {
						remove_filter( $hook, $fn, $priority );
					}
				}
			}
		}
	}

	public function test_deactivation_restores_core_author_capabilities() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$post   = self::factory()->post->create( array( 'post_author' => $author, 'post_status' => 'publish' ) );
		wp_set_current_user( $author );

		$this->assertFalse( current_user_can( 'publish_posts' ), 'Plugin active: Author cannot publish.' );
		$this->assertFalse( current_user_can( 'edit_post', $post ), 'Plugin active: Author cannot edit own live post.' );

		$this->deactivate();
		clean_user_cache( $author );
		wp_set_current_user( $author );

		$this->assertTrue( current_user_can( 'publish_posts' ) );
		$this->assertTrue( current_user_can( 'edit_post', $post ) );
	}

	public function test_staged_copies_remain_ordinary_drafts_after_deactivation() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$live   = self::factory()->post->create( array( 'post_author' => $author, 'post_status' => 'publish' ) );
		$copy   = PublicationTheory\Staging::open_for( $live, $author );
		$this->assertIsInt( $copy );

		$this->deactivate();

		$post = get_post( $copy );
		$this->assertNotNull( $post );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( 'post', $post->post_type );
		wp_set_current_user( $author );
		$this->assertTrue( current_user_can( 'edit_post', $copy ) );
		$this->assertSame( $copy, (int) get_posts( array( 'p' => $copy, 'post_status' => 'draft', 'fields' => 'ids' ) )[0] );
	}

	public function test_uninstall_removes_only_the_log_option() {
		update_option( 'pt_workflow_log', array( array( 'event' => 'x' ) ) );
		$post = self::factory()->post->create();
		update_post_meta( $post, '_pt_feedback', array( 'keep' => 1 ) );

		define( 'WP_UNINSTALL_PLUGIN', 'publication-theory/publication-theory.php' );
		include dirname( __DIR__ ) . '/uninstall.php';

		$this->assertFalse( get_option( 'pt_workflow_log' ) );
		$this->assertSame( array( 'keep' => 1 ), get_post_meta( $post, '_pt_feedback', true ) );
	}
}
