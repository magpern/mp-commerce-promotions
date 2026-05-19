<?php
/**
 * Map Commerce promotion cart fee labels to promotion IDs.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionCode;

final class PromotionFeeLabelResolver {

	/**
	 * @param array<string, mixed> $entry Applied promotion session entry.
	 */
	public static function label_from_entry( array $entry ): ?string {
		$name = isset( $entry['promotion_name'] ) ? sanitize_text_field( (string) $entry['promotion_name'] ) : '';
		if ( $name === '' ) {
			$name = __( 'Promotion', 'mp-commerce-promotions' );
		}

		$action_type = isset( $entry['action_type'] ) ? (string) $entry['action_type'] : '';
		$last4       = isset( $entry['promotion_code_last4'] ) ? sanitize_text_field( (string) $entry['promotion_code_last4'] ) : '';

		if ( $last4 !== '' ) {
			return self::label_for_code( $last4, $action_type );
		}

		return self::label_for_automatic( $name, $action_type );
	}

	public static function label_for_automatic( string $promotion_name, string $action_type ): string {
		if ( $action_type === CartPromotionApplier::ACTION_FREE_SHIPPING ) {
			return sprintf(
				/* translators: %s: sanitized promotion name */
				__( 'Commerce promotion: Free shipping - %s', 'mp-commerce-promotions' ),
				$promotion_name
			);
		}

		if ( $action_type === CartPromotionApplier::ACTION_CHEAPEST_ITEM_DISCOUNT ) {
			return sprintf(
				/* translators: %s: sanitized promotion name */
				__( 'Commerce promotion: Cheapest item discount - %s', 'mp-commerce-promotions' ),
				$promotion_name
			);
		}

		return sprintf(
			/* translators: %s: sanitized promotion name */
			__( 'Commerce promotion: %s', 'mp-commerce-promotions' ),
			$promotion_name
		);
	}

	public static function label_for_code( string $last4, string $action_type ): string {
		if ( $action_type === CartPromotionApplier::ACTION_FREE_SHIPPING ) {
			return sprintf(
				/* translators: %s: last four characters of the promotion code */
				__( 'Commerce promotion code: Free shipping ****%s', 'mp-commerce-promotions' ),
				$last4
			);
		}

		if ( $action_type === CartPromotionApplier::ACTION_CHEAPEST_ITEM_DISCOUNT ) {
			return sprintf(
				/* translators: %s: last four characters of the promotion code */
				__( 'Commerce promotion code: Cheapest item discount ****%s', 'mp-commerce-promotions' ),
				$last4
			);
		}

		return sprintf(
			/* translators: %s: last four characters of the promotion code */
			__( 'Commerce promotion code: ****%s', 'mp-commerce-promotions' ),
			$last4
		);
	}

	public static function label_for_promotion(
		Promotion $promotion,
		?PromotionCode $promotion_code,
		string $action_type
	): string {
		if ( $promotion_code !== null ) {
			$last4 = sanitize_text_field( $promotion_code->get_code_last4() );

			return self::label_for_code( $last4, $action_type );
		}

		$name = sanitize_text_field( $promotion->get_name() );
		if ( $name === '' ) {
			$name = __( 'Promotion', 'mp-commerce-promotions' );
		}

		return self::label_for_automatic( $name, $action_type );
	}

	/**
	 * @param list<array<string, mixed>> $entries
	 */
	public static function promotion_id_from_fee_label( string $fee_label, array $entries ): ?int {
		$fee_label = trim( $fee_label );
		if ( $fee_label === '' || $entries === array() ) {
			return null;
		}

		foreach ( $entries as $entry ) {
			if ( ! AppliedPromotionSession::is_valid_entry( $entry ) ) {
				continue;
			}

			$expected = self::label_from_entry( $entry );
			if ( $expected !== null && $expected === $fee_label ) {
				return (int) $entry['promotion_id'];
			}
		}

		$name = self::promotion_name_from_fee_label( $fee_label );
		if ( $name === null ) {
			return null;
		}

		foreach ( $entries as $entry ) {
			if ( ! AppliedPromotionSession::is_valid_entry( $entry ) ) {
				continue;
			}
			$entry_name = isset( $entry['promotion_name'] ) ? trim( (string) $entry['promotion_name'] ) : '';
			if ( $entry_name !== '' && $entry_name === $name ) {
				return (int) $entry['promotion_id'];
			}
		}

		return null;
	}

	public static function promotion_name_from_fee_label( string $label ): ?string {
		$label = trim( $label );
		if ( $label === '' ) {
			return null;
		}

		$prefixes = array(
			'Commerce promotion: Free shipping - ',
			'Commerce promotion: Cheapest item discount - ',
			'Commerce promotion: ',
		);

		foreach ( $prefixes as $prefix ) {
			if ( strncmp( $label, $prefix, strlen( $prefix ) ) === 0 ) {
				$name = trim( substr( $label, strlen( $prefix ) ) );

				return $name !== '' ? $name : null;
			}
		}

		return null;
	}
}
