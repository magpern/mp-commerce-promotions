<?php
/**
 * Aggregated discount allocation across cart lines and shipping.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

final class AllocationResult {

	/** @var list<AllocatedDiscount> */
	private array $line_allocations;

	/** @var list<AllocatedDiscount> */
	private array $shipping_allocations;

	private float $total_allocated;

	private float $effective_discount_rate;

	/** @var array<string, mixed> */
	private array $tax_metadata;

	/** @var array<string, mixed> */
	private array $summary;

	/**
	 * @param list<AllocatedDiscount>   $line_allocations
	 * @param list<AllocatedDiscount>   $shipping_allocations
	 * @param array<string, mixed>      $tax_metadata
	 * @param array<string, mixed>      $summary
	 */
	public function __construct(
		array $line_allocations,
		array $shipping_allocations,
		float $total_allocated,
		float $effective_discount_rate,
		array $tax_metadata = array(),
		array $summary = array()
	) {
		$this->line_allocations        = $line_allocations;
		$this->shipping_allocations    = $shipping_allocations;
		$this->total_allocated         = max( 0.0, $total_allocated );
		$this->effective_discount_rate = max( 0.0, min( 100.0, $effective_discount_rate ) );
		$this->tax_metadata            = $tax_metadata;
		$this->summary                 = $summary;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'line_allocations'        => array_map( static fn ( AllocatedDiscount $a ): array => $a->to_array(), $this->line_allocations ),
			'shipping_allocations'    => array_map( static fn ( AllocatedDiscount $a ): array => $a->to_array(), $this->shipping_allocations ),
			'total_allocated'         => round( $this->total_allocated, 4 ),
			'effective_discount_rate' => round( $this->effective_discount_rate, 4 ),
			'tax_metadata'            => $this->tax_metadata,
			'summary'                 => $this->summary,
		);
	}

	public function get_total_allocated(): float {
		return $this->total_allocated;
	}

	public function get_effective_discount_rate(): float {
		return $this->effective_discount_rate;
	}

	/**
	 * @return list<AllocatedDiscount>
	 */
	public function get_line_allocations(): array {
		return $this->line_allocations;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_tax_metadata(): array {
		return $this->tax_metadata;
	}
}
