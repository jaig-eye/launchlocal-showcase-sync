<?php
declare(strict_types=1);

namespace GHL\ShowcaseSync;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SEO Engine
 *
 * Generates optimised rank_math_title, rank_math_description, and
 * SEO-friendly image filenames / alt tags / image titles.
 *
 * Key design decisions:
 *  - Image filenames are ALWAYS optimised (regardless of SEO override toggle).
 *  - rank_math_title / rank_math_description are only written when the
 *    "Enable SEO Tag Override" setting is ON.
 *  - Uniqueness per image is guaranteed by md5($url) — URL is always unique,
 *    even when two images share the same original filename.
 *  - {auto_fill} token randomly picks from the configured keyword list, adding
 *    brand identity and variation across titles/descriptions.
 */
class SeoEngine {

	// ── Option keys ────────────────────────────────────────────────────────────

	public const OPT_SEO_OVERRIDE      = 'ghl_seo_override_enabled';  // bool: write rank_math meta
	public const OPT_TITLE_PATTERN     = 'ghl_seo_title_pattern';
	public const OPT_DESC_PATTERN      = 'ghl_seo_desc_pattern';
	public const OPT_IMG_NAME_PATTERN  = 'ghl_seo_img_name_pattern';
	public const OPT_IMG_ALT_PATTERN   = 'ghl_seo_img_alt_pattern';
	public const OPT_IMG_TITLE_PATTERN = 'ghl_seo_img_title_pattern';
	public const OPT_AUTO_FILL         = 'ghl_seo_auto_fill_keywords'; // newline-separated keywords

	// ── Defaults ───────────────────────────────────────────────────────────────

	public static function default_title_pattern(): string {
		return '{title} | {city} | {site_name}';
	}

	public static function default_desc_pattern(): string {
		return '{description_excerpt} — {title} in {city}. {brand_close}';
	}

	public static function default_img_name_pattern(): string {
		return '{title}-{auto_fill}-{index}-{context}-{city_slug}';
	}

	public static function default_img_alt_pattern(): string {
		return '{title} - {location} - {auto_fill} | {site_name} - {city}';
	}

	public static function default_img_title_pattern(): string {
		return '{title} Image {city} {context} {index} | {site_name}';
	}

	// ── SEO override check ─────────────────────────────────────────────────────

	public static function is_override_enabled(): bool {
		return (bool) get_option( self::OPT_SEO_OVERRIDE, 0 );
	}

	// ── Auto-fill keywords ─────────────────────────────────────────────────────

	/**
	 * Returns the keyword list as an array. Returns empty array if none set.
	 */
	public static function get_keywords(): array {
		$raw = (string) get_option( self::OPT_AUTO_FILL, '' );
		if ( empty( trim( $raw ) ) ) return [];
		$keywords = array_filter( array_map( 'trim', preg_split( '/[\n,]+/', $raw ) ) );
		return array_values( $keywords );
	}

	/**
	 * Pick a random keyword from the list, or empty string if none configured.
	 */
	public static function pick_keyword(): string {
		$keywords = self::get_keywords();
		if ( empty( $keywords ) ) return '';
		return $keywords[ array_rand( $keywords ) ];
	}

	// ── Token resolution ───────────────────────────────────────────────────────

	/**
	 * Build the token map for a given showcase record.
	 *
	 * @param array  $props    GHL record properties.
	 * @param int    $index    1-based image index within its context (featured=1, gallery=1,2,3…).
	 * @param string $context  'featured' or 'gallery' — prevents name collisions.
	 */
	public static function build_token_map( array $props, int $index = 1, string $context = 'img' ): array {
		$site_name   = wp_strip_all_tags( get_bloginfo( 'name' ) );
		$title       = sanitize_text_field( $props['title'] ?? '' );
		$description = wp_strip_all_tags( $props['showcase_description'] ?? '' );
		$address     = sanitize_text_field( $props['showcase_address'] ?? '' );

		$city      = self::extract_city( $address );
		$state     = self::extract_state( $address );
		$slug      = sanitize_title( $title );
		$city_slug = sanitize_title( $city );
		$excerpt   = self::smart_truncate( $description, 130 );
		$brand_close = sprintf( 'Showcased by %s.', $site_name );
		$keyphrase   = self::extract_keyphrase( $title );
		$auto_fill   = self::pick_keyword();

		return [
			'{title}'              => $title,
			'{title_slug}'         => $slug,
			'{keyphrase}'          => $keyphrase,
			'{description_excerpt}'=> $excerpt,
			'{address}'            => $address,
			'{city}'               => $city ?: $site_name,
			'{city_slug}'          => $city_slug ?: sanitize_title( $site_name ),
			'{state}'              => $state,
			'{location}'           => $address ?: $city ?: $site_name,
			'{site_name}'          => $site_name,
			'{brand_close}'        => $brand_close,
			'{index}'              => (string) $index,
			'{context}'            => $context,   // 'featured' or 'gallery'
			'{auto_fill}'          => $auto_fill, // random keyword from list
		];
	}

