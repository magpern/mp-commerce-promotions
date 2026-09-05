<?php
/**
 * Resolves pristine catalog effective prices (never cart line objects).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\BulkPricing;

use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use MP\CommercePromotions\GiftCard\GiftCardStorefrontAmounts;
use WC_Product;

final class CatalogBasePriceResolver {

	/** @var array<string, LinePriceSnapshot> */
	private static array $cycle_snapshots = array();

	private static int $cycle_id = 0;

	public static function begin_cycle(): int {
		++self::$cycle_id;
		self::$cycle_snapshots = array();

		return self::$cycle_id;
	}

	public static function get_cycle_id(): int {
		return self::$cycle_id;
	}

	public static function reset_cycle(): void {
		self::$cycle_snapshots = array();
	}

	public static function get_snapshot( string $cart_item_key ): ?LinePriceSnapshot {
		return self::$cycle_snapshots[ $cart_item_key ] ?? null;
	}

	/**
	 * @return array<string, LinePriceSnapshot>
	 */
	public static function get_all_snapshots(): array {
		return self::$cycle_snapshots;
	}

	public function resolve_for_product( int $product_id ): ?LinePriceSnapshot {
		if ( $product_id <= 0 ) {
			return null;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'simple' ) ) {
			return null;
		}

		if ( $this->is_excluded_product( $product ) ) {
			return null;
		}

		return $this->build_snapshot( $product );
	}

	/**
	 * @param object $cart WooCommerce cart.
	 */
	public function capture_for_cart( $cart ): void {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( ! is_string( $cart_item_key ) || ! is_array( $cart_item ) ) {
				continue;
			}

			$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
			if ( $product_id <= 0 ) {
				continue;
			}

			$snapshot = $this->resolve_for_product( $product_id );
			if ( $snapshot === null ) {
				continue;
			}

			self::$cycle_snapshots[ $cart_item_key ] = $snapshot;
		}
	}

	private function build_snapshot( WC_Product $product ): ?LinePriceSnapshot {
		$product_id = (int) $product->get_id();
		$decimals   = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;

		$display_currency = function_exists( 'get_woocommerce_currency' )
			? (string) get_woocommerce_currency()
			: GiftCardStorefrontAmounts::display_currency();
		$base_currency    = GiftCardStorefrontAmounts::base_currency();

		$display_effective = (float) $product->get_price();
		if ( $display_effective <= 0 ) {
			return null;
		}

		$regular = (float) $product->get_regular_price( 'edit' );
		$sale    = $product->get_sale_price( 'edit' );
		$sale_f  = is_numeric( $sale ) && (string) $sale !== '' ? (float) $sale : null;

		$price_source = 'regular';
		if ( $sale_f !== null && $sale_f > 0 && $sale_f < $regular ) {
			$price_source = 'sale';
		}

		$base_effective = GiftCardStorefrontAmounts::base_amount_from_display( $display_effective );
		if ( $base_effective <= 0 ) {
			$base_effective = $display_effective;
		}

		$display_minor = BulkPricingMoney::to_minor( $display_effective, $decimals );
		$base_minor    = BulkPricingMoney::to_minor( $base_effective, $decimals );

		$hash = md5(
			implode(
				'|',
				array(
					(string) $regular,
					(string) ( $sale_f ?? '' ),
					$price_source,
					$display_currency,
					(string) $decimals,
				)
			)
		);

		return new LinePriceSnapshot(
			$product_id,
			$display_minor,
			$base_minor,
			$display_currency,
			$base_currency,
			$price_source,
			$decimals,
			$hash
		);
	}

	private function is_excluded_product( WC_Product $product ): bool {
		$config = GiftCardProductMeta::read( (int) $product->get_id() );
		if ( ! empty( $config['sells'] ) ) {
			return true;
		}

		if ( $product->is_type( 'subscription' ) || $product->is_type( 'bundle' ) || $product->is_type( 'composite' ) ) {
			return true;
		}

		return false;
	}
}
