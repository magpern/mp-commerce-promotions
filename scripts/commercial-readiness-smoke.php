<?php
/**
 * WP-CLI smoke: commercial readiness settings, gates, support bundle, compatibility.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/commercial-readiness-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\Domain\AutomationRunRepository;
use MP\CommercePromotions\Domain\PlannerTelemetryRepository;
use MP\CommercePromotions\Domain\PromotionCodeBatchRepository;
use MP\CommercePromotions\Domain\PromotionCodeRepository;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Service\CompatibilityStatus;
use MP\CommercePromotions\Service\PromotionConflictAnalyzer;
use MP\CommercePromotions\Service\PromotionHealthMonitor;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Service\SupportBundleExporter;

$GLOBALS['commercial_smoke_failures'] = 0;

function commercial_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['commercial_smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

if ( ! class_exists( 'WP_CLI' ) ) {
	echo "WP-CLI required.\n";
	exit( 1 );
}

$plugin = new \MP\CommercePromotions\Plugin();
$plugin->init();

$schema = get_option( 'mp_cp_schema_version', '' );
commercial_smoke_assert( $schema === '1.14.0', 'schema version 1.14.0 (got ' . $schema . ')' );

$settings = new Settings();
commercial_smoke_assert( $settings->retain_data_on_uninstall(), 'retain data on uninstall default' );
commercial_smoke_assert( ! $settings->delete_data_on_uninstall(), 'delete on uninstall default off' );

$settings->set_planner_telemetry_enabled( false );
commercial_smoke_assert( ! $settings->planner_telemetry_enabled(), 'telemetry disable persists' );
$settings->set_planner_telemetry_enabled( true );

$settings->set_csv_export_enabled( false );
commercial_smoke_assert( ! $settings->csv_export_enabled(), 'csv export disable persists' );
$settings->set_csv_export_enabled( true );

$compat = ( new CompatibilityStatus() )->collect();
commercial_smoke_assert( isset( $compat['woocommerce_version'] ), 'compatibility status keys' );
commercial_smoke_assert( $compat['cart_checkout_blocks_declared'] === false, 'blocks not declared' );

global $wpdb;
$repo        = new PromotionRepository( $wpdb );
$redemptions = new RedemptionRepository( $wpdb );
$codes       = new PromotionCodeRepository( $wpdb );
$batches     = new PromotionCodeBatchRepository( $wpdb );
$runs        = new AutomationRunRepository( $wpdb );
$health      = new PromotionHealthMonitor( $repo, new PromotionConflictAnalyzer() );

$exporter = new SupportBundleExporter( $settings, $repo, $redemptions, $codes, $batches, $runs, $health );
$bundle   = $exporter->build();
commercial_smoke_assert( isset( $bundle['counts']['promotions'] ), 'support bundle counts' );
$json = wp_json_encode( $bundle );
commercial_smoke_assert( is_string( $json ) && strpos( $json, 'plain_code' ) === false, 'support bundle has no plain_code field' );

$settings->set_free_gift_enabled( false );
$flags = $settings->to_feature_flags();
commercial_smoke_assert( $flags['free_gift'] === false, 'free gift feature gate' );
$settings->set_free_gift_enabled( true );

if ( $GLOBALS['commercial_smoke_failures'] > 0 ) {
	WP_CLI::error( 'Commercial readiness smoke finished with ' . $GLOBALS['commercial_smoke_failures'] . ' failure(s).' );
}

WP_CLI::success( 'Commercial readiness smoke passed.' );
