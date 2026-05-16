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

	/**
	 * @param list<PromotionEvaluationDecision> $decisions
	 */
	public function __construct( array $decisions ) {
		$this->decisions = $decisions;
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

		return array(
			'decisions' => $rows,
		);
	}
}
