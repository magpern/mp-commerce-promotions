<?php
/**
 * Result of evaluating a single condition.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

final class ConditionResult {

	private bool $passed;

	private ?string $message;

	private string $reason_code;

	/** @var array<string, mixed> */
	private array $observed;

	/**
	 * @param array<string, mixed> $observed
	 */
	private function __construct( bool $passed, ?string $message, string $reason_code, array $observed ) {
		$this->passed      = $passed;
		$this->message     = $message;
		$this->reason_code = $reason_code;
		$this->observed    = $observed;
	}

	/**
	 * @param array<string, mixed> $observed
	 */
	public static function pass( ?string $message = null, ?string $reason_code = null, array $observed = array() ): self {
		return new self(
			true,
			$message,
			$reason_code !== null && $reason_code !== '' ? $reason_code : ConditionTrace::REASON_PASSED,
			$observed
		);
	}

	/**
	 * @param array<string, mixed> $observed
	 */
	public static function fail( ?string $message = null, ?string $reason_code = null, array $observed = array() ): self {
		return new self(
			false,
			$message,
			$reason_code !== null && $reason_code !== '' ? $reason_code : ConditionTrace::REASON_FAILED,
			$observed
		);
	}

	public function passed(): bool {
		return $this->passed;
	}

	public function get_message(): ?string {
		return $this->message;
	}

	public function get_reason_code(): string {
		return $this->reason_code;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_observed(): array {
		return $this->observed;
	}
}
