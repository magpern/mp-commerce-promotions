<?php
/**
 * WP-CLI smoke: gift card products excluded from free shipping progress and promotions.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/gift-card-shipping-exclusion-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use MP\CommercePromotions\GiftCard\GiftCardPromotionExclusion;
use MP\CommercePromotions\Woo\CartShippingEligibilitySubtotal;

$GLOBALS['gcs_smoke_failures'] = 0;

function gcs_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['gcs_smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

if ( ! class_exists( 'WP_CLI' ) ) {
	echo "WP-CLI required.\n";
	exit( 1 );
}

if ( ! class_exists( 'WC_Product_Simple' ) || ! function_exists( 'WC' ) ) {
	WP_CLI::error( 'WooCommerce unavailable' );
}

$plugin = new \MP\CommercePromotions\Plugin();
$plugin->init();

$gift = new WC_Product_Simple();
$gift->set_name( 'Smoke GC shipping exclusion ' . wp_generate_password( 3, false ) );
$gift->set_regular_price( '100' );
$gift->set_virtual( true );
$gift->set_status( 'publish' );
$gift_id = (int) $gift->save();

$physical = new WC_Product_Simple();
$physical->set_name( 'Smoke physical shipping ' . wp_generate_password( 3, false ) );
$physical->set_regular_price( '100' );
$physical->set_status( 'publish' );
$physical_id = (int) $physical->save();

GiftCardProductMeta::save(
	$gift_id,
	array(
		'sells'        => GiftCardProductMeta::VALUE_YES,
		'amount_mode'  => GiftCardProductMeta::AMOUNT_MODE_FIXED,
		'fixed_amount' => '100',
	)
);

$free_ship_promo = Promotion::from_array(
	array(
		'uuid'       => wp_generate_uuid4(),
		'name'       => 'Smoke free shipping min 50',
		'status'     => PromotionStatus::ACTIVE,
		'priority'   => 1,
		'conditions' => array(
			array(
				'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'amount' => 50,
			),
		),
		'actions'    => array(
			array(
				'type' => RuleTypes::ACTION_FREE_SHIPPING,
			),
		),
	)
);

$evaluator = new PromotionEvaluator();

function gcs_empty_cart(): void {
	if ( ! WC()->cart ) {
		return;
	}
	WC()->cart->empty_cart();
}

// Gift card €100 only — qualifying subtotal 0, no free shipping promo.
gcs_empty_cart();
WC()->cart->add_to_cart( $gift_id, 1 );
$gift_only_stats = CartShippingEligibilitySubtotal::stats_from_cart();
gcs_smoke_assert(
	$gift_only_stats[ CartShippingEligibilitySubtotal::TRACE_QUALIFYING_KEY ] === 0.0,
	'gift card €100 only → qualifying shipping subtotal €0'
);
gcs_smoke_assert(
	! $gift_only_stats['has_qualifying_shipping_items'],
	'gift card only → no qualifying shippable items'
);
$gift_only_subtotal = (float) apply_filters( 'biopentra_header_auth_cart_free_shipping_subtotal', 999.0 );
gcs_smoke_assert(
	$gift_only_subtotal === 0.0,
	'storefront progress subtotal filter returns €0 for gift-card-only cart'
);
$show_progress = (bool) apply_filters( 'biopentra_header_auth_cart_show_free_shipping_progress', true );
gcs_smoke_assert(
	! $show_progress,
	'gift card only → free shipping progress hidden'
);
$builder = new \MP\CommercePromotions\Woo\CartContextBuilder();
$gift_ctx = $builder->build_from_cart();
$gift_only_eval = $evaluator->evaluate( $free_ship_promo, $gift_ctx );
gcs_smoke_assert(
	! $gift_only_eval->is_eligible(),
	'gift card only → free shipping promotion does not apply'
);

// Physical €100 only — qualifying subtotal 100.
gcs_empty_cart();
WC()->cart->add_to_cart( $physical_id, 1 );
$physical_stats = CartShippingEligibilitySubtotal::stats_from_cart();
gcs_smoke_assert(
	$physical_stats[ CartShippingEligibilitySubtotal::TRACE_QUALIFYING_KEY ] === 100.0,
	'physical €100 only → qualifying shipping subtotal €100'
);
$physical_ctx = $builder->build_from_cart();
$physical_eval = $evaluator->evaluate( $free_ship_promo, $physical_ctx );
gcs_smoke_assert(
	$physical_eval->is_eligible(),
	'physical €100 only → free shipping promotion eligible (min 50)'
);

// Mixed gift €100 + physical €50 → qualifying €50.
gcs_empty_cart();
WC()->cart->add_to_cart( $gift_id, 1 );
WC()->cart->add_to_cart( $physical_id, 1, 0, array(), array( 'quantity' => 1 ) );
// Second line at €50: set product price to 50 for this test.
$physical_50 = new WC_Product_Simple();
$physical_50->set_name( 'Smoke physical 50 ' . wp_generate_password( 3, false ) );
$physical_50->set_regular_price( '50' );
$physical_50->set_status( 'publish' );
$physical_50_id = (int) $physical_50->save();
gcs_empty_cart();
WC()->cart->add_to_cart( $gift_id, 1 );
WC()->cart->add_to_cart( $physical_50_id, 1 );
$mixed_stats = CartShippingEligibilitySubtotal::stats_from_cart();
gcs_smoke_assert(
	abs( $mixed_stats[ CartShippingEligibilitySubtotal::TRACE_QUALIFYING_KEY ] - 50.0 ) < 0.01,
	'mixed gift €100 + physical €50 → qualifying subtotal €50'
);
$mixed_ctx = $builder->build_from_cart();
$mixed_eval = $evaluator->evaluate( $free_ship_promo, $mixed_ctx );
gcs_smoke_assert(
	$mixed_eval->is_eligible(),
	'mixed cart with €50 physical qualifies for min 50 free shipping'
);

$high_min_promo = Promotion::from_array(
	array(
		'uuid'       => wp_generate_uuid4(),
		'name'       => 'Smoke free shipping min 200',
		'status'     => PromotionStatus::ACTIVE,
		'priority'   => 1,
		'conditions' => array(
			array(
				'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'amount' => 200,
			),
		),
		'actions'    => array(
			array(
				'type' => RuleTypes::ACTION_FREE_SHIPPING,
			),
		),
	)
);
$mixed_fail = $evaluator->evaluate( $high_min_promo, $mixed_ctx );
gcs_smoke_assert(
	! $mixed_fail->is_eligible(),
	'gift card value alone does not satisfy €200 minimum (only €50 qualifying)'
);

gcs_smoke_assert(
	GiftCardPromotionExclusion::products()->product_sells_gift_card( $gift_id, 0 ),
	'gift card product remains purchasable (meta intact)'
);

wp_delete_post( $gift_id, true );
wp_delete_post( $physical_id, true );
wp_delete_post( $physical_50_id, true );
gcs_empty_cart();

if ( $GLOBALS['gcs_smoke_failures'] > 0 ) {
	WP_CLI::error( sprintf( '%d assertion(s) failed.', $GLOBALS['gcs_smoke_failures'] ) );
}

WP_CLI::success( 'gift-card-shipping-exclusion-smoke passed.' );
