<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) { exit; }
class SPW_Feedback_Test extends WP_UnitTestCase {

	public function test_add_and_history_order() {
		$post_id = self::factory()->post->create();
		$first   = SPW\Feedback::add( $post_id, 5, 'First note' );
		$second  = SPW\Feedback::add( $post_id, 6, 'Second note' );
		$this->assertIsInt( $first );
		$this->assertIsInt( $second );

		$history = SPW\Feedback::history( $post_id );
		$this->assertCount( 2, $history );
		$this->assertSame( 'Second note', $history[0]['note'] );
		$this->assertSame( 6, $history[0]['editor_id'] );
		$this->assertSame( 'First note', $history[1]['note'] );
		$this->assertNotSame( $history[0]['id'], $history[1]['id'] );
		$this->assertNotEmpty( $history[0]['time'] );
		$this->assertSame( 'Second note', SPW\Feedback::latest( $post_id )['note'] );
	}

	public function test_latest_null_when_empty() {
		$post_id = self::factory()->post->create();
		$this->assertNull( SPW\Feedback::latest( $post_id ) );
		$this->assertSame( array(), SPW\Feedback::history( $post_id ) );
	}

	public function test_whitespace_note_rejected() {
		$post_id = self::factory()->post->create();
		$result  = SPW\Feedback::add( $post_id, 5, "  \n\t " );
		$this->assertWPError( $result );
		$this->assertSame( 'spw_empty_note', $result->get_error_code() );
		$this->assertSame( array(), SPW\Feedback::history( $post_id ) );
	}

	public function test_note_is_sanitized() {
		$post_id = self::factory()->post->create();
		SPW\Feedback::add( $post_id, 5, 'Fix <script>alert(1)</script>this' );
		$this->assertStringNotContainsString( '<script', SPW\Feedback::latest( $post_id )['note'] );
	}

	public function test_quote_and_backslash_round_trip() {
		$post_id = self::factory()->post->create();
		$note    = 'He said "no" and it\'s C:\temp\new';
		SPW\Feedback::add( $post_id, 5, $note );
		$this->assertSame( $note, SPW\Feedback::latest( $post_id )['note'] );
	}
}
