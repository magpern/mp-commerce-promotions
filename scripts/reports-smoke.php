<?php
/**
 * WP-CLI smoke: promotion reports summary and CSV export.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/reports-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\Domain\AuditLogRepository;
use MP\CommercePromotions\Domain\PromotionFactory;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Domain\Redemption;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Service\AuditLogger;
use MP\CommercePromotions\Service\PromotionReports;
use MP\CommercePromotions\Service\PromotionService;

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
$audit           = new AuditLogger( new AuditLogRepository( $wpdb ) );
$factory         = new PromotionFactory();
$service         = new PromotionService( $promo_repo, $factory, $audit );
$reports         = new PromotionReports( $promo_repo, $redemption_repo );

$draft   = $service->create_draft( 'Reports smoke ' . wp_generate_password( 6, false ) );
$promo_id = (int) $draft->get_id();
if ( $promo_id <= 0 ) {
	WP_CLI::error( 'Could not create test promotion.' );
}

$active = $service->change_status(
	$draft->with_rules(
		array( array( 'type' => 'minimum_subtotal', 'amount' => 1 ) ),
		array( array( 'type' => 'fixed_amount_discount', 'amount' => 5 ) ),
		array()
	),
	PromotionStatus::ACTIVE
);

$order = wc_create_order();
$order_id = (int) $order->get_id();

$now = current_time( 'mysql' );

$recorded_id = $redemption_repo->insert(
	new Redemption(
		null,
		$promo_id,
		$order_id,
		null,
		null,
		12.50,
		'USD',
		Redemption::STATUS_RECORDED,
		$now,
		$now
	)
);

$reversed_id = $redemption_repo->insert(
	new Redemption(
		null,
		$promo_id,
		$order_id + 1,
		null,
		null,
		3.00,
		'USD',
		Redemption::STATUS_REVERSED,
		$now,
		$now
	)
);

smoke_assert( $recorded_id > 0 && $reversed_id > 0, 'inserted test redemptions' );

$filters = array( 'promotion_id' => $promo_id );

smoke_assert( $redemption_repo->count_recorded( $filters ) >= 1, 'recorded count includes test row' );
smoke_assert( $redemption_repo->count_reversed( $filters ) >= 1, 'reversed count includes test row' );
smoke_assert( $redemption_repo->sum_recorded_discount_amount( $filters ) >= 12.50, 'discount sum includes recorded amount' );

$summary = $reports->summary( $filters );
$found_top = false;
foreach ( $summary['top_promotions'] as $row ) {
	if ( (int) $row['promotion_id'] === $promo_id ) {
		$found_top = true;
		smoke_assert( $row['recorded_count'] >= 1, 'top promotion recorded_count' );
		break;
	}
}
smoke_assert( $found_top, 'top promotions includes test promotion' );

$csv = $reports->redemptions_csv( $filters );
smoke_assert( str_contains( $csv, 'redemption_id,promotion_id' ), 'CSV header present' );
smoke_assert( str_contains( $csv, (string) $promo_id ), 'CSV contains promotion_id row' );
smoke_assert( str_contains( $csv, (string) $recorded_id ), 'CSV contains redemption id' );

try {
	$archived = $service->change_status( $active, PromotionStatus::ARCHIVED );
	unset( $archived );
} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
	// cleanup best-effort
}

if ( $GLOBALS['smoke_failures'] > 0 ) {
	WP_CLI::error( sprintf( 'reports-smoke finished with %d failure(s).', (int) $GLOBALS['smoke_failures'] ) );
}

WP_CLI::success( 'reports-smoke completed.' );
