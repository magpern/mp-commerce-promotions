<?php
/**
 * WP-CLI smoke: orchestration groups, cooldown, segmentation, snapshots, reports, automation.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/orchestration-segmentation-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionSnapshotRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Engine\PromotionEvaluationDecision;
use MP\CommercePromotions\Engine\PromotionPlanExplainer;
use MP\CommercePromotions\Engine\PromotionPlanner;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PromotionConflictAnalyzer;
use MP\CommercePromotions\Service\PromotionReports;
use MP\CommercePromotions\Service\PromotionService;
use MP\CommercePromotions\Service\PromotionSnapshotService;
use MP\CommercePromotions\Service\PromotionTemplate;

$GLOBALS['orch_smoke_failures'] = 0;

function orch_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['orch_smoke_failures'];
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
orch_smoke_assert( $schema === '1.11.0', 'schema version 1.11.0 (got ' . $schema . ')' );

$repo        = new PromotionRepository( $wpdb );
$redemptions = new RedemptionRepository( $wpdb );
$snapshots   = new PromotionSnapshotRepository( $wpdb );
$audit       = new \MP\CommercePromotions\Domain\AuditLogRepository( $wpdb );
$audit_l     = new \MP\CommercePromotions\Service\AuditLogger( $audit );
$factory     = new \MP\CommercePromotions\Domain\PromotionFactory();
$service     = new PromotionService( $repo, $factory, $audit_l );
$reports     = new PromotionReports( $repo, $redemptions );
$snapshot_svc = new PromotionSnapshotService( $repo, $snapshots, $audit_l );

$created_ids = array();

try {
	$draft = $service->create_draft( 'Orch smoke ' . gmdate( 'Y-m-d H:i:s' ) );
	$pid   = (int) $draft->get_id();
	$created_ids[] = $pid;

	$orch = $draft
		->with_orchestration( 24, 'smoke-lane' )
		->with_rules(
			array( array( 'type' => RuleTypes::CONDITION_LOGGED_IN ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 5 ) ),
			array()
		);
	$service->update_promotion( $orch );
	$service->change_status( $orch, PromotionStatus::ACTIVE );

	$reload = $repo->find( $pid );
	orch_smoke_assert(
		$reload !== null && $reload->get_orchestration_group() === 'smoke-lane' && $reload->get_cooldown_hours() === 24,
		'orchestration fields persisted'
	);

	$peer = $service->create_draft( 'Orch smoke peer ' . gmdate( 'H:i:s' ) );
	$peer_id = (int) $peer->get_id();
	$created_ids[] = $peer_id;
	$peer = $peer
		->with_orchestration( null, 'smoke-lane' )
		->with_rules(
			array( array( 'type' => RuleTypes::CONDITION_LOGGED_IN ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 3 ) ),
			array()
		);
	$service->update_promotion( $peer );
	$service->change_status( $peer, PromotionStatus::ACTIVE );

	$active = $repo->find_active( 50 );
	$conflicts = ( new PromotionConflictAnalyzer() )->analyze( $active );
	$types = array_column( $conflicts, 'type' );
	orch_smoke_assert(
		in_array( PromotionConflictAnalyzer::TYPE_ORCHESTRATION_CONGESTION, $types, true ),
		'orchestration congestion detected among active promotions'
	);

	$context = new \MP\CommercePromotions\Engine\EvaluationContext(
		99,
		100.0,
		'USD',
		array(),
		array(
			'lifetime_spend'       => 1000.0,
			'order_count'          => 5.0,
			'average_order_value'  => 200.0,
		)
	);
	$plan = ( new PromotionPlanner() )->plan( array( $reload, $repo->find( $peer_id ) ), $context );
	$explain = PromotionPlanExplainer::explain( $plan );
	orch_smoke_assert( isset( $explain['plan_metrics']['blocked_by_group_count'] ), 'plan metrics in explainer' );

	$vip = PromotionTemplate::build(
		PromotionTemplate::TEMPLATE_VIP_CUSTOMER,
		array(
			'lifetime_spend_threshold' => 500,
			'discount_type'            => 'percentage',
			'percentage'               => 10,
		)
	);
	orch_smoke_assert(
		count( $vip['conditions'] ) === 2 && $vip['conditions'][0]['type'] === RuleTypes::CONDITION_LOGGED_IN,
		'VIP template includes logged_in and lifetime spend'
	);

	$snap_id = $snapshot_svc->capture( $reload, \MP\CommercePromotions\Domain\PromotionSnapshot::TYPE_AUTOMATION, 'smoke' );
	orch_smoke_assert( $snap_id > 0, 'snapshot captured' );

	$summary = $reports->summary();
	orch_smoke_assert(
		array_key_exists( 'cooldown_active_promotions', $summary )
		&& array_key_exists( 'top_orchestration_groups', $summary ),
		'reports orchestration summary keys'
	);

	$csv = $reports->redemptions_csv( array() );
	orch_smoke_assert(
		strpos( $csv, 'orchestration_group' ) !== false
		&& strpos( $csv, 'cooldown_hours' ) !== false
		&& strpos( $csv, 'budget_utilization_percent' ) !== false,
		'CSV export orchestration columns'
	);

	$activate = $service->activate_scheduled_promotions( 0 );
	orch_smoke_assert( is_array( $activate['changed'] ), 'activate_scheduled_promotions returns batch' );

	$archive = $service->archive_expired_paused_promotions( 0 );
	orch_smoke_assert( is_array( $archive['changed'] ), 'archive_expired_paused_promotions returns batch' );

	$normalize = $service->normalize_invalid_promotion_states( 0 );
	orch_smoke_assert( isset( $normalize['warnings'] ), 'normalize_invalid_promotion_states returns warnings' );
} finally {
	foreach ( $created_ids as $id ) {
		$p = $repo->find( $id );
		if ( $p instanceof Promotion && $p->get_status() !== PromotionStatus::ARCHIVED ) {
			try {
				$service->change_status( $p, PromotionStatus::ARCHIVED, 0 );
			} catch ( \Throwable $e ) {
				// ignore cleanup errors
			}
		}
	}
}

if ( $GLOBALS['orch_smoke_failures'] > 0 ) {
	WP_CLI::error( 'Orchestration/segmentation smoke finished with ' . $GLOBALS['orch_smoke_failures'] . ' failure(s).' );
}

WP_CLI::success( 'Orchestration/segmentation smoke passed.' );
