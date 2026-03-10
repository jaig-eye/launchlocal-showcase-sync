<?php
declare(strict_types=1);

namespace GHL\ShowcaseSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the 'showcase' custom post type.
 */
class PostType {

	private const POST_TYPE = 'showcase';

	private static ?PostType $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function register(): void {
		// The 'showcase' CPT is managed by MetaBox — no registration here.
		// This hook intentionally left empty to avoid overwriting CPT args or icon.
	}

	/**
	 * Field map: GHL record value key → WordPress post meta key.
	 *
	 * @return array<string,string>
	 */
	public static function get_field_map(): array {
		return [
			'title'               => 'post_title',         // handled separately
			'showcase_description'=> 'post_content',       // handled separately
			'showcase_location'   => 'showcase_location',
			'showcase_address'    => 'showcase_address',
			'customer'            => 'showcase_customer',
			'featured_image'      => '_thumbnail_url',     // handled separately
		];
	}
}
