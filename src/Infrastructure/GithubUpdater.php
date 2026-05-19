<?php
/**
 * GitHub Releases updater — production ZIP assets only.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Offers WordPress plugin updates from GitHub Release ZIP assets (not source archives).
 */
final class GithubUpdater {

	public const API_LATEST = 'https://api.github.com/repos/magpern/mp-commerce-promotions/releases/latest';

	private const TRANSIENT_RELEASE = 'mp_cp_github_release_latest';

	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	private const PLUGIN_SLUG = 'mp-commerce-promotions';

	private static ?self $instance = null;

	private string $plugin_basename;

	private string $installed_version;

	/**
	 * Register hooks when enabled (admin / cron only — see bootstrap).
	 */
	public static function maybe_init(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		self::$instance->register_hooks();
	}

	/**
	 * Whether the GitHub updater should run on this site.
	 */
	public static function is_enabled(): bool {
		if ( defined( 'MP_CP_DISABLE_GITHUB_UPDATER' ) && MP_CP_DISABLE_GITHUB_UPDATER ) {
			return false;
		}

		/**
		 * Override GitHub updater enablement.
		 *
		 * Return true/false to force. Return null (default) to use environment type:
		 * enabled on `production`, disabled on development/staging/local.
		 *
		 * @param bool|null $enabled Whether the updater is enabled.
		 */
		$filtered = apply_filters( 'mp_cp_github_updater_enabled', null );
		if ( null !== $filtered ) {
			return (bool) $filtered;
		}

		$env = function_exists( 'wp_get_environment_type' )
			? wp_get_environment_type()
			: 'production';

		return 'production' === $env;
	}

	/**
	 * @param string $version Installed plugin version string.
	 */
	public static function is_prerelease_install_version( string $version ): bool {
		return (bool) preg_match( '/-(dev|snapshot|pilot|alpha|beta|rc)(\.|$|-)/i', $version );
	}

	/**
	 * Strip prerelease suffix for comparison (e.g. 0.3.0-pilot.4 → 0.3.0).
	 *
	 * @param string $version Installed version string.
	 */
	public static function base_version( string $version ): string {
		if ( self::is_prerelease_install_version( $version ) ) {
			return (string) preg_replace( '/-(dev|snapshot|pilot|alpha|beta|rc).*$/i', '', $version );
		}
		return $version;
	}

	/**
	 * Whether to inject an update into the plugins transient.
	 *
	 * @param string $installed Installed plugin version.
	 * @param string $remote    Latest stable release version from GitHub.
	 */
	public static function should_offer_update( string $installed, string $remote ): bool {
		if ( '' === $remote || ! preg_match( '/^\d+\.\d+\.\d+/', $remote ) ) {
			return false;
		}

		if ( self::is_prerelease_install_version( $installed ) ) {
			$offer = version_compare( $remote, self::base_version( $installed ), '>' );
		} else {
			$offer = version_compare( $remote, $installed, '>' );
		}

		/**
		 * Filter whether a GitHub release update is offered.
		 *
		 * @param bool   $offer     Default decision.
		 * @param string $installed Installed version.
		 * @param string $remote    Latest stable release version.
		 */
		return (bool) apply_filters( 'mp_cp_github_updater_should_offer_update', $offer, $installed, $remote );
	}

	private function __construct() {
		$this->plugin_basename   = plugin_basename( MP_COMMERCE_PROMOTIONS_FILE );
		$this->installed_version = defined( 'MP_COMMERCE_PROMOTIONS_VERSION' )
			? (string) MP_COMMERCE_PROMOTIONS_VERSION
			: '0.0.0';
	}

