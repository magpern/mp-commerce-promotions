<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use InvalidArgumentException;
use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use MP\CommercePromotions\GiftCard\GiftCardProductService;
use PHPUnit\Framework\TestCase;

final class GiftCardProductServiceTest extends TestCase {

	private GiftCardProductService $service;

	protected function setUp(): void {
		global $mp_cp_test_post_meta;
		$mp_cp_test_post_meta = array();
		$this->service          = new GiftCardProductService();
	}

	public function test_product_marked_as_gift_card_via_meta(): void {
		GiftCardProductMeta::save(
			501,
			array(
				'sells'        => GiftCardProductMeta::VALUE_YES,
				'amount_mode'  => GiftCardProductMeta::AMOUNT_MODE_PRODUCT_PRICE,
				'fixed_amount' => '',
				'expiry_days'  => '',
			)
		);

		$this->assertTrue( $this->service->is_gift_card_product( 501 ) );
		$config = $this->service->get_line_config( 501, 0 );
		$this->assertNotNull( $config );
		$this->assertSame( GiftCardProductMeta::AMOUNT_MODE_PRODUCT_PRICE, $config['amount_mode'] );
	}

	public function test_amount_from_product_price(): void {
		$config = array(
			'sells'          => true,
			'amount_mode'    => GiftCardProductMeta::AMOUNT_MODE_PRODUCT_PRICE,
			'fixed_amount'   => 0.0,
			'expiry_days'    => null,
			'recipient_mode' => GiftCardProductMeta::RECIPIENT_PURCHASER_ONLY,
		);

		$this->assertSame( 25.0, $this->service->resolve_unit_amount( $config, 50.0, 2 ) );
	}

	public function test_amount_from_fixed_amount(): void {
		$config = array(
			'sells'          => true,
			'amount_mode'    => GiftCardProductMeta::AMOUNT_MODE_FIXED,
			'fixed_amount'   => 75.0,
			'expiry_days'    => null,
			'recipient_mode' => GiftCardProductMeta::RECIPIENT_PURCHASER_ONLY,
		);

		$this->assertSame( 75.0, $this->service->resolve_unit_amount( $config, 10.0, 1 ) );
	}

	public function test_resolve_expires_at_from_expiry_days(): void {
		$expires = $this->service->resolve_expires_at( 30, '2026-01-01 12:00:00' );
		$this->assertNotNull( $expires );
		$this->assertStringContainsString( '2026', $expires );
	}

	public function test_zero_amount_rejected(): void {
		$config = array(
			'sells'          => true,
			'amount_mode'    => GiftCardProductMeta::AMOUNT_MODE_FIXED,
			'fixed_amount'   => 0.0,
			'expiry_days'    => null,
			'recipient_mode' => GiftCardProductMeta::RECIPIENT_PURCHASER_ONLY,
		);

		$this->expectException( InvalidArgumentException::class );
		$this->service->resolve_unit_amount( $config, 0.0, 1 );
	}
}
