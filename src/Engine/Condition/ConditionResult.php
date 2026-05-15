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

	private function __construct( bool $passed, ?string $message ) {
		$this->passed  = $passed;
		$this->message = $message;
	}

	public static function pass( ?string $message = null ): self {
		return new self( true, $message );
	}

	public static function fail( ?string $message = null ): self {
		return new self( false, $message );
	}

	public function passed(): bool {
		return $this->passed;
	}

	public function get_message(): ?string {
		return $this->message;
	}
}
