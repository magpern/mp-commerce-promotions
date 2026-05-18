<?php
/**
 * WP-CLI: Cart/Checkout Blocks browser certification harness (server + Store API checks).
 *
 * Usage:
 *   docker compose run --rm -e MP_CP_BLOCK_QA_PRODUCT_ID=4338 --no-deps wpcli \
 *     eval-file wp-content/plugins/mp-commerce-promotions/scripts/blocks-browser-cert.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionCodeFactory;
use MP\CommercePromotions\Domain\PromotionCodeRepository;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\BlockTestPages;
use MP\CommercePromotions\Service\PromotionService;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Woo\CartSessionHelper;
use MP\CommercePromotions\Woo\OrderPromotionRecorder;
use MP\CommercePromotions\Woo\OrderPromotionState;

$GLOBALS['mp_cp_block_cert'] = array(
	'pass'  => 0,
	'fail'  => 0,
	'rows'  => array(),
	'orders' => array(),
);

function block_cert_row( string $scenario, string $status, string $notes = '' ): void {
	$GLOBALS['mp_cp_block_cert']['rows'][] = array(
		'scenario' => $scenario,
		'status'   => $status,
		'notes'    => $notes,
	);
	if ( $status === 'pass' ) {
		++$GLOBALS['mp_cp_block_cert']['pass'];
		echo "PASS {$scenario}" . ( $notes !== '' ? " — {$notes}" : '' ) . "\n";
	} else {
		++$GLOBALS['mp_cp_block_cert']['fail'];
		echo strtoupper( $status ) . " {$scenario}" . ( $notes !== '' ? " — {$notes}" : '' ) . "\n";
	}
}

function block_cert_pause_all( PromotionRepository $repo, PromotionService $service, int $except_id = 0 ): void {
	foreach ( $repo->find_filtered( array( 'search' => BlockTestPages::QA_PROMOTION_PREFIX, 'limit' => 30 ) ) as $p ) {
		$id = $p->get_id();
		if ( $id === null || $id <= 0 || $id === $except_id ) {
			continue;
		}
		if ( $p->get_status() === PromotionStatus::ACTIVE ) {
			try {
				$service->change_status( $p, PromotionStatus::PAUSED );
			} catch ( Throwable $e ) { // phpcs:ignore
			}
		}
	}
}

function block_cert_activate( PromotionRepository $repo, PromotionService $service, int $promo_id ): ?\MP\CommercePromotions\Domain\Promotion {
	$promo = $repo->find( $promo_id );
	if ( $promo === null ) {
		return null;
	}
	if ( $promo->get_status() === PromotionStatus::PAUSED ) {
		$service->change_status( $promo, PromotionStatus::ACTIVE );
		$promo = $repo->find( $promo_id );
	}

	return $promo;
}

function block_cert_prepare_cart( int $product_id, int $qty = 1 ): bool {
	if ( ! function_exists( 'wc_load_cart' ) || ! function_exists( 'WC' ) ) {
		return false;
	}
	wc_load_cart();
	$cart = WC()->cart;
	$cart->empty_cart( true );
	$key = $cart->add_to_cart( $product_id, $qty );
	if ( ! $key ) {
		return false;
	}
	$cart->calculate_totals();

	return true;
}

/**
 * @return list<array{name: string, amount: float}>
 */
function block_cert_cart_fees(): array {
	$fees = array();
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return $fees;
	}
	foreach ( WC()->cart->get_fees() as $fee ) {
		if ( is_object( $fee ) ) {
			$fees[] = array(
				'name'   => (string) ( $fee->name ?? '' ),
				'amount' => (float) ( $fee->amount ?? 0 ),
			);
		}
	}

	return $fees;
}

function block_cert_store_api_cart_has_fees(): bool {
	if ( ! function_exists( 'rest_do_request' ) ) {
		return false;
	}
	$request = new WP_REST_Request( 'GET', '/wc/store/v1/cart' );
	$response = rest_do_request( $request );
	if ( $response->is_error() ) {
		return false;
	}
	$data = $response->get_data();
	if ( ! is_array( $data ) ) {
		return false;
	}
	$fees = $data['fees'] ?? array();

	return is_array( $fees ) && count( $fees ) > 0;
}

