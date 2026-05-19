<?php
/**
 * WP-CLI smoke: paid shippable subtotal for free shipping qualification.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/shipping-qualified-subtotal-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use MP\CommercePromotions\Service\PromotionService;
use MP\CommercePromotions\Woo\CartContextBuilder;
use MP\CommercePromotions\Woo\FreeGiftCartHandler;
use MP\CommercePromotions\Woo\ShippingQualifiedSubtotalCalculator;

$GLOBALS['sqs_smoke_failures'] = 0;

function sqs_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['sqs_smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

if ( ! class_exists( 'WP_CLI' ) || ! function_exists( 'WC' ) ) {
	echo "WP-CLI and WooCommerce required.\n";
	exit( 1 );
}

$plugin = new \MP\CommercePromotions\Plugin();
$plugin->init();

function sqs_empty_cart(): void {
	if ( WC()->cart ) {
		WC()->cart->empty_cart();
	}
}

$gift = new WC_Product_Simple();
$gift->set_name( 'Smoke SQS gift ' . wp_generate_password( 3, false ) );
$gift->set_regular_price( '100' );
$gift->set_virtual( true );
$gift->set_status( 'publish' );
$gift_id = (int) $gift->save();

$physical = new WC_Product_Simple();
$physical->set_name( 'Smoke SQS physical ' . wp_generate_password( 3, false ) );
$physical->set_regular_price( '100' );
$physical->set_status( 'publish' );
$physical_id = (int) $physical->save();

$physical_50 = new WC_Product_Simple();
$physical_50->set_name( 'Smoke SQS physical 50 ' . wp_generate_password( 3, false ) );
$physical_50->set_regular_price( '50' );
$physical_50->set_status( 'publish' );
$physical_50_id = (int) $physical_50->save();

$free_gift_product = new WC_Product_Simple();
$free_gift_product->set_name( 'Smoke SQS free gift ' . wp_generate_password( 3, false ) );
$free_gift_product->set_regular_price( '10' );
$free_gift_product->set_status( 'publish' );
$free_gift_id = (int) $free_gift_product->save();

$bogo_product = new WC_Product_Simple();
$bogo_product->set_name( 'Smoke SQS BOGO ' . wp_generate_password( 3, false ) );
$bogo_product->set_regular_price( '10' );
$bogo_product->set_status( 'publish' );
$bogo_id = (int) $bogo_product->save();
if ( function_exists( 'wp_set_object_terms' ) ) {
	wp_set_object_terms( $bogo_id, array( 'smoke-bogo' ), 'product_cat', false );
}
$bogo_term = get_term_by( 'slug', 'smoke-bogo', 'product_cat' );
$bogo_cat_id = $bogo_term ? (int) $bogo_term->term_id : 0;

GiftCardProductMeta::save(
	$gift_id,
	array(
		'sells'        => GiftCardProductMeta::VALUE_YES,
		'amount_mode'  => GiftCardProductMeta::AMOUNT_MODE_FIXED,
		'fixed_amount' => '100',
	)
);

$builder = new CartContextBuilder();

// Gift card €100 only → 0.
sqs_empty_cart();
WC()->cart->add_to_cart( $gift_id, 1 );
$stats = ShippingQualifiedSubtotalCalculator::stats_from_cart();
sqs_smoke_assert( $stats[ ShippingQualifiedSubtotalCalculator::TRACE_QUALIFYING ] === 0.0, 'gift card €100 only → qualifying 0' );
sqs_smoke_assert( ! (bool) apply_filters( 'biopentra_header_auth_cart_show_free_shipping_progress', true ), 'gift card only hides progress' );

// Physical €100 only → 100.
sqs_empty_cart();
WC()->cart->add_to_cart( $physical_id, 1 );
$stats = ShippingQualifiedSubtotalCalculator::stats_from_cart();
sqs_smoke_assert( $stats[ ShippingQualifiedSubtotalCalculator::TRACE_QUALIFYING ] === 100.0, 'physical €100 only → qualifying 100' );

// Mixed gift + physical €50 → 50.
sqs_empty_cart();
WC()->cart->add_to_cart( $gift_id, 1 );
WC()->cart->add_to_cart( $physical_50_id, 1 );
$stats = ShippingQualifiedSubtotalCalculator::stats_from_cart();
sqs_smoke_assert( abs( $stats[ ShippingQualifiedSubtotalCalculator::TRACE_QUALIFYING ] - 50.0 ) < 0.01, 'mixed gift + physical €50 → qualifying 50' );

// Free gift €10 excluded.
sqs_empty_cart();
WC()->cart->add_to_cart( $physical_id, 1 );
WC()->cart->add_to_cart(
	$free_gift_id,
	1,
	0,
	array(),
	array( FreeGiftCartHandler::CART_ITEM_META_FREE_GIFT => 'yes' )
);
$stats = ShippingQualifiedSubtotalCalculator::stats_from_cart();
sqs_smoke_assert( $stats[ ShippingQualifiedSubtotalCalculator::TRACE_QUALIFYING ] === 100.0, 'free gift €10 excluded from qualifying (physical €100 remains)' );

// BOGO buy 3 get 1 free @ €10 → qualifying 30.
if ( $bogo_cat_id > 0 ) {
	global $wpdb;
	$repo      = new PromotionRepository( $wpdb );
	$factory   = new \MP\CommercePromotions\Domain\PromotionFactory();
	$audit     = new \MP\CommercePromotions\Domain\AuditLogRepository( $wpdb );
	$audit_log = new \MP\CommercePromotions\Service\AuditLogger( $audit );
	$service   = new PromotionService( $repo, $factory, $audit_log );

	$draft = $service->create_draft( 'Smoke BOGO shipping ' . gmdate( 'His' ) );
	$bogo_promo_id = (int) $draft->get_id();
	$bogo_promo = $draft->with_rules(
		array(
			array(
				'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'amount' => 1,
			),
		),
		array(
			array(
				'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
				'scope'               => 'category',
				'category_ids'        => array( $bogo_cat_id ),
				'discount_percentage' => 100,
				'required_quantity'   => 3,
				'discounted_quantity' => 1,
			),
		),
		array()
	);
	$service->update_promotion( $bogo_promo );
	$service->change_status( $bogo_promo, PromotionStatus::ACTIVE );

	sqs_empty_cart();
	WC()->cart->add_to_cart( $bogo_id, 4 );
	if ( method_exists( WC()->cart, 'calculate_totals' ) ) {
		WC()->cart->calculate_totals();
	}
	$bogo_active = $repo->find( $bogo_promo_id );
	$bogo_ctx    = $builder->build_from_cart();
	$bogo_stats  = $bogo_active !== null
		? ShippingQualifiedSubtotalCalculator::calculate( $bogo_ctx->get_items(), $bogo_ctx, array( $bogo_active ) )
		: ShippingQualifiedSubtotalCalculator::stats_from_cart();
	sqs_smoke_assert( abs( $bogo_stats[ ShippingQualifiedSubtotalCalculator::TRACE_QUALIFYING ] - 30.0 ) < 0.05, 'buy 3 get 1 free @ €10 → qualifying 30' );

	$free_ship = Promotion::from_array(
		array(
			'uuid'       => wp_generate_uuid4(),
			'name'       => 'Smoke FS 100',
			'status'     => PromotionStatus::ACTIVE,
			'priority'   => 2,
			'conditions' => array(
				array(
					'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
					'amount' => 100,
				),
			),
			'actions'    => array(
				array( 'type' => RuleTypes::ACTION_FREE_SHIPPING ),
			),
		)
	);
	$ctx = $builder->build_from_cart();
	sqs_smoke_assert( ! ( new PromotionEvaluator() )->evaluate( $free_ship, $ctx )->is_eligible(), '€100 threshold not met by gift card / BOGO free unit alone' );

	try {
		$archived = $repo->find( $bogo_promo_id );
		if ( $archived !== null ) {
			$service->change_status( $archived, PromotionStatus::ARCHIVED );
		}
	} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
	}
}

sqs_empty_cart();
wp_delete_post( $gift_id, true );
wp_delete_post( $physical_id, true );
wp_delete_post( $physical_50_id, true );
wp_delete_post( $free_gift_id, true );
wp_delete_post( $bogo_id, true );

if ( $GLOBALS['sqs_smoke_failures'] > 0 ) {
	WP_CLI::error( sprintf( '%d assertion(s) failed.', $GLOBALS['sqs_smoke_failures'] ) );
}

WP_CLI::success( 'shipping-qualified-subtotal-smoke passed.' );