	// ── Public generators ──────────────────────────────────────────────────────

	/** rank_math_title — only written when override is enabled. */
	public static function generate_title( array $props ): string {
		$pattern = (string) get_option( self::OPT_TITLE_PATTERN, self::default_title_pattern() );
		$result  = self::fill_pattern( $pattern, self::build_token_map( $props ) );
		return self::truncate( $result, 60 );
	}

	/** rank_math_description — only written when override is enabled. */
	public static function generate_description( array $props ): string {
		$pattern = (string) get_option( self::OPT_DESC_PATTERN, self::default_desc_pattern() );
		$result  = self::fill_pattern( $pattern, self::build_token_map( $props ) );

		if ( empty( trim( $result ) ) ) {
			$tokens = self::build_token_map( $props );
			$title  = $tokens['{title}'];
			$city   = $tokens['{city}'];
			$site   = $tokens['{site_name}'];
			$desc   = $tokens['{description_excerpt}'];
			$result = $desc
				? "{$desc} — {$title}" . ( $city ? " in {$city}" : '' ) . ". Brought to you by {$site}."
				: "{$title}" . ( $city ? " — Serving {$city}" : '' ) . ". Brought to you by {$site}.";
		}

		return self::truncate( $result, 160 );
	}

	/**
	 * Generate a unique SEO filename for an image (no extension).
	 *
	 * Uniqueness is guaranteed by appending md5($url) — the URL is always
	 * unique, so two images with identical original filenames still get
	 * distinct names. Context ('featured' vs 'gallery') prevents collisions
	 * between the featured image and gallery image #1.
	 *
	 * @param array  $props    GHL record properties.
	 * @param int    $index    1-based image position.
	 * @param string $context  'featured' | 'gallery' (or custom string).
	 * @param string $url      Source URL — used for the uniqueness hash.
	 */
	public static function generate_image_filename(
		array $props,
		int $index = 1,
		string $context = 'img',
		string $url = ''
	): string {
		$pattern = (string) get_option( self::OPT_IMG_NAME_PATTERN, self::default_img_name_pattern() );
		$tokens  = self::build_token_map( $props, $index, $context );
		$result  = self::fill_pattern( $pattern, $tokens );
		$slug    = sanitize_title( $result );

		// Hash based on URL (not original filename) — guarantees global uniqueness.
		$hash_input = $url ?: ( $slug . '-' . $index . '-' . $context );
		$hash       = substr( md5( $hash_input ), 0, 8 );

		return strtolower( trim( $slug . '-' . $hash, '-' ) );
	}

	/** Alt tag for an image attachment. Always applied regardless of override toggle. */
	public static function generate_image_alt( array $props, int $index = 1, string $context = 'img' ): string {
		$pattern = (string) get_option( self::OPT_IMG_ALT_PATTERN, self::default_img_alt_pattern() );
		$tokens  = self::build_token_map( $props, $index, $context );
		return wp_strip_all_tags( self::fill_pattern( $pattern, $tokens ) );
	}

