<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use InvalidArgumentException;
use MP\CommercePromotions\GiftCard\GiftCardDeliveryStatus;
use MP\CommercePromotions\GiftCard\GiftCardGeneratedOrderState;
use MP\CommercePromotions\GiftCard\GiftCardLineItemMeta;
use MP\CommercePromotions\GiftCard\GiftCardOrderGenerator;
use MP\CommercePromotions\GiftCard\GiftCardOrderReversal;
use MP\CommercePromotions\GiftCard\GiftCardPendingDeliveryState;
use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use MP\CommercePromotions\GiftCard\GiftCardRecipientValidator;
use MP\CommercePromotions\GiftCard\GiftCardScheduledDeliveryService;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\GiftCard\GiftCardProductService;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Tests\Support\InMemoryGiftCardStore;
use MP\CommercePromotions\Tests\Support\MemoryGiftCardRepository;
use MP\CommercePromotions\Tests\Support\MemoryGiftCardTransactionRepository;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;

final class GiftCardScheduledRecipientTest extends TestCase {

	private GiftCardLedger $ledger;

	private GiftCardRepository $cards;

	protected function setUp(): void {
		global $mp_cp_test_post_meta, $mp_cp_test_wp_mail_result;
		$mp_cp_test_post_meta       = array();
		$mp_cp_test_wp_mail_result   = true;

		$this->store = new InMemoryGiftCardStore();
		$this->cards  = new MemoryGiftCardRepository( $this->store );
		$this->ledger = new GiftCardLedger(
			$this->cards,
			new MemoryGiftCardTransactionRepository( $this->store )
		);
	}

	private InMemoryGiftCardStore $store;

	public function test_recipient_email_required_for_recipient_mode(): void {
		$this->expectException( InvalidArgumentException::class );
		GiftCardRecipientValidator::validate_for_product(
			array(
				'sells'          => true,
				'amount_mode'    => GiftCardProductMeta::AMOUNT_MODE_FIXED,
				'fixed_amount'   => 10.0,
				'expiry_days'    => null,
				'recipient_mode' => GiftCardProductMeta::RECIPIENT_EMAIL,
			),
			array(
				'recipient_email'  => 'not-valid',
				'recipient_name'   => '',
				'message'          => '',
				'delivery_timing'  => GiftCardLineItemMeta::TIMING_SEND_NOW,
				'scheduled_for'    => '',
			)
		);
	}

	public function test_message_length_cap(): void {
		$this->expectException( InvalidArgumentException::class );
		GiftCardRecipientValidator::validate_for_product(
			array(
				'sells'          => true,
				'amount_mode'    => GiftCardProductMeta::AMOUNT_MODE_FIXED,
				'fixed_amount'   => 10.0,
				'expiry_days'    => null,
				'recipient_mode' => GiftCardProductMeta::RECIPIENT_EMAIL_AND_MESSAGE,
			),
			array(
				'recipient_email'  => 'friend@example.com',
				'recipient_name'   => '',
				'message'          => str_repeat( 'x', GiftCardRecipientValidator::max_message_length() + 1 ),
				'delivery_timing'  => GiftCardLineItemMeta::TIMING_SEND_NOW,
				'scheduled_for'    => '',
			)
		);
	}

	public function test_scheduled_date_validation_rejects_past(): void {
		$this->expectException( InvalidArgumentException::class );
		GiftCardRecipientValidator::validate_scheduled_date( '2000-01-01' );
	}

	public function test_send_now_generates_after_payment(): void {
		GiftCardProductMeta::save(
			9101,
			array(
				'sells'          => GiftCardProductMeta::VALUE_YES,
				'amount_mode'    => GiftCardProductMeta::AMOUNT_MODE_FIXED,
				'fixed_amount'   => '20',
				'expiry_days'    => '',
				'recipient_mode' => GiftCardProductMeta::RECIPIENT_EMAIL,
			)
		);

		$settings  = new Settings();
		$settings->set_gift_card_delivery_email_enabled( true );
		$generator = new GiftCardOrderGenerator( $this->ledger, new GiftCardProductService(), $settings );

		$order = new ScheduledOrderStub();
		$order->set_id( 91001 );
		$order->set_currency( 'EUR' );
		$order->set_billing_email( 'buyer@example.com' );
		$item = $order->add_line( 9101, 0, 1, 20.0, 1 );
		$item->set_delivery_meta(
			array(
				'recipient_email'  => 'recipient@example.com',
				'delivery_timing'  => GiftCardLineItemMeta::TIMING_SEND_NOW,
			)
		);

		$generator->generate_for_order( $order );

		$this->assertCount( 1, GiftCardGeneratedOrderState::get_generated( $order ) );
		$this->assertSame( array(), GiftCardPendingDeliveryState::get_pending( $order ) );
	}

