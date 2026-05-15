<?php
/**
 * WooCommerce integration boundary (detection only; no discount hooks yet).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

final class WooCommerceBridge {

	private bool $available = false;

	public function init(): void {
		$this->available = class_exists( \WooCommerce::class, false )
			&& function_exists( 'WC' );
	}

	public function is_available(): bool {
		return $this->available;
	}
}
