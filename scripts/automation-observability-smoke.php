<?php
/**
 * WP-CLI smoke: automation runner, health monitor, telemetry, recovery, snapshots, duplicate presets.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/automation-observability-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\Domain\AutomationRunRepository;
use MP\CommercePromotions\Domain\PlannerTelemetryRepository;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionSnapshotRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Engine\PromotionEvaluationPlan;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PlannerTelemetryRecorder;
use MP\CommercePromotions\Service\PromotionAutomationRunner;
use MP\CommercePromotions\Service\PromotionConflictAnalyzer;
use MP\CommercePromotions\Service\PromotionHealthMonitor;
use MP\CommercePromotions\Service\PromotionOperationalRecovery;
use MP\CommercePromotions\Service\PromotionReports;
use MP\CommercePromotions\Service\PromotionService;
use MP\CommercePromotions\Service\PromotionSnapshotService;

$GLOBALS['auto_obs_smoke_failures'] = 0;

function auto_obs_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['auto_obs_smoke_failures'];
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
auto_obs_smoke_assert( $schema === '1.12.0', 'schema version 1.12.0 (got ' . $schema . ')' );

$repo           = new PromotionRepository( $wpdb );
$redemptions    = new RedemptionRepository( $wpdb );
$telemetry_repo = new PlannerTelemetryRepository( $wpdb );
$runs_repo      = new AutomationRunRepository( $wpdb );
$snapshots      = new PromotionSnapshotRepository( $wpdb );
$audit          = new \MP\CommercePromotions\Domain\AuditLogRepository( $wpdb );
$audit_l        = new \MP\CommercePromotions\Service\AuditLogger( $audit );
$factory        = new \MP\CommercePromotions\Domain\PromotionFactory();
$service        = new PromotionService( $repo, $factory, $audit_l );
$runner         = new PromotionAutomationRunner( $service, $runs_repo );
$health         = new PromotionHealthMonitor( $repo, new PromotionConflictAnalyzer() );
$recovery       = new PromotionOperationalRecovery( $repo, $redemptions, $telemetry_repo, $snapshots, $audit_l );
$snapshot_svc   = new PromotionSnapshotService( $repo, $snapshots, $audit_l );
$reports        = new PromotionReports( $repo, $redemptions, $telemetry_repo, $runs_repo, $health );
$recorder       = new PlannerTelemetryRecorder( $telemetry_repo );

$before_runs = count( $runs_repo->find_latest( 5 ) );
$summary     = $runner->run_all();
auto_obs_smoke_assert(
	isset( $summary['started_at'], $summary['finished_at'], $summary['actions'] ),
	'automation runner returns structured summary'
);
$after_runs = $runs_repo->find_latest( 5 );
auto_obs_smoke_assert( count( $after_runs ) >= $before_runs, 'automation runner records run' );

$issues = $health->analyze( 50 );
auto_obs_smoke_assert( is_array( $issues ), 'health monitor returns issues array' );

$plan = new PromotionEvaluationPlan( array(), array( 'blocked_by_group_count' => 0 ) );
$recorder->record_plan( $plan );
$top = $telemetry_repo->top_by_column( 'selected_count', 1 );
auto_obs_smoke_assert( is_array( $top ), 'telemetry repository readable' );

$validation = $recovery->validate_promotion_snapshots( 20 );
auto_obs_smoke_assert(
	isset( $validation['valid'], $validation['invalid'] ),
	'snapshot validation returns valid/invalid lists'
);

$budget_dry = $recovery->recalculate_budget_spent_from_redemptions( true );
auto_obs_smoke_assert( isset( $budget_dry['dry_run'] ) && $budget_dry['dry_run'] === true, 'budget recalculation dry-run' );

$telemetry_dry = $recovery->rebuild_planner_telemetry_from_redemptions( true );
auto_obs_smoke_assert( $telemetry_dry['dry_run'] === true, 'telemetry rebuild dry-run' );

$draft = $service->create_draft( 'Auto obs smoke ' . gmdate( 'Y-m-d H:i:s' ) );
$pid   = (int) $draft->get_id();
if ( $pid > 0 ) {
	$copy = $service->duplicate_as_draft(
		$repo->find( $pid ) ?? $draft,
		0,
		array( 'scheduled_draft' => true, 'without_budget' => true )
	);
	auto_obs_smoke_assert( $copy->get_id() !== null && $copy->get_id() !== $pid, 'duplicate scheduled draft without budget' );

	$snapshot_svc->capture(
		$repo->find( $pid ) ?? $draft,
		\MP\CommercePromotions\Domain\PromotionSnapshot::TYPE_AUTOMATION,
		'automation smoke',
		0,
		'smoke label',
		'diagnostics'
	);
	$latest = $snapshot_svc->list_recent( $pid, 1 );
	auto_obs_smoke_assert( $latest !== array(), 'automation snapshot captured' );
}

$telemetry_summary = $reports->telemetry_summary( 5 );
auto_obs_smoke_assert(
	isset( $telemetry_summary['most_selected'], $telemetry_summary['most_blocked'] ),
	'reports telemetry summary sections'
);

$health_summary = $reports->health_summary( 50 );
auto_obs_smoke_assert( isset( $health_summary['total'] ), 'reports health summary' );

$history = $reports->latest_automation_runs( 5 );
auto_obs_smoke_assert( is_array( $history ), 'reports automation history' );

if ( $GLOBALS['auto_obs_smoke_failures'] > 0 ) {
	WP_CLI::error( 'Automation observability smoke finished with ' . $GLOBALS['auto_obs_smoke_failures'] . ' failure(s).' );
}

WP_CLI::success( 'Automation observability smoke passed.' );
