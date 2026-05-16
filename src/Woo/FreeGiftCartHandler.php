<?php
/**
 * Adds free gift lines to the cart and zeroes their price before totals calculation.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Domain\Promotion;

final class FreeGiftCartHandler {

	public const CART_ITEM_META_FREE_GIFT = 'mp_cp_free_gift';

	public const CART_ITEM_META_PROMOTION_ID = 'mp_cp_promotion_id';

	public const CART_ITEM_META_PROMOTION_UUID = 'mp_cp_promotion_uuid';

	public const CART_ITEM_META_PROMOTION_NAME = 'mp_cp_promotion_name';

	private static bool $adding_gift = false;

	/**
	 * @param array<string, mixed> $payload Action preview payload.
	 */
	public function apply_gift( Promotion $promotion, array $payload, $cart ): bool {
		if ( self::$adding_gift ) {
			return false;
		}

		if ( ! is_object( $cart ) || ! method_exists( $cart, 'add_to_cart' ) || ! method_exists( $cart, 'get_cart' ) ) {
			return false;
		}

		$product_id = isset( $payload['product_id'] ) ? (int) $payload['product_id'] : 0;
		$quantity   = isset( $payload['quantity'] ) ? (int) $payload['quantity'] : 0;
		if ( $product_id <= 0 || $quantity < 1 ) {
			return false;
		}

		$variation_id = null;
		if ( isset( $payload['variation_id'] ) && is_numeric( $payload['variation_id'] ) && (int) $payload['variation_id'] > 0 ) {
			$variation_id = (int) $payload['variation_id'];
		}

		$promotion_id = $promotion->get_id();
		if ( $promotion_id === null || $promotion_id <= 0 ) {
			return false;
		}

		if ( $this->cart_has_gift_for_promotion( $cart, $promotion_id, $product_id, $variation_id ) ) {
			return true;
		}

		if ( ! $this->is_gift_product_purchasable( $product_id, $variation_id ) ) {
			return false;
		}

		$cart_item_data = array(
			self::CART_ITEM_META_FREE_GIFT      => 'yes',
			self::CART_ITEM_META_PROMOTION_ID   => (string) $promotion_id,
			self::CART_ITEM_META_PROMOTION_UUID  => $promotion->get_uuid(),
			self::CART_ITEM_META_PROMOTION_NAME => $promotion->get_name(),
		);

		$add_variation_id = $variation_id !== null ? $variation_id : 0;

		self::$adding_gift = true;
		try {
			$key = $cart->add_to_cart( $product_id, $quantity, $add_variation_id, array(), $cart_item_data );
		} catch ( \Throwable $e ) {
			$key = false;
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'[mp-commerce-promotions] FreeGiftCartHandler::apply_gift failed: %s',
						$e->getMessage()
					)
				);
			}
		} finally {
			self::$adding_gift = false;
		}

		return $key !== false && $key !== '';
	}

	/**
	 * @param object $cart WooCommerce cart.
	 */
	public static function zero_gift_line_prices( $cart ): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		$items = $cart->get_cart();
		if ( ! is_array( $items ) ) {
			return;
		}

		foreach ( $items as $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}
			if ( empty( $cart_item[ self::CART_ITEM_META_FREE_GIFT ] ) || $cart_item[ self::CART_ITEM_META_FREE_GIFT ] !== 'yes' ) {
				continue;
			}
			if ( ! isset( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) ) {
				continue;
			}
			if ( ! method_exists( $cart_item['data'], 'set_price' ) ) {
				continue;
			}

			$cart_item['data']->set_price( 0 );
		}
	}

	/**
	 * Sum line subtotals for cart rows that are not promotion free gifts.
	 *
	 * @param object $cart WooCommerce cart.
	 */
	public static function paid_cart_subtotal( $cart ): float {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return 0.0;
		}

		$total = 0.0;
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}
			if ( ! empty( $cart_item[ self::CART_ITEM_META_FREE_GIFT ] ) && $cart_item[ self::CART_ITEM_META_FREE_GIFT ] === 'yes' ) {
				continue;
			}
			if ( isset( $cart_item['line_subtotal'] ) && is_numeric( $cart_item['line_subtotal'] ) ) {
				$total += (float) $cart_item['line_subtotal'];
			}
		}

		return max( 0.0, $total );
	}

	/**
	 * @param object $cart WooCommerce cart.
	 */
	private function cart_has_gift_for_promotion( $cart, int $promotion_id, int $product_id, ?int $variation_id ): bool {
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}
			if ( empty( $cart_item[ self::CART_ITEM_META_FREE_GIFT ] ) || $cart_item[ self::CART_ITEM_META_FREE_GIFT ] !== 'yes' ) {
				continue;
			}
			$item_promotion_id = isset( $cart_item[ self::CART_ITEM_META_PROMOTION_ID ] )
				? (int) $cart_item[ self::CART_ITEM_META_PROMOTION_ID ]
				: 0;
			if ( $item_promotion_id !== $promotion_id ) {
				continue;
			}
			$item_product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
			if ( $item_product_id !== $product_id ) {
				continue;
			}
			$item_variation_id = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
			$expected_variation = $variation_id !== null ? $variation_id : 0;
			if ( $item_variation_id === $expected_variation ) {
				return true;
			}
		}

		return false;
	}

	private function is_gift_product_purchasable( int $product_id, ?int $variation_id ): bool {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$target_id = $variation_id !== null && $variation_id > 0 ? $variation_id : $product_id;
		$product   = wc_get_product( $target_id );
		if ( ! is_object( $product ) || ! method_exists( $product, 'is_purchasable' ) ) {
			return false;
		}

		return $product->is_purchasable();
	}
}
