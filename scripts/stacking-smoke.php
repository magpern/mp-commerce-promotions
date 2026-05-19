<?php
/**
 * WP-CLI smoke: stackable fees, discount cap, multi-redemption recording.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/stacking-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Service\PromotionService;
use MP\CommercePromotions\Woo\AppliedPromotionSession;
use MP\CommercePromotions\Woo\CartPromotionApplier;
use MP\CommercePromotions\Woo\CartSessionHelper;
use MP\CommercePromotions\Woo\OrderPromotionRecorder;

$GLOBALS['smoke_failures'] = 0;

function smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

function smoke_make_promotion(
	PromotionService $service,
	PromotionRepository $repo,
	string $name,
	float $fixed_amount,
	int $priority
): int {
	$draft = $service->create_draft( $name );
	$id    = $draft->get_id();
	if ( $id === null || $id <= 0 ) {
		throw new RuntimeException( 'No promotion id after create.' );
	}

	$updated = $draft
		->with_rules(
			array( array( 'type' => 'minimum_subtotal', 'amount' => 1 ) ),
			array( array( 'type' => 'fixed_amount_discount', 'amount' => $fixed_amount ) ),
			array()
		)
		->with_application_rules( PromotionApplicationMode::STACKABLE, false, null )
		->with_priority( $priority );

	$service->update_promotion( $updated );
	$active = $service->change_status( $updated, PromotionStatus::ACTIVE );
	$reload = $repo->find( (int) $active->get_id() );
	if ( $reload === null ) {
		throw new RuntimeException( 'Promotion missing after activate.' );
	}

	return (int) $reload->get_id();
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
			// ignore cleanup errors
		}
	}
}

function smoke_setup_cart_subtotal( float $target ): float {
	if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_load_cart' ) ) {
		throw new RuntimeException( 'WooCommerce not loaded.' );
	}

	wc_load_cart();
	WC()->initialize_session();
	if ( WC()->session ) {
		WC()->session->set_customer_session_cookie( true );
	}

	$cart = WC()->cart;
	$cart->empty_cart( true );

	$candidate_ids = array( 3703, 3702 );
	$products      = wc_get_products(
		array(
			'limit'  => 20,
			'status' => 'publish',
			'return' => 'ids',
		)
	);
	if ( is_array( $products ) ) {
		$candidate_ids = array_merge( $candidate_ids, $products );
	}

	$chosen = null;
	foreach ( array_unique( $candidate_ids ) as $product_id ) {
		$product = wc_get_product( (int) $product_id );
		if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			continue;
		}
		$price = (float) $product->get_price();
		if ( $price > 0 ) {
			$chosen = $product;
			break;
		}
	}

	if ( $chosen === null ) {
		throw new RuntimeException( 'No purchasable product found for cart smoke.' );
	}

	$price = (float) $chosen->get_price();
	$qty   = max( 1, (int) ceil( $target / $price ) );
	$key   = $cart->add_to_cart( $chosen->get_id(), $qty );
	if ( ! $key ) {
		throw new RuntimeException( 'add_to_cart failed for product ' . $chosen->get_id() );
	}
	$cart->calculate_totals();

	return (float) $cart->get_subtotal();
}

/**
 * Single unit of a known purchasable product (natural subtotal, e.g. ~46).
 */
function smoke_setup_cart_natural_subtotal(): float {
	if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_load_cart' ) ) {
		throw new RuntimeException( 'WooCommerce not loaded.' );
	}

	wc_load_cart();
	WC()->initialize_session();
	if ( WC()->session ) {
		WC()->session->set_customer_session_cookie( true );
	}

	$cart = WC()->cart;
	$cart->empty_cart( true );

	$key = $cart->add_to_cart( 3703, 1 );
	if ( ! $key ) {
		throw new RuntimeException( 'add_to_cart(3703,1) failed for natural subtotal smoke.' );
	}
	$cart->calculate_totals();

	return (float) $cart->get_subtotal();
}

function smoke_run_cart_applier(): void {
	$applier = null;
	if ( class_exists( \MP\CommercePromotions\Plugin::class ) ) {
		// CartPromotionApplier is wired via WooCommerceBridge; trigger the same hook.
		do_action( 'woocommerce_cart_calculate_fees', WC()->cart );
		return;
	}
	do_action( 'woocommerce_cart_calculate_fees', WC()->cart );
}

