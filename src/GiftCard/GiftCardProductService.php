<?php
/**
 * Gift card product configuration and line amount resolution.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use InvalidArgumentException;

final class GiftCardProductService {

	public function is_gift_card_product( int $product_id ): bool {
		return $this->product_sells_gift_card( $product_id, 0 );
	}

	public function product_sells_gift_card( int $product_id, int $variation_id = 0 ): bool {
		return $this->get_line_config( $product_id, $variation_id ) !== null;
	}

	/**
	 * Resolve config for a line item (variation or simple product).
	 *
	 * @return array{
	 *   sells: bool,
	 *   amount_mode: string,
	 *   fixed_amount: float,
	 *   expiry_days: ?int,
	 *   recipient_mode: string,
	 *   min_amount: float,
	 *   max_amount: ?float,
	 *   suggested_amounts: list<float>,
	 *   default_amount: ?float
	 * }|null
	 */
	public function get_line_config( int $product_id, int $variation_id = 0 ): ?array {
		$target = $variation_id > 0 ? $variation_id : $product_id;
		if ( $target <= 0 ) {
			return null;
		}

		$config = GiftCardProductMeta::read( $target );
		if ( ! $config['sells'] && $variation_id > 0 && $product_id > 0 ) {
			$config = GiftCardProductMeta::read( $product_id );
		}

		return $config['sells'] ? $config : null;
	}

	/**
	 * Per-unit gift card amount for a purchased line.
	 *
	 * @param array{
	 *   sells: bool,
	 *   amount_mode: string,
	 *   fixed_amount: float,
	 *   expiry_days: ?int,
	 *   recipient_mode: string,
	 *   min_amount: float,
	 *   max_amount: ?float,
	 *   suggested_amounts: list<float>,
	 *   default_amount: ?float
	 * } $config
	 */
	public function resolve_unit_amount( array $config, float $line_subtotal, int $quantity, ?float $customer_chosen_amount = null ): float {
		$quantity = max( 1, $quantity );

		if ( $config['amount_mode'] === GiftCardProductMeta::AMOUNT_MODE_FIXED ) {
			$amount = GiftCard::money( $config['fixed_amount'] );
		} elseif ( $config['amount_mode'] === GiftCardProductMeta::AMOUNT_MODE_CUSTOMER_AMOUNT ) {
			if ( $customer_chosen_amount !== null && $customer_chosen_amount > 0 ) {
				$amount = GiftCard::money( $customer_chosen_amount );
			} else {
				$amount = GiftCard::money( $line_subtotal / $quantity );
			}
		} else {
			$amount = GiftCard::money( $line_subtotal / $quantity );
		}

		if ( $amount <= 0 ) {
			throw new InvalidArgumentException( 'Gift card product amount must be greater than zero.' );
		}

		return $amount;
	}

	public function resolve_expires_at( ?int $expiry_days, string $paid_at_mysql ): ?string {
		if ( $expiry_days === null || $expiry_days <= 0 ) {
			return null;
		}

		$ts = strtotime( $paid_at_mysql );
		if ( $ts === false ) {
			return null;
		}

		$day_seconds = defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;

		return gmdate( 'Y-m-d H:i:s', $ts + ( $expiry_days * $day_seconds ) );
	}
}
