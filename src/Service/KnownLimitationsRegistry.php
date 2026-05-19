<?php
/**
 * Known limitation registry keyed by ecosystem detection codes.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

final class KnownLimitationsRegistry {

	/**
	 * @return array{summary: string, mitigation: string, doc_anchor: string}
	 */
	public static function lookup( string $code ): array {
		$code = sanitize_key( $code );
		$map  = self::definitions();

		if ( isset( $map[ $code ] ) ) {
			return $map[ $code ];
		}

		return array(
			'summary'    => __( 'No documented limitation entry for this detection code.', 'mp-commerce-promotions' ),
			'mitigation' => __( 'Review promotion configuration and test checkout in a staging environment.', 'mp-commerce-promotions' ),
			'doc_anchor' => 'KNOWN_LIMITATIONS.md',
		);
	}

	/**
	 * @return array<string, array{summary: string, mitigation: string, doc_anchor: string}>
	 */
	public static function definitions(): array {
		return array(
			'woocommerce_subscriptions'      => array(
				'summary'    => __( 'Renewal and subscription carts are not certified; automatic promotions may not apply on renewal flows.', 'mp-commerce-promotions' ),
				'mitigation' => __( 'Test subscription checkout and renewals before enabling high-value automatic promotions.', 'mp-commerce-promotions' ),
				'doc_anchor' => 'KNOWN_LIMITATIONS.md#woocommerce-subscriptions',
			),
			'woocommerce_product_bundles'    => array(
				'summary'    => __( 'Bundle parent/child line pricing may not match fee allocation breakdowns.', 'mp-commerce-promotions' ),
				'mitigation' => __( 'Prefer fee-based discounts and verify bundle carts manually.', 'mp-commerce-promotions' ),
				'doc_anchor' => 'KNOWN_LIMITATIONS.md#product-bundles',
			),
			'woocommerce_composite_products' => array(
				'summary'    => __( 'Composite configuration lines may not receive expected scoped discounts.', 'mp-commerce-promotions' ),
				'mitigation' => __( 'Avoid line-item mode on composite catalogs until QA passes.', 'mp-commerce-promotions' ),
				'doc_anchor' => 'KNOWN_LIMITATIONS.md#composite-products',
			),
			'multi_currency_plugin'          => array(
				'summary'    => __( 'Promotion amounts are stored in shop base currency; converted display may drift.', 'mp-commerce-promotions' ),
				'mitigation' => __( 'Validate each currency before campaigns; avoid uncapped percentage promos.', 'mp-commerce-promotions' ),
				'doc_anchor' => 'KNOWN_LIMITATIONS.md#multi-currency',
			),
			'tax_inclusive_prices'           => array(
				'summary'    => __( 'Tax-inclusive stores may show heuristic allocation totals in reports.', 'mp-commerce-promotions' ),
				'mitigation' => __( 'Compare checkout totals to Reports allocation summaries.', 'mp-commerce-promotions' ),
				'doc_anchor' => 'KNOWN_LIMITATIONS.md#tax-inclusive',
			),
			'germanized_detected'            => array(
				'summary'    => __( 'Germanized/EU VAT checkout fields are not integrated; fee discounts apply via standard cart fees.', 'mp-commerce-promotions' ),
				'mitigation' => __( 'Confirm B2B/VAT ID flows do not double-discount with native coupons.', 'mp-commerce-promotions' ),
				'doc_anchor' => 'KNOWN_LIMITATIONS.md#germanized',
			),
			'dynamic_pricing_plugin'         => array(
				'summary'    => __( 'Third-party dynamic pricing may mutate line prices before or after promotion fees.', 'mp-commerce-promotions' ),
				'mitigation' => __( 'Test cart order of operations; prefer exclusive promotions when stacking is unclear.', 'mp-commerce-promotions' ),
				'doc_anchor' => 'KNOWN_LIMITATIONS.md#dynamic-pricing',
			),
			'membership_plugin'              => array(
				'summary'    => __( 'Membership-gated pricing is not coordinated with promotion eligibility rules.', 'mp-commerce-promotions' ),
				'mitigation' => __( 'Add explicit customer/role conditions on sensitive promotions.', 'mp-commerce-promotions' ),
				'doc_anchor' => 'KNOWN_LIMITATIONS.md#memberships',
			),
			'external_object_cache'          => array(
				'summary'    => __( 'Planner transients and locks depend on object cache flush/TTL behavior.', 'mp-commerce-promotions' ),
				'mitigation' => __( 'Run Diagnostics lock cleanup after deploys; monitor concurrency warnings.', 'mp-commerce-promotions' ),
				'doc_anchor' => 'KNOWN_LIMITATIONS.md#object-cache',
			),
			'hpos_sync_pending'              => array(
				'summary'    => __( 'HPOS enabled but legacy post tables may still be authoritative for some plugins.', 'mp-commerce-promotions' ),
				'mitigation' => __( 'Complete WooCommerce HPOS compatibility review for all order plugins.', 'mp-commerce-promotions' ),
				'doc_anchor' => 'KNOWN_LIMITATIONS.md#hpos',
			),
			'advanced_coupon_plugin'         => array(
				'summary'    => __( 'Native or third-party coupons may stack unpredictably with promotion fees.', 'mp-commerce-promotions' ),
				'mitigation' => __( 'Set coupon_behavior on promotions; test coexistence on cart and checkout.', 'mp-commerce-promotions' ),
				'doc_anchor' => 'KNOWN_LIMITATIONS.md#coupon-plugins',
			),
			'checkout_blocks_active'         => array(
				'summary'    => __( 'Blocks checkout uses Store API; recording hooks differ from classic checkout.', 'mp-commerce-promotions' ),
				'mitigation' => __( 'Use declared block compatibility testing guide after plugin updates.', 'mp-commerce-promotions' ),
				'doc_anchor' => 'COMPATIBILITY_MATRIX.md#cart-checkout-blocks',
			),
		);
	}
}
