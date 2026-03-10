<?php
declare(strict_types=1);

namespace GHL\ShowcaseSync;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Back-Sync Engine
 *
 * Pushes WordPress showcase posts back into GHL as Custom Object records.
 *
 * Two modes:
 *   1. Backlog Sync  — posts that have NO _ghl_object_id (were created in WP directly)
 *   2. Full Reverse  — posts WITH _ghl_object_id get their WP edits pushed back to GHL
 *
 * In the dashboard log, WP-only posts (no GHL link) are shown with status 'backlog'.
 * After a successful back-sync, they transition to 'synced'.
 */
class BackSyncEngine {

	private const POST_TYPE   = 'showcase';
	private const GHL_ID_META = '_ghl_object_id';
	private const LOG_OPTION  = 'ghl_back_sync_last_log';

	// ── Entry Point ────────────────────────────────────────────────────────────

	/**
	 * Run the back-sync for all backlog posts (WP-only, no GHL ID).
	 *
	 * @return array|WP_Error  Summary of the operation.
	 */
	public static function run_backlog(): array|\WP_Error {
		return self::run_backlog_batch( 0 );
	}

	/**
	 * Push the next slice of backlog posts to GHL.
	 *
	 * There is NO offset parameter. Because each successful push saves the GHL ID
	 * to the post's meta, that post naturally disappears from the backlog on the
	 * next call. So we always take the FIRST $batch_size posts from whatever
	 * remains — the list shrinks with each batch.
	 *
	 * @param int $batch_size  0 = push everything. Positive = push this many.
	 */
	public static function run_backlog_batch( int $batch_size = 0 ): array|\WP_Error {
		$api = ApiClient::make();

		$schema_key = (string) get_option( 'ghl_sync_schema_key', '' );
		if ( empty( $schema_key ) ) {
			return new \WP_Error( 'missing_schema', 'No schema key configured. Configure the GHL Schema Key in Settings first.' );
		}

		// Use the raw query (no stale-ID purge) during batch runs so we don't
		// hammer the API with an extra get_records() call every batch.
		$all_remaining = self::get_backlog_posts_raw();
		$total_remaining = count( $all_remaining );

		// Slice from the front. Posts successfully pushed in a previous batch will
		// have a GHL ID saved and won't appear here anymore.
		$posts = ( $batch_size > 0 ) ? array_slice( $all_remaining, 0, $batch_size ) : $all_remaining;

		$summary = [
			'created'        => 0,
			'skipped'        => 0,
			'errors'         => [],
			'items'          => [],
			'total_remaining'=> $total_remaining,
			'batch_size'     => $batch_size,
			// has_more: true when there are more posts beyond what we just processed.
			'has_more'       => $batch_size > 0 && $total_remaining > $batch_size,
		];

		foreach ( $posts as $post ) {
			$result = self::push_post_to_ghl( $post, $api, false );
			if ( is_wp_error( $result ) ) {
				$summary['errors'][] = "[post:{$post->ID} \"{$post->post_title}\"]: " . $result->get_error_message();
				$summary['items'][]  = [ 'post_id' => $post->ID, 'title' => $post->post_title, 'status' => 'error', 'message' => $result->get_error_message() ];
			} else {
				$summary['created']++;
				$summary['items'][]  = [ 'post_id' => $post->ID, 'title' => $post->post_title, 'status' => 'created', 'ghl_id' => $result ];
			}
		}

		self::write_log( $summary );
		return $summary;
	}

	// ── Get WP-Only (Backlog) Posts ────────────────────────────────────────────

	/**
	 * Public entry point: purges stale GHL IDs first (validates against live API),
	 * then returns all WP posts with no _ghl_object_id.
	 * Use this for display/refresh. Avoid during batch loops (use get_backlog_posts_raw).
	 *
	 * @return \WP_Post[]
	 */
	public static function get_backlog_posts(): array {
		self::purge_stale_ghl_ids();
		return self::get_backlog_posts_raw();
	}

	/**
	 * Returns WP posts with no _ghl_object_id — without making any API calls.
	 * Used during batch processing to avoid hammering get_records() every batch.
	 *
	 * @return \WP_Post[]
	 */
	public static function get_backlog_posts_raw(): array {
		return get_posts( [
			'post_type'      => self::POST_TYPE,
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => -1,
			'meta_query'     => [
				[
					'key'     => self::GHL_ID_META,
					'compare' => 'NOT EXISTS',
				],
			],
		] );
	}