	private function register_hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filter_update_plugins' ) );
		add_filter( 'plugins_api', array( $this, 'filter_plugins_api' ), 20, 3 );
	}

	/**
	 * @param object|false $transient Update plugins transient.
	 * @return object|false
	 */
	public function filter_update_plugins( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		if ( ! isset( $transient->checked[ $this->plugin_basename ] ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( '' === $release['version'] || '' === $release['package'] ) {
			return $transient;
		}

		if ( ! self::should_offer_update( $this->installed_version, $release['version'] ) ) {
			return $transient;
		}

		$response               = new \stdClass();
		$response->slug         = self::PLUGIN_SLUG;
		$response->plugin       = $this->plugin_basename;
		$response->new_version  = $release['version'];
		$response->url          = $release['url'];
		$response->package      = $release['package'];
		$response->icons        = array();
		$response->banners      = array();
		$response->banners_rtl  = array();
		$response->tested       = '';
		$response->requires_php = '8.1';
		$response->requires     = '6.5';

		$transient->response[ $this->plugin_basename ] = $response;

		return $transient;
	}

	/**
	 * @param false|object|array $result Plugin API result.
	 * @param string             $action API action.
	 * @param object             $args   Query args.
	 * @return false|object|array
	 */
	public function filter_plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $args->slug !== self::PLUGIN_SLUG ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( '' === $release['version'] ) {
			return $result;
		}

		$info                  = new \stdClass();
		$info->name            = 'Commerce Promotions for WooCommerce';
		$info->slug            = self::PLUGIN_SLUG;
		$info->version         = $release['version'];
		$info->author          = '<a href="https://github.com/magpern">magpern</a>';
		$info->homepage        = $release['url'];
		$info->download_link   = $release['package'];
		$info->requires        = '6.5';
		$info->requires_php    = '8.1';
		$info->sections        = array(
			'description' => __( 'Rule-driven promotion engine for WooCommerce.', 'mp-commerce-promotions' ),
			'changelog'   => '' !== $release['notes'] ? wp_kses_post( $release['notes'] ) : '',
		);

		return $info;
	}

	/**
	 * @return array{version:string,package:string,url:string,notes:string}
	 */
	private function get_latest_release(): array {
		$cached = get_site_transient( self::TRANSIENT_RELEASE );
		if ( is_array( $cached ) && isset( $cached['version'] ) ) {
			return $cached;
		}

		$parsed = $this->fetch_latest_release();
		set_site_transient( self::TRANSIENT_RELEASE, $parsed, self::CACHE_TTL );

		return $parsed;
	}

	/**
	 * @return array{version:string,package:string,url:string,notes:string}
	 */
	private function fetch_latest_release(): array {
		$empty = array(
			'version' => '',
			'package' => '',
			'url'     => '',
			'notes'   => '',
		);

		$response = wp_remote_get(
			self::API_LATEST,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'mp-commerce-promotions-updater/' . $this->installed_version,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $empty;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return $empty;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return $empty;
		}

		if ( ! empty( $data['prerelease'] ) || ! empty( $data['draft'] ) ) {
			return $empty;
		}

		$version = $this->version_from_tag( isset( $data['tag_name'] ) ? (string) $data['tag_name'] : '' );
		if ( '' === $version || ! preg_match( '/^\d+\.\d+\.\d+/', $version ) ) {
			return $empty;
		}

		$package = $this->find_release_zip_url( $data, $version );
		if ( '' === $package ) {
			return $empty;
		}

		return array(
			'version' => $version,
			'package' => $package,
			'url'     => isset( $data['html_url'] ) ? (string) $data['html_url'] : '',
			'notes'   => isset( $data['body'] ) ? (string) $data['body'] : '',
		);
	}

	private function version_from_tag( string $tag_name ): string {
		$tag_name = ltrim( $tag_name, 'vV' );
		if ( preg_match( '/^(\d+\.\d+\.\d+)/', $tag_name, $matches ) ) {
			return $matches[1];
		}
		return '';
	}

	/**
	 * @param array<string,mixed> $data    GitHub release JSON.
	 * @param string              $version Parsed stable version.
	 */
	private function find_release_zip_url( array $data, string $version ): string {
		if ( empty( $data['assets'] ) || ! is_array( $data['assets'] ) ) {
			return '';
		}

		$expected = self::PLUGIN_SLUG . '-' . $version . '.zip';

		foreach ( $data['assets'] as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
			if ( $name !== $expected ) {
				continue;
			}
			$url = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
			if ( '' !== $url && false !== strpos( $url, 'github.com' ) ) {
				return $url;
			}
		}

		return '';
	}
}
