<?php
/**
 * WP-CLI smoke: free_shipping action preview and customer_redemption_count condition.
 *
 * Cart shipping fee offset is not asserted here (requires browser checkout with shipping).
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/free-shipping-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PromotionService;

$GLOBALS['smoke_failures'] = 0;

function smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

function smoke_archive_promotion( PromotionService $service, PromotionRepository $repo, int $id ): void {
	$p = $repo->find( $id );
	if ( $p === null ) {
		return;
	}
	$status = $p->get_status();
	if ( $status === PromotionStatus::ACTIVE || $status === PromotionStatus::PAUSED ) {
		try {
			$service->change_status( $p, PromotionStatus::ARCHIVED );
		} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}
	}
}

global $wpdb;

if ( ! class_exists( 'WP_CLI' ) ) {
	echo "WP-CLI required.\n";
	exit( 1 );
}

if ( ! function_exists( 'wc' ) ) {
	WP_CLI::error( 'WooCommerce is not active.' );
}

$plugin = new \MP\CommercePromotions\Plugin();
$plugin->init();

$repo      = new PromotionRepository( $wpdb );
$audit     = new \MP\CommercePromotions\Domain\AuditLogRepository( $wpdb );
$audit_log = new \MP\CommercePromotions\Service\AuditLogger( $audit );
$factory   = new \MP\CommercePromotions\Domain\PromotionFactory();
$service   = new PromotionService( $repo, $factory, $audit_log );
$evaluator = new PromotionEvaluator();

$draft = $service->create_draft( 'Smoke free shipping ' . gmdate( 'Y-m-d H:i:s' ) );
$promo_id = (int) $draft->get_id();
if ( $promo_id <= 0 ) {
	WP_CLI::error( 'Could not create smoke promotion.' );
}

$updated = $draft
	->with_rules(
		array(
			array(
				'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'amount' => 1,
			),
		),
		array(
			array( 'type' => RuleTypes::ACTION_FREE_SHIPPING ),
		),
		array()
	);

$service->update_promotion( $updated );
$active = $service->change_status( $updated, PromotionStatus::ACTIVE );
$reload = $repo->find( (int) $active->get_id() );

smoke_assert( $reload !== null, 'Active free_shipping promotion created' );

$context = new EvaluationContext( null, 100.0, 'USD', array(), array() );
$result  = $evaluator->evaluate( $reload, $context );

smoke_assert( $result->is_eligible(), 'Evaluator marks promotion eligible' );
$actions = $result->get_action_results();
smoke_assert(
	count( $actions ) === 1
	&& isset( $actions[0]['type'] )
	&& $actions[0]['type'] === RuleTypes::ACTION_FREE_SHIPPING
	&& isset( $actions[0]['payload']['free_shipping'] )
	&& $actions[0]['payload']['free_shipping'] === true,
	'Action preview includes free_shipping: true'
);

$traces = $result->get_action_traces();
smoke_assert(
	count( $traces ) > 0
	&& isset( $traces[0]['preview']['payload']['free_shipping'] )
	&& $traces[0]['preview']['payload']['free_shipping'] === true,
	'Action trace preview includes free_shipping'
);

WP_CLI::log( 'Note: verify shipping fee offset in browser checkout when shipping methods return a non-zero total.' );

$redemption_promo = $service->create_draft( 'Smoke redemption count ' . gmdate( 'Y-m-d H:i:s' ) );
$redemption_id    = (int) $redemption_promo->get_id();
$redemption_rules = $redemption_promo
	->with_rules(
		array(
			array(
				'type'     => RuleTypes::CONDITION_CUSTOMER_REDEMPTION_COUNT,
				'operator' => '<',
				'count'    => 1,
			),
		),
		array(
			array(
				'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'percentage' => 5,
			),
		),
		array()
	);
$service->update_promotion( $redemption_rules );
$active_redemption = $service->change_status( $redemption_rules, PromotionStatus::ACTIVE );
$reload_redemption = $repo->find( (int) $active_redemption->get_id() );

$pass_ctx = new EvaluationContext( 1, 50.0, 'USD', array(), array( 'customer_redemption_count' => 0 ) );
$pass     = $evaluator->evaluate( $reload_redemption, $pass_ctx );
smoke_assert( $pass->is_eligible(), 'customer_redemption_count passes when count < 1' );

$fail_ctx = new EvaluationContext( 1, 50.0, 'USD', array(), array( 'customer_redemption_count' => 5 ) );
$fail     = $evaluator->evaluate( $reload_redemption, $fail_ctx );
smoke_assert( ! $fail->is_eligible(), 'customer_redemption_count fails when count >= 1' );

$missing_ctx = new EvaluationContext( 1, 50.0, 'USD', array(), array() );
$missing     = $evaluator->evaluate( $reload_redemption, $missing_ctx );
smoke_assert( ! $missing->is_eligible(), 'customer_redemption_count fails when metadata missing' );

smoke_archive_promotion( $service, $repo, $promo_id );
smoke_archive_promotion( $service, $repo, $redemption_id );

if ( $GLOBALS['smoke_failures'] > 0 ) {
	WP_CLI::error( sprintf( '%d smoke assertion(s) failed.', (int) $GLOBALS['smoke_failures'] ) );
}

WP_CLI::success( 'free-shipping-smoke completed.' );
