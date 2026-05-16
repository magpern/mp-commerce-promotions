<?php
/**
 * WP-CLI smoke: max_applications plan cap and cart fees.
 *
 * Usage:
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/stacking-limits-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\PromotionEvaluationDecision;
use MP\CommercePromotions\Engine\PromotionPlanner;
use MP\CommercePromotions\Service\PromotionService;
use MP\CommercePromotions\Woo\AppliedPromotionSession;
use MP\CommercePromotions\Woo\CartSessionHelper;

$GLOBALS['smoke_failures'] = 0;

function limits_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

function limits_smoke_make(
	PromotionService $service,
	int $priority,
	float $fixed,
	?int $max_applications
): int {
	$draft = $service->create_draft( 'Smoke Limit ' . wp_rand( 1000, 9999 ) );
	$id    = (int) $draft->get_id();
	$updated = $draft
		->with_rules(
			array( array( 'type' => 'minimum_subtotal', 'amount' => 1 ) ),
			array( array( 'type' => 'fixed_amount_discount', 'amount' => $fixed ) ),
			array()
		)
		->with_application_rules( PromotionApplicationMode::STACKABLE, false, $max_applications )
		->with_priority( $priority );
	$service->update_promotion( $updated );
	$service->change_status( $updated, PromotionStatus::ACTIVE );
	return $id;
}

function limits_smoke_archive( PromotionService $service, PromotionRepository $repo, int $id ): void {
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

global $wpdb;

if ( ! class_exists( 'WP_CLI' ) || ! function_exists( 'wc' ) ) {
	echo "WP-CLI and WooCommerce required.\n";
	exit( 1 );
}

$plugin = new \MP\CommercePromotions\Plugin();
$plugin->init();

$repo    = new PromotionRepository( $wpdb );
$audit   = new \MP\CommercePromotions\Domain\AuditLogRepository( $wpdb );
$audit_l = new \MP\CommercePromotions\Service\AuditLogger( $audit );
$factory = new \MP\CommercePromotions\Domain\PromotionFactory();
$service = new PromotionService( $repo, $factory, $audit_l );
$planner = new PromotionPlanner();

$created = array();

try {
	$id1 = limits_smoke_make( $service, 1, 10.0, 2 );
	$id2 = limits_smoke_make( $service, 2, 10.0, null );
	$id3 = limits_smoke_make( $service, 3, 10.0, null );
	$created[] = $id1;
	$created[] = $id2;
	$created[] = $id3;

	wc_load_cart();
	WC()->initialize_session();
	WC()->cart->empty_cart( true );
	$key = WC()->cart->add_to_cart( 3703, 3 );
	if ( ! $key ) {
		throw new RuntimeException( 'add_to_cart failed.' );
	}
	WC()->cart->calculate_totals();
	$context = ( new \MP\CommercePromotions\Woo\CartContextBuilder() )->build_from_cart();

	$promos = array( $repo->find( $id1 ), $repo->find( $id2 ), $repo->find( $id3 ) );
	$plan   = $planner->plan( $promos, $context );

	$selected = array_map(
		static fn ( $d ) => (int) $d->get_promotion_id(),
		$plan->get_selected_decisions()
	);
	limits_smoke_assert( count( $selected ) === 2, 'planner selects 2 promotions (max_applications=2)' );
	limits_smoke_assert( in_array( $id1, $selected, true ), 'first promotion selected' );
	limits_smoke_assert( in_array( $id2, $selected, true ), 'second promotion selected' );
	limits_smoke_assert( ! in_array( $id3, $selected, true ), 'third skipped by max_applications' );
	limits_smoke_assert(
		$plan->get_decisions()[2]->get_skipped_reason() === PromotionEvaluationDecision::REASON_MAX_APPLICATIONS_REACHED,
		'third skipped_reason max_applications_reached'
	);

	do_action( 'woocommerce_cart_calculate_fees', WC()->cart );
	$session = CartSessionHelper::get_applied_promotion();
	$entries = AppliedPromotionSession::entries_from_session( is_array( $session ) ? $session : null );
	limits_smoke_assert( count( $entries ) === 2, 'cart session has 2 applied promotions' );

	$total = isset( $session['total_discount_amount'] ) ? (float) $session['total_discount_amount'] : 0.0;
	$subtotal = (float) WC()->cart->get_subtotal();
	limits_smoke_assert( $total <= $subtotal + 0.05, 'discount capped at subtotal (total=' . $total . ', subtotal=' . $subtotal . ')' );

} catch ( Throwable $e ) {
	++$GLOBALS['smoke_failures'];
	WP_CLI::warning( 'Exception: ' . $e->getMessage() );
} finally {
	foreach ( array_unique( $created ) as $pid ) {
		limits_smoke_archive( $service, $repo, $pid );
	}
}

$failures = (int) ( $GLOBALS['smoke_failures'] ?? 0 );
if ( $failures > 0 ) {
	WP_CLI::error( "Stacking limits smoke finished with {$failures} failure(s)." );
}

WP_CLI::success( 'Stacking limits smoke passed.' );
