<?php
declare(strict_types=1);

namespace GHL\ShowcaseSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GHL API Client
 *
 * ── Endpoint map ──────────────────────────────────────────────────────────────
 *  GET  /objects/?locationId=          List all schemas for a location
 *  POST /objects/:key/records/search   Search / list records (only bulk endpoint)
 *                                       Body: { locationId, page, pageLimit }
 *
 * ── Schema key format ──────────────────────────────────────────────────────────
 *  "custom_objects.<plural_label>" e.g. "custom_objects.showcases"
 *  Must be rawurlencode()'d when placed in a URL path segment.
 */
class ApiClient {

	private const BASE_URL    = 'https://services.leadconnectorhq.com';
	private const API_VERSION = '2021-07-28';
	private const TIMEOUT     = 20;
	private const CACHE_GROUP = 'ghl_sync';
	private const CACHE_TTL   = 60;

	/** Stores the last raw HTTP response body for debug inspection. */
	private static string $last_raw_response = '';
	private static int    $last_http_code    = 0;
	private static string $last_endpoint     = '';

	private string $token;
	private string $location_id;
	private string $schema_key;

	private function __construct( string $token, string $location_id, string $schema_key ) {
		$this->token       = $token;
		$this->location_id = $location_id;
		$this->schema_key  = $schema_key;
	}

	public static function make(): self {
		return new self(
			(string) get_option( 'ghl_sync_token', '' ),
			(string) get_option( 'ghl_sync_location_id', '' ),
			(string) get_option( 'ghl_sync_schema_key', 'custom_objects.showcases' ),
		);
	}

	// ── Public API Methods ─────────────────────────────────────────────────────