	/**
	 * Returns all showcase posts for the log view, with their back-sync status.
	 * Cross-references stored GHL IDs against live GHL records — stale IDs are
	 * deleted, making those posts show as "backlog" rather than "synced".
	 *
	 * Each item has: post_id, title, ghl_id, back_sync_status ('synced'|'backlog')
	 *
	 * @return array
	 */
	public static function get_all_wp_posts_with_status(): array {
		// Validate stored GHL IDs against what actually exists in LaunchLocal.
		self::purge_stale_ghl_ids();

		$posts = get_posts( [
			'post_type'      => self::POST_TYPE,
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => -1,
		] );

		$items = [];
		foreach ( $posts as $post ) {
			$ghl_id        = get_post_meta( $post->ID, self::GHL_ID_META, true );
			$stored_origin = (string) get_post_meta( $post->ID, '_ghl_origin', true );

			// Re-derive origin on every load: no GHL ID means it was never pushed,
			// so it's definitively WordPress-origin regardless of stored meta.
			if ( ! $ghl_id ) {
				$origin = 'wordpress';
				// Correct any stale origin meta while we're here.
				if ( $stored_origin !== 'wordpress' ) {
					update_post_meta( $post->ID, '_ghl_origin', 'wordpress' );
				}
			} else {
				$origin = ( $stored_origin === 'wordpress' ) ? 'wordpress' : 'ghl';
			}

			// Back-sync tab only shows WordPress-origin posts.
			if ( $origin !== 'wordpress' ) continue;

			$items[] = [
				'post_id'          => $post->ID,
				'title'            => $post->post_title,
				'ghl_id'           => $ghl_id ?: null,
				'back_sync_status' => $ghl_id ? 'synced' : 'backlog',
				'post_status'      => $post->post_status,
				'edit_url'         => get_edit_post_link( $post->ID, 'raw' ),
				'origin'           => 'wordpress',
			];
		}

		return $items;
	}

	/**
	 * Cross-reference every WP post's stored _ghl_object_id against the live
	 * GHL record list. Any ID that no longer exists in GHL is deleted from the
	 * post meta, effectively converting the post back into a backlog entry.
	 *
	 * Only runs when credentials and schema key are configured; silently bails
	 * on API errors so the page still loads.
	 *
	 * @return int  Number of stale IDs removed.
	 */
	public static function purge_stale_ghl_ids(): int {
		$schema_key = (string) get_option( 'ghl_sync_schema_key', '' );
		if ( empty( $schema_key ) ) return 0;

		$api     = ApiClient::make();
		$records = $api->get_records();
		if ( is_wp_error( $records ) ) return 0;

		// Build a flat set of current GHL IDs for O(1) lookup.
		$live_ids = array_flip( array_filter( array_column( $records, 'id' ) ) );

		// Find all WP posts that have a _ghl_object_id stored.
		$posts_with_id = get_posts( [
			'post_type'      => self::POST_TYPE,
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => -1,
			'meta_query'     => [
				[
					'key'     => self::GHL_ID_META,
					'compare' => 'EXISTS',
				],
			],
		] );

		$removed = 0;
		foreach ( $posts_with_id as $post ) {
			$stored_id = (string) get_post_meta( $post->ID, self::GHL_ID_META, true );
			if ( $stored_id && ! isset( $live_ids[ $stored_id ] ) ) {
				// This GHL record no longer exists — strip the stale link.
				delete_post_meta( $post->ID, self::GHL_ID_META );
				delete_post_meta( $post->ID, '_ghl_synced_at' );
				// Preserve origin so we know it came from GHL originally.
				if ( ! get_post_meta( $post->ID, '_ghl_origin', true ) ) {
					update_post_meta( $post->ID, '_ghl_origin', 'ghl' );
				}
				$removed++;
			}
		}

		return $removed;
	}

	// ── Push a Post to GHL ─────────────────────────────────────────────────────

