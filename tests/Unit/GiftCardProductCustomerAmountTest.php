<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\GiftCard\GiftCardProductCustomerAmount;
use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use PHPUnit\Framework\TestCase;

final class GiftCardProductCustomerAmountTest extends TestCase {

	protected function setUp(): void {
		global $mp_cp_test_post_meta;
		$mp_cp_test_post_meta = array();
	}

	public function test_parse_suggested_amounts(): void {
		$this->assertSame(
			array( 25.0, 50.0, 100.0 ),
			GiftCardProductCustomerAmount::parse_suggested_amounts( '25, 50, 100' )
		);
	}

	public function test_normalize_admin_requires_positive_min(): void {
		$result = GiftCardProductCustomerAmount::normalize_admin_settings(
			array(
				'min_amount'        => '0',
				'max_amount'        => '100',
				'suggested_amounts' => '25,50',
				'default_amount'    => '30',
			)
		);
		$this->assertNotEmpty( $result['errors'] );
		$this->assertGreaterThan( 0, $result['min_amount'] );
	}

	public function test_validate_customer_amount_within_range(): void {
		$config = $this->customer_config( 10.0, 500.0 );
		$this->assertNull( GiftCardProductCustomerAmount::validate_customer_amount( 50.0, $config ) );
		$this->assertNotNull( GiftCardProductCustomerAmount::validate_customer_amount( 0.0, $config ) );
		$this->assertNotNull( GiftCardProductCustomerAmount::validate_customer_amount( 600.0, $config ) );
	}

	public function test_catalog_price_html_choose_amount(): void {
		$html = GiftCardProductCustomerAmount::catalog_price_html( $this->customer_config( 0.0, null ) );
		$this->assertStringContainsString( 'Choose amount', $html );
	}

	public function test_catalog_price_html_from_min(): void {
		$html = GiftCardProductCustomerAmount::catalog_price_html( $this->customer_config( 25.0, null ) );
		// Storefront rounding lifts min 25 → 30 when displaying "From …".
		$this->assertStringContainsString( '30', $html );
		$this->assertStringContainsString( 'From', $html );
	}

	public function test_meta_save_and_read_customer_amount_settings(): void {
		GiftCardProductMeta::save(
			7001,
			array(
				'sells'             => GiftCardProductMeta::VALUE_YES,
				'amount_mode'       => GiftCardProductMeta::AMOUNT_MODE_CUSTOMER_AMOUNT,
				'min_amount'        => '10',
				'max_amount'        => '500',
				'suggested_amounts' => '25,50,100',
				'default_amount'    => '50',
				'fixed_amount'      => '',
				'expiry_days'       => '',
			)
		);

		$config = GiftCardProductMeta::read( 7001 );
		$this->assertSame( GiftCardProductMeta::AMOUNT_MODE_CUSTOMER_AMOUNT, $config['amount_mode'] );
		$this->assertSame( 10.0, $config['min_amount'] );
		$this->assertSame( 500.0, $config['max_amount'] );
		$this->assertSame( array( 25.0, 50.0, 100.0 ), $config['suggested_amounts'] );
		$this->assertSame( 50.0, $config['default_amount'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function customer_config( float $min, ?float $max ): array {
		return array(
			'sells'             => true,
			'amount_mode'       => GiftCardProductMeta::AMOUNT_MODE_CUSTOMER_AMOUNT,
			'fixed_amount'      => 0.0,
			'expiry_days'       => null,
			'recipient_mode'    => GiftCardProductMeta::RECIPIENT_PURCHASER_ONLY,
			'min_amount'        => $min,
			'max_amount'        => $max,
			'suggested_amounts' => array( 25.0, 50.0 ),
			'default_amount'    => null,
		);
	}
}
