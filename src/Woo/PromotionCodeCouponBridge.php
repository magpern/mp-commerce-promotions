<?php
/**
 * Lets manual promotion codes pass through the WooCommerce coupon field (no WC coupon posts).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Domain\PromotionCode;
use MP\CommercePromotions\Domain\PromotionCodeRepository;
use WC_Coupon;
use WC_Discounts;

final class PromotionCodeCouponBridge {

	private PromotionCodeRepository $promotion_codes;

	private bool $hooks_registered = false;

	public function __construct( PromotionCodeRepository $promotion_codes ) {
		$this->promotion_codes = $promotion_codes;
	}

	/**
	 * Register virtual-coupon filters (see WooCommerceBridge class docblock for hook list).
	 */
	public function register_hooks(): void {
		if ( $this->hooks_registered ) {
			return;
		}

		add_filter( 'woocommerce_get_shop_coupon_data', array( $this, 'filter_shop_coupon_data' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'filter_coupon_is_valid' ), 10, 3 );

		$this->hooks_registered = true;
	}

	/**
	 * Virtual coupon payload so WooCommerce accepts our code in the coupon field.
	 *
	 * @param array<string, mixed>|false $data Existing coupon data.
	 * @param string                     $code Coupon code entered by the customer.
	 * @return array<string, mixed>|false
	 */
	public function filter_shop_coupon_data( $data, string $code ) {
		if ( $data !== false && is_array( $data ) && $data !== array() ) {
			return $data;
		}

		$promotion_code = $this->promotion_codes->find_by_plain_code( $code );
		if ( $promotion_code === null ) {
			return $data;
		}

		return $this->build_virtual_coupon_data( $code );
	}

	/**
	 * @param bool              $valid   Whether the coupon is valid.
	 * @param WC_Coupon         $coupon  Coupon object.
	 * @param WC_Discounts|null $discounts Discounts instance.
	 */
	public function filter_coupon_is_valid( bool $valid, $coupon, $discounts ): bool {
		if ( ! $coupon instanceof WC_Coupon ) {
			return $valid;
		}

		$code = $coupon->get_code();
		if ( $code === '' ) {
			return $valid;
		}

		$promotion_code = $this->promotion_codes->find_by_plain_code( $code );
		if ( $promotion_code === null ) {
			return $valid;
		}

		return $this->promotion_codes->is_code_usable( $promotion_code );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_virtual_coupon_data( string $code ): array {
		return array(
			'code'                        => $code,
			'amount'                      => '0',
			'discount_type'               => 'fixed_cart',
			'description'                 => __( 'Commerce promotion code', 'mp-commerce-promotions' ),
			'individual_use'              => false,
			'product_ids'                 => array(),
			'excluded_product_ids'        => array(),
			'usage_limit'                 => '',
			'usage_limit_per_user'        => '',
			'limit_usage_to_x_items'      => null,
			'free_shipping'               => false,
			'product_categories'          => array(),
			'excluded_product_categories' => array(),
			'exclude_sale_items'          => false,
			'minimum_amount'              => '',
			'maximum_amount'              => '',
			'email_restrictions'          => array(),
		);
	}
}
