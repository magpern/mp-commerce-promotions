<?php
/**
 * Idempotent QA gift card WooCommerce product setup.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use WC_Product;
use WC_Product_Simple;

final class GiftCardQaProductSetup {

	public const PRODUCT_SKU = 'mp-cg-gift-card-qa';

	public const PRODUCT_NAME = 'Commerce Growth Gift Card QA';

	public const DEFAULT_PRICE = '30';

	/**
	 * Create or update the demo gift card product.
	 *
	 * @return array{
	 *   product_id: int,
	 *   created: bool,
	 *   product_url: string,
	 *   meta: array{sells: bool, amount_mode: string, fixed_amount: float, expiry_days: ?int, recipient_mode: string}
	 * }
	 */
	public function ensure_demo_product(): array {
		if ( ! class_exists( WC_Product_Simple::class ) ) {
			throw new \RuntimeException( 'WooCommerce product API unavailable.' );
		}

		$product_id = $this->find_product_id_by_sku( self::PRODUCT_SKU );
		$created    = false;

		if ( $product_id > 0 ) {
			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product ) {
				$product_id = 0;
			}
		}

		if ( $product_id <= 0 ) {
			$product = new WC_Product_Simple();
			$product->set_sku( self::PRODUCT_SKU );
			$created = true;
		}

		$product->set_name( self::PRODUCT_NAME );
		$product->set_status( 'publish' );
		$product->set_regular_price( self::DEFAULT_PRICE );
		$product->set_virtual( true );
		$product->set_manage_stock( false );
		$product->set_sold_individually( false );

		$product_id = (int) $product->save();
		if ( $product_id <= 0 ) {
			throw new \RuntimeException( 'Failed to save QA gift card product.' );
		}

		GiftCardProductMeta::save(
			$product_id,
			array(
				'sells'          => GiftCardProductMeta::VALUE_YES,
				'amount_mode'    => GiftCardProductMeta::AMOUNT_MODE_PRODUCT_PRICE,
				'fixed_amount'   => 0,
				'expiry_days'    => '365',
				'recipient_mode' => GiftCardProductMeta::RECIPIENT_EMAIL_AND_MESSAGE,
			)
		);

		$url = get_permalink( $product_id );
		if ( ! is_string( $url ) ) {
			$url = '';
		}

		return array(
			'product_id'  => $product_id,
			'created'     => $created,
			'product_url' => $url,
			'meta'        => GiftCardProductMeta::read( $product_id ),
		);
	}

	/**
	 * @return list<int>
	 */
	public static function find_published_gift_card_product_ids( int $limit = 20 ): array {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return array();
		}

		$limit = max( 1, min( 100, $limit ) );
		$sql   = $wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id
			WHERE m.meta_key = %s AND m.meta_value = %s
			AND p.post_type IN ('product', 'product_variation')
			AND p.post_status = 'publish'
			ORDER BY p.ID DESC
			LIMIT %d",
			GiftCardProductMeta::META_SELLS,
			GiftCardProductMeta::VALUE_YES,
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $sql );
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$out = array();
		foreach ( $ids as $id ) {
			$int = (int) $id;
			if ( $int > 0 ) {
				$out[] = $int;
			}
		}

		return $out;
	}

	public static function count_published_gift_card_products(): int {
		return count( self::find_published_gift_card_product_ids( 100 ) );
	}

	private function find_product_id_by_sku( string $sku ): int {
		if ( $sku === '' || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return 0;
		}

		return (int) wc_get_product_id_by_sku( $sku );
	}
}
