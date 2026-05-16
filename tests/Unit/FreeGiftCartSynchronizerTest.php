<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Woo\FreeGiftCartHandler;
use MP\CommercePromotions\Woo\FreeGiftCartSynchronizer;
use PHPUnit\Framework\TestCase;

final class FreeGiftCartSynchronizerTest extends TestCase {

	public function test_list_plugin_gift_lines_ignores_non_gift_items(): void {
		$cart = new CartStub(
			array(
				'paid' => array(
					'product_id' => 99,
					'quantity'   => 1,
				),
				'gift' => array(
					FreeGiftCartHandler::CART_ITEM_META_FREE_GIFT     => 'yes',
					FreeGiftCartHandler::CART_ITEM_META_PROMOTION_ID  => '5',
					'product_id'                                    => 10,
					'variation_id'                                  => 0,
					'quantity'                                      => 2,
				),
			)
		);

		$lines = FreeGiftCartSynchronizer::list_plugin_gift_lines( $cart );
		$this->assertCount( 1, $lines );
		$this->assertSame( 5, $lines[0]['promotion_id'] );
		$this->assertSame( 2, $lines[0]['quantity'] );
	}

	public function test_sync_removes_stale_gift_not_in_desired(): void {
		$cart = new CartStub(
			array(
				'stale' => array(
					FreeGiftCartHandler::CART_ITEM_META_FREE_GIFT    => 'yes',
					FreeGiftCartHandler::CART_ITEM_META_PROMOTION_ID => '9',
					'product_id'                                   => 20,
					'variation_id'                                 => 0,
					'quantity'                                     => 1,
				),
			)
		);

		$sync = new FreeGiftCartSynchronizer();
		$sync->sync( $cart, array() );

		$this->assertArrayNotHasKey( 'stale', $cart->items );
	}

	public function test_sync_normalizes_quantity(): void {
		$promotion = $this->make_promotion( 3 );
		$cart      = new CartStub(
			array(
				'gift' => array(
					FreeGiftCartHandler::CART_ITEM_META_FREE_GIFT    => 'yes',
					FreeGiftCartHandler::CART_ITEM_META_PROMOTION_ID => '3',
					'product_id'                                   => 50,
					'variation_id'                                 => 0,
					'quantity'                                     => 5,
				),
			)
		);

		$sync = new FreeGiftCartSynchronizer();
		$sync->sync(
			$cart,
			array(
				array(
					'promotion_id' => 3,
					'product_id'   => 50,
					'variation_id' => null,
					'quantity'     => 2,
					'promotion'    => $promotion,
				),
			)
		);

		$this->assertSame( 2, $cart->items['gift']['quantity'] );
	}

	private function make_promotion( int $id ): Promotion {
		return Promotion::from_array(
			array(
				'id'                   => $id,
				'uuid'                 => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
				'name'                 => 'Gift promo',
				'status'               => PromotionStatus::ACTIVE,
				'priority'             => 10,
				'conditions'           => array(),
				'actions'              => array(),
				'restrictions'         => array(),
				'usage_count'          => 0,
				'usage_limit'          => null,
				'customer_usage_limit' => null,
				'starts_at'            => null,
				'ends_at'              => null,
			)
		);
	}
}

/**
 * @internal
 */
final class CartStub {

	/** @var array<string, array<string, mixed>> */
	public array $items;

	public function __construct( array $items ) {
		$this->items = $items;
	}

	public function get_cart(): array {
		return $this->items;
	}

	public function remove_cart_item( string $key ): void {
		unset( $this->items[ $key ] );
	}

	public function set_quantity( string $key, int $qty, bool $refresh = false ): void {
		if ( isset( $this->items[ $key ] ) ) {
			$this->items[ $key ]['quantity'] = $qty;
		}
	}
}
