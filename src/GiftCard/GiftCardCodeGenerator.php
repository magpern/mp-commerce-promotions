<?php
/**
 * Generates one-time plain gift card codes (not stored after issue).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

final class GiftCardCodeGenerator {

	private const CHARSET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	/**
	 * Format: GC-XXXX-XXXX-XXXX-XXXX (19 chars + separators).
	 */
	public function generate_plain_code(): string {
		$segments = array( 'GC' );
		for ( $i = 0; $i < 4; $i++ ) {
			$segments[] = $this->random_segment( 4 );
		}

		return implode( '-', $segments );
	}

	public function last4_from_plain_code( string $plain_code ): string {
		$normalized = strtoupper( preg_replace( '/[^A-Z0-9]/', '', $plain_code ) ?? '' );
		if ( strlen( $normalized ) < 4 ) {
			return substr( str_pad( $normalized, 4, '0' ), -4 );
		}

		return substr( $normalized, -4 );
	}

	private function random_segment( int $length ): string {
		$max   = strlen( self::CHARSET ) - 1;
		$chunk = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$chunk .= self::CHARSET[ random_int( 0, $max ) ];
		}

		return $chunk;
	}
}
