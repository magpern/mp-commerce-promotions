<?php
/**
 * Chooses bulk vs promotion vs standard and commits one set_price per line.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\BulkPricing\BulkPricingCalculator;
use MP\CommercePromotions\BulkPricing\BulkPricingConfig;
use MP\CommercePromotions\BulkPricing\BulkPricingMoney;
use MP\CommercePromotions\BulkPricing\BulkPricingProductMeta;
use MP\CommercePromotions\BulkPricing\CatalogBasePriceResolver;
use MP\CommercePromotions\BulkPricing\LinePriceSnapshot;
use MP\CommercePromotions\BulkPricing\LinePricingSource;
use MP\CommercePromotions\BulkPricing\PromotionLineQuote;
use MP\CommercePromotions\GiftCard\GiftCardStorefrontAmounts;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Woo\LineDiscountPlanCache;
use MP\CommercePromotions\Woo\LinePriceMutationGuard;
use WC_Product;

final class LinePricingArbiter {

	private Settings $settings;

	private BulkPricingProductMeta $product_meta;

	private BulkPricingCalculator $calculator;

	private PromotionLineQuoteService $promotion_quotes;

	public function __construct(
		?Settings $settings = null,
		?BulkPricingProductMeta $product_meta = null,
		?BulkPricingCalculator $calculator = null,
		?PromotionLineQuoteService $promotion_quotes = null
	) {
		$this->settings          = $settings ?? new Settings();
		$this->product_meta      = $product_meta ?? new BulkPricingProductMeta();
		$this->calculator        = $calculator ?? new BulkPricingCalculator();
		$this->promotion_quotes  = $promotion_quotes ?? new PromotionLineQuoteService( null, $this->settings );
	}

	/**
	 * @param object $cart WooCommerce cart.
	 */
	public function arbitrate_and_commit( $cart ): void {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( $this->settings->safe_mode_enabled() ) {
			return;
		}

		$snapshots = CatalogBasePriceResolver::get_all_snapshots();
		if ( $snapshots === array() ) {
			return;
		}

		$promotion_quotes = $this->load_promotion_quotes( $cart, $snapshots );

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( ! is_string( $cart_item_key ) || ! is_array( $cart_item ) ) {
				continue;
			}

			$snapshot = $snapshots[ $cart_item_key ] ?? null;
			if ( ! $snapshot instanceof LinePriceSnapshot ) {
				continue;
			}

			if ( ! LinePriceMutationGuard::is_supported_product_type( $cart_item ) ) {
				continue;
			}

			$qty = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;
			$this->commit_line(
				$cart,
				$cart_item_key,
				$cart_item,
				$snapshot,
				$qty,
				$promotion_quotes[ $cart_item_key ] ?? null
			);
		}
	}

	/**
	 * @param object $cart
	 * @param array<string, LinePriceSnapshot> $snapshots
	 * @return array<string, PromotionLineQuote>
	 */
	private function load_promotion_quotes( $cart, array $snapshots ): array {
		$plan    = LineDiscountPlanCache::get_plan();
		$context = LineDiscountPlanCache::get_context();
		if ( $plan === null || $context === null ) {
			return array();
		}

		return $this->promotion_quotes->quote_for_plan( $cart, $plan, $context, $snapshots );
	}

	/**
	 * @param object $cart
	 * @param array<string, mixed> $cart_item
	 */
	private function commit_line(
		$cart,
		string $cart_item_key,
		array $cart_item,
		LinePriceSnapshot $snapshot,
		int $quantity,
		?PromotionLineQuote $promotion_quote
	): void {
		$standard_minor = BulkPricingMoney::line_total_minor( $snapshot->get_display_unit_minor(), $quantity );

		$bulk_quote = null;
		$config     = null;
		if ( $this->settings->bulk_pricing_enabled() ) {
			$config = $this->product_meta->read( $snapshot->get_product_id() );
			if ( $config->has_valid_tiers() ) {
				$bulk_quote = $this->calculator->quote_line( $snapshot, $config, $quantity );
			}
		}

		$bulk_minor     = $bulk_quote?->get_line_total_minor();
		$promotion_minor = $promotion_quote?->get_line_total_minor();

		$winner       = LinePricingSource::STANDARD;
		$winner_minor = $standard_minor;
		$tier_min     = null;
		$tier_pct     = null;

		if ( $bulk_minor !== null && $bulk_minor < $winner_minor ) {
			$winner       = LinePricingSource::BULK_TIER;
			$winner_minor = $bulk_minor;
			$tier_min     = $bulk_quote?->get_tier_min_quantity();
			$tier_pct     = $bulk_quote?->get_discount_percentage();
		}

		if ( $promotion_minor !== null && $promotion_minor < $winner_minor ) {
			$winner       = LinePricingSource::PROMOTION;
			$winner_minor = $promotion_minor;
			$tier_min     = null;
			$tier_pct     = null;
		} elseif ( $promotion_minor !== null && $promotion_minor === $winner_minor && $winner === LinePricingSource::BULK_TIER ) {
			// Tie → bulk_tier (already winner).
		}

		$unit_minor = (int) round( $winner_minor / max( 1, $quantity ) );
		$this->apply_unit_price( $cart, $cart_item_key, $cart_item, $snapshot, $unit_minor );

		if ( isset( $cart->cart_contents ) && is_array( $cart->cart_contents ) ) {
			$cart->cart_contents[ $cart_item_key ][ LinePricingSource::CART_META_SOURCE ]        = $winner;
			$cart->cart_contents[ $cart_item_key ][ LinePricingSource::CART_META_BASE_SNAPSHOT ] = wp_json_encode( $snapshot->to_array() );
			if ( $tier_min !== null ) {
				$cart->cart_contents[ $cart_item_key ][ LinePricingSource::CART_META_TIER_MIN ] = $tier_min;
			} else {
				unset( $cart->cart_contents[ $cart_item_key ][ LinePricingSource::CART_META_TIER_MIN ] );
			}
			if ( $tier_pct !== null ) {
				$cart->cart_contents[ $cart_item_key ][ LinePricingSource::CART_META_TIER_PCT ] = $tier_pct;
			} else {
				unset( $cart->cart_contents[ $cart_item_key ][ LinePricingSource::CART_META_TIER_PCT ] );
			}
		}

		LinePriceMutationGuard::mark_mutated( $cart_item_key );
	}

	/**
	 * @param object $cart
	 * @param array<string, mixed> $cart_item
	 */
	private function apply_unit_price(
		$cart,
		string $cart_item_key,
		array $cart_item,
		LinePriceSnapshot $snapshot,
		int $unit_minor
	): void {
		if ( ! isset( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) ) {
			return;
		}

		$product = $cart_item['data'];
		if ( ! $product instanceof WC_Product || ! method_exists( $product, 'set_price' ) ) {
			return;
		}

		$display_unit = BulkPricingMoney::from_minor( $unit_minor, $snapshot->get_decimals() );
		$base_unit    = GiftCardStorefrontAmounts::base_amount_from_display( $display_unit );

		if ( $snapshot->get_display_currency() === $snapshot->get_base_currency() ) {
			$base_unit = $display_unit;
		}

		$product->set_price( (string) $base_unit );

		if ( isset( $cart->cart_contents ) && is_array( $cart->cart_contents ) ) {
			$cart->cart_contents[ $cart_item_key ]['data'] = $product;
		}
	}
}
