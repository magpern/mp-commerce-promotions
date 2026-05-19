<?php
/**
 * Audit, backup, and remove Commerce Growth test/demo data (production-safe).
 *
 * Usage:
 *   # Audit + backup only (default):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/production-data-reset.php
 *
 *   # Apply deletions (requires explicit flags):
 *   MP_CP_PRODUCTION_DATA_RESET=1 MP_CP_ALLOW_LIVE_QA=1 ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/production-data-reset.php -- --apply
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\Infrastructure\Database\MigrationRunner;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use MP\CommercePromotions\Qa\ProductionDataReset;
use MP\CommercePromotions\Qa\QaEmailSuppression;
use MP\CommercePromotions\Qa\QaRuntimeGuard;

QaEmailSuppression::enable();

$apply = QaRuntimeGuard::env_is_truthy( 'MP_CP_PRODUCTION_DATA_RESET_APPLY' )
	|| in_array( '--apply', $GLOBALS['argv'] ?? array(), true );

if ( $apply && ! QaRuntimeGuard::env_is_truthy( 'MP_CP_PRODUCTION_DATA_RESET' ) ) {
	WP_CLI::error(
		'Destructive cleanup blocked. Set MP_CP_PRODUCTION_DATA_RESET=1 and MP_CP_ALLOW_LIVE_QA=1, then pass --apply.'
	);
}

if ( $apply && ! QaRuntimeGuard::env_is_truthy( 'MP_CP_ALLOW_LIVE_QA' ) ) {
	WP_CLI::error( 'Set MP_CP_ALLOW_LIVE_QA=1 for destructive cleanup on production-like sites.' );
}

global $wpdb;
if ( ! $wpdb instanceof wpdb ) {
	WP_CLI::error( 'wpdb unavailable.' );
}

$uploads = wp_upload_dir();
$base    = isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] )
	? $uploads['basedir']
	: WP_CONTENT_DIR . '/uploads';
$backup_dir = $base . '/mp-cp-cleanup-backup-' . gmdate( 'Ymd-His' );

WP_CLI::log( 'Commerce Growth production data reset' );
WP_CLI::log( 'Mode: ' . ( $apply ? 'APPLY' : 'AUDIT (dry-run)' ) );
WP_CLI::log( 'Backup dir: ' . $backup_dir );
WP_CLI::log( 'Schema: ' . Schema::SCHEMA_VERSION . ' (stored: ' . get_option( MigrationRunner::OPTION_SCHEMA_VERSION, '' ) . ')' );

$reset  = new ProductionDataReset( $wpdb, $backup_dir, $apply );
$report = $reset->run();

WP_CLI::log( wp_json_encode( $report['audit'] ?? array(), JSON_PRETTY_PRINT ) );
WP_CLI::log( 'Planned: ' . wp_json_encode( $report['planned'] ?? array() ) );

if ( $apply ) {
	WP_CLI::log( 'Deleted: ' . wp_json_encode( $report['deleted'] ?? array() ) );
	WP_CLI::log( 'Preserved: ' . wp_json_encode( $report['preserved'] ?? array() ) );
	WP_CLI::success( 'Cleanup applied. Reports in ' . $backup_dir );
} else {
	WP_CLI::success( 'Audit complete (no deletions). Review ' . $backup_dir . '/cleanup-report.md then re-run with --apply.' );
}
