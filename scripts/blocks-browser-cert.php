<?php
/**
 * WP-CLI: Cart/Checkout Blocks certification (focused server + Store API checks).
 *
 * Usage:
 *   docker compose run --rm --no-deps wpcli eval-file wp-content/plugins/mp-commerce-promotions/scripts/blocks-browser-cert.php
 *
 * Optional: MP_CP_BLOCK_CERT_SCENARIOS=stacked,code,gift,line (comma-separated)
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
use MP\CommercePromotions\Service\BlockQaPromotionSetup;
use MP\CommercePromotions\Service\BlockTestPages;
use MP\CommercePromotions\Service\PromotionService;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Woo\CartSessionHelper;
use MP\CommercePromotions\Woo\LineDiscountPlanCache;
use MP\CommercePromotions\Woo\OrderPromotionRecorder;
use MP\CommercePromotions\Woo\OrderPromotionState;

$GLOBALS['mp_cp_block_cert'] = array(
	'pass'   => 0,
	'fail'   => 0,
	'rows'   => array(),
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
		return;
	}
	++$GLOBALS['mp_cp_block_cert']['fail'];
	echo strtoupper( $status ) . " {$scenario}" . ( $notes !== '' ? " — {$notes}" : '' ) . "\n";
}

function block_cert_should_run( string $scenario ): bool {
	$filter = getenv( 'MP_CP_BLOCK_CERT_SCENARIOS' );
	if ( $filter === false || trim( (string) $filter ) === '' ) {
		return true;
	}
	$parts = array_map( 'trim', explode( ',', (string) $filter ) );

	return in_array( $scenario, $parts, true );
}

function block_cert_pause_all( PromotionRepository $repo, PromotionService $service, int $except_id = 0 ): void {
	foreach ( $repo->find_active_for_planner( 200 ) as $p ) {
		$id = $p->get_id();
		if ( $id === null || $id <= 0 || $id === $except_id ) {
			continue;
		}
		try {
			$service->change_status( $p, PromotionStatus::PAUSED );
		} catch ( Throwable $e ) { // phpcs:ignore
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

function block_cert_session_applied_count(): int {
	$session = CartSessionHelper::get_applied_promotion();
	if ( ! is_array( $session ) ) {
		return 0;
	}
	if ( isset( $session['applied_promotions'] ) && is_array( $session['applied_promotions'] ) ) {
		return count( $session['applied_promotions'] );
	}

	return $session !== array() ? 1 : 0;
}

function block_cert_store_api_cart_has_fees(): bool {
	if ( ! function_exists( 'rest_do_request' ) ) {
		return false;
	}
	$request  = new WP_REST_Request( 'GET', '/wc/store/v1/cart' );
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
	$cart  = WC()->cart;
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
	WC()->cart->calculate_totals();
	$order->calculate_totals();
	$order->save();
	do_action( 'woocommerce_checkout_create_order', $order, array() );
	do_action( 'woocommerce_checkout_order_processed', (int) $order->get_id(), $order, array() );

	return (int) $order->get_id();
}

function block_cert_reverse_order( int $order_id ): void {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}
	$order->update_status( 'cancelled' );
	do_action( 'woocommerce_order_status_cancelled', $order_id, $order );
}

/**
 * @return array{fee: int, fixed: int, stack_pct: int, stack_fixed: int, shipping: int, gift: int, line: int, paid_product_id: int, gift_product_id: int}
 */
