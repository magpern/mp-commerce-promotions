<?php
/**
 * Evaluates native Woo coupon vs plugin promotion coexistence.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionCouponBehavior;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PromotionEvaluationDecision;

final class CouponCoexistenceEvaluator {

	public const MODE_ALLOW = 'allow';

	public const MODE_WARN = 'warn';

	public const MODE_BLOCK = 'block';

	/**
	 * @return array{mode: string, native_coupon_count: int, message: string, codes: list<string>}
	 */
	public function evaluate_cart( ?object $cart = null ): array {
		$codes  = $this->native_coupon_codes( $cart );
		$count  = count( $codes );
		$mode   = self::MODE_ALLOW;
		$message = '';

		if ( $count > 0 ) {
			$mode    = self::MODE_WARN;
			$message = sprintf(
				/* translators: %d: coupon count */
				_n(
					'%d native WooCommerce coupon applied.',
					'%d native WooCommerce coupons applied.',
					$count,
					'mp-commerce-promotions'
				),
				$count
			);
		}

		if ( $count > 2 ) {
			$mode    = self::MODE_WARN;
			$message = __( 'Multiple native coupons may stack with plugin promotions.', 'mp-commerce-promotions' );
		}

		return array(
			'mode'                 => $mode,
			'native_coupon_count'  => $count,
			'message'              => $message,
			'codes'                => $codes,
		);
	}

	/**
	 * @return array{allowed: bool, reason: string|null, severity: string}
	 */
	public function evaluate_promotion( Promotion $promotion, EvaluationContext $context, ?object $cart = null ): array {
		$behavior = $promotion->get_coupon_behavior();
		$native   = $this->evaluate_cart( $cart );
		$native   = $this->merge_context_coupon_codes( $native, $context );
		$count    = $native['native_coupon_count'];

		if ( $behavior === PromotionCouponBehavior::BLOCK_NATIVE && $count > 0 ) {
			return array(
				'allowed'  => false,
				'reason'   => PromotionEvaluationDecision::REASON_BLOCKED_BY_COUPON,
				'severity' => self::MODE_BLOCK,
			);
		}

		if ( $behavior === PromotionCouponBehavior::REQUIRE_NO_COUPON && $count === 0 ) {
			return array(
				'allowed'  => false,
				'reason'   => PromotionEvaluationDecision::REASON_COUPON_REQUIRED_ABSENT,
				'severity' => self::MODE_BLOCK,
			);
		}

		if ( $count > 0 && $this->promotion_has_free_shipping( $promotion ) && $this->cart_has_shipping_coupon( $cart ) ) {
			return array(
				'allowed'  => false,
				'reason'   => PromotionEvaluationDecision::REASON_BLOCKED_BY_COUPON,
				'severity' => self::MODE_WARN,
			);
		}

		if ( $count > 0 && $behavior === PromotionCouponBehavior::COEXIST ) {
			return array(
				'allowed'  => true,
				'reason'   => null,
				'severity' => self::MODE_WARN,
			);
		}

		return array(
			'allowed'  => true,
			'reason'   => null,
			'severity' => self::MODE_ALLOW,
		);
	}

	/**
	 * @return list<string>
	 */
	/**
	 * @param array{mode: string, native_coupon_count: int, message: string, codes: list<string>} $native
	 * @return array{mode: string, native_coupon_count: int, message: string, codes: list<string>}
	 */
	private function merge_context_coupon_codes( array $native, EvaluationContext $context ): array {
		$meta = $context->get_metadata();
		if ( ! is_array( $meta ) || empty( $meta['native_coupon_codes'] ) || ! is_array( $meta['native_coupon_codes'] ) ) {
			return $native;
		}

		$codes = array_values(
			array_unique(
				array_merge(
					$native['codes'],
					array_filter( array_map( 'strval', $meta['native_coupon_codes'] ) )
				)
			)
		);
		$count = count( $codes );
		if ( $count <= 0 ) {
			return $native;
		}

		$native['codes']               = $codes;
		$native['native_coupon_count']   = $count;
		$native['mode']                  = $count > 0 ? self::MODE_WARN : $native['mode'];
		$native['message'] = $count === 1
			? __( '1 native WooCommerce coupon in context.', 'mp-commerce-promotions' )
			: sprintf(
				/* translators: %d: coupon count */
				__( '%d native WooCommerce coupons in context.', 'mp-commerce-promotions' ),
				$count
			);

		return $native;
	}

	/**
	 * @return list<string>
	 */
	private function native_coupon_codes( ?object $cart ): array {
		if ( $cart === null && function_exists( 'WC' ) && WC()->cart ) {
			$cart = WC()->cart;
		}

		if ( $cart === null || ! is_object( $cart ) || ! method_exists( $cart, 'get_applied_coupons' ) ) {
			return array();
		}

		$codes = $cart->get_applied_coupons();
		if ( ! is_array( $codes ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strval', $codes ) ) );
	}

	private function promotion_has_free_shipping( Promotion $promotion ): bool {
		foreach ( $promotion->get_actions() as $action ) {
			if ( is_array( $action ) && ( $action['type'] ?? '' ) === 'free_shipping' ) {
				return true;
			}
		}

		return false;
	}

	private function cart_has_shipping_coupon( ?object $cart ): bool {
		foreach ( $this->native_coupon_codes( $cart ) as $code ) {
			if ( function_exists( 'wc_get_coupon' ) ) {
				$coupon = wc_get_coupon( $code );
				if ( $coupon && method_exists( $coupon, 'get_free_shipping' ) && $coupon->get_free_shipping() ) {
					return true;
				}
			}
		}

		return false;
	}
}
