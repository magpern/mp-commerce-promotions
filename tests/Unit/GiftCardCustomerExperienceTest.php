<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardBalanceChecker;
use MP\CommercePromotions\GiftCard\GiftCardCustomerService;
use MP\CommercePromotions\GiftCard\GiftCardDeliveryStatus;
use MP\CommercePromotions\GiftCard\GiftCardEmailTemplate;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\GiftCard\GiftCardTransaction;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Tests\Support\InMemoryGiftCardStore;
use MP\CommercePromotions\Tests\Support\MemoryGiftCardRepository;
use MP\CommercePromotions\Tests\Support\MemoryGiftCardTransactionRepository;
use MP\CommercePromotions\Woo\GiftCardMyAccount;
use PHPUnit\Framework\TestCase;

final class GiftCardCustomerExperienceTest extends TestCase {

	private GiftCardLedger $ledger;

	private GiftCardRepository $cards;

	protected function setUp(): void {
		global $mp_cp_test_post_meta, $mp_cp_test_options;
		$mp_cp_test_post_meta = array();
		$mp_cp_test_options   = array();

		$store  = new InMemoryGiftCardStore();
		$this->cards  = new MemoryGiftCardRepository( $store );
		$this->ledger = new GiftCardLedger(
			$this->cards,
			new MemoryGiftCardTransactionRepository( $store )
		);
	}

	public function test_balance_checker_lookup_masks_code(): void {
		$issued = $this->ledger->issue( 25.0, 'EUR', null, null );
		$plain  = $issued->get_plain_code();
		$this->assertNotNull( $plain );

		$checker = new GiftCardBalanceChecker( $this->ledger );
		$result  = $checker->lookup( $plain );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 25.0, $result['balance'] );
		$this->assertStringStartsWith( '****', (string) $result['masked_code'] );
		$this->assertStringNotContainsString( $plain, (string) $result['masked_code'] );
	}

	public function test_balance_checker_rejects_invalid(): void {
		$checker = new GiftCardBalanceChecker( $this->ledger );
		$result  = $checker->lookup( 'NOT-A-REAL-CODE-XYZ' );
		$this->assertFalse( $result['ok'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_balance_checker_rate_limit_key(): void {
		$key = GiftCardBalanceChecker::rate_limit_transient_key( '192.0.2.1' );
		$this->assertStringStartsWith( 'mp_cp_gc_balance_', $key );
	}

	public function test_delivery_label_for_failed(): void {
		$label = GiftCardCustomerService::format_delivery_label(
			array( 'delivery_status' => GiftCardDeliveryStatus::FAILED )
		);
		$this->assertStringContainsString( 'failed', strtolower( $label ) );
	}

	public function test_my_account_endpoint_constant(): void {
		$this->assertSame( 'gift-cards', GiftCardMyAccount::ENDPOINT_GIFT_CARDS );
	}

	public function test_email_template_fallback(): void {
		$html = GiftCardEmailTemplate::render_html(
			'unknown_slug',
			array(
				'site_name' => 'Test Shop',
				'store_url' => 'https://example.com',
				'preview'   => true,
				'cards'     => array(
					array(
						'masked_code' => '****1234',
						'amount'      => 50.0,
						'currency'    => 'EUR',
					),
				),
			)
		);
		$this->assertStringContainsString( 'Test Shop', $html );
		$this->assertStringContainsString( '****1234', $html );
		$this->assertStringNotContainsString( 'plain_code', $html );
	}

	public function test_customer_service_status_label(): void {
		$issued = $this->ledger->issue( 10.0, 'EUR', null, null );
		$card   = $this->cards->find( (int) $issued->get_card()->get_id() );
		$this->assertNotNull( $card );
		$label = GiftCardCustomerService::status_label( $card );
		$this->assertSame( 'Active', $label );
	}

	public function test_settings_defaults(): void {
		$settings = new Settings();
		$this->assertTrue( $settings->gift_card_balance_checker_enabled() );
		$this->assertTrue( $settings->gift_card_my_account_enabled() );
		$this->assertTrue( $settings->gift_card_scheduled_cron_enabled() );
		$this->assertSame( Settings::GIFT_CARD_TEMPLATE_CLASSIC, $settings->gift_card_email_template() );
	}

}
