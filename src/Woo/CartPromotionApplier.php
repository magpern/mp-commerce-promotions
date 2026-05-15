<?php
/**
 * v1: applies the first eligible active promotion as a negative cart fee (percentage only).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\PromotionEvaluator;

final class CartPromotionApplier {

	private PromotionRepository $promotions;

	private PromotionEvaluator $evaluator;

	private CartContextBuilder $context_builder;

	public function __construct(
		PromotionRepository $promotions,
		PromotionEvaluator $evaluator,
		CartContextBuilder $context_builder
	) {
		$this->promotions       = $promotions;
		$this->evaluator        = $evaluator;
		$this->context_builder = $context_builder;
	}

	public function apply(): void {
		/**
		 * Disable cart discount fees without a settings UI.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $enabled Whether to run cart promotion fee logic.
		 */
		if ( ! apply_filters( 'mp_cp_enable_cart_discounts', true ) ) {
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
			return;
		}

		$active = $this->promotions->find_active( 50 );
		foreach ( $active as $promotion ) {
			if ( ! $this->apply_first_percentage_fee_for_promotion( $promotion, $context, $subtotal, $cart ) ) {
				continue;
			}
			return;
		}
	}

	/**
	 * @param object $cart WooCommerce cart (WC_Cart).
	 */
	private function apply_first_percentage_fee_for_promotion(
		Promotion $promotion,
		EvaluationContext $context,
		float $subtotal,
		$cart
	): bool {
		$result = $this->evaluator->evaluate( $promotion, $context );
		if ( ! $result->is_eligible() ) {
			return false;
		}

		foreach ( $result->get_action_results() as $action_row ) {
			if ( ! is_array( $action_row ) ) {
				continue;
			}

			$type = isset( $action_row['type'] ) ? (string) $action_row['type'] : '';
			if ( $type !== 'percentage_discount' ) {
				continue;
			}

			$payload = isset( $action_row['payload'] ) && is_array( $action_row['payload'] )
				? $action_row['payload']
				: array();

			if ( ! isset( $payload['percentage'] ) || ! is_numeric( $payload['percentage'] ) ) {
				continue;
			}

			$pct = (float) $payload['percentage'];
			if ( $pct <= 0 || $pct > 100 ) {
				continue;
			}

			$discount = $subtotal * $pct / 100.0;
			if ( $discount <= 0 ) {
				continue;
			}

			if ( $discount > $subtotal ) {
				$discount = $subtotal;
			}

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
			return true;
		}

		return false;
	}
}
