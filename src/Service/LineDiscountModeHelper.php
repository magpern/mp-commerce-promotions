<?php
/**
 * Pure helpers for line / hybrid discount application mode (admin + reports).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionDiscountApplicationMode;
use MP\CommercePromotions\Engine\RuleTypes;

final class LineDiscountModeHelper {

	/** @var list<string> */
	public const FEE_OR_GIFT_ACTIONS = array(
		RuleTypes::ACTION_FREE_SHIPPING,
		RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT,
		RuleTypes::ACTION_FREE_GIFT_PRODUCT,
	);

	public static function list_badge_label( string $mode ): ?string {
		$mode = PromotionDiscountApplicationMode::normalize( $mode );
		if ( $mode === PromotionDiscountApplicationMode::LINE_ITEM ) {
			return 'Line';
		}
		if ( $mode === PromotionDiscountApplicationMode::HYBRID ) {
			return 'Hybrid';
		}

		return null;
	}

	/**
	 * @param list<array<string, mixed>> $actions
	 * @return array{line_capable: list<string>, fee_or_gift: list<string>}
	 */
	public static function classify_actions( array $actions ): array {
		$line_capable = array();
		$fee_or_gift  = array();

		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$type = isset( $action['type'] ) ? (string) $action['type'] : '';
			if ( $type === '' ) {
				continue;
			}
			if ( PromotionDiscountApplicationMode::is_line_capable_action( $type ) ) {
				$line_capable[] = $type;
			} elseif ( in_array( $type, self::FEE_OR_GIFT_ACTIONS, true ) ) {
				$fee_or_gift[] = $type;
			}
		}

		return array(
			'line_capable' => array_values( array_unique( $line_capable ) ),
			'fee_or_gift'  => array_values( array_unique( $fee_or_gift ) ),
		);
	}

	/**
	 * @param list<array<string, mixed>> $actions
	 */
	public static function supported_actions_summary( array $actions ): string {
		$classified = self::classify_actions( $actions );
		if ( $classified['line_capable'] === array() ) {
			return __( 'None (add percentage or fixed amount discount)', 'mp-commerce-promotions' );
		}

		return implode( ', ', $classified['line_capable'] );
	}

	/**
	 * @param list<array<string, mixed>> $actions
	 */
	public static function unsupported_actions_fallback_summary( array $actions, string $mode ): string {
		$classified = self::classify_actions( $actions );
		if ( $classified['fee_or_gift'] === array() ) {
			return __( 'None configured', 'mp-commerce-promotions' );
		}

		$prefix = PromotionDiscountApplicationMode::allows_fee_fallback( $mode )
			? __( 'Applied as fee/gift:', 'mp-commerce-promotions' )
			: __( 'Not applied on line path:', 'mp-commerce-promotions' );

		return $prefix . ' ' . implode( ', ', $classified['fee_or_gift'] );
	}

	public static function per_action_fee_fallback_message( string $action_type ): ?string {
		if ( ! in_array( $action_type, self::FEE_OR_GIFT_ACTIONS, true ) ) {
			return null;
		}

		return __( 'This action remains fee-based/gift-based under line mode.', 'mp-commerce-promotions' );
	}

	public static function uses_experimental_line_mode( Promotion $promotion ): bool {
		return PromotionDiscountApplicationMode::uses_line_mutation( $promotion->get_discount_application_mode() );
	}
}
