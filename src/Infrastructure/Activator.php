<?php
/**
 * Plugin activation: rollback-safe no-op (no options, no DDL).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Infrastructure;

final class Activator {

	/**
	 * Runs on activation. Intentionally empty beyond load safety.
	 */
	public static function activate(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			exit;
		}
	}
}
