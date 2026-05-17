<?php
/**
 * Output of a promotion simulation run.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

final class SimulationResult {

	/** @var list<array<string, mixed>> */
	private array $eligible_promotions;

	/** @var list<array<string, mixed>> */
	private array $selected_promotions;

	/** @var list<array<string, mixed>> */
	private array $skipped_promotions;

	private float $total_discount;

	/** @var array<string, mixed> */
	private array $planner_metrics;

	/** @var list<array<string, mixed>> */
	private array $applied_actions;

	/** @var list<string> */
	private array $trace_summaries;

	/** @var list<string> */
	private array $warnings;

	/** @var array<string, mixed> */
	private array $explanation;

	/**
	 * @param list<array<string, mixed>> $eligible
	 * @param list<array<string, mixed>> $selected
	 * @param list<array<string, mixed>> $skipped
	 * @param array<string, mixed>      $planner_metrics
	 * @param list<array<string, mixed>> $applied_actions
	 * @param list<string>              $trace_summaries
	 * @param list<string>              $warnings
	 * @param array<string, mixed>       $explanation
	 */
	public function __construct(
		array $eligible,
		array $selected,
		array $skipped,
		float $total_discount,
		array $planner_metrics,
		array $applied_actions,
		array $trace_summaries,
		array $warnings,
		array $explanation = array()
	) {
		$this->eligible_promotions  = $eligible;
		$this->selected_promotions  = $selected;
		$this->skipped_promotions   = $skipped;
		$this->total_discount       = max( 0.0, $total_discount );
		$this->planner_metrics      = $planner_metrics;
		$this->applied_actions      = $applied_actions;
		$this->trace_summaries      = $trace_summaries;
		$this->warnings             = $warnings;
		$this->explanation          = $explanation;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'eligible_promotions'  => $this->eligible_promotions,
			'selected_promotions'  => $this->selected_promotions,
			'skipped_promotions'   => $this->skipped_promotions,
			'total_discount'       => $this->total_discount,
			'planner_metrics'      => $this->planner_metrics,
			'applied_actions'      => $this->applied_actions,
			'trace_summaries'      => $this->trace_summaries,
			'warnings'             => $this->warnings,
			'explanation'          => $this->explanation,
		);
	}

	public function get_total_discount(): float {
		return $this->total_discount;
	}
}
