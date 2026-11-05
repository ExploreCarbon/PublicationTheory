<?php
namespace PublicationTheory;

defined( 'ABSPATH' ) || exit;

/**
 * Workflow event log: option-backed ring buffer, newest first.
 */
final class Log {

	public const OPTION = 'pt_workflow_log';
	public const LIMIT  = 500;

	/**
	 * Record an entry.
	 *
	 * @param string $status sent|failed|no_recipients.
	 */
	public static function record( int $post_id, string $event, string $status, string $detail = '' ): void {
		$entries = self::entries();
		array_unshift(
			$entries,
			array(
				'time'    => current_time( 'mysql', true ),
				'post_id' => $post_id,
				'event'   => $event,
				'status'  => $status,
				'detail'  => $detail,
			)
		);
		$entries = array_slice( $entries, 0, self::LIMIT );
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $entries, '', false );
		} else {
			update_option( self::OPTION, $entries, false );
		}
	}

	/**
	 * All entries, newest first.
	 */
	public static function entries(): array {
		$entries = get_option( self::OPTION, array() );
		return is_array( $entries ) ? $entries : array();
	}
}
