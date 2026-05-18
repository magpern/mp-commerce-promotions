<?php
/**
 * Plugin activation: run additive schema migrations (dbDelta, no destructive DDL).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Infrastructure;

use MP\CommercePromotions\Infrastructure\Database\MigrationRunner;
use MP\CommercePromotions\Service\Settings;

final class Activator {

	/**
	 * Runs on activation: applies pending migrations only.
	 */
	public static function activate(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			exit;
		}

		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$runner = new MigrationRunner( $wpdb );
		$runner->run();

		$settings = new Settings();
		GiftCardPageInstaller::maybe_create_balance_page( $settings );

		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}
	}
}