function block_cert_resolve_promo_ids( PromotionRepository $repo ): array {
	$map = array(
		'fee'             => 0,
		'fixed'           => 0,
		'stack_pct'       => 0,
		'stack_fixed'     => 0,
		'shipping'        => 0,
		'gift'            => 0,
		'line'            => 0,
		'paid_product_id' => (int) get_option( BlockQaPromotionSetup::OPTION_PAID_PRODUCT_ID, BlockQaPromotionSetup::DEFAULT_PAID_PRODUCT_ID ),
		'gift_product_id' => (int) get_option( BlockQaPromotionSetup::OPTION_GIFT_PRODUCT_ID, 0 ),
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
		} elseif ( str_contains( $name, 'Stack 3%' ) && $map['stack_pct'] === 0 ) {
			$map['stack_pct'] = $id;
		} elseif ( str_contains( $name, 'Stack 2 fixed' ) && $map['stack_fixed'] === 0 ) {
			$map['stack_fixed'] = $id;
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

$repo      = new PromotionRepository( $wpdb );
$promo_ids = block_cert_resolve_promo_ids( $repo );
$paid_id   = $promo_ids['paid_product_id'] > 0 ? $promo_ids['paid_product_id'] : BlockQaPromotionSetup::DEFAULT_PAID_PRODUCT_ID;

if ( $promo_ids['fee'] <= 0 || $promo_ids['stack_pct'] <= 0 ) {
	echo "ERROR: Run blocks-compatibility-smoke.php first.\n";
	echo 'Resolved: ' . wp_json_encode( $promo_ids ) . "\n";
	exit( 1 );
}

echo 'QA promo IDs: ' . wp_json_encode( $promo_ids ) . "\n";
echo "Paid product ID: {$paid_id}\n";

$red_repo  = new RedemptionRepository( $wpdb );
$code_repo = new PromotionCodeRepository( $wpdb );
$audit     = new \MP\CommercePromotions\Domain\AuditLogRepository( $wpdb );
$audit_log = new \MP\CommercePromotions\Service\AuditLogger( $audit );
$factory   = new \MP\CommercePromotions\Domain\PromotionFactory();
$service   = new PromotionService( $repo, $factory, $audit_log );
$recorder  = new OrderPromotionRecorder( $red_repo, $repo, $code_repo, $audit_log );
$settings  = new Settings();

echo "MP CP Blocks browser certification\n";
echo str_repeat( '-', 56 ) . "\n";

if ( block_cert_should_run( 'fee' ) ) {
	block_cert_pause_all( $repo, $service );
	block_cert_activate( $repo, $service, $promo_ids['fee'] );
	if ( block_cert_prepare_cart( $paid_id ) ) {
		$fees      = block_cert_cart_fees();
		$has_neg   = false;
		foreach ( $fees as $fee ) {
			if ( $fee['amount'] < 0 ) {
				$has_neg = true;
			}
		}
		$store_ok     = block_cert_store_api_cart_has_fees();
		$usage_before = (int) $repo->find( $promo_ids['fee'] )->get_usage_count();
		$order_id     = block_cert_create_order_from_cart( $recorder );
		$usage_after  = (int) $repo->find( $promo_ids['fee'] )->get_usage_count();
		$meta_ok      = false;
		if ( $order_id > 0 ) {
			$order   = wc_get_order( $order_id );
			$meta_ok = $order && OrderPromotionState::get_applied_promotions( $order ) !== array();
			$GLOBALS['mp_cp_block_cert']['orders']['fee'] = $order_id;
			block_cert_reverse_order( $order_id );
			$usage_rev = (int) $repo->find( $promo_ids['fee'] )->get_usage_count();
			block_cert_row(
				'fee_checkout_reversal',
				( $usage_after === $usage_before + 1 && $usage_rev === $usage_before ) ? 'pass' : 'partial',
				"order {$order_id} usage {$usage_before}→{$usage_after}→{$usage_rev}"
			);
		}
		block_cert_row(
			'fee_cart',
			( $has_neg && $store_ok ) ? 'pass' : ( $has_neg ? 'partial' : 'fail' ),
			wp_json_encode( $fees )
		);
		block_cert_row( 'fee_order_meta', $meta_ok ? 'pass' : 'fail', $order_id > 0 ? "order {$order_id}" : 'no order' );
	}
	block_cert_pause_all( $repo, $service );
}

if ( block_cert_should_run( 'stacked' ) ) {
	block_cert_pause_all( $repo, $service );
	block_cert_activate( $repo, $service, $promo_ids['stack_pct'] );
	block_cert_activate( $repo, $service, $promo_ids['stack_fixed'] );
	if ( block_cert_prepare_cart( $paid_id ) ) {
		$fees      = block_cert_cart_fees();
		$neg_count = 0;
		foreach ( $fees as $fee ) {
			if ( $fee['amount'] < 0 ) {
				++$neg_count;
			}
		}
		$applied_count = block_cert_session_applied_count();
		block_cert_row(
			'stacked_fees',
			( $neg_count >= 2 && $applied_count >= 2 ) ? 'pass' : 'partial',
			"negative_fees={$neg_count} applied_promotions={$applied_count}"
		);
	}
	block_cert_pause_all( $repo, $service );
}

if ( block_cert_should_run( 'code' ) && $promo_ids['fixed'] > 0 ) {
	block_cert_pause_all( $repo, $service );
	$code_plain = 'BLOCKQA5';
	$existing   = $code_repo->find_by_plain_code( $code_plain );
	if ( $existing !== null && $existing->get_promotion_id() !== $promo_ids['fixed'] ) {
		$code_plain = 'BLOCKQA' . (string) $promo_ids['fixed'];
		$existing   = $code_repo->find_by_plain_code( $code_plain );
	}
	if ( $existing === null ) {
		$code_factory = new PromotionCodeFactory();
		$code_repo->insert( $code_factory->create_manual_code( $promo_ids['fixed'], $code_plain, 50, null ) );
	}
	block_cert_activate( $repo, $service, $promo_ids['fixed'] );
	if ( block_cert_prepare_cart( $paid_id ) && function_exists( 'WC' ) ) {
		WC()->cart->apply_coupon( $code_plain );
		WC()->cart->calculate_totals();
		$fees    = block_cert_cart_fees();
		$code_ok = false;
		foreach ( $fees as $fee ) {
			if ( $fee['amount'] < 0 ) {
				$code_ok = true;
			}
		}
		$applied     = WC()->cart->get_applied_coupons();
		$only_linked = block_cert_session_applied_count() === 1;
		block_cert_row(
			'promotion_code',
			( $code_ok && in_array( strtolower( $code_plain ), array_map( 'strtolower', $applied ), true ) && $only_linked ) ? 'pass' : 'fail',
			'code=' . $code_plain . ' coupons=' . wp_json_encode( $applied ) . ' fees=' . wp_json_encode( $fees )
		);
	}
	block_cert_pause_all( $repo, $service );
}

if ( block_cert_should_run( 'gift' ) && $promo_ids['gift'] > 0 ) {
	$gift_pid = $promo_ids['gift_product_id'];
	if ( $gift_pid <= 0 || $gift_pid === $paid_id ) {
		block_cert_row(
			'free_gift',
			'partial',
			'CLI needs distinct SKUs; browser: MOTS-C paid + gift 4338 (promo ' . $promo_ids['gift'] . ')'
		);
	} else {
		block_cert_pause_all( $repo, $service );
		block_cert_activate( $repo, $service, $promo_ids['gift'] );
		if ( block_cert_prepare_cart( $paid_id ) ) {
			WC()->cart->calculate_totals();
			$gift_zero = false;
			$gift_flag = false;
			foreach ( WC()->cart->get_cart() as $line ) {
				if ( ! empty( $line['mp_cp_free_gift'] ) ) {
					$gift_flag = true;
					if ( isset( $line['data'] ) && is_object( $line['data'] ) && (float) $line['data']->get_price() === 0.0 ) {
						$gift_zero = true;
					}
				}
			}
			$lines = WC()->cart->get_cart_contents_count();
			block_cert_row(
				'free_gift',
				( $gift_flag && $gift_zero && $lines >= 2 ) ? 'pass' : 'fail',
				"lines={$lines} gift_flag=" . ( $gift_flag ? 'yes' : 'no' ) . ' paid=' . $paid_id . ' gift_pid=' . $gift_pid
			);
		}
		block_cert_pause_all( $repo, $service );
	}
}

if ( block_cert_should_run( 'line' ) && $promo_ids['line'] > 0 ) {
	block_cert_pause_all( $repo, $service );
	block_cert_activate( $repo, $service, $promo_ids['line'] );
	if ( block_cert_prepare_cart( $paid_id ) ) {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			// WooCommerce may run fees before line subtotals exist; prime line discounts before totals.
			do_action( 'woocommerce_before_calculate_totals', WC()->cart );
			do_action( 'woocommerce_before_calculate_totals', WC()->cart );
			WC()->cart->calculate_totals();
		}
		$line_alloc   = CartSessionHelper::get_line_allocations();
		$has_session  = is_array( $line_alloc ) && ! empty( $line_alloc['line_discounts'] );
		$line_applied = LineDiscountPlanCache::get_line_applied_total( $promo_ids['line'] );
		$mutated      = false;
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $line ) {
				if ( ! isset( $line['data'] ) || ! is_object( $line['data'] ) ) {
					continue;
				}
				$product_id = (int) ( $line['product_id'] ?? 0 );
				$regular    = (float) $line['data']->get_regular_price();
				if ( $regular <= 0 && $product_id > 0 && function_exists( 'wc_get_product' ) ) {
					$catalog = wc_get_product( $product_id );
					if ( $catalog ) {
						$regular = (float) $catalog->get_regular_price();
					}
				}
				$current = (float) $line['data']->get_price();
				if ( $regular > 0 && $current >= 0 && $current < $regular ) {
					$mutated = true;
					break;
				}
			}
		}
		$server_line = $has_session || $line_applied > 0 || $mutated;
		block_cert_row(
			'line_item_mode',
			$server_line ? 'pass' : 'partial',
			$has_session
				? 'line_discounts in session'
				: ( $mutated ? 'line price mutated (no session payload)' : 'no line_discounts; block UI may still show line prices' )
		);
	}
	block_cert_pause_all( $repo, $service );
}

if ( block_cert_should_run( 'safe' ) ) {
	$settings->set_safe_mode_enabled( true );
	block_cert_prepare_cart( $paid_id );
	block_cert_row( 'safe_mode', count( block_cert_cart_fees() ) === 0 ? 'pass' : 'fail', '' );
	$settings->set_safe_mode_enabled( false );
	block_cert_pause_all( $repo, $service );
}

echo str_repeat( '-', 56 ) . "\n";
echo 'Summary: ' . $GLOBALS['mp_cp_block_cert']['pass'] . ' pass, ' . $GLOBALS['mp_cp_block_cert']['fail'] . " fail/partial\n";
echo 'Orders: ' . wp_json_encode( $GLOBALS['mp_cp_block_cert']['orders'] ) . "\n";

exit( $GLOBALS['mp_cp_block_cert']['fail'] > 0 ? 1 : 0 );
