<?php
/**
 * Bulk bracket quote from immutable line snapshots.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\BulkPricing;

final class BulkPricingCalculator {

	public function quote_line(
		LinePriceSnapshot $snapshot,
		BulkPricingConfig $config,
		int $quantity
	): ?BulkPricingQuote {
		if ( ! $config->has_valid_tiers() || $quantity <= 0 ) {
			return null;
		}

		$bracket = $config->resolve_bracket_for_quantity( $quantity );
		if ( $bracket === null ) {
			return null;
		}

		$unit_minor = BulkPricingMoney::apply_percentage_minor(
			$snapshot->get_display_unit_minor(),
			(int) $bracket['discount_percentage']
		);

		$line_minor = BulkPricingMoney::line_total_minor( $unit_minor, $quantity );
		$standard   = BulkPricingMoney::line_total_minor( $snapshot->get_display_unit_minor(), $quantity );

		return new BulkPricingQuote(
			(int) $bracket['min_quantity'],
			(int) $bracket['discount_percentage'],
			$unit_minor,
			$line_minor,
			$standard
		);
	}
}
