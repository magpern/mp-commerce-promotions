<?php
/**
 * Result of a code batch generation run (batch row + show-once plain codes).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\PromotionCodeBatch;

final class PromotionCodeBatchGenerationOutcome {

	private PromotionCodeBatch $batch;

	/**
	 * @var list<string>
	 */
	private array $plain_codes;

	private int $inserted_count;

	private int $requested_quantity;

	private ?string $warning;

	/**
	 * @param list<string> $plain_codes
	 */
	public function __construct(
		PromotionCodeBatch $batch,
		array $plain_codes,
		int $inserted_count,
		int $requested_quantity,
		?string $warning = null
	) {
		$this->batch                = $batch;
		$this->plain_codes          = $plain_codes;
		$this->inserted_count       = $inserted_count;
		$this->requested_quantity   = $requested_quantity;
		$this->warning              = $warning;
	}

	public function get_batch(): PromotionCodeBatch {
		return $this->batch;
	}

	/**
	 * @return list<string>
	 */
	public function get_plain_codes(): array {
		return $this->plain_codes;
	}

	public function get_inserted_count(): int {
		return $this->inserted_count;
	}

	public function get_requested_quantity(): int {
		return $this->requested_quantity;
	}

	public function get_warning(): ?string {
		return $this->warning;
	}

	public function is_complete(): bool {
		return $this->inserted_count >= $this->requested_quantity;
	}
}