	public function test_scheduled_does_not_generate_immediately(): void {
		GiftCardProductMeta::save(
			9102,
			array(
				'sells'          => GiftCardProductMeta::VALUE_YES,
				'amount_mode'    => GiftCardProductMeta::AMOUNT_MODE_FIXED,
				'fixed_amount'   => '30',
				'expiry_days'    => '',
				'recipient_mode' => GiftCardProductMeta::RECIPIENT_EMAIL,
			)
		);

		$generator = new GiftCardOrderGenerator( $this->ledger, new GiftCardProductService(), new Settings() );
		$order     = new ScheduledOrderStub();
		$order->set_id( 91002 );
		$order->set_currency( 'EUR' );
		$item = $order->add_line( 9102, 0, 1, 30.0, 1 );
		$today = gmdate( 'Y-m-d' );
		$item->set_delivery_meta(
			array(
				'recipient_email'  => 'postmaster@biopentra.eu',
				'delivery_timing'  => GiftCardLineItemMeta::TIMING_SEND_ON_DATE,
				'scheduled_for'    => $today,
			)
		);

		$generator->generate_for_order( $order );

		$this->assertSame( array(), GiftCardGeneratedOrderState::get_generated( $order ) );
		$pending = GiftCardPendingDeliveryState::get_pending( $order );
		$this->assertCount( 1, $pending );
		$this->assertSame( 'postmaster@biopentra.eu', $pending[0]['recipient_email'] );
		$raw = $order->meta[ GiftCardPendingDeliveryState::META_PENDING ] ?? '';
		$this->assertStringNotContainsString( 'plain_code', $raw );
	}

	public function test_cancelled_order_cancels_pending_scheduled(): void {
		GiftCardProductMeta::save(
			9104,
			array(
				'sells'          => GiftCardProductMeta::VALUE_YES,
				'amount_mode'    => GiftCardProductMeta::AMOUNT_MODE_FIXED,
				'fixed_amount'   => '15',
				'expiry_days'    => '',
				'recipient_mode' => GiftCardProductMeta::RECIPIENT_EMAIL,
			)
		);

		$generator = new GiftCardOrderGenerator( $this->ledger, new GiftCardProductService(), new Settings() );
		$reversal  = new GiftCardOrderReversal( $this->ledger, $this->cards );
		$order     = new ScheduledOrderStub();
		$order->set_id( 91004 );
		$order->set_currency( 'EUR' );
		$item = $order->add_line( 9104, 0, 1, 15.0, 1 );
		$item->set_delivery_meta(
			array(
				'recipient_email'  => 'cancel@example.com',
				'delivery_timing'  => GiftCardLineItemMeta::TIMING_SEND_ON_DATE,
				'scheduled_for'    => gmdate( 'Y-m-d' ),
			)
		);

		$generator->generate_for_order( $order );
		$this->assertCount( 1, GiftCardPendingDeliveryState::get_pending( $order ) );

		$reversal->handle_order_reversal( $order );
		$pending = GiftCardPendingDeliveryState::get_pending( $order );
		$this->assertCount( 1, $pending );
		$this->assertSame( GiftCardDeliveryStatus::CANCELLED, $pending[0]['delivery_status'] );
		$this->assertFalse( GiftCardPendingDeliveryState::is_due( $pending[0] ) );
	}

	public function test_order_meta_stores_recipient_without_plain_code(): void {
		GiftCardProductMeta::save(
			9105,
			array(
				'sells'          => GiftCardProductMeta::VALUE_YES,
				'amount_mode'    => GiftCardProductMeta::AMOUNT_MODE_FIXED,
				'fixed_amount'   => '25',
				'expiry_days'    => '',
				'recipient_mode' => GiftCardProductMeta::RECIPIENT_EMAIL_AND_MESSAGE,
			)
		);

		$generator = new GiftCardOrderGenerator( $this->ledger, new GiftCardProductService(), new Settings() );
		$order     = new ScheduledOrderStub();
		$order->set_id( 91005 );
		$order->set_currency( 'EUR' );
		$item = $order->add_line( 9105, 0, 1, 25.0, 1 );
		$item->set_delivery_meta(
			array(
				'recipient_email'  => 'friend@example.com',
				'message'          => 'Enjoy!',
				'delivery_timing'  => GiftCardLineItemMeta::TIMING_SEND_ON_DATE,
				'scheduled_for'    => gmdate( 'Y-m-d' ),
			)
		);

		$generator->generate_for_order( $order );
		$raw = $order->meta[ GiftCardPendingDeliveryState::META_PENDING ] ?? '';
		$this->assertStringContainsString( 'friend@example.com', $raw );
		$this->assertStringContainsString( 'Enjoy!', $raw );
		$this->assertStringNotContainsString( 'plain_code', $raw );
	}

