<?php
/**
 * WP-CLI smoke: promotion budgets, lifecycle filters, reports presets, schedule analyzer.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/economics-scheduling-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionCodeBatch;
use MP\CommercePromotions\Domain\PromotionCodeBatchRepository;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PromotionLifecycle;
use MP\CommercePromotions\Service\PromotionReports;
use MP\CommercePromotions\Service\PromotionScheduleAnalyzer;
use MP\CommercePromotions\Service\PromotionService;

$GLOBALS['smoke_failures'] = 0;

function economics_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['smoke_failures'];
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
economics_smoke_assert( $schema === '1.10.0', 'schema version 1.10.0 (got ' . $schema . ')' );

$repo       = new PromotionRepository( $wpdb );
$redemptions = new RedemptionRepository( $wpdb );
$batches    = new PromotionCodeBatchRepository( $wpdb );
$audit      = new \MP\CommercePromotions\Domain\AuditLogRepository( $wpdb );
$audit_l    = new \MP\CommercePromotions\Service\AuditLogger( $audit );
$factory    = new \MP\CommercePromotions\Domain\PromotionFactory();
$service    = new PromotionService( $repo, $factory, $audit_l );
$reports    = new PromotionReports( $repo, $redemptions );

$created_ids = array();

try {
	$draft = $service->create_draft( 'Smoke Budget ' . gmdate( 'Y-m-d H:i:s' ) );
	$pid   = (int) $draft->get_id();
	$created_ids[] = $pid;

	$budgeted = $draft
		->with_budget( 50.0, 0.0, 'USD' )
		->with_rules(
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 5 ) ),
			array()
		);
	$service->update_promotion( $budgeted );
	$service->change_status( $budgeted, PromotionStatus::ACTIVE );

	$reload = $repo->find( $pid );
	economics_smoke_assert( $reload !== null && $reload->has_budget_cap(), 'budget cap persisted' );

	$repo->adjust_budget_spent( $pid, 50.0 );
	$spent_reload = $repo->find( $pid );
	economics_smoke_assert(
		$spent_reload !== null && $spent_reload->is_budget_exhausted(),
		'adjust_budget_spent marks exhausted'
	);

	economics_smoke_assert(
		PromotionLifecycle::primary_phase( $spent_reload ) === PromotionLifecycle::PHASE_BUDGET_EXHAUSTED,
		'lifecycle phase budget_exhausted'
	);

	$exhausted_list = $repo->find_filtered(
		array(
			'lifecycle_phase' => PromotionLifecycle::PHASE_BUDGET_EXHAUSTED,
			'limit'           => 20,
		)
	);
	$found_exhausted = false;
	foreach ( $exhausted_list as $p ) {
		if ( (int) $p->get_id() === $pid ) {
			$found_exhausted = true;
			break;
		}
	}
	economics_smoke_assert( $found_exhausted, 'find_filtered lifecycle budget_exhausted' );

	$batch = new PromotionCodeBatch(
		null,
		$pid,
		wp_generate_uuid4(),
		'Smoke batch',
		2,
		'SMK',
		1,
		null,
		'Smoke batch note',
		null,
		null,
		0,
		(int) get_current_user_id(),
		null
	);
	$batch_id = $batches->insert( $batch );
	economics_smoke_assert( $batch_id > 0, 'batch insert with notes/export defaults' );

	economics_smoke_assert(
		$batches->record_export( $batch_id, 2, (int) get_current_user_id() ),
		'record_export increments'
	);
	$batch_reload = $batches->find( $batch_id );
	economics_smoke_assert(
		$batch_reload !== null && $batch_reload->get_export_count() === 1,
		'export_count incremented'
	);

	$preset = PromotionReports::resolve_date_preset( PromotionReports::DATE_PRESET_7D );
	economics_smoke_assert(
		isset( $preset['date_from'], $preset['date_to'] ) && $preset['date_from'] <= $preset['date_to'],
		'date preset 7d resolves'
	);

	$summary = $reports->summary(
		array(
			'date_preset'      => PromotionReports::DATE_PRESET_30D,
			'budget_exhausted' => 'yes',
		)
	);
	economics_smoke_assert(
		isset( $summary['total_budget_spent'], $summary['exhausted_promotions'] ),
		'reports summary economics keys'
	);

	$analyzer = new PromotionScheduleAnalyzer();
	$peer     = $service->create_draft( 'Smoke Peer ' . gmdate( 'H:i:s' ) );
	$peer_id  = (int) $peer->get_id();
	$created_ids[] = $peer_id;
	$peer_active = $peer
		->with_rules(
			array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
			array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 3 ) ),
			array()
		);
	$service->update_promotion( $peer_active );
	$service->change_status( $peer_active, PromotionStatus::ACTIVE );

	$subject = $repo->find( $pid );
	$issues  = $analyzer->analyze( array( $subject, $repo->find( $peer_id ) ), $subject );
	economics_smoke_assert( count( $issues ) > 0, 'schedule analyzer overlap rows' );

	$pause_result = $service->pause_budget_exhausted_promotions( (int) get_current_user_id() );
	economics_smoke_assert( count( $pause_result['changed'] ) >= 1, 'pause_budget_exhausted_promotions' );

} finally {
	foreach ( $created_ids as $id ) {
		$p = $repo->find( $id );
		if ( $p === null ) {
			continue;
		}
		if ( $p->get_status() !== PromotionStatus::ARCHIVED ) {
			try {
				$service->change_status( $p, PromotionStatus::ARCHIVED );
			} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}
	}
}

if ( (int) $GLOBALS['smoke_failures'] > 0 ) {
	WP_CLI::error( 'Economics scheduling smoke finished with ' . (int) $GLOBALS['smoke_failures'] . ' failure(s).' );
}

WP_CLI::success( 'Economics scheduling smoke passed.' );
