<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardGeneratedOrderState;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardOrderGenerator;
use MP\CommercePromotions\GiftCard\GiftCardOrderReversal;
use MP\CommercePromotions\GiftCard\GiftCardProductCustomerAmount;
use MP\CommercePromotions\GiftCard\GiftCardProductMeta;
use MP\CommercePromotions\GiftCard\GiftCardProductService;
use MP\CommercePromotions\GiftCard\GiftCardRepository;
use MP\CommercePromotions\Tests\Support\InMemoryGiftCardStore;
use MP\CommercePromotions\Tests\Support\MemoryGiftCardRepository;
use MP\CommercePromotions\Tests\Support\MemoryGiftCardTransactionRepository;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;

final class GiftCardOrderGeneratorTest extends TestCase {

	private InMemoryGiftCardStore $store;

	private GiftCardLedger $ledger;

	private GiftCardRepository $cards;

	private GiftCardOrderGenerator $generator;

	private GiftCardOrderReversal $reversal;

	protected function setUp(): void {
		global $mp_cp_test_post_meta;
		$mp_cp_test_post_meta = array();

		$this->store    = new InMemoryGiftCardStore();
		$this->cards    = new MemoryGiftCardRepository( $this->store );
		$this->ledger   = new GiftCardLedger(
			$this->cards,
			new MemoryGiftCardTransactionRepository( $this->store )
		);
		$this->generator = new GiftCardOrderGenerator( $this->ledger, new GiftCardProductService() );
		$this->reversal  = new GiftCardOrderReversal( $this->ledger, $this->cards );
	}

	public function test_quantity_generates_multiple_cards(): void {
		$product_id = 9001;
		GiftCardProductMeta::save(
			$product_id,
			array(
				'sells'        => GiftCardProductMeta::VALUE_YES,
				'amount_mode'  => GiftCardProductMeta::AMOUNT_MODE_PRODUCT_PRICE,
				'fixed_amount' => '',
				'expiry_days'  => '',
			)
		);

		$order = $this->make_order(
			array(
				9001 => array(
					'product_id'   => $product_id,
					'variation_id' => 0,
					'qty'          => 3,
					'total'        => 90.0,
				),
			)
		);

		$generated = $this->generator->generate_for_order( $order );
		$this->assertCount( 3, $generated );
		$this->assertTrue( GiftCardGeneratedOrderState::is_generation_complete( $order ) );
	}

	public function test_idempotent_generation(): void {
		$product_id = 9002;
		GiftCardProductMeta::save(
			$product_id,
			array(
				'sells'        => GiftCardProductMeta::VALUE_YES,
				'amount_mode'  => GiftCardProductMeta::AMOUNT_MODE_FIXED,
				'fixed_amount' => '40',
				'expiry_days'  => '',
			)
		);

		$order = $this->make_order(
			array(
				9002 => array(
					'product_id'   => $product_id,
					'variation_id' => 0,
					'qty'          => 1,
					'total'        => 40.0,
				),
			)
		);

		$first = $this->generator->generate_for_order( $order );
		$second = $this->generator->generate_for_order( $order );
		$this->assertCount( 1, $first );
		$this->assertCount( 1, $second );
		$this->assertSame( $first[0]['gift_card_id'], $second[0]['gift_card_id'] );
	}

	public function test_generated_card_stores_order_and_amount(): void {
		$product_id = 9003;
		GiftCardProductMeta::save(
			$product_id,
			array(
				'sells'        => GiftCardProductMeta::VALUE_YES,
				'amount_mode'  => GiftCardProductMeta::AMOUNT_MODE_FIXED,
				'fixed_amount' => '55',
				'expiry_days'  => '',
			)
		);

		$order = $this->make_order(
			array(
				9003 => array(
					'product_id'   => $product_id,
					'variation_id' => 0,
					'qty'          => 1,
					'total'        => 55.0,
				),
			),
			42,
			'customer@example.com'
		);

		$generated = $this->generator->generate_for_order( $order );
		$card        = $this->cards->find( (int) $generated[0]['gift_card_id'] );
		$this->assertNotNull( $card );
		$this->assertSame( 55.0, $card->get_initial_amount() );
		$this->assertSame( 'EUR', $card->get_currency() );
		$this->assertSame( 88001, $card->get_created_order_id() );
		$this->assertSame( 42, $card->get_purchaser_customer_id() );
		$this->assertSame( 'customer@example.com', $card->get_recipient_email() );
	}

	public function test_customer_amount_from_order_item_meta(): void {
		$product_id = 9010;
		GiftCardProductMeta::save(
			$product_id,
			array(
				'sells'             => GiftCardProductMeta::VALUE_YES,
				'amount_mode'       => GiftCardProductMeta::AMOUNT_MODE_CUSTOMER_AMOUNT,
				'min_amount'        => '10',
				'max_amount'        => '500',
				'suggested_amounts' => '25,50,100',
				'fixed_amount'      => '',
				'expiry_days'       => '',
			)
		);

		$order = $this->make_order(
			array(
				9010 => array(
					'product_id'   => $product_id,
					'variation_id' => 0,
					'qty'          => 2,
					'total'        => 100.0,
					'unit_amount'  => 50.0,
				),
			),
			0,
			'buyer@example.com'
		);

		$generated = $this->generator->generate_for_order( $order );
		$this->assertCount( 2, $generated );
		foreach ( $generated as $row ) {
			$card = $this->cards->find( (int) $row['gift_card_id'] );
			$this->assertNotNull( $card );
			$this->assertSame( 50.0, $card->get_initial_amount() );
		}
	}

