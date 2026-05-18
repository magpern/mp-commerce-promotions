<?php
/**
 * Draft Cart/Checkout block pages for manual Blocks QA (not live storefront pages).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

final class BlockTestPages {

	public const OPTION_CART_PAGE_ID = 'mp_cp_block_cart_page_id';

	public const OPTION_CHECKOUT_PAGE_ID = 'mp_cp_block_checkout_page_id';

	public const OPTION_COMPATIBILITY_STATUS = 'mp_cp_block_compatibility_status';

	public const OPTION_COMPATIBILITY_NOTES = 'mp_cp_block_compatibility_notes';

	public const DEFAULT_CART_PAGE_ID = 4333;

	public const DEFAULT_CHECKOUT_PAGE_ID = 4334;

	public const CART_SLUG = 'mp-cp-block-cart-qa';

	public const CHECKOUT_SLUG = 'mp-cp-block-checkout-qa';

	public const CART_TITLE = 'Promotion Block Cart Test';

	public const CHECKOUT_TITLE = 'Promotion Block Checkout Test';

	/** @deprecated Self-closing blocks render empty; use WooCommerceBlockPageContent::default_*_markup(). */
	public const CART_BLOCK_MARKUP = '<!-- wp:woocommerce/cart /-->';

	/** @deprecated Self-closing blocks render empty; use WooCommerceBlockPageContent::default_*_markup(). */
	public const CHECKOUT_BLOCK_MARKUP = '<!-- wp:woocommerce/checkout /-->';

	public const STATUS_NOT_TESTED = 'not_tested';

	public const STATUS_PARTIAL = 'partial';

	public const STATUS_PASSED = 'passed';

	public const STATUS_FAILED = 'failed';

	public const QA_PROMOTION_PREFIX = 'MP CP Blocks QA';

	/**
	 * @return list<string>
	 */
	public static function allowed_statuses(): array {
		return array(
			self::STATUS_NOT_TESTED,
			self::STATUS_PARTIAL,
			self::STATUS_PASSED,
			self::STATUS_FAILED,
		);
	}

	public static function normalize_status( string $status ): string {
		$status = sanitize_key( $status );

		return in_array( $status, self::allowed_statuses(), true )
			? $status
			: self::STATUS_NOT_TESTED;
	}

	public static function is_block_cart_content( string $content ): bool {
		return str_contains( $content, 'woocommerce/cart' );
	}

	public static function is_block_checkout_content( string $content ): bool {
		return str_contains( $content, 'woocommerce/checkout' );
	}

	/**
	 * @return array{cart_page_id: int, checkout_page_id: int}
	 */
	public function ensure_pages(): array {
		$cart_id     = $this->ensure_single_page(
			self::OPTION_CART_PAGE_ID,
			self::DEFAULT_CART_PAGE_ID,
			self::CART_TITLE,
			self::CART_SLUG,
			WooCommerceBlockPageContent::default_cart_markup(),
			true
		);
		$checkout_id = $this->ensure_single_page(
			self::OPTION_CHECKOUT_PAGE_ID,
			self::DEFAULT_CHECKOUT_PAGE_ID,
			self::CHECKOUT_TITLE,
			self::CHECKOUT_SLUG,
			WooCommerceBlockPageContent::default_checkout_markup(),
			false
		);

		return array(
			'cart_page_id'     => $cart_id,
			'checkout_page_id' => $checkout_id,
		);
	}

	/**
	 * Upgrade existing QA pages from self-closing block comments to full inner block markup.
	 *
	 * @return array{cart_repaired: bool, checkout_repaired: bool}
	 */
	public function repair_page_block_markup(): array {
		$cart_id     = $this->resolve_page_id( self::OPTION_CART_PAGE_ID, self::DEFAULT_CART_PAGE_ID );
		$checkout_id = $this->resolve_page_id( self::OPTION_CHECKOUT_PAGE_ID, self::DEFAULT_CHECKOUT_PAGE_ID );

		return array(
			'cart_repaired'     => $this->repair_single_page( $cart_id, true ),
			'checkout_repaired' => $this->repair_single_page( $checkout_id, false ),
		);
	}

	/**
	 * @return array{cart_page_id: int, checkout_page_id: int, block_pages_present: bool}
	 */
	public function resolve_page_state(): array {
		$cart_id     = $this->resolve_page_id( self::OPTION_CART_PAGE_ID, self::DEFAULT_CART_PAGE_ID );
		$checkout_id = $this->resolve_page_id( self::OPTION_CHECKOUT_PAGE_ID, self::DEFAULT_CHECKOUT_PAGE_ID );

		$cart_ok     = $this->page_has_complete_block_structure( $cart_id, true );
		$checkout_ok = $this->page_has_complete_block_structure( $checkout_id, false );

		return array(
			'cart_page_id'        => $cart_id,
			'checkout_page_id'    => $checkout_id,
			'block_pages_present' => $cart_ok && $checkout_ok,
		);
	}

	/**
	 * @return array{cart_preview_url: string, checkout_preview_url: string, cart_permalink: string, checkout_permalink: string}
	 */
	public function preview_urls( int $cart_id, int $checkout_id ): array {
		$home = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';

		return array(
			'cart_preview_url'     => $cart_id > 0 ? add_query_arg( 'page_id', (string) $cart_id, $home ) : '',
			'checkout_preview_url' => $checkout_id > 0 ? add_query_arg( 'page_id', (string) $checkout_id, $home ) : '',
			'cart_permalink'       => $cart_id > 0 && function_exists( 'get_permalink' ) ? (string) get_permalink( $cart_id ) : '',
			'checkout_permalink'   => $checkout_id > 0 && function_exists( 'get_permalink' ) ? (string) get_permalink( $checkout_id ) : '',
		);
	}

	public function compatibility_status(): string {
		$raw = get_option( self::OPTION_COMPATIBILITY_STATUS, self::STATUS_NOT_TESTED );

		return self::normalize_status( is_string( $raw ) ? $raw : self::STATUS_NOT_TESTED );
	}

	public function compatibility_notes(): string {
		$raw = get_option( self::OPTION_COMPATIBILITY_NOTES, '' );

		return is_string( $raw ) ? $raw : '';
	}

	public function set_compatibility_status( string $status, string $notes = '' ): void {
		update_option( self::OPTION_COMPATIBILITY_STATUS, self::normalize_status( $status ), false );
		update_option( self::OPTION_COMPATIBILITY_NOTES, sanitize_textarea_field( $notes ), false );
	}

	private function resolve_page_id( string $option, int $default_id ): int {
		$stored = get_option( $option, $default_id );
		$id     = is_numeric( $stored ) ? (int) $stored : $default_id;
		if ( $id > 0 && $this->get_post_type( $id ) === 'page' ) {
			return $id;
		}
		if ( $default_id > 0 && $this->get_post_type( $default_id ) === 'page' ) {
			return $default_id;
		}

		return 0;
	}

	private function ensure_single_page(
		string $option,
		int $preferred_id,
		string $title,
		string $slug,
		string $content,
		bool $is_cart
	): int {
		$existing = $this->resolve_page_id( $option, $preferred_id );
		if ( $existing > 0 ) {
			if ( $this->page_has_complete_block_structure( $existing, $is_cart ) ) {
				update_option( $option, $existing, false );

				return $existing;
			}
			if ( $this->repair_single_page( $existing, $is_cart ) ) {
				update_option( $option, $existing, false );

				return $existing;
			}
		}

		if ( ! function_exists( 'wp_insert_post' ) ) {
			return $existing > 0 ? $existing : 0;
		}

		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $content,
				'post_status'  => 'draft',
				'post_type'    => 'page',
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! is_numeric( $post_id ) ) {
			return $existing > 0 ? $existing : 0;
		}

		$id = (int) $post_id;
		update_option( $option, $id, false );

		return $id;
	}

	private function page_has_complete_block_structure( int $page_id, bool $cart ): bool {
		if ( $page_id <= 0 ) {
			return false;
		}

		$content = $this->get_post_content( $page_id );

		return $cart
			? WooCommerceBlockPageContent::has_complete_cart_structure( $content )
			: WooCommerceBlockPageContent::has_complete_checkout_structure( $content );
	}

	private function repair_single_page( int $page_id, bool $is_cart ): bool {
		if ( $page_id <= 0 || ! function_exists( 'wp_update_post' ) ) {
			return false;
		}

		$content = $is_cart
			? WooCommerceBlockPageContent::default_cart_markup()
			: WooCommerceBlockPageContent::default_checkout_markup();

		$result = wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => $content,
			),
			true
		);

		return ! is_wp_error( $result );
	}

	private function get_post_content( int $post_id ): string {
		if ( ! function_exists( 'get_post' ) ) {
			return '';
		}

		$post = get_post( $post_id );
		if ( ! is_object( $post ) || ! isset( $post->post_content ) ) {
			return '';
		}

		return (string) $post->post_content;
	}

	private function get_post_type( int $post_id ): string {
		if ( ! function_exists( 'get_post_type' ) ) {
			return '';
		}

		$type = get_post_type( $post_id );

		return is_string( $type ) ? $type : '';
	}
}