	/**
	 * Push a single WP post to GHL.
	 * If $update is true and the post has a _ghl_object_id, it will update the record.
	 * Otherwise it creates a new record.
	 *
	 * @return string|\WP_Error  The GHL record ID on success.
	 */
	private static function push_post_to_ghl( \WP_Post $post, ApiClient $api, bool $update = false ): string|\WP_Error {
		$field_map  = SyncEngine::get_field_map();
		$properties = self::build_ghl_properties( $post, $field_map );

		$existing_ghl_id = (string) get_post_meta( $post->ID, self::GHL_ID_META, true );

		if ( $update && $existing_ghl_id ) {
			$result = $api->update_record( $existing_ghl_id, $properties );
			if ( is_wp_error( $result ) ) return $result;
			return $existing_ghl_id;
		} else {
			$result = $api->create_record( $properties );
			if ( is_wp_error( $result ) ) return $result;

			// GHL APIs return the new ID in various shapes — try every known path.
			$new_ghl_id = $result['record']['id']
				?? $result['id']
				?? $result['object']['id']
				?? $result['data']['id']
				?? $result['data']['record']['id']
				?? '';

			// If the response still has no ID, bust the cache and search live records
			// to find the just-created entry by title match (prevents infinite re-create).
			if ( ! $new_ghl_id ) {
				$api->bust_cache();
				$live_records = $api->get_records();
				if ( ! is_wp_error( $live_records ) ) {
					$needle = strtolower( trim( $post->post_title ) );
					foreach ( array_reverse( $live_records ) as $rec ) {
						$rec_title = strtolower( trim( (string) ( $rec['properties']['title'] ?? '' ) ) );
						if ( $rec_title === $needle && ! empty( $rec['id'] ) ) {
							$new_ghl_id = $rec['id'];
							break;
						}
					}
				}
			}

			if ( ! $new_ghl_id ) {
				return new \WP_Error( 'missing_id', 'GHL created the record but did not return an ID. Raw response: ' . substr( wp_json_encode( $result ), 0, 400 ) );
			}

			// Store GHL ID back on the post so future syncs link correctly.
			update_post_meta( $post->ID, self::GHL_ID_META, sanitize_text_field( $new_ghl_id ) );
			update_post_meta( $post->ID, '_ghl_back_synced_at', gmdate( 'c' ) );
			// Stamp synced_at so the forward sync doesn't immediately flag this post as needing an update.
			update_post_meta( $post->ID, '_ghl_synced_at', gmdate( 'c' ) );
			// Track origin so it shows correctly in records lists.
			if ( ! get_post_meta( $post->ID, '_ghl_origin', true ) ) {
				update_post_meta( $post->ID, '_ghl_origin', 'wordpress' );
			}

			return $new_ghl_id;
		}
	}

	/**
	 * Build a GHL-compatible properties array from a WP post + field map.
	 * Reverses the direction: WP fields → GHL property keys.
	 */
	private static function build_ghl_properties( \WP_Post $post, array $field_map ): array {
		$props = [];

		foreach ( $field_map as $m ) {
			$ghl_key = $m['ghl'] ?? '';
			$wp_key  = $m['wp']  ?? '';
			$type    = $m['type'] ?? 'meta';

			if ( ! $ghl_key || ! $wp_key ) continue;

			// Skip image types — we don't push images back to GHL in back-sync.
			if ( in_array( $type, [ 'image_single', 'image_gallery' ], true ) ) continue;

			switch ( $type ) {
				case 'post':
					if ( $wp_key === 'post_title' ) {
						$props[ $ghl_key ] = $post->post_title;
					} elseif ( $wp_key === 'post_content' ) {
						$props[ $ghl_key ] = $post->post_content;
					}
					break;

				case 'meta':
					$val = get_post_meta( $post->ID, $wp_key, true );
					if ( $val !== '' && $val !== false ) {
						$props[ $ghl_key ] = (string) $val;
					}
					break;

				case 'taxonomy':
					// The wp_key is the taxonomy slug (e.g. 'showcase-category').
					$terms = get_the_terms( $post->ID, $wp_key );
					if ( $terms && ! is_wp_error( $terms ) ) {
						$props[ $ghl_key ] = array_map( fn( $t ) => $t->name, $terms );
					}
					break;
			}
		}

		return $props;
	}

	// ── Log ───────────────────────────────────────────────────────────────────

	private static function write_log( array $summary ): void {
		update_option( self::LOG_OPTION, [
			'summary'   => $summary,
			'timestamp' => gmdate( 'c' ),
		], false );
	}

	public static function get_last_log(): ?array {
		$log = get_option( self::LOG_OPTION );
		return is_array( $log ) ? $log : null;
	}
}
