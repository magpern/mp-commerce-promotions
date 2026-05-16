<?php
/**
 * Outcome of evaluating a promotion against a context.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

use MP\CommercePromotions\Engine\Action\ActionTrace;
use MP\CommercePromotions\Engine\Condition\ConditionTrace;

final class EvaluationResult {

	private bool $eligible;

	/** @var list<string> */
	private array $messages;

	/** @var list<array<string, mixed>> */
	private array $action_results;

	/** @var list<array<string, mixed>> */
	private array $condition_traces;

	/** @var list<array<string, mixed>> */
	private array $action_traces;

	/**
	 * @param list<string>               $messages
	 * @param list<array<string, mixed>> $action_results
	 * @param list<array<string, mixed>> $condition_traces
	 * @param list<array<string, mixed>> $action_traces
	 */
	private function __construct(
		bool $eligible,
		array $messages,
		array $action_results,
		array $condition_traces,
		array $action_traces
	) {
		$this->eligible         = $eligible;
		$this->messages         = $messages;
		$this->action_results   = $action_results;
		$this->condition_traces = $condition_traces;
		$this->action_traces    = $action_traces;
	}

	/**
	 * @param list<array<string, mixed>>           $action_results
	 * @param list<string>                         $messages
	 * @param list<ConditionTrace|array<string,mixed>> $condition_traces
	 * @param list<ActionTrace|array<string,mixed>>    $action_traces
	 */
	public static function eligible(
		array $action_results = array(),
		array $messages = array(),
		array $condition_traces = array(),
		array $action_traces = array()
	): self {
		return new self(
			true,
			$messages,
			$action_results,
			self::normalize_condition_traces( $condition_traces ),
			self::normalize_action_traces( $action_traces )
		);
	}

	/**
	 * @param list<string>                         $messages
	 * @param list<ConditionTrace|array<string,mixed>> $condition_traces
	 * @param list<ActionTrace|array<string,mixed>>    $action_traces
	 */
	public static function ineligible(
		array $messages = array(),
		array $condition_traces = array(),
		array $action_traces = array()
	): self {
		return new self(
			false,
			$messages,
			array(),
			self::normalize_condition_traces( $condition_traces ),
			self::normalize_action_traces( $action_traces )
		);
	}

	public function is_eligible(): bool {
		return $this->eligible;
	}

	/**
	 * @return list<string>
	 */
	public function get_messages(): array {
		return $this->messages;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function get_action_results(): array {
		return $this->action_results;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function get_condition_traces(): array {
		return $this->condition_traces;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function get_action_traces(): array {
		return $this->action_traces;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'eligible'          => $this->eligible,
			'messages'          => $this->messages,
			'action_results'    => $this->action_results,
			'condition_traces'  => $this->condition_traces,
			'action_traces'     => $this->action_traces,
		);
	}

	/**
	 * @param list<ConditionTrace|array<string, mixed>> $traces
	 * @return list<array<string, mixed>>
	 */
	private static function normalize_condition_traces( array $traces ): array {
		$out = array();
		foreach ( $traces as $trace ) {
			if ( $trace instanceof ConditionTrace ) {
				$out[] = $trace->to_array();
				continue;
			}
			if ( is_array( $trace ) ) {
				$out[] = $trace;
			}
		}

		return $out;
	}

	/**
	 * @param list<ActionTrace|array<string, mixed>> $traces
	 * @return list<array<string, mixed>>
	 */
	private static function normalize_action_traces( array $traces ): array {
		$out = array();
		foreach ( $traces as $trace ) {
			if ( $trace instanceof ActionTrace ) {
				$out[] = $trace->to_array();
				continue;
			}
			if ( is_array( $trace ) ) {
				$out[] = $trace;
			}
		}

		return $out;
	}
}
