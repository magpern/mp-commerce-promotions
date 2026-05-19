<?php
/**
 * Bootstrap QA/smoke scripts with guards, email suppression, and cleanup.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Qa;

final class QaScriptBootstrap {

	private static ?QaScriptContext $context = null;

	public static function bootstrap( string $script_file, ?array $manifest_entry = null ): QaScriptContext {
		$script_name = basename( $script_file, '.php' );
		$capabilities = $manifest_entry['capabilities'] ?? array( QaRuntimeGuard::CAP_READONLY );

		$guard   = new QaRuntimeGuard( $script_name, $capabilities );
		$cleanup = new QaCleanupRegistry( $script_name, $guard->should_cleanup() );

		try {
			$guard->assert_may_run();
		} catch ( \RuntimeException $e ) {
			if ( class_exists( 'WP_CLI', false ) ) {
				\WP_CLI::error( $e->getMessage() );
			}
			throw $e;
		}

		if ( $guard->requires_email() && ! $guard->allows_email() ) {
			QaEmailSuppression::enable();
		}

		if ( $guard->should_cleanup() && $guard->requires_persistent() ) {
			self::register_shutdown_cleanup( $cleanup );
		}

		self::$context = new QaScriptContext( $guard, $cleanup );
		self::log_bootstrap( self::$context );

		return self::$context;
	}

	public static function context(): ?QaScriptContext {
		return self::$context;
	}

	private static function register_shutdown_cleanup( QaCleanupRegistry $cleanup ): void {
		register_shutdown_function(
			static function () use ( $cleanup ): void {
				$result = $cleanup->run_cleanup();
				if ( class_exists( 'WP_CLI', false ) ) {
					$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $result ) : json_encode( $result );
					\WP_CLI::log( 'QA cleanup: ' . ( is_string( $encoded ) ? $encoded : '{}' ) );
				}
			}
		);
	}

	private static function log_bootstrap( QaScriptContext $context ): void {
		if ( ! class_exists( 'WP_CLI', false ) ) {
			return;
		}

		$summary = $context->guard->summary();
		if ( $context->guard->requires_email() && ! $context->guard->allows_email() ) {
			$summary['email_mode'] = 'suppressed';
		}

		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $summary, JSON_PRETTY_PRINT ) : json_encode( $summary );
		\WP_CLI::log( 'QA runtime: ' . ( is_string( $encoded ) ? $encoded : '{}' ) );
	}

	private function __construct() {
	}
}

final class QaScriptContext {

	public QaRuntimeGuard $guard;

	public QaCleanupRegistry $cleanup;

	public function __construct( QaRuntimeGuard $guard, QaCleanupRegistry $cleanup ) {
		$this->guard   = $guard;
		$this->cleanup = $cleanup;
	}

	public function is_dry_run(): bool {
		return $this->guard->is_dry_run();
	}

	public function assert_may_write(): void {
		$this->guard->assert_may_write();
	}

	public function register_product( int $product_id ): void {
		$this->cleanup->register_product( $product_id );
	}

	public function register_order( int $order_id ): void {
		$this->cleanup->register_order( $order_id );
	}

	public function register_promotion( int $promotion_id ): void {
		$this->cleanup->register_promotion( $promotion_id );
	}

	public function register_gift_card( int $gift_card_id ): void {
		$this->cleanup->register_gift_card( $gift_card_id );
	}

	public function qa_note( string $detail = '' ): string {
		return QaDataTagger::qa_note( $this->cleanup->get_run_id(), $detail );
	}
}
