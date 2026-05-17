<?php
/**
 * Tax-aware discount estimates for admin/reporting (no checkout mutation).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Engine\EvaluationContext;

final class TaxAwareDiscountCalculator {

	/**
	 * @return array{
	 *     before_tax_discount: float,
	 *     after_tax_discount: float,
	 *     estimated_tax_impact: float,
	 *     shipping_tax_impact: float,
	 *     prices_include_tax: bool
	 * }
	 */
	public function estimate_for_allocation(
		EvaluationContext $context,
		float $discount_total,
		float $shipping_total
	): array {
		$include_tax = $this->prices_include_tax();
		$rate        = $this->estimated_tax_rate( $context );

		$before_tax = $discount_total;
		$after_tax  = $discount_total;

		if ( $include_tax && $rate > 0 ) {
			$before_tax = $discount_total / ( 1 + $rate );
		} elseif ( ! $include_tax && $rate > 0 ) {
			$after_tax = $discount_total * ( 1 + $rate );
		}

		$shipping_tax_impact = 0.0;
		if ( $shipping_total > 0 && $rate > 0 ) {
			$shipping_tax_impact = $include_tax
				? $shipping_total - ( $shipping_total / ( 1 + $rate ) )
				: $shipping_total * $rate;
		}

		return array(
			'before_tax_discount'   => round( max( 0.0, $before_tax ), 4 ),
			'after_tax_discount'    => round( max( 0.0, $after_tax ), 4 ),
			'estimated_tax_impact'  => round( max( 0.0, $after_tax - $before_tax ), 4 ),
			'shipping_tax_impact'   => round( max( 0.0, $shipping_tax_impact ), 4 ),
			'prices_include_tax'    => $include_tax,
		);
	}

	private function prices_include_tax(): bool {
		if ( function_exists( 'wc_prices_include_tax' ) ) {
			return (bool) wc_prices_include_tax();
		}

		return get_option( 'woocommerce_prices_include_tax', 'no' ) === 'yes';
	}

	private function estimated_tax_rate( EvaluationContext $context ): float {
		$meta = $context->to_array()['metadata'] ?? array();
		if ( is_array( $meta ) && isset( $meta['estimated_tax_rate'] ) && is_numeric( $meta['estimated_tax_rate'] ) ) {
			return max( 0.0, (float) $meta['estimated_tax_rate'] );
		}

		if ( class_exists( '\WC_Tax' ) && is_callable( array( '\WC_Tax', 'get_rates' ) ) ) {
			$rates = \WC_Tax::get_rates();
			if ( is_array( $rates ) && $rates !== array() ) {
				$first = reset( $rates );
				if ( is_array( $first ) && isset( $first['rate'] ) ) {
					return max( 0.0, (float) $first['rate'] ) / 100;
				}
			}
		}

		return 0.0;
	}
}