	/**
	 * Verify connection — lists all schemas for the location.
	 * GET /objects/?locationId=
	 */
	public function verify_connection(): array|\WP_Error {
		if ( empty( $this->token ) || empty( $this->location_id ) ) {
			return new \WP_Error( 'missing_credentials', 'API token and Location ID are required.' );
		}

		$response = $this->get( '/objects/', [ 'locationId' => $this->location_id ] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$objects     = $response['objects'] ?? [];
		$schema_keys = array_column( $objects, 'key' );
		$schemas     = array_map( fn( array $obj ) => [
			'key'   => $obj['key'] ?? '',
			'label' => $obj['labels']['plural'] ?? ( $obj['labels']['singular'] ?? $obj['key'] ?? '' ),
		], $objects );

		return [
			'schemas'      => $schemas,
			'schema_count' => count( $schemas ),
			'schema_found' => ! empty( $this->schema_key ) && in_array( $this->schema_key, $schema_keys, true ),
			'schema_key'   => $this->schema_key,
		];
	}

	/**
	 * Fetch all records — POST /objects/:key/records/search
	 * Returns the flat list of records for syncing.
	 *
	 * @return list<array>|\WP_Error
	 */
	public function get_records(): array|\WP_Error {
		if ( empty( $this->location_id ) || empty( $this->schema_key ) ) {
			// Return empty silently — schema not yet configured, no records to show.
			return [];
		}

		$cache_key = 'records_' . md5( $this->location_id . $this->schema_key );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$all_records = [];
		$page        = 1;
		$page_size   = 100;
		$encoded_key = rawurlencode( $this->schema_key );

		do {
			$response = $this->post(
				"/objects/{$encoded_key}/records/search",
				[
					'locationId' => $this->location_id,
					'page'       => $page,
					'pageLimit'  => $page_size,
				]
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$records     = $response['records'] ?? $response['data'] ?? [];
			$all_records = array_merge( $all_records, $records );

			$total    = (int) ( $response['meta']['total'] ?? $response['total'] ?? 0 );
			$has_more = $total > 0
				? count( $all_records ) < $total
				: count( $records ) === $page_size;

			$page++;

		} while ( $has_more );

		wp_cache_set( $cache_key, $all_records, self::CACHE_GROUP, self::CACHE_TTL );

		return $all_records;
	}

	/**
	 * Fetch one page of records and return the COMPLETE raw response envelope.
	 * Used exclusively by the debug panel — never cached.
	 *
	 * @return array|\WP_Error  Full response body as-is from GHL.
	 */
	public function get_records_raw(): array|\WP_Error {
		if ( empty( $this->location_id ) || empty( $this->schema_key ) ) {
			return new \WP_Error( 'missing_config', 'Location ID and Schema Key are required.' );
		}

		return $this->post(
			'/objects/' . rawurlencode( $this->schema_key ) . '/records/search',
			[
				'locationId' => $this->location_id,
				'page'       => 1,
				'pageLimit'  => 10, // small page — just enough to inspect the structure
			]
		);
	}

	public function bust_cache(): void {
		wp_cache_delete( 'records_' . md5( $this->location_id . $this->schema_key ), self::CACHE_GROUP );
	}

	/**
	 * Create a new GHL Custom Object record.
	 * POST /objects/:key/records
	 *
	 * @param array $properties  Key-value map of record field values.
	 * @return array|\WP_Error   Full response envelope on success.
	 */
	public function create_record( array $properties ): array|\WP_Error {
		if ( empty( $this->location_id ) || empty( $this->schema_key ) ) {
			return new \WP_Error( 'missing_config', 'Location ID and Schema Key are required.' );
		}
		$encoded_key = rawurlencode( $this->schema_key );
		return $this->post(
			"/objects/{$encoded_key}/records",
			[
				'locationId' => $this->location_id,
				'properties' => $properties,
			]
		);
	}

	/**
	 * Update an existing GHL Custom Object record.
	 * PUT /objects/:key/records/:id
	 *
	 * @param string $record_id   GHL record ID.
	 * @param array  $properties  Fields to update.
	 * @return array|\WP_Error
	 */
	public function update_record( string $record_id, array $properties ): array|\WP_Error {
		if ( empty( $this->location_id ) || empty( $this->schema_key ) ) {
			return new \WP_Error( 'missing_config', 'Location ID and Schema Key are required.' );
		}
		$encoded_key = rawurlencode( $this->schema_key );
		$encoded_id  = rawurlencode( $record_id );
		$url         = self::BASE_URL . "/objects/{$encoded_key}/records/{$encoded_id}";
		self::$last_endpoint = 'PUT ' . $url;
		return $this->parse_response(
			wp_remote_request( $url, [
				'method'  => 'PUT',
				'timeout' => self::TIMEOUT,
				'headers' => $this->build_headers(),
				'body'    => wp_json_encode( [
					'locationId' => $this->location_id,
					'properties' => $properties,
				] ),
			] )
		);
	}

	// ── Last-response accessors (for debug panel) ──────────────────────────────

	public static function get_last_raw(): string  { return self::$last_raw_response; }
	public static function get_last_code(): int    { return self::$last_http_code; }
	public static function get_last_endpoint(): string { return self::$last_endpoint; }

	// ── HTTP Helpers ────────────────────────────────────────────────────────────

	/** @param array<string,scalar> $params */
	private function get( string $endpoint, array $params = [] ): array|\WP_Error {
		$url = self::BASE_URL . $endpoint;
		if ( ! empty( $params ) ) {
			$url = add_query_arg( $params, $url );
		}
		self::$last_endpoint = 'GET ' . $url;
		return $this->parse_response(
			wp_remote_get( $url, [ 'timeout' => self::TIMEOUT, 'headers' => $this->build_headers() ] )
		);
	}

	/** @param array<string,mixed> $body */
	private function post( string $endpoint, array $body = [] ): array|\WP_Error {
		$url = self::BASE_URL . $endpoint;
		self::$last_endpoint = 'POST ' . $url;
		return $this->parse_response(
			wp_remote_post( $url, [
				'timeout' => self::TIMEOUT,
				'headers' => $this->build_headers(),
				'body'    => wp_json_encode( $body ),
			] )
		);
	}

	/** @return array<string,string> */
	private function build_headers(): array {
		return [
			'Authorization' => 'Bearer ' . $this->token,
			'Version'       => self::API_VERSION,
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json',
		];
	}

	/** @param array|\WP_Error $response */
	private function parse_response( $response ): array|\WP_Error {
		if ( is_wp_error( $response ) ) {
			self::$last_raw_response = $response->get_error_message();
			self::$last_http_code    = 0;
			return new \WP_Error( 'http_request_failed', 'HTTP request failed: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );

		// Always store the last raw response so the debug panel can show it.
		self::$last_raw_response = $raw;
		self::$last_http_code    = $code;

		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'invalid_json', sprintf( 'Non-JSON response (HTTP %d): %s', $code, substr( $raw, 0, 300 ) ) );
		}

		switch ( $code ) {
			case 200:
			case 201:
				return $body;
			case 401:
				return new \WP_Error( 'unauthorized', 'Invalid or expired API token. Verify your Private Integration Token.' );
			case 403:
				return new \WP_Error( 'forbidden', 'Permission denied. Ensure your token has "objects/schema.readonly" and "objects/record.readonly" scopes.' );
			case 404:
				return new \WP_Error( 'not_found', sprintf( 'Endpoint not found (HTTP 404) for schema key "%s". Use "Test Connection" to see valid keys.', $this->schema_key ) );
			case 422:
				return new \WP_Error( 'unprocessable', $body['message'] ?? $body['msg'] ?? 'Validation error — check locationId and schema key.' );
			default:
				return new \WP_Error( "ghl_error_{$code}", $body['message'] ?? $body['msg'] ?? "Unexpected GHL error (HTTP {$code})." );
		}
	}

	/** Sanitize a schema key, preserving dots (sanitize_key() strips them). */
	public static function sanitize_schema_key( string $key ): string {
		return strtolower( trim( preg_replace( '/[^a-z0-9_.]/', '', strtolower( $key ) ) ) );
	}
}
