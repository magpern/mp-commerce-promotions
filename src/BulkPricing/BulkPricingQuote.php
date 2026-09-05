<?php
/**
 * Bulk pricing quote for one cart line (display minor units).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\BulkPricing;

final class BulkPricingQuote {

	public function __construct(
		private int $tier_min_quantity,
		private int $discount_percentage,
		private int $unit_minor,
		private int $line_total_minor,
		private int $standard_line_total_minor
	) {
	}

	public function get_tier_min_quantity(): int {
		return $this->tier_min_quantity;
	}

	public function get_discount_percentage(): int {
		return $this->discount_percentage;
	}

	public function get_unit_minor(): int {
		return $this->unit_minor;
	}

	public function get_line_total_minor(): int {
		return $this->line_total_minor;
	}

	public function get_standard_line_total_minor(): int {
		return $this->standard_line_total_minor;
	}
}
