<?php
/**
 * Maps WooCommerce cart state into a generic EvaluationContext (read-only).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Engine\CartQuantityHelper;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Service\CustomerOrderStats;

final class CartContextBuilder {

	private ?RedemptionRepository $redemptions;

	public function __construct( ?RedemptionRepository $redemptions = null ) {
		$this->redemptions = $redemptions;
	}

	/**
	 * Order statuses that count as a previous purchase for first_order.
	 *
	 * @var list<string>
	 */
	private const PREVIOUS_ORDER_STATUSES = array(
		'completed',
		'processing',
		'on-hold',
	);

	/**
	 * Build evaluation context from the current WooCommerce cart (read-only).
	 */
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
			$uid         = (int) get_current_user_id();
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

		$items    = array();
		$raw_cart = $cart->get_cart();
		if ( is_array( $raw_cart ) ) {
			foreach ( $raw_cart as $cart_item_key => $cart_item ) {
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

				$unit_price = 0.0;
				if ( $quantity > 0 && $line_subtotal >= 0 ) {
					$unit_price = $line_subtotal / $quantity;
				}

				$categories = $this->category_term_ids_for_product( $product_id );

				$row = array(
					'product_id'    => $product_id,
					'variation_id'  => $variation,
					'quantity'      => $quantity,
					'line_subtotal' => $line_subtotal,
					'unit_price'    => $unit_price,
					'categories'    => $categories,
					'on_sale'       => $this->cart_item_is_on_sale( $cart_item, $product_id, $variation ),
				);

				if ( is_string( $cart_item_key ) && $cart_item_key !== '' ) {
					$row['item_key'] = $cart_item_key;
				}

				$product_name = $this->product_name_for_cart_item( $product_id, $cart_item );
				if ( $product_name !== null && $product_name !== '' ) {
					$row['product_name'] = $product_name;
				}

				$items[] = $row;
			}
		}

		$metadata = array(
			'source'              => 'woocommerce_cart',
			'cart_total_quantity' => CartQuantityHelper::total_quantity_from_items( $items ),
		);

		if ( $customer_id !== null && $customer_id > 0 ) {
			$this->enrich_customer_metadata( $customer_id, $metadata );
		}

		$this->enrich_billing_metadata( $customer_id, $metadata );
		$this->enrich_shipping_and_coupon_metadata( $cart, $metadata );

		return new EvaluationContext( $customer_id, $subtotal, $currency, $items, $metadata );
	}

	/**
	 * Add per-promotion redemption count metadata for logged-in customers.
	 */
	public function enrich_context_for_promotion( EvaluationContext $context, Promotion $promotion ): EvaluationContext {
		$metadata     = $context->get_metadata();
		$customer_id  = $context->get_customer_id();
		$promotion_id = $promotion->get_id();

		if (
			$this->redemptions !== null
			&& $customer_id !== null
			&& $customer_id > 0
			&& $promotion_id !== null
			&& $promotion_id > 0
		) {
			$metadata['customer_promotion_redemption_count'] = $this->redemptions->count_recorded_for_customer_and_promotion(
				$customer_id,
				$promotion_id
			);
		}

		if ( ! isset( $metadata['cart_total_quantity'] ) ) {
			$metadata['cart_total_quantity'] = CartQuantityHelper::total_quantity_from_items( $context->get_items() );
		}

		return new EvaluationContext(
			$context->get_customer_id(),
			$context->get_cart_subtotal(),
			$context->get_currency(),
			$context->get_items(),
			$metadata
		);
	}

	/**
	 * @param array<string, mixed> $metadata
	 */
	private function enrich_customer_metadata( int $customer_id, array &$metadata ): void {
		$has_previous = $this->customer_has_previous_orders( $customer_id );
		if ( $has_previous !== null ) {
			$metadata['has_previous_orders'] = $has_previous;
		}

		$roles = $this->customer_role_slugs( $customer_id );
		if ( $roles !== null ) {
			$metadata['customer_roles'] = $roles;
		}

		if ( $this->redemptions !== null ) {
			$metadata['customer_redemption_count'] = $this->redemptions->count_recorded_for_customer( $customer_id );
		}

		$stats = CustomerOrderStats::for_customer( $customer_id );
		$metadata['lifetime_spend']       = $stats['lifetime_spend'];
		$metadata['order_count']          = $stats['order_count'];
		$metadata['average_order_value']  = $stats['average_order_value'];
	}

	private function customer_has_previous_orders( int $customer_id ): ?bool {
		if ( $customer_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		try {
			$orders = wc_get_orders(
				array(
					'customer_id' => $customer_id,
					'limit'       => 1,
					'status'      => self::PREVIOUS_ORDER_STATUSES,
					'return'      => 'ids',
				)
			);
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( ! is_array( $orders ) ) {
			return null;
		}

		return count( $orders ) > 0;
	}

	/**
	 * @return list<string>|null Role slugs from WordPress user object, or null if unavailable.
	 */
	/**
	 * @param array<string, mixed> $metadata
	 */
	/**
	 * @param object|null          $cart WooCommerce cart.
	 * @param array<string, mixed> $metadata
	 */
	private function enrich_shipping_and_coupon_metadata( $cart, array &$metadata ): void {
		if ( $cart !== null && is_object( $cart ) ) {
			if ( method_exists( $cart, 'get_shipping_total' ) ) {
				$metadata['shipping_total'] = max( 0.0, (float) $cart->get_shipping_total() );
			}
			if ( method_exists( $cart, 'get_applied_coupons' ) ) {
				$coupons = $cart->get_applied_coupons();
				if ( is_array( $coupons ) ) {
					$metadata['native_coupon_codes'] = array_values( array_map( 'strval', $coupons ) );
				}
			}
		}

		if ( function_exists( 'WC' ) && WC()->cart && $cart === null ) {
			$this->enrich_shipping_and_coupon_metadata( WC()->cart, $metadata );
		}
	}

	private function enrich_billing_metadata( ?int $customer_id, array &$metadata ): void {
		$country = $this->resolve_billing_country( $customer_id );
		if ( $country !== null && $country !== '' ) {
			$metadata['billing_country'] = strtoupper( $country );
		}

		$email = $this->resolve_customer_email( $customer_id );
		if ( $email !== null && $email !== '' ) {
			$metadata['customer_email'] = $email;
		}
	}

	private function resolve_billing_country( ?int $customer_id ): ?string {
		$from_session = $this->wc_customer_billing_country();
		if ( $from_session !== null && $from_session !== '' ) {
			return $from_session;
		}

		if ( $customer_id !== null && $customer_id > 0 && function_exists( 'get_user_meta' ) ) {
			$meta = get_user_meta( $customer_id, 'billing_country', true );
			if ( is_string( $meta ) && $meta !== '' ) {
				return $meta;
			}
		}

		return null;
	}

	private function resolve_customer_email( ?int $customer_id ): ?string {
		if ( $customer_id !== null && $customer_id > 0 && function_exists( 'get_userdata' ) ) {
			$user = get_userdata( $customer_id );
			if ( is_object( $user ) && isset( $user->user_email ) && is_string( $user->user_email ) && $user->user_email !== '' ) {
				return $user->user_email;
			}
		}

		return $this->wc_customer_billing_email();
	}

	private function wc_customer_billing_country(): ?string {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->customer ) || ! is_object( $wc->customer ) ) {
			return null;
		}

		if ( ! method_exists( $wc->customer, 'get_billing_country' ) ) {
			return null;
		}

		$country = $wc->customer->get_billing_country();
		if ( ! is_string( $country ) || $country === '' ) {
			return null;
		}

		return $country;
	}

	private function wc_customer_billing_email(): ?string {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->customer ) || ! is_object( $wc->customer ) ) {
			return null;
		}

		if ( ! method_exists( $wc->customer, 'get_billing_email' ) ) {
			return null;
		}

		$email = $wc->customer->get_billing_email();
		if ( ! is_string( $email ) || $email === '' ) {
			return null;
		}

		return $email;
	}

	/**
	 * @return list<string>|null Role slugs from WordPress user object, or null if unavailable.
	 */
	private function customer_role_slugs( int $customer_id ): ?array {
		if ( $customer_id <= 0 || ! function_exists( 'get_userdata' ) ) {
			return null;
		}

		$user = get_userdata( $customer_id );
		if ( ! is_object( $user ) || ! isset( $user->roles ) || ! is_array( $user->roles ) ) {
			return null;
		}

		$roles = array();
		foreach ( $user->roles as $role ) {
			if ( is_string( $role ) && $role !== '' ) {
				$roles[] = $role;
			}
		}

		return array_values( array_unique( $roles ) );
	}

	private function empty_context(): EvaluationContext {
		return new EvaluationContext( null, null, null, array(), array() );
	}

	/**
	 * @return list<int>
	 */
	/**
	 * @param array<string, mixed> $cart_item
	 */
	private function product_name_for_cart_item( int $product_id, array $cart_item ): ?string {
		if ( isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) && method_exists( $cart_item['data'], 'get_name' ) ) {
			$name = $cart_item['data']->get_name();
			if ( is_string( $name ) && $name !== '' ) {
				return $name;
			}
		}

		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $product_id );
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_name' ) ) {
			return null;
		}

		$name = $product->get_name();

		return is_string( $name ) && $name !== '' ? $name : null;
	}

	/**
	 * @return list<int>
	 */
	/**
	 * @param array<string, mixed> $cart_item
	 */
	private function cart_item_is_on_sale( array $cart_item, int $product_id, ?int $variation_id ): bool {
		if ( isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) && method_exists( $cart_item['data'], 'is_on_sale' ) ) {
			return (bool) $cart_item['data']->is_on_sale();
		}

		if ( ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$lookup_id = ( $variation_id !== null && $variation_id > 0 ) ? $variation_id : $product_id;
		if ( $lookup_id <= 0 ) {
			return false;
		}

		$product = wc_get_product( $lookup_id );
		if ( ! is_object( $product ) || ! method_exists( $product, 'is_on_sale' ) ) {
			return false;
		}

		return (bool) $product->is_on_sale();
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
