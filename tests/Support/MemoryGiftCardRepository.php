<?php
/**
 * In-memory GiftCardRepository for unit tests.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Support;

use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardRepository;

/**
 * @internal
 */
final class MemoryGiftCardRepository extends GiftCardRepository {

	/** @var InMemoryGiftCardStore */
	private $store;

	public function __construct( InMemoryGiftCardStore $store ) {
		$this->store = $store;
		parent::__construct( new \wpdb() );
	}

	public function insert( GiftCard $card ): int {
		return $this->store->insert_card( $card );
	}

	public function update( GiftCard $card ): bool {
		return $this->store->update_card( $card );
	}

	public function find( int $id ): ?GiftCard {
		return $this->store->find_card( $id );
	}

	public function find_by_plain_code( string $plain_code ): ?GiftCard {
		return $this->store->find_by_hash( self::hash_plain_code( $plain_code ) );
	}

	public function find_store_credit_wallet( int $customer_id, string $currency ): ?GiftCard {
		$currency = strtoupper( trim( $currency ) );
		foreach ( $this->store->all_cards() as $card ) {
			if (
				$card->get_source_type() === GiftCard::SOURCE_STORE_CREDIT
				&& $card->get_owner_customer_id() === $customer_id
				&& $card->get_currency() === $currency
			) {
				return $card;
			}
		}

		return null;
	}
}
