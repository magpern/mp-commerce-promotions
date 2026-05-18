<?php
/**
 * Renders compatibility status table for Reports/Diagnostics.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\Service\CompatibilityStatus;

final class CompatibilityStatusPanel {

	public static function render( ?CompatibilityStatus $status = null ): void {
		$status = $status ?? new CompatibilityStatus();
		$data   = $status->collect();

		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Compatibility status', 'mp-commerce-promotions' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:900px;"><tbody>';

		self::row( __( 'WordPress version', 'mp-commerce-promotions' ), (string) ( $data['wordpress_version'] ?? '' ) );
		self::row( __( 'WooCommerce version', 'mp-commerce-promotions' ), (string) ( $data['woocommerce_version'] ?? '' ) );
		self::row( __( 'PHP version', 'mp-commerce-promotions' ), (string) ( $data['php_version'] ?? '' ) );
		self::row(
			__( 'HPOS enabled', 'mp-commerce-promotions' ),
			! empty( $data['hpos_enabled'] ) ? __( 'Yes', 'mp-commerce-promotions' ) : __( 'No', 'mp-commerce-promotions' )
		);
		self::row(
			__( 'HPOS declared compatible', 'mp-commerce-promotions' ),
			! empty( $data['hpos_declared_compatible'] ) ? __( 'Yes', 'mp-commerce-promotions' ) : __( 'No', 'mp-commerce-promotions' )
		);
		self::row(
			__( 'Cart/Checkout Blocks', 'mp-commerce-promotions' ),
			(string) ( $data['cart_checkout_blocks_note'] ?? __( 'Not declared', 'mp-commerce-promotions' ) )
		);
		self::row(
			__( 'Blocks QA cart page ID', 'mp-commerce-promotions' ),
			(string) (int) ( $data['block_cart_page_id'] ?? 0 )
		);
		self::row(
			__( 'Blocks QA checkout page ID', 'mp-commerce-promotions' ),
			(string) (int) ( $data['block_checkout_page_id'] ?? 0 )
		);
		self::row(
			__( 'Block QA pages present', 'mp-commerce-promotions' ),
			! empty( $data['block_pages_present'] ) ? __( 'Yes', 'mp-commerce-promotions' ) : __( 'No', 'mp-commerce-promotions' )
		);
		self::row(
			__( 'Block compatibility status', 'mp-commerce-promotions' ),
			(string) ( $data['block_compatibility_status'] ?? 'not_tested' )
		);
		$block_notes = trim( (string) ( $data['block_compatibility_notes'] ?? '' ) );
		if ( $block_notes !== '' ) {
			self::row( __( 'Block compatibility notes', 'mp-commerce-promotions' ), $block_notes );
		}
		$preview = is_array( $data['block_preview_urls'] ?? null ) ? $data['block_preview_urls'] : array();
		if ( ! empty( $preview['cart_preview_url'] ) ) {
			self::row( __( 'Block cart preview URL', 'mp-commerce-promotions' ), (string) $preview['cart_preview_url'] );
		}
		if ( ! empty( $preview['checkout_preview_url'] ) ) {
			self::row( __( 'Block checkout preview URL', 'mp-commerce-promotions' ), (string) $preview['checkout_preview_url'] );
		}

		$strategy       = is_array( $data['discount_strategy'] ?? null ) ? $data['discount_strategy'] : array();
		$strategy_parts = array();
		if ( ! empty( $strategy['cart_fees'] ) ) {
			$strategy_parts[] = __( 'Cart fees', 'mp-commerce-promotions' );
		}
		if ( ! empty( $strategy['free_gift_cart_line'] ) ) {
			$strategy_parts[] = __( 'Free gift cart lines', 'mp-commerce-promotions' );
		}
		if ( ! empty( $strategy['allocation_reporting'] ) ) {
			$strategy_parts[] = __( 'Allocation reporting', 'mp-commerce-promotions' );
		}
		self::row( __( 'Discount strategy', 'mp-commerce-promotions' ), implode( '; ', $strategy_parts ) );

		$tax = is_array( $data['tax_mode'] ?? null ) ? (string) ( $data['tax_mode']['label'] ?? '' ) : '';
		self::row( __( 'Tax mode', 'mp-commerce-promotions' ), $tax );
		self::row( __( 'Currency', 'mp-commerce-promotions' ), (string) ( $data['currency'] ?? '' ) );

		$gateways = is_array( $data['payment_gateways'] ?? null ) ? $data['payment_gateways'] : array();
		self::row( __( 'Active payment gateways', 'mp-commerce-promotions' ), $gateways !== array() ? implode( ', ', array_map( 'strval', $gateways ) ) : '—' );

		$shipping = is_array( $data['shipping_methods'] ?? null ) ? $data['shipping_methods'] : array();
		self::row( __( 'Shipping methods (zones)', 'mp-commerce-promotions' ), $shipping !== array() ? implode( ', ', array_map( 'strval', $shipping ) ) : '—' );

		echo '</tbody></table>';
	}

	private static function row( string $label, string $value ): void {
		echo '<tr><th scope="row" style="width:240px;">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}
}
