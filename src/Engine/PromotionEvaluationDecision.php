<?php
/**
 * Outcome of evaluating one promotion within a multi-promotion plan.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

use InvalidArgumentException;
use MP\CommercePromotions\Domain\Promotion;

final class PromotionEvaluationDecision {

	public const REASON_NOT_ELIGIBLE               = 'not_eligible';
	public const REASON_BLOCKED_EXCLUSIVE          = 'blocked_by_exclusive_promotion';
	public const REASON_STOPPED_PROCESSING         = 'stopped_processing';
	public const REASON_EXCLUDED_BY_SELECTED       = 'excluded_by_selected_promotion';

	private ?int $promotion_id;

	private string $promotion_uuid;

	private string $promotion_name;

	private Promotion $promotion;

	private EvaluationResult $result;

	private bool $selected;

	private ?string $skipped_reason;

	public function __construct(
		Promotion $promotion,
		EvaluationResult $result,
		bool $selected,
		?string $skipped_reason
	) {
		$id = $promotion->get_id();
		if ( $id !== null && $id <= 0 ) {
			$id = null;
		}

		$this->promotion_id    = $id;
		$this->promotion_uuid  = $promotion->get_uuid();
		$this->promotion_name  = $promotion->get_name();
		$this->promotion       = $promotion;
		$this->result          = $result;
		$this->selected        = $selected;
		$this->skipped_reason  = $skipped_reason;

		if ( $selected && $skipped_reason !== null && $skipped_reason !== '' ) {
			throw new InvalidArgumentException( 'Selected decisions must not set skipped_reason.' );
		}
		if ( ! $selected && ( $skipped_reason === null || trim( $skipped_reason ) === '' ) ) {
			throw new InvalidArgumentException( 'Non-selected decisions require skipped_reason.' );
		}
	}

	public function get_promotion(): Promotion {
		return $this->promotion;
	}

	public function get_promotion_id(): ?int {
		return $this->promotion_id;
	}

	public function get_promotion_uuid(): string {
		return $this->promotion_uuid;
	}

	public function get_promotion_name(): string {
		return $this->promotion_name;
	}

	public function get_result(): EvaluationResult {
		return $this->result;
	}

	public function is_selected(): bool {
		return $this->selected;
	}

	public function get_skipped_reason(): ?string {
		return $this->skipped_reason;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'promotion_id'    => $this->promotion_id,
			'promotion_uuid'  => $this->promotion_uuid,
			'promotion_name'  => $this->promotion_name,
			'selected'        => $this->selected,
			'skipped_reason'  => $this->skipped_reason,
			'eligible'        => $this->result->is_eligible(),
			'result'          => $this->result->to_array(),
		);
	}
}
