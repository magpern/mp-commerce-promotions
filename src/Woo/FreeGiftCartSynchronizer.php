<?php
/**
 * Keeps plugin-marked free gift cart lines aligned with currently selected promotions.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Service\AuditLogger;

final class FreeGiftCartSynchronizer {

	private ?PromotionRepository $promotions;

	private ?AuditLogger $audit;

	public function __construct( ?PromotionRepository $promotions = null, ?AuditLogger $audit = null ) {
		$this->promotions = $promotions;
		$this->audit      = $audit;
	}

	/**
	 * @param object $cart WooCommerce cart.
	 * @param list<array{
	 *     promotion_id: int,
	 *     product_id: int,
	 *     variation_id: int|null,
	 *     quantity: int,
	 *     promotion?: Promotion|null
	 * }> $desired_gifts Expected gifts from selected eligible promotions.
	 */
	public function sync( $cart, array $desired_gifts ): void {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		$desired_map = $this->normalize_desired_map( $desired_gifts );
		$lines       = self::list_plugin_gift_lines( $cart );

		foreach ( $lines as $line ) {
			$key          = $line['cart_item_key'];
			$promotion_id = $line['promotion_id'];
			$product_id   = $line['product_id'];
			$variation_id = $line['variation_id'];
			$spec_key     = $this->spec_key( $promotion_id, $product_id, $variation_id );

			if ( ! $this->is_promotion_gift_allowed( $promotion_id ) ) {
				$this->remove_gift_line( $cart, $key, $promotion_id, $product_id, 'promotion_inactive_or_missing' );
				continue;
			}

			if ( ! isset( $desired_map[ $spec_key ] ) ) {
				$this->remove_gift_line( $cart, $key, $promotion_id, $product_id, 'promotion_not_selected' );
				continue;
			}

			$expected_qty = $desired_map[ $spec_key ]['quantity'];
			if ( $line['quantity'] !== $expected_qty && method_exists( $cart, 'set_quantity' ) ) {
				$cart->set_quantity( $key, $expected_qty, false );
			}

			unset( $desired_map[ $spec_key ] );
		}

		if ( $desired_map === array() ) {
			return;
		}

		$handler = new FreeGiftCartHandler();
		foreach ( $desired_map as $spec ) {
			$promotion = $spec['promotion'];
			if ( ! $promotion instanceof Promotion ) {
				continue;
			}

			$payload = array(
				'product_id' => $spec['product_id'],
				'quantity'   => $spec['quantity'],
			);
			if ( $spec['variation_id'] !== null && $spec['variation_id'] > 0 ) {
				$payload['variation_id'] = $spec['variation_id'];
			}

			if ( $handler->apply_gift( $promotion, $payload, $cart ) ) {
				$this->log_gift_added( $promotion->get_id() ?? 0, $spec['product_id'], $spec['quantity'] );
			}
		}
	}

	/**
	 * @param object $cart WooCommerce cart.
	 * @return list<array{
	 *     cart_item_key: string,
	 *     promotion_id: int,
	 *     product_id: int,
	 *     variation_id: int,
	 *     quantity: int
	 * }>
	 */
	public static function list_plugin_gift_lines( $cart ): array {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return array();
		}

		$out = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! is_array( $item ) || ! is_string( $key ) ) {
				continue;
			}
			if ( empty( $item[ FreeGiftCartHandler::CART_ITEM_META_FREE_GIFT ] )
				|| $item[ FreeGiftCartHandler::CART_ITEM_META_FREE_GIFT ] !== 'yes' ) {
				continue;
			}

			$promotion_id = isset( $item[ FreeGiftCartHandler::CART_ITEM_META_PROMOTION_ID ] )
				? (int) $item[ FreeGiftCartHandler::CART_ITEM_META_PROMOTION_ID ]
				: 0;
			$product_id   = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$variation_id = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
			$quantity     = isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;

			$out[] = array(
				'cart_item_key' => $key,
				'promotion_id'  => $promotion_id,
				'product_id'    => $product_id,
				'variation_id'  => $variation_id,
				'quantity'      => max( 0, $quantity ),
			);
		}

		return $out;
	}

	/**
	 * @param list<array<string, mixed>> $desired_gifts
	 * @return array<string, array{
	 *     promotion_id: int,
	 *     product_id: int,
	 *     variation_id: int|null,
	 *     quantity: int,
	 *     promotion: Promotion|null
	 * }>
	 */
	private function normalize_desired_map( array $desired_gifts ): array {
		$map = array();
		foreach ( $desired_gifts as $spec ) {
			$promotion_id = isset( $spec['promotion_id'] ) ? (int) $spec['promotion_id'] : 0;
			$product_id   = isset( $spec['product_id'] ) ? (int) $spec['product_id'] : 0;
			$quantity     = isset( $spec['quantity'] ) ? (int) $spec['quantity'] : 0;
			if ( $promotion_id <= 0 || $product_id <= 0 || $quantity < 1 ) {
				continue;
			}

			$variation_id = null;
			if ( isset( $spec['variation_id'] ) && is_numeric( $spec['variation_id'] ) && (int) $spec['variation_id'] > 0 ) {
				$variation_id = (int) $spec['variation_id'];
			}

			$key = $this->spec_key( $promotion_id, $product_id, $variation_id );
			$map[ $key ] = array(
				'promotion_id'  => $promotion_id,
				'product_id'    => $product_id,
				'variation_id'  => $variation_id,
				'quantity'      => $quantity,
				'promotion'     => isset( $spec['promotion'] ) && $spec['promotion'] instanceof Promotion
					? $spec['promotion']
					: null,
			);
		}

		return $map;
	}

	private function spec_key( int $promotion_id, int $product_id, ?int $variation_id ): string {
		$var = $variation_id !== null && $variation_id > 0 ? (string) $variation_id : '0';

		return $promotion_id . ':' . $product_id . ':' . $var;
	}

	private function is_promotion_gift_allowed( int $promotion_id ): bool {
		if ( $promotion_id <= 0 ) {
			return false;
		}

		if ( $this->promotions === null ) {
			return true;
		}

		$promotion = $this->promotions->find( $promotion_id );
		if ( $promotion === null ) {
			return false;
		}

		return $promotion->get_status() === PromotionStatus::ACTIVE;
	}

	/**
	 * @param object $cart WooCommerce cart.
	 */
	private function remove_gift_line( $cart, string $cart_item_key, int $promotion_id, int $product_id, string $reason ): void {
		if ( ! method_exists( $cart, 'remove_cart_item' ) ) {
			return;
		}

		$cart->remove_cart_item( $cart_item_key );
		$this->log_gift_removed( $promotion_id, $product_id, $reason );
	}

	private function log_gift_added( int $promotion_id, int $product_id, int $quantity ): void {
		if ( $this->audit === null || $promotion_id <= 0 ) {
			return;
		}

		$this->audit->log(
			'promotion.gift_added_to_cart',
			$promotion_id,
			array(
				'promotion_id' => $promotion_id,
				'product_id'   => $product_id,
				'quantity'     => $quantity,
			),
			null
		);
	}

	private function log_gift_removed( int $promotion_id, int $product_id, string $reason ): void {
		if ( $this->audit === null || $promotion_id <= 0 ) {
			return;
		}

		$this->audit->log(
			'promotion.gift_removed_from_cart',
			$promotion_id,
			array(
				'promotion_id' => $promotion_id,
				'product_id'   => $product_id,
				'reason'       => $reason,
			),
			null
		);
	}
}
