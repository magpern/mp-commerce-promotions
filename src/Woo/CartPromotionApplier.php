<?php
/**
 * v1: applies the first eligible active promotion as a negative cart fee.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Service\Settings;

final class CartPromotionApplier {

	public const SESSION_KEY = 'mp_cp_applied_promotion';

	public const ACTION_PERCENTAGE_DISCOUNT = 'percentage_discount';

	public const ACTION_FIXED_AMOUNT_DISCOUNT = 'fixed_amount_discount';

	private PromotionRepository $promotions;

	private PromotionEvaluator $evaluator;

	private CartContextBuilder $context_builder;

	private Settings $settings;

	public function __construct(
		PromotionRepository $promotions,
		PromotionEvaluator $evaluator,
		CartContextBuilder $context_builder,
		Settings $settings
	) {
		$this->promotions       = $promotions;
		$this->evaluator        = $evaluator;
		$this->context_builder = $context_builder;
		$this->settings         = $settings;
	}

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

		$context = $this->context_builder->build_from_cart();
		$subtotal = $context->get_cart_subtotal();
		if ( $subtotal === null || $subtotal <= 0 ) {
			$this->clear_applied_promotion_session();
			return;
		}

		$active = $this->promotions->find_active( 50 );
		foreach ( $active as $promotion ) {
			$applied = $this->apply_first_discount_fee_for_promotion( $promotion, $context, $subtotal, $cart );
			if ( is_array( $applied ) ) {
				$this->store_applied_promotion_session(
					$applied['promotion'],
					$applied['discount'],
					$applied['action_type'],
					isset( $applied['percentage'] ) ? (float) $applied['percentage'] : null,
					isset( $applied['fixed_amount'] ) ? (float) $applied['fixed_amount'] : null
				);
				return;
			}
		}

		$this->clear_applied_promotion_session();
	}

	/**
	 * @param float|null $percentage     Configured percentage when action is percentage_discount.
	 * @param float|null $fixed_amount   Configured fixed amount when action is fixed_amount_discount.
	 */
	private function store_applied_promotion_session(
		Promotion $promotion,
		float $discount,
		string $action_type,
		?float $percentage = null,
		?float $fixed_amount = null
	): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$wc = WC();
		if ( ! is_object( $wc ) || empty( $wc->session ) ) {
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

		$wc->session->set( self::SESSION_KEY, $payload );
	}

	private function clear_applied_promotion_session(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$wc = WC();
		if ( ! is_object( $wc ) || empty( $wc->session ) ) {
			return;
		}

		$session = $wc->session;
		if ( $session instanceof \ArrayAccess ) {
			unset( $session[ self::SESSION_KEY ] );
		}
		if ( method_exists( $session, 'set' ) ) {
			$session->set( self::SESSION_KEY, null );
		}
	}

	/**
	 * @param object $cart WooCommerce cart (WC_Cart).
	 * @return array<string, mixed>|false
	 */
	private function apply_first_discount_fee_for_promotion(
		Promotion $promotion,
		EvaluationContext $context,
		float $subtotal,
		$cart
	) {
		$result = $this->evaluator->evaluate( $promotion, $context );
		if ( ! $result->is_eligible() ) {
			return false;
		}

		foreach ( $result->get_action_results() as $action_row ) {
			if ( ! is_array( $action_row ) ) {
				continue;
			}

			$type = isset( $action_row['type'] ) ? (string) $action_row['type'] : '';
			$payload = isset( $action_row['payload'] ) && is_array( $action_row['payload'] )
				? $action_row['payload']
				: array();

			if ( $type === self::ACTION_PERCENTAGE_DISCOUNT ) {
				$applied = $this->apply_percentage_discount_fee( $promotion, $payload, $subtotal, $cart );
				if ( is_array( $applied ) ) {
					return $applied;
				}
				continue;
			}

			if ( $type === self::ACTION_FIXED_AMOUNT_DISCOUNT ) {
				$applied = $this->apply_fixed_amount_discount_fee( $promotion, $payload, $subtotal, $cart );
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
	private function apply_percentage_discount_fee( Promotion $promotion, array $payload, float $subtotal, $cart ) {
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

		$this->add_promotion_fee( $cart, $promotion, $discount );

		return array(
			'promotion'    => $promotion,
			'discount'     => $discount,
			'action_type'  => self::ACTION_PERCENTAGE_DISCOUNT,
			'percentage'   => $pct,
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param object               $cart
	 * @return array<string, mixed>|false
	 */
	private function apply_fixed_amount_discount_fee( Promotion $promotion, array $payload, float $subtotal, $cart ) {
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

		$this->add_promotion_fee( $cart, $promotion, $discount );

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
	 * @param object $cart WooCommerce cart (WC_Cart).
	 */
	private function add_promotion_fee( $cart, Promotion $promotion, float $discount ): void {
		$name = sanitize_text_field( $promotion->get_name() );
		if ( $name === '' ) {
			$name = __( 'Promotion', 'mp-commerce-promotions' );
		}

		$label = sprintf(
			/* translators: %s: sanitized promotion name */
			__( 'Commerce promotion: %s', 'mp-commerce-promotions' ),
			$name
		);

		$cart->add_fee( $label, -$discount, false );
	}
}
