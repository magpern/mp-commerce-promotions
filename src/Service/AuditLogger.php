<?php
/**
 * Higher-level append-only audit API (never fatal on logging failure).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\AuditLogEntry;
use MP\CommercePromotions\Domain\AuditLogRepository;
use Throwable;

final class AuditLogger {

	private AuditLogRepository $repository;

	public function __construct( AuditLogRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * @param array<mixed> $context
	 */
	public function log(
		string $action,
		?int $promotion_id = null,
		array $context = array(),
		?int $actor_user_id = null
	): int {
		try {
			$actor = $actor_user_id;
			if ( $actor === null && function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
				$actor = (int) get_current_user_id();
				if ( $actor <= 0 ) {
					$actor = null;
				}
			}

			$ip_hash = $this->hash_remote_addr();

			$entry = new AuditLogEntry(
				null,
				$promotion_id,
				$actor,
				$action,
				$context,
				$ip_hash,
				null
			);

			return $this->repository->insert( $entry );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'[mp-commerce-promotions] Audit log failed: %s',
						$e->getMessage()
					)
				);
			}
			return 0;
		}
	}

	private function hash_remote_addr(): ?string {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return null;
		}

		$raw = wp_unslash( $_SERVER['REMOTE_ADDR'] );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return null;
		}

		$ip = trim( explode( ',', $raw, 2 )[0] );
		if ( $ip === '' ) {
			return null;
		}

		if ( function_exists( 'filter_var' ) ) {
			$valid = filter_var( $ip, FILTER_VALIDATE_IP );
			if ( false === $valid ) {
				return null;
			}
			$ip = (string) $valid;
		}

		return hash( 'sha256', $ip );
	}
}