function block_cert_create_order_from_cart( OrderPromotionRecorder $recorder ): ?int {
	if ( ! function_exists( 'wc_create_order' ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
		return null;
	}
	$cart = WC()->cart;
	$order = wc_create_order();
	if ( ! $order ) {
		return null;
	}
	foreach ( $cart->get_cart() as $item ) {
		$product_id = (int) ( $item['product_id'] ?? 0 );
		$qty        = (int) ( $item['quantity'] ?? 1 );
		if ( $product_id > 0 ) {
			$order->add_product( wc_get_product( $product_id ), $qty );
		}
	}
	foreach ( $cart->get_fees() as $fee ) {
		if ( is_object( $fee ) ) {
			$order->add_fee( (string) ( $fee->name ?? 'Fee' ), (float) ( $fee->amount ?? 0 ) );
		}
	}
	$order->set_payment_method( 'cod' );
	if ( WC()->cart ) {
		WC()->cart->calculate_totals();
	}
	$order->calculate_totals();
	$order->save();
	do_action( 'woocommerce_checkout_create_order', $order, array() );
	do_action( 'woocommerce_checkout_order_processed', (int) $order->get_id(), $order, array() );

	return (int) $order->get_id();
}

function block_cert_reverse_order( OrderPromotionRecorder $recorder, int $order_id ): void {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}
	$order->update_status( 'cancelled' );
	do_action( 'woocommerce_order_status_cancelled', $order_id, $order );
}

$product_id = 4338;
if ( getenv( 'MP_CP_BLOCK_QA_PRODUCT_ID' ) !== false ) {
	$product_id = (int) getenv( 'MP_CP_BLOCK_QA_PRODUCT_ID' );
}

/**
 * @return array{fee: int, fixed: int, shipping: int, gift: int, line: int}
 */
function block_cert_resolve_promo_ids( PromotionRepository $repo ): array {
	$map = array(
		'fee'      => 0,
		'fixed'    => 0,
		'shipping' => 0,
		'gift'     => 0,
		'line'     => 0,
	);
	$rows = $repo->find_filtered( array( 'search' => BlockTestPages::QA_PROMOTION_PREFIX, 'limit' => 50 ) );
	usort(
		$rows,
		static function ( $a, $b ): int {
			return ( $b->get_id() ?? 0 ) <=> ( $a->get_id() ?? 0 );
		}
	);
	foreach ( $rows as $p ) {
		$id = $p->get_id();
		if ( $id === null || $id <= 0 ) {
			continue;
		}
		$status = $p->get_status();
		if ( $status === PromotionStatus::ARCHIVED || $status === PromotionStatus::DRAFT ) {
			continue;
		}
		$name = $p->get_name();
		if ( str_contains( $name, 'Fee 10%' ) && $map['fee'] === 0 ) {
			$map['fee'] = $id;
		} elseif ( str_contains( $name, 'Fixed 5' ) && $map['fixed'] === 0 ) {
			$map['fixed'] = $id;
		} elseif ( str_contains( $name, 'Free shipping' ) && $map['shipping'] === 0 ) {
			$map['shipping'] = $id;
		} elseif ( str_contains( $name, 'Free gift' ) && $map['gift'] === 0 ) {
			$map['gift'] = $id;
		} elseif ( str_contains( $name, 'Line 10%' ) && $map['line'] === 0 ) {
			$map['line'] = $id;
		}
	}

	return $map;
}

global $wpdb;
$plugin = new \MP\CommercePromotions\Plugin();
$plugin->init();

$repo = new PromotionRepository( $wpdb );
$promo_ids = block_cert_resolve_promo_ids( $repo );
$fee_promo_id      = $promo_ids['fee'];
$fixed_promo_id    = $promo_ids['fixed'];
$shipping_promo_id = $promo_ids['shipping'];
$gift_promo_id     = $promo_ids['gift'];
$line_promo_id     = $promo_ids['line'];

if ( $fee_promo_id <= 0 || $fixed_promo_id <= 0 ) {
	echo "ERROR: Run blocks-compatibility-smoke.php first to create QA promotions.\n";
	echo 'Resolved: ' . wp_json_encode( $promo_ids ) . "\n";
	exit( 1 );
}

echo 'QA promo IDs: ' . wp_json_encode( $promo_ids ) . "\n";

$red_repo = new RedemptionRepository( $wpdb );
$code_repo = new PromotionCodeRepository( $wpdb );
$audit    = new \MP\CommercePromotions\Domain\AuditLogRepository( $wpdb );
$audit_log = new \MP\CommercePromotions\Service\AuditLogger( $audit );
$factory  = new \MP\CommercePromotions\Domain\PromotionFactory();
$service  = new PromotionService( $repo, $factory, $audit_log );
$recorder = new OrderPromotionRecorder( $red_repo, $repo, $code_repo, $audit_log );
$settings = new Settings();

echo "MP CP Blocks browser certification (server + Store API)\n";
echo str_repeat( '-', 56 ) . "\n";

