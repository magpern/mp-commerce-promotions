<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use MP\CommercePromotions\GiftCard\GiftCardPromotionExclusion;
use MP\CommercePromotions\Woo\ShippingQualifiedSubtotalCalculator;
use PHPUnit\Framework\TestCase;

final class ShippingQualifiedSubtotalTest extends TestCase {

	private int $gift_product_id = 93001;

	private int $physical_product_id = 93002;

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

	public function test_gift_card_only_cart_does_not_qualify(): void {
		$stats = ShippingQualifiedSubtotalCalculator::calculate(
			array(
				array(
					'product_id'           => $this->gift_product_id,
					'quantity'             => 1.0,
					'line_subtotal'        => 100.0,
					'is_gift_card_product' => true,
					'needs_shipping'       => false,
				),
			)
		);

		$this->assertSame( 0.0, $stats[ ShippingQualifiedSubtotalCalculator::TRACE_QUALIFYING ] );
		$this->assertFalse( $stats['has_qualifying_shipping_items'] );
		$this->assertSame( 100.0, $stats['shipping_exclusion_reasons'][ ShippingQualifiedSubtotalCalculator::REASON_GIFT_CARD ] );
	}

	public function test_virtual_only_cart_does_not_qualify(): void {
		$stats = ShippingQualifiedSubtotalCalculator::calculate(
			array(
				array(
					'product_id'     => $this->physical_product_id,
					'quantity'       => 1.0,
					'line_subtotal'  => 80.0,
					'needs_shipping' => false,
				),
			)
		);

		$this->assertSame( 0.0, $stats[ ShippingQualifiedSubtotalCalculator::TRACE_QUALIFYING ] );
		$this->assertSame( 80.0, $stats['shipping_exclusion_reasons'][ ShippingQualifiedSubtotalCalculator::REASON_NON_SHIPPABLE ] );
	}

	public function test_physical_product_qualifies_normally(): void {
		$stats = ShippingQualifiedSubtotalCalculator::calculate(
			array(
				array(
					'product_id'     => $this->physical_product_id,
					'quantity'       => 1.0,
					'line_subtotal'  => 100.0,
					'needs_shipping' => true,
				),
			)
		);

		$this->assertSame( 100.0, $stats[ ShippingQualifiedSubtotalCalculator::TRACE_QUALIFYING ] );
	}

	public function test_mixed_gift_card_and_physical_counts_only_physical(): void {
		$stats = ShippingQualifiedSubtotalCalculator::calculate(
			array(
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
			)
		);

		$this->assertSame( 50.0, $stats[ ShippingQualifiedSubtotalCalculator::TRACE_QUALIFYING ] );
	}

	public function test_free_gift_line_excluded(): void {
		$stats = ShippingQualifiedSubtotalCalculator::calculate(
			array(
				array(
					'product_id'     => $this->physical_product_id,
					'quantity'       => 1.0,
					'line_subtotal'  => 10.0,
					'needs_shipping' => true,
					'is_free_gift'   => true,
				),
				array(
					'product_id'     => $this->physical_product_id,
					'quantity'       => 1.0,
					'line_subtotal'  => 90.0,
					'needs_shipping' => true,
				),
			)
		);

		$this->assertSame( 90.0, $stats[ ShippingQualifiedSubtotalCalculator::TRACE_QUALIFYING ] );
		$this->assertSame( 10.0, $stats['shipping_exclusion_reasons'][ ShippingQualifiedSubtotalCalculator::REASON_FREE_GIFT ] );
	}

	public function test_buy_three_get_one_free_counts_only_paid_units(): void {
		$items = array(
			array(
				'item_key'       => 'line-bogo',
				'product_id'     => 100,
				'quantity'       => 4.0,
				'line_subtotal'  => 40.0,
				'unit_price'     => 10.0,
				'needs_shipping' => true,
				'categories'     => array( 10 ),
			),
		);

		$promotion = Promotion::from_array(
			array(
				'uuid'     => 'bogo-ship-test',
				'name'     => 'BOGO ship',
				'status'   => PromotionStatus::ACTIVE,
				'priority' => 1,
				'actions'  => array(
					array(
						'type'                => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
						'scope'               => 'category',
						'category_ids'        => array( 10 ),
						'discount_percentage' => 100,
						'required_quantity'   => 3,
						'discounted_quantity' => 1,
					),
				),
			)
		);

		$context = new EvaluationContext( null, 40.0, 'EUR', $items, array() );
		$stats   = ShippingQualifiedSubtotalCalculator::calculate( $items, $context, array( $promotion ) );

		$this->assertSame( 30.0, $stats[ ShippingQualifiedSubtotalCalculator::TRACE_QUALIFYING ] );
		$this->assertGreaterThan( 0.0, $stats['shipping_exclusion_reasons'][ ShippingQualifiedSubtotalCalculator::REASON_DISCOUNT_ALLOCATION ] );
	}

	public function test_reduced_line_subtotal_counts_paid_portion_after_line_discount(): void {
		$stats = ShippingQualifiedSubtotalCalculator::calculate(
			array(
				array(
					'product_id'     => $this->physical_product_id,
					'quantity'       => 1.0,
					'line_subtotal'  => 15.0,
					'unit_price'     => 15.0,
					'needs_shipping' => true,
				),
			)
		);

		$this->assertSame( 15.0, $stats[ ShippingQualifiedSubtotalCalculator::TRACE_QUALIFYING ] );
	}

	public function test_free_shipping_promotion_uses_shipping_qualified_subtotal(): void {
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
				'uuid'       => 'free-ship-gc',
				'name'       => 'Free ship',
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

		$result = ( new PromotionEvaluator() )->evaluate(
			$promotion,
			new EvaluationContext( null, 100.0, 'EUR', $items, array() )
		);

		$this->assertFalse( $result->is_eligible() );
	}

	public function test_gift_card_product_remains_detected(): void {
		$this->assertTrue(
			GiftCardPromotionExclusion::products()->product_sells_gift_card( $this->gift_product_id, 0 )
		);
	}
}
