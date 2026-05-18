<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use InvalidArgumentException;
use MP\CommercePromotions\GiftCard\GiftCardCurrency;
use PHPUnit\Framework\TestCase;

final class GiftCardCurrencyTest extends TestCase {

	protected function tearDown(): void {
		if ( function_exists( 'remove_all_filters' ) ) {
			remove_all_filters( GiftCardCurrency::FILTER_ALLOWED );
		}
		parent::tearDown();
	}

	public function test_store_currency_fallback(): void {
		$this->assertSame( 'EUR', GiftCardCurrency::store_currency() );
	}

	public function test_validate_accepts_known_code(): void {
		$this->assertSame( 'EUR', GiftCardCurrency::validate( 'eur' ) );
	}

	public function test_validate_defaults_empty_to_store_currency(): void {
		$this->assertSame( GiftCardCurrency::store_currency(), GiftCardCurrency::validate( '' ) );
	}

	public function test_validate_rejects_unknown_code(): void {
		$this->expectException( InvalidArgumentException::class );
		GiftCardCurrency::validate( 'ZZZ' );
	}

	public function test_allowed_currencies_filter_restricts_list(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			$this->markTestSkipped( 'WordPress filter API not available.' );
		}

		add_filter(
			GiftCardCurrency::FILTER_ALLOWED,
			static function ( array $codes ): array {
				return array( 'EUR' );
			}
		);

		$allowed = GiftCardCurrency::allowed_currencies();
		$this->assertArrayHasKey( 'EUR', $allowed );
		$this->assertArrayNotHasKey( 'USD', $allowed );
		$this->assertFalse( GiftCardCurrency::is_allowed( 'USD' ) );
	}
}
