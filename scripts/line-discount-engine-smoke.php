<?php
/**
 * WP-CLI smoke: line discount application modes (schema 1.15.0).
 *
 * Usage:
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/line-discount-engine-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionDiscountApplicationMode;
use MP\CommercePromotions\Engine\AppliedLineDiscount;
use MP\CommercePromotions\Engine\DiscountAllocationEngine;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\LineDiscountAllocationResult;
use MP\CommercePromotions\Engine\EvaluationResult;
use MP\CommercePromotions\Engine\PromotionEvaluationDecision;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use MP\CommercePromotions\Service\LineDiscountModeHelper;
use MP\CommercePromotions\Service\PromotionReports;
use MP\CommercePromotions\Service\PromotionRuleValidator;
use MP\CommercePromotions\Woo\CartSessionHelper;
use MP\CommercePromotions\Woo\LineDiscountFallbackTelemetry;
use MP\CommercePromotions\Woo\LineDiscountPlanCache;
use MP\CommercePromotions\Woo\LineItemDiscountApplier;
use MP\CommercePromotions\Woo\LinePriceMutationGuard;
use MP\CommercePromotions\Woo\OrderPromotionState;
use MP\CommercePromotions\Woo\PricingCompatibilityAnalyzer;

$GLOBALS['mp_cp_line_smoke_ok']   = 0;
$GLOBALS['mp_cp_line_smoke_fail'] = 0;

function line_discount_smoke_assert( bool $cond, string $label ): void {
	if ( $cond ) {
		++$GLOBALS['mp_cp_line_smoke_ok'];
		echo "OK  {$label}\n";
		return;
	}
	++$GLOBALS['mp_cp_line_smoke_fail'];
	echo "FAIL {$label}\n";
}

$schema = get_option( 'mp_cp_schema_version', '' );
line_discount_smoke_assert( $schema === Schema::SCHEMA_VERSION, 'schema version ' . Schema::SCHEMA_VERSION );

line_discount_smoke_assert(
	PromotionDiscountApplicationMode::normalize( 'line_item' ) === PromotionDiscountApplicationMode::LINE_ITEM,
	'discount application mode line_item'
);

line_discount_smoke_assert( class_exists( LineItemDiscountApplier::class ), 'LineItemDiscountApplier class' );
line_discount_smoke_assert( class_exists( LinePriceMutationGuard::class ), 'LinePriceMutationGuard class' );
line_discount_smoke_assert( class_exists( LineDiscountModeHelper::class ), 'LineDiscountModeHelper class' );

$sample = new AppliedLineDiscount( 'test', 1, null, 1, 2.5, 9, 'percentage_discount' );
line_discount_smoke_assert( AppliedLineDiscount::from_array( $sample->to_array() ) !== null, 'AppliedLineDiscount round-trip' );

$result = new LineDiscountAllocationResult( array( $sample ), 2.5 );
line_discount_smoke_assert( LineDiscountAllocationResult::from_array( $result->to_array() )->get_total_allocated() === 2.5, 'LineDiscountAllocationResult round-trip' );

LineDiscountFallbackTelemetry::reset();
LineDiscountFallbackTelemetry::record( LineDiscountFallbackTelemetry::REASON_MUTATION_GUARD_TRIGGERED, 1 );
line_discount_smoke_assert( LineDiscountFallbackTelemetry::get_total() >= 1, 'fallback telemetry request scope' );
$persisted = LineDiscountFallbackTelemetry::get_persisted_stats();
line_discount_smoke_assert( (int) ( $persisted['total'] ?? 0 ) >= 1, 'fallback telemetry persisted' );

$context = new EvaluationContext(
	null,
	100.0,
	'EUR',
	array(
		array(
			'item_key'      => 'line_a',
			'product_id'    => 101,
			'line_subtotal' => 60.0,
			'unit_price'    => 30.0,
			'quantity'      => 2,
		),
		array(
			'item_key'      => 'line_b',
			'product_id'    => 202,
			'line_subtotal' => 40.0,
			'unit_price'    => 40.0,
			'quantity'      => 1,
		),
	),
	array()
);

$promotion_pct = Promotion::from_array(
	array(
		'id'                        => 901,
		'uuid'                      => '00000000-0000-0000-0000-000000000901',
		'name'                      => 'Smoke Line Pct',
		'status'                    => 'active',
		'priority'                  => 10,
		'conditions'                => array(),
		'actions'                   => array(
			array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ),
		),
		'restrictions'              => array(),
		'usage_count'               => 0,
		'application_mode'          => 'stackable',
		'stop_processing'           => false,
		'discount_application_mode' => PromotionDiscountApplicationMode::LINE_ITEM,
	)
);

$allocator = new DiscountAllocationEngine();
$decision  = new PromotionEvaluationDecision( $promotion_pct, EvaluationResult::eligible( array(), array() ), true, null );
$alloc     = $allocator->allocate( $context, array( $decision ), false );
line_discount_smoke_assert( $alloc->get_total_allocated() > 0, 'percentage line allocation estimate' );

$promotion_fixed = Promotion::from_array(
	array(
		'id'                        => 902,
		'uuid'                      => '00000000-0000-0000-0000-000000000902',
		'name'                      => 'Smoke Line Fixed',
		'status'                    => 'active',
		'priority'                  => 10,
		'conditions'                => array(),
		'actions'                   => array(
			array( 'type' => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, 'amount' => 15 ),
		),
		'restrictions'              => array(),
		'usage_count'               => 0,
		'application_mode'          => 'stackable',
		'stop_processing'           => false,
		'discount_application_mode' => PromotionDiscountApplicationMode::LINE_ITEM,
	)
);
$decision_fixed = new PromotionEvaluationDecision( $promotion_fixed, EvaluationResult::eligible( array(), array() ), true, null );
$alloc_fixed      = $allocator->allocate( $context, array( $decision_fixed ), false );
line_discount_smoke_assert( $alloc_fixed->get_total_allocated() >= 15, 'fixed line allocation estimate' );

$promotion_scoped = Promotion::from_array(
	array(
		'id'                        => 903,
		'uuid'                      => '00000000-0000-0000-0000-000000000903',
		'name'                      => 'Smoke Scoped',
		'status'                    => 'active',
		'priority'                  => 10,
		'conditions'                => array(),
		'actions'                   => array(
			array(
				'type'         => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'percentage'   => 20,
				'product_ids'  => array( 101 ),
			),
		),
		'restrictions'              => array(),
		'usage_count'               => 0,
		'application_mode'          => 'stackable',
		'stop_processing'           => false,
		'discount_application_mode' => PromotionDiscountApplicationMode::LINE_ITEM,
	)
);
$decision_scoped = new PromotionEvaluationDecision( $promotion_scoped, EvaluationResult::eligible( array(), array() ), true, null );
$alloc_scoped    = $allocator->allocate( $context, array( $decision_scoped ), false );
$scoped_total    = 0.0;
foreach ( $alloc_scoped->get_line_allocations() as $slice ) {
	$row = $slice->to_array();
	if ( ( $row['line_key'] ?? '' ) === 'line_b' ) {
		line_discount_smoke_assert( false, 'scoped allocation should skip line_b' );
	}
	$scoped_total += (float) ( $row['amount'] ?? 0 );
}
line_discount_smoke_assert( $scoped_total > 0, 'scoped line allocation targets qualifying line' );

$hybrid_promo = Promotion::from_array(
	array(
		'id'                        => 904,
		'uuid'                      => '00000000-0000-0000-0000-000000000904',
		'name'                      => 'Smoke Hybrid Gift',
		'status'                    => 'active',
		'priority'                  => 10,
		'conditions'                => array(),
		'actions'                   => array(
			array( 'type' => RuleTypes::ACTION_FREE_GIFT_PRODUCT, 'product_id' => 55, 'quantity' => 1 ),
		),
		'restrictions'              => array(),
		'usage_count'               => 0,
		'application_mode'          => 'stackable',
		'stop_processing'           => false,
		'discount_application_mode' => PromotionDiscountApplicationMode::HYBRID,
	)
);
$classified = LineDiscountModeHelper::classify_actions( $hybrid_promo->get_actions() );
line_discount_smoke_assert(
	$classified['fee_or_gift'] !== array() && $classified['line_capable'] === array(),
	'hybrid unsupported action classified as fee/gift only'
);

LinePriceMutationGuard::begin_cycle();
LinePriceMutationGuard::mark_mutated( 'dup_key' );
line_discount_smoke_assert( LinePriceMutationGuard::was_mutated_this_cycle( 'dup_key' ), 'mutation guard duplicate tracking' );
LinePriceMutationGuard::reset_cycle();

$session_payload = array(
	'line_discounts'  => array( $sample->to_array() ),
	'total_allocated' => 2.5,
);
CartSessionHelper::set_line_allocations( $session_payload );
$read_back = CartSessionHelper::get_line_allocations();
line_discount_smoke_assert(
	is_array( $read_back ) && (float) ( $read_back['total_allocated'] ?? 0 ) === 2.5,
	'session allocation summary'
);
$usage = CartSessionHelper::get_line_usage_stats();
line_discount_smoke_assert( (int) ( $usage['applications'] ?? 0 ) >= 1, 'line usage stats incremented' );

line_discount_smoke_assert( OrderPromotionState::META_LINE_ALLOCATIONS === '_mp_cp_line_allocations', 'order meta constant for line allocations' );

$validator = new PromotionRuleValidator();
$issues    = $validator->validate( $hybrid_promo );
$has_fee_warning = false;
foreach ( $issues as $issue ) {
	if ( ! is_array( $issue ) ) {
		continue;
	}
	if ( str_contains( (string) ( $issue['message'] ?? '' ), 'fee-based' ) || str_contains( (string) ( $issue['message'] ?? '' ), 'gift-based' ) ) {
		$has_fee_warning = true;
	}
}
line_discount_smoke_assert( $has_fee_warning, 'validator warns fee/gift action under line mode' );

$analyzer = new PricingCompatibilityAnalyzer();
$audit    = $analyzer->audit_line_discount_mode( PromotionDiscountApplicationMode::LINE_ITEM );
line_discount_smoke_assert( ! empty( $audit['confidence'] ), 'line mode compatibility audit' );

global $wpdb;
if ( $wpdb instanceof wpdb ) {
	$reports = new PromotionReports(
		new \MP\CommercePromotions\Domain\PromotionRepository( $wpdb ),
		new \MP\CommercePromotions\Domain\RedemptionRepository( $wpdb )
	);
	$summary = $reports->line_discount_mode_summary();
	line_discount_smoke_assert( isset( $summary['line_item_promotions'] ), 'reports line_discount_mode_summary' );
}

$root = dirname( __DIR__ );
line_discount_smoke_assert( is_readable( $root . '/docs/manual-line-discount-engine-test.md' ), 'manual line discount QA doc' );

$ok   = (int) $GLOBALS['mp_cp_line_smoke_ok'];
$fail = (int) $GLOBALS['mp_cp_line_smoke_fail'];
echo "\nLine discount engine smoke: {$ok} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
