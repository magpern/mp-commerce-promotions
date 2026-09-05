<?php
/**
 * Cart/checkout hooks for bulk pricing and line arbitration.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\BulkPricing\BulkPricingStorefront;
use MP\CommercePromotions\BulkPricing\CatalogBasePriceResolver;
use MP\CommercePromotions\BulkPricing\LinePricingSource;
use MP\CommercePromotions\BulkPricing\BulkPricingMoney;
use MP\CommercePromotions\Service\LinePricingArbiter;
use MP\CommercePromotions\Service\Settings;

final class BulkPricingCartHooks {

	private Settings $settings;

	private CatalogBasePriceResolver $resolver;

	private LinePricingArbiter $arbiter;

	public function __construct(
		?Settings $settings = null,
		?CatalogBasePriceResolver $resolver = null,
		?LinePricingArbiter $arbiter = null
	) {
		$this->settings  = $settings ?? new Settings();
		$this->resolver  = $resolver ?? new CatalogBasePriceResolver();
		$this->arbiter   = $arbiter ?? new LinePricingArbiter( $this->settings );
	}

	public function register(): void {
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'capture_snapshots' ), 5, 1 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'arbitrate_and_commit' ), 18, 1 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'persist_order_line_item' ), 12, 4 );
		add_filter( 'mp_cp_bulk_pricing_storefront_v1', array( BulkPricingStorefront::class, 'filter_contract' ), 10, 2 );
	}

	/**
	 * @param \WC_Cart $cart
	 */
	public function capture_snapshots( $cart ): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		CatalogBasePriceResolver::begin_cycle();
		$this->resolver->capture_for_cart( $cart );
	}

	/**
	 * @param \WC_Cart $cart
	 */
	public function arbitrate_and_commit( $cart ): void {
		$this->arbiter->arbitrate_and_commit( $cart );
	}

	/**
	 * @param \WC_Order_Item_Product $item
	 * @param string $cart_item_key
	 * @param array<string, mixed> $values
	 * @param \WC_Order $order
	 */
	public function persist_order_line_item( $item, $cart_item_key, $values, $order ): void {
		unset( $cart_item_key, $order );
		if ( ! is_array( $values ) ) {
			return;
		}

		$source = isset( $values[ LinePricingSource::CART_META_SOURCE ] )
			? (string) $values[ LinePricingSource::CART_META_SOURCE ]
			: '';
		if ( $source === '' ) {
			return;
		}

		$item->add_meta_data( LinePricingSource::ORDER_META_SOURCE, $source, true );

		if ( isset( $values[ LinePricingSource::CART_META_TIER_MIN ] ) ) {
			$item->add_meta_data( LinePricingSource::ORDER_META_TIER_MIN, (int) $values[ LinePricingSource::CART_META_TIER_MIN ], true );
		}
		if ( isset( $values[ LinePricingSource::CART_META_TIER_PCT ] ) ) {
			$item->add_meta_data( LinePricingSource::ORDER_META_TIER_PCT, (int) $values[ LinePricingSource::CART_META_TIER_PCT ], true );
		}
		if ( isset( $values[ LinePricingSource::CART_META_BASE_SNAPSHOT ] ) ) {
			$item->add_meta_data( LinePricingSource::ORDER_META_BASE_SNAPSHOT, (string) $values[ LinePricingSource::CART_META_BASE_SNAPSHOT ], true );
		}

		$product = $item->get_product();
		if ( $product && method_exists( $product, 'get_price' ) ) {
			$item->add_meta_data( LinePricingSource::ORDER_META_FINAL_UNIT, (string) $product->get_price(), true );
		}
	}
}
