<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Woo\OrderPromotionState;
use PHPUnit\Framework\TestCase;

final class OrderPromotionStateTest extends TestCase {

	public function test_parses_applied_promotions_json(): void {
		$order = new OrderMetaStub();
		$order->meta[ OrderPromotionState::META_APPLIED_PROMOTIONS ] = wp_json_encode(
			array(
				array(
					'promotion_id'    => 10,
					'promotion_name'  => 'Gift',
					'action_type'     => 'free_gift_product',
					'discount_amount' => 0,
				),
			)
		);

		$rows = OrderPromotionState::get_applied_promotions( $order );
		$this->assertCount( 1, $rows );
		$this->assertSame( 10, $rows[0]['promotion_id'] );
	}

	public function test_legacy_single_promotion_meta(): void {
		$order = new OrderMetaStub();
		$order->meta['_mp_cp_promotion_id'] = '7';

		$rows = OrderPromotionState::get_applied_promotions( $order );
		$this->assertCount( 1, $rows );
		$this->assertSame( 7, $rows[0]['promotion_id'] );
	}

	public function test_mark_recorded_clears_reversed_flag(): void {
		$order = new OrderMetaStub();
		$order->meta[ OrderPromotionState::META_REDEMPTION_REVERSED ] = OrderPromotionState::META_VALUE_YES;

		OrderPromotionState::mark_recorded( $order );

		$this->assertSame( OrderPromotionState::META_VALUE_YES, $order->meta[ OrderPromotionState::META_REDEMPTION_RECORDED ] );
		$this->assertFalse( isset( $order->meta[ OrderPromotionState::META_REDEMPTION_REVERSED ] ) );
	}

	public function test_promotion_ids_from_order(): void {
		$order = new OrderMetaStub();
		$order->meta[ OrderPromotionState::META_APPLIED_PROMOTIONS ] = wp_json_encode(
			array(
				array( 'promotion_id' => 1 ),
				array( 'promotion_id' => 2 ),
			)
		);

		$ids = OrderPromotionState::promotion_ids_from_order( $order );
		$this->assertSame( array( 1, 2 ), $ids );
	}
}

/**
 * Minimal WC_Order meta stub for unit tests.
 */
final class OrderMetaStub {

	/** @var array<string, string> */
	public array $meta = array();

	public function get_meta( string $key, bool $single = true ) {
		if ( ! isset( $this->meta[ $key ] ) ) {
			return '';
		}

		return $this->meta[ $key ];
	}

	public function update_meta_data( string $key, $value ): void {
		$this->meta[ $key ] = (string) $value;
	}

	public function delete_meta_data( string $key ): void {
		unset( $this->meta[ $key ] );
	}
}
