<?php
/**
 * Maps WooCommerce cart state into a generic EvaluationContext (read-only).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Engine\EvaluationContext;

final class CartContextBuilder {

	public function build_from_cart(): EvaluationContext {
		if ( ! function_exists( 'WC' ) ) {
			return $this->empty_context();
		}

		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->cart ) || ! is_object( $wc->cart ) ) {
			return $this->empty_context();
		}

		$cart = $wc->cart;
		if ( ! method_exists( $cart, 'get_subtotal' ) || ! method_exists( $cart, 'get_cart' ) ) {
			return $this->empty_context();
		}

		$customer_id = null;
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && function_exists( 'get_current_user_id' ) ) {
			$uid = (int) get_current_user_id();
			$customer_id = $uid > 0 ? $uid : null;
		}

		$subtotal = (float) $cart->get_subtotal();
		if ( $subtotal < 0 ) {
			$subtotal = 0.0;
		}

		$currency = null;
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$cur = get_woocommerce_currency();
			if ( is_string( $cur ) && $cur !== '' ) {
				$currency = $cur;
			}
		}

		$items = array();
		$raw_cart = $cart->get_cart();
		if ( is_array( $raw_cart ) ) {
			foreach ( $raw_cart as $cart_item ) {
				if ( ! is_array( $cart_item ) ) {
					continue;
				}

				$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
				$var_raw    = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
				$variation  = $var_raw > 0 ? $var_raw : null;

				$quantity = isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 0.0;

				$line_subtotal = 0.0;
				if ( isset( $cart_item['line_subtotal'] ) && is_numeric( $cart_item['line_subtotal'] ) ) {
					$line_subtotal = (float) $cart_item['line_subtotal'];
				}

				$categories = $this->category_term_ids_for_product( $product_id );

				$items[] = array(
					'product_id'    => $product_id,
					'variation_id'  => $variation,
					'quantity'      => $quantity,
					'line_subtotal' => $line_subtotal,
					'categories'    => $categories,
				);
			}
		}

		$metadata = array(
			'source' => 'woocommerce_cart',
		);

		return new EvaluationContext( $customer_id, $subtotal, $currency, $items, $metadata );
	}

	private function empty_context(): EvaluationContext {
		return new EvaluationContext( null, null, null, array(), array() );
	}

	/**
	 * @return list<int>
	 */
	private function category_term_ids_for_product( int $product_id ): array {
		if ( $product_id <= 0 || ! function_exists( 'get_the_terms' ) ) {
			return array();
		}

		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $terms ) ) {
			return array();
		}
		if ( false === $terms || ! is_array( $terms ) ) {
			return array();
		}

		$ids = array();
		foreach ( $terms as $term ) {
			if ( is_object( $term ) && isset( $term->term_id ) ) {
				$ids[] = (int) $term->term_id;
			}
		}

		return array_values( array_unique( $ids, SORT_NUMERIC ) );
	}
}
