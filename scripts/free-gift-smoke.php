<?php
/**
 * WP-CLI smoke: free_gift_product action preview and optional cart metadata.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/free-gift-smoke.php
 *
 * Optional env (via wp eval-file --env):
 *   MP_CP_SMOKE_GIFT_PRODUCT_ID — product ID for cart add test (skip cart if unset)
 *   MP_CP_SMOKE_QUALIFYING_PRODUCT_ID — product added before gift applier (defaults to gift ID)
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
use MP\CommercePromotions\Service\SimpleRuleBuilder;
use MP\CommercePromotions\Woo\CartPromotionApplier;
use MP\CommercePromotions\Woo\FreeGiftCartHandler;

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

$gift_product_id = 0;
if ( getenv( 'MP_CP_SMOKE_GIFT_PRODUCT_ID' ) !== false && is_numeric( getenv( 'MP_CP_SMOKE_GIFT_PRODUCT_ID' ) ) ) {
	$gift_product_id = (int) getenv( 'MP_CP_SMOKE_GIFT_PRODUCT_ID' );
}
if ( $gift_product_id <= 0 && function_exists( 'wc_get_products' ) ) {
	$products = wc_get_products(
		array(
			'status'  => 'publish',
			'limit'   => 1,
			'orderby' => 'date',
			'order'   => 'DESC',
		)
	);
	if ( is_array( $products ) && isset( $products[0] ) && is_object( $products[0] ) && method_exists( $products[0], 'get_id' ) ) {
		$gift_product_id = (int) $products[0]->get_id();
	}
}

if ( $gift_product_id <= 0 ) {
	WP_CLI::error( 'No gift product ID available; set MP_CP_SMOKE_GIFT_PRODUCT_ID or publish a product.' );
}

$qualifying_id = $gift_product_id;
if ( getenv( 'MP_CP_SMOKE_QUALIFYING_PRODUCT_ID' ) !== false && is_numeric( getenv( 'MP_CP_SMOKE_QUALIFYING_PRODUCT_ID' ) ) ) {
	$qualifying_id = (int) getenv( 'MP_CP_SMOKE_QUALIFYING_PRODUCT_ID' );
}

$draft    = $service->create_draft( 'Smoke free gift ' . gmdate( 'Y-m-d H:i:s' ) );
$promo_id = (int) $draft->get_id();
if ( $promo_id <= 0 ) {
	WP_CLI::error( 'Could not create smoke promotion.' );
}

$rules = array(
	'conditions' => array(
		array(
			'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
			'amount' => 0.01,
		),
	),
	'actions'    => array(
		array(
			'type'       => RuleTypes::ACTION_FREE_GIFT_PRODUCT,
			'product_id' => $gift_product_id,
			'quantity'   => 1,
		),
	),
);

$updated = $draft->with_rules( $rules['conditions'], $rules['actions'], $draft->get_restrictions() );
$service->update_promotion( $updated );
$service->change_status( $updated, PromotionStatus::ACTIVE );

$promotion = $repo->find( $promo_id );
smoke_assert( $promotion !== null && $promotion->get_status() === PromotionStatus::ACTIVE, 'Promotion active' );

$context = new EvaluationContext( null, 100.0, 'USD', array(), array() );
$result  = $evaluator->evaluate( $promotion, $context );
smoke_assert( $result->is_eligible(), 'Evaluator eligible' );
smoke_assert(
	isset( $result->get_action_results()[0]['payload']['product_id'] )
	&& (int) $result->get_action_results()[0]['payload']['product_id'] === $gift_product_id,
	'Preview product_id matches'
);

$builder = SimpleRuleBuilder::build_from_post(
	array(
		'mp_cp_builder_condition_type'  => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
		'mp_cp_builder_amount'          => '1',
		'mp_cp_builder_action_type'       => RuleTypes::ACTION_FREE_GIFT_PRODUCT,
		'mp_cp_builder_gift_product_id'   => (string) $gift_product_id,
		'mp_cp_builder_gift_quantity'     => '1',
	)
);
smoke_assert(
	$builder['actions'][0]['type'] === RuleTypes::ACTION_FREE_GIFT_PRODUCT,
	'SimpleRuleBuilder free_gift_product'
);

if ( function_exists( 'wc_load_cart' ) ) {
	wc_load_cart();
}

$wc = WC();
if ( is_object( $wc ) && isset( $wc->cart ) && is_object( $wc->cart ) && method_exists( $wc->cart, 'empty_cart' ) ) {
	$wc->cart->empty_cart();
	$wc->cart->add_to_cart( $qualifying_id, 1 );
	$context = ( new \MP\CommercePromotions\Woo\CartContextBuilder( new \MP\CommercePromotions\Domain\RedemptionRepository( $wpdb ) ) )
		->build_from_cart();

	$applier = new CartPromotionApplier(
		$repo,
		new \MP\CommercePromotions\Domain\PromotionCodeRepository( $wpdb ),
		$evaluator,
		new \MP\CommercePromotions\Woo\CartContextBuilder( new \MP\CommercePromotions\Domain\RedemptionRepository( $wpdb ) ),
		new \MP\CommercePromotions\Service\Settings()
	);

	$applier->apply();

	$has_gift = false;
	foreach ( $wc->cart->get_cart() as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		if ( ! empty( $item[ FreeGiftCartHandler::CART_ITEM_META_FREE_GIFT ] ) && $item[ FreeGiftCartHandler::CART_ITEM_META_FREE_GIFT ] === 'yes' ) {
			$has_gift = true;
			smoke_assert( (int) $item['product_id'] === $gift_product_id, 'Gift cart line product_id' );
			smoke_assert( ! empty( $item[ FreeGiftCartHandler::CART_ITEM_META_PROMOTION_ID ] ), 'Gift cart line promotion_id meta' );
		}
	}
	smoke_assert( $has_gift, 'Gift line added to cart' );

	$applier->zero_free_gift_line_prices();
	$gift_price_zero = false;
	foreach ( $wc->cart->get_cart() as $item ) {
		if ( ! is_array( $item ) || empty( $item[ FreeGiftCartHandler::CART_ITEM_META_FREE_GIFT ] ) ) {
			continue;
		}
		if ( isset( $item['data'] ) && is_object( $item['data'] ) && method_exists( $item['data'], 'get_price' ) ) {
			$gift_price_zero = (float) $item['data']->get_price() === 0.0;
		}
	}
	smoke_assert( $gift_price_zero, 'Gift line price zero after totals hook' );

	$wc->cart->empty_cart();
} else {
	WP_CLI::log( 'Cart smoke skipped (wc_load_cart unavailable).' );
}

smoke_archive_promotion( $service, $repo, $promo_id );

if ( $GLOBALS['smoke_failures'] > 0 ) {
	WP_CLI::error( sprintf( '%d assertion(s) failed.', $GLOBALS['smoke_failures'] ) );
}

WP_CLI::success( 'free-gift-smoke completed.' );
