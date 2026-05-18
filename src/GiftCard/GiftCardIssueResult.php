<?php
/**
 * Result of issuing a gift card (plain code returned once).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\GiftCard;

final class GiftCardIssueResult {

	private string $plain_code;

	private GiftCard $card;

	public function __construct( string $plain_code, GiftCard $card ) {
		$this->plain_code = $plain_code;
		$this->card       = $card;
	}

	public function get_plain_code(): string {
		return $this->plain_code;
	}

	public function get_card(): GiftCard {
		return $this->card;
	}
}
