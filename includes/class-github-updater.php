<?php
/**
 * GitHub-based auto-updater for LaunchLocal Showcase Sync.
 *
 * Hooks into WordPress's update system so sites running this plugin
 * receive update notices whenever a new GitHub release is published.
 */

declare(strict_types=1);

namespace GHL\ShowcaseSync;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class GithubUpdater {

	private const GITHUB_REPO = 'jaig-eye/launchlocal-showcase-sync';
	private const CACHE_KEY   = 'ghl_sync_github_release';
	private const CACHE_TTL   = 12 * HOUR_IN_SECONDS;

	private string $slug;
	private string $plugin_file;
	private string $version;

	public function __construct( string $plugin_file, string $version ) {
		$this->plugin_file = $plugin_file;
		$this->slug        = plugin_basename( $plugin_file );
		$this->version     = $version;
	}

	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update'   ]        );
		add_filter( 'plugins_api',                           [ $this, 'plugin_info'     ], 10, 3 );
		add_filter( 'upgrader_source_selection',             [ $this, 'fix_source_dir'  ], 10, 4 );
		add_action( 'upgrader_process_complete',             [ $this, 'purge_cache'     ], 10, 2 );
	}

	// ── Update transient injection ─────────────────────────────────────────────

	public function inject_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$latest = ltrim( $release['tag_name'], 'v' );

		if ( version_compare( $this->version, $latest, '<' ) ) {
			$transient->response[ $this->slug ] = (object) [
				'slug'        => dirname( $this->slug ),
				'plugin'      => $this->slug,
				'new_version' => $latest,
				'url'         => $release['html_url'],
				'package'     => $release['zipball_url'],
			];
		} else {
			// Let WP know the plugin is up to date.
			$transient->no_update[ $this->slug ] = (object) [
				'slug'        => dirname( $this->slug ),
				'plugin'      => $this->slug,
				'new_version' => $latest,
				'url'         => $release['html_url'],
				'package'     => '',
			];
		}

		return $transient;
	}

	// ── Plugin info modal ──────────────────────────────────────────────────────

	public function plugin_info( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->slug ) ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) [
			'name'          => 'LaunchLocal Showcase Sync',
			'slug'          => dirname( $this->slug ),
			'version'       => ltrim( $release['tag_name'], 'v' ),
			'author'        => '<a href="https://launchlocal.io">LaunchLocal</a>',
			'homepage'      => 'https://launchlocal.io',
			'download_link' => $release['zipball_url'],
			'requires'      => '6.0',
			'requires_php'  => '8.0',
			'sections'      => [
				'changelog' => nl2br( esc_html( $release['body'] ?? '' ) ),
			],
		];
	}

	// ── Folder rename after extraction ─────────────────────────────────────────
	// GitHub zipballs extract to "owner-repo-{hash}/" — rename to the correct
	// plugin directory so WordPress activates the right plugin.

	public function fix_source_dir( string $source, string $remote_source, $upgrader, array $hook_extra = [] ): string {
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->slug ) {
			return $source;
		}

		$correct = trailingslashit( dirname( $source ) ) . dirname( $this->slug ) . '/';

		if ( $source === $correct ) {
			return $source;
		}

		global $wp_filesystem;
		if ( $wp_filesystem->move( $source, $correct, true ) ) {
			return $correct;
		}

		return $source;
	}

	// ── Cache management ───────────────────────────────────────────────────────

	public function purge_cache( $upgrader, array $hook_extra ): void {
		if ( isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $this->slug ) {
			delete_transient( self::CACHE_KEY );
		}
	}

	// ── GitHub API ─────────────────────────────────────────────────────────────

	private function get_latest_release(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest',
			[
				'headers' => [ 'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) ],
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, [], self::CACHE_TTL );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			set_transient( self::CACHE_KEY, [], self::CACHE_TTL );
			return null;
		}

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}
}
