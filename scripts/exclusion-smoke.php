<?php
/**
 * WP-CLI smoke: promotion exclusion planning and cart fees.
 *
 * Usage:
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/exclusion-smoke.php
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
use MP\CommercePromotions\Engine\PromotionEvaluationDecision;
use MP\CommercePromotions\Engine\PromotionPlanner;
use MP\CommercePromotions\Service\PromotionService;
use MP\CommercePromotions\Woo\AppliedPromotionSession;
use MP\CommercePromotions\Woo\CartSessionHelper;

$GLOBALS['smoke_failures'] = 0;

function exclusion_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

function exclusion_smoke_make(
	PromotionService $service,
	PromotionRepository $repo,
	string $name,
	float $fixed,
	int $priority,
	array $excluded_ids
): int {
	$draft = $service->create_draft( $name );
	$id    = (int) $draft->get_id();
	$updated = $draft
		->with_rules(
			array( array( 'type' => 'minimum_subtotal', 'amount' => 1 ) ),
			array( array( 'type' => 'fixed_amount_discount', 'amount' => $fixed ) ),
			array()
		)
		->with_application_rules( PromotionApplicationMode::STACKABLE, false, null )
		->with_excluded_promotion_ids( $excluded_ids )
		->with_priority( $priority );
	$service->update_promotion( $updated );
	$service->change_status( $updated, PromotionStatus::ACTIVE );
	return $id;
}

function exclusion_smoke_archive( PromotionService $service, PromotionRepository $repo, int $id ): void {
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
	$schema = get_option( 'mp_cp_schema_version', '' );
	exclusion_smoke_assert( $schema === '1.6.0', 'schema version 1.6.0 (got ' . $schema . ')' );

	$id_a = exclusion_smoke_make( $service, $repo, 'Smoke Excl A', 10.0, 1, array() );
	$id_b = exclusion_smoke_make( $service, $repo, 'Smoke Excl B', 10.0, 2, array() );
	$id_c = exclusion_smoke_make( $service, $repo, 'Smoke Excl C', 10.0, 3, array() );
	$created[] = $id_a;
	$created[] = $id_b;
	$created[] = $id_c;

	$a = $repo->find( $id_a )->with_excluded_promotion_ids( array( $id_b ) );
	$repo->update( $a );

	$active = $repo->find_active( 50 );
	$context_builder = new \MP\CommercePromotions\Woo\CartContextBuilder();
	wc_load_cart();
	WC()->initialize_session();
	WC()->cart->empty_cart( true );
	$key = WC()->cart->add_to_cart( 3703, 1 );
	if ( ! $key ) {
		throw new RuntimeException( 'Could not add product to cart.' );
	}
	WC()->cart->calculate_totals();
	$context = $context_builder->build_from_cart();

	$ordered = array();
	foreach ( $active as $p ) {
		if ( in_array( (int) $p->get_id(), array( $id_a, $id_b, $id_c ), true ) ) {
			$ordered[] = $p;
		}
	}
	usort(
		$ordered,
		static function ( $x, $y ) {
			return $x->get_priority() <=> $y->get_priority();
		}
	);

	$plan = $planner->plan( $ordered, $context );
	$selected_ids = array();
	foreach ( $plan->get_selected_decisions() as $d ) {
		$selected_ids[] = (int) $d->get_promotion_id();
	}
	exclusion_smoke_assert( in_array( $id_a, $selected_ids, true ), 'A selected' );
	exclusion_smoke_assert( ! in_array( $id_b, $selected_ids, true ), 'B excluded by A' );
	exclusion_smoke_assert( in_array( $id_c, $selected_ids, true ), 'C selected' );

	foreach ( $plan->get_decisions() as $decision ) {
		if ( (int) $decision->get_promotion_id() === $id_b ) {
			exclusion_smoke_assert(
				$decision->get_skipped_reason() === PromotionEvaluationDecision::REASON_EXCLUDED_BY_SELECTED,
				'B skipped_reason excluded_by_selected_promotion'
			);
		}
	}

	do_action( 'woocommerce_cart_calculate_fees', WC()->cart );
	$session = CartSessionHelper::get_applied_promotion();
	$entries = AppliedPromotionSession::entries_from_session( is_array( $session ) ? $session : null );
	$entry_ids = array_map(
		static fn ( $e ) => (int) $e['promotion_id'],
		$entries
	);
	exclusion_smoke_assert( count( $entries ) === 2, 'cart applies two fees (A and C)' );
	exclusion_smoke_assert( ! in_array( $id_b, $entry_ids, true ), 'cart does not apply B' );

	exclusion_smoke_archive( $service, $repo, $id_a );
	exclusion_smoke_archive( $service, $repo, $id_b );
	exclusion_smoke_archive( $service, $repo, $id_c );

	$id_ex = exclusion_smoke_make( $service, $repo, 'Smoke Excl Exclusive', 5.0, 1, array() );
	$created[] = $id_ex;
	$ex_row = $repo->find( $id_ex )->with_application_rules( PromotionApplicationMode::EXCLUSIVE, true, null );
	$repo->update( $ex_row );

	$id_after = exclusion_smoke_make( $service, $repo, 'Smoke Excl After', 5.0, 2, array() );
	$created[] = $id_after;

	$pair = array( $repo->find( $id_ex ), $repo->find( $id_after ) );
	$pair_plan = $planner->plan( $pair, $context );
	exclusion_smoke_assert( count( $pair_plan->get_selected_decisions() ) === 1, 'exclusive stops second selection' );
} catch ( Throwable $e ) {
	++$GLOBALS['smoke_failures'];
	WP_CLI::warning( 'Exception: ' . $e->getMessage() );
} finally {
	foreach ( array_unique( $created ) as $pid ) {
		exclusion_smoke_archive( $service, $repo, $pid );
	}
}

$failures = (int) ( $GLOBALS['smoke_failures'] ?? 0 );
if ( $failures > 0 ) {
	WP_CLI::error( "Exclusion smoke finished with {$failures} failure(s)." );
}

WP_CLI::success( 'Exclusion smoke passed.' );
