<?php
/**
 * Validation for gift card recipient checkout fields.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use InvalidArgumentException;

final class GiftCardRecipientValidator {

	public const DEFAULT_MAX_MESSAGE_LENGTH = 500;

	public const DEFAULT_MAX_SCHEDULE_DAYS = 365;

	/**
	 * @param array{sells: bool, amount_mode: string, fixed_amount: float, expiry_days: ?int, recipient_mode: string} $product_config
	 * @param array{recipient_email: string, recipient_name: string, message: string, delivery_timing: string, scheduled_for: string} $delivery
	 * @return array{recipient_email: string, recipient_name: string, message: string, delivery_timing: string, scheduled_for: string}
	 * @throws InvalidArgumentException
	 */
	public static function validate_for_product( array $product_config, array $delivery, ?string $billing_email = null ): array {
		$delivery = GiftCardLineItemMeta::normalize_array( $delivery );
		$mode     = (string) ( $product_config['recipient_mode'] ?? GiftCardProductMeta::RECIPIENT_PURCHASER_ONLY );

		if ( $mode === GiftCardProductMeta::RECIPIENT_PURCHASER_ONLY ) {
			$email = $billing_email !== null ? sanitize_email( $billing_email ) : '';
			if ( $email === '' || ! is_email( $email ) ) {
				throw new InvalidArgumentException(
					__( 'A valid billing email is required for gift card delivery.', 'mp-commerce-promotions' )
				);
			}

			return array(
				'recipient_email'  => $email,
				'recipient_name'   => '',
				'message'          => '',
				'delivery_timing'  => GiftCardLineItemMeta::TIMING_SEND_NOW,
				'scheduled_for'    => '',
			);
		}

		$email = $delivery['recipient_email'];
		if ( $email === '' || ! is_email( $email ) ) {
			throw new InvalidArgumentException(
				__( 'Please enter a valid gift card recipient email address.', 'mp-commerce-promotions' )
			);
		}

		if ( $mode === GiftCardProductMeta::RECIPIENT_EMAIL_AND_MESSAGE ) {
			$max_len = self::max_message_length();
			if ( strlen( $delivery['message'] ) > $max_len ) {
				throw new InvalidArgumentException(
					sprintf(
						/* translators: %d: max characters */
						__( 'Gift card message must be %d characters or fewer.', 'mp-commerce-promotions' ),
						$max_len
					)
				);
			}
		} else {
			$delivery['message'] = '';
		}

		$timing = $delivery['delivery_timing'];
		if ( $timing === GiftCardLineItemMeta::TIMING_SEND_ON_DATE ) {
			$delivery['scheduled_for'] = self::validate_scheduled_date( $delivery['scheduled_for'] );
		} else {
			$delivery['delivery_timing'] = GiftCardLineItemMeta::TIMING_SEND_NOW;
			$delivery['scheduled_for']   = '';
		}

		return $delivery;
	}

	public static function validate_scheduled_date( string $date ): string {
		$date = trim( $date );
		if ( $date === '' ) {
			throw new InvalidArgumentException(
				__( 'Please choose a delivery date for the gift card.', 'mp-commerce-promotions' )
			);
		}

		$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d', $date );
		if ( $parsed === false || $parsed->format( 'Y-m-d' ) !== $date ) {
			throw new InvalidArgumentException(
				__( 'Gift card delivery date must be a valid date (YYYY-MM-DD).', 'mp-commerce-promotions' )
			);
		}

		$today = self::site_today();
		if ( $parsed < $today ) {
			throw new InvalidArgumentException(
				__( 'Gift card delivery date cannot be in the past.', 'mp-commerce-promotions' )
			);
		}

		$max_days = self::max_schedule_days();
		$limit    = $today->modify( '+' . $max_days . ' days' );
		if ( $parsed > $limit ) {
			throw new InvalidArgumentException(
				sprintf(
					/* translators: %d: maximum days ahead */
					__( 'Gift card delivery date cannot be more than %d days in the future.', 'mp-commerce-promotions' ),
					$max_days
				)
			);
		}

		return $date;
	}

	public static function max_message_length(): int {
		$max = (int) apply_filters( 'mp_cp_gift_card_max_message_length', self::DEFAULT_MAX_MESSAGE_LENGTH );
		return max( 50, min( 2000, $max ) );
	}

	public static function max_schedule_days(): int {
		$max = (int) apply_filters( 'mp_cp_gift_card_schedule_max_days', self::DEFAULT_MAX_SCHEDULE_DAYS );
		return max( 1, min( 730, $max ) );
	}

	private static function site_today(): \DateTimeImmutable {
		if ( function_exists( 'current_time' ) ) {
			$mysql = current_time( 'Y-m-d' );
			$dt    = \DateTimeImmutable::createFromFormat( 'Y-m-d', $mysql );
			if ( $dt !== false ) {
				return $dt;
			}
		}

		return new \DateTimeImmutable( 'today' );
	}
}
