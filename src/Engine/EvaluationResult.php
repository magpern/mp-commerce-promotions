<?php
/**
 * Outcome of evaluating a promotion against a context.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

final class EvaluationResult {

	private bool $eligible;

	/** @var list<string> */
	private array $messages;

	/** @var list<array<string, mixed>> */
	private array $action_results;

	/**
	 * @param list<string>               $messages
	 * @param list<array<string, mixed>> $action_results
	 */
	private function __construct( bool $eligible, array $messages, array $action_results ) {
		$this->eligible       = $eligible;
		$this->messages       = $messages;
		$this->action_results = $action_results;
	}

	/**
	 * @param list<array<string, mixed>> $action_results
	 * @param list<string>               $messages
	 */
	public static function eligible( array $action_results = array(), array $messages = array() ): self {
		return new self( true, $messages, $action_results );
	}

	/**
	 * @param list<string> $messages
	 */
	public static function ineligible( array $messages = array() ): self {
		return new self( false, $messages, array() );
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
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'eligible'       => $this->eligible,
			'messages'       => $this->messages,
			'action_results' => $this->action_results,
		);
	}
}