	public function test_cancelled_unused_card_voided(): void {
		$product_id = 9004;
		GiftCardProductMeta::save(
			$product_id,
			array(
				'sells'        => GiftCardProductMeta::VALUE_YES,
				'amount_mode'  => GiftCardProductMeta::AMOUNT_MODE_FIXED,
				'fixed_amount' => '20',
				'expiry_days'  => '',
			)
		);

		$order = $this->make_order(
			array(
				9004 => array(
					'product_id'   => $product_id,
					'variation_id' => 0,
					'qty'          => 1,
					'total'        => 20.0,
				),
			)
		);

		$generated = $this->generator->generate_for_order( $order );
		$card_id   = (int) $generated[0]['gift_card_id'];

		$this->reversal->handle_order_reversal( $order );

		$card = $this->cards->find( $card_id );
		$this->assertNotNull( $card );
		$this->assertSame( GiftCard::STATUS_VOIDED, $card->get_status() );
	}

	public function test_partially_used_card_not_auto_voided(): void {
		$product_id = 9005;
		GiftCardProductMeta::save(
			$product_id,
			array(
				'sells'        => GiftCardProductMeta::VALUE_YES,
				'amount_mode'  => GiftCardProductMeta::AMOUNT_MODE_FIXED,
				'fixed_amount' => '100',
				'expiry_days'  => '',
			)
		);

		$order = $this->make_order(
			array(
				9005 => array(
					'product_id'   => $product_id,
					'variation_id' => 0,
					'qty'          => 1,
					'total'        => 100.0,
				),
			)
		);

		$generated = $this->generator->generate_for_order( $order );
		$card_id   = (int) $generated[0]['gift_card_id'];
		$this->ledger->redeem( $card_id, 25.0, 5001 );

		$this->reversal->handle_order_reversal( $order );

		$card = $this->cards->find( $card_id );
		$this->assertNotNull( $card );
		$this->assertSame( GiftCard::STATUS_ACTIVE, $card->get_status() );
		$this->assertSame( 75.0, $card->get_balance() );
	}

	/**
	 * @param array<int, array{product_id: int, variation_id: int, qty: int, total: float, unit_amount?: float}> $lines
	 */
	private function make_order( array $lines, int $customer_id = 0, string $billing_email = '' ): GiftCardOrderTestStub {
		$order = new GiftCardOrderTestStub();
		$order->set_id( 88001 );
		$order->set_currency( 'EUR' );
		$order->set_customer_id( $customer_id );
		$order->set_billing_email( $billing_email );

		foreach ( $lines as $item_id => $line ) {
			$item = new GiftCardOrderLineStub();
			$item->set_id( (int) $item_id );
			$item->set_product_id( $line['product_id'] );
			$item->set_variation_id( $line['variation_id'] );
			$item->set_quantity( $line['qty'] );
			$item->set_total( $line['total'] );
			if ( isset( $line['unit_amount'] ) ) {
				GiftCardProductCustomerAmount::write_amount_to_order_item( $item, (float) $line['unit_amount'] );
			}
			$order->add_item( $item );
		}

		return $order;
	}
}

/**
 * @internal
 */
final class GiftCardOrderTestStub extends WC_Order {

	private int $order_id = 0;

	private string $currency = 'EUR';

	private int $customer_id = 0;

	private string $billing_email = '';

	/** @var array<int, GiftCardOrderLineStub> */
	private array $items = array();

	/** @var array<string, string> */
	public array $meta = array();

	/** @var list<string> */
	public array $notes = array();

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

	public function set_customer_id( int $customer_id ): void {
		$this->customer_id = $customer_id;
	}

	public function get_customer_id(): int {
		return $this->customer_id;
	}

	public function set_billing_email( string $email ): void {
		$this->billing_email = $email;
	}

	public function get_billing_email(): string {
		return $this->billing_email;
	}

	public function add_item( GiftCardOrderLineStub $item ): void {
		$this->items[ $item->get_id() ] = $item;
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
		unset( $is_customer_note, $added_by_user );
		$this->notes[] = (string) $note;
		return count( $this->notes );
	}
}

/**
 * @internal
 */
final class GiftCardOrderLineStub extends WC_Order_Item_Product {

	private int $item_id = 0;

	private int $product_id = 0;

	private int $variation_id = 0;

	private int $quantity = 1;

	private float $total = 0.0;

	/** @var array<string, mixed> */
	private array $meta = array();

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
	 * @param string $key
	 * @param bool $single
	 * @return mixed
	 */
	public function get_meta( $key, $single = true ) {
		unset( $single );
		return $this->meta[ (string) $key ] ?? '';
	}

	/**
	 * @param string $key
	 * @param mixed $value
	 */
	public function update_meta_data( $key, $value ): void {
		$this->meta[ (string) $key ] = $value;
	}
}