// 1. Fee percentage.
block_cert_pause_all( $repo, $service );
block_cert_activate( $repo, $service, $fee_promo_id );
if ( block_cert_prepare_cart( $product_id ) ) {
	$fees = block_cert_cart_fees();
	$has_neg = false;
	foreach ( $fees as $fee ) {
		if ( $fee['amount'] < 0 ) {
			$has_neg = true;
		}
	}
	$store_ok = block_cert_store_api_cart_has_fees();
	$usage_before = (int) $repo->find( $fee_promo_id )->get_usage_count();
	$order_id     = block_cert_create_order_from_cart( $recorder );
	$usage_after  = (int) $repo->find( $fee_promo_id )->get_usage_count();
	$meta_ok      = false;
	if ( $order_id > 0 ) {
		$order   = wc_get_order( $order_id );
		$meta_ok = $order && OrderPromotionState::get_applied_promotions( $order ) !== array();
		$GLOBALS['mp_cp_block_cert']['orders']['fee_pct'] = $order_id;
		block_cert_reverse_order( $recorder, $order_id );
		$usage_rev = (int) $repo->find( $fee_promo_id )->get_usage_count();
		block_cert_row(
			'fee_percentage_checkout_reversal',
			( $usage_after === $usage_before + 1 && $usage_rev === $usage_before ) ? 'pass' : 'partial',
			"order {$order_id} usage {$usage_before}→{$usage_after}→{$usage_rev}"
		);
	}
	block_cert_row(
		'fee_percentage_cart',
		( $has_neg && $store_ok ) ? 'pass' : ( $has_neg ? 'partial' : 'fail' ),
		'fees=' . wp_json_encode( $fees ) . ' store_api_fees=' . ( $store_ok ? 'yes' : 'no' )
	);
	block_cert_row( 'fee_percentage_order_meta', $meta_ok ? 'pass' : 'fail', $order_id > 0 ? "order {$order_id}" : 'no order' );
}

block_cert_pause_all( $repo, $service, $fixed_promo_id );
block_cert_activate( $repo, $service, $fixed_promo_id );
if ( block_cert_prepare_cart( $product_id ) ) {
	$fees    = block_cert_cart_fees();
	$fixed_ok = false;
	foreach ( $fees as $fee ) {
		if ( $fee['amount'] < 0 ) {
			$fixed_ok = true;
		}
	}
	block_cert_row( 'fee_fixed_cart', $fixed_ok ? 'pass' : 'fail', wp_json_encode( $fees ) );
}

block_cert_pause_all( $repo, $service );

// 2. Stacked fees.
block_cert_pause_all( $repo, $service );
$p183 = $repo->find( $fee_promo_id );
$p184 = $repo->find( $fixed_promo_id );
if ( $p183 && $p184 ) {
	$repo->update( $p183->with_application_rules( PromotionApplicationMode::STACKABLE, $p183->should_stop_processing(), $p183->get_max_applications() ) );
	$repo->update( $p184->with_application_rules( PromotionApplicationMode::STACKABLE, $p184->should_stop_processing(), $p184->get_max_applications() ) );
	block_cert_activate( $repo, $service, $fee_promo_id );
	block_cert_activate( $repo, $service, $fixed_promo_id );
	if ( block_cert_prepare_cart( $product_id ) ) {
		$fees       = block_cert_cart_fees();
		$neg_count  = 0;
		foreach ( $fees as $fee ) {
			if ( $fee['amount'] < 0 ) {
				++$neg_count;
			}
		}
		block_cert_row( 'stacked_fees', $neg_count >= 2 ? 'pass' : 'partial', 'negative_fees=' . $neg_count );
	}
	$p183r = $repo->find( $fee_promo_id );
	$p184r = $repo->find( $fixed_promo_id );
	if ( $p183r && $p184r ) {
		$repo->update( $p183r->with_application_rules( PromotionApplicationMode::EXCLUSIVE, $p183r->should_stop_processing(), $p183r->get_max_applications() ) );
		$repo->update( $p184r->with_application_rules( PromotionApplicationMode::EXCLUSIVE, $p184r->should_stop_processing(), $p184r->get_max_applications() ) );
	}
}

block_cert_pause_all( $repo, $service );

// 3. Promotion code.
block_cert_pause_all( $repo, $service, $fixed_promo_id );
$code_plain = 'BLOCKQA5';
$existing   = $code_repo->find_by_plain_code( $code_plain );
if ( $existing !== null && $existing->get_promotion_id() !== $fixed_promo_id ) {
	$code_plain = 'BLOCKQA' . (string) $fixed_promo_id;
	$existing   = $code_repo->find_by_plain_code( $code_plain );
}
if ( $existing === null ) {
	$code_factory = new PromotionCodeFactory();
	$entity       = $code_factory->create_manual_code( $fixed_promo_id, $code_plain, 50, null );
	$code_repo->insert( $entity );
}
block_cert_activate( $repo, $service, $fixed_promo_id );
if ( block_cert_prepare_cart( $product_id ) && function_exists( 'WC' ) ) {
	WC()->cart->apply_coupon( $code_plain );
	WC()->cart->calculate_totals();
	$fees     = block_cert_cart_fees();
	$code_ok  = false;
	foreach ( $fees as $fee ) {
		if ( $fee['amount'] < 0 ) {
			$code_ok = true;
		}
	}
	$applied = WC()->cart->get_applied_coupons();
	block_cert_row(
		'promotion_code_coupon',
		( $code_ok && in_array( strtolower( $code_plain ), array_map( 'strtolower', $applied ), true ) ) ? 'pass' : 'fail',
		'coupons=' . wp_json_encode( $applied ) . ' fees=' . wp_json_encode( $fees )
	);
}

