<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\Condition\MinimumSubtotalCondition;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use MP\CommercePromotions\GiftCard\GiftCardPromotionExclusion;
use MP\CommercePromotions\Woo\CartShippingEligibilitySubtotal;
use PHPUnit\Framework\TestCase;

final class GiftCardShippingExclusionTest extends TestCase {

	private int $gift_product_id = 92001;

	private int $physical_product_id = 92002;

	private int $virtual_product_id = 92003;

	protected function setUp(): void {
		global $mp_cp_test_post_meta;
		$mp_cp_test_post_meta = array();

		GiftCardProductMeta::save(
			$this->gift_product_id,
			array(
				'sells'        => GiftCardProductMeta::VALUE_YES,
				'amount_mode'  => GiftCardProductMeta::AMOUNT_MODE_FIXED,
				'fixed_amount' => '100',
			)
		);
	}

	public function test_gift_card_only_cart_has_zero_qualifying_shipping_subtotal(): void {
		$items = array(
			array(
				'product_id'           => $this->gift_product_id,
				'quantity'             => 1.0,
				'line_subtotal'        => 100.0,
				'is_gift_card_product' => true,
				'needs_shipping'       => false,
			),
		);

		$stats = CartShippingEligibilitySubtotal::stats( $items );
		$this->assertSame( 1, $stats[ CartShippingEligibilitySubtotal::TRACE_GIFT_COUNT_KEY ] );
		$this->assertSame( 100.0, $stats[ CartShippingEligibilitySubtotal::TRACE_GIFT_SUBTOTAL_KEY ] );
		$this->assertSame( 0.0, $stats[ CartShippingEligibilitySubtotal::TRACE_QUALIFYING_KEY ] );
		$this->assertFalse( $stats['has_qualifying_shipping_items'] );
	}

	public function test_mixed_cart_counts_only_physical_subtotal(): void {
		$items = array(
			array(
				'product_id'           => $this->gift_product_id,
				'quantity'             => 1.0,
				'line_subtotal'        => 100.0,
				'is_gift_card_product' => true,
				'needs_shipping'       => false,
			),
			array(
				'product_id'     => $this->physical_product_id,
				'quantity'       => 1.0,
				'line_subtotal'  => 50.0,
				'needs_shipping' => true,
			),
		);

		$stats = CartShippingEligibilitySubtotal::stats( $items );
		$this->assertSame( 50.0, $stats[ CartShippingEligibilitySubtotal::TRACE_QUALIFYING_KEY ] );
		$this->assertTrue( $stats['has_qualifying_shipping_items'] );
	}

	public function test_virtual_non_gift_card_does_not_count_toward_shipping(): void {
		$items = array(
			array(
				'product_id'     => $this->virtual_product_id,
				'quantity'       => 1.0,
				'line_subtotal'  => 80.0,
				'needs_shipping' => false,
			),
		);

		$stats = CartShippingEligibilitySubtotal::stats( $items );
		$this->assertSame( 0.0, $stats[ CartShippingEligibilitySubtotal::TRACE_QUALIFYING_KEY ] );
		$this->assertFalse( $stats['has_qualifying_shipping_items'] );
	}

	public function test_gift_card_only_cart_does_not_qualify_for_free_shipping_promotion(): void {
		$items = array(
			array(
				'product_id'           => $this->gift_product_id,
				'quantity'             => 1.0,
				'line_subtotal'        => 100.0,
				'is_gift_card_product' => true,
				'needs_shipping'       => false,
			),
		);

		$promotion = Promotion::from_array(
			array(
				'uuid'       => 'test-free-ship-gc-only',
				'name'       => 'Free shipping min 50',
				'status'     => PromotionStatus::ACTIVE,
				'priority'   => 1,
				'conditions' => array(
					array(
						'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
						'amount' => 50,
					),
				),
				'actions'    => array(
					array(
						'type' => RuleTypes::ACTION_FREE_SHIPPING,
					),
				),
			)
		);

		$context = new EvaluationContext(
			null,
			0.0,
			'EUR',
			$items,
			array(
				GiftCardPromotionExclusion::TRACE_COUNT_KEY    => 1,
				GiftCardPromotionExclusion::TRACE_SUBTOTAL_KEY => 100.0,
			)
		);

		$result = ( new PromotionEvaluator() )->evaluate( $promotion, $context );
		$this->assertFalse( $result->is_eligible() );
	}

	public function test_mixed_cart_free_shipping_uses_physical_subtotal_for_minimum(): void {
		$items = array(
			array(
				'product_id'           => $this->gift_product_id,
				'quantity'             => 1.0,
				'line_subtotal'        => 100.0,
				'is_gift_card_product' => true,
				'needs_shipping'       => false,
			),
			array(
				'product_id'     => $this->physical_product_id,
				'quantity'       => 1.0,
				'line_subtotal'  => 50.0,
				'needs_shipping' => true,
			),
		);

		$promotion = Promotion::from_array(
			array(
				'uuid'       => 'test-free-ship-mixed',
				'name'       => 'Free shipping min 50 mixed',
				'status'     => PromotionStatus::ACTIVE,
				'priority'   => 1,
				'conditions' => array(
					array(
						'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
						'amount' => 50,
					),
				),
				'actions'    => array(
					array(
						'type' => RuleTypes::ACTION_FREE_SHIPPING,
					),
				),
			)
		);

		$result = ( new PromotionEvaluator() )->evaluate( $promotion, new EvaluationContext( null, 150.0, 'EUR', $items, array() ) );
		$this->assertTrue( $result->is_eligible() );

		$low_physical = Promotion::from_array(
			array(
				'uuid'       => 'test-free-ship-mixed-low',
				'name'       => 'Free shipping min 60 mixed',
				'status'     => PromotionStatus::ACTIVE,
				'priority'   => 1,
				'conditions' => array(
					array(
						'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
						'amount' => 60,
					),
				),
				'actions'    => array(
					array(
						'type' => RuleTypes::ACTION_FREE_SHIPPING,
					),
				),
			)
		);

		$fail = ( new PromotionEvaluator() )->evaluate( $low_physical, new EvaluationContext( null, 150.0, 'EUR', $items, array() ) );
		$this->assertFalse( $fail->is_eligible() );
	}

	public function test_minimum_subtotal_condition_uses_context_cart_subtotal(): void {
		$condition = new MinimumSubtotalCondition( 50.0 );
		$context   = new EvaluationContext( null, 50.0, 'EUR', array(), array() );
		$this->assertTrue( $condition->evaluate( $context )->passed() );
	}
}
