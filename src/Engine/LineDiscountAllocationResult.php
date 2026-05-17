<?php
/**
 * Aggregated line-level allocation outcome for a cart calculation cycle.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

final class LineDiscountAllocationResult {

	/** @var list<AppliedLineDiscount> */
	private array $line_discounts;

	private float $total_allocated;

	/** @var list<array<string, mixed>> */
	private array $fallback_events;

	/** @var array<string, mixed> */
	private array $meta;

	/**
	 * @param list<AppliedLineDiscount>        $line_discounts
	 * @param list<array<string, mixed>>       $fallback_events
	 * @param array<string, mixed>             $meta
	 */
	public function __construct(
		array $line_discounts,
		float $total_allocated,
		array $fallback_events = array(),
		array $meta = array()
	) {
		$this->line_discounts   = $line_discounts;
		$this->total_allocated = max( 0.0, round( $total_allocated, 4 ) );
		$this->fallback_events = $fallback_events;
		$this->meta            = $meta;
	}

	/** @return list<AppliedLineDiscount> */
	public function get_line_discounts(): array {
		return $this->line_discounts;
	}

	public function get_total_allocated(): float {
		return $this->total_allocated;
	}

	/** @return list<array<string, mixed>> */
	public function get_fallback_events(): array {
		return $this->fallback_events;
	}

	/** @return array<string, mixed> */
	public function get_meta(): array {
		return $this->meta;
	}

	public function get_fallback_count(): int {
		return count( $this->fallback_events );
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		$lines = array();
		foreach ( $this->line_discounts as $discount ) {
			$lines[] = $discount->to_array();
		}

		return array(
			'line_discounts'   => $lines,
			'total_allocated'  => $this->total_allocated,
			'fallback_events'  => $this->fallback_events,
			'meta'             => $this->meta,
		);
	}

	/**
	 * @param array<string, mixed>|null $raw
	 */
	public static function from_array( ?array $raw ): self {
		if ( $raw === null || $raw === array() ) {
			return new self( array(), 0.0 );
		}

		$lines = array();
		if ( isset( $raw['line_discounts'] ) && is_array( $raw['line_discounts'] ) ) {
			foreach ( $raw['line_discounts'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$parsed = AppliedLineDiscount::from_array( $row );
				if ( $parsed instanceof AppliedLineDiscount ) {
					$lines[] = $parsed;
				}
			}
		}

		$fallbacks = array();
		if ( isset( $raw['fallback_events'] ) && is_array( $raw['fallback_events'] ) ) {
			foreach ( $raw['fallback_events'] as $event ) {
				if ( is_array( $event ) ) {
					$fallbacks[] = $event;
				}
			}
		}

		$meta = isset( $raw['meta'] ) && is_array( $raw['meta'] ) ? $raw['meta'] : array();

		return new self(
			$lines,
			isset( $raw['total_allocated'] ) ? (float) $raw['total_allocated'] : 0.0,
			$fallbacks,
			$meta
		);
	}
}
