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
		add_action( 'init', [ $this, 'register_post_type' ] );
	}

	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'labels'       => [
					'name'               => _x( 'Showcases', 'post type general name', 'ghl-showcase-sync' ),
					'singular_name'      => _x( 'Showcase', 'post type singular name', 'ghl-showcase-sync' ),
					'add_new_item'       => __( 'Add New Showcase', 'ghl-showcase-sync' ),
					'edit_item'          => __( 'Edit Showcase', 'ghl-showcase-sync' ),
					'new_item'           => __( 'New Showcase', 'ghl-showcase-sync' ),
					'view_item'          => __( 'View Showcase', 'ghl-showcase-sync' ),
					'search_items'       => __( 'Search Showcases', 'ghl-showcase-sync' ),
					'not_found'          => __( 'No showcases found.', 'ghl-showcase-sync' ),
					'not_found_in_trash' => __( 'No showcases found in Trash.', 'ghl-showcase-sync' ),
					'menu_name'          => __( 'Showcases', 'ghl-showcase-sync' ),
				],
				'public'              => true,
				'has_archive'         => true,
				'rewrite'             => [ 'slug' => 'showcases' ],
				'supports'            => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ],
				'show_in_rest'        => true,
				'menu_icon'           => 'dashicons-star-filled',
				'capability_type'     => 'post',
				'hierarchical'        => false,
				'exclude_from_search' => false,
			]
		);
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
