<?php
/**
 * WooCommerce product edit UI for bulk pricing brackets.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\BulkPricing\BulkPricingCacheInvalidator;
use MP\CommercePromotions\BulkPricing\BulkPricingProductMeta;
use MP\CommercePromotions\Service\Settings;

final class BulkPricingProductAdmin {

	public const TAB_KEY = 'mp_cp_bulk_pricing';

	public const PANEL_ID = 'mp_cp_bulk_pricing_product_data';

	private Settings $settings;

	private BulkPricingProductMeta $meta;

	private BulkPricingCacheInvalidator $cache;

	public function __construct(
		?Settings $settings = null,
		?BulkPricingProductMeta $meta = null,
		?BulkPricingCacheInvalidator $cache = null
	) {
		$this->settings = $settings ?? new Settings();
		$this->meta     = $meta ?? new BulkPricingProductMeta();
		$this->cache    = $cache ?? new BulkPricingCacheInvalidator( $this->settings );
	}

	public function register(): void {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'register_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ), 10, 1 );
	}

	/**
	 * @param array<string, array<string, mixed>> $tabs
	 * @return array<string, array<string, mixed>>
	 */
	public function register_tab( array $tabs ): array {
		$tabs[ self::TAB_KEY ] = array(
			'label'    => __( 'Bulk pricing', 'mp-commerce-promotions' ),
			'target'   => self::PANEL_ID,
			'class'    => array( 'show_if_simple' ),
			'priority' => 66,
		);

		return $tabs;
	}

	public function render_panel(): void {
		global $post;
		$product_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
		if ( $product_id <= 0 ) {
			return;
		}

		$config = $this->meta->read( $product_id );

		echo '<div id="' . esc_attr( self::PANEL_ID ) . '" class="panel woocommerce_options_panel hidden">';
		echo '<div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => 'mp_cp_bulk_pricing_enabled',
				'label'       => __( 'Enable bulk pricing', 'mp-commerce-promotions' ),
				'description' => __( 'Quantity brackets apply to this simple product when global bulk pricing is enabled.', 'mp-commerce-promotions' ),
				'value'       => $config->is_enabled() ? 'yes' : 'no',
			)
		);
		echo '</div>';

		echo '<div class="options_group"><p><strong>' . esc_html__( 'Brackets', 'mp-commerce-promotions' ) . '</strong></p>';
		$tiers = $config->get_tiers();
		if ( $tiers === array() ) {
			$tiers = array(
				array(
					'min_quantity'        => 1,
					'discount_percentage' => 0,
					'anchor_quantity'   => 1,
					'badge'             => null,
					'sort_order'        => 1,
				),
			);
		}

		foreach ( $tiers as $index => $tier ) {
			echo '<p class="form-field">';
			echo '<label>' . esc_html( sprintf( __( 'Bracket %d', 'mp-commerce-promotions' ), $index + 1 ) ) . '</label>';
			echo '<input type="number" name="mp_cp_bulk_tiers[' . esc_attr( (string) $index ) . '][min_quantity]" value="' . esc_attr( (string) $tier['min_quantity'] ) . '" min="1" step="1" placeholder="min qty" /> ';
			echo '<input type="number" name="mp_cp_bulk_tiers[' . esc_attr( (string) $index ) . '][discount_percentage]" value="' . esc_attr( (string) $tier['discount_percentage'] ) . '" min="0" max="100" step="1" placeholder="%" /> ';
			echo '<input type="number" name="mp_cp_bulk_tiers[' . esc_attr( (string) $index ) . '][anchor_quantity]" value="' . esc_attr( (string) $tier['anchor_quantity'] ) . '" min="1" step="1" placeholder="anchor" /> ';
			echo '<input type="text" name="mp_cp_bulk_tiers[' . esc_attr( (string) $index ) . '][badge]" value="' . esc_attr( (string) ( $tier['badge'] ?? '' ) ) . '" placeholder="badge" maxlength="40" /> ';
			echo '<input type="hidden" name="mp_cp_bulk_tiers[' . esc_attr( (string) $index ) . '][sort_order]" value="' . esc_attr( (string) $tier['sort_order'] ) . '" />';
			echo '</p>';
		}
		echo '<p class="description">' . esc_html__( 'Highest min_quantity where cart qty >= min_quantity wins. Percentage off current selling price. Badges are merchant-entered only.', 'mp-commerce-promotions' ) . '</p>';
		echo '</div></div>';
	}

	public function save( int $product_id ): void {
		if ( $product_id <= 0 || ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}

		if ( ! isset( $_POST['mp_cp_bulk_tiers'] ) && ! isset( $_POST['mp_cp_bulk_pricing_enabled'] ) ) {
			return;
		}

		$post = array(
			'mp_cp_bulk_pricing_enabled' => isset( $_POST['mp_cp_bulk_pricing_enabled'] ) ? 'yes' : '',
			'mp_cp_bulk_tiers'           => isset( $_POST['mp_cp_bulk_tiers'] ) && is_array( $_POST['mp_cp_bulk_tiers'] )
				? wp_unslash( $_POST['mp_cp_bulk_tiers'] )
				: array(),
		);

		try {
			$validated = $this->meta->validate_from_post( $post );
		} catch ( \InvalidArgumentException $e ) {
			return;
		}

		$this->meta->write( $product_id, $validated['enabled'], $validated['tiers'] );
		$this->cache->invalidate_product( $product_id );
	}
}
