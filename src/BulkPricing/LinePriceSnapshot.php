<?php
/**
 * Immutable per-line catalog base snapshot for one totals cycle.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\BulkPricing;

final class LinePriceSnapshot {

	public function __construct(
		private int $product_id,
		private int $display_unit_minor,
		private int $base_unit_minor,
		private string $display_currency,
		private string $base_currency,
		private string $price_source,
		private int $decimals,
		private string $catalog_price_hash
	) {
	}

	public function get_product_id(): int {
		return $this->product_id;
	}

	public function get_display_unit_minor(): int {
		return $this->display_unit_minor;
	}

	public function get_base_unit_minor(): int {
		return $this->base_unit_minor;
	}

	public function get_display_currency(): string {
		return $this->display_currency;
	}

	public function get_base_currency(): string {
		return $this->base_currency;
	}

	public function get_price_source(): string {
		return $this->price_source;
	}

	public function get_decimals(): int {
		return $this->decimals;
	}

	public function get_catalog_price_hash(): string {
		return $this->catalog_price_hash;
	}

	/**
	 * @return array<string, int|string>
	 */
	public function to_array(): array {
		return array(
			'product_id'           => $this->product_id,
			'display_unit_minor'   => $this->display_unit_minor,
			'base_unit_minor'      => $this->base_unit_minor,
			'display_currency'     => $this->display_currency,
			'base_currency'        => $this->base_currency,
			'price_source'         => $this->price_source,
			'decimals'             => $this->decimals,
			'catalog_price_hash'   => $this->catalog_price_hash,
		);
	}
}
