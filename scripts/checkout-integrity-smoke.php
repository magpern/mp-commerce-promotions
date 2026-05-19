<?php
/**
 * WP-CLI smoke: checkout redemption integrity (idempotency, reversal, gift sync helpers).
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/checkout-integrity-smoke.php
 *
 * Browser checkout still required for full cart fee/gift flows — see docs/manual-checkout-integrity-test.md.
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\Domain\AuditLogRepository;
use MP\CommercePromotions\Domain\PromotionCodeRepository;
use MP\CommercePromotions\Domain\PromotionFactory;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Domain\Redemption;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Service\AuditLogger;
use MP\CommercePromotions\Service\PromotionService;
use MP\CommercePromotions\Woo\AppliedPromotionSession;
use MP\CommercePromotions\Woo\CartPromotionApplier;
use MP\CommercePromotions\Woo\CartSessionHelper;
use MP\CommercePromotions\Woo\FreeGiftCartHandler;
use MP\CommercePromotions\Woo\FreeGiftCartSynchronizer;
use MP\CommercePromotions\Woo\OrderPromotionRecorder;
use MP\CommercePromotions\Woo\OrderPromotionState;

$GLOBALS['smoke_failures'] = 0;

function smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

global $wpdb;

if ( ! class_exists( 'WP_CLI' ) ) {
	fwrite( STDERR, "WP-CLI required.\n" );
	exit( 1 );
}

if ( ! $wpdb instanceof wpdb ) {
	WP_CLI::error( 'wpdb unavailable.' );
}

$promo_repo      = new PromotionRepository( $wpdb );
$redemption_repo = new RedemptionRepository( $wpdb );
$code_repo       = new PromotionCodeRepository( $wpdb );
$audit           = new AuditLogger( new AuditLogRepository( $wpdb ) );
$factory         = new PromotionFactory();
$service         = new PromotionService( $promo_repo, $factory, $audit );

$recorder = new OrderPromotionRecorder(
	$redemption_repo,
	$promo_repo,
	$code_repo,
	$audit
);

// --- OrderPromotionState JSON parse ---
$order = wc_create_order();
$order->update_meta_data(
	OrderPromotionState::META_APPLIED_PROMOTIONS,
	wp_json_encode(
		array(
			array( 'promotion_id' => 1 ),
			array( 'promotion_id' => 2 ),
		)
	)
);
$order->save();
$parsed = OrderPromotionState::get_applied_promotions( $order );
smoke_assert( count( $parsed ) === 2, 'OrderPromotionState parses applied promotions meta' );

// --- Stacked record + duplicate checkout hook ---
$promo_a = smoke_make_fixed_promo( $service, $promo_repo, 'Integrity A', 5.0, 40 );
$promo_b = smoke_make_fixed_promo( $service, $promo_repo, 'Integrity B', 3.0, 41 );

$entries = array(
	array(
		'promotion_id'    => $promo_a,
		'promotion_uuid'  => (string) $promo_repo->find( $promo_a )->get_uuid(),
		'promotion_name'  => 'Integrity A',
		'discount_amount' => 5.0,
		'action_type'     => CartPromotionApplier::ACTION_FIXED_AMOUNT_DISCOUNT,
		'fixed_amount'    => 5.0,
	),
	array(
		'promotion_id'    => $promo_b,
		'promotion_uuid'  => (string) $promo_repo->find( $promo_b )->get_uuid(),
		'promotion_name'  => 'Integrity B',
		'discount_amount' => 3.0,
		'action_type'     => CartPromotionApplier::ACTION_FIXED_AMOUNT_DISCOUNT,
		'fixed_amount'    => 3.0,
	),
);

CartSessionHelper::set_applied_promotion( AppliedPromotionSession::build_session_payload( $entries ) );

$stack_order = wc_create_order();
$recorder->record_on_order_create( $stack_order, array() );
$usage_after_first = (int) $promo_repo->find( $promo_a )->get_usage_count();

CartSessionHelper::set_applied_promotion( AppliedPromotionSession::build_session_payload( $entries ) );
$recorder->record_on_order_create( $stack_order, array() );
$usage_after_dup = (int) $promo_repo->find( $promo_a )->get_usage_count();

smoke_assert( $usage_after_dup === $usage_after_first, 'duplicate checkout_create_order does not double usage_count' );
smoke_assert( $redemption_repo->count_for_order( (int) $stack_order->get_id() ) === 2, 'stacked promotions record two redemption rows' );

// --- Reversal once ---
$order_id = (int) $stack_order->get_id();
$recorder->reverse_for_order( $order_id );
$recorder->reverse_for_order( $order_id );
$reversed = 0;
foreach ( $redemption_repo->find_for_order( $order_id ) as $row ) {
	if ( $row->get_status() === Redemption::STATUS_REVERSED ) {
		++$reversed;
	}
}
smoke_assert( $reversed === 2, 'reversal marks both rows reversed once' );
smoke_assert( OrderPromotionState::is_reversed( wc_get_order( $order_id ) ), 'order meta reversed flag set' );

// --- Gift synchronizer list helper (no live cart required) ---
if ( function_exists( 'WC' ) && function_exists( 'wc_load_cart' ) ) {
	wc_load_cart();
	$cart = WC()->cart;
	$cart->empty_cart( true );

	$product_ids = wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'return' => 'ids' ) );
	if ( is_array( $product_ids ) && count( $product_ids ) > 0 ) {
		$pid     = (int) $product_ids[0];
		$product = wc_get_product( $pid );
		if ( $product ) {
			$orphan_key = 'mp_cp_smoke_orphan';
			$cart->cart_contents[ $orphan_key ] = array(
				'key'                                          => $orphan_key,
				'product_id'                                   => $pid,
				'variation_id'                                 => 0,
				'quantity'                                     => 1,
				'data'                                         => $product,
				FreeGiftCartHandler::CART_ITEM_META_FREE_GIFT    => 'yes',
				FreeGiftCartHandler::CART_ITEM_META_PROMOTION_ID => '9999',
			);
		}

		$lines = FreeGiftCartSynchronizer::list_plugin_gift_lines( $cart );
		smoke_assert( count( $lines ) === 1, 'list_plugin_gift_lines finds mp_cp_free_gift row' );

		$sync = new FreeGiftCartSynchronizer( $promo_repo );
		$sync->sync( $cart, array() );
		$after = FreeGiftCartSynchronizer::list_plugin_gift_lines( $cart );
		smoke_assert( count( $after ) === 0 && count( $lines ) === 1, 'sync removes stale orphan gift' );
	} else {
		WP_CLI::log( 'Skip cart gift sync: no published products.' );
	}
} else {
	WP_CLI::log( 'Skip cart gift sync: Woo cart unavailable.' );
}

// cleanup
$stack_order->delete( true );
$order->delete( true );
smoke_archive( $service, $promo_repo, $promo_a );
smoke_archive( $service, $promo_repo, $promo_b );

if ( $GLOBALS['smoke_failures'] > 0 ) {
	WP_CLI::error( sprintf( 'checkout-integrity-smoke finished with %d failure(s).', (int) $GLOBALS['smoke_failures'] ) );
}

WP_CLI::success( 'checkout-integrity-smoke completed.' );

/**
 * @return int promotion id
 */
function smoke_make_fixed_promo(
	PromotionService $service,
	PromotionRepository $repo,
	string $name,
	float $amount,
	int $priority
): int {
	$draft = $service->create_draft( $name );
	$id    = (int) $draft->get_id();
	$updated = $draft
		->with_rules(
			array( array( 'type' => 'minimum_subtotal', 'amount' => 1 ) ),
			array( array( 'type' => 'fixed_amount_discount', 'amount' => $amount ) ),
			array()
		)
		->with_priority( $priority );
	$service->update_promotion( $updated );
	$service->change_status( $updated, PromotionStatus::ACTIVE );
	$reload = $repo->find( $id );

	return (int) $reload->get_id();
}

function smoke_archive( PromotionService $service, PromotionRepository $repo, int $id ): void {
	$p = $repo->find( $id );
	if ( $p === null ) {
		return;
	}
	if ( $p->get_status() === PromotionStatus::ACTIVE || $p->get_status() === PromotionStatus::PAUSED ) {
		try {
			$service->change_status( $p, PromotionStatus::ARCHIVED );
		} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}
	}
}
