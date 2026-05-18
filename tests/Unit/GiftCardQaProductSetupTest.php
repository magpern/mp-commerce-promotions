<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use MP\CommercePromotions\GiftCard\GiftCardQaProductSetup;
use PHPUnit\Framework\TestCase;

final class GiftCardQaProductSetupTest extends TestCase {

	public function test_qa_sku_constant(): void {
		$this->assertSame( 'mp-cg-gift-card-qa', GiftCardQaProductSetup::PRODUCT_SKU );
	}

	public function test_catalog_count_without_wpdb_returns_zero(): void {
		global $wpdb;
		$backup = $wpdb;
		$wpdb   = null;
		$this->assertSame( 0, GiftCardQaProductSetup::count_published_gift_card_products() );
		$wpdb = $backup;
	}

	public function test_meta_sells_key_matches_product_meta(): void {
		$this->assertSame( '_mp_cp_sells_gift_card', GiftCardProductMeta::META_SELLS );
	}
}
