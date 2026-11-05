<?php
class PT_Migrate_Test extends WP_UnitTestCase {

	public function test_renames_legacy_meta_and_options(): void {
		global $wpdb;
		$post_id = self::factory()->post->create();
		$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $post_id, 'meta_key' => '_spw_feedback', 'meta_value' => maybe_serialize( array( 'note' => 'Old note' ) ) ) );
		$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $post_id, 'meta_key' => '_spw_staged_for', 'meta_value' => '42' ) );
		add_option( 'spw_workflow_log', array( array( 'event' => 'submitted' ) ), '', false );
		delete_option( 'pt_workflow_log' );
		delete_option( PublicationTheory\Migrate::OPTION );
		wp_cache_flush();

		PublicationTheory\Migrate::maybe_run();

		$this->assertSame( 'Old note', get_post_meta( $post_id, '_pt_feedback', true )['note'] );
		$this->assertSame( '42', get_post_meta( $post_id, '_pt_staged_for', true ) );
		$this->assertSame( array(), get_post_meta( $post_id, '_spw_feedback' ) );
		$this->assertSame( 'submitted', get_option( 'pt_workflow_log' )[0]['event'] );
		$this->assertFalse( get_option( 'spw_workflow_log' ) );
		$this->assertSame( PublicationTheory\Migrate::DB_VERSION, (int) get_option( PublicationTheory\Migrate::OPTION ) );
	}

	public function test_runs_once(): void {
		update_option( PublicationTheory\Migrate::OPTION, PublicationTheory\Migrate::DB_VERSION );
		add_option( 'spw_workflow_log', array( 'x' ), '', false );
		PublicationTheory\Migrate::maybe_run();
		$this->assertSame( array( 'x' ), get_option( 'spw_workflow_log' ) );
	}
}
