<?php
/**
 * WP-CLI helper: activate one Blocks QA promotion and report cart fee state.
 *
 * Usage:
 *   ./wp eval-file .../blocks-qa-runner.php --promo_id=168
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
use MP\CommercePromotions\Service\BlockQaPromotionSetup;
use MP\CommercePromotions\Service\PromotionService;

$promo_id = 0;
if ( isset( $args ) && is_array( $args ) && isset( $args[0] ) && is_numeric( $args[0] ) ) {
	$promo_id = (int) $args[0];
}
if ( $promo_id <= 0 && getenv( 'MP_CP_BLOCK_QA_PROMO_ID' ) !== false ) {
	$promo_id = (int) getenv( 'MP_CP_BLOCK_QA_PROMO_ID' );
}
if ( $promo_id <= 0 && defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
	$assoc = \WP_CLI::get_runner()->config;
	if ( is_array( $assoc ) && isset( $assoc['promo_id'] ) && is_numeric( $assoc['promo_id'] ) ) {
		$promo_id = (int) $assoc['promo_id'];
	}
}
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WP-CLI only.
if ( $promo_id <= 0 && isset( $_SERVER['argv'] ) && is_array( $_SERVER['argv'] ) ) {
	foreach ( $_SERVER['argv'] as $arg ) {
		if ( is_string( $arg ) && preg_match( '/^--promo_id=(\d+)$/', $arg, $m ) ) {
			$promo_id = (int) $m[1];
			break;
		}
	}
}

$pair       = BlockQaPromotionSetup::resolve_default_product_pair();
$product_id = $pair['paid_product_id'] > 0 ? $pair['paid_product_id'] : BlockQaPromotionSetup::DEFAULT_PAID_PRODUCT_ID;
if ( getenv( 'MP_CP_BLOCK_QA_PRODUCT_ID' ) !== false ) {
	$product_id = (int) getenv( 'MP_CP_BLOCK_QA_PRODUCT_ID' );
}

global $wpdb;

$repo    = new PromotionRepository( $wpdb );
$audit   = new \MP\CommercePromotions\Domain\AuditLogRepository( $wpdb );
$audit_log = new \MP\CommercePromotions\Service\AuditLogger( $audit );
$factory = new \MP\CommercePromotions\Domain\PromotionFactory();
$service = new PromotionService( $repo, $factory, $audit_log );

foreach ( $repo->find_active_for_planner( 200 ) as $p ) {
	$id = $p->get_id();
	if ( $id === null || $id <= 0 || $id === $promo_id ) {
		continue;
	}
	try {
		$service->change_status( $p, PromotionStatus::PAUSED );
		echo "Paused competing promo {$id}\n";
	} catch ( Throwable $e ) { // phpcs:ignore
	}
}

if ( $promo_id <= 0 ) {
	echo "Set MP_CP_BLOCK_QA_PROMO_ID or pass promo id as first arg.\n";
	exit( 1 );
}

$promo = $repo->find( $promo_id );
if ( $promo === null ) {
	echo "Promotion {$promo_id} not found.\n";
	exit( 1 );
}

if ( $promo->get_status() === PromotionStatus::PAUSED ) {
	$service->change_status( $promo, PromotionStatus::ACTIVE );
	$promo = $repo->find( $promo_id );
}

if ( ! function_exists( 'wc_load_cart' ) || ! function_exists( 'WC' ) ) {
	echo "WooCommerce cart unavailable.\n";
	exit( 1 );
}

wc_load_cart();
$cart = WC()->cart;
$cart->empty_cart();
$key = $cart->add_to_cart( $product_id, 1 );
if ( ! $key ) {
	echo "add_to_cart failed for product {$product_id}\n";
	exit( 1 );
}

$cart->calculate_totals();

$fees = array();
foreach ( $cart->get_fees() as $fee ) {
	if ( is_object( $fee ) ) {
		$fees[] = array(
			'name'   => (string) ( $fee->name ?? '' ),
			'amount' => (float) ( $fee->amount ?? 0 ),
		);
	}
}

echo 'Promotion: ' . $promo->get_name() . ' (' . $promo->get_status() . ")\n";
echo 'Mode: ' . $promo->get_discount_application_mode() . "\n";
echo 'Cart subtotal: ' . (string) $cart->get_subtotal() . "\n";
echo 'Cart total: ' . (string) $cart->get_total( 'edit' ) . "\n";
echo 'Fees: ' . wp_json_encode( $fees ) . "\n";

$session = \MP\CommercePromotions\Woo\CartSessionHelper::get_applied_promotion();
echo 'Session promo: ' . wp_json_encode( $session ) . "\n";

$line_alloc = \MP\CommercePromotions\Woo\CartSessionHelper::get_line_allocations();
if ( is_array( $line_alloc ) && ! empty( $line_alloc['line_discounts'] ) ) {
	echo 'Line allocations: yes' . "\n";
}
