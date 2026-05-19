<?php
/**
 * Front-end styles for gift card customer UX.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

final class GiftCardCustomerAssets {

	private static bool $enqueued = false;

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_style' ) );
	}

	public static function register_style(): void {
		if ( ! defined( 'MP_COMMERCE_PROMOTIONS_URL' ) || ! defined( 'MP_COMMERCE_PROMOTIONS_VERSION' ) ) {
			return;
		}

		wp_register_style(
			'mp-cp-gift-card-customer',
			MP_COMMERCE_PROMOTIONS_URL . 'assets/css/gift-card-customer.css',
			array(),
			MP_COMMERCE_PROMOTIONS_VERSION
		);

		wp_register_script(
			'mp-cp-gift-card-credit-accordion',
			MP_COMMERCE_PROMOTIONS_URL . 'assets/js/gift-card-credit-accordion.js',
			array(),
			MP_COMMERCE_PROMOTIONS_VERSION,
			true
		);
	}

	public static function enqueue(): void {
		if ( self::$enqueued ) {
			return;
		}
		if ( wp_style_is( 'mp-cp-gift-card-customer', 'registered' ) ) {
			wp_enqueue_style( 'mp-cp-gift-card-customer' );
		}
		if ( wp_script_is( 'mp-cp-gift-card-credit-accordion', 'registered' ) ) {
			wp_enqueue_script( 'mp-cp-gift-card-credit-accordion' );
		}
		self::$enqueued = true;
	}
}
