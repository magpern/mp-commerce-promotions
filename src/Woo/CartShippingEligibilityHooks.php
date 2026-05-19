<?php
/**
 * Storefront hooks: free-shipping progress subtotal and visibility.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

final class CartShippingEligibilityHooks {

	public function register(): void {
		add_filter( 'biopentra_header_auth_cart_free_shipping_subtotal', array( $this, 'filter_progress_subtotal' ), 10, 1 );
		add_filter( 'biopentra_header_auth_cart_show_free_shipping_progress', array( $this, 'filter_show_progress' ), 10, 1 );
		add_filter( 'mp_cp_qualifying_shipping_subtotal', array( $this, 'filter_public_qualifying_subtotal' ), 10, 1 );
	}

	/**
	 * @param float $subtotal
	 */
	public function filter_progress_subtotal( $subtotal ): float {
		unset( $subtotal );
		$stats = CartShippingEligibilitySubtotal::stats_from_cart();

		return (float) $stats[ CartShippingEligibilitySubtotal::TRACE_QUALIFYING_KEY ];
	}

	/**
	 * @param bool $show
	 */
	public function filter_show_progress( $show ): bool {
		if ( ! $show ) {
			return false;
		}

		$stats = CartShippingEligibilitySubtotal::stats_from_cart();

		return (bool) $stats['has_qualifying_shipping_items'];
	}

	/**
	 * @param float $subtotal
	 */
	public function filter_public_qualifying_subtotal( $subtotal ): float {
		unset( $subtotal );
		$stats = CartShippingEligibilitySubtotal::stats_from_cart();

		return (float) $stats[ CartShippingEligibilitySubtotal::TRACE_QUALIFYING_KEY ];
	}
}
