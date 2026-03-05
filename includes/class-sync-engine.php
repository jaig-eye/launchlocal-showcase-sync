<?php
declare(strict_types=1);

namespace GHL\ShowcaseSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync Engine — GHL → WordPress
 *
 * Syncs GHL Custom Object records to WP posts (showcase CPT).
 * v4.0 additions:
 *   - SEO meta (rank_math_title, rank_math_description) via SeoEngine
 *   - SEO-optimised image filenames, alt tags, and image titles
 *   - Taxonomy field type (showcase_category → WP taxonomy terms)
 *   - Publisher (post_author) from settings
 *   - No-duplicate image logic using stored original basename
 *   - Backlog (WP-only) posts shown in status list
 */
class SyncEngine {

	private const POST_TYPE   = 'showcase';
	private const GHL_ID_META = '_ghl_object_id';
	private const LOG_OPTION  = 'ghl_sync_last_log';

	public static function get_default_field_map(): array {
		return [
			[ 'ghl' => 'title',                  'wp' => 'post_title',       'type' => 'post'          ],
			[ 'ghl' => 'showcase_description',   'wp' => 'post_content',     'type' => 'post'          ],
			[ 'ghl' => 'showcase_description',   'wp' => 'description',      'type' => 'meta'          ],
			[ 'ghl' => 'showcase_address',       'wp' => 'address_location', 'type' => 'meta'          ],
			[ 'ghl' => 'showcase_customer',      'wp' => 'customer',         'type' => 'meta'          ],
			[ 'ghl' => 'featured_image',         'wp' => '_thumbnail',       'type' => 'image_single'  ],
			[ 'ghl' => 'showcase_image_gallery', 'wp' => 'images',           'type' => 'image_gallery' ],
			[ 'ghl' => 'showcase_category',      'wp' => 'category',         'type' => 'taxonomy'      ],
		];
	}

	public static function get_field_map(): array {
		$saved = get_option( 'ghl_sync_field_map', '' );
		if ( $saved ) {
			$decoded = json_decode( $saved, true );
			if ( is_array( $decoded ) && ! empty( $decoded ) ) {
				return $decoded;
			}
		}
		return self::get_default_field_map();
	}

	// ── Entry Points ───────────────────────────────────────────────────────────

	public static function run( int $batch_size = 0, int $offset = 0 ): array|\WP_Error {
		$api        = ApiClient::make();
		$schema_key = (string) get_option( 'ghl_sync_schema_key', '' );

		// No schema configured — return gracefully, nothing to sync.
		if ( empty( $schema_key ) ) {
			return [
				'created' => 0, 'updated' => 0, 'skipped' => 0,
				'errors'  => [], 'notices' => [ 'Schema key not configured — configure it in Settings.' ],
				'total_ghl' => 0, 'batch_size' => 0, 'offset' => 0,
			];
		}

		$records = $api->get_records();

		if ( is_wp_error( $records ) ) {
			self::write_log( 'error', $records->get_error_message(), [] );
			return $records;
		}

		$total_ghl = count( $records );

		// Build set of all current GHL IDs for orphan detection.
		$all_ghl_ids = array_filter( array_column( $records, 'id' ) );

		if ( $batch_size > 0 ) {
			$records = array_slice( $records, $offset, $batch_size );
		}

		$summary = [
			'created'          => 0,
			'updated'          => 0,
			'skipped'          => 0,
			'drafted'          => 0,
			'drafted_deleted'  => 0,
			'errors'           => [],
			'notices'          => [],
			'total_ghl'        => $total_ghl,
			'batch_size'       => $batch_size,
			'offset'           => $offset,
			'orphans_cleared'  => 0,
			'orphans_deleted'  => 0,
		];

		$field_map = self::get_field_map();

		foreach ( $records as $record ) {
			$result = self::upsert_record( $record, $field_map, $summary );
			if ( is_wp_error( $result ) ) {
				$ghl_id = $record['id'] ?? 'unknown';
				$title  = $record['properties']['title'] ?? '(no title)';
				$summary['errors'][] = "[{$ghl_id}] \"{$title}\": " . $result->get_error_message();
			} else {
				$summary[ $result ]++;
			}
		}

		// Only handle orphans on full sync (not batched mid-run).
		if ( $batch_size === 0 || ( $offset + $batch_size >= $total_ghl ) ) {
			self::handle_orphaned_records( $all_ghl_ids, $summary );
		}

		$api->bust_cache();
		self::write_log( 'success', null, $summary );
		return $summary;
	}

	/**
	 * Find WP posts whose stored _ghl_object_id no longer exists in GHL.
	 * If delete_missing option is enabled → delete the post.
	 * Otherwise → clear the GHL ID so the post becomes a backlog entry again.
	 */
	private static function handle_orphaned_records( array $current_ghl_ids, array &$summary ): void {
		global $wpdb;
		$delete_missing = (bool) get_option( 'ghl_sync_delete_missing', 0 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::GHL_ID_META
			)
		);

