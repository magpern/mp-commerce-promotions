<?php
/**
 * Records merchant CSV export timestamps for pilot backup hygiene.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

final class GiftCardExportTracker {

	public const OPTION_TIMESTAMPS = 'mp_cp_gift_card_export_timestamps';

	public const TYPE_GIFT_CARDS = 'gift_cards';

	public const TYPE_TRANSACTIONS = 'transactions';

	public const TYPE_LIABILITY = 'liability';

	/** @var int Days without export before diagnostics warn when liability exists. */
	public const STALE_EXPORT_DAYS = 30;

	public static function record_export( string $export_type ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		$timestamps = self::get_timestamps();
		$timestamps[ $export_type ] = gmdate( 'Y-m-d H:i:s' );
		update_option( self::OPTION_TIMESTAMPS, $timestamps, false );
	}

	/**
	 * @return array<string, string>
	 */
	public static function get_timestamps(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}

		$raw = get_option( self::OPTION_TIMESTAMPS, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $key => $value ) {
			if ( ! is_string( $key ) || ! is_string( $value ) || $value === '' ) {
				continue;
			}
			$out[ $key ] = $value;
		}

		return $out;
	}

	public static function last_export_at(): ?string {
		$timestamps = self::get_timestamps();
		if ( $timestamps === array() ) {
			return null;
		}

		$latest = null;
		foreach ( $timestamps as $value ) {
			if ( $latest === null || strcmp( $value, $latest ) > 0 ) {
				$latest = $value;
			}
		}

		return $latest;
	}

	public static function is_export_stale( ?int $max_age_days = null ): bool {
		$last = self::last_export_at();
		if ( $last === null ) {
			return true;
		}

		$days = $max_age_days ?? self::STALE_EXPORT_DAYS;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		return strcmp( $last, $cutoff ) < 0;
	}
}
