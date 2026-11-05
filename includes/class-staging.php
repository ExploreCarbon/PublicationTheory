<?php
namespace PublicationTheory;

defined( 'ABSPATH' ) || exit;

/**
 * Staged edits to live content (Phase 1b).
 *
 * A non-publisher never writes to a live (publish/future) covered post. Instead they edit a
 * staged copy: a separate draft post of the same type carrying meta `_pt_staged_for` = live ID.
 * Editors approve (merge into live, keeping a core revision) or send back.
 */
final class Staging {

	public const META = '_pt_staged_for';
	public const BASE = '_pt_base';

	private const LIVE_STATUSES = array( 'publish', 'future' );
	private const OPEN_STATUSES = array( 'draft', 'pending' );
	public const FIELDS = array( 'post_title', 'post_content', 'post_excerpt' );
	private const ALL_STATUSES  = array( 'draft', 'pending', 'trash', 'auto-draft', 'publish', 'future', 'private' );

	/**
	 * Meta keys never cloned or synced.
	 */
	private const SKIP_META = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date' );

	/**
	 * Bypass our map_meta_cap denial (to ask "would core allow?").
	 *
	 * @var bool
	 */
	private static $bypass = false;

	/**
	 * True while this class writes meta/terms itself.
	 *
	 * @var bool
	 */
	private static $internal = false;

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 10, 4 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'guard_live_write' ), 20, 2 );
		add_filter( 'add_post_metadata', array( __CLASS__, 'guard_add_meta' ), 10, 3 );
		add_filter( 'update_post_metadata', array( __CLASS__, 'guard_update_meta' ), 10, 3 );
		add_filter( 'delete_post_metadata', array( __CLASS__, 'guard_delete_meta' ), 10, 3 );
		add_filter( 'update_post_metadata_by_mid', array( __CLASS__, 'guard_meta_by_mid' ), 10, 2 );
		add_filter( 'delete_post_metadata_by_mid', array( __CLASS__, 'guard_meta_by_mid' ), 10, 2 );
		add_action( 'set_object_terms', array( __CLASS__, 'guard_set_terms' ), 10, 6 );
		add_action( 'deleted_term_relationships', array( __CLASS__, 'guard_removed_terms' ), 10, 3 );
		add_filter( 'pre_trash_post', array( __CLASS__, 'guard_trash_delete' ), 10, 2 );
		add_filter( 'pre_delete_post', array( __CLASS__, 'guard_trash_delete' ), 10, 2 );

		add_action( 'pre_get_posts', array( __CLASS__, 'hide_copies' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'hide_in_args' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_filters' ) );
		add_action( 'init', array( __CLASS__, 'register_insert_filters' ), 100 );

		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
		add_action( 'load-post.php', array( __CLASS__, 'on_load_post' ), 1 );
		add_action( 'admin_post_pt_open_staged', array( __CLASS__, 'handle_open' ) );

		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash_live' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_live' ) );
		add_filter( 'pt_queue_item_kind', array( __CLASS__, 'kind' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_notice' ) );
	}

	// ------------------------------------------------------------------ API

	/**
	 * Live post ID a copy stages changes for, or null.
	 */
	public static function live_id( int $copy_id ): ?int {
		$live = (int) get_post_meta( $copy_id, self::META, true );
		return $live > 0 ? $live : null;
	}

	/**
	 * Whether a post is a staged copy.
	 */
	public static function is_staged( int $post_id ): bool {
		return $post_id > 0 && null !== self::live_id( $post_id );
	}

	/**
	 * Whether a post is live covered content (and not itself a copy).
	 */
	public static function is_live( $post ): bool {
		$post = get_post( $post );
		return $post instanceof \WP_Post
			&& in_array( $post->post_status, self::LIVE_STATUSES, true )
			&& Types::is_covered( $post->post_type )
			&& ! self::is_staged( (int) $post->ID );
	}

	/**
	 * Whether a user is barred from writing to a post: logged-in non-publisher on live content.
	 */
	public static function restricted( int $user_id, $post ): bool {
		$post = get_post( $post );
		return $user_id > 0 && $post instanceof \WP_Post && self::is_live( $post ) && ! Caps::can_publish( $user_id, $post->post_type );
	}

	/**
	 * Whether core (without our denial) would let the user edit the live post, i.e. may stage edits.
	 */
	public static function can_stage( int $user_id, int $live_id ): bool {
		if ( $user_id <= 0 || ! self::is_live( $live_id ) ) {
			return false;
		}
		$previous     = self::$bypass;
		self::$bypass = true;
		try {
			return user_can( $user_id, 'edit_post', $live_id );
		} finally {
			self::$bypass = $previous;
		}
	}

	/**
	 * Open copy (draft or pending) of a live post, or 0.
	 */
	public static function find_copy( int $live_id, array $statuses = self::OPEN_STATUSES ): int {
		$live = get_post( $live_id );
		if ( ! $live || $live_id <= 0 ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'          => $live->post_type,
				'post_status'        => $statuses,
				'meta_key'           => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'         => (string) $live_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'             => 'ids',
				'numberposts'        => 1,
				'orderby'            => 'ID',
				'order'              => 'ASC',
				'pt_include_staged' => true,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Return the open copy for a live post, creating it if needed.
	 *
	 * @return int|\WP_Error Copy ID.
	 */
	public static function open_for( int $live_id, int $author_id ) {
		$live = get_post( $live_id );
		if ( ! $live || ! self::is_live( $live ) ) {
			return new \WP_Error( 'pt_not_live', __( 'Only published content can be staged.', 'publication-theory' ), array( 'status' => 400 ) );
		}
		if ( ! self::can_stage( $author_id, $live_id ) ) {
			return new \WP_Error( 'pt_forbidden', __( 'You cannot edit this item.', 'publication-theory' ), array( 'status' => 403 ) );
		}

		$existing = self::find_copy( $live_id );
		if ( $existing ) {
			if ( (int) get_post_field( 'post_author', $existing ) !== $author_id ) {
				return new \WP_Error( 'pt_copy_locked', self::locked_message( $existing ), array( 'status' => 409 ) );
			}
			return $existing;
		}

		$copy_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'      => $live->post_type,
					'post_status'    => 'draft',
					'post_author'    => $author_id,
					'post_title'     => $live->post_title,
					'post_content'   => $live->post_content,
					'post_excerpt'   => $live->post_excerpt,
					'menu_order'     => $live->menu_order,
					'comment_status' => $live->comment_status,
					'ping_status'    => $live->ping_status,
					'post_parent'    => 0,
				)
			),
			true
		);
		if ( is_wp_error( $copy_id ) ) {
			return $copy_id;
		}

		$previous       = self::$internal;
		self::$internal = true;
		try {
			add_post_meta( $copy_id, self::META, (string) $live_id, true );
			self::sync_meta( $live_id, $copy_id );
			self::sync_terms( $live_id, $copy_id );
			add_post_meta( $copy_id, self::BASE, wp_slash( self::snapshot( $live_id ) ), true );
		} finally {
			self::$internal = $previous;
		}
		return (int) $copy_id;
	}

	/**
	 * Merge a pending copy into its live post, then delete the copy.
	 *
	 * @return int|\WP_Error Live ID.
	 */
	public static function approve( int $copy_id ) {
		$copy    = get_post( $copy_id );
		$live_id = $copy ? self::live_id( $copy_id ) : null;
		if ( ! $copy || ! $live_id ) {
			return new \WP_Error( 'pt_not_staged', __( 'This is not a staged edit.', 'publication-theory' ), array( 'status' => 400 ) );
		}
		$object = get_post_type_object( $copy->post_type );
		if ( ! $object || ! current_user_can( $object->cap->edit_others_posts ) ) {
			return new \WP_Error( 'pt_forbidden', __( 'You cannot approve this.', 'publication-theory' ), array( 'status' => 403 ) );
		}
		$live = get_post( $live_id );
		if ( ! $live || $live->post_type !== $copy->post_type || ! self::is_live( $live ) ) {
			return new \WP_Error( 'pt_live_missing', __( 'The live item no longer exists.', 'publication-theory' ), array( 'status' => 409 ) );
		}
		if ( 'pending' !== $copy->post_status ) {
			return new \WP_Error( 'pt_not_pending', __( 'Only pending items can be approved.', 'publication-theory' ), array( 'status' => 400 ) );
		}

		$actor     = get_current_user_id();
		$author_id = (int) $copy->post_author;

		// Make sure the prior live state exists as a revision (no-op when it already matches the latest one).
		if ( wp_revisions_enabled( $live ) ) {
			wp_save_post_revision( $live_id );
		}

		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $live_id,
					'post_title'   => $copy->post_title,
					'post_content' => $copy->post_content,
					'post_excerpt' => $copy->post_excerpt,
				)
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$plan           = self::plan( $copy_id );
		$previous       = self::$internal;
		self::$internal = true;
		try {
			if ( null === $plan ) {
				// No base snapshot (copy predates 3-way merge): full sync.
				self::sync_meta( $copy_id, $live_id, true );
				self::sync_terms( $copy_id, $live_id );
			} else {
				self::apply_plan( $live_id, $plan );
			}
		} finally {
			self::$internal = $previous;
		}
		clean_post_cache( $live_id );

		// Never delete the copy while any note still points at it; a retry resumes where this stopped.
		$moved = self::move_notes( $copy_id, $live_id );
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}
		$moved = self::move_feedback( $copy_id, $live_id );
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}
		wp_delete_post( $copy_id, true );

		Mailer::notify( 'staged_approved', $live_id, $actor, $author_id );
		return $live_id;
	}

	/**
	 * Copy feedback history entries from a copy to the live post (idempotent by entry id).
	 *
	 * @return true|\WP_Error
	 */
	private static function move_feedback( int $copy_id, int $live_id ) {
		$existing = array();
		foreach ( (array) get_post_meta( $live_id, Feedback::META_KEY ) as $entry ) {
			if ( is_array( $entry ) && isset( $entry['id'] ) ) {
				$existing[ (string) $entry['id'] ] = true;
			}
		}
		$previous       = self::$internal;
		self::$internal = true;
		try {
			foreach ( (array) get_post_meta( $copy_id, Feedback::META_KEY ) as $entry ) {
				if ( ! is_array( $entry ) || ( isset( $entry['id'] ) && isset( $existing[ (string) $entry['id'] ] ) ) ) {
					continue;
				}
				if ( ! add_post_meta( $live_id, Feedback::META_KEY, wp_slash( $entry ) ) ) {
					return new \WP_Error( 'pt_feedback_move_failed', __( 'Feedback could not be moved to the live item. Nothing was deleted; try approving again.', 'publication-theory' ), array( 'status' => 500 ) );
				}
			}
		} finally {
			self::$internal = $previous;
		}
		return true;
	}

	/**
	 * Discard a staged copy (and its notes, which are deleted with it).
	 * Allowed for the type's edit_others_posts and for the copy's own author.
	 *
	 * @return int|\WP_Error Live ID.
	 */
	public static function discard( int $copy_id ) {
		$copy    = get_post( $copy_id );
		$live_id = $copy ? self::live_id( $copy_id ) : null;
		if ( ! $copy || ! $live_id ) {
			return new \WP_Error( 'pt_not_staged', __( 'This is not a staged edit.', 'publication-theory' ), array( 'status' => 400 ) );
		}
		$user   = get_current_user_id();
		$object = get_post_type_object( $copy->post_type );
		$editor = $object && current_user_can( $object->cap->edit_others_posts );
		if ( $user <= 0 || ( ! $editor && (int) $copy->post_author !== $user ) ) {
			return new \WP_Error( 'pt_forbidden', __( 'You cannot discard this.', 'publication-theory' ), array( 'status' => 403 ) );
		}
		if ( ! wp_delete_post( $copy_id, true ) ) {
			return new \WP_Error( 'pt_failed', __( 'Could not discard this.', 'publication-theory' ), array( 'status' => 500 ) );
		}
		return (int) $live_id;
	}

	/**
	 * Discard form markup (POST, nonce'd).
	 */
	public static function discard_form( int $copy_id ): string {
		$confirm = __( 'Discard the proposed version? It and its notes are deleted permanently; the live item is unchanged.', 'publication-theory' );
		return '<form method="post" class="pt-discard" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return window.confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');">'
			. '<input type="hidden" name="action" value="pt_discard_staged" />'
			. '<input type="hidden" name="post_id" value="' . esc_attr( (string) $copy_id ) . '" />'
			. wp_nonce_field( 'pt_discard_staged_' . $copy_id, '_wpnonce', true, false )
			. '<p>' . esc_html__( 'Discarding deletes the proposed version and its notes permanently. The live item is not changed.', 'publication-theory' ) . '</p>'
			. '<p><button type="submit" class="button">' . esc_html__( 'Discard staged copy', 'publication-theory' ) . '</button></p></form>';
	}

	/**
	 * Lock message naming the copy's holder.
	 */
	public static function locked_message( int $copy_id ): string {
		$holder = get_userdata( (int) get_post_field( 'post_author', $copy_id ) );
		/* translators: %s: user display name */
		return sprintf( __( 'This item is already being edited by %s; ask an Editor to discard that copy.', 'publication-theory' ), $holder ? $holder->display_name : __( 'another user', 'publication-theory' ) );
	}

	/**
	 * REST: refuse to publish a staged copy with a visible error (silent revert would confuse the editor).
	 *
	 * @param \stdClass|\WP_Error $prepared Prepared post.
	 * @param \WP_REST_Request    $request  Request.
	 */
	public static function rest_pre_insert( $prepared, $request ) {
		if ( is_wp_error( $prepared ) || ! is_object( $prepared ) || empty( $prepared->ID ) ) {
			return $prepared;
		}
		$status = isset( $request['status'] ) ? (string) $request['status'] : (string) ( $prepared->post_status ?? '' );
		if ( self::is_staged( (int) $prepared->ID ) && in_array( $status, array( 'publish', 'future', 'private' ), true ) ) {
			return new \WP_Error( 'pt_use_review_screen', __( 'This is a proposed version. Use Approve on the review screen.', 'publication-theory' ), array( 'status' => 409 ) );
		}
		return $prepared;
	}

	/**
	 * Move editorial notes (comment_type 'note', any status) from a copy to the live post,
	 * so the review record survives the copy's deletion. Returns true or WP_Error on first failure.
	 */
	private static function move_notes( int $copy_id, int $live_id ) {
		foreach ( array( 'all', 'trash', 'spam' ) as $status ) {
			$ids = get_comments(
				array(
					'post_id' => $copy_id,
					'type'    => 'note',
					'status'  => $status,
					'fields'  => 'ids',
					'number'  => 0,
				)
			);
			foreach ( $ids as $comment_id ) {
				$updated = wp_update_comment(
					array(
						'comment_ID'      => (int) $comment_id,
						'comment_post_ID' => $live_id,
					),
					true
				);
				if ( is_wp_error( $updated ) || false === $updated ) {
					wp_update_comment_count( $live_id );
					wp_update_comment_count( $copy_id );
					return new \WP_Error( 'pt_notes_move_failed', __( 'The notes could not be moved to the live item. Nothing was deleted; try approving again.', 'publication-theory' ), array( 'status' => 500 ) );
				}
			}
		}
		wp_update_comment_count( $live_id );
		wp_update_comment_count( $copy_id );
		return true;
	}

	/**
	 * Whether a meta key is copied between live and copy.
	 */
	private static function syncable_key( string $key ): bool {
		return ! in_array( $key, self::SKIP_META, true )
			&& 0 !== strpos( $key, '_pt_' )
			&& 0 !== strpos( $key, '_wp_trash_meta_' );
	}

	/**
	 * Copy syncable meta from one post to another. With $prune, remove target keys absent on source.
	 */
	private static function sync_meta( int $from, int $to, bool $prune = false ): void {
		$source = get_post_meta( $from );
		$source = is_array( $source ) ? $source : array();
		if ( $prune ) {
			$target = get_post_meta( $to );
			foreach ( array_keys( is_array( $target ) ? $target : array() ) as $key ) {
				$key = (string) $key;
				if ( self::syncable_key( $key ) && ! array_key_exists( $key, $source ) ) {
					delete_post_meta( $to, $key );
				}
			}
		}
		foreach ( $source as $key => $values ) {
			$key = (string) $key;
			if ( ! self::syncable_key( $key ) ) {
				continue;
			}
			delete_post_meta( $to, $key );
			foreach ( (array) $values as $value ) {
				add_post_meta( $to, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
	}

	/**
	 * Syncable meta of a post: key => list of unserialized values.
	 */
	public static function meta_map( int $post_id ): array {
		$out = array();
		$all = get_post_meta( $post_id );
		foreach ( is_array( $all ) ? $all : array() as $key => $values ) {
			$key = (string) $key;
			if ( self::syncable_key( $key ) ) {
				$out[ $key ] = array_map( 'maybe_unserialize', (array) $values );
			}
		}
		return $out;
	}

	/**
	 * Sorted term IDs per taxonomy of a post.
	 */
	public static function term_map( int $post_id ): array {
		$out = array();
		foreach ( get_object_taxonomies( (string) get_post_type( $post_id ) ) as $taxonomy ) {
			$ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			$ids = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
			sort( $ids );
			$out[ $taxonomy ] = $ids;
		}
		return $out;
	}

	/**
	 * Hash of a meta value list.
	 */
	private static function hash( array $values ): string {
		return md5( serialize( $values ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * Base snapshot of a live post at copy time: meta hashes and term IDs.
	 */
	private static function snapshot( int $live_id ): array {
		$meta = array();
		foreach ( self::meta_map( $live_id ) as $key => $values ) {
			$meta[ $key ] = self::hash( $values );
		}
		$live   = get_post( $live_id );
		$fields = array();
		foreach ( self::FIELDS as $field ) {
			$fields[ $field ] = md5( (string) ( $live ? $live->$field : '' ) );
		}
		return array(
			'fields' => $fields,
			'meta'   => $meta,
			'terms' => self::term_map( $live_id ),
		);
	}

	/**
	 * Three-way merge plan (base snapshot, copy, CURRENT live), or null when the copy has no base.
	 *
	 * meta:  key => {before: live values|null, after: copy values|null}, author-changed keys that differ from live.
	 * terms: taxonomy => {added, removed, result} term IDs, author-changed taxonomies that differ from live.
	 * live_changed: {meta: keys, terms: taxonomies} changed on live since the copy was opened.
	 * The copy wins where author and live both changed the same key.
	 */
	public static function plan( int $copy_id ): ?array {
		$live_id = self::live_id( $copy_id );
		$base    = get_post_meta( $copy_id, self::BASE, true );
		if ( ! $live_id || ! is_array( $base ) || ! isset( $base['meta'], $base['terms'] ) ) {
			return null;
		}
		$base_meta = (array) $base['meta'];
		$copy_meta = self::meta_map( $copy_id );
		$live_meta = self::meta_map( $live_id );

		$plan = array(
			'meta'         => array(),
			'terms'        => array(),
			'live_changed' => array(
				'fields' => array(),
				'meta'   => array(),
				'terms' => array(),
			),
		);

		// Title/content/excerpt: the copy always wins; only report live edits made since the copy was opened.
		$live_post = get_post( $live_id );
		foreach ( self::FIELDS as $field ) {
			if ( isset( $base['fields'][ $field ] ) && $live_post && md5( (string) $live_post->$field ) !== (string) $base['fields'][ $field ] ) {
				$plan['live_changed']['fields'][] = $field;
			}
		}

		$keys = array_unique( array_merge( array_keys( $base_meta ), array_keys( $copy_meta ), array_keys( $live_meta ) ) );
		foreach ( $keys as $key ) {
			$key = (string) $key;
			$b   = isset( $base_meta[ $key ] ) ? (string) $base_meta[ $key ] : null;
			$c   = isset( $copy_meta[ $key ] ) ? self::hash( $copy_meta[ $key ] ) : null;
			$l   = isset( $live_meta[ $key ] ) ? self::hash( $live_meta[ $key ] ) : null;
			if ( $l !== $b ) {
				$plan['live_changed']['meta'][] = $key;
			}
			if ( $c === $b || $c === $l ) {
				continue; // Author did not touch it, or live already matches.
			}
			$plan['meta'][ $key ] = array(
				'before' => $live_meta[ $key ] ?? null,
				'after'  => $copy_meta[ $key ] ?? null,
			);
		}

		$base_terms = (array) $base['terms'];
		$copy_terms = self::term_map( $copy_id );
		$live_terms = self::term_map( $live_id );
		foreach ( array_unique( array_merge( array_keys( $copy_terms ), array_keys( $base_terms ) ) ) as $taxonomy ) {
			$taxonomy = (string) $taxonomy;
			$b        = array_map( 'intval', (array) ( $base_terms[ $taxonomy ] ?? array() ) );
			sort( $b );
			$c = $copy_terms[ $taxonomy ] ?? array();
			$l = $live_terms[ $taxonomy ] ?? array();
			if ( $l !== $b ) {
				$plan['live_changed']['terms'][] = $taxonomy;
			}
			$added   = array_diff( $c, $b );
			$removed = array_diff( $b, $c );
			$result  = array_values( array_unique( array_merge( array_diff( $l, $removed ), $added ) ) );
			$result  = array_map( 'intval', $result );
			sort( $result );
			if ( $result === $l ) {
				continue;
			}
			$plan['terms'][ $taxonomy ] = array(
				'added'   => array_values( array_diff( $result, $l ) ),
				'removed' => array_values( array_diff( $l, $result ) ),
				'result'  => $result,
			);
		}
		return $plan;
	}

	/**
	 * Apply a merge plan to the live post.
	 */
	private static function apply_plan( int $live_id, array $plan ): void {
		foreach ( $plan['meta'] as $key => $change ) {
			delete_post_meta( $live_id, (string) $key );
			foreach ( (array) ( $change['after'] ?? array() ) as $value ) {
				add_post_meta( $live_id, (string) $key, wp_slash( $value ) );
			}
		}
		foreach ( $plan['terms'] as $taxonomy => $change ) {
			wp_set_object_terms( $live_id, $change['result'], (string) $taxonomy, false );
		}
	}

	/**
	 * Mirror all taxonomy terms from one post to another.
	 */
	private static function sync_terms( int $from, int $to ): void {
		foreach ( get_object_taxonomies( get_post_type( $from ) ) as $taxonomy ) {
			$ids = wp_get_object_terms( $from, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $ids ) ) {
				continue;
			}
			wp_set_object_terms( $to, array_map( 'intval', $ids ), $taxonomy, false );
		}
	}

	// ------------------------------------------------------- write blocking

	/**
	 * Deny edit/delete of live covered posts to non-publishers (blocks wp-admin, REST, quick/bulk edit).
	 *
	 * @param string[] $caps    Primitive caps.
	 * @param string   $cap     Meta cap.
	 * @param int      $user_id User.
	 * @param array    $args    Args.
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ): array {
		$caps = (array) $caps;
		if ( self::$bypass || ! in_array( $cap, array( 'edit_post', 'edit_page', 'delete_post', 'delete_page' ), true ) || empty( $args[0] ) ) {
			return $caps;
		}
		if ( self::restricted( (int) $user_id, (int) $args[0] ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	/**
	 * Defense in depth: restore all post fields on a restricted live write.
	 *
	 * @param array $data    Slashed post data.
	 * @param array $postarr Raw post array.
	 */
	public static function guard_live_write( $data, $postarr ): array {
		$data = (array) $data;
		$id   = (int) ( $postarr['ID'] ?? 0 );
		if ( $id > 0 && self::is_staged( $id ) ) {
			// A copy keeps its post type (never moved to an uncovered, unguarded type).
			$data['post_type'] = (string) get_post_field( 'post_type', $id );
			return $data;
		}
		if ( $id <= 0 || ! self::restricted( get_current_user_id(), $id ) ) {
			return $data;
		}
		$old = get_post( $id );
		foreach ( array_keys( $data ) as $field ) {
			if ( isset( $old->$field ) ) {
				$data[ $field ] = wp_slash( $old->$field );
			}
		}
		return $data;
	}

	/**
	 * Whether a meta write by the current user must be blocked.
	 */
	private static function meta_blocked( int $post_id, string $key ): bool {
		if ( self::$internal ) {
			return false;
		}
		$user = get_current_user_id();
		if ( $user <= 0 ) {
			return false;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}
		if ( 0 === strpos( $key, '_pt_' ) ) {
			// Workflow-internal keys: never writable by non-publishers on markers, nor on live posts.
			return ! Caps::can_publish( $user, $post->post_type )
				&& ( self::META === $key || self::BASE === $key || self::restricted( $user, $post ) );
		}
		return self::restricted( $user, $post ) && ! self::allowed_live_key( $key );
	}

	/**
	 * Housekeeping meta that a non-publisher's request may still write on live posts.
	 * Entries ending in "_" are prefixes. `_pt_*` is never allowed.
	 */
	public static function allowed_live_key( string $key ): bool {
		if ( 0 === strpos( $key, '_pt_' ) ) {
			return false;
		}
		$allowed = array( '_oembed_', '_encloseme', '_pingme', '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_desired_post_slug', '_wp_trash_meta_' );
		/**
		 * Filter meta keys/prefixes (a trailing "_" marks a prefix) that non-publishers' requests may write on live posts.
		 *
		 * @param string[] $allowed Keys and prefixes.
		 */
		$allowed = (array) apply_filters( 'pt_allowed_live_meta_keys', $allowed );
		foreach ( $allowed as $entry ) {
			$entry = (string) $entry;
			if ( '' === $entry ) {
				continue;
			}
			if ( $key === $entry || ( '_' === substr( $entry, -1 ) && 0 === strpos( $key, $entry ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Filter for add_post_metadata.
	 *
	 * @param mixed  $check   Short-circuit.
	 * @param int    $post_id Post.
	 * @param string $key     Key.
	 */
	public static function guard_add_meta( $check, $post_id, $key ) {
		return self::meta_blocked( (int) $post_id, (string) $key ) ? false : $check;
	}

	/**
	 * Filter for update_post_metadata.
	 *
	 * @param mixed  $check   Short-circuit.
	 * @param int    $post_id Post.
	 * @param string $key     Key.
	 */
	public static function guard_update_meta( $check, $post_id, $key ) {
		return self::meta_blocked( (int) $post_id, (string) $key ) ? false : $check;
	}

	/**
	 * Filter for delete_post_metadata.
	 *
	 * @param mixed  $check   Short-circuit.
	 * @param int    $post_id Post.
	 * @param string $key     Key.
	 */
	public static function guard_delete_meta( $check, $post_id, $key ) {
		return self::meta_blocked( (int) $post_id, (string) $key ) ? false : $check;
	}

	/**
	 * Filter for update/delete_post_metadata_by_mid (live posts only).
	 *
	 * @param mixed $check   Short-circuit.
	 * @param int   $meta_id Meta ID.
	 */
	public static function guard_meta_by_mid( $check, $meta_id ) {
		if ( self::$internal ) {
			return $check;
		}
		$meta = get_metadata_by_mid( 'post', (int) $meta_id );
		if ( $meta && self::restricted( get_current_user_id(), (int) $meta->post_id ) && ! self::allowed_live_key( (string) $meta->meta_key ) ) {
			return false;
		}
		return $check;
	}

	/**
	 * Term IDs for term_taxonomy IDs.
	 *
	 * @param int[] $tt_ids TT IDs.
	 */
	private static function term_ids( array $tt_ids, string $taxonomy ): array {
		$out = array();
		foreach ( $tt_ids as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', (int) $tt_id, $taxonomy );
			if ( $term ) {
				$out[] = (int) $term->term_id;
			}
		}
		return $out;
	}

	/**
	 * Whether a term change on an object must be undone.
	 */
	private static function terms_blocked( int $object_id, string $taxonomy ): bool {
		if ( self::$internal ) {
			return false;
		}
		$post = get_post( $object_id );
		return $post && is_object_in_taxonomy( $post->post_type, $taxonomy ) && self::restricted( get_current_user_id(), $post );
	}

	/**
	 * Undo wp_set_object_terms on restricted live posts (core has no pre-filter).
	 */
	public static function guard_set_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		if ( ! self::terms_blocked( (int) $object_id, (string) $taxonomy ) ) {
			return;
		}
		self::$internal = true;
		try {
			wp_set_object_terms( (int) $object_id, self::term_ids( array_map( 'intval', (array) $old_tt_ids ), (string) $taxonomy ), (string) $taxonomy, false );
		} finally {
			self::$internal = false; // Only reached from a non-internal call (checked above).
		}
	}

	/**
	 * Undo wp_remove_object_terms on restricted live posts.
	 */
	public static function guard_removed_terms( $object_id, $tt_ids, $taxonomy ): void {
		if ( ! self::terms_blocked( (int) $object_id, (string) $taxonomy ) ) {
			return;
		}
		self::$internal = true;
		try {
			wp_set_object_terms( (int) $object_id, self::term_ids( array_map( 'intval', (array) $tt_ids ), (string) $taxonomy ), (string) $taxonomy, true );
		} finally {
			self::$internal = false;
		}
	}

	/**
	 * Block trash/delete of live posts by restricted users.
	 *
	 * @param mixed    $check Short-circuit.
	 * @param \WP_Post $post  Post.
	 */
	public static function guard_trash_delete( $check, $post ) {
		if ( $post instanceof \WP_Post && self::restricted( get_current_user_id(), $post ) ) {
			return false;
		}
		return $check;
	}

	// --------------------------------------------------------------- hiding

	/**
	 * The meta clause excluding copies.
	 */
	private static function clause(): array {
		return array(
			'key'     => self::META,
			'compare' => 'NOT EXISTS',
		);
	}

	/**
	 * Merge the exclusion clause into a meta_query value.
	 *
	 * @param mixed $meta_query Existing meta_query.
	 */
	private static function with_clause( $meta_query ): array {
		if ( empty( $meta_query ) || ! is_array( $meta_query ) ) {
			return array( self::clause() );
		}
		return array(
			'relation' => 'AND',
			self::clause(),
			$meta_query,
		);
	}

	/**
	 * Whether a post_type query value can include covered types.
	 *
	 * @param mixed $post_type Query value.
	 */
	private static function touches_covered( $post_type ): bool {
		if ( empty( $post_type ) || 'any' === $post_type ) {
			return true;
		}
		foreach ( (array) $post_type as $type ) {
			if ( 'any' === $type || Types::is_covered( (string) $type ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * pre_get_posts: exclude copies from every query unless it opts in with pt_include_staged.
	 * Lookups by explicit ID (p / page_id) are left alone; post status protects copies there.
	 *
	 * @param \WP_Query $query Query.
	 */
	public static function hide_copies( $query ): void {
		if ( ! $query instanceof \WP_Query || $query->get( 'pt_include_staged' ) || $query->get( 'pt_staged_hidden' ) ) {
			return;
		}
		if ( (int) $query->get( 'p' ) > 0 || (int) $query->get( 'page_id' ) > 0 || (int) $query->get( 'attachment_id' ) > 0 ) {
			return;
		}
		if ( ! self::touches_covered( $query->get( 'post_type' ) ) ) {
			return;
		}
		$query->set( 'meta_query', self::with_clause( $query->get( 'meta_query' ) ) );
		$query->set( 'pt_staged_hidden', true );
	}

	/**
	 * Add the exclusion to query args (REST collections, sitemaps).
	 *
	 * @param array $args Query args.
	 */
	public static function hide_in_args( $args ): array {
		$args = (array) $args;
		unset( $args['pt_include_staged'] );
		$args['meta_query']        = self::with_clause( $args['meta_query'] ?? array() ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$args['pt_staged_hidden'] = true;
		return $args;
	}

	/**
	 * Register rest_{type}_query filters for covered types.
	 */
	public static function register_insert_filters(): void {
		foreach ( Types::covered() as $type ) {
			add_filter( "rest_pre_insert_{$type}", array( __CLASS__, 'rest_pre_insert' ), 10, 2 );
		}
	}

	/**
	 * Register rest_{type}_query filters for covered types.
	 */
	public static function register_rest_filters(): void {
		foreach ( Types::covered() as $type ) {
			add_filter( "rest_{$type}_query", array( __CLASS__, 'hide_in_args' ) );
		}
	}

	// ------------------------------------------------------ edit entry points

	/**
	 * URL of the nonce-protected "open staged copy" endpoint.
	 */
	public static function open_url( int $live_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'pt_open_staged',
					'post_id' => $live_id,
				),
				admin_url( 'admin-post.php' )
			),
			'pt_open_staged_' . $live_id
		);
	}

	/**
	 * Whether the current user should be routed to a staged copy for this post.
	 */
	private static function routes_to_copy( int $post_id ): bool {
		$user = get_current_user_id();
		return self::restricted( $user, $post_id ) && self::can_stage( $user, $post_id );
	}

	/**
	 * Row action "Edit" for restricted users (core omits it because edit_post is denied).
	 *
	 * @param array    $actions Actions.
	 * @param \WP_Post $post    Post.
	 */
	public static function row_actions( $actions, $post ): array {
		$actions = (array) $actions;
		if ( ! $post instanceof \WP_Post || ! self::routes_to_copy( (int) $post->ID ) ) {
			return $actions;
		}
		$title           = get_the_title( $post );
		$actions['edit'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( self::open_url( (int) $post->ID ) ),
			/* translators: %s: post title */
			esc_attr( sprintf( __( 'Edit "%s" (changes go to review)', 'publication-theory' ), $title ) ),
			esc_html__( 'Edit', 'publication-theory' )
		);
		return $actions;
	}

	/**
	 * Admin bar "Edit" node on a live singular view for restricted users.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 */
	public static function admin_bar( $bar ): void {
		if ( is_admin() || ! is_singular() || ! $bar instanceof \WP_Admin_Bar ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! self::routes_to_copy( (int) $post->ID ) || $bar->get_node( 'edit' ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'edit',
				'title' => esc_html__( 'Edit', 'publication-theory' ),
				'href'  => self::open_url( (int) $post->ID ),
			)
		);
	}

	/**
	 * Redirect a restricted user away from the live edit screen. Existing copy: straight there.
	 * No copy yet: show a nonce-protected link rather than create one on a bare GET.
	 */
	public static function on_load_post(): void {
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $post_id <= 0 || 'edit' !== $action || ! self::routes_to_copy( $post_id ) ) {
			return;
		}
		$copy  = self::find_copy( $post_id );
		$title = esc_html__( 'Edit live content', 'publication-theory' );
		if ( $copy && (int) get_post_field( 'post_author', $copy ) !== get_current_user_id() ) {
			wp_die( '<h1>' . $title . '</h1><p>' . esc_html( self::locked_message( $copy ) ) . '</p>', $title, array( 'response' => 409 ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped parts.
		}
		if ( $copy ) {
			$state   = 'pending' === get_post_status( $copy ) ? __( 'Your proposed changes are waiting for review.', 'publication-theory' ) : __( 'You have a proposed version of this item in progress.', 'publication-theory' );
			$message = '<h1>' . $title . '</h1><p>' . esc_html( $state ) . '</p>'
				. '<p><a class="button button-primary" href="' . esc_url( admin_url( 'post.php?post=' . $copy . '&action=edit' ) ) . '">' . esc_html__( 'Continue editing your copy', 'publication-theory' ) . '</a></p>'
				. self::discard_form( $copy );
			wp_die( $message, $title, array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped parts.
		}
		$message = '<h1>' . esc_html__( 'Edit live content', 'publication-theory' ) . '</h1><p>' . esc_html__( 'This item is live. Your changes will be saved as a copy and sent to an editor for review.', 'publication-theory' ) . '</p>'
			. '<p><a class="button button-primary" href="' . esc_url( self::open_url( $post_id ) ) . '">' . esc_html__( 'Edit a copy for review', 'publication-theory' ) . '</a></p>';
		wp_die( $message, esc_html__( 'Edit live content', 'publication-theory' ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	}

	/**
	 * admin-post: open (or create) the copy and go to its edit screen.
	 */
	public static function handle_open(): void {
		$post_id = isset( $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- verified next line.
		check_admin_referer( 'pt_open_staged_' . $post_id );
		$copy = self::open_for( $post_id, get_current_user_id() );
		if ( is_wp_error( $copy ) ) {
			$data = $copy->get_error_data();
			wp_die( esc_html( $copy->get_error_message() ), '', array( 'response' => (int) ( $data['status'] ?? 403 ) ) );
		}
		wp_safe_redirect( admin_url( 'post.php?post=' . (int) $copy . '&action=edit' ) );
		exit;
	}

	// ------------------------------------------------------------ lifecycle

	/**
	 * Trash the copy with its live post.
	 *
	 * @param int $post_id Post being trashed.
	 */
	public static function on_trash_live( $post_id ): void {
		$post_id = (int) $post_id;
		if ( self::is_staged( $post_id ) ) {
			return;
		}
		$copy = self::find_copy( $post_id );
		if ( $copy ) {
			wp_trash_post( $copy );
		}
	}

	/**
	 * Delete copies (any status) with their live post.
	 *
	 * @param int $post_id Post being deleted.
	 */
	public static function on_delete_live( $post_id ): void {
		$post_id = (int) $post_id;
		if ( self::is_staged( $post_id ) ) {
			return;
		}
		$copy = self::find_copy( $post_id, self::ALL_STATUSES );
		while ( $copy ) {
			wp_delete_post( $copy, true );
			$next = self::find_copy( $post_id, self::ALL_STATUSES );
			$copy = $next !== $copy ? $next : 0;
		}
	}

	// ------------------------------------------------------------ admin UI

	/**
	 * Queue kind label.
	 *
	 * @param string $kind    Kind.
	 * @param int    $post_id Post.
	 */
	public static function kind( $kind, $post_id ): string {
		return self::is_staged( (int) $post_id ) ? __( 'Staged edit', 'publication-theory' ) : (string) $kind;
	}

	/**
	 * Review screen URL for a copy.
	 */
	public static function review_url( int $copy_id ): string {
		return add_query_arg(
			array(
				'page' => Staging_Review::SLUG,
				'copy' => $copy_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Notice for the edit screen of a live post (Editors, any open copy) or of a copy (publishers).
	 *
	 * @return array{message:string,label:string,url:string,copy:int}|null
	 */
	public static function notice_data( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}
		$object = get_post_type_object( $post->post_type );
		if ( ! $object || ! current_user_can( $object->cap->edit_others_posts ) ) {
			return null;
		}
		if ( self::is_staged( $post_id ) ) {
			return array(
				'message' => __( 'Proposed version — approve it on the review screen.', 'publication-theory' ),
				'label'   => __( 'Open review screen', 'publication-theory' ),
				'url'     => self::review_url( $post_id ),
				'copy'    => $post_id,
			);
		}
		if ( ! self::is_live( $post ) ) {
			return null;
		}
		$copy = self::find_copy( $post_id );
		if ( ! $copy ) {
			return null;
		}
		$holder = get_userdata( (int) get_post_field( 'post_author', $copy ) );
		$name   = $holder ? $holder->display_name : __( 'another user', 'publication-theory' );
		return array(
			'message' => 'pending' === get_post_status( $copy )
				? __( 'Changes pending review.', 'publication-theory' )
				/* translators: %s: user display name */
				: sprintf( __( 'Staged changes by %s are in draft (returned or not yet submitted).', 'publication-theory' ), $name ),
			'label'   => __( 'Review staged changes', 'publication-theory' ),
			'url'     => self::review_url( $copy ),
			'copy'    => $copy,
		);
	}

	/**
	 * Notice markup ('' when there is nothing to say).
	 */
	public static function notice_html( int $post_id ): string {
		$data = self::notice_data( $post_id );
		if ( ! $data ) {
			return '';
		}
		$html = '<div class="notice notice-warning"><p>' . esc_html( $data['message'] )
			. ' <a href="' . esc_url( $data['url'] ) . '">' . esc_html( $data['label'] ) . '</a></p>';
		if ( ! self::is_staged( $post_id ) ) {
			$html .= self::discard_form( $data['copy'] );
		}
		return $html . '</div>';
	}

	/**
	 * Notice after a non-REST attempt to publish a copy was reverted to pending.
	 */
	public static function blocked_publish_notice(): void {
		$user = get_current_user_id();
		if ( $user <= 0 || ! get_transient( 'pt_copy_publish_blocked_' . $user ) ) {
			return;
		}
		delete_transient( 'pt_copy_publish_blocked_' . $user );
		echo '<div class="notice notice-error" role="alert"><p>' . esc_html__( 'This is a proposed version. Use Approve on the review screen.', 'publication-theory' ) . '</p></div>';
	}

	/**
	 * Classic edit screen notice.
	 */
	public static function admin_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		self::blocked_publish_notice();
		echo self::notice_html( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in notice_html().
	}

	/**
	 * Block editor notice (the block editor does not render admin_notices).
	 */
	public static function editor_notice(): void {
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$data = self::notice_data( $post_id );
		if ( ! $data ) {
			return;
		}
		$config = array(
			'message' => $data['message'],
			'label'   => $data['label'],
			'url'     => $data['url'],
		);
		wp_add_inline_script(
			'wp-edit-post',
			'(function(c){wp.domReady&&wp.domReady(function(){wp.data.dispatch("core/notices").createNotice("warning",c.message,{id:"pt-staged",isDismissible:true,actions:[{label:c.label,url:c.url}]});});})(' . wp_json_encode( $config ) . ');'
		);
	}
}
