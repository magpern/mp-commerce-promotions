<?php
/**
 * Logged-in customer store credit checkout preview and session (no ledger writes).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

use MP\CommercePromotions\Woo\StoreCreditSession;

final class StoreCreditCheckoutService {

	private StoreCreditAccountService $accounts;

	private GiftCardRedemptionService $redemption;

	public function __construct( StoreCreditAccountService $accounts, GiftCardLedger $ledger ) {
		$this->accounts   = $accounts;
		$this->redemption = new GiftCardRedemptionService( $ledger );
	}

	public function can_apply(): bool {
		return function_exists( 'is_user_logged_in' ) && is_user_logged_in();
	}

	public function get_current_customer_id(): int {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return 0;
		}

		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	public function get_wallet_for_customer( int $customer_id, string $currency ): ?GiftCard {
		if ( $customer_id <= 0 ) {
			return null;
		}

		return $this->accounts->find_wallet( $customer_id, $currency );
	}

	public function get_available_balance( int $customer_id, string $currency ): float {
		$wallet = $this->get_wallet_for_customer( $customer_id, $currency );

		return $wallet !== null ? GiftCard::money( $wallet->get_balance() ) : 0.0;
	}

	public function preview_apply_amount( int $customer_id, string $currency, float $cart_payable ): float {
		if ( $customer_id <= 0 ) {
			return 0.0;
		}

		$wallet = $this->get_wallet_for_customer( $customer_id, $currency );
		if ( $wallet === null || ! $this->redemption->is_redeemable( $wallet ) ) {
			return 0.0;
		}

		return $this->redemption->preview_apply_amount( $wallet, $cart_payable );
	}

	/**
	 * @return array{account_id: int, applied_amount: float, currency: string}|null
	 */
	public function build_session_payload( int $customer_id, string $currency, float $cart_payable ): ?array {
		$amount = $this->preview_apply_amount( $customer_id, $currency, $cart_payable );
		if ( $amount <= 0 ) {
			return null;
		}

		$wallet = $this->get_wallet_for_customer( $customer_id, $currency );
		$id     = $wallet !== null ? $wallet->get_id() : null;
		if ( $id === null || $id <= 0 ) {
			return null;
		}

		return array(
			'account_id'     => $id,
			'applied_amount' => $amount,
			'currency'       => strtoupper( trim( $currency ) ),
		);
	}

	public function apply_to_session( int $customer_id, string $currency, float $cart_payable ): bool {
		$payload = $this->build_session_payload( $customer_id, $currency, $cart_payable );
		if ( $payload === null ) {
			return false;
		}

		StoreCreditSession::set( $payload );

		return true;
	}

	public function remove_from_session(): void {
		StoreCreditSession::clear();
	}

	/**
	 * @return array{account_id: int, applied_amount: float, currency: string}|null
	 */
	public function get_applied_from_session(): ?array {
		return StoreCreditSession::get();
	}
}
