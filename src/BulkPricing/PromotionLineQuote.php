<?php
/**
 * Promotion line quote (display minor units) without set_price.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\BulkPricing;

final class PromotionLineQuote {

	public function __construct(
		private int $line_total_minor,
		private int $promotion_id,
		private string $action_type
	) {
	}

	public function get_line_total_minor(): int {
		return $this->line_total_minor;
	}

	public function get_promotion_id(): int {
		return $this->promotion_id;
	}

	public function get_action_type(): string {
		return $this->action_type;
	}
}
