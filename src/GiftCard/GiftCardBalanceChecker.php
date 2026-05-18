<?php
/**
 * Public gift card balance lookup (no full code persistence or logging).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

final class GiftCardBalanceChecker {

	private const RATE_LIMIT_MAX = 10;

	private const RATE_LIMIT_WINDOW = 900;

	private GiftCardLedger $ledger;

	public function __construct( GiftCardLedger $ledger ) {
		$this->ledger = $ledger;
	}

	/**
	 * @return array{ok: bool, error?: string, balance?: float, currency?: string, status?: string, expires_at?: ?string, masked_code?: string}
	 */
	public function lookup( string $plain_code ): array {
		if ( ! $this->check_rate_limit() ) {
			return array(
				'ok'    => false,
				'error' => __( 'Too many attempts. Please wait a few minutes and try again.', 'mp-commerce-promotions' ),
			);
		}

		$this->record_attempt();

		$plain = trim( $plain_code );
		if ( $plain === '' ) {
			return array(
				'ok'    => false,
				'error' => __( 'Enter a gift card code.', 'mp-commerce-promotions' ),
			);
		}

		$card = $this->ledger->find_by_plain_code( $plain );
		if ( $card === null || $card->is_store_credit_wallet() ) {
			return array(
				'ok'    => false,
				'error' => __( 'Gift card not found or not valid.', 'mp-commerce-promotions' ),
			);
		}

		return array(
			'ok'           => true,
			'balance'      => GiftCard::money( $card->get_balance() ),
			'currency'     => $card->get_currency(),
			'status'       => $this->public_status_label( $card ),
			'expires_at'   => $card->get_expires_at(),
			'masked_code'  => '****' . $card->get_code_last4(),
		);
	}

	public function public_status_label( GiftCard $card ): string {
		$status = $card->get_status();
		switch ( $status ) {
			case GiftCard::STATUS_ACTIVE:
				return $card->can_redeem( 0.01, current_time( 'mysql' ) )
					? __( 'Active', 'mp-commerce-promotions' )
					: __( 'Not redeemable', 'mp-commerce-promotions' );
			case GiftCard::STATUS_DEPLETED:
				return __( 'Fully used', 'mp-commerce-promotions' );
			case GiftCard::STATUS_EXPIRED:
				return __( 'Expired', 'mp-commerce-promotions' );
			case GiftCard::STATUS_VOIDED:
				return __( 'Voided', 'mp-commerce-promotions' );
			default:
				return ucfirst( $status );
		}
	}

	private function check_rate_limit(): bool {
		if ( ! function_exists( 'get_transient' ) ) {
			return true;
		}

		$key = $this->rate_limit_key();
		$count = (int) get_transient( $key );

		return $count < self::RATE_LIMIT_MAX;
	}

	private function record_attempt(): void {
		if ( ! function_exists( 'set_transient' ) || ! function_exists( 'get_transient' ) ) {
			return;
		}

		$key   = $this->rate_limit_key();
		$count = (int) get_transient( $key );
		++$count;
		set_transient( $key, $count, self::RATE_LIMIT_WINDOW );
	}

	private function rate_limit_key(): string {
		$ip = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
		}

		return 'mp_cp_gc_balance_' . md5( $ip !== '' ? $ip : 'unknown' );
	}
}
