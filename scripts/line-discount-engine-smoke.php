<?php
/**
 * WP-CLI smoke: line discount application modes (schema 1.15.0).
 *
 * Usage:
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/line-discount-engine-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\Domain\PromotionDiscountApplicationMode;
use MP\CommercePromotions\Engine\AppliedLineDiscount;
use MP\CommercePromotions\Engine\LineDiscountAllocationResult;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use MP\CommercePromotions\Woo\LineDiscountFallbackTelemetry;
use MP\CommercePromotions\Woo\LineDiscountPlanCache;
use MP\CommercePromotions\Woo\LineItemDiscountApplier;
use MP\CommercePromotions\Woo\LinePriceMutationGuard;
use MP\CommercePromotions\Woo\PricingCompatibilityAnalyzer;

$ok = 0;
$fail = 0;

function line_discount_smoke_assert( bool $cond, string $label ): void {
	global $ok, $fail;
	if ( $cond ) {
		++$ok;
		echo "OK  {$label}\n";
		return;
	}
	++$fail;
	echo "FAIL {$label}\n";
}

$schema = get_option( 'mp_cp_schema_version', '' );
line_discount_smoke_assert( $schema === Schema::SCHEMA_VERSION, 'schema version ' . Schema::SCHEMA_VERSION );

line_discount_smoke_assert(
	PromotionDiscountApplicationMode::normalize( 'line_item' ) === PromotionDiscountApplicationMode::LINE_ITEM,
	'discount application mode line_item'
);

line_discount_smoke_assert( class_exists( LineItemDiscountApplier::class ), 'LineItemDiscountApplier class' );
line_discount_smoke_assert( class_exists( LinePriceMutationGuard::class ), 'LinePriceMutationGuard class' );

$sample = new AppliedLineDiscount( 'test', 1, null, 1, 2.5, 9, 'percentage_discount' );
line_discount_smoke_assert( AppliedLineDiscount::from_array( $sample->to_array() ) !== null, 'AppliedLineDiscount round-trip' );

$result = new LineDiscountAllocationResult( array( $sample ), 2.5 );
line_discount_smoke_assert( LineDiscountAllocationResult::from_array( $result->to_array() )->get_total_allocated() === 2.5, 'LineDiscountAllocationResult round-trip' );

LineDiscountFallbackTelemetry::record( LineDiscountFallbackTelemetry::REASON_MUTATION_GUARD_TRIGGERED, 1 );
line_discount_smoke_assert( LineDiscountFallbackTelemetry::get_total() >= 1, 'fallback telemetry' );

$analyzer = new PricingCompatibilityAnalyzer();
$audit = $analyzer->audit_line_discount_mode( PromotionDiscountApplicationMode::LINE_ITEM );
line_discount_smoke_assert( ! empty( $audit['confidence'] ), 'line mode compatibility audit' );

$root = dirname( __DIR__ );
line_discount_smoke_assert( is_readable( $root . '/src/Domain/PromotionDiscountApplicationMode.php' ), 'PromotionDiscountApplicationMode source' );

echo "\nLine discount engine smoke: {$ok} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