block_cert_pause_all( $repo, $service );

// 4. Free shipping (set address so shipping can calculate).
block_cert_activate( $repo, $service, $shipping_promo_id );
if ( block_cert_prepare_cart( $product_id ) ) {
	if ( function_exists( 'WC' ) && WC()->customer ) {
		WC()->customer->set_billing_country( 'LT' );
		WC()->customer->set_shipping_country( 'LT' );
		WC()->customer->set_billing_city( 'Vilnius' );
		WC()->customer->set_shipping_city( 'Vilnius' );
		WC()->customer->set_billing_postcode( '01100' );
		WC()->customer->set_shipping_postcode( '01100' );
	}
	WC()->cart->calculate_totals();
	$fees = block_cert_cart_fees();
	$ship_fee = false;
	foreach ( $fees as $fee ) {
		if ( stripos( $fee['name'], 'shipping' ) !== false || $fee['amount'] < 0 ) {
			$ship_fee = true;
		}
	}
	block_cert_row(
		'free_shipping_offset',
		$ship_fee ? 'pass' : 'partial',
		'shipping_total=' . (string) WC()->cart->get_shipping_total() . ' fees=' . wp_json_encode( $fees )
	);
}

block_cert_pause_all( $repo, $service );

// 5. Free gift.
block_cert_activate( $repo, $service, $gift_promo_id );
if ( block_cert_prepare_cart( $product_id ) ) {
	$count1 = WC()->cart->get_cart_contents_count();
	WC()->cart->calculate_totals();
	$count2 = WC()->cart->get_cart_contents_count();
	$gift_zero = false;
	foreach ( WC()->cart->get_cart() as $line ) {
		$is_gift = ! empty( $line['mp_cp_free_gift'] );
		if ( $is_gift && isset( $line['data'] ) && is_object( $line['data'] ) && (float) $line['data']->get_price() === 0.0 ) {
			$gift_zero = true;
		}
	}
	$usage_before = (int) $repo->find( $gift_promo_id )->get_usage_count();
	$order_id     = block_cert_create_order_from_cart( $recorder );
	$usage_after  = (int) $repo->find( $gift_promo_id )->get_usage_count();
	if ( $order_id > 0 ) {
		$GLOBALS['mp_cp_block_cert']['orders']['gift'] = $order_id;
		block_cert_reverse_order( $recorder, $order_id );
	}
	block_cert_row(
		'free_gift',
		( $gift_zero && $count2 >= $count1 ) ? 'pass' : 'fail',
		"lines={$count2} gift_zero=" . ( $gift_zero ? 'yes' : 'no' ) . " order={$order_id} usage {$usage_before}→{$usage_after}"
	);
}

block_cert_pause_all( $repo, $service );

// 6. Line item mode.
block_cert_activate( $repo, $service, $line_promo_id );
if ( block_cert_prepare_cart( $product_id ) ) {
	$line_alloc = CartSessionHelper::get_line_allocations();
	$has_line   = is_array( $line_alloc ) && ! empty( $line_alloc['line_discounts'] );
	block_cert_row( 'line_item_mode', $has_line ? 'pass' : 'partial', $has_line ? 'line_discounts set' : 'no line allocations' );
}

block_cert_pause_all( $repo, $service );

// 10. Safe mode.
$settings->set_safe_mode_enabled( true );
if ( block_cert_prepare_cart( $product_id ) ) {
	$fees_safe = block_cert_cart_fees();
	block_cert_row( 'safe_mode_no_auto_fees', count( $fees_safe ) === 0 ? 'pass' : 'fail', 'fee_count=' . count( $fees_safe ) );
}
$settings->set_safe_mode_enabled( false );
block_cert_pause_all( $repo, $service );

echo str_repeat( '-', 56 ) . "\n";
echo 'Summary: ' . $GLOBALS['mp_cp_block_cert']['pass'] . ' pass, ' . $GLOBALS['mp_cp_block_cert']['fail'] . " fail/partial\n";
echo 'Orders: ' . wp_json_encode( $GLOBALS['mp_cp_block_cert']['orders'] ) . "\n";

$fail = $GLOBALS['mp_cp_block_cert']['fail'];
exit( $fail > 0 ? 1 : 0 );
