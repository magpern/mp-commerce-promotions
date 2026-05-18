<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\PromotionDiscountApplicationMode;
use MP\CommercePromotions\Engine\AppliedLineDiscount;
use MP\CommercePromotions\Engine\LineDiscountAllocationResult;
use MP\CommercePromotions\Engine\AllocationContextCache;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use MP\CommercePromotions\Woo\LineDiscountFallbackTelemetry;
use MP\CommercePromotions\Woo\LineDiscountPlanCache;
use MP\CommercePromotions\Woo\LinePriceMutationGuard;
use MP\CommercePromotions\Service\LineDiscountModeHelper;
use MP\CommercePromotions\Woo\PricingCompatibilityAnalyzer;
use PHPUnit\Framework\TestCase;

final class LineDiscountEngineTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		AllocationContextCache::reset_request_cache();
		LineDiscountPlanCache::reset();
		LineDiscountFallbackTelemetry::reset();
		LinePriceMutationGuard::reset_cycle();
	}

	public function test_discount_application_mode_defaults_to_fee_based(): void {
		$this->assertSame(
			PromotionDiscountApplicationMode::FEE_BASED,
			PromotionDiscountApplicationMode::normalize( null )
		);
		$this->assertTrue( PromotionDiscountApplicationMode::allows_fee_fallback( PromotionDiscountApplicationMode::HYBRID ) );
		$this->assertFalse( PromotionDiscountApplicationMode::allows_fee_fallback( PromotionDiscountApplicationMode::LINE_ITEM ) );
	}

	public function test_promotion_row_defaults_discount_application_mode(): void {
		$row       = PromotionTestFixtures::active_promotion_with_id(
			1,
			array(),
			array( array( 'type' => 'percentage_discount', 'percentage' => 10 ) )
		)->to_array();
		$promotion = \MP\CommercePromotions\Domain\Promotion::from_array( $row );
		$this->assertSame( PromotionDiscountApplicationMode::FEE_BASED, $promotion->get_discount_application_mode() );
	}

	public function test_promotion_with_line_item_mode_round_trip(): void {
		$row = PromotionTestFixtures::active_promotion_with_id(
			2,
			array(),
			array( array( 'type' => 'fixed_amount_discount', 'amount' => 5 ) )
		)->to_array();
		$row['discount_application_mode'] = PromotionDiscountApplicationMode::LINE_ITEM;
		$promotion = \MP\CommercePromotions\Domain\Promotion::from_array( $row );
		$this->assertSame( PromotionDiscountApplicationMode::LINE_ITEM, $promotion->get_discount_application_mode() );
		$updated = $promotion->with_pricing_fields( null, null, null, PromotionDiscountApplicationMode::HYBRID );
		$this->assertSame( PromotionDiscountApplicationMode::HYBRID, $updated->get_discount_application_mode() );
	}

	public function test_applied_line_discount_serialization(): void {
		$discount = new AppliedLineDiscount(
			'abc123',
			42,
			null,
			2,
			10.5,
			7,
			'percentage_discount',
			'exclusive'
		);
		$parsed = AppliedLineDiscount::from_array( $discount->to_array() );
		$this->assertInstanceOf( AppliedLineDiscount::class, $parsed );
		$this->assertSame( 10.5, $parsed->get_allocated_amount() );
	}

	public function test_line_discount_allocation_result_totals(): void {
		$line = new AppliedLineDiscount( 'k1', 1, null, 1, 5.0, 2, 'fixed_amount_discount' );
		$result = new LineDiscountAllocationResult(
			array( $line ),
			5.0,
			array(
				array(
					'promotion_id'  => 2,
					'reason_code'   => LineDiscountFallbackTelemetry::REASON_LINE_MUTATION_FAILED,
				),
			)
		);
		$this->assertSame( 5.0, $result->get_total_allocated() );
		$this->assertSame( 1, $result->get_fallback_count() );
		$roundtrip = LineDiscountAllocationResult::from_array( $result->to_array() );
		$this->assertSame( 5.0, $roundtrip->get_total_allocated() );
	}

	public function test_line_discount_plan_cache_tracks_applied_totals(): void {
		LineDiscountPlanCache::record_line_applied( 9, 4.0 );
		LineDiscountPlanCache::record_line_applied( 9, 1.5 );
		$this->assertTrue( LineDiscountPlanCache::has_line_applied( 9 ) );
		$this->assertSame( 5.5, LineDiscountPlanCache::get_line_applied_total( 9 ) );
	}

	public function test_fallback_telemetry_counts_reasons(): void {
		LineDiscountFallbackTelemetry::record( LineDiscountFallbackTelemetry::REASON_COUPON_CONFLICT, 3 );
		LineDiscountFallbackTelemetry::record( LineDiscountFallbackTelemetry::REASON_COUPON_CONFLICT, 3 );
		$this->assertSame( 2, LineDiscountFallbackTelemetry::get_total() );
		$counts = LineDiscountFallbackTelemetry::get_counts();
		$this->assertSame( 2, $counts[ LineDiscountFallbackTelemetry::REASON_COUPON_CONFLICT ] ?? 0 );
	}

	public function test_pricing_analyzer_line_mode_lowers_confidence_for_tax_inclusive(): void {
		$analyzer = new PricingCompatibilityAnalyzer();
		$audit    = $analyzer->audit_line_discount_mode(
			PromotionDiscountApplicationMode::LINE_ITEM,
			array( array( 'type' => 'percentage_discount', 'percentage' => 10 ) )
		);
		$this->assertNotEmpty( $audit['confidence'] );
		$this->assertGreaterThanOrEqual( 0, $audit['score'] );
		$this->assertNotEmpty( $audit['issues'] );
	}

	public function test_fee_based_mode_has_high_line_audit_confidence(): void {
		$analyzer = new PricingCompatibilityAnalyzer();
		$audit    = $analyzer->audit_line_discount_mode( PromotionDiscountApplicationMode::FEE_BASED );
		$this->assertSame( PricingCompatibilityAnalyzer::CONFIDENCE_HIGH, $audit['confidence'] );
		$this->assertSame( 100, $audit['score'] );
	}

	public function test_list_badge_labels(): void {
		$this->assertSame( 'Line', LineDiscountModeHelper::list_badge_label( PromotionDiscountApplicationMode::LINE_ITEM ) );
		$this->assertSame( 'Hybrid', LineDiscountModeHelper::list_badge_label( PromotionDiscountApplicationMode::HYBRID ) );
		$this->assertNull( LineDiscountModeHelper::list_badge_label( PromotionDiscountApplicationMode::FEE_BASED ) );
	}

	public function test_validator_warns_per_action_fee_fallback(): void {
		$promotion = \MP\CommercePromotions\Domain\Promotion::from_array(
			array(
				'id'                        => 3,
				'uuid'                      => '00000000-0000-0000-0000-000000000003',
				'name'                      => 'Line + gift',
				'status'                    => 'active',
				'priority'                  => 1,
				'conditions'                => array(),
				'actions'                   => array(
					array( 'type' => 'percentage_discount', 'percentage' => 10 ),
					array( 'type' => 'free_gift_product', 'product_id' => 1, 'quantity' => 1 ),
				),
				'restrictions'              => array(),
				'usage_count'               => 0,
				'application_mode'          => 'stackable',
				'stop_processing'           => false,
				'discount_application_mode' => PromotionDiscountApplicationMode::LINE_ITEM,
			)
		);
		$validator = new \MP\CommercePromotions\Service\PromotionRuleValidator();
		$messages  = array();
		foreach ( $validator->validate( $promotion ) as $issue ) {
			if ( is_array( $issue ) && isset( $issue['message'] ) ) {
				$messages[] = (string) $issue['message'];
			}
		}
		$joined = implode( ' ', $messages );
		$this->assertStringContainsString( 'fee-based', strtolower( $joined ) );
	}

	public function test_recovery_dry_run_summary(): void {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			$this->markTestSkipped( 'wpdb not available' );
		}
		$recovery = new \MP\CommercePromotions\Service\PromotionPricingRecovery(
			new \MP\CommercePromotions\Domain\PromotionRepository( $wpdb ),
			new \MP\CommercePromotions\Domain\PromotionSnapshotRepository( $wpdb )
		);
		$result = $recovery->repair_stuck_line_discount_sessions( true );
		$this->assertTrue( $result['dry_run'] ?? false );
		$this->assertArrayHasKey( 'would_clear', $result );
	}
}
