<?php
/**
 * Customer store credit wallet lookup and creation.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use InvalidArgumentException;
use RuntimeException;

final class StoreCreditAccountService {

	private GiftCardRepository $cards;

	public function __construct( GiftCardRepository $cards ) {
		$this->cards = $cards;
	}

	/**
	 * Resolve a WooCommerce customer ID from numeric ID, email, or login.
	 */
	public function resolve_customer_id( string $query ): ?int {
		$query = trim( $query );
		if ( $query === '' ) {
			return null;
		}

		if ( ctype_digit( $query ) ) {
			$id = (int) $query;
			if ( $id > 0 && $this->customer_exists( $id ) ) {
				return $id;
			}

			return null;
		}

		if ( is_email( $query ) ) {
			$user = get_user_by( 'email', $query );
			if ( $user instanceof \WP_User ) {
				return (int) $user->ID;
			}

			return null;
		}

		$user = get_user_by( 'login', $query );
		if ( $user instanceof \WP_User ) {
			return (int) $user->ID;
		}

		return null;
	}

	public function find_wallet( int $customer_id, string $currency ): ?GiftCard {
		if ( $customer_id <= 0 ) {
			return null;
		}

		return $this->cards->find_store_credit_wallet( $customer_id, $currency );
	}

	/**
	 * @throws InvalidArgumentException
	 * @throws RuntimeException
	 */
	public function find_or_create_wallet( int $customer_id, string $currency ): GiftCard {
		if ( $customer_id <= 0 ) {
			throw new InvalidArgumentException( 'Customer ID is required for store credit.' );
		}

		$currency = GiftCardCurrency::validate( $currency );

		$existing = $this->cards->find_store_credit_wallet( $customer_id, $currency );
		if ( $existing !== null ) {
			return $existing;
		}

		$hash = GiftCard::store_credit_wallet_hash( $customer_id, $currency );
		$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'sc-', true );

		$card = new GiftCard(
			null,
			$uuid,
			$hash,
			GiftCard::WALLET_CODE_LAST4,
			0.0,
			0.0,
			$currency,
			GiftCard::STATUS_ACTIVE,
			null,
			null,
			null,
			null,
			null,
			null,
			GiftCard::SOURCE_STORE_CREDIT,
			$customer_id,
			sprintf( 'Store credit wallet (customer %d)', $customer_id )
		);

		$card_id = $this->cards->insert( $card );
		if ( $card_id <= 0 ) {
			throw new RuntimeException( 'Failed to create store credit wallet.' );
		}

		$stored = $this->cards->find( $card_id );
		if ( $stored === null ) {
			throw new RuntimeException( 'Failed to load store credit wallet after creation.' );
		}

		return $stored;
	}

	private function customer_exists( int $customer_id ): bool {
		if ( function_exists( 'wc_get_customer' ) ) {
			$customer = wc_get_customer( $customer_id );

			return $customer !== false && $customer !== null;
		}

		return get_user_by( 'id', $customer_id ) instanceof \WP_User;
	}
}
