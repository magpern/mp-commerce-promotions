<?php
/**
 * Collection of per-promotion evaluation decisions for application planning.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

final class PromotionEvaluationPlan {

	/** @var list<PromotionEvaluationDecision> */
	private array $decisions;

	/** @var array<string, mixed> */
	private array $metrics;

	/**
	 * @param list<PromotionEvaluationDecision> $decisions
	 * @param array<string, mixed>              $metrics
	 */
	public function __construct( array $decisions, array $metrics = array() ) {
		$this->decisions = $decisions;
		$this->metrics     = $metrics;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_metrics(): array {
		return $this->metrics;
	}

	/**
	 * @return list<PromotionEvaluationDecision>
	 */
	public function get_decisions(): array {
		return $this->decisions;
	}

	/**
	 * @return list<PromotionEvaluationDecision>
	 */
	public function get_selected_decisions(): array {
		$selected = array();
		foreach ( $this->decisions as $decision ) {
			if ( $decision->is_selected() ) {
				$selected[] = $decision;
			}
		}

		return $selected;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$rows = array();
		foreach ( $this->decisions as $decision ) {
			$rows[] = $decision->to_array();
		}

		$out = array(
			'decisions' => $rows,
		);
		if ( $this->metrics !== array() ) {
			$out['metrics'] = $this->metrics;
		}

		return $out;
	}
}