	public function test_scheduled_runner_generates_once_when_due(): void {
		GiftCardProductMeta::save(
			9103,
			array(
				'sells'          => GiftCardProductMeta::VALUE_YES,
				'amount_mode'    => GiftCardProductMeta::AMOUNT_MODE_FIXED,
				'fixed_amount'   => '40',
				'expiry_days'    => '',
				'recipient_mode' => GiftCardProductMeta::RECIPIENT_EMAIL,
			)
		);

		$settings  = new Settings();
		$settings->set_gift_card_delivery_email_enabled( true );
		$generator = new GiftCardOrderGenerator( $this->ledger, new GiftCardProductService(), $settings );
		$scheduler = new GiftCardScheduledDeliveryService( $this->ledger, new GiftCardProductService(), $settings );

		$order = new ScheduledOrderStub();
		$order->set_id( 91003 );
		$order->set_currency( 'EUR' );
		$item = $order->add_line( 9103, 0, 1, 40.0, 1 );
		$item->set_delivery_meta(
			array(
				'recipient_email'  => 'scheduled@example.com',
				'delivery_timing'  => GiftCardLineItemMeta::TIMING_SEND_ON_DATE,
				'scheduled_for'    => gmdate( 'Y-m-d' ),
			)
		);

		$generator->generate_for_order( $order );
		$result = $scheduler->fulfill_order_pending( $order, true );
		$this->assertSame( 1, $result['fulfilled'] );
		$this->assertCount( 1, GiftCardGeneratedOrderState::get_generated( $order ) );

		$result2 = $scheduler->fulfill_order_pending( $order, true );
		$this->assertSame( 0, $result2['fulfilled'] );
		$this->assertCount( 1, GiftCardGeneratedOrderState::get_generated( $order ) );
	}
}

/**
 * @internal
 */
final class ScheduledOrderStub extends WC_Order {

	private int $order_id = 0;

	private string $currency = 'EUR';

	private string $billing_email = '';

	/** @var array<int, ScheduledOrderLineStub> */
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

	public function add_line( int $product_id, int $variation_id, int $qty, float $total, int $item_id ): ScheduledOrderLineStub {
		$item = new ScheduledOrderLineStub();
		$item->set_id( $item_id );
		$item->set_product_id( $product_id );
		$item->set_variation_id( $variation_id );
		$item->set_quantity( $qty );
		$item->set_total( $total );
		$this->items[ $item_id ] = $item;
		return $item;
	}

	/**
	 * @return array<int, ScheduledOrderLineStub>
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

/**
 * @internal
 */
final class ScheduledOrderLineStub extends WC_Order_Item_Product {

	private int $item_id = 0;

	private int $product_id = 0;

	private int $variation_id = 0;

	private int $quantity = 1;

	private float $total = 0.0;

	/** @var array<string, string> */
	private array $item_meta = array();

	public function set_id( int $id ): void {
		$this->item_id = $id;
	}

	public function get_id(): int {
		return $this->item_id;
	}

	public function set_product_id( int $product_id ): void {
		$this->product_id = $product_id;
	}

	public function get_product_id(): int {
		return $this->product_id;
	}

	public function set_variation_id( int $variation_id ): void {
		$this->variation_id = $variation_id;
	}

	public function get_variation_id(): int {
		return $this->variation_id;
	}

	public function set_quantity( int $quantity ): void {
		$this->quantity = $quantity;
	}

	public function get_quantity(): int {
		return $this->quantity;
	}

	public function set_total( float $total ): void {
		$this->total = $total;
	}

	public function get_total(): float {
		return $this->total;
	}

	/**
	 * @param array<string, string> $delivery
	 */
	public function set_delivery_meta( array $delivery ): void {
		$normalized = GiftCardLineItemMeta::normalize_array( $delivery );
		$this->item_meta[ GiftCardLineItemMeta::KEY_RECIPIENT_EMAIL ]  = $normalized['recipient_email'];
		$this->item_meta[ GiftCardLineItemMeta::KEY_RECIPIENT_NAME ]   = $normalized['recipient_name'];
		$this->item_meta[ GiftCardLineItemMeta::KEY_MESSAGE ]          = $normalized['message'];
		$this->item_meta[ GiftCardLineItemMeta::KEY_DELIVERY_TIMING ]  = $normalized['delivery_timing'];
		$this->item_meta[ GiftCardLineItemMeta::KEY_SCHEDULED_FOR ]    = $normalized['scheduled_for'];
	}

	public function get_meta( $key, $single = true ) {
		if ( ! isset( $this->item_meta[ (string) $key ] ) ) {
			return '';
		}
		return $this->item_meta[ (string) $key ];
	}
}
