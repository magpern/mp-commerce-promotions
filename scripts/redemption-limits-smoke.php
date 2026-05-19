<?php
/**
 * WP-CLI smoke: redemption restrictions and cart quantity conditions.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/redemption-limits-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\Condition\ConditionTrace;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PromotionService;
use MP\CommercePromotions\Service\SimpleRuleBuilder;

$GLOBALS['smoke_failures'] = 0;

function smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

function smoke_reason( EvaluationContext $context, \MP\CommercePromotions\Domain\Promotion $promotion, PromotionEvaluator $evaluator ): string {
	$result = $evaluator->evaluate( $promotion, $context );
	if ( $result->is_eligible() ) {
		return '';
	}
	$traces = $result->get_condition_traces();
	if ( isset( $traces[0]['reason_code'] ) ) {
		return (string) $traces[0]['reason_code'];
	}

	return '';
}

global $wpdb;

if ( ! class_exists( 'WP_CLI' ) ) {
	echo "WP-CLI required.\n";
	exit( 1 );
}

$plugin = new \MP\CommercePromotions\Plugin();
$plugin->init();

$repo      = new PromotionRepository( $wpdb );
$audit     = new \MP\CommercePromotions\Domain\AuditLogRepository( $wpdb );
$audit_log = new \MP\CommercePromotions\Service\AuditLogger( $audit );
$factory   = new \MP\CommercePromotions\Domain\PromotionFactory();
$service   = new PromotionService( $repo, $factory, $audit_log );
$evaluator = new PromotionEvaluator( new \MP\CommercePromotions\Domain\RedemptionRepository( $wpdb ) );

$context = new EvaluationContext( null, 100.0, 'USD', array(), array() );

$usage_limit_promo = \MP\CommercePromotions\Domain\Promotion::from_array(
	array_merge(
		$service->create_draft( 'Smoke usage limit ' . gmdate( 'His' ) )->to_array(),
		array(
			'status'      => PromotionStatus::ACTIVE,
			'usage_limit' => 1,
			'usage_count' => 1,
			'conditions'  => array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 1,
				),
			),
			'actions'     => array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 5,
				),
			),
		)
	)
);
smoke_assert(
	smoke_reason( $context, $usage_limit_promo, $evaluator ) === ConditionTrace::REASON_USAGE_LIMIT_REACHED,
	'usage_limit_reached'
);

$customer_limit_promo = \MP\CommercePromotions\Domain\Promotion::from_array(
	array_merge(
		$service->create_draft( 'Smoke customer limit ' . gmdate( 'His' ) )->to_array(),
		array(
			'status'               => PromotionStatus::ACTIVE,
			'customer_usage_limit' => 1,
			'conditions'           => array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 1,
				),
			),
			'actions'              => array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 5,
				),
			),
		)
	)
);
smoke_assert(
	smoke_reason( $context, $customer_limit_promo, $evaluator ) === ConditionTrace::REASON_CUSTOMER_REQUIRED_FOR_USAGE_TRACKING,
	'guest blocked for customer_usage_limit'
);

$future = gmdate( 'Y-m-d H:i:s', time() + 86400 );
$future_promo = $service->create_draft( 'Smoke future ' . gmdate( 'His' ) );
$future_promo = $future_promo->with_date_window( $future, null )->with_status( PromotionStatus::ACTIVE );
smoke_assert(
	smoke_reason( $context, $future_promo, $evaluator ) === ConditionTrace::REASON_PROMOTION_NOT_STARTED,
	'promotion_not_started'
);

$past = gmdate( 'Y-m-d H:i:s', time() - 86400 );
$past_promo = $service->create_draft( 'Smoke expired ' . gmdate( 'His' ) );
$past_promo = $past_promo->with_date_window( null, $past )->with_status( PromotionStatus::ACTIVE );
smoke_assert(
	smoke_reason( $context, $past_promo, $evaluator ) === ConditionTrace::REASON_PROMOTION_EXPIRED,
	'promotion_expired'
);

$min_qty_promo = \MP\CommercePromotions\Domain\Promotion::from_array(
	array_merge(
		$service->create_draft( 'Smoke min qty ' . gmdate( 'His' ) )->to_array(),
		array(
			'status'     => PromotionStatus::ACTIVE,
			'conditions' => array(
				array(
					'type'     => RuleTypes::CONDITION_MINIMUM_CART_QUANTITY,
					'quantity' => 3,
				),
			),
			'actions'    => array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 5,
				),
			),
		)
	)
);
$low_qty_context = new EvaluationContext(
	null,
	10.0,
	'USD',
	array(
		array(
			'product_id' => 1,
			'quantity'   => 1.0,
		),
	),
	array()
);
smoke_assert( ! $evaluator->evaluate( $min_qty_promo, $low_qty_context )->is_eligible(), 'minimum_cart_quantity fails below threshold' );

$builder = SimpleRuleBuilder::build_from_post(
	array(
		'mp_cp_builder_condition_type' => RuleTypes::CONDITION_MAXIMUM_CART_QUANTITY,
		'mp_cp_builder_cart_quantity'  => '2',
		'mp_cp_builder_action_type'    => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
		'mp_cp_builder_percentage'     => '5',
	)
);
smoke_assert(
	$builder['conditions'][0]['type'] === RuleTypes::CONDITION_MAXIMUM_CART_QUANTITY,
	'SimpleRuleBuilder maximum_cart_quantity'
);

if ( $GLOBALS['smoke_failures'] > 0 ) {
	WP_CLI::error( sprintf( '%d assertion(s) failed.', $GLOBALS['smoke_failures'] ) );
}

WP_CLI::success( 'redemption-limits-smoke completed.' );