	/** Attachment title (media library). Always applied regardless of override toggle. */
	public static function generate_image_title( array $props, int $index = 1, string $context = 'img' ): string {
		$pattern = (string) get_option( self::OPT_IMG_TITLE_PATTERN, self::default_img_title_pattern() );
		$tokens  = self::build_token_map( $props, $index, $context );
		return wp_strip_all_tags( self::fill_pattern( $pattern, $tokens ) );
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	private static function fill_pattern( string $pattern, array $tokens ): string {
		$result = strtr( $pattern, $tokens );
		$result = preg_replace( '/[\s\|\-–—,]+\s*[\|\-–—,]+/', ' | ', $result );
		$result = preg_replace( '/\s{2,}/', ' ', $result );
		return trim( $result, " \t\n\r|,-–—" );
	}

	private static function truncate( string $text, int $max ): string {
		if ( mb_strlen( $text ) <= $max ) return $text;
		$cut = mb_substr( $text, 0, $max - 1 );
		$pos = mb_strrpos( $cut, ' ' );
		return $pos ? mb_substr( $cut, 0, $pos ) . '…' : $cut . '…';
	}

	private static function smart_truncate( string $text, int $max ): string {
		$text = preg_replace( '/\s+/', ' ', trim( $text ) );
		return self::truncate( $text, $max );
	}

	private static function extract_city( string $address ): string {
		if ( empty( $address ) ) return '';
		$parts = array_map( 'trim', explode( ',', $address ) );
		if ( count( $parts ) >= 3 ) return $parts[ count( $parts ) - 2 ];
		if ( count( $parts ) === 2 ) return $parts[0];
		return $parts[0];
	}

	private static function extract_state( string $address ): string {
		if ( empty( $address ) ) return '';
		if ( preg_match( '/,\s*([A-Z]{2})\s*(?:\d{5})?(?:$|,)/', $address, $m ) ) return $m[1];
		return '';
	}

	private static function extract_keyphrase( string $title ): string {
		$stopwords = [ 'the','a','an','and','or','but','in','on','at','to','for','of','with','by' ];
		$kept = [];
		foreach ( preg_split( '/\s+/', $title ) as $w ) {
			if ( ! in_array( strtolower( $w ), $stopwords, true ) && strlen( $w ) > 1 ) $kept[] = $w;
			if ( count( $kept ) >= 3 ) break;
		}
		return implode( ' ', $kept ) ?: $title;
	}

	// ── Settings metadata (for UI rendering) ──────────────────────────────────

	public static function get_pattern_options(): array {
		return [
			[
				'key'     => self::OPT_TITLE_PATTERN,
				'label'   => 'SEO Title Tag Pattern',
				'default' => self::default_title_pattern(),
				'help'    => 'Generates <code>rank_math_title</code>. Max ~60 chars. Only applied when SEO Override is enabled.',
			],
			[
				'key'     => self::OPT_DESC_PATTERN,
				'label'   => 'SEO Description Pattern',
				'default' => self::default_desc_pattern(),
				'help'    => 'Generates <code>rank_math_description</code>. Max ~160 chars. Only applied when SEO Override is enabled.',
			],
			[
				'key'     => self::OPT_IMG_NAME_PATTERN,
				'label'   => 'Image Filename Pattern',
				'default' => self::default_img_name_pattern(),
				'help'    => 'Applied to every sideloaded image — always active. A URL-based hash is appended automatically for uniqueness.',
			],
			[
				'key'     => self::OPT_IMG_ALT_PATTERN,
				'label'   => 'Image Alt Tag Pattern',
				'default' => self::default_img_alt_pattern(),
				'help'    => 'Sets attachment alt text — always active.',
			],
			[
				'key'     => self::OPT_IMG_TITLE_PATTERN,
				'label'   => 'Image Title Pattern',
				'default' => self::default_img_title_pattern(),
				'help'    => 'Sets the media library attachment title — always active.',
			],
		];
	}

	public static function get_tokens_reference(): array {
		return [
			'{title}'               => 'Showcase title',
			'{city}'                => 'City extracted from address',
			'{city_slug}'           => 'URL-safe city slug',
			'{state}'               => 'State abbreviation from address',
			'{location}'            => 'Full address if available, else city',
			'{site_name}'           => 'WordPress site title',
			'{description_excerpt}' => 'First ~130 chars of description',
			'{brand_close}'         => 'Auto brand closing sentence',
			'{index}'               => 'Image number within context (1, 2, 3…)',
			'{context}'             => '"featured" or "gallery"',
			'{auto_fill}'           => 'Random keyword from Auto-Fill Keywords list',
		];
	}
}