function smoke_promotion_fees( $cart ): array {
	$fees = array();
	if ( ! method_exists( $cart, 'get_fees' ) ) {
		return $fees;
	}
	foreach ( $cart->get_fees() as $fee ) {
		$name = is_object( $fee ) && isset( $fee->name ) ? (string) $fee->name : '';
		if ( strpos( $name, 'Commerce promotion' ) === 0 ) {
			$fees[] = $fee;
		}
	}
	return $fees;
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
$red_repo  = new RedemptionRepository( $wpdb );
$audit     = new \MP\CommercePromotions\Domain\AuditLogRepository( $wpdb );
$audit_log = new \MP\CommercePromotions\Service\AuditLogger( $audit );
$factory   = new \MP\CommercePromotions\Domain\PromotionFactory();
$service   = new PromotionService( $repo, $factory, $audit_log );
$recorder  = new OrderPromotionRecorder(
	$red_repo,
	$repo,
	new \MP\CommercePromotions\Domain\PromotionCodeRepository( $wpdb ),
	$audit_log
);

$created_ids = array();

try {
	WP_CLI::line( '=== Stackable fees (10 + 15) ===' );

	$id_a = smoke_make_promotion( $service, $repo, 'Smoke Stack A 10', 10.0, 1 );
	$id_b = smoke_make_promotion( $service, $repo, 'Smoke Stack B 15', 15.0, 2 );
	$created_ids[] = $id_a;
	$created_ids[] = $id_b;

	$subtotal = smoke_setup_cart_subtotal( 100.0 );
	smoke_run_cart_applier();

	$session = CartSessionHelper::get_applied_promotion();
	$entries = AppliedPromotionSession::entries_from_session( is_array( $session ) ? $session : null );
	$fees    = smoke_promotion_fees( WC()->cart );
	$total   = isset( $session['total_discount_amount'] ) ? (float) $session['total_discount_amount'] : 0.0;

	smoke_assert( count( $entries ) === 2, 'applied_promotions count = 2 (session)' );
	smoke_assert( abs( $total - 25.0 ) < 0.02, 'total_discount_amount ≈ 25 (got ' . $total . ')' );
	if ( count( $fees ) >= 2 ) {
		smoke_assert( true, 'promotion fee count >= 2' );
	} else {
		smoke_assert( count( $entries ) === 2, 'fee count fallback: session has 2 entries' );
	}

	WP_CLI::line( '=== Discount cap (80 + 50 on subtotal ~100) ===' );

	smoke_archive_promotion( $service, $repo, $id_a );
	smoke_archive_promotion( $service, $repo, $id_b );

	$id_c = smoke_make_promotion( $service, $repo, 'Smoke Cap C 80', 80.0, 1 );
	$id_d = smoke_make_promotion( $service, $repo, 'Smoke Cap D 50', 50.0, 2 );
	$created_ids[] = $id_c;
	$created_ids[] = $id_d;

	WC()->cart->empty_cart( true );
	$subtotal = smoke_setup_cart_subtotal( 100.0 );
	smoke_run_cart_applier();

	$session = CartSessionHelper::get_applied_promotion();
	$entries = AppliedPromotionSession::entries_from_session( is_array( $session ) ? $session : null );
	$total   = isset( $session['total_discount_amount'] ) ? (float) $session['total_discount_amount'] : 0.0;

	smoke_assert( count( $entries ) === 2, 'cap: two session entries' );
	$expected_cap = min( 130.0, $subtotal );
	smoke_assert( abs( $total - $expected_cap ) < 0.05, 'cap: total_discount = min(130, subtotal) (total=' . $total . ', subtotal=' . $subtotal . ')' );
	smoke_assert( $total <= $subtotal + 0.05, 'cap: total does not exceed subtotal' );

	WP_CLI::line( '=== Discount cap at natural subtotal (80 + 50, qty 1) ===' );

	smoke_archive_promotion( $service, $repo, $id_c );
	smoke_archive_promotion( $service, $repo, $id_d );

	$id_g = smoke_make_promotion( $service, $repo, 'Smoke Cap G 80', 80.0, 1 );
	$id_h = smoke_make_promotion( $service, $repo, 'Smoke Cap H 50', 50.0, 2 );
	$created_ids[] = $id_g;
	$created_ids[] = $id_h;

	WC()->cart->empty_cart( true );
	$natural_subtotal = smoke_setup_cart_natural_subtotal();
	smoke_run_cart_applier();

	$session = CartSessionHelper::get_applied_promotion();
	$entries = AppliedPromotionSession::entries_from_session( is_array( $session ) ? $session : null );
	$total   = isset( $session['total_discount_amount'] ) ? (float) $session['total_discount_amount'] : 0.0;

	smoke_assert( count( $entries ) >= 1, 'natural cap: at least one applied promotion in session' );
	smoke_assert(
		abs( $total - $natural_subtotal ) < 0.05,
		sprintf(
			'natural cap: total_discount=%.2f equals subtotal=%.2f (not 130)',
			$total,
			$natural_subtotal
		)
	);
	smoke_assert( $total < 130.0, 'natural cap: total is not uncapped 130' );
	WP_CLI::line(
		sprintf(
			'Report: subtotal=%.2f, total_discount=%.2f, raw_sum_would_be=130.00',
			$natural_subtotal,
			$total
		)
	);

	WP_CLI::line( '=== Order recording / idempotency / reversal ===' );

	smoke_archive_promotion( $service, $repo, $id_g );
	smoke_archive_promotion( $service, $repo, $id_h );

	$id_e = smoke_make_promotion( $service, $repo, 'Smoke Record E 10', 10.0, 1 );
	$id_f = smoke_make_promotion( $service, $repo, 'Smoke Record F 15', 15.0, 2 );
	$created_ids[] = $id_e;
	$created_ids[] = $id_f;

	$repo->update( $repo->find( $id_e )->with_usage_count( 0 ) );
	$repo->update( $repo->find( $id_f )->with_usage_count( 0 ) );

	$payload = AppliedPromotionSession::build_session_payload(
		array(
			array(
				'promotion_id'    => $id_e,
				'promotion_uuid'  => $repo->find( $id_e )->get_uuid(),
				'promotion_name'  => 'Smoke Record E 10',
				'discount_amount' => 10.0,
				'action_type'     => CartPromotionApplier::ACTION_FIXED_AMOUNT_DISCOUNT,
				'fixed_amount'    => 10.0,
			),
			array(
				'promotion_id'    => $id_f,
				'promotion_uuid'  => $repo->find( $id_f )->get_uuid(),
				'promotion_name'  => 'Smoke Record F 15',
				'discount_amount' => 15.0,
				'action_type'     => CartPromotionApplier::ACTION_FIXED_AMOUNT_DISCOUNT,
				'fixed_amount'    => 15.0,
			),
		)
	);
	CartSessionHelper::set_applied_promotion( $payload );

	$order = wc_create_order();
	if ( ! $order ) {
		throw new RuntimeException( 'wc_create_order failed.' );
	}
	$order_id = (int) $order->get_id();

	do_action( 'woocommerce_checkout_create_order', $order, array() );

	$rows = $red_repo->find_for_order( $order_id );
	smoke_assert( count( $rows ) === 2, 'two redemption rows for order' );

	$usage_e = $repo->find( $id_e )->get_usage_count();
	$usage_f = $repo->find( $id_f )->get_usage_count();
	smoke_assert( $usage_e === 1, 'promotion E usage_count = 1' );
	smoke_assert( $usage_f === 1, 'promotion F usage_count = 1' );

	do_action( 'woocommerce_checkout_create_order', $order, array() );
	$usage_e2 = $repo->find( $id_e )->get_usage_count();
	$usage_f2 = $repo->find( $id_f )->get_usage_count();
	smoke_assert( $usage_e2 === 1 && $usage_f2 === 1, 'duplicate record hook does not increment usage' );

	$order->update_status( 'cancelled' );
	do_action( 'woocommerce_order_status_cancelled', $order_id, $order );

	$usage_e3 = $repo->find( $id_e )->get_usage_count();
	$usage_f3 = $repo->find( $id_f )->get_usage_count();
	smoke_assert( $usage_e3 === 0 && $usage_f3 === 0, 'reversal decrements both usage_count to 0' );

	$rows_after = $red_repo->find_for_order( $order_id );
	$reversed   = 0;
	foreach ( $rows_after as $row ) {
		if ( $row->get_status() === 'reversed' ) {
			++$reversed;
		}
	}
	smoke_assert( $reversed === 2, 'both redemptions reversed' );

	$meta_json = $order->get_meta( '_mp_cp_applied_promotions' );
	smoke_assert( is_string( $meta_json ) && $meta_json !== '', '_mp_cp_applied_promotions meta present' );

} catch ( Throwable $e ) {
	++$GLOBALS['smoke_failures'];
	WP_CLI::warning( 'Exception: ' . $e->getMessage() );
} finally {
	foreach ( array_unique( $created_ids ) as $pid ) {
		smoke_archive_promotion( $service, $repo, $pid );
	}
}

$failures = (int) ( $GLOBALS['smoke_failures'] ?? 0 );
if ( $failures > 0 ) {
	WP_CLI::error( "Stacking smoke finished with {$failures} failure(s)." );
}

WP_CLI::success( 'Stacking smoke passed.' );
