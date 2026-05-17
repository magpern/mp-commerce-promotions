<?php
/**
 * Destructive uninstall cleanup (only when administrator opts in).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Infrastructure;

use MP\CommercePromotions\Infrastructure\Database\Schema;
use wpdb;

final class UninstallDataCleaner {

	/**
	 * Drop custom tables and delete mp_cp_* options.
	 */
	public static function run( wpdb $wpdb ): void {
		$tables = array(
			Schema::simulation_scenarios_table( $wpdb ),
			Schema::planner_telemetry_table( $wpdb ),
			Schema::automation_runs_table( $wpdb ),
			Schema::promotion_snapshots_table( $wpdb ),
			Schema::code_batches_table( $wpdb ),
			Schema::promotion_codes_table( $wpdb ),
			Schema::audit_log_table( $wpdb ),
			Schema::redemptions_table( $wpdb ),
			Schema::promotions_table( $wpdb ),
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Schema helpers.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}

		$like = $wpdb->esc_like( 'mp_cp_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$options = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);

		if ( is_array( $options ) ) {
			foreach ( $options as $option_name ) {
				if ( is_string( $option_name ) && $option_name !== '' ) {
					delete_option( $option_name );
				}
			}
		}
	}
}
