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
use MP\CommercePromotions\Service\Settings;

final class CartPromotionApplier {

	public const SESSION_KEY = 'mp_cp_applied_promotion';

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
			$applied = $this->apply_first_percentage_fee_for_promotion( $promotion, $context, $subtotal, $cart );
			if ( is_array( $applied ) ) {
				$this->store_applied_promotion_session(
					$applied['promotion'],
					$applied['discount'],
					$applied['percentage']
				);
				return;
			}
		}

		$this->clear_applied_promotion_session();
	}

	private function store_applied_promotion_session( Promotion $promotion, float $discount, float $percentage ): void {
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

		$wc->session->set(
			self::SESSION_KEY,
			array(
				'promotion_id'     => $pid,
				'promotion_uuid'   => $promotion->get_uuid(),
				'promotion_name'   => $promotion->get_name(),
				'discount_amount'  => $discount,
				'action_type'      => 'percentage_discount',
				'percentage'       => $percentage,
			)
		);
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
	private function apply_first_percentage_fee_for_promotion(
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
			return array(
				'promotion'  => $promotion,
				'discount'   => $discount,
				'percentage' => $pct,
			);
		}

		return false;
	}
}
