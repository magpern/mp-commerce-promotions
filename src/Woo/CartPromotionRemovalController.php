<?php
/**
 * Shopper-facing remove/restore controls for applied Commerce promotions in cart totals.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Domain\PromotionCodeRepository;

final class CartPromotionRemovalController {

	public const NONCE_REMOVE = 'mp_cp_remove_promotion';

	public const NONCE_RESTORE = 'mp_cp_restore_promotions';

	public const QUERY_REMOVE = 'mp_cp_remove_promotion';

	public const QUERY_RESTORE = 'mp_cp_restore_promotions';

	private PromotionCodeRepository $promotion_codes;

	public function __construct( PromotionCodeRepository $promotion_codes ) {
		$this->promotion_codes = $promotion_codes;
	}

	public function register(): void {
		add_action( 'wp_loaded', array( $this, 'maybe_handle_request' ), 5 );
		add_filter( 'woocommerce_cart_totals_fee_html', array( $this, 'filter_fee_html' ), 10, 2 );
		add_action( 'woocommerce_before_cart_totals', array( $this, 'render_restore_notice' ), 6 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_cart_styles' ) );
	}

	public function enqueue_cart_styles(): void {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return;
		}

		if ( ! defined( 'MP_COMMERCE_PROMOTIONS_URL' ) || ! defined( 'MP_COMMERCE_PROMOTIONS_VERSION' ) ) {
			return;
		}

		wp_enqueue_style(
			'mp-cp-gift-card-customer',
			MP_COMMERCE_PROMOTIONS_URL . 'assets/css/gift-card-customer.css',
			array(),
			MP_COMMERCE_PROMOTIONS_VERSION
		);
	}

	public function maybe_handle_request(): void {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_get_cart_url' ) ) {
			return;
		}

		if ( isset( $_GET[ self::QUERY_RESTORE ] ) ) {
			$this->handle_restore_request();

			return;
		}

		if ( ! isset( $_GET[ self::QUERY_REMOVE ] ) ) {
			return;
		}

		$this->handle_remove_request();
	}

	private function handle_restore_request(): void {
		if ( ! isset( $_GET['_wpnonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_RESTORE ) ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( __( 'Security check failed. Please try again.', 'mp-commerce-promotions' ), 'error' );
			}
			$this->redirect_to_cart();

			return;
		}

		PromotionCartExclusionSession::clear_all();

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'Removed promotions were restored for this cart.', 'mp-commerce-promotions' ), 'success' );
		}

		$this->recalculate_cart();
		$this->redirect_to_cart();
	}

	private function handle_remove_request(): void {
		if ( ! isset( $_GET['_wpnonce'] ) ) {
			return;
		}

		$promotion_id = (int) sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_REMOVE ] ) );
		if ( $promotion_id <= 0 ) {
			$this->redirect_to_cart();

			return;
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_REMOVE ) ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( __( 'Security check failed. Please try again.', 'mp-commerce-promotions' ), 'error' );
			}
			$this->redirect_to_cart();

			return;
		}

		PromotionCartExclusionSession::exclude( $promotion_id );
		$this->remove_linked_coupons_for_promotion( $promotion_id );

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'Promotion removed from this cart.', 'mp-commerce-promotions' ), 'success' );
		}

		$this->recalculate_cart();
		$this->redirect_to_cart();
	}

	private function remove_linked_coupons_for_promotion( int $promotion_id ): void {
		if ( ! function_exists( 'WC' ) || ! is_object( WC()->cart ?? null ) ) {
			return;
		}

		$cart = WC()->cart;
		if ( ! method_exists( $cart, 'get_applied_coupons' ) || ! method_exists( $cart, 'remove_coupon' ) ) {
			return;
		}

		$coupons = $cart->get_applied_coupons();
		if ( ! is_array( $coupons ) ) {
			return;
		}

		foreach ( $coupons as $coupon_code ) {
			if ( ! is_string( $coupon_code ) || $coupon_code === '' ) {
				continue;
			}

			$promotion_code = $this->promotion_codes->find_by_plain_code( $coupon_code );
			if ( $promotion_code === null ) {
				continue;
			}

			if ( (int) $promotion_code->get_promotion_id() === $promotion_id ) {
				$cart->remove_coupon( $coupon_code );
			}
		}
	}

	/**
	 * @param string $fee_html Cart totals fee row HTML.
	 * @param object $fee      WooCommerce fee object.
	 */
	public function filter_fee_html( string $fee_html, $fee ): string {
		if ( ! is_object( $fee ) || ! isset( $fee->name ) ) {
			return $fee_html;
		}

		$entries = AppliedPromotionSession::entries_from_session(
			CartSessionHelper::get_applied_promotion()
		);
		if ( $entries === array() ) {
			return $fee_html;
		}

		$promotion_id = PromotionFeeLabelResolver::promotion_id_from_fee_label( (string) $fee->name, $entries );
		if ( $promotion_id === null || $promotion_id <= 0 ) {
			return $fee_html;
		}

		if ( PromotionCartExclusionSession::is_excluded( $promotion_id ) ) {
			return $fee_html;
		}

		$url = wp_nonce_url(
			add_query_arg( self::QUERY_REMOVE, (string) $promotion_id, wc_get_cart_url() ),
			self::NONCE_REMOVE
		);

		$link = sprintf(
			'<a href="%1$s" class="mp-cp-cart-promotion-remove" aria-label="%2$s">%3$s</a>',
			esc_url( $url ),
			esc_attr__( 'Remove this promotion from the cart', 'mp-commerce-promotions' ),
			esc_html__( 'Remove', 'mp-commerce-promotions' )
		);

		return $fee_html . ' ' . $link;
	}

	public function render_restore_notice(): void {
		if ( ! PromotionCartExclusionSession::has_exclusions() ) {
			return;
		}

		$url = wp_nonce_url(
			add_query_arg( self::QUERY_RESTORE, '1', wc_get_cart_url() ),
			self::NONCE_RESTORE
		);

		echo '<p class="mp-cp-cart-promotion-restore-notice">';
		echo esc_html__( 'Some promotions were removed from this cart.', 'mp-commerce-promotions' );
		echo ' <a href="' . esc_url( $url ) . '" class="mp-cp-cart-promotion-restore-link">';
		echo esc_html__( 'Restore', 'mp-commerce-promotions' );
		echo '</a></p>';
	}

	private function recalculate_cart(): void {
		if ( ! function_exists( 'WC' ) || ! is_object( WC()->cart ?? null ) ) {
			return;
		}

		if ( method_exists( WC()->cart, 'calculate_totals' ) ) {
			WC()->cart->calculate_totals();
		}
	}

	private function redirect_to_cart(): void {
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}
}
