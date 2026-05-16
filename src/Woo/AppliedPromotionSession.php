<?php
/**
 * Normalize read/write shapes for mp_cp_applied_promotion session payload.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

final class AppliedPromotionSession {

	/**
	 * @param array<string, mixed>|null $raw Session payload from CartSessionHelper.
	 * @return list<array<string, mixed>>
	 */
	public static function entries_from_session( ?array $raw ): array {
		if ( $raw === null ) {
			return array();
		}

		if ( isset( $raw['applied_promotions'] ) && is_array( $raw['applied_promotions'] ) ) {
			$entries = array();
			foreach ( $raw['applied_promotions'] as $entry ) {
				if ( is_array( $entry ) && self::is_valid_entry( $entry ) ) {
					$entries[] = $entry;
				}
			}

			return $entries;
		}

		if ( self::is_valid_entry( $raw ) ) {
			return array( $raw );
		}

		return array();
	}

	/**
	 * @param list<array<string, mixed>> $entries
	 * @return array<string, mixed>
	 */
	public static function build_session_payload( array $entries ): array {
		if ( $entries === array() ) {
			return array();
		}

		$first = $entries[0];
		$total = 0.0;
		foreach ( $entries as $entry ) {
			if ( isset( $entry['discount_amount'] ) && is_numeric( $entry['discount_amount'] ) ) {
				$total += (float) $entry['discount_amount'];
			}
		}

		$payload = array(
			'promotion_id'        => (int) $first['promotion_id'],
			'promotion_uuid'      => (string) $first['promotion_uuid'],
			'promotion_name'      => (string) $first['promotion_name'],
			'discount_amount'     => (float) $first['discount_amount'],
			'action_type'         => (string) $first['action_type'],
			'applied_promotions'  => $entries,
			'total_discount_amount' => $total,
		);

		if ( isset( $first['percentage'] ) ) {
			$payload['percentage'] = (float) $first['percentage'];
		}
		if ( isset( $first['fixed_amount'] ) ) {
			$payload['fixed_amount'] = (float) $first['fixed_amount'];
		}
		if ( isset( $first['promotion_code_id'] ) ) {
			$payload['promotion_code_id'] = (int) $first['promotion_code_id'];
		}
		if ( isset( $first['promotion_code_last4'] ) ) {
			$payload['promotion_code_last4'] = (string) $first['promotion_code_last4'];
		}
		if ( isset( $first['entered_code_hash'] ) ) {
			$payload['entered_code_hash'] = (string) $first['entered_code_hash'];
		}

		return $payload;
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	public static function is_valid_entry( array $entry ): bool {
		$promotion_id = isset( $entry['promotion_id'] ) ? (int) $entry['promotion_id'] : 0;
		if ( $promotion_id <= 0 ) {
			return false;
		}
		if ( ! isset( $entry['discount_amount'] ) || ! is_numeric( $entry['discount_amount'] ) ) {
			return false;
		}
		if ( (float) $entry['discount_amount'] < 0 ) {
			return false;
		}

		$action_type = isset( $entry['action_type'] ) ? (string) $entry['action_type'] : '';

		if ( $action_type === CartPromotionApplier::ACTION_FREE_GIFT_PRODUCT ) {
			$product_id = isset( $entry['product_id'] ) ? (int) $entry['product_id'] : 0;
			$quantity   = isset( $entry['quantity'] ) ? (int) $entry['quantity'] : 0;

			return $product_id > 0 && $quantity >= 1;
		}

		return $action_type === CartPromotionApplier::ACTION_PERCENTAGE_DISCOUNT
			|| $action_type === CartPromotionApplier::ACTION_FIXED_AMOUNT_DISCOUNT
			|| $action_type === CartPromotionApplier::ACTION_FREE_SHIPPING
			|| $action_type === CartPromotionApplier::ACTION_CHEAPEST_ITEM_DISCOUNT;
	}
}
