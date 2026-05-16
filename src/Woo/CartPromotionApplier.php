<?php
/**
 * v1: applies promotion discount as a negative cart fee (automatic or via coupon code).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionCode;
use MP\CommercePromotions\Domain\PromotionCodeRepository;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Service\Settings;

final class CartPromotionApplier {

	public const SESSION_KEY = 'mp_cp_applied_promotion';

	public const ACTION_PERCENTAGE_DISCOUNT = 'percentage_discount';

	public const ACTION_FIXED_AMOUNT_DISCOUNT = 'fixed_amount_discount';

	private PromotionRepository $promotions;

	private PromotionCodeRepository $promotion_codes;

	private PromotionEvaluator $evaluator;

	private CartContextBuilder $context_builder;

	private Settings $settings;

	public function __construct(
		PromotionRepository $promotions,
		PromotionCodeRepository $promotion_codes,
		PromotionEvaluator $evaluator,
		CartContextBuilder $context_builder,
		Settings $settings
	) {
		$this->promotions      = $promotions;
		$this->promotion_codes = $promotion_codes;
		$this->evaluator       = $evaluator;
		$this->context_builder = $context_builder;
		$this->settings        = $settings;
	}

	/**
	 * WooCommerce cart fee hook: apply at most one promotion as a negative fee.
	 */
	public function apply(): void {
		/**
		 * Disable cart discount fees (admin setting or custom code).
		 *
		 * @since 0.1.0
		 *
		 * @param bool $enabled Whether to run cart promotion fee logic.
		 */
		if ( ! apply_filters( 'mp_cp_enable_cart_discounts', $this->settings->cart_discounts_enabled() ) ) {
			$this->clear_applied_promotion_session();
			return;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->cart ) || ! is_object( $wc->cart ) ) {
			return;
		}

		$cart = $wc->cart;
		if ( ! method_exists( $cart, 'add_fee' ) ) {
			return;
		}

		$context  = $this->context_builder->build_from_cart();
		$subtotal = $context->get_cart_subtotal();
		if ( $subtotal === null || $subtotal <= 0 ) {
			$this->clear_applied_promotion_session();
			return;
		}

		if ( $this->try_apply_via_applied_coupon_codes( $cart, $context, $subtotal ) ) {
			return;
		}

		$this->apply_automatic_first_eligible_promotion( $cart, $context, $subtotal );
	}

	/**
	 * When a WooCommerce coupon matches a promotion code, apply only that linked promotion.
	 *
	 * @param object $cart WooCommerce cart.
	 */
	private function try_apply_via_applied_coupon_codes( $cart, EvaluationContext $context, float $subtotal ): bool {
		if ( ! method_exists( $cart, 'get_applied_coupons' ) ) {
			return false;
		}

		$coupons = $cart->get_applied_coupons();
		if ( ! is_array( $coupons ) || count( $coupons ) === 0 ) {
			return false;
		}

		foreach ( $coupons as $coupon_code ) {
			if ( ! is_string( $coupon_code ) || $coupon_code === '' ) {
				continue;
			}

			$promotion_code = $this->promotion_codes->find_by_plain_code( $coupon_code );
			if ( $promotion_code === null ) {
				continue;
			}

			if ( ! $this->promotion_codes->is_code_usable( $promotion_code ) ) {
				$this->clear_applied_promotion_session();
				return true;
			}

			$promotion_id = $promotion_code->get_promotion_id();
			$promotion    = $this->promotions->find( $promotion_id );
			if ( $promotion === null ) {
				$this->clear_applied_promotion_session();
				return true;
			}

			$applied = $this->apply_first_discount_fee_for_promotion(
				$promotion,
				$context,
				$subtotal,
				$cart,
				$promotion_code
			);

			if ( is_array( $applied ) ) {
				$this->store_applied_promotion_session(
					$applied['promotion'],
					$applied['discount'],
					$applied['action_type'],
					isset( $applied['percentage'] ) ? (float) $applied['percentage'] : null,
					isset( $applied['fixed_amount'] ) ? (float) $applied['fixed_amount'] : null,
					$promotion_code
				);
				return true;
			}

			$this->clear_applied_promotion_session();
			return true;
		}

		return false;
	}

	/**
	 * @param object $cart WooCommerce cart.
	 */
	private function apply_automatic_first_eligible_promotion( $cart, EvaluationContext $context, float $subtotal ): void {
		$active = $this->promotions->find_active( 50 );
		foreach ( $active as $promotion ) {
			$applied = $this->apply_first_discount_fee_for_promotion( $promotion, $context, $subtotal, $cart, null );
			if ( is_array( $applied ) ) {
				$this->store_applied_promotion_session(
					$applied['promotion'],
					$applied['discount'],
					$applied['action_type'],
					isset( $applied['percentage'] ) ? (float) $applied['percentage'] : null,
					isset( $applied['fixed_amount'] ) ? (float) $applied['fixed_amount'] : null,
					null
				);
				return;
			}
		}

		$this->clear_applied_promotion_session();
	}

	/**
	 * @param float|null $percentage   Configured percentage when action is percentage_discount.
	 * @param float|null $fixed_amount Configured fixed amount when action is fixed_amount_discount.
	 */
	private function store_applied_promotion_session(
		Promotion $promotion,
		float $discount,
		string $action_type,
		?float $percentage = null,
		?float $fixed_amount = null,
		?PromotionCode $promotion_code = null
	): void {
		if ( ! CartSessionHelper::has_wc_session() ) {
			return;
		}

		$pid = $promotion->get_id();
		if ( $pid === null || $pid <= 0 ) {
			return;
		}

		$payload = array(
			'promotion_id'    => $pid,
			'promotion_uuid'  => $promotion->get_uuid(),
			'promotion_name'  => $promotion->get_name(),
			'discount_amount' => $discount,
			'action_type'     => $action_type,
		);

		if ( $percentage !== null ) {
			$payload['percentage'] = $percentage;
		}
		if ( $fixed_amount !== null ) {
			$payload['fixed_amount'] = $fixed_amount;
		}

		if ( $promotion_code !== null ) {
			$code_id = $promotion_code->get_id();
			if ( $code_id !== null && $code_id > 0 ) {
				$payload['promotion_code_id'] = $code_id;
			}
			$payload['promotion_code_last4'] = $promotion_code->get_code_last4();
			$payload['entered_code_hash']    = $promotion_code->get_code_hash();
		}

		CartSessionHelper::set_applied_promotion( $payload );
	}

	private function clear_applied_promotion_session(): void {
		CartSessionHelper::clear_applied_promotion();
	}

	/**
	 * @param object             $cart           WooCommerce cart (WC_Cart).
	 * @param PromotionCode|null $promotion_code When set, fee label uses masked code last4.
	 * @return array<string, mixed>|false
	 */
	private function apply_first_discount_fee_for_promotion(
		Promotion $promotion,
		EvaluationContext $context,
		float $subtotal,
		$cart,
		?PromotionCode $promotion_code
	) {
		$result = $this->evaluator->evaluate( $promotion, $context );
		if ( ! $result->is_eligible() ) {
			return false;
		}

		foreach ( $result->get_action_results() as $action_row ) {
			if ( ! is_array( $action_row ) ) {
				continue;
			}

			$type    = isset( $action_row['type'] ) ? (string) $action_row['type'] : '';
			$payload = isset( $action_row['payload'] ) && is_array( $action_row['payload'] )
				? $action_row['payload']
				: array();

			if ( $type === self::ACTION_PERCENTAGE_DISCOUNT ) {
				$applied = $this->apply_percentage_discount_fee( $promotion, $payload, $subtotal, $cart, $promotion_code );
				if ( is_array( $applied ) ) {
					return $applied;
				}
				continue;
			}

			if ( $type === self::ACTION_FIXED_AMOUNT_DISCOUNT ) {
				$applied = $this->apply_fixed_amount_discount_fee( $promotion, $payload, $subtotal, $cart, $promotion_code );
				if ( is_array( $applied ) ) {
					return $applied;
				}
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param object               $cart
	 * @return array<string, mixed>|false
	 */
	private function apply_percentage_discount_fee(
		Promotion $promotion,
		array $payload,
		float $subtotal,
		$cart,
		?PromotionCode $promotion_code
	) {
		if ( ! isset( $payload['percentage'] ) || ! is_numeric( $payload['percentage'] ) ) {
			return false;
		}

		$pct = (float) $payload['percentage'];
		if ( $pct <= 0 || $pct > 100 ) {
			return false;
		}

		$discount = $subtotal * $pct / 100.0;
		$discount = $this->clamp_discount_to_subtotal( $discount, $subtotal );
		if ( $discount <= 0 ) {
			return false;
		}

		$this->add_promotion_fee( $cart, $promotion, $discount, $promotion_code );

		return array(
			'promotion'   => $promotion,
			'discount'    => $discount,
			'action_type' => self::ACTION_PERCENTAGE_DISCOUNT,
			'percentage'  => $pct,
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param object               $cart
	 * @return array<string, mixed>|false
	 */
	private function apply_fixed_amount_discount_fee(
		Promotion $promotion,
		array $payload,
		float $subtotal,
		$cart,
		?PromotionCode $promotion_code
	) {
		if ( ! isset( $payload['amount'] ) || ! is_numeric( $payload['amount'] ) ) {
			return false;
		}

		$configured = (float) $payload['amount'];
		if ( $configured <= 0 ) {
			return false;
		}

		$discount = $this->clamp_discount_to_subtotal( $configured, $subtotal );
		if ( $discount <= 0 ) {
			return false;
		}

		$this->add_promotion_fee( $cart, $promotion, $discount, $promotion_code );

		return array(
			'promotion'    => $promotion,
			'discount'     => $discount,
			'action_type'  => self::ACTION_FIXED_AMOUNT_DISCOUNT,
			'fixed_amount' => $configured,
		);
	}

	private function clamp_discount_to_subtotal( float $discount, float $subtotal ): float {
		if ( $discount <= 0 ) {
			return 0.0;
		}
		if ( $discount > $subtotal ) {
			return $subtotal;
		}

		return $discount;
	}

	/**
	 * @param object             $cart WooCommerce cart (WC_Cart).
	 * @param PromotionCode|null $promotion_code
	 */
	private function add_promotion_fee( $cart, Promotion $promotion, float $discount, ?PromotionCode $promotion_code ): void {
		if ( $promotion_code !== null ) {
			$last4 = sanitize_text_field( $promotion_code->get_code_last4() );
			$label = sprintf(
				/* translators: %s: last four characters of the promotion code */
				__( 'Commerce promotion code: ****%s', 'mp-commerce-promotions' ),
				$last4
			);
		} else {
			$name = sanitize_text_field( $promotion->get_name() );
			if ( $name === '' ) {
				$name = __( 'Promotion', 'mp-commerce-promotions' );
			}

			$label = sprintf(
				/* translators: %s: sanitized promotion name */
				__( 'Commerce promotion: %s', 'mp-commerce-promotions' ),
				$name
			);
		}

		$cart->add_fee( $label, -$discount, false );
	}
}
