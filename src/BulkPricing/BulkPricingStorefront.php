<?php
/**
 * Storefront contract for bulk pricing PDP selector.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\BulkPricing;

use MP\CommercePromotions\GiftCard\GiftCardStorefrontAmounts;
use MP\CommercePromotions\Service\Settings;
use WC_Product;

final class BulkPricingStorefront {

	public const FILTER = 'mp_cp_bulk_pricing_storefront_v1';

	public const CONTRACT_VERSION = '1';

	private Settings $settings;

	private BulkPricingProductMeta $meta;

	private CatalogBasePriceResolver $resolver;

	private BulkPricingCalculator $calculator;

	private BulkPricingCacheInvalidator $cache;

	public function __construct(
		?Settings $settings = null,
		?BulkPricingProductMeta $meta = null,
		?CatalogBasePriceResolver $resolver = null,
		?BulkPricingCalculator $calculator = null,
		?BulkPricingCacheInvalidator $cache = null
	) {
		$this->settings   = $settings ?? new Settings();
		$this->meta       = $meta ?? new BulkPricingProductMeta();
		$this->resolver   = $resolver ?? new CatalogBasePriceResolver();
		$this->calculator = $calculator ?? new BulkPricingCalculator();
		$this->cache      = $cache ?? new BulkPricingCacheInvalidator();
	}

	public static function for_product( WC_Product $product ): ?array {
		$instance = new self();

		return $instance->build_contract( $product );
	}

	/**
	 * @param mixed $contract
	 * @return array<string, mixed>|null
	 */
	public static function filter_contract( $contract, $product ): ?array {
		if ( $contract !== null ) {
			return is_array( $contract ) ? $contract : null;
		}

		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		return self::for_product( $product );
	}

	public function build_contract( WC_Product $product ): ?array {
		if ( ! $this->settings->bulk_pricing_enabled() || $this->settings->safe_mode_enabled() ) {
			return null;
		}

		if ( ! $product->is_type( 'simple' ) ) {
			return null;
		}

		$config = $this->meta->read( (int) $product->get_id() );
		if ( ! $config->has_valid_tiers() ) {
			return null;
		}

		$snapshot = $this->resolver->resolve_for_product( (int) $product->get_id() );
		if ( $snapshot === null ) {
			return null;
		}

		$cached = $this->cache->get_cached_contract( (int) $product->get_id(), $snapshot, $config );
		if ( $cached !== null ) {
			return $cached;
		}

		$decimals = $snapshot->get_decimals();
		$currency = $snapshot->get_display_currency();

		$bracket_table = array();
		$anchors       = array();

		foreach ( $config->get_tiers() as $tier ) {
			$unit_minor = BulkPricingMoney::apply_percentage_minor(
				$snapshot->get_display_unit_minor(),
				(int) $tier['discount_percentage']
			);

			$bracket_table[] = array(
				'min_quantity'        => (int) $tier['min_quantity'],
				'discount_percentage' => (int) $tier['discount_percentage'],
				'unit_minor'          => $unit_minor,
				'unit_html'           => wc_price( BulkPricingMoney::from_minor( $unit_minor, $decimals ) ),
			);

			$anchor_qty   = (int) $tier['anchor_quantity'];
			$line_minor   = BulkPricingMoney::line_total_minor( $unit_minor, $anchor_qty );
			$base_line    = BulkPricingMoney::line_total_minor( $snapshot->get_display_unit_minor(), $anchor_qty );
			$savings      = max( 0, $base_line - $line_minor );
			$tier_id      = md5( (string) $tier['min_quantity'] . ':' . (string) $tier['discount_percentage'] );

			$anchors[] = array(
				'tier_id'               => $tier_id,
				'min_quantity'          => (int) $tier['min_quantity'],
				'anchor_quantity'       => $anchor_qty,
				'discount_percentage'   => (int) $tier['discount_percentage'],
				'badge'                 => $tier['badge'],
				'unit_minor'            => $unit_minor,
				'unit_html'             => wc_price( BulkPricingMoney::from_minor( $unit_minor, $decimals ) ),
				'line_total_minor'      => $line_minor,
				'line_total_html'       => wc_price( BulkPricingMoney::from_minor( $line_minor, $decimals ) ),
				'savings_minor'         => $savings > 0 ? $savings : null,
				'savings_html'          => $savings > 0 ? wc_price( BulkPricingMoney::from_minor( $savings, $decimals ) ) : null,
				'is_baseline'           => (int) $tier['discount_percentage'] === 0,
			);
		}

		$contract = array(
			'contract_version'    => self::CONTRACT_VERSION,
			'product_id'          => (int) $product->get_id(),
			'currency'            => $currency,
			'decimals'            => $decimals,
			'base_unit_minor'     => $snapshot->get_display_unit_minor(),
			'base_unit_html'      => wc_price( BulkPricingMoney::from_minor( $snapshot->get_display_unit_minor(), $decimals ) ),
			'bracket_table'       => $bracket_table,
			'anchors'             => $anchors,
			'pricing_disclaimer'  => __( 'Volume price shown. Cart promotions may reduce the final price further.', 'mp-commerce-promotions' ),
			'form'                => array(
				'baseline_quantity' => 1,
			),
			'a11y'                => array(
				'group_label'     => __( 'Quantity pricing', 'mp-commerce-promotions' ),
				'option_pattern'  => __( '%1$s units, %2$s per unit, %3$s total', 'mp-commerce-promotions' ),
			),
			'cache_version'       => $this->cache->contract_cache_version( $snapshot, $config ),
		);

		$this->cache->set_cached_contract( (int) $product->get_id(), $snapshot, $config, $contract );

		return $contract;
	}
}
