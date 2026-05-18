<?php
/**
 * In-memory GiftCardTransactionRepository for unit tests.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Support;

use MP\CommercePromotions\GiftCard\GiftCardTransaction;
use MP\CommercePromotions\GiftCard\GiftCardTransactionRepository;

/**
 * @internal
 */
final class MemoryGiftCardTransactionRepository extends GiftCardTransactionRepository {

	/** @var InMemoryGiftCardStore */
	private $store;

	public function __construct( InMemoryGiftCardStore $store ) {
		$this->store = $store;
		parent::__construct( new \wpdb() );
	}

	public function insert( GiftCardTransaction $transaction ): int {
		return $this->store->insert_tx( $transaction );
	}

	public function list_for_card( int $gift_card_id, int $limit = 100 ): array {
		unset( $limit );
		return $this->store->list_tx( $gift_card_id );
	}

	public function has_redeemed_for_order( int $gift_card_id, int $order_id ): bool {
		return $this->store->has_redeemed_for_order( $gift_card_id, $order_id );
	}
}
