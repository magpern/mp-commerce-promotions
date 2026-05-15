<?php
/**
 * Plugin activation: run additive schema migrations (dbDelta, no destructive DDL).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Infrastructure;

use MP\CommercePromotions\Infrastructure\Database\MigrationRunner;
use wpdb;

final class Activator {

	/**
	 * Runs on activation: applies pending migrations only.
	 */
	public static function activate(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			exit;
		}

		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return;
		}

		$runner = new MigrationRunner( $wpdb );
		$runner->run();
	}
}
