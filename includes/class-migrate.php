<?php
namespace PublicationTheory;

defined( 'ABSPATH' ) || exit;

/**
 * One-time data migration from the legacy "Sentinel Publication Workflow"
 * plugin (spw_ prefixes) to PublicationTheory (pt_ prefixes).
 *
 * Runs once per site; tracked by the `pt_db_version` option.
 */
final class Migrate {

	public const OPTION     = 'pt_db_version';
	public const DB_VERSION = 1;

	/** Legacy post meta key => new post meta key. */
	public const META_MAP = array(
		'_spw_feedback'   => '_pt_feedback',
		'_spw_staged_for' => '_pt_staged_for',
		'_spw_base'       => '_pt_base',
	);

	/** Legacy option => new option. */
	public const OPTION_MAP = array(
		'spw_workflow_log' => 'pt_workflow_log',
	);

	/**
	 * Run pending migrations.
	 */
	public static function maybe_run(): void {
		if ( (int) get_option( self::OPTION, 0 ) >= self::DB_VERSION ) {
			return;
		}
		self::from_spw();
		update_option( self::OPTION, self::DB_VERSION, true );
	}

	/**
	 * Rename legacy spw_ storage keys. Direct SQL bypasses the staging meta
	 * guards, which only police requests, not this migration.
	 */
	public static function from_spw(): void {
		global $wpdb;

		foreach ( self::META_MAP as $old => $new ) {
			$wpdb->update( $wpdb->postmeta, array( 'meta_key' => $new ), array( 'meta_key' => $old ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		if ( function_exists( 'wp_cache_flush_group' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'post_meta' );
		} else {
			wp_cache_flush();
		}

		foreach ( self::OPTION_MAP as $old => $new ) {
			$value = get_option( $old, null );
			if ( null === $value ) {
				continue;
			}
			if ( false === get_option( $new, false ) ) {
				add_option( $new, $value, '', false );
			}
			delete_option( $old );
		}
	}
}