		foreach ( $rows as $row ) {
			$post_id = (int) $row->post_id;
			$ghl_id  = (string) $row->meta_value;

			if ( in_array( $ghl_id, $current_ghl_ids, true ) ) continue; // still exists in GHL

			if ( $delete_missing ) {
				wp_delete_post( $post_id, true );
				$summary['orphans_deleted']++;
			} else {
				// Strip the GHL link so post becomes backlog (re-syncable via Back-Sync tab).
				delete_post_meta( $post_id, self::GHL_ID_META );
				delete_post_meta( $post_id, '_ghl_synced_at' );
				// Only mark as ghl-origin if no origin is already recorded.
				// A post back-synced from WP to GHL has origin='wordpress' — preserve that.
				if ( ! get_post_meta( $post_id, '_ghl_origin', true ) ) {
					update_post_meta( $post_id, '_ghl_origin', 'ghl' );
				}
				$summary['orphans_cleared']++;
			}
		}
	}

	/**
	 * Returns ALL records from the API with their sync status.
	 * Includes backlog (WP-only) posts via BackSyncEngine.
	 */
	public static function get_all_records_with_status(): array|\WP_Error {
		$schema_key = (string) get_option( 'ghl_sync_schema_key', '' );

		// Schema not configured — return empty without error.
		if ( empty( $schema_key ) ) {
			return [
				'total' => 0, 'new' => 0, 'needs_update' => 0,
				'synced' => 0, 'pending' => 0, 'backlog' => 0, 'items' => [],
			];
		}

		$api     = ApiClient::make();
		$records = $api->get_records();

		if ( is_wp_error( $records ) ) {
			return $records;
		}

		$field_map   = self::get_field_map();
		$items       = [];
		$count_new   = 0;
		$count_upd   = 0;
		$count_sync  = 0;
		$count_draft = 0;

		foreach ( $records as $record ) {
			$ghl_id = $record['id'] ?? '';
			$props  = $record['properties'] ?? [];
			$title  = $props['title'] ?? '(Untitled)';

			// Check showcase_status first.
			$showcase_status = self::resolve_showcase_status( $props );
			$post_id         = self::find_post_by_ghl_id( $ghl_id );

			if ( $showcase_status === 'draft' ) {
				$count_draft++;
				$status = 'drafted';
				// Mirror the draft status to the linked WP post immediately.
				if ( $post_id ) {
					$wp_post = get_post( $post_id );
					if ( $wp_post && $wp_post->post_status !== 'draft' ) {
						wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
					}
				}
			} elseif ( $showcase_status === 'publish' && $post_id ) {
				// Re-publish a post that was previously drafted.
				$wp_post = get_post( $post_id );
				if ( $wp_post && $wp_post->post_status === 'draft' ) {
					wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );
				}
				$diffs  = self::get_field_diffs( $post_id, $record, $field_map );
				$status = ! empty( $diffs ) ? 'needs_update' : 'synced';
				if ( $status === 'needs_update' ) $count_upd++;
				else $count_sync++;
			} elseif ( ! $post_id ) {
				$status = 'new';
				$count_new++;
			} else {
				$diffs  = self::get_field_diffs( $post_id, $record, $field_map );
				$status = ! empty( $diffs ) ? 'needs_update' : 'synced';
				if ( $status === 'needs_update' ) $count_upd++;
				else $count_sync++;
			}

			$items[] = [
				'id'      => $ghl_id,
				'title'   => $title,
				'status'  => $status,
				'post_id' => $post_id ?: null,
				'origin'  => 'ghl',
				'diffs'   => ( $status === 'needs_update' ) ? ( $diffs ?? [] ) : [],
			];
		}

		// Append WP-only backlog posts.
		$backlog_posts = BackSyncEngine::get_backlog_posts();
		foreach ( $backlog_posts as $post ) {
			// Determine origin: posts with _ghl_origin=ghl were previously synced from GHL then orphaned.
			$stored_origin = (string) get_post_meta( $post->ID, '_ghl_origin', true );
			$items[] = [
				'id'      => null,
				'title'   => $post->post_title,
				'status'  => 'backlog',
				'post_id' => $post->ID,
				'origin'  => $stored_origin === 'ghl' ? 'ghl' : 'wordpress',
			];
		}

		return [
			'total'        => count( $records ),
			'new'          => $count_new,
			'needs_update' => $count_upd,
			'synced'       => $count_sync,
			'drafted'      => $count_draft,
			'pending'      => $count_new + $count_upd,
			'backlog'      => count( $backlog_posts ),
			'items'        => $items,
		];
	}

	// ── String Normalisation ───────────────────────────────────────────────────

	/**
	 * Normalise a field value for change-detection comparisons.
	 * Trims whitespace, collapses internal runs of whitespace, and strips
	 * invisible Unicode spaces so minor formatting differences don't trigger
	 * spurious "needs_update" flags.
	 */
	private static function norm( string $v ): string {
		$v = preg_replace( '/[\x00\xA0\xC2\xE2\x80\x8B-\x8F\xAD]+/u', ' ', $v ) ?? $v;
		$v = preg_replace( '/\s+/', ' ', $v ) ?? $v;
		return trim( $v );
	}

	/**
	 * Convert rich-text / HTML content to a plain comparable string.
	 *
	 * WYSIWYG editors (TinyMCE) and GHL store the same semantic content in
	 * wildly different markup: <p>Hello</p> vs "Hello", &nbsp; vs " ",
	 * smart-quotes, <strong>, <br> line-endings, etc.
	 *
	 * Strategy:
	 *  1. Decode all HTML entities (handles &amp; &nbsp; &#8220; etc.)
	 *  2. Replace block-level closing tags with a space so "word</p>word"
	 *     doesn't collapse to "wordword".
	 *  3. Strip remaining tags entirely.
	 *  4. Convert curly/smart quotes and common typographic chars to ASCII.
	 *  5. Run through norm() for final whitespace collapse.
	 *
	 * Result is lowercase for a fully case-insensitive comparison.
	 */
	private static function html_to_plain( string $v ): string {
		// 1. Decode entities (&amp; &nbsp; &#8220; etc.)
		$v = html_entity_decode( $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// 2. Block tags → space so adjacent words don't merge.
		$v = preg_replace( '#</(p|div|li|br|h[1-6]|blockquote|tr|td|th)[^>]*>#i', ' ', $v ) ?? $v;
		$v = str_replace( [ '<br>', '<br/>', '<br />' ], ' ', $v );

		// 3. Strip remaining tags.
		$v = wp_strip_all_tags( $v );

		// 4. Smart/curly quotes and dashes → ASCII equivalents.
		$v = str_replace(
			[ "\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "\u{00A0}" ],
			[ "'",         "'",         '"',         '"',         '-',         '-',         ' '   ],
			$v
		);

		// 5. Whitespace collapse + lowercase.
		return mb_strtolower( self::norm( $v ) );
	}

	/**
	 * Returns a list of fields that differ between GHL and WP, each as:
	 *   { field, wp, ghl }  (truncated strings for display)
	 * Empty array means no differences (i.e. post is synced).
	 */
	public static function get_field_diffs( int $post_id, array $record, array $field_map ): array {
		$props = $record['properties'] ?? [];
		$post  = get_post( $post_id );
		if ( ! $post ) return [ [ 'field' => 'post', 'wp' => '(not found)', 'ghl' => '' ] ];

		$post_origin   = (string) get_post_meta( $post_id, '_ghl_origin', true );
		$is_ghl_origin = ( $post_origin === 'ghl' ); // blank = WP-created, never assume ghl
		$diffs         = [];

		foreach ( $field_map as $m ) {
			$ghl_key   = $m['ghl']   ?? '';
			$wp_key    = $m['wp']    ?? '';
			$type      = $m['type']  ?? 'meta';
			$label     = $m['label'] ?? $wp_key;
			$ghl_value = $props[ $ghl_key ] ?? null;
			if ( $ghl_value === null ) continue;

			// Skip images for GHL-origin posts (never pushed back).
			if ( $is_ghl_origin && in_array( $type, [ 'image_single', 'image_gallery' ], true ) ) continue;

			switch ( $type ) {
				case 'post':
					if ( $wp_key === 'post_title' ) {
						// Titles are plain text — whitespace-normalize only.
						$ghl_c = self::norm( sanitize_text_field( (string) $ghl_value ) );
						$wp_c  = self::norm( $post->post_title );
						if ( $ghl_c !== $wp_c ) $diffs[] = self::make_diff( 'Title', $wp_c, $ghl_c );
					}
					if ( $wp_key === 'post_content' ) {
						// Content from WYSIWYG — strip all markup before comparing.
						$ghl_c = self::html_to_plain( (string) $ghl_value );
						$wp_c  = self::html_to_plain( $post->post_content );
						if ( $ghl_c !== $wp_c ) {
							$snip_wp  = mb_substr( strip_tags( $post->post_content ), 0, 55 );
							$snip_ghl = mb_substr( strip_tags( (string) $ghl_value ), 0, 55 );
							$diffs[] = self::make_diff( 'Content', $snip_wp . '…', $snip_ghl . '…' );
						}
					}
					break;
				case 'meta':
					// Meta may also contain WYSIWYG-produced markup or encoded chars.
					$ghl_c = self::html_to_plain( (string) $ghl_value );
					$wp_c  = self::html_to_plain( (string) get_post_meta( $post_id, $wp_key, true ) );
					if ( $ghl_c !== $wp_c ) $diffs[] = self::make_diff( $label ?: $wp_key, $wp_c, $ghl_c );
					break;
				case 'image_single':
					$new_url = self::extract_url( $ghl_value );
					if ( $new_url && (string) get_post_meta( $post_id, '_ghl_featured_image_url', true ) !== $new_url ) {
						$diffs[] = self::make_diff( 'Featured Image', '(current)', basename( $new_url ) );
					}
					break;
				case 'image_gallery':
					$new_urls  = self::extract_gallery_urls( $ghl_value );
					if ( ! empty( $new_urls ) ) {
						$cache_key = "_ghl_gallery_url_map_{$wp_key}";
						$cached    = get_post_meta( $post_id, $cache_key, true );
						$url_map   = ( is_string( $cached ) && $cached !== '' ) ? (array) json_decode( $cached, true ) : [];
						if ( array_keys( $url_map ) !== $new_urls ) {
							$diffs[] = self::make_diff( 'Gallery', count( $url_map ) . ' images', count( $new_urls ) . ' images' );
						}
					}
					break;
				case 'taxonomy':
					$tax_slug  = self::resolve_taxonomy_slug( $wp_key );
					$new_names = is_array( $ghl_value ) ? $ghl_value : [];
					$cur_terms = get_the_terms( $post_id, $tax_slug );
					$cur_names = ( $cur_terms && ! is_wp_error( $cur_terms ) ) ? array_map( fn( $t ) => $t->name, $cur_terms ) : [];
					// Case-insensitive comparison — 'Commercial' and 'commercial' are the same.
					$new_lower = array_map( 'mb_strtolower', $new_names );
					$cur_lower = array_map( 'mb_strtolower', $cur_names );
					sort( $new_lower ); sort( $cur_lower );
					if ( $new_lower !== $cur_lower ) {
						$diffs[] = self::make_diff( $label ?: $wp_key, implode( ', ', $cur_names ) ?: '(none)', implode( ', ', $new_names ) ?: '(none)' );
					}
					break;
			}
		}

		return $diffs;
	}

	/**
	 * Build a single diff entry with truncated display values.
	 */
	private static function make_diff( string $field, string $wp, string $ghl ): array {
		$trunc = fn( string $s ) => mb_strlen( $s ) > 60 ? mb_substr( $s, 0, 57 ) . '…' : $s;
		return [ 'field' => $field, 'wp' => $trunc( $wp ), 'ghl' => $trunc( $ghl ) ];
	}

	private static function detect_post_status( int $post_id, array $record, array $field_map ): bool {
		return ! empty( self::get_field_diffs( $post_id, $record, $field_map ) );
	}

	/**
	 * Entry point for cron-scheduled sync.
	 *
	 * If a batch size is configured, the cron walks through all records across
	 * multiple fires — storing the current offset in an option between runs.
	 * When all records are processed the offset resets to 0 and orphan handling runs.
	 */
	public static function run_cron(): void {
		$batch_size = (int) get_option( 'ghl_sync_batch_size', 0 );

		if ( $batch_size <= 0 ) {
			// No batching configured — run everything in one go.
			self::run( 0, 0 );
			return;
		}

		$offset    = (int) get_option( 'ghl_sync_cron_offset', 0 );
		$result    = self::run( $batch_size, $offset );

		if ( is_wp_error( $result ) ) {
			update_option( 'ghl_sync_cron_offset', 0 );
			return;
		}

		$total     = (int) ( $result['total_ghl'] ?? 0 );
		$next      = $offset + $batch_size;

		if ( $total > 0 && $next < $total ) {
			// More records remain — pick up here on the next cron fire.
			update_option( 'ghl_sync_cron_offset', $next );
		} else {
			// All done — reset for next full cycle.
			update_option( 'ghl_sync_cron_offset', 0 );
		}
	}

	/**
	 * Resolve the intended WP post_status from a GHL record's showcase_status field.
	 * Returns 'publish', 'draft', or null (field not present / not mapped).
	 */
	private static function resolve_showcase_status( array $props ): ?string {
		$raw = strtolower( trim( (string) ( $props['showcase_status'] ?? '' ) ) );
		if ( $raw === '' ) return null;
		return in_array( $raw, [ 'draft', 'draft ' ], true ) ? 'draft' : 'publish';
	}

	// ── Origin Migration ───────────────────────────────────────────────────────

	/**
	 * One-time backfill: stamp _ghl_origin on all existing showcase posts that
	 * have never been tagged. Runs on plugin init guarded by a DB option flag.
	 *
	 * Rule: if the post has NO _ghl_object_id   → origin = 'wordpress'
	 *       if it has a GHL ID but no origin     → origin = 'ghl'
	 */
	public static function maybe_migrate_origins(): void {
		if ( get_option( 'ghl_sync_origin_migrated_v1' ) ) return;

		$posts = get_posts( [
			'post_type'      => 'showcase',
			'post_status'    => [ 'publish', 'draft', 'private', 'any' ],
			'posts_per_page' => -1,
		] );

		foreach ( $posts as $post ) {
			if ( get_post_meta( $post->ID, '_ghl_origin', true ) !== '' ) continue; // already tagged
			$has_ghl_id = (bool) get_post_meta( $post->ID, self::GHL_ID_META, true );
			update_post_meta( $post->ID, '_ghl_origin', $has_ghl_id ? 'ghl' : 'wordpress' );
		}

		update_option( 'ghl_sync_origin_migrated_v1', 1 );
	}

	private static function upsert_record( array $record, array $field_map, array &$summary ): string|\WP_Error {
		$ghl_id = $record['id'] ?? null;
		$props  = $record['properties'] ?? [];

		if ( empty( $ghl_id ) ) {
			return new \WP_Error( 'missing_id', 'Record missing ID.' );
		}

		$title   = sanitize_text_field( $props['title'] ?? 'New Showcase' );
		$content = wp_kses_post( $props['showcase_description'] ?? '' );

		foreach ( $field_map as $m ) {
			if ( ( $m['type'] ?? '' ) !== 'post' ) continue;
			$v = $props[ $m['ghl'] ?? '' ] ?? null;
			if ( $v === null ) continue;
			if ( $m['wp'] === 'post_title' )   $title   = sanitize_text_field( $v );
			if ( $m['wp'] === 'post_content' ) $content = wp_kses_post( $v );
		}

		$publisher_id    = (int)    get_option( 'ghl_sync_publisher_id',    0 );
		$obey_changes    = (string) get_option( 'ghl_sync_obey_changes',    'launchlocal' );
		$exclude_draft   = (bool)   get_option( 'ghl_sync_exclude_draft',   1 );
		$delete_on_draft = (bool)   get_option( 'ghl_sync_delete_on_draft', 0 );
		$existing_id     = self::find_post_by_ghl_id( $ghl_id );

		// ── Resolve showcase_status field ──────────────────────────────────────
		$showcase_status = self::resolve_showcase_status( $props ); // 'publish'|'draft'|null
		$is_draft        = ( $showcase_status === 'draft' );

		// ── Draft handling ─────────────────────────────────────────────────────
		if ( $is_draft ) {
			if ( $existing_id ) {
				// Post already on site — mirror the status change.
				if ( $delete_on_draft ) {
					wp_delete_post( $existing_id, true );
					$summary['notices'][] = "[post:{$existing_id} \"{$title}\"] Deleted — status set to draft in LaunchLocal.";
					return 'drafted_deleted';
				}
				$wp_post = get_post( $existing_id );
				if ( $wp_post && $wp_post->post_status !== 'draft' ) {
					wp_update_post( [ 'ID' => $existing_id, 'post_status' => 'draft' ] );
					update_post_meta( $existing_id, '_ghl_synced_at', gmdate( 'c' ) );
				}
				return 'drafted';
			}
			// No existing post — don't create it if exclude_draft is on.
			if ( $exclude_draft ) return 'skipped';
			// Otherwise fall through and create with status=draft.
		}

		// ── "Obey WordPress" mode ──────────────────────────────────────────────
		if ( 'wordpress' === $obey_changes && $existing_id ) {
			// Status always mirrors LaunchLocal regardless of obey mode.
			$wp_post = get_post( $existing_id );
			if ( $showcase_status !== null && $wp_post && $wp_post->post_status !== $showcase_status ) {
				wp_update_post( [ 'ID' => $existing_id, 'post_status' => $showcase_status ] );
			}
			if ( ! self::detect_post_status( $existing_id, $record, $field_map ) ) {
				return 'skipped';
			}
			$api          = ApiClient::make();
			$wp_post      = get_post( $existing_id );
			$wp_field_map = self::get_field_map();
			$wp_props     = [];
			foreach ( $wp_field_map as $m ) {
				$type    = $m['type'] ?? 'meta';
				$ghl_key = $m['ghl'] ?? '';
				$wp_key  = $m['wp']  ?? '';
				if ( ! $ghl_key || ! $wp_key ) continue;
				if ( in_array( $type, [ 'image_single', 'image_gallery' ], true ) ) continue;
				if ( $type === 'post' ) {
					if ( $wp_key === 'post_title' )   $wp_props[ $ghl_key ] = $wp_post->post_title;
					if ( $wp_key === 'post_content' ) $wp_props[ $ghl_key ] = $wp_post->post_content;
				} elseif ( $type === 'meta' ) {
					$val = get_post_meta( $existing_id, $wp_key, true );
					if ( $val !== '' && $val !== false ) $wp_props[ $ghl_key ] = (string) $val;
				}
			}
			$push = $api->update_record( $ghl_id, $wp_props );
			if ( ! is_wp_error( $push ) ) {
				update_post_meta( $existing_id, '_ghl_synced_at', gmdate( 'c' ) );
			}
			return 'updated';
		}

		// ── Default: "Obey LaunchLocal" mode — LaunchLocal wins ───────────────
		$resolved_status = $showcase_status ?? 'publish';

		$post_data = [
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $resolved_status,
			'post_type'    => self::POST_TYPE,
		];
		if ( $publisher_id > 0 ) {
			$post_data['post_author'] = $publisher_id;
		}

		$action = 'created';

		if ( $existing_id ) {
			// ── Always sync post_status first, unconditionally ─────────────────
			// This covers both directions: publish→draft and draft→publish.
			// Status changes must apply even when no content fields have changed.
			$wp_post = get_post( $existing_id );
			if ( $wp_post && $wp_post->post_status !== $resolved_status ) {
				wp_update_post( [ 'ID' => $existing_id, 'post_status' => $resolved_status ] );
			}

			if ( ! self::detect_post_status( $existing_id, $record, $field_map ) ) {
				// Nothing else changed — status already handled above.
				return 'skipped';
			}
			$post_data['ID'] = $existing_id;
			$post_id         = wp_update_post( $post_data, true );
			$action          = 'updated';
		} else {
			$post_id = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return new \WP_Error( 'wp_insert_failed', $post_id->get_error_message() );
		}

		update_post_meta( $post_id, self::GHL_ID_META, $ghl_id );
		update_post_meta( $post_id, '_ghl_synced_at', gmdate( 'c' ) );

		if ( $action === 'created' ) {
			update_post_meta( $post_id, '_ghl_origin', 'ghl' );
		}

		// Apply SEO meta.
		self::apply_seo_meta( $post_id, $props );

		// Skip image fields only in WordPress-obey mode (we never push images back).
		// In LaunchLocal-wins mode, images should always be downloaded.
		$post_origin   = (string) get_post_meta( $post_id, '_ghl_origin', true );
		$is_ghl_origin = ( $post_origin === 'ghl' );
		$skip_images   = ( $is_ghl_origin && 'wordpress' === $obey_changes );

		// Apply field mappings.
		foreach ( $field_map as $m ) {
			$ghl_key = $m['ghl'] ?? '';
			$wp_key  = $m['wp']  ?? '';
			$type    = $m['type'] ?? 'meta';

			if ( ! $ghl_key || ! $wp_key || $type === 'post' ) continue;

			$value = $props[ $ghl_key ] ?? null;
			if ( $value === null ) continue;

			if ( $skip_images && in_array( $type, [ 'image_single', 'image_gallery' ], true ) ) continue;

			switch ( $type ) {
				case 'meta':
					update_post_meta( $post_id, $wp_key, sanitize_textarea_field( (string) $value ) );
					break;

				case 'image_single':
					$url = self::extract_url( $value );
					if ( $url ) {
						$notice = self::sync_featured_image( $post_id, $url, $props );
						if ( $notice ) $summary['notices'][] = "[post:{$post_id} \"{$title}\"] {$wp_key}: {$notice}";
					} else {
						$summary['notices'][] = "[post:{$post_id}] {$wp_key}: cannot extract URL. Raw: " . substr( wp_json_encode( $value ), 0, 200 );
					}
					break;

				case 'image_gallery':
					$notices = self::sync_image_gallery( $post_id, $value, $wp_key, $title, $props );
					foreach ( $notices as $n ) $summary['notices'][] = $n;
					break;

				case 'taxonomy':
					$tax_slug = self::resolve_taxonomy_slug( $wp_key );
					self::sync_taxonomy( $post_id, $value, $tax_slug );
					break;
			}
		}

		return $action;
	}

	// ── SEO ────────────────────────────────────────────────────────────────────

	private static function apply_seo_meta( int $post_id, array $props ): void {
		// Only write rank_math meta when the SEO override toggle is explicitly enabled.
		// Image names/alt tags are always optimised (handled in sideload_image).
		if ( ! SeoEngine::is_override_enabled() ) {
			return;
		}
		$seo_title = SeoEngine::generate_title( $props );
		$seo_desc  = SeoEngine::generate_description( $props );
		if ( $seo_title ) update_post_meta( $post_id, 'rank_math_title', $seo_title );
		if ( $seo_desc )  update_post_meta( $post_id, 'rank_math_description', $seo_desc );
	}

	// ── Taxonomy ──────────────────────────────────────────────────────────────

	private static function resolve_taxonomy_slug( string $wp_key ): string {
		$override = (string) get_option( 'ghl_sync_taxonomy_slug', '' );
		return $override ?: $wp_key;
	}

	private static function sync_taxonomy( int $post_id, mixed $value, string $tax_slug ): void {
		if ( ! taxonomy_exists( $tax_slug ) ) return;

		$names = is_array( $value ) ? array_map( 'strval', $value )
		       : ( is_string( $value ) ? array_map( 'trim', explode( ',', $value ) ) : [] );
		$names = array_filter( $names );

		if ( empty( $names ) ) {
			wp_set_object_terms( $post_id, [], $tax_slug );
			return;
		}

		$term_ids = [];
		foreach ( $names as $name ) {
			$name = sanitize_text_field( $name );
			// Case-insensitive lookup: find existing term ignoring case.
			$term = get_term_by( 'name', $name, $tax_slug );
			if ( ! $term ) {
				// Try a slugified lookup too (handles 'commercial' vs 'Commercial').
				$term = get_term_by( 'slug', sanitize_title( $name ), $tax_slug );
			}
			if ( ! $term ) {
				$result = wp_insert_term( $name, $tax_slug );
				if ( is_wp_error( $result ) ) continue;
				$term_ids[] = (int) $result['term_id'];
			} else {
				$term_ids[] = (int) $term->term_id;
			}
		}

		wp_set_object_terms( $post_id, $term_ids, $tax_slug );
		update_post_meta( $post_id, 'category', implode( ',', $term_ids ) );
	}

	// ── URL Extraction ─────────────────────────────────────────────────────────

	private static function extract_url( mixed $value ): string {
		if ( is_array( $value ) && isset( $value[0] ) ) {
			$first = $value[0];
			if ( is_array( $first ) && ! empty( $first['url'] ) ) return esc_url_raw( (string) $first['url'] );
			if ( is_string( $first ) && filter_var( $first, FILTER_VALIDATE_URL ) ) return esc_url_raw( $first );
		}
		if ( is_array( $value ) && ! empty( $value['url'] ) ) return esc_url_raw( (string) $value['url'] );
		if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ) return esc_url_raw( $value );
		return '';
	}

	private static function extract_gallery_urls( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			if ( is_string( $value ) ) { $decoded = json_decode( $value, true ); $value = is_array( $decoded ) ? $decoded : []; }
			else return [];
		}
		$urls = [];
		foreach ( $value as $item ) {
			if ( is_array( $item ) && ! empty( $item['url'] ) ) {
				$url = esc_url_raw( trim( (string) $item['url'] ) );
				if ( $url ) $urls[] = $url;
			} elseif ( is_string( $item ) && filter_var( $item, FILTER_VALIDATE_URL ) ) {
				$urls[] = esc_url_raw( trim( $item ) );
			}
		}
		return $urls;
	}

	// ── Image Sync ─────────────────────────────────────────────────────────────

	private static function sync_featured_image( int $post_id, string $url, array $props = [] ): string {
		if ( (string) get_post_meta( $post_id, '_ghl_featured_image_url', true ) === $url ) return '';
		$att_id = self::sideload_image( $url, $post_id, $props, 1, 'featured' );
		if ( is_wp_error( $att_id ) ) {
			return "sideload failed \"{$url}\": " . $att_id->get_error_code() . ' — ' . $att_id->get_error_message();
		}
		set_post_thumbnail( $post_id, $att_id );
		update_post_meta( $post_id, '_ghl_featured_image_url', $url );
		return '';
	}

	private static function sync_image_gallery( int $post_id, mixed $raw, string $meta_key, string $title, array $props = [] ): array {
		$notices = [];
		$urls    = self::extract_gallery_urls( $raw );

		if ( empty( $urls ) ) {
			$notices[] = "[post:{$post_id} \"{$title}\"] Gallery ({$meta_key}): no URLs found.";
			return $notices;
		}

		$cache_key  = "_ghl_gallery_url_map_{$meta_key}";
		$cached_raw = get_post_meta( $post_id, $cache_key, true );
		$url_map    = ( is_string( $cached_raw ) && $cached_raw !== '' ) ? (array) json_decode( $cached_raw, true ) : [];
		$att_ids    = [];

		foreach ( $urls as $i_zero => $url ) {
			$img_idx = $i_zero + 1;
			if ( isset( $url_map[ $url ] ) ) {
				$existing = get_post( (int) $url_map[ $url ] );
				if ( $existing && $existing->post_type === 'attachment' ) {
					$att_ids[] = (int) $url_map[ $url ];
					continue;
				}
				$notices[] = "[post:{$post_id}] Gallery: cached attachment {$url_map[$url]} gone, re-downloading.";
			}

			$att_id = self::sideload_image( $url, $post_id, $props, $img_idx, 'gallery' );
			if ( is_wp_error( $att_id ) ) {
				$notices[] = "[post:{$post_id} \"{$title}\"] Gallery sideload failed \"{$url}\": " . $att_id->get_error_code() . ' — ' . $att_id->get_error_message();
				continue;
			}
			$url_map[ $url ] = $att_id;
			$att_ids[]       = $att_id;
		}

		update_post_meta( $post_id, $cache_key, wp_json_encode( $url_map ) );

		if ( empty( $att_ids ) ) {
			$notices[] = "[post:{$post_id} \"{$title}\"] Gallery: all URLs failed.";
			return $notices;
		}

		delete_post_meta( $post_id, $meta_key );
		foreach ( $att_ids as $id ) add_post_meta( $post_id, $meta_key, (string) $id );

		return $notices;
	}

	/**
	 * Sideload an image with SEO-optimised filename, alt tag, and title.
	 *
	 * Uniqueness strategy:
	 *  - Callers (sync_featured_image / sync_image_gallery) already check their
	 *    own URL→attachment maps before calling here, so duplicate URLs are
	 *    filtered out before reaching this method in normal flow.
	 *  - As a global safety net we store `_ghl_source_url` on each attachment.
	 *    If two different posts / fields share the same source URL we reuse the
	 *    same attachment rather than uploading a second copy.
	 *  - The filename hash is md5($url) — URL is globally unique so filenames
	 *    are guaranteed unique even if the original basenames are identical.
	 *  - $context ('featured' | 'gallery') is baked into the SEO filename so
	 *    the featured image and gallery image #1 of the same post never collide.
	 *
	 * @param string $url       Remote image URL.
	 * @param int    $post_id   Parent WP post.
	 * @param array  $props     GHL record properties (for SEO generation).
	 * @param int    $index     1-based position (featured=1, gallery=1,2,3…).
	 * @param string $context   'featured' | 'gallery'.
	 */
	private static function sideload_image(
		string $url,
		int    $post_id,
		array  $props   = [],
		int    $index   = 1,
		string $context = 'img'
	): int|\WP_Error {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		global $wpdb;

		// ── Dedup: reuse existing attachment if this URL was already sideloaded ─
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key = '_ghl_source_url' AND meta_value = %s
			 LIMIT 1",
			$url
		) );
		if ( $existing_id ) {
			return (int) $existing_id;
		}

		// ── Derive extension from URL ──────────────────────────────────────────
		$raw_path     = wp_parse_url( $url, PHP_URL_PATH ) ?? '';
		$clean_name   = (string) preg_replace( '/[?#].*$/', '', basename( $raw_path ) );
		$original_ext = strtolower( pathinfo( $clean_name, PATHINFO_EXTENSION ) );

		// ── Build SEO filename ─────────────────────────────────────────────────
		// generate_image_filename() appends md5($url) as the uniqueness suffix.
		$seo_base  = SeoEngine::generate_image_filename( $props, $index, $context, $url );
		$seo_file  = $seo_base . ( $original_ext ? '.' . $original_ext : '' );
		$seo_alt   = SeoEngine::generate_image_alt( $props, $index, $context );
		$seo_title = SeoEngine::generate_image_title( $props, $index, $context );

		// ── Download & sideload ────────────────────────────────────────────────
		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) return $tmp;

		$att_id = media_handle_sideload( [ 'name' => $seo_file, 'tmp_name' => $tmp ], $post_id, $seo_title );
		if ( is_wp_error( $att_id ) ) { @unlink( $tmp ); return $att_id; }

		$att_id = (int) $att_id;

		// ── Store metadata ─────────────────────────────────────────────────────
		if ( $seo_alt ) update_post_meta( $att_id, '_wp_attachment_image_alt', $seo_alt );
		update_post_meta( $att_id, '_ghl_source_url', $url ); // used for dedup on re-sync

		return $att_id;
	}

	// ── DB Helpers ─────────────────────────────────────────────────────────────

	private static function find_post_by_ghl_id( string $ghl_id ): int|false {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$post_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s LIMIT 1",
			self::GHL_ID_META, $ghl_id
		) );
		return $post_id ? (int) $post_id : false;
	}

	private static function write_log( string $status, ?string $error, array $summary = [] ): void {
		update_option( self::LOG_OPTION, [
			'status'    => $status,
			'error'     => $error,
			'summary'   => $summary,
			'timestamp' => gmdate( 'c' ),
		], false );
	}

	public static function get_last_log(): ?array {
		$log = get_option( self::LOG_OPTION );
		return is_array( $log ) ? $log : null;
	}
}
