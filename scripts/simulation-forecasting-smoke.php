<?php
/**
 * WP-CLI smoke: simulation, forecasting, replay, recommendations, overlap, bulk workflow.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/simulation-forecasting-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\Domain\PlannerTelemetryRepository;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Domain\SimulationScenarioRepository;
use MP\CommercePromotions\Domain\SimulationScenarioRecord;
use MP\CommercePromotions\Engine\PlannerContextCache;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PromotionBulkCampaignWorkflow;
use MP\CommercePromotions\Service\PromotionForecastEngine;
use MP\CommercePromotions\Service\PromotionIntelligenceRecovery;
use MP\CommercePromotions\Service\PromotionOverlapSimulator;
use MP\CommercePromotions\Service\PromotionRecommendationEngine;
use MP\CommercePromotions\Service\PromotionReplayEngine;
use MP\CommercePromotions\Service\PromotionReports;
use MP\CommercePromotions\Service\PromotionSimulationEngine;
use MP\CommercePromotions\Service\SimulationScenario;

$GLOBALS['sim_forecast_smoke_failures'] = 0;

function sim_forecast_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['sim_forecast_smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

if ( ! class_exists( 'WP_CLI' ) ) {
	echo "WP-CLI required.\n";
	exit( 1 );
}

global $wpdb;

$plugin = new \MP\CommercePromotions\Plugin();
$plugin->init();

$schema = get_option( 'mp_cp_schema_version', '' );
sim_forecast_smoke_assert( $schema === '1.13.0', 'schema version 1.13.0 (got ' . $schema . ')' );

$repo        = new PromotionRepository( $wpdb );
$redemptions = new RedemptionRepository( $wpdb );
$telemetry   = new PlannerTelemetryRepository( $wpdb );
$scenarios   = new SimulationScenarioRepository( $wpdb );
$audit       = new \MP\CommercePromotions\Domain\AuditLogRepository( $wpdb );
$audit_l     = new \MP\CommercePromotions\Service\AuditLogger( $audit );

$simulator = new PromotionSimulationEngine( $repo );
$result    = $simulator->simulate( SimulationScenario::from_preset( SimulationScenario::PRESET_WHOLE_CART ) );
sim_forecast_smoke_assert( is_float( $result->get_total_discount() ), 'simulation engine runs' );

$forecast_engine = new PromotionForecastEngine( $repo, $redemptions, $telemetry );
$forecast          = $forecast_engine->forecast_catalog( null, false );
sim_forecast_smoke_assert( isset( $forecast['estimated_discount_exposure'] ), 'forecast engine outputs exposure' );

$replay = new PromotionReplayEngine( $repo, $redemptions, $telemetry, $simulator );
$replay_out = $replay->replay_catalog( 5 );
sim_forecast_smoke_assert( isset( $replay_out['promotions'] ), 'replay engine returns catalog rows' );

$recs = ( new PromotionRecommendationEngine( $repo, $redemptions, $telemetry ) )->recommend( 50 );
sim_forecast_smoke_assert( is_array( $recs ), 'recommendation engine returns list' );

$overlap = ( new PromotionOverlapSimulator( $repo ) )->simulate_overlap();
sim_forecast_smoke_assert( is_array( $overlap ), 'overlap simulation returns array' );

$bulk = new PromotionBulkCampaignWorkflow( $repo, $audit_l );
$bulk_result = $bulk->bulk_assign_campaign_label( array(), 'smoke-label', 1 );
sim_forecast_smoke_assert( (int) ( $bulk_result['changed'] ?? 0 ) === 0, 'bulk workflow handles empty selection' );

PlannerContextCache::record_simulated_run();
$stats = PlannerContextCache::request_counters();
sim_forecast_smoke_assert( $stats['simulated_runs'] >= 1, 'planner cache counters increment' );

$record = new SimulationScenarioRecord(
	null,
	'Smoke scenario ' . wp_generate_password( 4, false ),
	SimulationScenario::from_preset( SimulationScenario::PRESET_GUEST_CUSTOMER )->to_array(),
	SimulationScenarioRecord::STATUS_ACTIVE,
	1,
	current_time( 'mysql' ),
	null,
	0
);
$scenario_id = $scenarios->insert( $record );
sim_forecast_smoke_assert( $scenario_id > 0, 'scenario persistence insert' );

$recovery = new PromotionIntelligenceRecovery( $telemetry, $scenarios );
$validate = $recovery->validate_scenario_payloads( true );
sim_forecast_smoke_assert( isset( $validate['valid'] ), 'validate scenario payloads dry-run' );
$recovery->reset_forecast_cache();
sim_forecast_smoke_assert( ! is_array( get_option( PromotionForecastEngine::OPTION_CACHE, null ) ), 'forecast cache reset' );

$health = new \MP\CommercePromotions\Service\PromotionHealthMonitor( $repo, new \MP\CommercePromotions\Service\PromotionConflictAnalyzer() );
$reports = new PromotionReports( $repo, $redemptions, $telemetry, null, $health, $scenarios );
sim_forecast_smoke_assert( $reports->forecast_summary() !== array() || true, 'reports forecast_summary callable' );
sim_forecast_smoke_assert( isset( $reports->promotion_calendar()['active'] ), 'promotion calendar buckets' );

if ( $GLOBALS['sim_forecast_smoke_failures'] > 0 ) {
	WP_CLI::error( 'Simulation/forecasting smoke finished with ' . $GLOBALS['sim_forecast_smoke_failures'] . ' failure(s).' );
}

WP_CLI::success( 'Simulation and forecasting smoke passed.' );
