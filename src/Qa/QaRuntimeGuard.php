<?php
/**
 * Environment and env-var guardrails for WP-CLI QA/smoke scripts.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Qa;

final class QaRuntimeGuard {

	public const ENV_ALLOW_LIVE_QA = 'MP_CP_ALLOW_LIVE_QA';

	public const ENV_ALLOW_QA_EMAILS = 'MP_CP_ALLOW_QA_EMAILS';

	public const ENV_QA_DRY_RUN = 'MP_CP_QA_DRY_RUN';

	public const ENV_QA_CLEANUP = 'MP_CP_QA_CLEANUP';

	public const ENV_ALLOW_PERSISTENT_SETUP = 'MP_CP_ALLOW_PERSISTENT_QA_SETUP';

	public const ENV_QA_APPLY = 'MP_CP_QA_APPLY';

	public const ENV_PRODUCTION_DATA_RESET = 'MP_CP_PRODUCTION_DATA_RESET';

	public const CAP_READONLY = 'readonly';

	public const CAP_PERSISTENT = 'persistent';

	public const CAP_EMAIL = 'email';

	public const CAP_SETUP = 'setup';

	public const CAP_HARNESS = 'harness';

	private string $script_name;

	/** @var list<string> */
	private array $capabilities;

	private string $environment;

	private bool $is_production_like;

	private bool $allow_live_qa;

	private bool $allow_qa_emails;

	private bool $allow_persistent_setup;

	private bool $dry_run;

	private bool $cleanup_enabled;

	private string $site_url;

	public function __construct( string $script_name, array $capabilities = array( self::CAP_READONLY ) ) {
		$this->script_name   = $script_name;
		$this->capabilities  = $capabilities;
		$this->site_url      = self::resolve_site_url();
		$this->environment   = self::detect_environment_type( $this->site_url );
		$this->is_production_like = self::is_production_like_environment( $this->environment, $this->site_url );
		$this->allow_live_qa = self::env_is_truthy( self::ENV_ALLOW_LIVE_QA );
		$this->allow_qa_emails = self::env_is_truthy( self::ENV_ALLOW_QA_EMAILS );
		$this->allow_persistent_setup = self::env_is_truthy( self::ENV_ALLOW_PERSISTENT_SETUP );
		$this->dry_run                = self::resolve_dry_run_default();
		$this->cleanup_enabled        = ! self::env_is_set( self::ENV_QA_CLEANUP ) || self::env_is_truthy( self::ENV_QA_CLEANUP );
	}

	public function get_script_name(): string {
		return $this->script_name;
	}

	public function get_environment(): string {
		return $this->environment;
	}

	public function is_production_like(): bool {
		return $this->is_production_like;
	}

	public function is_dry_run(): bool {
		return $this->dry_run;
	}

	public function should_cleanup(): bool {
		return $this->cleanup_enabled;
	}

	public function allows_email(): bool {
		return $this->allow_qa_emails;
	}

	public function requires_email(): bool {
		return in_array( self::CAP_EMAIL, $this->capabilities, true );
	}

	public function requires_persistent(): bool {
		return in_array( self::CAP_PERSISTENT, $this->capabilities, true )
			|| in_array( self::CAP_SETUP, $this->capabilities, true )
			|| in_array( self::CAP_HARNESS, $this->capabilities, true );
	}

	public function requires_setup(): bool {
		return in_array( self::CAP_SETUP, $this->capabilities, true );
	}

	public function is_readonly_script(): bool {
		if ( $this->requires_persistent() || $this->requires_email() ) {
			return false;
		}

		return in_array( self::CAP_READONLY, $this->capabilities, true );
	}

	/**
	 * @throws \RuntimeException When the script must not run.
	 */
	public function assert_may_run(): void {
		if ( $this->is_readonly_script() ) {
			return;
		}

		if ( $this->requires_setup() && $this->is_production_like && ! $this->allow_persistent_setup && ! $this->allow_live_qa ) {
			throw new \RuntimeException(
				'Persistent QA setup is blocked on production. Set MP_CP_ALLOW_PERSISTENT_QA_SETUP=1 or MP_CP_ALLOW_LIVE_QA=1 to proceed.'
			);
		}

		if ( $this->requires_persistent() && $this->is_production_like && ! $this->allow_live_qa ) {
			throw new \RuntimeException(
				'QA script writes are blocked on production. Set MP_CP_ALLOW_LIVE_QA=1 to proceed, or run on local/staging.'
			);
		}
	}

	public function assert_may_write(): void {
		$this->assert_may_run();
		if ( $this->dry_run ) {
			throw new \RuntimeException( 'QA_DRY_RUN: write skipped.' );
		}
	}

	public function summary(): array {
		return array(
			'script'                    => $this->script_name,
			'capabilities'              => $this->capabilities,
			'environment'               => $this->environment,
			'is_production_like'        => $this->is_production_like,
			'site_url'                  => $this->site_url,
			'allow_live_qa'             => $this->allow_live_qa,
			'allow_qa_emails'           => $this->allow_qa_emails,
			'allow_persistent_setup'    => $this->allow_persistent_setup,
			'dry_run'                   => $this->dry_run,
			'cleanup_enabled'           => $this->cleanup_enabled,
		);
	}

	public static function detect_environment_type( string $site_url = '' ): string {
		if ( defined( 'MP_CP_QA_ALLOW_PRODUCTION' ) && MP_CP_QA_ALLOW_PRODUCTION ) {
			return 'production';
		}

		if ( function_exists( 'wp_get_environment_type' ) ) {
			$type = wp_get_environment_type();
			if ( is_string( $type ) && $type !== '' ) {
				return strtolower( $type );
			}
		}

		if ( defined( 'WP_ENVIRONMENT_TYPE' ) && is_string( WP_ENVIRONMENT_TYPE ) && WP_ENVIRONMENT_TYPE !== '' ) {
			return strtolower( WP_ENVIRONMENT_TYPE );
		}

		if ( self::site_url_looks_non_production( $site_url ) ) {
			return 'local';
		}

		return 'production';
	}

	public static function is_production_like_environment( string $environment, string $site_url = '' ): bool {
		$environment = strtolower( $environment );
		if ( in_array( $environment, array( 'local', 'development', 'staging' ), true ) ) {
			return false;
		}

		if ( self::site_url_looks_non_production( $site_url ) ) {
			return false;
		}

		return true;
	}

	public static function site_url_looks_non_production( string $site_url ): bool {
		$url = strtolower( $site_url );
		if ( $url === '' ) {
			return false;
		}

		$needles = array(
			'localhost',
			'127.0.0.1',
			'::1',
			'.test',
			'.local',
			'.invalid',
			'staging.',
			'-staging',
			'.staging',
			'dev.',
			'-dev.',
		);

		foreach ( $needles as $needle ) {
			if ( str_contains( $url, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	public static function env_is_truthy( string $name ): bool {
		$value = self::env_value( $name );
		if ( $value === null ) {
			return false;
		}

		return in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
	}

	public static function env_is_set( string $name ): bool {
		return self::env_value( $name ) !== null;
	}

	public static function env_value( string $name ): ?string {
		$getenv = getenv( $name );
		if ( is_string( $getenv ) && $getenv !== '' ) {
			return $getenv;
		}

		if ( isset( $_ENV[ $name ] ) && is_string( $_ENV[ $name ] ) && $_ENV[ $name ] !== '' ) {
			return $_ENV[ $name ];
		}

		return null;
	}

	private function resolve_dry_run_default(): bool {
		if ( self::env_is_truthy( self::ENV_QA_DRY_RUN ) ) {
			return true;
		}
		if ( self::env_is_set( self::ENV_QA_DRY_RUN ) && ! self::env_is_truthy( self::ENV_QA_DRY_RUN ) ) {
			return false;
		}
		if ( $this->requires_persistent() && $this->is_production_like && $this->allow_live_qa && ! self::env_is_truthy( self::ENV_QA_APPLY ) ) {
			return true;
		}

		return false;
	}

	private static function resolve_site_url(): string {
		if ( function_exists( 'home_url' ) ) {
			$url = home_url( '/' );
			if ( is_string( $url ) ) {
				return $url;
			}
		}

		return '';
	}
}
