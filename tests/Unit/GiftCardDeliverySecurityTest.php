<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\GiftCard\GiftCardDeliveryMailer;
use MP\CommercePromotions\GiftCard\GiftCardDeliveryStatus;
use MP\CommercePromotions\GiftCard\GiftCardGeneratedOrderState;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardOrderGenerator;
use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use MP\CommercePromotions\GiftCard\GiftCardProductService;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Tests\Support\InMemoryGiftCardStore;
use MP\CommercePromotions\Tests\Support\MemoryGiftCardRepository;
use MP\CommercePromotions\Tests\Support\MemoryGiftCardTransactionRepository;
use PHPUnit\Framework\TestCase;
use WC_Order;

final class GiftCardDeliverySecurityTest extends TestCase {

	private InMemoryGiftCardStore $store;

	private GiftCardLedger $ledger;

	private GiftCardRepository $cards;

	protected function setUp(): void {
		global $mp_cp_test_post_meta, $mp_cp_test_wp_mail_result;
		$mp_cp_test_post_meta       = array();
		$mp_cp_test_wp_mail_result   = true;

		$this->store  = new InMemoryGiftCardStore();
		$this->cards  = new MemoryGiftCardRepository( $this->store );
		$this->ledger = new GiftCardLedger(
			$this->cards,
			new MemoryGiftCardTransactionRepository( $this->store )
		);
	}

	public function test_generated_order_meta_does_not_persist_plain_code(): void {
		GiftCardProductMeta::save(
			9100,
			array(
				'sells'        => GiftCardProductMeta::VALUE_YES,
				'amount_mode'  => GiftCardProductMeta::AMOUNT_MODE_FIXED,
				'fixed_amount' => '15',
				'expiry_days'  => '',
			)
		);

		$settings  = new Settings();
		$settings->set_gift_card_delivery_email_enabled( true );
		$generator = new GiftCardOrderGenerator( $this->ledger, new GiftCardProductService(), $settings );

		$order = new GiftCardDeliveryOrderStub();
		$order->set_id( 91001 );
		$order->set_currency( 'EUR' );
		$order->set_billing_email( 'buyer@example.com' );
		$order->add_line( 9100, 0, 1, 15.0, 1 );

		$generator->generate_for_order( $order );

		$raw = $order->meta[ GiftCardGeneratedOrderState::META_GENERATED ] ?? '';
		$this->assertNotSame( '', $raw );
		$this->assertStringNotContainsString( 'plain_code', $raw );

		$rows = GiftCardGeneratedOrderState::get_generated( $order );
		$this->assertCount( 1, $rows );
		$this->assertSame( '****', substr( (string) $rows[0]['masked_code'], 0, 4 ) );
		$this->assertArrayNotHasKey( 'plain_code', $rows[0] );
	}

	public function test_invalid_email_marks_delivery_failed(): void {
		$settings = new Settings();
		$settings->set_gift_card_delivery_email_enabled( true );
		$mailer   = new GiftCardDeliveryMailer( $settings );

		$result = $mailer->deliver_batch(
			'not-an-email',
			100,
			array(
				array(
					'plain_code'   => 'TEST-CODE-1234',
					'amount'       => 10.0,
					'currency'     => 'EUR',
					'expires_at'   => null,
					'gift_card_id' => 1,
				),
			)
		);

		$this->assertFalse( $result['recipient_valid'] );
		$this->assertSame( GiftCardDeliveryStatus::FAILED, $result['results'][0]['delivery_status'] );
	}

	public function test_sanitize_strips_plain_code_from_storage_row(): void {
		$row = GiftCardGeneratedOrderState::sanitize_row_for_storage(
			array(
				'gift_card_id'  => 5,
				'order_item_id' => 1,
				'unit_index'    => 0,
				'plain_code'    => 'SECRET-CODE',
				'code_last4'    => '1234',
				'amount'        => 25.0,
				'currency'      => 'EUR',
				'status'        => 'active',
				'delivery_status' => GiftCardDeliveryStatus::SENT,
			)
		);
		$this->assertNotNull( $row );
		$this->assertArrayNotHasKey( 'plain_code', $row );
		$this->assertSame( '****1234', $row['masked_code'] );
	}

	public function test_row_contains_plain_code_helper(): void {
		$this->assertTrue(
			GiftCardGeneratedOrderState::row_contains_plain_code(
				array( 'plain_code' => 'SECRET' )
			)
		);
		$this->assertFalse(
			GiftCardGeneratedOrderState::row_contains_plain_code(
				array( 'masked_code' => '****1234' )
			)
		);
	}

	public function test_set_generated_strips_plain_code(): void {
		$order = new GiftCardDeliveryOrderStub();
		GiftCardGeneratedOrderState::set_generated(
			$order,
			array(
				array(
					'gift_card_id'  => 100,
					'order_item_id' => 2,
					'unit_index'    => 0,
					'plain_code'    => 'REMOVE-ME',
					'code_last4'    => '4242',
					'amount'        => 10.0,
					'currency'      => 'EUR',
					'status'        => 'active',
				),
			)
		);

		$raw = $order->meta[ GiftCardGeneratedOrderState::META_GENERATED ] ?? '';
		$this->assertStringNotContainsString( 'plain_code', $raw );
		$this->assertStringNotContainsString( 'REMOVE-ME', $raw );

		$rows = GiftCardGeneratedOrderState::get_generated( $order );
		$this->assertSame( '****4242', $rows[0]['masked_code'] );
	}
}

/**
 * @internal
 */
final class GiftCardDeliveryOrderStub extends WC_Order {

	private int $order_id = 0;

	private string $currency = 'EUR';

	private string $billing_email = '';

	/** @var array<int, GiftCardOrderLineStub> */
	private array $items = array();

	/** @var array<string, string> */
	public array $meta = array();

	public function set_id( int $id ): void {
		$this->order_id = $id;
	}

	public function get_id(): int {
		return $this->order_id;
	}

	public function set_currency( string $currency ): void {
		$this->currency = $currency;
	}

	public function get_currency(): string {
		return $this->currency;
	}

	public function set_billing_email( string $email ): void {
		$this->billing_email = $email;
	}

	public function get_billing_email(): string {
		return $this->billing_email;
	}

	public function get_customer_id(): int {
		return 0;
	}

	public function add_line( int $product_id, int $variation_id, int $qty, float $total, int $item_id ): void {
		$item = new GiftCardOrderLineStub();
		$item->set_id( $item_id );
		$item->set_product_id( $product_id );
		$item->set_variation_id( $variation_id );
		$item->set_quantity( $qty );
		$item->set_total( $total );
		$this->items[ $item_id ] = $item;
	}

	/**
	 * @return array<int, GiftCardOrderLineStub>
	 */
	public function get_items( $type = 'line_item' ): array {
		unset( $type );
		return $this->items;
	}

	public function get_meta( $key, $single = true ) {
		if ( ! isset( $this->meta[ (string) $key ] ) ) {
			return '';
		}

		return $this->meta[ (string) $key ];
	}

	public function update_meta_data( $key, $value ): void {
		$this->meta[ (string) $key ] = is_string( $value ) ? $value : (string) wp_json_encode( $value );
	}

	public function save(): int {
		return $this->order_id;
	}

	public function add_order_note( $note, $is_customer_note = false, $added_by_user = false ): int {
		unset( $note, $is_customer_note, $added_by_user );
		return 1;
	}
}
